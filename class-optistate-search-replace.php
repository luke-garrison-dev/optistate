<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Search_Replace
{
    private OPTISTATE $main_plugin;
    private bool $case_sensitive = false;
    private bool $partial_match = false;
    private ?array $protected_options_cache = null;
    private ?array $deferred_options_cache = null;
    private ?array $options_exclude_cache = null;
    private ?array $scannable_tables_cache = null;
    private array $pattern_cache = [];
    private int $last_replace_count = 0;
    private const REGEX_BOUNDARY_FMT = "/(?<![\p{L}\p{N}_\-'])%s(?![\p{L}\p{N}_\-'])/%s";
    private const REGEX_BOUNDARY_ASCII_FMT = "/(?<![A-Za-z0-9_\\-'])%s(?![A-Za-z0-9_\\-'])/%s";
    private const PREVIEW_MAX_BYTES = 262144;
    private const PREVIEW_MAX_ROWS = 500;
    private const PREVIEW_MAX_VALUES_PER_CELL = 200;
    private const PREVIEW_MAX_SNIPPETS_PER_CELL = 10;
    private const PREVIEW_SNIPPET_LENGTH = 160;
    private const PREVIEW_HIGHLIGHT_OPEN = '<strong style="background:#ffeb3b;">';
    private const EXECUTE_BATCH_SIZE = 300;
    private const DRY_RUN_BATCH_SIZE = 200;
    private const CHUNK_TIME_BUDGET = 4.0;
    private const REQUEST_TIME_LIMIT = 300;
    private const LOCK_TTL = 600;
    private const LOCK_TOKEN_PATTERN = '/^[a-f0-9]{32}$/';
    private const MAX_SEARCH_LEN = 600;
    private const MAX_REPLACE_LEN = 4096;
    private const PATTERN_CACHE_LIMIT = 32;
    private const MAX_RECURSION_DEPTH = 100;
    private const MAX_STATE_ERRORS = 100;
    private const MAX_TOUCHED_OPTIONS = 2000;
    private const GC_ROW_INTERVAL = 1000;
    private const LOG_VALUE_MAX_LEN = 120;
    private const OPTISTATE_TRANSIENT_PREFIXES = [
        "_transient_optistate_",
        "_transient_timeout_optistate_",
        "_site_transient_optistate_",
        "_site_transient_timeout_optistate_",
    ];
    private const TRANSACTIONAL_ENGINES = [
        "INNODB",
        "XTRADB",
        "MYROCKS",
        "TOKUDB",
    ];

    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        add_action("wp_ajax_optistate_search_replace_dry_run", [
            $this,
            "ajax_dry_run",
        ]);
        add_action("wp_ajax_optistate_search_replace_execute", [
            $this,
            "ajax_execute",
        ]);
    }
    private function build_pattern(
        string $search,
        bool $case_sensitive,
        bool $partial_match,
        bool $unicode = true
    ): string {
        $key =
            ($case_sensitive ? "s" : "i") .
            ($partial_match ? "p" : "w") .
            ($unicode ? "u" : "b") .
            "|" .
            $search;
        if (isset($this->pattern_cache[$key])) {
            $value = $this->pattern_cache[$key];
            unset($this->pattern_cache[$key]);
            $this->pattern_cache[$key] = $value;
            return $value;
        }
        $escaped = preg_quote($search, "/");
        if ($unicode) {
            $flags = $case_sensitive ? "u" : "iu";
            $pattern = $partial_match
                ? "/" . $escaped . "/" . $flags
                : sprintf(self::REGEX_BOUNDARY_FMT, $escaped, $flags);
        } else {
            $flags = $case_sensitive ? "" : "i";
            $pattern = $partial_match
                ? "/" . $escaped . "/" . $flags
                : sprintf(self::REGEX_BOUNDARY_ASCII_FMT, $escaped, $flags);
        }
        if (count($this->pattern_cache) >= self::PATTERN_CACHE_LIMIT) {
            $oldest = array_key_first($this->pattern_cache);
            if ($oldest !== null) {
                unset($this->pattern_cache[$oldest]);
            }
        }
        $this->pattern_cache[$key] = $pattern;
        return $pattern;
    }
    private static function mb_search_available(): bool
    {
        static $available = null;
        if ($available === null) {
            $available =
                OPTISTATE_Utils::is_function_available("mb_strpos") &&
                OPTISTATE_Utils::is_function_available("mb_stripos");
        }
        return $available;
    }
    private static function text_pos(
        string $haystack,
        string $needle,
        bool $case_sensitive
    ) {
        if ($haystack === "" || $needle === "") {
            return false;
        }
        if (self::mb_search_available()) {
            return $case_sensitive
                ? mb_strpos($haystack, $needle)
                : mb_stripos($haystack, $needle);
        }
        $byte_pos = $case_sensitive
            ? strpos($haystack, $needle)
            : stripos($haystack, $needle);
        if ($byte_pos === false) {
            return false;
        }
        return mb_strlen(substr($haystack, 0, $byte_pos));
    }

    private function string_contains(string $string, string $search): bool
    {
        if ($string === "" || $search === "") {
            return false;
        }
        if ($this->partial_match) {
            return self::text_pos($string, $search, $this->case_sensitive) !==
                false;
        }
        $matched = @preg_match(
            $this->build_pattern($search, $this->case_sensitive, false, true),
            $string
        );
        if ($matched === false) {
            $matched = @preg_match(
                $this->build_pattern(
                    $search,
                    $this->case_sensitive,
                    false,
                    false
                ),
                $string
            );
        }
        return $matched === 1;
    }

    private function count_text_occurrences(
        string $text,
        string $search,
        bool $case_sensitive,
        bool $partial_match
    ): int {
        if ($text === "" || $search === "") {
            return 0;
        }
        if ($partial_match && $case_sensitive) {
            return substr_count($text, $search);
        }
        $count = @preg_match_all(
            $this->build_pattern($search, $case_sensitive, $partial_match, true),
            $text
        );
        if (is_int($count)) {
            return $count;
        }
        $count = @preg_match_all(
            $this->build_pattern(
                $search,
                $case_sensitive,
                $partial_match,
                false
            ),
            $text
        );
        if (is_int($count)) {
            return $count;
        }
        $this->log_regex_failure(
            "count_text_occurrences",
            "preg_match_all",
            strlen($text),
            strlen($search),
            preg_last_error()
        );
        if ($partial_match) {
            return substr_count(strtolower($text), strtolower($search));
        }
        return 0;
    }

    private function get_snippet(
        string $text,
        string $search,
        int $length = 100,
        bool $case_sensitive = false
    ): string {
        $pos = self::text_pos($text, $search, $case_sensitive);
        $text_len = mb_strlen($text);
        if ($pos === false) {
            return $text_len > $length
                ? mb_substr($text, 0, max(1, $length - 3)) . "..."
                : $text;
        }
        $half_length = (int) floor($length / 2);
        $start = max(0, $pos - $half_length);
        $prefix = $start > 0 ? "..." : "";
        $fetch_len = $length - mb_strlen($prefix);
        $suffix = $start + $fetch_len < $text_len ? "..." : "";
        if ($suffix !== "") {
            $fetch_len -= mb_strlen($suffix);
        }
        $fetch_len = max(1, $fetch_len);
        return $prefix . mb_substr($text, $start, $fetch_len) . $suffix;
    }

    public function get_highlighted_snippet(
        string $text,
        string $search,
        bool $case_sensitive = false,
        bool $partial_match = false,
        int $length = 140
    ): string {
        $preview_text = esc_html(
            $this->get_snippet($text, $search, $length, $case_sensitive)
        );
        $escaped_search = esc_html($search);
        if ($escaped_search === "" || $preview_text === "") {
            return $preview_text;
        }
        $replacement = self::PREVIEW_HIGHLIGHT_OPEN . '$0</strong>';
        $highlighted = @preg_replace(
            $this->build_pattern(
                $escaped_search,
                $case_sensitive,
                $partial_match,
                true
            ),
            $replacement,
            $preview_text
        );
        if ($highlighted === null) {
            $highlighted = @preg_replace(
                $this->build_pattern(
                    $escaped_search,
                    $case_sensitive,
                    $partial_match,
                    false
                ),
                $replacement,
                $preview_text
            );
        }
        if ($highlighted === null) {
            $this->log_regex_failure(
                "get_highlighted_snippet",
                "preg_replace",
                strlen($preview_text),
                strlen($escaped_search),
                preg_last_error()
            );
            return $preview_text;
        }
        return $highlighted;
    }

    public function replace_data(
        string $from,
        string $to,
        $data,
        bool $case_sensitive = false,
        bool $partial_match = false
    ) {
        $this->last_replace_count = 0;
        return $this->recursive_unserialize_replace(
            $from,
            $to,
            $data,
            false,
            $case_sensitive,
            0,
            $partial_match
        );
    }

    public function get_last_replace_count(): int
    {
        return $this->last_replace_count;
    }

    private static function bsr_unserialize(string $serialized_string)
    {
        if (!is_serialized($serialized_string)) {
            return false;
        }
        return @unserialize(trim($serialized_string), [
            "allowed_classes" => [],
        ]);
    }

    private static function is_object_cloneable(object $object): bool
    {
        static $cache = [];
        $class = get_class($object);
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        try {
            $reflection = new \ReflectionClass($class);
            $result = $reflection->isCloneable();
        } catch (\Throwable $e) {
            $result = false;
        }
        if (count($cache) < 500) {
            $cache[$class] = $result;
        }
        return $result;
    }

    private static function contains_incomplete_class(
        $data,
        int $depth = 0
    ): bool {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return false;
        }
        if ($data instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if (is_array($data)) {
            foreach ($data as $value) {
                if (self::contains_incomplete_class($value, $depth + 1)) {
                    return true;
                }
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                if (self::contains_incomplete_class($value, $depth + 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function recursive_unserialize_replace(
        string $from = "",
        string $to = "",
        $data = "",
        bool $serialised = false,
        bool $case_sensitive = false,
        int $depth = 0,
        bool $partial_match = false
    ) {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return $serialised ? serialize($data) : $data;
        }
        try {
            if (is_string($data)) {
                $found = $case_sensitive
                    ? strpos($data, $from) !== false
                    : stripos($data, $from) !== false;
                if (!$found) {
                    return $serialised ? serialize($data) : $data;
                }
            }
            if (
                is_string($data) &&
                !is_serialized_string($data) &&
                ($unserialized = self::bsr_unserialize($data)) !== false
            ) {
                if (
                    $unserialized instanceof \__PHP_Incomplete_Class ||
                    self::contains_incomplete_class($unserialized)
                ) {
                    $data = $this->replace_in_serialized_string(
                        $from,
                        $to,
                        $data,
                        $case_sensitive,
                        $partial_match
                    );
                } else {
                    $data = $this->recursive_unserialize_replace(
                        $from,
                        $to,
                        $unserialized,
                        true,
                        $case_sensitive,
                        $depth + 1,
                        $partial_match
                    );
                }
            } elseif (is_array($data)) {
                $_tmp = [];
                foreach ($data as $key => $value) {
                    $_tmp[$key] = $this->recursive_unserialize_replace(
                        $from,
                        $to,
                        $value,
                        false,
                        $case_sensitive,
                        $depth + 1,
                        $partial_match
                    );
                }
                $data = $_tmp;
                unset($_tmp);
            } elseif (is_object($data)) {
                if ($data instanceof \__PHP_Incomplete_Class) {
                    $re_serialized = serialize($data);
                    $replaced = $this->replace_in_serialized_string(
                        $from,
                        $to,
                        $re_serialized,
                        $case_sensitive,
                        $partial_match
                    );
                    if ($replaced !== $re_serialized) {
                        $new_data = @unserialize($replaced, [
                            "allowed_classes" => [],
                        ]);
                        if ($new_data !== false) {
                            $data = $new_data;
                        } else {
                            OPTISTATE_Utils::log_critical_error(
                                "Failed to unserialize replaced __PHP_Incomplete_Class",
                                [
                                    "error" => sprintf(
                                        "original=%d bytes, replaced=%d bytes",
                                        strlen($re_serialized),
                                        strlen($replaced)
                                    ),
                                ]
                            );
                        }
                    }
                } elseif (self::is_object_cloneable($data)) {
                    $_tmp = clone $data;
                    foreach (get_object_vars($data) as $key => $value) {
                        if (is_int($key)) {
                            continue;
                        }
                        if (isset($key[0]) && ord($key[0]) === 0) {
                            continue;
                        }
                        $_tmp->$key = $this->recursive_unserialize_replace(
                            $from,
                            $to,
                            $value,
                            false,
                            $case_sensitive,
                            $depth + 1,
                            $partial_match
                        );
                    }
                    $data = $_tmp;
                    unset($_tmp);
                }
            } elseif (is_serialized_string($data)) {
                $unserialized = self::bsr_unserialize($data);
                if ($unserialized !== false) {
                    $data = $this->recursive_unserialize_replace(
                        $from,
                        $to,
                        $unserialized,
                        true,
                        $case_sensitive,
                        $depth + 1,
                        $partial_match
                    );
                }
            } elseif (is_string($data)) {
                $data = $this->perform_string_replace(
                    $from,
                    $to,
                    $data,
                    $case_sensitive,
                    $partial_match
                );
            }
            if ($serialised) {
                return serialize($data);
            }
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Unexpected error in search/replace",
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "error" => sprintf(
                        "%s (depth %d)",
                        $e->getMessage(),
                        $depth
                    ),
                ]
            );
            return $data;
        }
        return $data;
    }
    private function perform_string_replace(
        string $from,
        string $to,
        string $data,
        bool $case_sensitive = false,
        bool $partial_match = false
    ): string {
        if ($data === "" || $from === "") {
            return $data;
        }
        if ($partial_match && $case_sensitive) {
            $this->last_replace_count += substr_count($data, $from);
            return str_replace($from, $to, $data);
        }
        $to_escaped = addcslashes($to, '\\$');
        $count = 0;
        $result = @preg_replace(
            $this->build_pattern($from, $case_sensitive, $partial_match, true),
            $to_escaped,
            $data,
            -1,
            $count
        );
        if ($result !== null) {
            $this->last_replace_count += $count;
            return $result;
        }
        $fallback_count = 0;
        $result = @preg_replace(
            $this->build_pattern($from, $case_sensitive, $partial_match, false),
            $to_escaped,
            $data,
            -1,
            $fallback_count
        );
        if ($result !== null) {
            $this->last_replace_count += $fallback_count;
            return $result;
        }
        $this->log_regex_failure(
            "perform_string_replace",
            "preg_replace",
            strlen($data),
            strlen($from),
            preg_last_error()
        );
        if ($partial_match) {
            $this->last_replace_count += substr_count(
                strtolower($data),
                strtolower($from)
            );
            return str_ireplace($from, $to, $data);
        }
        return $data;
    }

    private static function mark_value_consumed(array &$stack): void
    {
        if (!empty($stack)) {
            $stack[count($stack) - 1]["expect_key"] = true;
        }
    }
    private function replace_in_serialized_string(
        string $from,
        string $to,
        string $data,
        bool $case_sensitive,
        bool $partial_match = false
    ): string {
        $result = "";
        $i = 0;
        $len = strlen($data);
        $stack = [];
        while ($i < $len) {
            $ch = $data[$i];
            if ($ch === "}") {
                if (!empty($stack)) {
                    array_pop($stack);
                    self::mark_value_consumed($stack);
                }
                $result .= "}";
                $i++;
                continue;
            }
            if (
                ($ch === "a" || $ch === "O") &&
                isset($data[$i + 1]) &&
                $data[$i + 1] === ":"
            ) {
                $brace = strpos($data, "{", $i + 2);
                if ($brace !== false) {
                    $header = substr($data, $i, $brace - $i + 1);
                    $valid =
                        $ch === "a"
                            ? (bool) preg_match('/^a:\d+:\{$/', $header)
                            : (bool) preg_match(
                                '/^O:\d+:"[^"]*":\d+:\{$/',
                                $header
                            );
                    if ($valid) {
                        $stack[] = ["expect_key" => true];
                        $result .= $header;
                        $i += strlen($header);
                        continue;
                    }
                }
            }
            if ($ch === "C" && isset($data[$i + 1]) && $data[$i + 1] === ":") {
                $brace = strpos($data, "{", $i + 2);
                if ($brace !== false) {
                    $header = substr($data, $i, $brace - $i + 1);
                    if (
                        preg_match(
                            '/^C:\d+:"[^"]*":(\d+):\{$/',
                            $header,
                            $matches
                        )
                    ) {
                        $body_len = (int) $matches[1];
                        $body_end = $brace + 1 + $body_len;
                        if (
                            $body_len <= $len &&
                            isset($data[$body_end]) &&
                            $data[$body_end] === "}"
                        ) {
                            $result .= substr(
                                $data,
                                $i,
                                $body_end + 1 - $i
                            );
                            $i = $body_end + 1;
                            self::mark_value_consumed($stack);
                            continue;
                        }
                    }
                }
            }
            if ($ch === "E" && isset($data[$i + 1]) && $data[$i + 1] === ":") {
                $token_end = self::read_length_prefixed_token($data, $i, $len);
                if ($token_end !== null) {
                    $result .= substr($data, $i, $token_end - $i);
                    $i = $token_end;
                    self::mark_value_consumed($stack);
                    continue;
                }
            }
            if ($ch === "i" && isset($data[$i + 1]) && $data[$i + 1] === ":") {
                $semi = strpos($data, ";", $i + 1);
                if ($semi !== false) {
                    $result .= substr($data, $i, $semi - $i + 1);
                    $i = $semi + 1;
                    if (!empty($stack)) {
                        $top = count($stack) - 1;
                        $stack[$top]["expect_key"] = !$stack[$top]["expect_key"];
                    }
                    continue;
                }
            }
            if (
                (($ch === "d" || $ch === "b") &&
                    isset($data[$i + 1]) &&
                    $data[$i + 1] === ":") ||
                ($ch === "N" && isset($data[$i + 1]) && $data[$i + 1] === ";")
            ) {
                $semi = strpos($data, ";", $i + 1);
                if ($semi !== false) {
                    $result .= substr($data, $i, $semi - $i + 1);
                    $i = $semi + 1;
                    self::mark_value_consumed($stack);
                    continue;
                }
            }
            if (
                ($ch === "r" || $ch === "R") &&
                isset($data[$i + 1]) &&
                $data[$i + 1] === ":"
            ) {
                $semi = strpos($data, ";", $i + 1);
                if ($semi !== false) {
                    $token = substr($data, $i, $semi - $i + 1);
                    if (preg_match('/^[rR]:\d+;$/', $token)) {
                        $result .= $token;
                        $i = $semi + 1;
                        self::mark_value_consumed($stack);
                        continue;
                    }
                }
            }
            if ($ch === "s" && $i + 2 < $len && $data[$i + 1] === ":") {
                $colon_pos = strpos($data, ":", $i + 2);
                if ($colon_pos === false) {
                    $result .= substr($data, $i);
                    break;
                }
                $len_str = substr($data, $i + 2, $colon_pos - ($i + 2));
                if (!preg_match('/^\d+$/', $len_str)) {
                    OPTISTATE_Utils::log_critical_error(
                        "replace_in_serialized_string: invalid string length header",
                        [
                            "error" => sprintf(
                                'header="%s" at offset %d',
                                substr($len_str, 0, 32),
                                $i
                            ),
                        ]
                    );
                    return $data;
                }
                $str_len = (int) $len_str;
                if ($str_len > $len) {
                    OPTISTATE_Utils::log_critical_error(
                        "replace_in_serialized_string: string length out of bounds",
                        [
                            "error" => sprintf(
                                "declared %d bytes, payload is %d bytes (offset %d)",
                                $str_len,
                                $len,
                                $i
                            ),
                        ]
                    );
                    return $data;
                }
                if (
                    !isset($data[$colon_pos + 1]) ||
                    $data[$colon_pos + 1] !== '"'
                ) {
                    $result .= $data[$i];
                    $i++;
                    continue;
                }
                $str_start = $colon_pos + 2;
                $str_end = $str_start + $str_len;
                if (
                    $str_end + 2 > $len ||
                    $data[$str_end] !== '"' ||
                    $data[$str_end + 1] !== ";"
                ) {
                    OPTISTATE_Utils::log_critical_error(
                        "replace_in_serialized_string: malformed string terminator",
                        [
                            "error" => sprintf(
                                "expected terminator at offset %d, payload is %d bytes",
                                $str_end,
                                $len
                            ),
                        ]
                    );
                    return $data;
                }
                $str_content = substr($data, $str_start, $str_len);
                $is_key =
                    !empty($stack) && $stack[count($stack) - 1]["expect_key"];
                if ($is_key) {
                    $result .= "s:" . $str_len . ':"' . $str_content . '";';
                    $stack[count($stack) - 1]["expect_key"] = false;
                } else {
                    $new_content = $this->perform_string_replace(
                        $from,
                        $to,
                        $str_content,
                        $case_sensitive,
                        $partial_match
                    );
                    $result .=
                        "s:" .
                        strlen($new_content) .
                        ':"' .
                        $new_content .
                        '";';
                    self::mark_value_consumed($stack);
                }
                $i = $str_end + 2;
                continue;
            }
            $result .= $data[$i];
            $i++;
        }
        return $result;
    }
    private static function read_length_prefixed_token(
        string $data,
        int $offset,
        int $len
    ): ?int {
        $colon_pos = strpos($data, ":", $offset + 2);
        if ($colon_pos === false) {
            return null;
        }
        $len_str = substr($data, $offset + 2, $colon_pos - ($offset + 2));
        if (!preg_match('/^\d+$/', $len_str)) {
            return null;
        }
        $value_len = (int) $len_str;
        if ($value_len > $len) {
            return null;
        }
        if (!isset($data[$colon_pos + 1]) || $data[$colon_pos + 1] !== '"') {
            return null;
        }
        $value_start = $colon_pos + 2;
        $value_end = $value_start + $value_len;
        if (
            $value_end + 2 > $len ||
            $data[$value_end] !== '"' ||
            $data[$value_end + 1] !== ";"
        ) {
            return null;
        }
        return $value_end + 2;
    }

    private function find_all_preview_values(
        $data,
        string $search,
        array &$results = [],
        int $depth = 0
    ): array {
        if (
            $depth > self::MAX_RECURSION_DEPTH ||
            count($results) >= self::PREVIEW_MAX_VALUES_PER_CELL
        ) {
            return $results;
        }
        if (is_string($data)) {
            if (is_serialized($data)) {
                $unserialized = self::bsr_unserialize($data);
                if ($unserialized !== false) {
                    $this->find_all_preview_values(
                        $unserialized,
                        $search,
                        $results,
                        $depth + 1
                    );
                    return $results;
                }
                if ($data === "b:0;") {
                    return $results;
                }
            }
            if ($this->string_contains($data, $search)) {
                $results[] = $data;
            }
        } elseif (is_array($data)) {
            foreach ($data as $value) {
                $this->find_all_preview_values(
                    $value,
                    $search,
                    $results,
                    $depth + 1
                );
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $value) {
                $this->find_all_preview_values(
                    $value,
                    $search,
                    $results,
                    $depth + 1
                );
            }
        }
        return $results;
    }

    public function get_selectable_tables(): array
    {
        return $this->resolve_tables(["all"]);
    }

    private function get_scannable_tables(): array
    {
        if ($this->scannable_tables_cache !== null) {
            return $this->scannable_tables_cache;
        }
        global $wpdb;
        $all_tables = OPTISTATE_Utils::get_all_tables();
        if (!is_array($all_tables) || empty($all_tables)) {
            $this->scannable_tables_cache = [];
            return $this->scannable_tables_cache;
        }
        $core_tables = array_values($wpdb->tables("all"));
        $prefix = (string) $wpdb->prefix;
        $prefix_len = strlen($prefix);
        $base_prefix = (string) $wpdb->base_prefix;
        $other_blog_pattern = "";
        if (is_multisite() && $base_prefix !== "" && $prefix === $base_prefix) {
            $other_blog_pattern =
                "/^" . preg_quote($base_prefix, "/") . "\\d+_/";
        }
        $owned = [];
        foreach ($all_tables as $table) {
            $table = (string) $table;
            if (in_array($table, $core_tables, true)) {
                $owned[] = $table;
                continue;
            }
            if ($prefix_len === 0 || strncmp($table, $prefix, $prefix_len) !== 0) {
                continue;
            }
            if (
                $other_blog_pattern !== "" &&
                preg_match($other_blog_pattern, $table)
            ) {
                continue;
            }
            $owned[] = $table;
        }
        $owned = apply_filters(
            "optistate_sr_scannable_tables",
            array_values(array_unique($owned))
        );
        $this->scannable_tables_cache = is_array($owned)
            ? array_values(array_unique(array_map("strval", $owned)))
            : [];
        return $this->scannable_tables_cache;
    }

    private function resolve_tables(array $tables_input): array
    {
        $scannable = $this->get_scannable_tables();
        if (empty($scannable)) {
            return [];
        }
        if (empty($tables_input) || in_array("all", $tables_input, true)) {
            $tables = $scannable;
        } else {
            $tables = array_intersect($tables_input, $scannable);
        }
        $protected = array_merge(
            OPTISTATE_Utils::get_all_excluded_tables(),
            self::get_additional_protected_tables()
        );
        return array_values(array_diff($tables, $protected));
    }

    private static function get_additional_protected_tables(): array
    {
        $tables = apply_filters("optistate_protected_tables", []);
        if (!is_array($tables)) {
            return [];
        }
        return array_values(
            array_filter(array_map("strval", $tables), static function ($table) {
                return $table !== "";
            })
        );
    }

    private function get_protected_options(): array
    {
        if ($this->protected_options_cache !== null) {
            return $this->protected_options_cache;
        }
        $this->protected_options_cache = [
            "optistate_settings",
            "active_plugins",
            "wp_user_roles",
            "cron",
            "db_version",
            "db_upgraded",
        ];
        return $this->protected_options_cache;
    }

    private function get_deferred_options(): array
    {
        if ($this->deferred_options_cache !== null) {
            return $this->deferred_options_cache;
        }
        $this->deferred_options_cache = ["siteurl", "home"];
        return $this->deferred_options_cache;
    }

    private function build_options_exclude(): array
    {
        if ($this->options_exclude_cache !== null) {
            return $this->options_exclude_cache;
        }
        global $wpdb;
        $protected = $this->get_protected_options();
        $sql = "";
        $values = [];
        if (!empty($protected)) {
            $sql .=
                " AND `option_name` NOT IN (" .
                implode(", ", array_fill(0, count($protected), "%s")) .
                ")";
            $values = array_merge($values, $protected);
        }
        foreach (self::OPTISTATE_TRANSIENT_PREFIXES as $prefix) {
            $sql .= " AND `option_name` NOT LIKE %s";
            $values[] = $wpdb->esc_like($prefix) . "%";
        }
        $this->options_exclude_cache = ["sql" => $sql, "values" => $values];
        return $this->options_exclude_cache;
    }

    private function should_protect_option(string $option_name): string
    {
        if ($option_name === "") {
            return "";
        }
        if (in_array($option_name, $this->get_protected_options(), true)) {
            return "skip";
        }
        foreach (self::OPTISTATE_TRANSIENT_PREFIXES as $prefix) {
            if (strpos($option_name, $prefix) === 0) {
                return "skip";
            }
        }
        if (in_array($option_name, $this->get_deferred_options(), true)) {
            return "defer";
        }
        return "";
    }
    private function get_immutable_columns(string $table): array
    {
        global $wpdb;
        $immutable = [];
        if ($table === $wpdb->options) {
            $immutable = ["option_name", "autoload"];
        } elseif ($table === $wpdb->users) {
            $immutable = ["user_pass", "user_activation_key"];
        }
        $immutable = apply_filters(
            "optistate_sr_immutable_columns",
            $immutable,
            $table
        );
        if (!is_array($immutable)) {
            return [];
        }
        return array_values(array_unique(array_map("strval", $immutable)));
    }
    private function get_context_columns(string $table): array
    {
        global $wpdb;
        return $table === $wpdb->options ? ["option_name"] : [];
    }

    private function get_replaceable_columns(
        string $table,
        array $text_cols
    ): array {
        $immutable = $this->get_immutable_columns($table);
        if (empty($immutable)) {
            return array_values($text_cols);
        }
        return array_values(array_diff($text_cols, $immutable));
    }

    private static function get_table_meta(string $table): array
    {
        global $wpdb;
        static $request_cache = [];
        if (isset($request_cache[$table])) {
            return $request_cache[$table];
        }
        $cache_key = "meta_" . $table;
        $cached = wp_cache_get($cache_key, "optistate_sr");
        if (
            is_array($cached) &&
            isset($cached["transactional"], $cached["base_table"])
        ) {
            $request_cache[$table] = $cached;
            return $cached;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $table
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            OPTISTATE_Utils::log_critical_error(
                "get_table_meta: information_schema returned no row for table; " .
                    "table will be skipped this request",
                [
                    "table" => $table,
                    "error" =>
                        $wpdb->last_error !== ""
                            ? $wpdb->last_error
                            : "no row in information_schema.TABLES",
                ]
            );
            $meta = ["transactional" => false, "base_table" => false];
            wp_cache_set($cache_key, $meta, "optistate_sr", 60);
            $request_cache[$table] = $meta;
            return $meta;
        }
        $meta = [
            "transactional" => in_array(
                strtoupper((string) ($row["ENGINE"] ?? "")),
                self::TRANSACTIONAL_ENGINES,
                true
            ),
            "base_table" =>
                strtoupper((string) ($row["TABLE_TYPE"] ?? "")) === "BASE TABLE",
        ];
        wp_cache_set($cache_key, $meta, "optistate_sr", HOUR_IN_SECONDS);
        $request_cache[$table] = $meta;
        return $meta;
    }

    private function get_table_columns_info(string $table): array
    {
        $cache_key = "cols_" . $table;
        $cached = wp_cache_get($cache_key, "optistate_sr");
        if (is_array($cached)) {
            return $cached;
        }
        global $wpdb;
        $columns = $wpdb->get_results(
            "SHOW COLUMNS FROM " . OPTISTATE_Utils::escape_identifier($table),
            ARRAY_A
        );
        if (!is_array($columns) || empty($columns)) {
            OPTISTATE_Utils::log_critical_error(
                "Search/replace: unable to read table columns",
                [
                    "table" => $table,
                    "error" =>
                        $wpdb->last_error !== ""
                            ? $wpdb->last_error
                            : "SHOW COLUMNS returned no rows",
                ]
            );
            return ["pk" => [], "text_cols" => [], "limits" => []];
        }
        $pk_columns = [];
        $text_cols = [];
        $limits = [];
        foreach ($columns as $col) {
            $field = (string) ($col["Field"] ?? "");
            $type = (string) ($col["Type"] ?? "");
            if ($field === "") {
                continue;
            }
            if (($col["Key"] ?? "") === "PRI") {
                $pk_columns[] = $field;
            }
            if ($field === "guid") {
                continue;
            }
            if (preg_match('/^(tiny|medium|long)?blob$/i', $type)) {
                continue;
            }
            if (preg_match("/char|text|blob/i", $type)) {
                $text_cols[] = $field;
                $limit = self::parse_column_limit($type);
                if ($limit !== null) {
                    $limits[$field] = $limit;
                }
            }
        }
        $info = [
            "pk" => $pk_columns,
            "text_cols" => $text_cols,
            "limits" => $limits,
        ];
        wp_cache_set($cache_key, $info, "optistate_sr", HOUR_IN_SECONDS);
        return $info;
    }

    private static function parse_column_limit(string $type): ?array
    {
        if (preg_match('/^\s*(?:var)?char\s*\(\s*(\d+)\s*\)/i', $type, $matches)) {
            return ["type" => "char", "length" => (int) $matches[1]];
        }
        $byte_lengths = [
            "tinytext" => 255,
            "text" => 65535,
            "mediumtext" => 16777215,
            "longtext" => 4294967295,
        ];
        $base = strtolower(trim((string) preg_replace('/[\s(].*$/', "", $type)));
        if (isset($byte_lengths[$base])) {
            return ["type" => "byte", "length" => $byte_lengths[$base]];
        }
        return null;
    }

    private static function value_fits_column(string $value, ?array $limit): bool
    {
        if ($limit === null) {
            return true;
        }
        if ($limit["type"] === "byte") {
            return strlen($value) <= $limit["length"];
        }
        return mb_strlen($value) <= $limit["length"];
    }

    private function acquire_or_verify_lock(
        string $lock_key,
        bool $reset,
        string $conflict_msg
    ): ?string {
        if ($reset) {
            try {
                $token = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Could not generate secure lock token. Please try again.",
                        "optistate"
                    ),
                    500
                );
                return null;
            }
            set_transient($lock_key, $token, self::LOCK_TTL);
            return $token;
        }
        $token = isset($_POST["lock_token"])
            ? (string) wp_unslash($_POST["lock_token"])
            : "";
        if (!preg_match(self::LOCK_TOKEN_PATTERN, $token)) {
            OPTISTATE_Utils::send_json_error($conflict_msg, 409);
            return null;
        }
        $stored_token = get_transient($lock_key);
        if (
            !is_string($stored_token) ||
            !preg_match(self::LOCK_TOKEN_PATTERN, $stored_token) ||
            !hash_equals($stored_token, $token)
        ) {
            OPTISTATE_Utils::send_json_error($conflict_msg, 409);
            return null;
        }
        return $token;
    }

    private function save_state_and_lock(
        string $transient_key,
        array $state,
        string $lock_key,
        string $token
    ): void {
        set_transient($transient_key, $state, self::LOCK_TTL);
        set_transient($lock_key, $token, self::LOCK_TTL);
    }

    private static function release_session(
        string $transient_key,
        string $lock_key
    ): void {
        delete_transient($transient_key);
        delete_transient($lock_key);
    }

    private static function add_state_error(
        array &$state,
        string $message
    ): void {
        if (!isset($state["errors"]) || !is_array($state["errors"])) {
            $state["errors"] = [];
        }
        if (count($state["errors"]) >= self::MAX_STATE_ERRORS) {
            return;
        }
        $state["errors"][] = $message;
    }

    private function remember_touched_option(
        array &$state,
        string $option_name
    ): void {
        if (
            !isset($state["touched_options"]) ||
            !is_array($state["touched_options"])
        ) {
            $state["touched_options"] = [];
        }
        if (isset($state["touched_options"][$option_name])) {
            return;
        }
        if (count($state["touched_options"]) >= self::MAX_TOUCHED_OPTIONS) {
            $state["touched_overflow"] = true;
            return;
        }
        $state["touched_options"][$option_name] = true;
    }
    private function flush_options_cache(array $touched, bool $overflow): void
    {
        if ($overflow) {
            if (
                function_exists("wp_cache_supports") &&
                function_exists("wp_cache_flush_group") &&
                wp_cache_supports("flush_group")
            ) {
                wp_cache_flush_group("options");
            } elseif (function_exists("wp_cache_flush")) {
                wp_cache_flush();
            }
        } else {
            foreach (array_keys($touched) as $option) {
                wp_cache_delete((string) $option, "options");
            }
        }
        wp_cache_delete("alloptions", "options");
        wp_cache_delete("notoptions", "options");
    }
    private function checkpoint_options_cache(array &$state): void
    {
        $touched =
            isset($state["touched_options"]) &&
            is_array($state["touched_options"])
                ? $state["touched_options"]
                : [];
        $overflow = !empty($state["touched_overflow"]);
        if (empty($touched) && !$overflow) {
            return;
        }
        $this->flush_options_cache($touched, $overflow);
        $state["touched_options"] = [];
        $state["touched_overflow"] = false;
    }

    private static function prepare_long_request(bool $is_writing): void
    {
        OPTISTATE_Utils::safe_set_time_limit(self::REQUEST_TIME_LIMIT);
        if (function_exists("wp_raise_memory_limit")) {
            wp_raise_memory_limit("admin");
        }
        if (
            $is_writing &&
            OPTISTATE_Utils::is_function_available("ignore_user_abort")
        ) {
            @ignore_user_abort(true);
        }
    }

    private static function truncate_for_log(string $value): string
    {
        $value = wp_strip_all_tags($value);
        if (mb_strlen($value) <= self::LOG_VALUE_MAX_LEN) {
            return $value;
        }
        return mb_substr($value, 0, self::LOG_VALUE_MAX_LEN) . "…";
    }

    private static function read_table_selection(): array
    {
        if (!isset($_POST["tables"])) {
            return ["all"];
        }
        $tables = array_map(
            "sanitize_text_field",
            array_map("wp_unslash", (array) $_POST["tables"])
        );
        $tables = array_values(
            array_filter($tables, static function ($table) {
                return is_string($table) && $table !== "";
            })
        );
        return empty($tables) ? ["all"] : $tables;
    }

    private static function read_post_string(string $key): string
    {
        if (!isset($_POST[$key])) {
            return "";
        }
        $value = wp_unslash($_POST[$key]);
        return is_string($value) ? $value : "";
    }

    public function ajax_dry_run(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $reset = self::read_post_string("reset") === "true";
        if ($reset && !OPTISTATE_Utils::check_rate_limit("sr_dry_run", 5)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        $search = self::read_post_string("search");
        $tables_input = self::read_table_selection();
        $case_sensitive_post = self::read_post_string("case_sensitive") === "1";
        $partial_match_post = self::read_post_string("partial_match") === "1";
        if ($search === "") {
            OPTISTATE_Utils::send_json_error(
                __("Please enter a search term.", "optistate")
            );
            return;
        }
        if (strlen($search) > self::MAX_SEARCH_LEN) {
            OPTISTATE_Utils::send_json_error(
                sprintf(
                    __(
                        "Search term is too long. Maximum length is %d bytes.",
                        "optistate"
                    ),
                    self::MAX_SEARCH_LEN
                )
            );
            return;
        }
        $user_id = get_current_user_id();
        $transient_key = "optistate_sr_dry_" . $user_id;
        $lock_key = "optistate_sr_dry_lock_" . $user_id;
        $token = $this->acquire_or_verify_lock(
            $lock_key,
            $reset,
            __(
                "Operation conflict: another search is already in progress for this account.",
                "optistate"
            )
        );
        if ($token === null) {
            return;
        }
        $state = get_transient($transient_key);
        $has_state =
            is_array($state) &&
            isset($state["tables"], $state["search"], $state["current_idx"]);
        if (!$reset && !$has_state) {
            delete_transient($lock_key);
            OPTISTATE_Utils::send_json_error(
                __(
                    "The scan state expired or could not be stored. Please run the dry run again.",
                    "optistate"
                ),
                409
            );
            return;
        }
        if (!$has_state) {
            $state = [
                "search" => $search,
                "tables" => $this->resolve_tables($tables_input),
                "current_idx" => 0,
                "total_matches" => 0,
                "tables_affected" => 0,
                "preview" => [],
                "preview_bytes" => 0,
                "preview_occurrences" => 0,
                "status" => "running",
                "counts_capped" => false,
                "unique_rows" => 0,
                "has_serialized_data" => false,
                "skipped_non_transactional" => [],
                "skipped_composite" => [],
                "case_sensitive" => $case_sensitive_post,
                "partial_match" => $partial_match_post,
            ];
        }
        $search = (string) $state["search"];
        $case_sensitive = (bool) $state["case_sensitive"];
        $partial_match = (bool) $state["partial_match"];
        $this->case_sensitive = $case_sensitive;
        $this->partial_match = $partial_match;
        self::prepare_long_request(false);
        $start_time = microtime(true);
        $total_tables = count($state["tables"]);
        $preview_full =
            count($state["preview"]) >= self::PREVIEW_MAX_ROWS ||
            $state["preview_bytes"] >= self::PREVIEW_MAX_BYTES;
        while ($state["current_idx"] < $total_tables) {
            if (microtime(true) - $start_time > self::CHUNK_TIME_BUDGET) {
                $this->save_state_and_lock(
                    $transient_key,
                    $state,
                    $lock_key,
                    $token
                );
                OPTISTATE_Utils::send_json_success([
                    "status" => "running",
                    "percent" => (int) round(
                        ($state["current_idx"] / max(1, $total_tables)) * 100
                    ),
                    "lock_token" => $token,
                    "message" => sprintf(
                        __("Scanning table %s of %s...", "optistate"),
                        number_format_i18n($state["current_idx"] + 1),
                        number_format_i18n($total_tables)
                    ),
                ]);
                return;
            }
            $table = (string) $state["tables"][$state["current_idx"]];
            if (!OPTISTATE_Utils::validate_table_name($table)) {
                $state["current_idx"]++;
                continue;
            }
            $table_meta = self::get_table_meta($table);
            if (!$table_meta["base_table"]) {
                $state["current_idx"]++;
                continue;
            }
            if (!$table_meta["transactional"]) {
                $state["skipped_non_transactional"][] = $table;
                $state["current_idx"]++;
                continue;
            }
            $col_info = $this->get_table_columns_info($table);
            $pk_columns = $col_info["pk"];
            if (count($pk_columns) !== 1) {
                if (count($pk_columns) > 1) {
                    $state["skipped_composite"][] = $table;
                }
                $state["current_idx"]++;
                continue;
            }
            $text_cols = $this->get_replaceable_columns(
                $table,
                $col_info["text_cols"]
            );
            if (empty($text_cols)) {
                $state["current_idx"]++;
                continue;
            }
            $result = $this->scan_table_dry_run(
                $table,
                (string) $pk_columns[0],
                $text_cols,
                $search,
                $case_sensitive,
                $partial_match,
                $preview_full,
                $state
            );
            if ($result["table_matches"] > 0) {
                $state["total_matches"] += $result["table_matches"];
                $state["tables_affected"]++;
            }
            $state["unique_rows"] += $result["unique_rows"];
            $state["has_serialized_data"] =
                $state["has_serialized_data"] || $result["has_serialized_data"];
            if (!$preview_full && $result["preview_full"]) {
                $preview_full = true;
            }
            $state["current_idx"]++;
        }
        self::release_session($transient_key, $lock_key);
        $response_data = [
            "total_matches" => (int) $state["total_matches"],
            "unique_rows" => (int) $state["unique_rows"],
            "tables_affected" => (int) $state["tables_affected"],
            "preview" => $state["preview"],
            "preview_occurrences" => (int) $state["preview_occurrences"],
            "has_serialized_data" => (bool) $state["has_serialized_data"],
            "skipped_non_transactional" => array_values(
                array_unique($state["skipped_non_transactional"])
            ),
            "skipped_composite" => array_values(
                array_unique($state["skipped_composite"])
            ),
        ];
        if ($state["counts_capped"]) {
            $response_data["counts_capped"] = true;
            $response_data["counts_capped_note"] = sprintf(
                __(
                    "At least one table returned the %s-row sampling limit; the totals shown are a partial sample and the real number of matches is higher.",
                    "optistate"
                ),
                number_format_i18n(self::DRY_RUN_BATCH_SIZE)
            );
        }
        OPTISTATE_Utils::send_json_success([
            "status" => "done",
            "data" => $response_data,
        ]);
    }

    private function scan_table_dry_run(
        string $table,
        string $primary_key,
        array $text_cols,
        string $search,
        bool $case_sensitive,
        bool $partial_match,
        bool $preview_full,
        array &$state
    ): array {
        global $wpdb;
        $empty_result = [
            "table_matches" => 0,
            "unique_rows" => 0,
            "has_serialized_data" => false,
            "preview_full" => false,
        ];
        $primary_key_q = OPTISTATE_Utils::escape_identifier($primary_key);
        $table_q = OPTISTATE_Utils::escape_identifier($table);
        $like_value = "%" . $wpdb->esc_like($search) . "%";
        $where_parts = [];
        $where_values = [];
        foreach ($text_cols as $col) {
            $col_q = OPTISTATE_Utils::escape_identifier($col);
            $where_parts[] = $case_sensitive
                ? "CAST($col_q AS BINARY) LIKE CAST(%s AS BINARY)"
                : "$col_q LIKE %s";
            $where_values[] = $like_value;
        }
        $where_fmt = implode(" OR ", $where_parts);
        $exclude_sql = "";
        $exclude_values = [];
        if ($table === $wpdb->options) {
            $exclude = $this->build_options_exclude();
            $exclude_sql = $exclude["sql"];
            $exclude_values = $exclude["values"];
        }
        $select_cols = array_values(
            array_unique(
                array_merge(
                    [$primary_key],
                    $text_cols,
                    $this->get_context_columns($table)
                )
            )
        );
        $select_list = implode(
            ", ",
            array_map(
                static fn($c) => OPTISTATE_Utils::escape_identifier($c),
                $select_cols
            )
        );
        $sql = $wpdb->prepare(
            "SELECT $select_list FROM $table_q WHERE ($where_fmt)" .
                $exclude_sql .
                " ORDER BY $primary_key_q ASC LIMIT %d",
            array_merge($where_values, $exclude_values, [
                self::DRY_RUN_BATCH_SIZE,
            ])
        );
        if (!is_string($sql) || $sql === "") {
            OPTISTATE_Utils::log_critical_error(
                "Search/replace dry run: could not prepare statement",
                ["table" => $table]
            );
            return $empty_result;
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            OPTISTATE_Utils::log_critical_error(
                "Search/replace dry run: query failed",
                [
                    "table" => $table,
                    "error" =>
                        $wpdb->last_error !== ""
                            ? $wpdb->last_error
                            : "unknown database error",
                ]
            );
            return $empty_result;
        }
        if (count($rows) >= self::DRY_RUN_BATCH_SIZE) {
            $state["counts_capped"] = true;
        }
        $table_matches = 0;
        $unique_rows_in_table = [];
        $has_serialized = false;
        $became_full = false;
        foreach ($rows as $row) {
            if (!array_key_exists($primary_key, $row)) {
                continue;
            }
            $pk_val = $row[$primary_key];
            $row_counted = false;
            foreach ($text_cols as $col) {
                $original = $row[$col] ?? null;
                if (!is_string($original) || $original === "") {
                    continue;
                }
                if (!$this->string_contains($original, $search)) {
                    continue;
                }
                $is_serialized = is_serialized($original);
                if ($is_serialized) {
                    $has_serialized = true;
                }
                $preview_values = [];
                $this->find_all_preview_values(
                    $original,
                    $search,
                    $preview_values
                );
                if (empty($preview_values)) {
                    continue;
                }
                if (
                    count($preview_values) >= self::PREVIEW_MAX_VALUES_PER_CELL
                ) {
                    $state["counts_capped"] = true;
                }
                $cell_occurrences = 0;
                foreach ($preview_values as $value) {
                    if (is_string($value)) {
                        $cell_occurrences += $this->count_text_occurrences(
                            $value,
                            $search,
                            $case_sensitive,
                            $partial_match
                        );
                    }
                }
                if ($cell_occurrences === 0) {
                    continue;
                }
                $table_matches += $cell_occurrences;
                if (!$row_counted) {
                    $unique_rows_in_table[(string) $pk_val] = true;
                    $row_counted = true;
                }
                if ($preview_full || $became_full) {
                    continue;
                }
                $snippets = 0;
                foreach ($preview_values as $preview_value) {
                    if (!is_string($preview_value)) {
                        continue;
                    }
                    if ($snippets >= self::PREVIEW_MAX_SNIPPETS_PER_CELL) {
                        break;
                    }
                    $preview_text = $this->get_highlighted_snippet(
                        $preview_value,
                        $search,
                        $case_sensitive,
                        $partial_match,
                        self::PREVIEW_SNIPPET_LENGTH
                    );
                    $visible_occurrences = substr_count(
                        $preview_text,
                        self::PREVIEW_HIGHLIGHT_OPEN
                    );
                    if ($is_serialized) {
                        $preview_text =
                            '<span style="color:#2271b1; font-size:0.9em;">[' .
                            __("Serialized Match", "optistate") .
                            "]</span> " .
                            $preview_text;
                    }
                    $state["preview_bytes"] +=
                        strlen($preview_text) +
                        strlen($table) +
                        strlen($col) +
                        32;
                    $state["preview"][] = [
                        "table" => $table,
                        "column" => $col,
                        "id" => $pk_val,
                        "content" => $preview_text,
                    ];
                    $state["preview_occurrences"] += $visible_occurrences;
                    $snippets++;
                    if (
                        count($state["preview"]) >= self::PREVIEW_MAX_ROWS ||
                        $state["preview_bytes"] >= self::PREVIEW_MAX_BYTES
                    ) {
                        $became_full = true;
                        break;
                    }
                }
            }
        }
        return [
            "table_matches" => $table_matches,
            "unique_rows" => count($unique_rows_in_table),
            "has_serialized_data" => $has_serialized,
            "preview_full" => $became_full,
        ];
    }

    public function ajax_execute(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $reset = self::read_post_string("reset") === "true";
        if ($reset && !OPTISTATE_Utils::check_rate_limit("sr_execute", 10)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        $search = self::read_post_string("search");
        $replace = self::read_post_string("replace");
        $tables_input = self::read_table_selection();
        $case_sensitive_post = self::read_post_string("case_sensitive") === "1";
        $partial_match_post = self::read_post_string("partial_match") === "1";
        if ($search === "") {
            OPTISTATE_Utils::send_json_error(
                __("Search term cannot be empty.", "optistate")
            );
            return;
        }
        if (strlen($search) > self::MAX_SEARCH_LEN) {
            OPTISTATE_Utils::send_json_error(
                sprintf(
                    __(
                        "Search term is too long. Maximum length is %d bytes.",
                        "optistate"
                    ),
                    self::MAX_SEARCH_LEN
                )
            );
            return;
        }
        if (strlen($replace) > self::MAX_REPLACE_LEN) {
            OPTISTATE_Utils::send_json_error(
                sprintf(
                    __(
                        "Replacement value is too long. Maximum length is %d bytes.",
                        "optistate"
                    ),
                    self::MAX_REPLACE_LEN
                )
            );
            return;
        }
        $user_id = get_current_user_id();
        $transient_key = "optistate_sr_exec_" . $user_id;
        $lock_key = "optistate_sr_exec_lock_" . $user_id;
        $token = $this->acquire_or_verify_lock(
            $lock_key,
            $reset,
            __(
                "Operation conflict: another replacement is already in progress for this account.",
                "optistate"
            )
        );
        if ($token === null) {
            return;
        }
        $state = get_transient($transient_key);
        $has_state =
            is_array($state) &&
            isset(
                $state["tables"],
                $state["search"],
                $state["replace"],
                $state["current_idx"]
            );
        if (!$reset && !$has_state) {
            delete_transient($lock_key);
            OPTISTATE_Utils::send_json_error(
                __(
                    "The replacement state expired or could not be stored. No further changes were made; please re-run the dry run before retrying.",
                    "optistate"
                ),
                409
            );
            return;
        }
        if (!$has_state) {
            $state = [
                "search" => $search,
                "replace" => $replace,
                "tables" => $this->resolve_tables($tables_input),
                "current_idx" => 0,
                "last_pk" => null,
                "rows_affected" => 0,
                "errors" => [],
                "status" => "running",
                "deferred_updates" => [],
                "gc_counter" => 0,
                "occurrences_replaced" => 0,
                "touched_options" => [],
                "touched_overflow" => false,
                "case_sensitive" => $case_sensitive_post,
                "partial_match" => $partial_match_post,
            ];
        }
        $search = (string) $state["search"];
        $replace = (string) $state["replace"];
        $case_sensitive = (bool) $state["case_sensitive"];
        $partial_match = (bool) $state["partial_match"];
        $this->case_sensitive = $case_sensitive;
        $this->partial_match = $partial_match;
        self::prepare_long_request(true);
        $start_time = microtime(true);
        $total_tables = count($state["tables"]);
        while ($state["current_idx"] < $total_tables) {
            if (microtime(true) - $start_time > self::CHUNK_TIME_BUDGET) {
                $this->checkpoint_options_cache($state);
                $this->save_state_and_lock(
                    $transient_key,
                    $state,
                    $lock_key,
                    $token
                );
                OPTISTATE_Utils::send_json_success([
                    "status" => "running",
                    "percent" => (int) round(
                        ($state["current_idx"] / max(1, $total_tables)) * 100
                    ),
                    "lock_token" => $token,
                    "message" => sprintf(
                        __("Processing table %s of %s...", "optistate"),
                        number_format_i18n($state["current_idx"] + 1),
                        number_format_i18n($total_tables)
                    ),
                ]);
                return;
            }
            $table = (string) $state["tables"][$state["current_idx"]];
            if (!OPTISTATE_Utils::validate_table_name($table)) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            $table_meta = self::get_table_meta($table);
            if (!$table_meta["base_table"]) {
                self::add_state_error(
                    $state,
                    sprintf(
                        __(
                            "Skipped %s: not a base table (views cannot be rewritten safely).",
                            "optistate"
                        ),
                        $table
                    )
                );
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            if (!$table_meta["transactional"]) {
                self::add_state_error(
                    $state,
                    sprintf(
                        __(
                            "Skipped %s: non-transactional storage engine (cannot safely roll back).",
                            "optistate"
                        ),
                        $table
                    )
                );
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            $col_info = $this->get_table_columns_info($table);
            $pk_columns = $col_info["pk"];
            if (count($pk_columns) !== 1) {
                self::add_state_error(
                    $state,
                    count($pk_columns) > 1
                        ? sprintf(
                            __(
                                "Skipped %s: composite primary keys not supported.",
                                "optistate"
                            ),
                            $table
                        )
                        : sprintf(
                            __("Skipped %s: no primary key found.", "optistate"),
                            $table
                        )
                );
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            $text_cols = $this->get_replaceable_columns(
                $table,
                $col_info["text_cols"]
            );
            if (empty($text_cols)) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            $outcome = $this->replace_table_execute(
                $table,
                (string) $pk_columns[0],
                $text_cols,
                $search,
                $replace,
                $case_sensitive,
                $partial_match,
                $state,
                $start_time,
                $total_tables,
                $token,
                $transient_key,
                $lock_key
            );
            if ($outcome !== "done") {
                return;
            }
        }
        if (!empty($state["deferred_updates"])) {
            if (
                !$this->apply_deferred_updates(
                    $state,
                    $transient_key,
                    $lock_key
                )
            ) {
                return;
            }
        }
        self::release_session($transient_key, $lock_key);
        $this->flush_options_cache(
            isset($state["touched_options"]) &&
                is_array($state["touched_options"])
                ? $state["touched_options"]
                : [],
            !empty($state["touched_overflow"])
        );
        $this->main_plugin->log_entry(
            sprintf(
                "↳↰ Search & Replace Executed by {username}: '%s' -> '%s' (%s rows)",
                self::truncate_for_log($search),
                self::truncate_for_log($replace),
                number_format_i18n($state["rows_affected"])
            )
        );
        $response = [
            "status" => "done",
            "message" => sprintf(
                __(
                    "Replacement complete! %s rows updated (affecting %s total occurrences).",
                    "optistate"
                ),
                number_format_i18n($state["rows_affected"]),
                number_format_i18n($state["occurrences_replaced"])
            ),
            "rows" => (int) $state["rows_affected"],
            "occurrences" => (int) $state["occurrences_replaced"],
        ];
        if (!empty($state["errors"])) {
            $response["warnings"] = array_slice($state["errors"], 0, 10);
            $response["total_errors"] = count($state["errors"]);
        }
        OPTISTATE_Utils::send_json_success($response);
    }
    private function abort_execution(
        array &$state,
        string $transient_key,
        string $lock_key,
        string $message
    ): string {
        self::release_session($transient_key, $lock_key);
        $this->flush_options_cache(
            isset($state["touched_options"]) &&
                is_array($state["touched_options"])
                ? $state["touched_options"]
                : [],
            !empty($state["touched_overflow"])
        );
        OPTISTATE_Utils::send_json_error($message, 500, [
            "rows" => (int) ($state["rows_affected"] ?? 0),
        ]);
        return "error";
    }

    private function replace_table_execute(
        string $table,
        string $primary_key,
        array $text_cols,
        string $search,
        string $replace,
        bool $case_sensitive,
        bool $partial_match,
        array &$state,
        float $start_time,
        int $total_tables,
        string $token,
        string $transient_key,
        string $lock_key
    ): string {
        global $wpdb;
        $col_limits = $this->get_table_columns_info($table)["limits"] ?? [];
        $primary_key_q = OPTISTATE_Utils::escape_identifier($primary_key);
        $table_q = OPTISTATE_Utils::escape_identifier($table);
        $like_value = "%" . $wpdb->esc_like($search) . "%";
        $where_parts = [];
        $where_values = [];
        foreach ($text_cols as $col) {
            $col_q = OPTISTATE_Utils::escape_identifier($col);
            $where_parts[] = $case_sensitive
                ? "CAST($col_q AS BINARY) LIKE CAST(%s AS BINARY)"
                : "$col_q LIKE %s";
            $where_values[] = $like_value;
        }
        $where_fmt = implode(" OR ", $where_parts);
        $exclude_sql = "";
        $exclude_values = [];
        $is_options_table = $table === $wpdb->options;
        if ($is_options_table) {
            $exclude = $this->build_options_exclude();
            $exclude_sql = $exclude["sql"];
            $exclude_values = $exclude["values"];
        }
        $select_cols = array_values(
            array_unique(
                array_merge(
                    [$primary_key],
                    $text_cols,
                    $this->get_context_columns($table)
                )
            )
        );
        $select_list = implode(
            ", ",
            array_map(
                static fn($c) => OPTISTATE_Utils::escape_identifier($c),
                $select_cols
            )
        );
        while (true) {
            if (microtime(true) - $start_time > self::CHUNK_TIME_BUDGET) {
                $this->checkpoint_options_cache($state);
                $this->save_state_and_lock(
                    $transient_key,
                    $state,
                    $lock_key,
                    $token
                );
                OPTISTATE_Utils::send_json_success([
                    "status" => "running",
                    "percent" => (int) round(
                        ($state["current_idx"] / max(1, $total_tables)) * 100
                    ),
                    "lock_token" => $token,
                    "message" => sprintf(
                        __("Processing %s... (%s rows updated)", "optistate"),
                        $table,
                        number_format_i18n($state["rows_affected"])
                    ),
                ]);
                return "timeout";
            }
            if ($state["last_pk"] !== null) {
                $sql = $wpdb->prepare(
                    "SELECT $select_list FROM $table_q WHERE ($where_fmt) AND $primary_key_q > %s" .
                        $exclude_sql .
                        " ORDER BY $primary_key_q ASC LIMIT %d",
                    array_merge(
                        $where_values,
                        [$state["last_pk"]],
                        $exclude_values,
                        [self::EXECUTE_BATCH_SIZE]
                    )
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT $select_list FROM $table_q WHERE ($where_fmt)" .
                        $exclude_sql .
                        " ORDER BY $primary_key_q ASC LIMIT %d",
                    array_merge($where_values, $exclude_values, [
                        self::EXECUTE_BATCH_SIZE,
                    ])
                );
            }
            if (!is_string($sql) || $sql === "") {
                OPTISTATE_Utils::log_critical_error(
                    "Search/replace: could not prepare batch statement",
                    ["table" => $table]
                );
                return $this->abort_execution(
                    $state,
                    $transient_key,
                    $lock_key,
                    sprintf(
                        __(
                            "Search & Replace aborted: a safe query could not be built for %s.",
                            "optistate"
                        ),
                        $table
                    )
                );
            }
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($rows)) {
                $db_error =
                    $wpdb->last_error !== ""
                        ? $wpdb->last_error
                        : __("unknown database error", "optistate");
                OPTISTATE_Utils::log_critical_error(
                    "Search/replace: batch read failed",
                    ["table" => $table, "error" => $db_error]
                );
                return $this->abort_execution(
                    $state,
                    $transient_key,
                    $lock_key,
                    sprintf(
                        __(
                            "Search & Replace aborted: reading %1\$s failed (%2\$s).",
                            "optistate"
                        ),
                        $table,
                        $db_error
                    )
                );
            }
            if (empty($rows)) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                return "done";
            }
            if ($wpdb->query("START TRANSACTION") === false) {
                OPTISTATE_Utils::log_critical_error(
                    "Search/replace: could not start transaction",
                    ["table" => $table, "error" => $wpdb->last_error]
                );
                return $this->abort_execution(
                    $state,
                    $transient_key,
                    $lock_key,
                    sprintf(
                        __(
                            "Search & Replace aborted: no transaction could be started for %s.",
                            "optistate"
                        ),
                        $table
                    )
                );
            }
            $batch_success = true;
            $batch_rows = 0;
            $batch_error = "";
            $last_pk_in_batch = null;
            foreach ($rows as $row) {
                if (!array_key_exists($primary_key, $row)) {
                    continue;
                }
                $pk_val = $row[$primary_key];
                $last_pk_in_batch = $pk_val;
                $option_name = $is_options_table
                    ? (string) ($row["option_name"] ?? "")
                    : "";
                $protection = $is_options_table
                    ? $this->should_protect_option($option_name)
                    : "";
                if ($protection === "skip") {
                    continue;
                }
                $update_data = [];
                $deferred_data = [];
                foreach ($text_cols as $col) {
                    $original = $row[$col] ?? null;
                    if (!is_string($original) || $original === "") {
                        continue;
                    }
                    $modified = $this->replace_data(
                        $search,
                        $replace,
                        $original,
                        $case_sensitive,
                        $partial_match
                    );
                    if (!is_string($modified) || $modified === $original) {
                        continue;
                    }
                    if (
                        !self::value_fits_column(
                            $modified,
                            $col_limits[$col] ?? null
                        )
                    ) {
                        self::add_state_error(
                            $state,
                            sprintf(
                                __(
                                    "Skipped %1\$s.%2\$s (ID: %3\$s): the replacement would exceed the column size and would be silently truncated.",
                                    "optistate"
                                ),
                                $table,
                                $col,
                                (string) $pk_val
                            )
                        );
                        continue;
                    }
                    $state["occurrences_replaced"] += $this->last_replace_count;
                    if ($protection === "defer") {
                        $deferred_data[$col] = $modified;
                    } else {
                        $update_data[$col] = $modified;
                    }
                }
                if (!empty($update_data)) {
                    $result = $wpdb->update($table, $update_data, [
                        $primary_key => $pk_val,
                    ]);
                    if ($result === false || $wpdb->last_error !== "") {
                        $batch_success = false;
                        $batch_error =
                            $wpdb->last_error !== ""
                                ? $wpdb->last_error
                                : __("unknown database error", "optistate");
                        self::add_state_error(
                            $state,
                            sprintf(
                                __(
                                    "Update failed for %1\$s (ID: %2\$s): %3\$s",
                                    "optistate"
                                ),
                                $table,
                                (string) $pk_val,
                                $batch_error
                            )
                        );
                        OPTISTATE_Utils::log_critical_error(
                            "Search/replace update failed",
                            [
                                "table" => $table,
                                "error" => sprintf(
                                    "pk=%s columns=%s: %s",
                                    (string) $pk_val,
                                    implode(",", array_keys($update_data)),
                                    $batch_error
                                ),
                            ]
                        );
                        break;
                    }
                    if ($result > 0) {
                        $batch_rows++;
                        if ($option_name !== "") {
                            $this->remember_touched_option($state, $option_name);
                        }
                    }
                }
                if (!empty($deferred_data)) {
                    $state["deferred_updates"][] = [
                        "table" => $table,
                        "data" => $deferred_data,
                        "where" => [$primary_key => $pk_val],
                        "option_name" => $option_name,
                    ];
                }
            }
            if (!$batch_success) {
                $wpdb->query("ROLLBACK");
                return $this->abort_execution(
                    $state,
                    $transient_key,
                    $lock_key,
                    sprintf(
                        __("Search & Replace aborted: %s", "optistate"),
                        $batch_error
                    )
                );
            }
            if ($wpdb->query("COMMIT") === false) {
                $wpdb->query("ROLLBACK");
                OPTISTATE_Utils::log_critical_error(
                    "Search/replace: COMMIT failed",
                    ["table" => $table, "error" => $wpdb->last_error]
                );
                return $this->abort_execution(
                    $state,
                    $transient_key,
                    $lock_key,
                    sprintf(
                        __(
                            "Search & Replace aborted: changes to %s could not be committed and were rolled back.",
                            "optistate"
                        ),
                        $table
                    )
                );
            }
            $state["rows_affected"] += $batch_rows;
            if ($batch_rows > 0) {
                $state["gc_counter"] += $batch_rows;
                if ($state["gc_counter"] >= self::GC_ROW_INTERVAL) {
                    if (
                        OPTISTATE_Utils::is_function_available(
                            "gc_collect_cycles"
                        )
                    ) {
                        gc_collect_cycles();
                    }
                    $state["gc_counter"] = 0;
                }
            }
            if (count($rows) < self::EXECUTE_BATCH_SIZE) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                return "done";
            }
            if ($last_pk_in_batch === null) {
                OPTISTATE_Utils::log_critical_error(
                    "Search/replace: primary key missing from result set",
                    ["table" => $table, "error" => "pagination cursor lost"]
                );
                return $this->abort_execution(
                    $state,
                    $transient_key,
                    $lock_key,
                    sprintf(
                        __(
                            "Search & Replace aborted: the primary key of %s could not be read, so paging cannot continue safely.",
                            "optistate"
                        ),
                        $table
                    )
                );
            }
            $state["last_pk"] = $last_pk_in_batch;
        }
    }
    private function apply_deferred_updates(
        array &$state,
        string $transient_key,
        string $lock_key
    ): bool {
        global $wpdb;
        if ($wpdb->query("START TRANSACTION") === false) {
            OPTISTATE_Utils::log_critical_error(
                "Deferred updates: could not start transaction",
                ["error" => $wpdb->last_error]
            );
            self::release_session($transient_key, $lock_key);
            OPTISTATE_Utils::send_json_error(
                __(
                    "Replacement partially failed: deferred options (siteurl / home) could not be updated safely.",
                    "optistate"
                ),
                500
            );
            return false;
        }
        $committed = false;
        try {
            $deferred_rows = 0;
            $failed_option = null;
            foreach ($state["deferred_updates"] as $deferred) {
                $result = $wpdb->update(
                    $deferred["table"],
                    $deferred["data"],
                    $deferred["where"]
                );
                if ($result === false || $wpdb->last_error !== "") {
                    $failed_option = (string) ($deferred["option_name"] ?? "");
                    OPTISTATE_Utils::log_critical_error(
                        "Deferred search/replace update failed",
                        [
                            "table" => (string) $deferred["table"],
                            "error" => sprintf(
                                "option=%s: %s",
                                $failed_option !== ""
                                    ? $failed_option
                                    : "unknown",
                                $wpdb->last_error !== ""
                                    ? $wpdb->last_error
                                    : "unknown database error"
                            ),
                        ]
                    );
                    break;
                }
                if ($result > 0) {
                    $deferred_rows++;
                    $option_name = (string) ($deferred["option_name"] ?? "");
                    if ($option_name !== "") {
                        $this->remember_touched_option($state, $option_name);
                    }
                }
            }
            if ($failed_option !== null) {
                $wpdb->query("ROLLBACK");
                self::add_state_error(
                    $state,
                    sprintf(
                        __("Deferred update failed for %s", "optistate"),
                        $failed_option !== "" ? $failed_option : "unknown"
                    )
                );
                self::release_session($transient_key, $lock_key);
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Replacement partially failed: deferred options (siteurl / home) could not be updated and were rolled back.",
                        "optistate"
                    ),
                    500
                );
                return false;
            }
            if (!$this->deferred_urls_are_valid($state)) {
                $wpdb->query("ROLLBACK");
                self::release_session($transient_key, $lock_key);
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Critical error: siteurl or home would become invalid after replacement. The deferred changes were rolled back.",
                        "optistate"
                    ),
                    500
                );
                return false;
            }
            if ($wpdb->query("COMMIT") === false) {
                throw new \RuntimeException(
                    "COMMIT failed: " . $wpdb->last_error
                );
            }
            $committed = true;
            $state["rows_affected"] += $deferred_rows;
        } catch (\Throwable $e) {
            if (!$committed) {
                $wpdb->query("ROLLBACK");
            }
            OPTISTATE_Utils::log_critical_error(
                "Deferred updates: unexpected exception during transaction",
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "error" => $e->getMessage(),
                ]
            );
            self::release_session($transient_key, $lock_key);
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while applying deferred updates. Changes were rolled back.",
                    "optistate"
                ),
                500
            );
            return false;
        }
        return true;
    }

    private function deferred_urls_are_valid(array $state): bool
    {
        $urls = [];
        foreach ($state["deferred_updates"] as $deferred) {
            $option = (string) ($deferred["option_name"] ?? "");
            if ($option !== "siteurl" && $option !== "home") {
                continue;
            }
            if (!isset($deferred["data"]["option_value"])) {
                continue;
            }
            $urls[$option] = $deferred["data"]["option_value"];
        }
        if (empty($urls)) {
            return true;
        }
        foreach ($urls as $option => $value) {
            if (!is_string($value) || !self::is_valid_site_url($value)) {
                OPTISTATE_Utils::log_critical_error(
                    "siteurl/home would become invalid after replacement",
                    ["error" => sprintf("option=%s failed URL validation", $option)]
                );
                return false;
            }
        }
        return true;
    }

    private static function is_valid_site_url(string $url): bool
    {
        if ($url === "" || strlen($url) > 2000) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7F<>"\'\\\\]/', $url)) {
            return false;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts["scheme"]) || empty($parts["host"])) {
            return false;
        }
        $scheme = strtolower((string) $parts["scheme"]);
        if ($scheme !== "http" && $scheme !== "https") {
            return false;
        }
        if (isset($parts["user"]) || isset($parts["pass"])) {
            return false;
        }
        $host = (string) $parts["host"];
        if (strpbrk($host, "#?[]@") !== false || trim($host, ".") === "") {
            return false;
        }
        if (isset($parts["port"])) {
            $port = (int) $parts["port"];
            if ($port < 1 || $port > 65535) {
                return false;
            }
        }
        return true;
    }

    private function log_regex_failure(
        string $context,
        string $function,
        int $subject_length,
        int $pattern_length,
        int $error_code,
        ?string $pattern = null
    ): void {
        $error_msg = function_exists("preg_last_error_msg")
            ? preg_last_error_msg()
            : "preg error code " . $error_code;
        $detail = sprintf(
            "%s(): %s [code %d, subject %d bytes, needle %d bytes]",
            $function,
            $error_msg,
            $error_code,
            $subject_length,
            $pattern_length
        );
        if ($pattern !== null) {
            $detail .= " pattern=" . substr($pattern, 0, 100);
        }
        OPTISTATE_Utils::log_critical_error("Regex failure in " . $context, [
            "error" => $detail,
        ]);
    }
}