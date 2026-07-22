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
    private array $pattern_cache = [];
    private int $last_replace_count = 0;
    private const REGEX_BOUNDARY_FMT = "/(?<![\p{L}\p{N}_\-'])%s(?![\p{L}\p{N}_\-'])/%s";
    private const PREVIEW_MAX_BYTES = 524288;
    private const EXECUTE_BATCH_SIZE = 300;
    private const DRY_RUN_BATCH_SIZE = 200;
    private const LOCK_TTL = 600;
    private const MAX_SEARCH_LEN = 600;
    private const MAX_REPLACE_LEN = 4096;
    private const PATTERN_CACHE_LIMIT = 32;
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

    private static function quote_ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier");
        }
        return "`" . $name . "`";
    }
    private function build_boundary_pattern(
        string $search,
        bool $case_sensitive,
        bool $partial_match
    ): string {
        $key =
            ($case_sensitive ? "s" : "i") .
            "|" .
            ($partial_match ? "p" : "w") .
            "|" .
            $search;
        if (isset($this->pattern_cache[$key])) {
            return $this->pattern_cache[$key];
        }
        $escaped = preg_quote($search, "/");
        $flags = $case_sensitive ? "u" : "iu";
        $pattern = $partial_match
            ? "/" . $escaped . "/" . $flags
            : sprintf(self::REGEX_BOUNDARY_FMT, $escaped, $flags);
        if (count($this->pattern_cache) >= self::PATTERN_CACHE_LIMIT) {
            $this->pattern_cache = [];
        }
        $this->pattern_cache[$key] = $pattern;
        return $pattern;
    }
    private function build_byte_pattern(
        string $search,
        bool $case_sensitive,
        bool $partial_match
    ): string {
        $escaped = preg_quote($search, "/");
        $flags = $case_sensitive ? "" : "i";
        if ($partial_match) {
            return "/" . $escaped . "/" . $flags;
        }
        return "/(?<![A-Za-z0-9_\\-'])" .
            $escaped .
            "(?![A-Za-z0-9_\\-'])/" .
            $flags;
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
            ? sanitize_text_field(wp_unslash($_POST["lock_token"]))
            : "";
        $stored_token = get_transient($lock_key);
        if (
            $token === "" ||
            $stored_token === false ||
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
    private function count_text_occurrences(
        string $text,
        string $search,
        bool $case_sensitive,
        bool $partial_match
    ): int {
        if ($text === "" || $search === "") {
            return 0;
        }
        if ($partial_match) {
            if ($case_sensitive) {
                return substr_count($text, $search);
            }
            $pattern = "/" . preg_quote($search, "/") . "/iu";
            $count = @preg_match_all($pattern, $text);
            if ($count === false || $count === null) {
                $count = @preg_match_all(
                    "/" . preg_quote($search, "/") . "/i",
                    $text
                );
            }
            if ($count === false || $count === null) {
                $count = substr_count(strtolower($text), strtolower($search));
            }
            return (int) $count;
        }
        $pattern = $this->build_boundary_pattern(
            $search,
            $case_sensitive,
            false
        );
        $matches = @preg_match_all($pattern, $text);
        if ($matches === false || $matches === null) {
            $matches = @preg_match_all(
                $this->build_byte_pattern($search, $case_sensitive, false),
                $text
            );
            if ($matches === false || $matches === null) {
                OPTISTATE_Utils::log_critical_error(
                    "preg_match_all failed in count_text_occurrences",
                    [
                        "text_length" => strlen($text),
                        "search_length" => strlen($search),
                    ]
                );
                return 0;
            }
        }
        return (int) $matches;
    }
    public function get_last_replace_count(): int
    {
        return $this->last_replace_count;
    }
    private function resolve_tables(array $tables_input): array
    {
        global $wpdb;
        $valid_db_tables = OPTISTATE_Utils::get_all_tables();
        if (!empty($tables_input) && $tables_input[0] === "all") {
            $tables = $valid_db_tables;
        } else {
            $tables = array_intersect(
                array_map("sanitize_text_field", $tables_input),
                $valid_db_tables
            );
        }
        $process_store_table = $this->main_plugin->process_store->get_table_name();
        $protected = array_merge(
            [
                $process_store_table,
                $wpdb->prefix . "optistate_backup_metadata",
                $wpdb->prefix . OPTISTATE_Login_Protection::TABLE_NAME,
                $wpdb->prefix . "optistate_core_data",
                $wpdb->prefix . "optistate_trash",
            ],
            self::get_additional_protected_tables()
        );
        return array_values(array_diff($tables, $protected));
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
        $protected = $this->get_protected_options();
        $placeholders = array_fill(0, count($protected), "%s");
        $sql =
            " AND `option_name` NOT IN (" .
            implode(", ", $placeholders) .
            ")" .
            " AND `option_name` NOT LIKE %s" .
            " AND `option_name` NOT LIKE %s" .
            " AND `option_name` NOT LIKE %s" .
            " AND `option_name` NOT LIKE %s";
        $values = array_merge($protected, [
            "\_transient\_optistate\_%",
            "\_transient\_timeout\_optistate\_%",
            "\_site\_transient\_optistate\_%",
            "\_site\_transient\_timeout\_optistate\_%",
        ]);
        return ["sql" => $sql, "values" => $values];
    }
    private static function get_additional_protected_tables(): array
    {
        return apply_filters("optistate_protected_tables", []);
    }
    private static function is_transactional_engine(string $table): bool
    {
        global $wpdb;
        static $request_cache = [];
        if (isset($request_cache[$table])) {
            return $request_cache[$table];
        }
        $cache_key = "engine_" . $table;
        $cached = wp_cache_get($cache_key, "optistate_sr");
        if ($cached !== false) {
            $request_cache[$table] = (bool) $cached;
            return $request_cache[$table];
        }
        $engine = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $table
            )
        );
        $ok = in_array(
            strtoupper((string) $engine),
            self::TRANSACTIONAL_ENGINES,
            true
        );
        wp_cache_set($cache_key, $ok ? 1 : 0, "optistate_sr", HOUR_IN_SECONDS);
        $request_cache[$table] = $ok;
        return $ok;
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
            "SHOW COLUMNS FROM " . self::quote_ident($table),
            ARRAY_A
        );
        $pk_columns = [];
        $text_cols = [];
        foreach ((array) $columns as $col) {
            if ($col["Key"] === "PRI") {
                $pk_columns[] = $col["Field"];
            }
            if ($col["Field"] === "guid") {
                continue;
            }
            if (preg_match("/char|text|blob/i", $col["Type"])) {
                $text_cols[] = $col["Field"];
            }
        }
        $info = ["pk" => $pk_columns, "text_cols" => $text_cols];
        wp_cache_set($cache_key, $info, "optistate_sr", HOUR_IN_SECONDS);
        return $info;
    }
    public function ajax_dry_run(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $reset =
            isset($_POST["reset"]) && wp_unslash($_POST["reset"]) === "true";
        if ($reset && !OPTISTATE_Utils::check_rate_limit("sr_dry_run", 5)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        $search = isset($_POST["search"]) ? wp_unslash($_POST["search"]) : "";
        $tables_input = isset($_POST["tables"])
            ? array_map(
                "sanitize_text_field",
                array_map("wp_unslash", (array) $_POST["tables"])
            )
            : ["all"];
        $case_sensitive_post =
            isset($_POST["case_sensitive"]) && $_POST["case_sensitive"] === "1";
        $partial_match_post =
            isset($_POST["partial_match"]) && $_POST["partial_match"] === "1";
        if (empty($search)) {
            OPTISTATE_Utils::send_json_error(
                __("Please enter a search term.", "optistate")
            );
            return;
        }
        if (strlen($search) > self::MAX_SEARCH_LEN) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Search term is too long. Maximum length is 600 characters.",
                    "optistate"
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
        if ($reset || !$state) {
            $state = [
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
        $case_sensitive = $state["case_sensitive"];
        $partial_match = $state["partial_match"];
        $this->case_sensitive = $case_sensitive;
        $this->partial_match = $partial_match;
        $start_time = microtime(true);
        $max_exec_time = 4.0;
        $total_tables = count($state["tables"]);
        $preview_full =
            count($state["preview"]) >= 500 ||
            $state["preview_bytes"] >= self::PREVIEW_MAX_BYTES;
        while ($state["current_idx"] < $total_tables) {
            if (microtime(true) - $start_time > $max_exec_time) {
                $this->save_state_and_lock(
                    $transient_key,
                    $state,
                    $lock_key,
                    $token
                );
                $percent = round(($state["current_idx"] / $total_tables) * 100);
                OPTISTATE_Utils::send_json_success([
                    "status" => "running",
                    "percent" => $percent,
                    "lock_token" => $token,
                    "message" => sprintf(
                        __("Scanning table %s of %s...", "optistate"),
                        number_format_i18n($state["current_idx"] + 1),
                        number_format_i18n($total_tables)
                    ),
                ]);
                return;
            }
            $table = $state["tables"][$state["current_idx"]];
            if (!OPTISTATE_Utils::validate_table_name($table)) {
                $state["current_idx"]++;
                continue;
            }
            if (!self::is_transactional_engine($table)) {
                $state["skipped_non_transactional"][] = $table;
                $state["current_idx"]++;
                continue;
            }
            $col_info = $this->get_table_columns_info($table);
            $pk_columns = $col_info["pk"];
            $text_cols = $col_info["text_cols"];
            if (count($pk_columns) !== 1) {
                if (count($pk_columns) > 1) {
                    $state["skipped_composite"][] = $table;
                }
                $state["current_idx"]++;
                continue;
            }
            $primary_key = $pk_columns[0];
            if (empty($text_cols)) {
                $state["current_idx"]++;
                continue;
            }
            $result = $this->scan_table_dry_run(
                $table,
                $primary_key,
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
        delete_transient($lock_key);
        delete_transient($transient_key);
        $response_data = [
            "total_matches" => $state["total_matches"],
            "unique_rows" => $state["unique_rows"],
            "tables_affected" => $state["tables_affected"],
            "preview" => $state["preview"],
            "preview_occurrences" => $state["preview_occurrences"],
            "has_serialized_data" => $state["has_serialized_data"],
            "skipped_non_transactional" => $state["skipped_non_transactional"],
            "skipped_composite" => $state["skipped_composite"],
        ];
        if ($state["counts_capped"]) {
            $response_data["counts_capped"] = true;
            $response_data["counts_capped_note"] = __(
                "Some columns returned 200+ matches; displayed counts may be understated.",
                "optistate"
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
        $primary_key_q = self::quote_ident($primary_key);
        $table_q = self::quote_ident($table);
        $like_value = "%" . $wpdb->esc_like($search) . "%";
        $where_parts = [];
        $where_values = [];
        foreach ($text_cols as $col) {
            $col_q = self::quote_ident($col);
            if ($case_sensitive) {
                $where_parts[] = "CAST($col_q AS BINARY) LIKE CAST(%s AS BINARY)";
            } else {
                $where_parts[] = "$col_q LIKE %s";
            }
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
        $select_cols = array_unique(array_merge([$primary_key], $text_cols));
        $select_list = implode(
            ", ",
            array_map(static fn($c) => self::quote_ident($c), $select_cols)
        );
        $sql = $wpdb->prepare(
            "SELECT $select_list FROM $table_q WHERE ($where_fmt)" .
                $exclude_sql .
                " ORDER BY $primary_key_q ASC LIMIT %d",
            array_merge($where_values, $exclude_values, [
                self::DRY_RUN_BATCH_SIZE,
            ])
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (count($rows) === self::DRY_RUN_BATCH_SIZE) {
            $state["counts_capped"] = true;
        }
        $table_matches = 0;
        $unique_rows_in_table = [];
        $has_serialized = false;
        $became_full = false;
        foreach ($rows as $row) {
            $pk_val = $row[$primary_key];
            $unique_rows_in_table[$pk_val] = true;
            foreach ($text_cols as $col) {
                $original = $row[$col] ?? "";
                if (!is_string($original) || $original === "") {
                    continue;
                }
                if (!$this->string_contains($original, $search)) {
                    continue;
                }
                if (is_serialized($original)) {
                    $has_serialized = true;
                }
                $preview_values = $this->find_all_preview_values(
                    $original,
                    $search
                );
                if (empty($preview_values)) {
                    continue;
                }
                $cell_occurrences = 0;
                foreach ($preview_values as $val) {
                    if (is_string($val)) {
                        $cell_occurrences += $this->count_text_occurrences(
                            $val,
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
                if (!$preview_full && !$became_full) {
                    $is_serialized = is_serialized($original);
                    foreach ($preview_values as $preview_value) {
                        $preview_text = $this->get_highlighted_snippet(
                            $preview_value,
                            $search,
                            $case_sensitive,
                            $partial_match,
                            140
                        );
                        $visible_occurrences = substr_count(
                            $preview_text,
                            '<strong style="background:#ffeb3b;">'
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
                        if (
                            count($state["preview"]) >= 500 ||
                            $state["preview_bytes"] >= self::PREVIEW_MAX_BYTES
                        ) {
                            $became_full = true;
                            break;
                        }
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
        $reset =
            isset($_POST["reset"]) && wp_unslash($_POST["reset"]) === "true";
        if ($reset && !OPTISTATE_Utils::check_rate_limit("sr_execute", 10)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        $search = isset($_POST["search"]) ? wp_unslash($_POST["search"]) : "";
        $replace = isset($_POST["replace"])
            ? wp_unslash($_POST["replace"])
            : "";
        $tables_input = isset($_POST["tables"])
            ? array_map(
                "sanitize_text_field",
                array_map("wp_unslash", (array) $_POST["tables"])
            )
            : ["all"];
        $case_sensitive_post =
            isset($_POST["case_sensitive"]) && $_POST["case_sensitive"] === "1";
        $partial_match_post =
            isset($_POST["partial_match"]) && $_POST["partial_match"] === "1";
        if (empty($search)) {
            OPTISTATE_Utils::send_json_error(
                __("Search term cannot be empty.", "optistate")
            );
            return;
        }
        if (strlen($search) > self::MAX_SEARCH_LEN) {
            OPTISTATE_Utils::send_json_error(
                __("Search term is too long.", "optistate")
            );
            return;
        }
        if (strlen($replace) > self::MAX_REPLACE_LEN) {
            OPTISTATE_Utils::send_json_error(
                sprintf(
                    __(
                        "Replacement value is too long. Maximum length is %d characters.",
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
        if ($reset || !$state) {
            $state = [
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
                "case_sensitive" => $case_sensitive_post,
                "partial_match" => $partial_match_post,
            ];
        }
        $case_sensitive = $state["case_sensitive"];
        $partial_match = $state["partial_match"];
        $start_time = microtime(true);
        $max_exec_time = 4.0;
        $total_tables = count($state["tables"]);
        while ($state["current_idx"] < $total_tables) {
            if (microtime(true) - $start_time > $max_exec_time) {
                $this->save_state_and_lock(
                    $transient_key,
                    $state,
                    $lock_key,
                    $token
                );
                $percent = round(($state["current_idx"] / $total_tables) * 100);
                OPTISTATE_Utils::send_json_success([
                    "status" => "running",
                    "percent" => $percent,
                    "lock_token" => $token,
                    "message" => sprintf(
                        __("Processing table %s of %s...", "optistate"),
                        number_format_i18n($state["current_idx"] + 1),
                        number_format_i18n($total_tables)
                    ),
                ]);
                return;
            }
            $table = $state["tables"][$state["current_idx"]];
            if (!OPTISTATE_Utils::validate_table_name($table)) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            if (!self::is_transactional_engine($table)) {
                $state["errors"][] = sprintf(
                    __(
                        "Skipped %s: non-transactional storage engine (cannot safely roll back).",
                        "optistate"
                    ),
                    $table
                );
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            $col_info = $this->get_table_columns_info($table);
            $pk_columns = $col_info["pk"];
            $text_cols = $col_info["text_cols"];
            if (count($pk_columns) !== 1) {
                $state["errors"][] =
                    count($pk_columns) > 1
                        ? sprintf(
                            __(
                                "Skipped %s: composite primary keys not supported.",
                                "optistate"
                            ),
                            $table
                        )
                        : sprintf(
                            __(
                                "Skipped %s: no primary key found.",
                                "optistate"
                            ),
                            $table
                        );
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            $primary_key = $pk_columns[0];
            if (empty($text_cols)) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                continue;
            }
            $abort = $this->replace_table_execute(
                $table,
                $primary_key,
                $text_cols,
                $search,
                $replace,
                $case_sensitive,
                $partial_match,
                $state,
                $start_time,
                $max_exec_time,
                $total_tables,
                $token
            );
            if ($abort === "error") {
                return;
            }
            if ($abort === "timeout") {
                return;
            }
        }
        if (!empty($state["deferred_updates"])) {
            global $wpdb;
            $wpdb->query("START TRANSACTION");
            $deferred_success = true;
            $deferred_rows = 0;
            foreach ($state["deferred_updates"] as $deferred) {
                $result = $wpdb->update(
                    $deferred["table"],
                    $deferred["data"],
                    $deferred["where"]
                );
                if ($result === false || !empty($wpdb->last_error)) {
                    $deferred_success = false;
                    $error_msg =
                        $wpdb->last_error !== ""
                            ? $wpdb->last_error
                            : "Unknown DB error";
                    $state["errors"][] = sprintf(
                        __("Deferred update failed for %s", "optistate"),
                        $deferred["option_name"] ?? "unknown"
                    );
                    OPTISTATE_Utils::log_critical_error(
                        "Deferred search/replace update failed",
                        [
                            "table" => $deferred["table"],
                            "option_name" => $deferred["option_name"] ?? null,
                            "where" => $deferred["where"],
                            "error" => $error_msg,
                        ]
                    );
                    break;
                }
                if ($result > 0) {
                    $deferred_rows++;
                    if (($deferred["option_name"] ?? "") !== "") {
                        $state["touched_options"][
                            $deferred["option_name"]
                        ] = true;
                    }
                }
            }
            if ($deferred_success) {
                $siteurl_deferred = false;
                $home_deferred = false;
                $new_siteurl = null;
                $new_home = null;
                foreach ($state["deferred_updates"] as $deferred) {
                    $option = $deferred["option_name"] ?? "";
                    if (
                        $option === "siteurl" &&
                        isset($deferred["data"]["option_value"])
                    ) {
                        $siteurl_deferred = true;
                        $new_siteurl = $deferred["data"]["option_value"];
                    }
                    if (
                        $option === "home" &&
                        isset($deferred["data"]["option_value"])
                    ) {
                        $home_deferred = true;
                        $new_home = $deferred["data"]["option_value"];
                    }
                }
                if ($siteurl_deferred || $home_deferred) {
                    $valid_url = true;
                    if (
                        $siteurl_deferred &&
                        (!is_string($new_siteurl) ||
                            $new_siteurl === "" ||
                            !wp_http_validate_url($new_siteurl))
                    ) {
                        $valid_url = false;
                    }
                    if (
                        $home_deferred &&
                        (!is_string($new_home) ||
                            $new_home === "" ||
                            !wp_http_validate_url($new_home))
                    ) {
                        $valid_url = false;
                    }
                    if (!$valid_url) {
                        $wpdb->query("ROLLBACK");
                        delete_transient($transient_key);
                        delete_transient($lock_key);
                        OPTISTATE_Utils::log_critical_error(
                            "siteurl/home would become invalid after replacement",
                            ["siteurl" => $new_siteurl, "home" => $new_home]
                        );
                        OPTISTATE_Utils::send_json_error(
                            __(
                                "Critical error: siteurl or home would become invalid after replacement.",
                                "optistate"
                            )
                        );
                        return;
                    }
                }
                $wpdb->query("COMMIT");
                $state["rows_affected"] += $deferred_rows;
            } else {
                $wpdb->query("ROLLBACK");
                delete_transient($transient_key);
                delete_transient($lock_key);
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Replacement partially failed: deferred options (siteurl / home) could not be updated and were rolled back.",
                        "optistate"
                    ),
                    500
                );
                return;
            }
        }
        delete_transient($transient_key);
        delete_transient($lock_key);
        $touched = array_keys($state["touched_options"] ?? []);
        if (!empty($touched) && count($touched) <= 200) {
            foreach ($touched as $opt) {
                wp_cache_delete($opt, "options");
            }
            wp_cache_delete("alloptions", "options");
        } else {
            wp_cache_flush();
        }
        $log_message = sprintf(
            "↳↰ Search & Replace Executed by {username}: '%s' -> '%s' (%s rows)",
            $search,
            $replace,
            number_format_i18n($state["rows_affected"])
        );
        $this->main_plugin->log_entry($log_message);
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
            "rows" => $state["rows_affected"],
            "occurrences" => $state["occurrences_replaced"],
        ];
        if (!empty($state["errors"])) {
            $response["warnings"] = array_slice($state["errors"], 0, 10);
            $response["total_errors"] = count($state["errors"]);
        }
        OPTISTATE_Utils::send_json_success($response);
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
        float $max_exec_time,
        int $total_tables,
        string $token
    ): string {
        global $wpdb;
        $primary_key_q = self::quote_ident($primary_key);
        $table_q = self::quote_ident($table);
        $like_value = "%" . $wpdb->esc_like($search) . "%";
        $transient_key = "optistate_sr_exec_" . get_current_user_id();
        $lock_key = "optistate_sr_exec_lock_" . get_current_user_id();
        $where_parts = [];
        $where_values = [];
        foreach ($text_cols as $col) {
            $col_q = self::quote_ident($col);
            if ($case_sensitive) {
                $where_parts[] = "CAST($col_q AS BINARY) LIKE CAST(%s AS BINARY)";
            } else {
                $where_parts[] = "$col_q LIKE %s";
            }
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
        $select_cols = array_unique(array_merge([$primary_key], $text_cols));
        $select_list = implode(
            ", ",
            array_map(static fn($c) => self::quote_ident($c), $select_cols)
        );
        while (true) {
            if (microtime(true) - $start_time > $max_exec_time) {
                $this->save_state_and_lock(
                    $transient_key,
                    $state,
                    $lock_key,
                    $token
                );
                $percent = round(($state["current_idx"] / $total_tables) * 100);
                OPTISTATE_Utils::send_json_success([
                    "status" => "running",
                    "percent" => $percent,
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
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (empty($rows)) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                return "done";
            }
            $wpdb->query("START TRANSACTION");
            $batch_success = true;
            $batch_rows = 0;
            $batch_error = "";
            foreach ($rows as $row) {
                $update_data = [];
                $deferred_data = [];
                $pk_val = $row[$primary_key];
                $option_name = $is_options_table
                    ? $row["option_name"] ?? ""
                    : "";
                $protection =
                    $is_options_table && $option_name
                        ? $this->should_protect_option($option_name)
                        : false;
                if ($protection === "skip") {
                    continue;
                }
                foreach ($text_cols as $col) {
                    $original = $row[$col];
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
                    if ($modified !== $original) {
                        $state["occurrences_replaced"] +=
                            $this->last_replace_count;
                        if ($protection === "defer") {
                            $deferred_data[$col] = $modified;
                        } else {
                            $update_data[$col] = $modified;
                        }
                    }
                }
                if (!empty($update_data)) {
                    $result = $wpdb->update($table, $update_data, [
                        $primary_key => $pk_val,
                    ]);
                    if ($result === false || !empty($wpdb->last_error)) {
                        $batch_success = false;
                        $batch_error =
                            $wpdb->last_error !== ""
                                ? $wpdb->last_error
                                : "Unknown DB error";
                        $state["errors"][] = sprintf(
                            __(
                                "Update failed for %s (ID: %s): %s",
                                "optistate"
                            ),
                            $table,
                            $pk_val,
                            $batch_error
                        );
                        OPTISTATE_Utils::log_critical_error(
                            "Search/replace update failed",
                            [
                                "table" => $table,
                                "pk_value" => $pk_val,
                                "error" => $batch_error,
                                "sql_data" => $update_data,
                            ]
                        );
                        break;
                    }
                    if ($result > 0) {
                        $batch_rows++;
                        if ($is_options_table && $option_name !== "") {
                            $state["touched_options"][$option_name] = true;
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
            if ($batch_success) {
                $wpdb->query("COMMIT");
                $state["rows_affected"] += $batch_rows;
                $last_row = end($rows);
                $state["last_pk"] = $last_row[$primary_key];
            } else {
                $wpdb->query("ROLLBACK");
                delete_transient($transient_key);
                delete_transient($lock_key);
                OPTISTATE_Utils::send_json_error(
                    sprintf(
                        __("Search & Replace aborted: %s", "optistate"),
                        $batch_error
                    ),
                    500
                );
                return "error";
            }
            if ($batch_rows > 0) {
                $state["gc_counter"] += $batch_rows;
                if ($state["gc_counter"] >= 100) {
                    gc_collect_cycles();
                    $state["gc_counter"] = 0;
                }
            }
            if (count($rows) < self::EXECUTE_BATCH_SIZE) {
                $state["current_idx"]++;
                $state["last_pk"] = null;
                return "done";
            }
        }
    }
    private function should_protect_option(string $option_name)
    {
        if (in_array($option_name, $this->get_protected_options(), true)) {
            return "skip";
        }
        if (
            strpos($option_name, "_transient_optistate_") === 0 ||
            strpos($option_name, "_transient_timeout_optistate_") === 0 ||
            strpos($option_name, "_site_transient_optistate_") === 0 ||
            strpos($option_name, "_site_transient_timeout_optistate_") === 0
        ) {
            return "skip";
        }
        if (in_array($option_name, $this->get_deferred_options(), true)) {
            return "defer";
        }
        return false;
    }
    public function replace_data(
        string $from,
        string $to,
        $data,
        bool $case_sensitive = false,
        bool $partial_match = false
    ) {
        $this->case_sensitive = $case_sensitive;
        $this->partial_match = $partial_match;
        $this->last_replace_count = 0;
        return $this->recursive_unserialize_replace(
            $from,
            $to,
            $data,
            false,
            $case_sensitive
        );
    }
    public function get_highlighted_snippet(
        string $text,
        string $search,
        bool $case_sensitive = false,
        bool $partial_match = false,
        int $length = 140
    ): string {
        $this->case_sensitive = $case_sensitive;
        $this->partial_match = $partial_match;
        $snippet = $this->get_snippet($text, $search, $length, $case_sensitive);
        $preview_text = esc_html($snippet);
        $modifier = $case_sensitive ? "" : "i";
        $search_html_escaped = esc_html($search);
        $search_regex_quoted = preg_quote($search_html_escaped, "/");
        $highlight_pattern = $partial_match
            ? "/" . $search_regex_quoted . "/" . $modifier . "u"
            : sprintf(
                self::REGEX_BOUNDARY_FMT,
                $search_regex_quoted,
                $modifier . "u"
            );
        $highlighted_text = @preg_replace(
            $highlight_pattern,
            '<strong style="background:#ffeb3b;">$0</strong>',
            $preview_text
        );
        if ($highlighted_text === null) {
            $err = function_exists("preg_last_error_msg")
                ? preg_last_error_msg()
                : "preg error code " . preg_last_error();
            OPTISTATE_Utils::log_critical_error(
                "preg_replace failed in get_highlighted_snippet",
                ["error" => $err, "pattern" => $highlight_pattern]
            );
            return $preview_text;
        }
        return $highlighted_text;
    }
    private static function contains_incomplete_class(
        $data,
        int $depth = 0
    ): bool {
        if ($depth > 100) {
            return false;
        }
        if ($data instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if (is_array($data)) {
            foreach ($data as $v) {
                if (self::contains_incomplete_class($v, $depth + 1)) {
                    return true;
                }
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $v) {
                if (self::contains_incomplete_class($v, $depth + 1)) {
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
        int $depth = 0
    ) {
        if ($depth > 100) {
            return $data;
        }
        try {
            if (is_string($data)) {
                if ($case_sensitive) {
                    if (false === strpos($data, $from)) {
                        return $data;
                    }
                } else {
                    if (false === stripos($data, $from)) {
                        return $data;
                    }
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
                        $case_sensitive
                    );
                } else {
                    $data = $this->recursive_unserialize_replace(
                        $from,
                        $to,
                        $unserialized,
                        true,
                        $case_sensitive,
                        $depth + 1
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
                        $depth + 1
                    );
                }
                $data = $_tmp;
                unset($_tmp);
            } elseif (is_object($data)) {
                if (self::is_object_cloneable($data)) {
                    $_tmp = clone $data;
                    $props = get_object_vars($data);
                    foreach ($props as $key => $value) {
                        if (is_int($key)) {
                            continue;
                        }
                        if (
                            is_string($key) &&
                            isset($key[0]) &&
                            ord($key[0]) === 0
                        ) {
                            continue;
                        }
                        $_tmp->$key = $this->recursive_unserialize_replace(
                            $from,
                            $to,
                            $value,
                            false,
                            $case_sensitive,
                            $depth + 1
                        );
                    }
                    $data = $_tmp;
                    unset($_tmp);
                } elseif ($data instanceof \__PHP_Incomplete_Class) {
                    $re_serialized = serialize($data);
                    $replaced = $this->replace_in_serialized_string(
                        $from,
                        $to,
                        $re_serialized,
                        $case_sensitive
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
                                    "original_length" => strlen($re_serialized),
                                    "replaced_length" => strlen($replaced),
                                ]
                            );
                        }
                    }
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
                        $depth + 1
                    );
                }
            } else {
                if (is_string($data)) {
                    $data = $this->perform_string_replace(
                        $from,
                        $to,
                        $data,
                        $case_sensitive
                    );
                }
            }
            if ($serialised) {
                return serialize($data);
            }
        } catch (\ReflectionException $e) {
            OPTISTATE_Utils::log_critical_error(
                "Reflection error in search/replace",
                ["message" => $e->getMessage(), "depth" => $depth]
            );
            return $data;
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Unexpected error in search/replace",
                [
                    "message" => $e->getMessage(),
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "depth" => $depth,
                ]
            );
            return $data;
        }
        return $data;
    }
    private function replace_in_serialized_string(
        string $from,
        string $to,
        string $data,
        bool $case_sensitive
    ): string {
        $result = "";
        $i = 0;
        $len = strlen($data);
        $stack = [];
        $partial = $this->partial_match;
        $to_escaped = addcslashes($to, '\\$');
        $boundary_pattern = $partial
            ? null
            : $this->build_boundary_pattern($from, $case_sensitive, false);
        $byte_pattern = $partial
            ? null
            : $this->build_byte_pattern($from, $case_sensitive, false);
        $partial_ci_pattern =
            $partial && !$case_sensitive
                ? "/" . preg_quote($from, "/") . "/iu"
                : null;
        while ($i < $len) {
            $ch = $data[$i];
            if ($ch === "}") {
                if (!empty($stack)) {
                    array_pop($stack);
                }
                $result .= "}";
                $i++;
                continue;
            }
            if ($ch === "a" && $i + 1 < $len && $data[$i + 1] === ":") {
                $brace = strpos($data, "{", $i + 2);
                if ($brace !== false) {
                    $header = substr($data, $i, $brace - $i + 1);
                    if (preg_match('/^a:\d+:\{$/', $header)) {
                        array_push($stack, ["expect_key" => true]);
                        $result .= $header;
                        $i += strlen($header);
                        continue;
                    }
                }
            }
            if ($ch === "O" && $i + 1 < $len && $data[$i + 1] === ":") {
                $brace = strpos($data, "{", $i + 2);
                if ($brace !== false) {
                    $header = substr($data, $i, $brace - $i + 1);
                    if (preg_match('/^O:\d+:"[^"]*":\d+:\{$/', $header)) {
                        array_push($stack, ["expect_key" => true]);
                        $result .= $header;
                        $i += strlen($header);
                        continue;
                    }
                }
            }
            if ($ch === "C" && $i + 1 < $len && $data[$i + 1] === ":") {
                $brace = strpos($data, "{", $i + 2);
                if ($brace !== false) {
                    $header = substr($data, $i, $brace - $i + 1);
                    if (preg_match('/^C:\d+:"[^"]*":\d+:\{$/', $header)) {
                        array_push($stack, ["expect_key" => true]);
                        $result .= $header;
                        $i += strlen($header);
                        continue;
                    }
                }
            }
            if (
                (($ch === "i" || $ch === "d" || $ch === "b") &&
                    $i + 1 < $len &&
                    $data[$i + 1] === ":") ||
                ($ch === "N" && $i + 1 < $len && $data[$i + 1] === ";")
            ) {
                $semi = strpos($data, ";", $i + 1);
                if ($semi !== false) {
                    $token = substr($data, $i, $semi - $i + 1);
                    $result .= $token;
                    $i += strlen($token);
                    if (!empty($stack)) {
                        $stack[count($stack) - 1]["expect_key"] = !$stack[
                            count($stack) - 1
                        ]["expect_key"];
                    }
                    continue;
                }
            }
            if ($ch === "s" && $i + 2 < $len && $data[$i + 1] === ":") {
                $colon_pos = strpos($data, ":", $i + 2);
                if ($colon_pos === false) {
                    $result .= substr($data, $i);
                    break;
                }
                $str_len = (int) substr($data, $i + 2, $colon_pos - ($i + 2));
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
                    $str_end + 1 >= $len ||
                    $data[$str_end] !== '"' ||
                    $data[$str_end + 1] !== ";"
                ) {
                    $result .= $data[$i];
                    $i++;
                    continue;
                }
                $str_content = substr($data, $str_start, $str_len);
                $is_key =
                    !empty($stack) && $stack[count($stack) - 1]["expect_key"];
                if ($is_key) {
                    $result .= "s:" . $str_len . ':"' . $str_content . '";';
                    $stack[count($stack) - 1]["expect_key"] = false;
                } else {
                    if (!$partial) {
                        $count = 0;
                        $new_content = @preg_replace(
                            $boundary_pattern,
                            $to_escaped,
                            $str_content,
                            -1,
                            $count
                        );
                        if ($new_content === null) {
                            $err = preg_last_error();
                            $fallback_count = 0;
                            $new_content = @preg_replace(
                                $byte_pattern,
                                $to_escaped,
                                $str_content,
                                -1,
                                $fallback_count
                            );
                            if ($new_content === null) {
                                $new_content = $str_content;
                                OPTISTATE_Utils::log_critical_error(
                                    "preg_replace failed in serialized string replacement",
                                    [
                                        "preg_error" => $err,
                                        "subject_length" => strlen(
                                            $str_content
                                        ),
                                    ]
                                );
                            } else {
                                $this->last_replace_count += $fallback_count;
                            }
                        } else {
                            $this->last_replace_count += $count;
                        }
                    } else {
                        if (!$case_sensitive) {
                            $ci_count = 0;
                            $replaced = @preg_replace(
                                $partial_ci_pattern,
                                $to_escaped,
                                $str_content,
                                -1,
                                $ci_count
                            );
                            if ($replaced === null) {
                                $this->last_replace_count += substr_count(
                                    strtolower($str_content),
                                    strtolower($from)
                                );
                                $new_content = str_ireplace(
                                    $from,
                                    $to,
                                    $str_content
                                );
                            } else {
                                $this->last_replace_count += $ci_count;
                                $new_content = $replaced;
                            }
                        } else {
                            $this->last_replace_count += substr_count(
                                $str_content,
                                $from
                            );
                            $new_content = str_replace(
                                $from,
                                $to,
                                $str_content
                            );
                        }
                    }
                    $result .=
                        "s:" .
                        strlen($new_content) .
                        ':"' .
                        $new_content .
                        '";';
                    if (!empty($stack)) {
                        $stack[count($stack) - 1]["expect_key"] = true;
                    }
                }
                $i = $str_end + 2;
                continue;
            }
            $result .= $data[$i];
            $i++;
        }
        return $result;
    }
    private static function bsr_unserialize(string $serialized_string)
    {
        if (!is_serialized($serialized_string)) {
            return false;
        }
        $serialized_string = trim($serialized_string);
        return @unserialize($serialized_string, ["allowed_classes" => []]);
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
    private function perform_string_replace(
        string $from,
        string $to,
        string $data,
        bool $case_sensitive = false
    ): string {
        if ($data === "") {
            return $data;
        }
        $to_escaped = addcslashes($to, '\\$');
        if (!$this->partial_match) {
            $pattern = $this->build_boundary_pattern(
                $from,
                $case_sensitive,
                false
            );
            $count = 0;
            $result = @preg_replace($pattern, $to_escaped, $data, -1, $count);
            if ($result === null) {
                $err = preg_last_error();
                $fallback_count = 0;
                $result = @preg_replace(
                    $this->build_byte_pattern($from, $case_sensitive, false),
                    $to_escaped,
                    $data,
                    -1,
                    $fallback_count
                );
                if ($result === null) {
                    OPTISTATE_Utils::log_critical_error(
                        "preg_replace failed in perform_string_replace",
                        ["preg_error" => $err, "data_length" => strlen($data)]
                    );
                    return $data;
                }
                $this->last_replace_count += $fallback_count;
                return $result;
            }
            $this->last_replace_count += $count;
            return $result;
        }
        if (!$case_sensitive) {
            $pattern = "/" . preg_quote($from, "/") . "/iu";
            $count = 0;
            $result = @preg_replace($pattern, $to_escaped, $data, -1, $count);
            if ($result !== null) {
                $this->last_replace_count += $count;
                return $result;
            }
            $this->last_replace_count += substr_count(
                strtolower($data),
                strtolower($from)
            );
            return str_ireplace($from, $to, $data);
        }
        $this->last_replace_count += substr_count($data, $from);
        return str_replace($from, $to, $data);
    }
    private function string_contains(string $string, string $search): bool
    {
        if ($this->partial_match) {
            return $this->case_sensitive
                ? mb_strpos($string, $search) !== false
                : mb_stripos($string, $search) !== false;
        }
        $pattern = $this->build_boundary_pattern(
            $search,
            $this->case_sensitive,
            false
        );
        $r = @preg_match($pattern, $string);
        if ($r === false) {
            $r = @preg_match(
                $this->build_byte_pattern(
                    $search,
                    $this->case_sensitive,
                    false
                ),
                $string
            );
        }
        return $r === 1;
    }
    private function find_all_preview_values(
        $data,
        string $search,
        array &$results = [],
        int $depth = 0
    ): array {
        if ($depth > 100) {
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
    private function get_snippet(
        string $text,
        string $search,
        int $length = 100,
        bool $case_sensitive = false
    ): string {
        $pos = $case_sensitive
            ? mb_strpos($text, $search)
            : mb_stripos($text, $search);
        $text_len = mb_strlen($text);
        if ($pos === false) {
            return $text_len > $length
                ? mb_substr($text, 0, $length - 3) . "..."
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
}