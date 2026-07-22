<?php declare(strict_types=1);
if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_SQL_Parser
{
    private const EXCLUDED_TABLE_PREFIX = "optistate_";
    public static function read_statement(
        $handle,
        string &$buffer,
        bool $is_gzipped,
        string &$current_delimiter = ";"
    ): ?string {
        static $memory_limit_bytes = null;
        static $max_buffer_size = null;
        if ($memory_limit_bytes === null) {
            $memory_limit_str = ini_get("memory_limit");
            $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit_str);
            if ($memory_limit_bytes <= 0) {
                $memory_limit_bytes = 1024 * 1024 * 1024;
            }
            $safety_margin =
                $memory_limit_bytes > 512 * 1024 * 1024 ? 0.45 : 0.35;
            $max_buffer_size = (int) min(
                100 * 1024 * 1024,
                $memory_limit_bytes * $safety_margin
            );
            $max_buffer_size = max(10 * 1024 * 1024, $max_buffer_size);
        }
        $chunk_size = 512 * 1024;
        $loop_start_time = microtime(true);
        $loop_iterations = 0;
        $max_loop_seconds = 60;
        $max_loop_iterations = 15000;
        $pos = 0;
        $in_string = false;
        $quote_char = "";
        while (true) {
            $loop_iterations++;
            if ($loop_iterations > $max_loop_iterations) {
                $error_msg = __(
                    "SQL parser: maximum iterations exceeded while reading statement. Possible file corruption or infinite loop.",
                    "optistate"
                );
                OPTISTATE_Utils::log_critical_error($error_msg, [
                    "iterations" => $loop_iterations,
                    "buffer_len" => strlen($buffer),
                ]);
                throw new Exception($error_msg);
            }
            if (microtime(true) - $loop_start_time > $max_loop_seconds) {
                $error_msg = sprintf(
                    __(
                        "SQL parser: timeout after %d seconds while reading statement. File may be too large or corrupted.",
                        "optistate"
                    ),
                    $max_loop_seconds
                );
                OPTISTATE_Utils::log_critical_error($error_msg, [
                    "buffer_len" => strlen($buffer),
                    "file_pos" => $is_gzipped
                        ? gztell($handle)
                        : ftell($handle),
                ]);
                throw new Exception($error_msg);
            }
            if (strlen($buffer) > $max_buffer_size) {
                $truncated = self::try_truncate_multirow_insert($buffer);
                if ($truncated !== null) {
                    return $truncated;
                }
                if (stripos(ltrim($buffer), "INSERT ") === 0) {
                    $error_msg = __(
                        "Parser memory exhausted, and the INSERT statement cannot be safely truncated (not a multi-row insert). Please increase your PHP memory_limit.",
                        "optistate"
                    );
                    OPTISTATE_Utils::log_critical_error($error_msg, [
                        "buffer_len" => strlen($buffer),
                    ]);
                    throw new Exception($error_msg);
                }
                $error_msg = sprintf(
                    __(
                        "SQL statement exceeds maximum buffer size (%s). File may be malformed or contain overly large statements.",
                        "optistate"
                    ),
                    size_format($max_buffer_size)
                );
                OPTISTATE_Utils::log_critical_error($error_msg, [
                    "buffer_len" => strlen($buffer),
                    "max_buffer" => $max_buffer_size,
                ]);
                throw new Exception($error_msg);
            }
            if ($pos === 0) {
                $buffer = ltrim($buffer);
                $stripped = false;
                do {
                    $stripped = false;
                    $first_char = isset($buffer[0]) ? $buffer[0] : "";
                    if ($first_char === "-" || $first_char === "#") {
                        if (
                            strpos($buffer, "\n") === false &&
                            !($is_gzipped ? gzeof($handle) : feof($handle))
                        ) {
                            break;
                        }
                        if (
                            preg_match(
                                '/^(--[^\n]*\n|#[^\n]*\n)/s',
                                $buffer,
                                $m
                            )
                        ) {
                            $buffer = substr($buffer, strlen($m[0]));
                            $buffer = ltrim($buffer);
                            $stripped = true;
                        }
                    }
                    if (
                        !$stripped &&
                        $first_char === "/" &&
                        isset($buffer[1]) &&
                        $buffer[1] === "*"
                    ) {
                        if (!isset($buffer[2]) || $buffer[2] !== "!") {
                            $end_pos = strpos($buffer, "*/", 2);
                            if ($end_pos !== false) {
                                $buffer = ltrim(substr($buffer, $end_pos + 2));
                                $stripped = true;
                            }
                        }
                    }
                } while ($stripped);
                if ($buffer === "") {
                    $chunk = $is_gzipped
                        ? gzread($handle, $chunk_size)
                        : fread($handle, $chunk_size);
                    if ($chunk === false || strlen($chunk) === 0) {
                        return null;
                    }
                    $current_memory = memory_get_usage();
                    $chunk_size_bytes = strlen($chunk);
                    $dynamic_headroom = max(
                        16 * 1024 * 1024,
                        (int) ($memory_limit_bytes * 0.15)
                    );
                    if (
                        $current_memory + $chunk_size_bytes >
                        $memory_limit_bytes - $dynamic_headroom
                    ) {
                        $error_msg = __(
                            "Parser memory exhausted before appending chunk. Please increase your PHP memory_limit.",
                            "optistate"
                        );
                        OPTISTATE_Utils::log_critical_error($error_msg, [
                            "memory_usage" => $current_memory,
                            "memory_limit" => $memory_limit_bytes,
                            "chunk_size" => $chunk_size_bytes,
                        ]);
                        throw new Exception($error_msg);
                    }
                    $buffer .= $chunk;
                    if (
                        !$is_gzipped &&
                        $loop_iterations > 1000 &&
                        $loop_iterations % 200 === 0
                    ) {
                        usleep(500);
                    }
                    continue;
                }
                if (stripos($buffer, "DELIMITER") === 0) {
                    if (
                        preg_match(
                            '/^DELIMITER\s+(\S+)(?:[\r\n]+|$)/i',
                            $buffer,
                            $m
                        )
                    ) {
                        $current_delimiter = $m[1];
                        $buffer = substr($buffer, strlen($m[0]));
                        continue;
                    }
                    $chunk = $is_gzipped
                        ? gzread($handle, $chunk_size)
                        : fread($handle, $chunk_size);
                    if ($chunk !== false && strlen($chunk) > 0) {
                        $buffer .= $chunk;
                    }
                    continue;
                }
            }
            $delim_len = strlen($current_delimiter);
            $len = strlen($buffer);
            while ($pos < $len) {
                $char = $buffer[$pos];
                $ord = ord($char);
                if ($ord >= 0x80) {
                    if (($ord & 0xe0) === 0xc0) {
                        $step = 2;
                    } elseif (($ord & 0xf0) === 0xe0) {
                        $step = 3;
                    } elseif (($ord & 0xf8) === 0xf0) {
                        $step = 4;
                    } else {
                        $step = 1;
                    }
                    if ($pos + $step > $len) {
                        break;
                    }
                    $pos += $step;
                    continue;
                }
                if ($in_string) {
                    $next_quote = strpos($buffer, $quote_char, $pos);
                    if ($next_quote === false) {
                        $pos = $len;
                        break;
                    }
                    $slashes = 0;
                    $i = $next_quote - 1;
                    while ($i >= $pos && $buffer[$i] === "\\") {
                        $slashes++;
                        $i--;
                    }
                    if ($slashes % 2 === 0) {
                        $in_string = false;
                        $quote_char = "";
                        $pos = $next_quote + 1;
                        continue;
                    } else {
                        $pos = $next_quote + 1;
                        continue;
                    }
                }
                if ($char === "'" || $char === '"' || $char === "`") {
                    $in_string = true;
                    $quote_char = $char;
                    $pos++;
                    continue;
                }
                if (
                    ($char === "-" &&
                        $pos + 1 < $len &&
                        $buffer[$pos + 1] === "-") ||
                    $char === "#"
                ) {
                    $nl = strpos($buffer, "\n", $pos);
                    if ($nl === false) {
                        break;
                    }
                    $pos = $nl + 1;
                    continue;
                }
                if (
                    $char === "/" &&
                    $pos + 1 < $len &&
                    $buffer[$pos + 1] === "*"
                ) {
                    if ($pos + 2 < $len && $buffer[$pos + 2] === "!") {
                        $pos += 3;
                        continue;
                    }
                    $close = strpos($buffer, "*/", $pos + 2);
                    if ($close === false) {
                        break;
                    }
                    $pos = $close + 2;
                    continue;
                }
                if (
                    $pos + $delim_len <= $len &&
                    substr($buffer, $pos, $delim_len) === $current_delimiter
                ) {
                    $statement = substr($buffer, 0, $pos);
                    $buffer = ltrim(substr($buffer, $pos + $delim_len));
                    return trim($statement);
                }
                $pos++;
            }
            $chunk = $is_gzipped
                ? gzread($handle, $chunk_size)
                : fread($handle, $chunk_size);
            if ($chunk !== false && strlen($chunk) > 0) {
                $current_memory = memory_get_usage();
                $buffer_size = strlen($buffer);
                $chunk_size_bytes = strlen($chunk);
                $dynamic_margin = max(
                    16 * 1024 * 1024,
                    (int) ($memory_limit_bytes * 0.15)
                );
                if ($memory_limit_bytes - $dynamic_margin < 0) {
                    $dynamic_margin = (int) ($memory_limit_bytes * 0.3);
                }
                if (
                    $current_memory + $buffer_size + $chunk_size_bytes >
                    $memory_limit_bytes - $dynamic_margin
                ) {
                    $truncated = self::try_truncate_multirow_insert($buffer);
                    if ($truncated !== null) {
                        return $truncated;
                    }
                    $error_msg = __(
                        "Parser memory exhausted during read operation. Please increase your PHP memory_limit.",
                        "optistate"
                    );
                    OPTISTATE_Utils::log_critical_error($error_msg, [
                        "memory_usage" => $current_memory,
                        "memory_limit" => $memory_limit_bytes,
                        "buffer_len" => $buffer_size,
                    ]);
                    throw new Exception($error_msg);
                }
                $buffer .= $chunk;
            } elseif (
                ($is_gzipped && gzeof($handle)) ||
                (!$is_gzipped && feof($handle))
            ) {
                $statement = trim($buffer);
                $buffer = "";
                return $statement !== "" ? $statement : null;
            }
        }
    }
    private static function try_truncate_multirow_insert(
        string &$buffer
    ): ?string {
        if (stripos(ltrim($buffer), "INSERT ") !== 0) {
            return null;
        }
        if (
            !preg_match(
                '/^(INSERT\s+(?:IGNORE\s+)?INTO\s+[`\'"]?[a-zA-Z0-9_$.]+[`\'"]?(?:\s*\([^)]+\))?\s+VALUES\s*)/i',
                $buffer,
                $matches
            )
        ) {
            return null;
        }
        $insert_header = $matches[1];
        $data = substr($buffer, strlen($insert_header));
        $len = strlen($data);
        $in_string = false;
        $quote_char = "";
        $depth = 0;
        $last_safe_cut = false;
        for ($i = 0; $i < $len; $i++) {
            $char = $data[$i];
            if ($in_string) {
                if ($char === "\\") {
                    $i++;
                    continue;
                }
                if ($char === $quote_char) {
                    if (isset($data[$i + 1]) && $data[$i + 1] === $quote_char) {
                        $i++;
                        continue;
                    }
                    $in_string = false;
                }
            } else {
                if ($char === "'" || $char === '"') {
                    $in_string = true;
                    $quote_char = $char;
                } elseif ($char === "(") {
                    $depth++;
                } elseif ($char === ")") {
                    $depth--;
                } elseif ($char === "," && $depth === 0) {
                    $j = $i + 1;
                    while (
                        $j < $len &&
                        ($data[$j] === " " ||
                            $data[$j] === "\n" ||
                            $data[$j] === "\r")
                    ) {
                        $j++;
                    }
                    if ($j < $len && $data[$j] === "(") {
                        $last_safe_cut = $i;
                    }
                }
            }
        }
        if ($last_safe_cut === false) {
            return null;
        }
        $statement = $insert_header . substr($data, 0, $last_safe_cut);
        $buffer = $insert_header . ltrim(substr($data, $last_safe_cut + 1));
        return trim($statement) . ";";
    }
    public static function rewrite_ddl(
        string $query,
        string $type,
        array &$temp_tables_created,
        array $excluded_tables_cache = []
    ): array {
        $result = [
            "query" => $query,
            "skip" => false,
            "original_table" => null,
            "temp_table" => null,
        ];
        $extracted = self::extract_ddl_table_name($query);
        if ($extracted === null) {
            return $result;
        }
        [$original_table, $name_start, $name_length] = $extracted;
        if (self::is_excluded_table($original_table, $excluded_tables_cache)) {
            $result["skip"] = true;
            return $result;
        }
        if (!isset($temp_tables_created[$original_table])) {
            if ($type !== "CREATE" && $type !== "DROP") {
                $result["skip"] = true;
                return $result;
            }
            $temp_name = OPTISTATE_Utils::generate_safe_table_name(
                $original_table,
                "optistate_temp_",
                64
            );
            $temp_tables_created[$original_table] = $temp_name;
        }
        $temp_name = $temp_tables_created[$original_table];
        $result["original_table"] = $original_table;
        $result["temp_table"] = $temp_name;
        $result["query"] =
            substr($query, 0, $name_start) .
            "`" .
            $temp_name .
            "`" .
            substr($query, $name_start + $name_length);
        if ($type === "CREATE") {
            if (stripos($query, "FOREIGN KEY") !== false) {
                preg_match_all(
                    '/REFERENCES\s+[`"]?([a-zA-Z0-9_]+)[`"]?\s*\(/i',
                    $query,
                    $fk_matches
                );
                if (!empty($fk_matches[1])) {
                    foreach ($fk_matches[1] as $referenced_table) {
                        if (
                            !isset($temp_tables_created[$referenced_table]) &&
                            !self::is_excluded_table(
                                $referenced_table,
                                $excluded_tables_cache
                            )
                        ) {
                            $temp_name_ref = OPTISTATE_Utils::generate_safe_table_name(
                                $referenced_table,
                                "optistate_temp_",
                                64
                            );
                            $temp_tables_created[
                                $referenced_table
                            ] = $temp_name_ref;
                        }
                    }
                }
            }
            if (count($temp_tables_created) > 1) {
                self::rewrite_foreign_key_references(
                    $result["query"],
                    $original_table,
                    $temp_tables_created
                );
            }
        }
        return $result;
    }
    private static function extract_ddl_table_name(string $query): ?array
    {
        $len = strlen($query);
        $pos = 0;
        $skip_token = function () use (&$pos, $len, $query): bool {
            if ($pos >= $len) {
                return false;
            }
            if ($query[$pos] === "`") {
                $pos++;
                while ($pos < $len) {
                    if ($query[$pos] === "`") {
                        $pos++;
                        if ($pos < $len && $query[$pos] === "`") {
                            $pos++;
                            continue;
                        }
                        break;
                    }
                    $pos++;
                }
            } else {
                while (
                    $pos < $len &&
                    $query[$pos] !== " " &&
                    $query[$pos] !== "\t" &&
                    $query[$pos] !== "\r" &&
                    $query[$pos] !== "\n"
                ) {
                    $pos++;
                }
            }
            while (
                $pos < $len &&
                ($query[$pos] === " " ||
                    $query[$pos] === "\t" ||
                    $query[$pos] === "\r" ||
                    $query[$pos] === "\n")
            ) {
                $pos++;
            }
            return true;
        };
        while (
            $pos < $len &&
            ($query[$pos] === " " ||
                $query[$pos] === "\t" ||
                $query[$pos] === "\r" ||
                $query[$pos] === "\n")
        ) {
            $pos++;
        }
        if (
            $pos + 2 < $len &&
            $query[$pos] === "/" &&
            $query[$pos + 1] === "*" &&
            $query[$pos + 2] === "!"
        ) {
            $pos += 3;
            while ($pos < $len && $query[$pos] >= "0" && $query[$pos] <= "9") {
                $pos++;
            }
            while (
                $pos < $len &&
                ($query[$pos] === " " || $query[$pos] === "\t")
            ) {
                $pos++;
            }
        }
        if (!preg_match("/\G(CREATE|DROP|ALTER)\s+/i", $query, $m, 0, $pos)) {
            return null;
        }
        $pos += strlen($m[0]);
        if (preg_match("/\GALGORITHM\s*=\s*/i", $query, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            $skip_token();
        }
        if (preg_match("/\GDEFINER\s*=\s*/i", $query, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            $skip_token();
            if ($pos < $len && $query[$pos] === "@") {
                $pos++;
                $skip_token();
            }
        }
        if (preg_match("/\GSQL\s+SECURITY\s+\S+\s+/i", $query, $m, 0, $pos)) {
            $pos += strlen($m[0]);
        }
        if (preg_match("/\GOR\s+REPLACE\s+/i", $query, $m, 0, $pos)) {
            $pos += strlen($m[0]);
        }
        if (!preg_match("/\G(?:TABLE|VIEW)\s+/i", $query, $m, 0, $pos)) {
            return null;
        }
        $pos += strlen($m[0]);
        if (preg_match("/\GIF\s+(?:NOT\s+)?EXISTS\s+/i", $query, $m, 0, $pos)) {
            $pos += strlen($m[0]);
        }
        if ($pos >= $len) {
            return null;
        }
        $name_start = $pos;
        $quote = $query[$pos];
        if ($quote === "`" || $quote === '"') {
            $pos++;
            $table_name = "";
            while ($pos < $len) {
                $c = $query[$pos];
                if ($c === $quote) {
                    $pos++;
                    if ($pos < $len && $query[$pos] === $quote) {
                        $table_name .= $quote;
                        $pos++;
                        continue;
                    }
                    break;
                }
                $table_name .= $c;
                $pos++;
            }
            $name_length = $pos - $name_start;
        } else {
            if (!preg_match("/\G([a-zA-Z0-9_]+)/i", $query, $m, 0, $pos)) {
                return null;
            }
            $table_name = $m[1];
            $name_length = strlen($m[1]);
            $pos += $name_length;
        }
        if ($table_name === "") {
            return null;
        }
        return [$table_name, $name_start, $name_length];
    }
    public static function is_excluded_table(
        string $table_name,
        array $excluded_tables_cache
    ): bool {
        if (in_array($table_name, $excluded_tables_cache, true)) {
            return true;
        }
        return strpos($table_name, self::EXCLUDED_TABLE_PREFIX) === 0;
    }
    private static function rewrite_foreign_key_references(
        string &$query,
        string $current_table,
        array $temp_tables_created
    ): void {
        $patterns = [];
        $replacements = [];
        foreach (
            $temp_tables_created
            as $ref_original_table => $ref_temp_table
        ) {
            $escaped_ref = preg_quote($ref_original_table, "/");
            $patterns[] =
                '/(REFERENCES\s+)(?:[`"]?[a-zA-Z0-9_]+[`"]?\.)?[`"]?' .
                $escaped_ref .
                '[`"]?(\s*\()/i';
            $replacements[] = '${1}`' . $ref_temp_table . '`$2';
        }
        if (!empty($patterns)) {
            $new_query = preg_replace($patterns, $replacements, $query);
            if ($new_query !== null) {
                $query = $new_query;
            }
        }
    }
    public static function parse_create_table_for_indexes(
        string $create_query,
        string $temp_table_name
    ): array {
        $alter_queries = [];
        if (!preg_match("/CREATE\s+TABLE/i", $create_query)) {
            return [
                "create_table_query" => $create_query,
                "alter_queries" => [],
            ];
        }
        $quoted_temp = "`" . str_replace("`", "``", $temp_table_name) . "`";
        $first_paren = strpos($create_query, "(");
        $last_paren = strrpos($create_query, ")");
        if (
            $first_paren === false ||
            $last_paren === false ||
            $last_paren <= $first_paren
        ) {
            $modified_query = preg_replace(
                '/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`\'"]?([a-zA-Z0-9_]+)[`\'"]?/i',
                'CREATE TABLE $1' . $quoted_temp,
                $create_query,
                1
            );
            return [
                "create_table_query" => $modified_query,
                "alter_queries" => [],
            ];
        }
        $create_header = substr($create_query, 0, $first_paren + 1);
        $definitions_str = substr(
            $create_query,
            $first_paren + 1,
            $last_paren - $first_paren - 1
        );
        $create_footer = substr($create_query, $last_paren);
        $create_header = preg_replace(
            '/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`\'"]?([a-zA-Z0-9_]+)[`\'"]?/i',
            'CREATE TABLE $1' . $quoted_temp,
            $create_header,
            1
        );
        $definitions = [];
        $len = strlen($definitions_str);
        $paren_level = 0;
        $in_quote = false;
        $quote_char = "";
        $current_def = "";
        for ($i = 0; $i < $len; $i++) {
            $char = $definitions_str[$i];
            if ($in_quote) {
                if ($char === "\\" && $i + 1 < $len) {
                    $current_def .= $char . $definitions_str[$i + 1];
                    $i++;
                    continue;
                }
                if ($char === $quote_char) {
                    if (
                        $i + 1 < $len &&
                        $definitions_str[$i + 1] === $quote_char
                    ) {
                        $current_def .= $char . $quote_char;
                        $i++;
                        continue;
                    }
                    $in_quote = false;
                }
            } elseif ($char === "'" || $char === '"' || $char === "`") {
                $in_quote = true;
                $quote_char = $char;
            } elseif ($char === "(") {
                $paren_level++;
            } elseif ($char === ")") {
                $paren_level--;
            } elseif ($char === "," && $paren_level === 0) {
                $definitions[] = trim($current_def);
                $current_def = "";
                continue;
            }
            $current_def .= $char;
        }
        if (!empty(trim($current_def))) {
            $definitions[] = trim($current_def);
        }
        $essential_definitions = [];
        $deferrable_indexes = [];
        foreach ($definitions as $def) {
            if (empty($def)) {
                continue;
            }
            if (
                preg_match("/^\s*PRIMARY\s+KEY\s*\(/i", $def) ||
                preg_match("/^\s*UNIQUE\s+(KEY|INDEX)\s+/i", $def) ||
                preg_match("/^\s*CONSTRAINT\s+/i", $def)
            ) {
                $essential_definitions[] = $def;
            } elseif (
                preg_match("/^\s*(KEY|INDEX|FULLTEXT|SPATIAL)\s+/i", $def)
            ) {
                $deferrable_indexes[] = $def;
            } else {
                $essential_definitions[] = $def;
            }
        }
        $max_deferred_per_table = 50;
        if (count($deferrable_indexes) > $max_deferred_per_table) {
            $spillover_count =
                count($deferrable_indexes) - $max_deferred_per_table;
            OPTISTATE_Utils::log_critical_error(
                "parse_create_table_for_indexes: deferred index limit exceeded. Spilling over safely inline.",
                [
                    "temp_table" => $temp_table_name,
                    "total_deferrable_indexes" => count($deferrable_indexes),
                    "spillover_inline_count" => $spillover_count,
                ]
            );
            $spillover_indexes = array_slice(
                $deferrable_indexes,
                $max_deferred_per_table
            );
            $deferrable_indexes = array_slice(
                $deferrable_indexes,
                0,
                $max_deferred_per_table
            );
            foreach ($spillover_indexes as $inline_idx) {
                $essential_definitions[] = $inline_idx;
            }
        }
        $new_create_query =
            $create_header .
            "\n" .
            implode(",\n", $essential_definitions) .
            "\n" .
            $create_footer;
        if (!empty($deferrable_indexes)) {
            $alter_prefix = "ALTER TABLE $quoted_temp ";
            $alter_parts = [];
            foreach ($deferrable_indexes as $index_line) {
                $alter_parts[] = "ADD " . $index_line;
            }
            $max_indexes_per_alter = 10;
            $chunks = array_chunk($alter_parts, $max_indexes_per_alter);
            foreach ($chunks as $chunk) {
                $alter_queries[] = $alter_prefix . implode(", ", $chunk) . ";";
            }
        }
        return [
            "create_table_query" => $new_create_query,
            "alter_queries" => $alter_queries,
        ];
    }
    public static function clean_create_statement(string $query): string
    {
        $current_query = (string) $query;
        $replaced = preg_replace(
            '/DEFINER\s*=\s*[`\'"]?[^`\'"\s@]+[`\'"]?(?:@[`\'"]?[^`\'"\s]+[`\'"]?)?\s+/i',
            "",
            $current_query
        );
        if ($replaced !== null) {
            $current_query = $replaced;
        }
        $replaced = preg_replace(
            '/CONSTRAINT\s+[`\'"]?[a-zA-Z0-9_]+[`\'"]?\s+(FOREIGN\s+KEY)/i',
            '$1',
            $current_query
        );
        if ($replaced !== null) {
            $current_query = $replaced;
        }
        static $mysql_version_cache = null;
        static $mysql_version_checked = false;
        if (!$mysql_version_checked) {
            global $wpdb;
            $mysql_version_cache =
                isset($wpdb) && method_exists($wpdb, "db_version")
                    ? $wpdb->db_version()
                    : null;
            $mysql_version_checked = true;
        }
        if ($mysql_version_cache !== null) {
            $mysql_version = $mysql_version_cache;
            $collation_map = [
                "utf8mb4_uca1400_ai_ci" => [
                    "fallback_8_0" => "utf8mb4_0900_ai_ci",
                    "fallback_5_7" => "utf8mb4_unicode_520_ci",
                ],
                "utf8mb4_0900_ai_ci" => [
                    "fallback_5_7" => "utf8mb4_unicode_520_ci",
                ],
            ];
            foreach ($collation_map as $new_collation => $fallbacks) {
                if (strpos($current_query, $new_collation) !== false) {
                    if (version_compare($mysql_version, "8.0.0", ">=")) {
                        $fallback =
                            $fallbacks["fallback_8_0"] ?? $new_collation;
                    } else {
                        $fallback =
                            $fallbacks["fallback_5_7"] ??
                            "utf8mb4_unicode_520_ci";
                    }
                    $current_query = str_replace(
                        $new_collation,
                        $fallback,
                        $current_query
                    );
                }
            }
        }
        return $current_query;
    }
    public static function fast_insert_rewrite(
        string $insert_query,
        string $original_table,
        string $temp_table
    ): string {
        if (
            empty($original_table) ||
            empty($temp_table) ||
            $original_table === $temp_table ||
            strlen($insert_query) < 10
        ) {
            return $insert_query;
        }
        static $pattern_cache = [];
        if (!isset($pattern_cache[$original_table])) {
            $quoted_orig = preg_quote($original_table, "/");
            $pattern =
                "/^(INSERT\s+(?:IGNORE\s+)?INTO\s+|REPLACE\s+INTO\s+)(?:`" .
                $quoted_orig .
                '`|"' .
                $quoted_orig .
                '"|(?<![a-zA-Z0-9_$])' .
                $quoted_orig .
                '(?![a-zA-Z0-9_$]))(?=\s|\()/i';
            if (@preg_match($pattern, "") === false) {
                return $insert_query;
            }
            $pattern_cache[$original_table] = $pattern;
            if (count($pattern_cache) > 500) {
                unset($pattern_cache[array_key_first($pattern_cache)]);
            }
        }
        $new_query = preg_replace(
            $pattern_cache[$original_table],
            '${1}`' . $temp_table . "`",
            $insert_query,
            1
        );
        return $new_query ?? $insert_query;
    }
    public static function split_and_retry_insert(
        $db,
        string $query,
        string $original_table,
        string $temp_table
    ): bool {
        if (
            !preg_match(
                "/(\sVALUES\s*)(\()/i",
                $query,
                $matches,
                PREG_OFFSET_CAPTURE
            )
        ) {
            OPTISTATE_Utils::log_critical_error(
                "split_and_retry_insert: Could not find VALUES clause",
                ["query_preview" => substr($query, 0, 200)]
            );
            return false;
        }
        $data_start_offset = $matches[2][1];
        $header = substr($query, 0, $data_start_offset);
        $data = substr($query, $data_start_offset);
        $data = rtrim(trim($data), ";");
        $len = strlen($data);
        $current_row = "";
        $depth = 0;
        $in_quote = false;
        $quote_char = "";
        $rows_inserted = 0;
        if (!$db->query("START TRANSACTION")) {
            OPTISTATE_Utils::log_critical_error(
                "split_and_retry_insert: failed to start transaction",
                ["error" => $db->error]
            );
            return false;
        }
        for ($i = 0; $i < $len; $i++) {
            $char = $data[$i];
            if ($in_quote) {
                if ($char === "\\") {
                    $current_row .= $char . ($data[$i + 1] ?? "");
                    $i++;
                    continue;
                }
                if ($char === $quote_char) {
                    if (isset($data[$i + 1]) && $data[$i + 1] === $quote_char) {
                        $current_row .= $char . $char;
                        $i++;
                        continue;
                    }
                    $in_quote = false;
                }
            } else {
                if ($char === "'" || $char === '"') {
                    $in_quote = true;
                    $quote_char = $char;
                } elseif ($char === "(") {
                    $depth++;
                } elseif ($char === ")") {
                    $depth--;
                } elseif ($char === "," && $depth === 0) {
                    $full_query = $header . $current_row;
                    if (!$db->query($full_query)) {
                        $db->query("ROLLBACK");
                        OPTISTATE_Utils::log_critical_error(
                            "split_and_retry_insert: batch insert failed",
                            [
                                "error" => $db->error,
                                "query_preview" => substr($full_query, 0, 300),
                            ]
                        );
                        return false;
                    }
                    $rows_inserted++;
                    $current_row = "";
                    continue;
                }
            }
            $current_row .= $char;
        }
        if (!empty($current_row)) {
            $full_query = $header . $current_row;
            if (!$db->query($full_query)) {
                $db->query("ROLLBACK");
                OPTISTATE_Utils::log_critical_error(
                    "split_and_retry_insert: final insert failed",
                    [
                        "error" => $db->error,
                        "query_preview" => substr($full_query, 0, 300),
                    ]
                );
                return false;
            }
            $rows_inserted++;
        }
        $db->query("COMMIT");
        return true;
    }
}