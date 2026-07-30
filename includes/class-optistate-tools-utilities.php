<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Tools_Utilities
{
    public const ANALYZE_KEY_PREFIX = "optistate_analyze_";
    public const ANALYZE_STATE_TTL = HOUR_IN_SECONDS;
    public const MAX_DETAIL_ROWS = 200;
    public const INDEX_TASK_TTL = 30 * MINUTE_IN_SECONDS;
    private const AUTOLOAD_BACKUP_KEY = "optistate_autoload_backup";
    private const BATCH_SIZE_FETCH_STANDARD = 1000;
    private const BATCH_SIZE_FETCH_CLI = 2000;
    private const BATCH_SIZE_UPDATE = 1000;
    private static $essential_regex = null;
    private static ?array $base_tables = null;
    private static array $released_db_locks = [];
    private static array $protected_options = [
        "jetpack_options",
        "wpseo",
        "rank_math",
        "acf",
        "wpml",
        "polylang",
        "wordfence",
        "updraftplus",
        "wp_rocket_settings",
        "wpforms",
        "fluent_form",
        "ninja_forms",
    ];
    public static function is_cli_context(): bool
    {
        return defined("WP_CLI") && WP_CLI && php_sapi_name() === "cli";
    }

    private static function get_timeout_guard(int $fallback = 600): int
    {
        $max_exec = (int) ini_get("max_execution_time");

        if ($max_exec <= 0) {
            $max_exec = $fallback;
        }

        return max(5, $max_exec - 5);
    }

    private static function get_memory_safety_limit(bool $use_cli): int
    {
        $sys_limit = ini_get("memory_limit");

        if (empty($sys_limit) || $sys_limit === "-1") {
            return $use_cli ? 512 * 1024 * 1024 : 256 * 1024 * 1024;
        }

        return (int) (wp_convert_hr_to_bytes($sys_limit) * 0.9);
    }

    private static function get_autoload_fetch_batch_size(bool $use_cli): int
    {
        return $use_cli
            ? self::BATCH_SIZE_FETCH_CLI
            : self::BATCH_SIZE_FETCH_STANDARD;
    }

    public static function require_post(): bool
    {
        if (
            (isset($_SERVER["REQUEST_METHOD"])
                ? strtoupper((string) $_SERVER["REQUEST_METHOD"])
                : "") !== "POST"
        ) {
            OPTISTATE_Utils::send_json_error(
                ["message" => __("Invalid request method.", "optistate")],
                405
            );

            return false;
        }

        return true;
    }
    public static function invalidate_analysis_caches(): void
    {
        $table_key = "optistate_table_analysis_" . md5(DB_NAME);
        $index_key = "optistate_index_analysis_" . md5(DB_NAME);

        wp_cache_delete($table_key, "optistate");
        delete_transient($table_key);

        wp_cache_delete($index_key, "optistate");
        delete_transient($index_key);
    }

    public static function mark_db_lock_acquired(string $lock_name): void
    {
        unset(self::$released_db_locks[$lock_name]);
    }

    public static function release_db_lock(string $lock_name): void
    {
        if (isset(self::$released_db_locks[$lock_name])) {
            return;
        }

        self::$released_db_locks[$lock_name] = true;

        global $wpdb;

        try {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to release lock '{$lock_name}': " . $e->getMessage()
            );
        }
    }

    public static function register_lock_release_on_shutdown(
        string $lock_name
    ): void {
        register_shutdown_function(static function () use ($lock_name) {
            self::release_db_lock($lock_name);
        });
    }
    public static function get_base_tables(): array
    {
        if (self::$base_tables !== null) {
            return self::$base_tables;
        }

        global $wpdb;

        $tables = [];

        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results("SHOW FULL TABLES", ARRAY_N);
        $wpdb->suppress_errors($suppress);

        if (is_array($rows) && !empty($rows)) {
            foreach ($rows as $row) {
                if (!isset($row[0])) {
                    continue;
                }
                $type = isset($row[1])
                    ? strtoupper((string) $row[1])
                    : "BASE TABLE";
                if ($type === "VIEW") {
                    continue;
                }
                $tables[] = (string) $row[0];
            }

            self::$base_tables = $tables;

            return self::$base_tables;
        }

        self::$base_tables = array_values(
            array_unique((array) OPTISTATE_Utils::get_all_tables())
        );

        return self::$base_tables;
    }

    public static function flush_base_table_cache(): void
    {
        self::$base_tables = null;
    }
    public static function save_analyze_state(
        OPTISTATE_Process_Store $process_store,
        string $key,
        array $state
    ): bool {
        return (bool) $process_store->set(
            $key,
            $state,
            self::ANALYZE_STATE_TTL
        );
    }

    public static function load_analyze_state(
        OPTISTATE_Process_Store $process_store,
        string $key
    ): ?array {
        $state = $process_store->get($key);

        return is_array($state) ? $state : null;
    }

    public static function delete_analyze_state(
        OPTISTATE_Process_Store $process_store,
        string $key
    ): void {
        $process_store->delete($key);
        $process_store->delete(self::get_analyze_session_key());
    }

    public static function get_analyze_session_key(?int $user_id = null): string
    {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        return "optistate_ar_session_" . (int) $user_id;
    }

    public static function is_valid_analyze_key(string $key): bool
    {
        return (bool) preg_match(
            "/^" .
                preg_quote(self::ANALYZE_KEY_PREFIX, "/") .
                '[a-f0-9]{28,32}$/',
            $key
        );
    }

    public static function evaluate_check_table_rows(array $rows): array
    {
        $needs_repair = false;
        $error_message = null;
        $is_ok = false;

        foreach ($rows as $check_row) {
            $msg_type = strtolower(
                trim((string) ($check_row["Msg_type"] ?? ""))
            );
            $msg_text = strtolower(
                trim((string) ($check_row["Msg_text"] ?? ""))
            );

            if ($msg_type === "status" && $msg_text === "ok") {
                $is_ok = true;
            }
            if (
                strpos($msg_text, "doesn't support check") !== false ||
                strpos($msg_text, "does not support check") !== false ||
                strpos(
                    $msg_text,
                    "the storage engine for the table doesn't"
                ) !== false
            ) {
                $is_ok = true;
                continue;
            }
            if ($msg_type === "error") {
                $needs_repair = true;
                $error_message = $check_row["Msg_text"];
            }
            if ($msg_type === "warning") {
                if (
                    strpos($msg_text, "crash") !== false ||
                    strpos($msg_text, "corrupt") !== false ||
                    strpos($msg_text, "repair") !== false ||
                    strpos($msg_text, "marked as crashed") !== false
                ) {
                    $needs_repair = true;
                    $error_message = $check_row["Msg_text"];
                }
            }
            if (
                $msg_type !== "status" &&
                (strpos($msg_text, "corrupt") !== false ||
                    strpos($msg_text, "crashed") !== false ||
                    strpos($msg_text, "repair by sort") !== false ||
                    strpos($msg_text, "repair with keycache") !== false)
            ) {
                $needs_repair = true;
                if (!$error_message) {
                    $error_message = $check_row["Msg_text"];
                }
            }
        }

        return [$needs_repair, $error_message, $is_ok];
    }

    public static function apply_check_result_to_state(
        array &$state,
        string $table_name,
        bool $needs_repair,
        ?string $error_message,
        bool $is_ok
    ): void {
        if (!isset($state["final_results"]["analyzed"])) {
            $state["final_results"]["analyzed"] = 0;
        }
        $state["final_results"]["analyzed"]++;

        if ($needs_repair) {
            $state["tables_to_repair"][] = $table_name;
            $state["tables_to_optimize"][] = $table_name;
            if (isset($state["table_statuses"][$table_name])) {
                $state["table_statuses"][$table_name]["corrupted"] = true;
                $state["table_statuses"][$table_name]["error"] =
                    $error_message ?: "Table check failed";
            }
        } elseif (!$is_ok && !$needs_repair) {
            if (isset($state["table_statuses"][$table_name])) {
                $state["table_statuses"][$table_name]["error"] =
                    "Ambiguous check result";
            }
        }
    }
    public static function run_preview_autoload_options(
        OPTISTATE $main_plugin
    ): void {
        if (!$main_plugin->verify_ajax_request()) {
            return;
        }

        if (!OPTISTATE_Utils::check_rate_limit("preview_autoload", 10)) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }

        try {
            $result = self::get_autoload_candidates();
            $display_candidates = array_slice($result["candidates"], 0, 200);
            $formatted = array_map(function ($item) {
                return [
                    "name" => $item["name"],
                    "size" => $item["size"],
                    "size_formatted" => size_format($item["size"], 2),
                ];
            }, $display_candidates);

            OPTISTATE_Utils::send_json_success([
                "candidates" => $formatted,
                "count" => $result["total_count"],
                "total_size" => $result["total_size"],
                "total_size_formatted" => size_format($result["total_size"], 2),
                "partial" => !empty($result["truncated"]),
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Preview autoload failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __("Could not fetch preview.", "optistate"),
            ]);
        }
    }

    public static function run_optimize_autoload(OPTISTATE $main_plugin): void
    {
        if (!$main_plugin->verify_ajax_request()) {
            return;
        }

        if (!self::require_post()) {
            return;
        }

        if (!OPTISTATE_Utils::check_rate_limit("heavy_op", 20)) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }

        wp_raise_memory_limit("admin");
        OPTISTATE_Utils::safe_set_time_limit(600);

        $start_time = time();
        $timeout_guard = self::get_timeout_guard();

        global $wpdb;
        $results = [
            "optimized" => 0,
            "skipped" => 0,
            "total_found" => 0,
            "total_size_reduced" => 0,
            "errors" => [],
            "details" => [],
            "details_truncated" => false,
            "listed" => ["optimized" => 0, "skipped" => 0],
        ];
        $backup_data = [];

        $use_cli = self::is_cli_context();
        self::compile_patterns();

        $on_values = self::autoload_on_values();
        $autoload_in = implode(",", array_fill(0, count($on_values), "%s"));

        try {
            $total_autoload = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ($autoload_in)",
                    ...$on_values
                )
            );
            $results["total_found"] = (int) $total_autoload;

            if ($results["total_found"] === 0) {
                $main_plugin->log_entry(
                    sprintf(
                        "⚙️ " .
                            __(
                                "Optimized Autoloaded Options (0) by {username}",
                                "optistate"
                            )
                    )
                );
                OPTISTATE_Utils::send_json_success($results);
                return;
            }

            $fetch_batch_size = self::get_autoload_fetch_batch_size($use_cli);
            $update_batch_size = self::BATCH_SIZE_UPDATE;
            $memory_safety_limit = self::get_memory_safety_limit($use_cli);

            $last_seen_id = 0;
            $options_buffer = [];
            $optimized_options = [];

            while (true) {
                if (time() - $start_time >= $timeout_guard) {
                    break;
                }

                $chunk = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT option_id, option_name, autoload, LENGTH(option_value) AS option_size
                           FROM {$wpdb->options}
                          WHERE autoload IN ($autoload_in) AND option_id > %d
                          ORDER BY option_id ASC
                          LIMIT %d",
                        ...array_merge($on_values, [
                            $last_seen_id,
                            $fetch_batch_size,
                        ])
                    ),
                    ARRAY_A
                );

                if (empty($chunk)) {
                    break;
                }

                foreach ($chunk as $option) {
                    $last_seen_id = (int) $option["option_id"];
                    $name = $option["option_name"];
                    $size = (int) ($option["option_size"] ?? 0);

                    $status = self::get_autoload_candidate_status($name, $size);

                    if ($status === "candidate") {
                        $options_buffer[] = $option;
                        if (count($options_buffer) >= $update_batch_size) {
                            $optimized_options = self::process_autoload_update_batch(
                                $main_plugin,
                                $options_buffer,
                                $results,
                                $optimized_options,
                                $backup_data
                            );
                            $options_buffer = [];
                        }
                    } else {
                        $results["skipped"]++;
                        $reason = "";
                        switch ($status) {
                            case "protected":
                                $reason = __(
                                    "Critical plugin setting",
                                    "optistate"
                                );
                                break;
                            case "essential":
                                if ($size > 100000) {
                                    $reason = __(
                                        "Essential plugin/theme setting",
                                        "optistate"
                                    );
                                }
                                break;
                            case "transient_timeout":
                                $reason = __("Transient timeout", "optistate");
                                break;
                            case "not_safe":
                                $reason = __(
                                    "Not safe to optimize (size or pattern)",
                                    "optistate"
                                );
                                break;
                            default:
                                $reason = __("Unknown reason", "optistate");
                        }
                        if ($reason !== "") {
                            self::push_autoload_detail($results, "skipped", [
                                "option" => $name,
                                "size" => size_format($size, 2),
                                "status" => "skipped",
                                "reason" => $reason,
                            ]);
                        }
                    }
                }

                if (
                    function_exists("memory_get_usage") &&
                    memory_get_usage(true) > $memory_safety_limit
                ) {
                    $results["errors"][] =
                        "Memory safety limit reached - processed partial dataset.";
                    break;
                }

                if (!$use_cli && count($chunk) === $fetch_batch_size) {
                    usleep(10000);
                }
            }

            if (!empty($options_buffer)) {
                $optimized_options = self::process_autoload_update_batch(
                    $main_plugin,
                    $options_buffer,
                    $results,
                    $optimized_options,
                    $backup_data
                );
            }

            if (!empty($optimized_options)) {
                foreach ($optimized_options as $option_name) {
                    wp_cache_delete($option_name, "options");
                }
                wp_cache_delete("alloptions", "options");
                wp_cache_delete("notoptions", "options");
            }
            if ($results["optimized"] > 0) {
                $main_plugin->clear_stats_cache();
            }

            $main_plugin->log_entry(
                sprintf(
                    "⚙️ " .
                        __(
                            "Optimized Autoloaded Options (%s) by {username}",
                            "optistate"
                        ),
                    number_format_i18n($results["optimized"])
                )
            );

            unset($results["listed"]);
            OPTISTATE_Utils::send_json_success($results);
        } catch (Throwable $e) {
            $error_message = "Optimization failed: " . $e->getMessage();
            $results["errors"][] = $error_message;

            $main_plugin->log_entry(
                "❌ " . __("Autoload Optimization Failed", "optistate"),
                "error",
                "",
                [
                    "details" => $error_message,
                    "memory_usage" => size_format(memory_get_usage(true), 2),
                    "peak_memory" => size_format(
                        memory_get_peak_usage(true),
                        2
                    ),
                ]
            );

            OPTISTATE_Utils::log_critical_error(
                "Autoload optimization failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );

            unset($results["listed"]);
            OPTISTATE_Utils::send_json_error(
                [
                    "message" => $error_message,
                ],
                500,
                $results
            );
        }
    }

    public static function run_restore_autoload_backup(
        OPTISTATE $main_plugin
    ): void {
        if (!$main_plugin->verify_ajax_request()) {
            return;
        }

        if (!self::require_post()) {
            return;
        }

        if (!OPTISTATE_Utils::check_rate_limit("restore_autoload_backup", 10)) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }

        try {
            $backup = self::get_autoload_backup($main_plugin);
            if (!$backup || !is_array($backup)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "No autoload backup found to restore.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $count = self::restore_autoload_backup($backup);
            if ($count === false) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Failed to restore autoload backup.",
                        "optistate"
                    ),
                ]);
                return;
            }

            foreach (array_keys($backup) as $option_name) {
                wp_cache_delete($option_name, "options");
            }
            wp_cache_delete("alloptions", "options");
            wp_cache_delete("notoptions", "options");

            self::delete_autoload_backup($main_plugin);

            $main_plugin->clear_stats_cache();
            $main_plugin->log_entry(
                sprintf(
                    __(
                        "↩️ Restored %s autoloaded options from backup by {username}",
                        "optistate"
                    ),
                    number_format_i18n($count)
                )
            );

            OPTISTATE_Utils::send_json_success([
                "message" => sprintf(
                    __(
                        "Successfully restored %s autoloaded options.",
                        "optistate"
                    ),
                    number_format_i18n($count)
                ),
                "count" => $count,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Restore autoload backup failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred while restoring the autoload backup.",
                    "optistate"
                ),
            ]);
        }
    }

    public static function get_autoload_backup(OPTISTATE $main_plugin): ?array
    {
        $data = $main_plugin->get_store_data(self::AUTOLOAD_BACKUP_KEY, null);
        return is_array($data) ? $data : null;
    }
    private static function compile_patterns(): void
    {
        if (self::$essential_regex !== null) {
            return;
        }

        $essential_parts = [
            'active_plugins$',
            'template$',
            'stylesheet$',
            'siteurl$',
            'home$',
            'rewrite_rules$',
            'cron$',
            'wp_user_roles$',
            'blogname$',
            'admin_email$',
            'permalink_structure$',
            'show_on_front$',
            'page_on_front$',
            'page_for_posts$',
            "theme_mods_",
            "widget_",
            "sidebars_widgets",
        ];

        self::$essential_regex =
            "/^(?:" . implode("|", $essential_parts) . ")/";
    }

    private static function autoload_on_values(): array
    {
        static $values = null;

        if ($values !== null) {
            return $values;
        }

        if (function_exists("wp_autoload_values_to_autoload")) {
            $values = array_values(
                array_filter(
                    (array) wp_autoload_values_to_autoload(),
                    static function ($value) {
                        return is_string($value) && $value !== "";
                    }
                )
            );
        } else {
            $values = [];
        }

        if (empty($values)) {
            $values = ["yes"];
        }

        return $values;
    }

    private static function autoload_off_value(): string
    {
        return OPTISTATE_Utils::get_autoload_off_value();
    }

    private static function is_safe_to_optimize(
        string $option_name,
        int $option_size
    ): bool {
        if ($option_size > 2048) {
            $always_safe = [
                "_transient_",
                "_site_transient_",
                "wc_session_",
                "_wc_session_",
                "_oembed_",
                "jetpack_sync_",
                "jetpack_sync_error_",
            ];
            foreach ($always_safe as $pattern) {
                if (strpos($option_name, $pattern) !== false) {
                    return true;
                }
            }
        }

        if ($option_size > 51200) {
            $is_settings =
                stripos($option_name, "settings") !== false ||
                stripos($option_name, "config") !== false ||
                stripos($option_name, "options") !== false;
            if (!$is_settings) {
                $cache_indicators = ["cache", "cached", "temp", "temporary"];
                foreach ($cache_indicators as $indicator) {
                    if (stripos($option_name, $indicator) !== false) {
                        return true;
                    }
                }
            }
        }

        if ($option_size > 102400) {
            $is_settings =
                stripos($option_name, "settings") !== false ||
                stripos($option_name, "config") !== false ||
                stripos($option_name, "options") !== false ||
                stripos($option_name, "_option") !== false;
            if (!$is_settings) {
                return true;
            }
        }

        return false;
    }

    private static function get_autoload_candidate_status(
        string $option_name,
        int $option_size
    ): string {
        self::compile_patterns();
        static $protected_map = null;
        if ($protected_map === null) {
            $protected_map = array_flip(self::$protected_options);
        }

        if (isset($protected_map[$option_name])) {
            return "protected";
        }
        if (preg_match(self::$essential_regex, $option_name)) {
            return "essential";
        }
        if (
            strpos($option_name, "_transient_timeout_") === 0 ||
            strpos($option_name, "_site_transient_timeout_") === 0
        ) {
            return "transient_timeout";
        }
        if (!self::is_safe_to_optimize($option_name, $option_size)) {
            return "not_safe";
        }
        return "candidate";
    }

    private static function get_autoload_candidates(): array
    {
        global $wpdb;
        $candidates = [];
        $total_size = 0;
        $count = 0;
        $truncated = false;
        self::compile_patterns();

        $on_values = self::autoload_on_values();
        $autoload_in = implode(",", array_fill(0, count($on_values), "%s"));

        $use_cli = self::is_cli_context();
        $start_time = time();
        $timeout_guard = self::get_timeout_guard();
        $memory_safety_limit = self::get_memory_safety_limit($use_cli);
        $fetch_batch_size = self::get_autoload_fetch_batch_size($use_cli);
        $last_seen_id = 0;

        while (true) {
            if (time() - $start_time >= $timeout_guard) {
                $truncated = true;
                break;
            }
            if (
                function_exists("memory_get_usage") &&
                memory_get_usage(true) > $memory_safety_limit
            ) {
                $truncated = true;
                break;
            }

            $chunk = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_id, option_name, LENGTH(option_value) AS option_size
                       FROM {$wpdb->options}
                      WHERE autoload IN ($autoload_in) AND option_id > %d
                      ORDER BY option_id ASC
                      LIMIT %d",
                    ...array_merge($on_values, [
                        $last_seen_id,
                        $fetch_batch_size,
                    ])
                ),
                ARRAY_A
            );

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                $last_seen_id = (int) $row["option_id"];
                $name = $row["option_name"];
                $size = (int) $row["option_size"];

                $status = self::get_autoload_candidate_status($name, $size);
                if ($status === "candidate") {
                    $candidates[] = ["name" => $name, "size" => $size];
                    $total_size += $size;
                    $count++;
                }
            }
        }

        return [
            "candidates" => $candidates,
            "total_count" => $count,
            "total_size" => $total_size,
            "truncated" => $truncated,
        ];
    }

    private static function process_autoload_update_batch(
        OPTISTATE $main_plugin,
        array $options_data,
        array &$results,
        array $optimized_options,
        array &$backup_data
    ): array {
        if (empty($options_data)) {
            return $optimized_options;
        }

        global $wpdb;

        $option_names = [];
        $size_by_name = [];

        foreach ($options_data as $row) {
            $option_name = (string) $row["option_name"];
            $option_names[] = $option_name;
            $size_by_name[$option_name] = (int) ($row["option_size"] ?? 0);
            $backup_data[$option_name] = (string) ($row["autoload"] ?? "");
        }

        if (!self::save_autoload_backup($main_plugin, $backup_data)) {
            throw new Exception(
                "Autoload rollback snapshot could not be persisted; aborting before modifying options."
            );
        }

        $placeholders = implode(",", array_fill(0, count($option_names), "%s"));
        $rows_affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET autoload = %s WHERE option_name IN ($placeholders)",
                ...array_merge([self::autoload_off_value()], $option_names)
            )
        );

        if ($rows_affected === false) {
            throw new Exception("Database update failed: " . $wpdb->last_error);
        }

        foreach ($option_names as $option_name) {
            $results["optimized"]++;
            $optimized_options[] = $option_name;
            $size = isset($size_by_name[$option_name])
                ? $size_by_name[$option_name]
                : 0;
            $results["total_size_reduced"] += $size;

            self::push_autoload_detail($results, "optimized", [
                "option" => $option_name,
                "size" => size_format($size, 2),
                "status" => "optimized",
            ]);
        }

        return $optimized_options;
    }

    private static function push_autoload_detail(
        array &$results,
        string $bucket,
        array $detail
    ): void {
        if (!isset($results["details"]) || !is_array($results["details"])) {
            $results["details"] = [];
        }

        if (!isset($results["listed"][$bucket])) {
            $results["listed"][$bucket] = 0;
        }

        if ($results["listed"][$bucket] >= self::MAX_DETAIL_ROWS) {
            $results["details_truncated"] = true;

            return;
        }

        $results["details"][] = $detail;
        $results["listed"][$bucket]++;
    }

    private static function save_autoload_backup(
        OPTISTATE $main_plugin,
        array $backup_data
    ): bool {
        if (empty($backup_data)) {
            return true;
        }

        return (bool) $main_plugin->set_store_data(
            self::AUTOLOAD_BACKUP_KEY,
            $backup_data
        );
    }

    private static function restore_autoload_backup(array $backup)
    {
        global $wpdb;

        if (empty($backup)) {
            return 0;
        }

        $by_value = [];

        foreach ($backup as $option_name => $data) {
            if (!is_string($option_name) || $option_name === "") {
                continue;
            }

            if (is_array($data)) {
                $autoload = isset($data["autoload"])
                    ? (string) $data["autoload"]
                    : "";
            } elseif (is_scalar($data)) {
                $autoload = (string) $data;
            } else {
                continue;
            }

            if ($autoload === "") {
                continue;
            }

            $by_value[$autoload][] = $option_name;
        }

        if (empty($by_value)) {
            return 0;
        }

        $batch_size = 500;

        try {
            return (int) OPTISTATE_Utils::transaction(function () use (
                $wpdb,
                $by_value,
                $batch_size
            ) {
                $count = 0;

                foreach ($by_value as $autoload => $names) {
                    foreach (array_chunk($names, $batch_size) as $chunk) {
                        $placeholders = implode(
                            ",",
                            array_fill(0, count($chunk), "%s")
                        );

                        $result = $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE {$wpdb->options}
                                    SET autoload = %s
                                  WHERE option_name IN ($placeholders)",
                                ...array_merge([$autoload], $chunk)
                            )
                        );

                        if ($result === false) {
                            throw new \Exception(
                                "Autoload backup restore batch failed: " .
                                    $wpdb->last_error
                            );
                        }

                        $count += count($chunk);
                    }
                }

                return $count;
            });
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Autoload backup restore failed – transaction rolled back",
                ["error" => $e->getMessage()]
            );

            return false;
        }
    }

    private static function delete_autoload_backup(OPTISTATE $main_plugin): void
    {
        $main_plugin->delete_store_data(self::AUTOLOAD_BACKUP_KEY);
    }
    public static function run_scan_integrity(OPTISTATE $main_plugin): void
    {
        if (!$main_plugin->verify_ajax_request()) {
            return;
        }

        if (!OPTISTATE_Utils::check_rate_limit("scan_integrity", 10)) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }

        try {
            global $wpdb;
            $results = [];
            $total_issues = 0;
            $rules = self::get_integrity_rules();
            $start_time = microtime(true);
            $max_exec = 20;

            $count_subqueries = [];
            foreach ($rules as $type => $rule) {
                if (
                    !is_array($rule) ||
                    !self::is_valid_integrity_rule($rule, $type)
                ) {
                    continue;
                }
                $child_table = $wpdb->prefix . $rule["child_table"];
                if (!OPTISTATE_Utils::table_exists($child_table)) {
                    continue;
                }
                $parent_table = $wpdb->prefix . $rule["parent_table"];
                $extra_where = isset($rule["extra_where"])
                    ? $rule["extra_where"]
                    : "";
                $sql = "SELECT COUNT(*) FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where";
                $count_subqueries[] = "SELECT '$type' AS rule_type, ($sql) AS cnt";
            }

            if (empty($count_subqueries)) {
                OPTISTATE_Utils::send_json_success([
                    "issues" => [],
                    "total" => 0,
                ]);
                return;
            }

            $hint = "";
            if (
                version_compare(
                    OPTISTATE_Utils::get_mysql_version(),
                    "8.0.11",
                    ">="
                )
            ) {
                $hint = "/*+ MAX_EXECUTION_TIME(30000) */ ";
            }

            $union_sql =
                "SELECT {$hint}rule_type, cnt FROM (" .
                implode(" UNION ALL ", $count_subqueries) .
                ") AS counts";
            $counts = $wpdb->get_results($union_sql, OBJECT_K);

            if ($wpdb->last_error) {
                self::fallback_integrity_scan(
                    $rules,
                    $start_time,
                    $max_exec,
                    $results,
                    $total_issues
                );
                OPTISTATE_Utils::send_json_success([
                    "issues" => $results,
                    "total" => $total_issues,
                ]);
                return;
            }

            foreach ($rules as $type => $rule) {
                if (
                    !is_array($rule) ||
                    !self::is_valid_integrity_rule($rule, $type)
                ) {
                    continue;
                }
                if (microtime(true) - $start_time > $max_exec) {
                    $results[] = [
                        "type" => "timeout",
                        "label" => __("Scan paused (Time Limit)", "optistate"),
                        "count" => 0,
                        "child_table" => "...",
                        "parent_table" => "...",
                        "samples" => [],
                    ];
                    break;
                }

                $count = isset($counts[$type]) ? (int) $counts[$type]->cnt : 0;
                if ($count === 0) {
                    continue;
                }

                $total_issues += $count;

                $child_table = $wpdb->prefix . $rule["child_table"];
                $parent_table = $wpdb->prefix . $rule["parent_table"];
                $extra_where = isset($rule["extra_where"])
                    ? $rule["extra_where"]
                    : "";
                $context_col = $rule["context_col"];

                $sample_sql = "SELECT c.{$rule["child_key"]} as fk_id, SUBSTRING(c.$context_col, 1, 50) as context FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where LIMIT 3";
                $samples = $wpdb->get_results($sample_sql);

                $results[] = [
                    "type" => $type,
                    "label" => $rule["label"],
                    "count" => $count,
                    "child_table" => $rule["child_table"],
                    "parent_table" => $rule["parent_table"],
                    "samples" => $samples,
                ];
            }

            OPTISTATE_Utils::send_json_success([
                "issues" => $results,
                "total" => $total_issues,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Integrity scan failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred during integrity scan.",
                    "optistate"
                ),
            ]);
        }
    }

    public static function run_fix_integrity(OPTISTATE $main_plugin): void
    {
        if (!$main_plugin->verify_ajax_request()) {
            return;
        }

        if (!self::require_post()) {
            return;
        }

        if (!OPTISTATE_Utils::check_rate_limit("fix_integrity", 2)) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }

        try {
            $type = isset($_POST["type"])
                ? sanitize_key(wp_unslash($_POST["type"]))
                : "";
            $rules = self::get_integrity_rules();

            if (!isset($rules[$type]) || !is_array($rules[$type])) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Invalid rule type.", "optistate"),
                ]);
                return;
            }

            $rule = $rules[$type];
            if (!self::is_valid_integrity_rule($rule, $type)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Invalid rule definition.", "optistate"),
                ]);
                return;
            }

            global $wpdb;
            $child_table = $wpdb->prefix . $rule["child_table"];
            $parent_table = $wpdb->prefix . $rule["parent_table"];

            if (
                !OPTISTATE_Utils::table_exists($child_table) ||
                !OPTISTATE_Utils::table_exists($parent_table)
            ) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "The tables for this rule are not present.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $extra_where = isset($rule["extra_where"])
                ? $rule["extra_where"]
                : "";
            $limit = 2000;
            $deleted_count = 0;
            $affected_object_ids = [];
            $deleted_post_ids = [];

            try {
                if ($type === "term_relationships") {
                    $sql_fetch = "SELECT tr.object_id, tr.term_taxonomy_id FROM $child_table tr LEFT JOIN $parent_table tt ON tr.{$rule["child_key"]} = tt.{$rule["parent_key"]} WHERE tt.{$rule["parent_key"]} IS NULL LIMIT $limit";
                    $rows = $wpdb->get_results($sql_fetch);
                    if ($rows) {
                        $affected_object_ids = array_unique(
                            array_map(
                                "absint",
                                array_column((array) $rows, "object_id")
                            )
                        );

                        $values = [];
                        foreach ($rows as $row) {
                            $values[] = sprintf(
                                "(%d, %d)",
                                (int) $row->object_id,
                                (int) $row->term_taxonomy_id
                            );
                        }

                        $deleted_count = (int) OPTISTATE_Utils::transaction(
                            function () use ($wpdb, $child_table, $values) {
                                $deleted = $wpdb->query(
                                    "DELETE FROM $child_table WHERE (object_id, term_taxonomy_id) IN (" .
                                        implode(",", $values) .
                                        ")"
                                );

                                if ($deleted === false) {
                                    throw new \Exception(
                                        "term_relationships delete failed: " .
                                            $wpdb->last_error
                                    );
                                }

                                return $deleted;
                            }
                        );
                    }
                } else {
                    $pk = $rule["pk"];
                    if ($pk === false || !is_string($pk) || $pk === "") {
                        OPTISTATE_Utils::send_json_error([
                            "message" => __(
                                "This rule has no primary key and cannot be fixed automatically.",
                                "optistate"
                            ),
                        ]);
                        return;
                    }

                    $ids_sql = "SELECT c.$pk FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where LIMIT $limit";
                    $ids = $wpdb->get_col($ids_sql);
                    if (!empty($ids)) {
                        $safe_ids = array_values(
                            array_filter(
                                array_map("absint", $ids),
                                static function ($id) {
                                    return $id > 0;
                                }
                            )
                        );

                        if (!empty($safe_ids)) {
                            $ids_string = implode(",", $safe_ids);

                            if ($type === "child_posts") {
                                $deleted_post_ids = $safe_ids;
                            }

                            $deleted_count = (int) OPTISTATE_Utils::transaction(
                                function () use (
                                    $wpdb,
                                    $child_table,
                                    $pk,
                                    $ids_string,
                                    $type
                                ) {
                                    if ($type === "comments_on_deleted") {
                                        if (
                                            $wpdb->query(
                                                "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($ids_string)"
                                            ) === false
                                        ) {
                                            throw new \Exception(
                                                "commentmeta delete failed: " .
                                                    $wpdb->last_error
                                            );
                                        }
                                    } elseif ($type === "child_posts") {
                                        if (
                                            $wpdb->query(
                                                "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids_string)"
                                            ) === false
                                        ) {
                                            throw new \Exception(
                                                "postmeta delete failed: " .
                                                    $wpdb->last_error
                                            );
                                        }
                                        if (
                                            $wpdb->query(
                                                "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($ids_string)"
                                            ) === false
                                        ) {
                                            throw new \Exception(
                                                "term_relationships delete failed: " .
                                                    $wpdb->last_error
                                            );
                                        }
                                    }

                                    $deleted = $wpdb->query(
                                        "DELETE FROM $child_table WHERE $pk IN ($ids_string)"
                                    );

                                    if ($deleted === false) {
                                        throw new \Exception(
                                            "orphan delete failed: " .
                                                $wpdb->last_error
                                        );
                                    }

                                    return $deleted;
                                }
                            );
                        }
                    }
                }
            } catch (Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "Integrity fix failed: " . $e->getMessage(),
                    [
                        "type" => $type,
                        "child_table" => $child_table,
                        "parent_table" => $parent_table,
                    ]
                );

                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Database error during fix. Transaction rolled back.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $remaining_sql = "SELECT COUNT(c.{$rule["child_key"]}) FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where";
            $remaining = (int) $wpdb->get_var($remaining_sql);

            if ($deleted_count > 0) {
                $main_plugin->clear_stats_cache();
                $main_plugin->log_entry(
                    "🔗 " .
                        sprintf(
                            __(
                                "Integrity Fix: Cleaned %s orphaned rows in %s",
                                "optistate"
                            ),
                            number_format_i18n($deleted_count),
                            $rule["label"]
                        )
                );
                foreach ($deleted_post_ids as $post_id) {
                    clean_post_cache($post_id);
                }

                if ($remaining === 0 || $deleted_count > 100) {
                    if (!empty($affected_object_ids)) {
                        foreach ($affected_object_ids as $obj_id) {
                            clean_post_cache($obj_id);
                        }
                    }
                    wp_cache_delete("last_changed", "terms");
                }
            }

            OPTISTATE_Utils::send_json_success([
                "count" => $deleted_count,
                "remaining" => $remaining,
                "message" => sprintf(
                    __("Cleaned %s rows.", "optistate"),
                    number_format_i18n($deleted_count)
                ),
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Fix integrity outer error: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred during integrity fix.",
                    "optistate"
                ),
            ]);
        }
    }
    private static function get_integrity_rules(): array
    {
        return apply_filters("optistate_integrity_rules", [
            "postmeta" => [
                "label" => __("Post Meta (Orphaned)", "optistate"),
                "child_table" => "postmeta",
                "child_key" => "post_id",
                "parent_table" => "posts",
                "parent_key" => "ID",
                "context_col" => "meta_key",
                "pk" => "meta_id",
            ],
            "commentmeta" => [
                "label" => __("Comment Meta (Orphaned)", "optistate"),
                "child_table" => "commentmeta",
                "child_key" => "comment_id",
                "parent_table" => "comments",
                "parent_key" => "comment_ID",
                "context_col" => "meta_key",
                "pk" => "meta_id",
            ],
            "usermeta" => [
                "label" => __("User Meta (Orphaned)", "optistate"),
                "child_table" => "usermeta",
                "child_key" => "user_id",
                "parent_table" => "users",
                "parent_key" => "ID",
                "context_col" => "meta_key",
                "pk" => "umeta_id",
            ],
            "termmeta" => [
                "label" => __("Term Meta (Orphaned)", "optistate"),
                "child_table" => "termmeta",
                "child_key" => "term_id",
                "parent_table" => "terms",
                "parent_key" => "term_id",
                "context_col" => "meta_key",
                "pk" => "meta_id",
            ],
            "term_taxonomy" => [
                "label" => __("Zombie Taxonomies (No Term Def)", "optistate"),
                "child_table" => "term_taxonomy",
                "child_key" => "term_id",
                "parent_table" => "terms",
                "parent_key" => "term_id",
                "context_col" => "taxonomy",
                "pk" => "term_taxonomy_id",
            ],
            "term_relationships" => [
                "label" => __(
                    "Broken Relationships (No Taxonomy)",
                    "optistate"
                ),
                "child_table" => "term_relationships",
                "child_key" => "term_taxonomy_id",
                "parent_table" => "term_taxonomy",
                "parent_key" => "term_taxonomy_id",
                "context_col" => "object_id",
                "pk" => false,
            ],
            "child_posts" => [
                "label" => __(
                    "Orphaned Post Children & Revisions (No Parent)",
                    "optistate"
                ),
                "child_table" => "posts",
                "child_key" => "post_parent",
                "parent_table" => "posts",
                "parent_key" => "ID",
                "context_col" => "post_title",
                "pk" => "ID",
                "extra_where" =>
                    "AND c.post_parent > 0 AND c.post_type != 'attachment'",
            ],
            "comments_on_deleted" => [
                "label" => __("Comments on Deleted Posts", "optistate"),
                "child_table" => "comments",
                "child_key" => "comment_post_ID",
                "parent_table" => "posts",
                "parent_key" => "ID",
                "context_col" => "comment_content",
                "pk" => "comment_ID",
                "extra_where" => "AND c.comment_post_ID > 0",
            ],
        ]);
    }

    private static function is_valid_integrity_rule(
        array $rule,
        $type = null
    ): bool {
        $id_re = '/^[a-zA-Z0-9_]+$/';

        if ($type !== null) {
            if (!is_string($type) || preg_match($id_re, $type) !== 1) {
                return false;
            }
        }

        if (
            preg_match($id_re, $rule["child_table"] ?? "") !== 1 ||
            preg_match($id_re, $rule["parent_table"] ?? "") !== 1 ||
            preg_match($id_re, $rule["child_key"] ?? "") !== 1 ||
            preg_match($id_re, $rule["parent_key"] ?? "") !== 1 ||
            preg_match($id_re, $rule["context_col"] ?? "") !== 1
        ) {
            return false;
        }
        $pk = $rule["pk"] ?? null;
        if ($pk !== false && preg_match($id_re, (string) $pk) !== 1) {
            return false;
        }
        $extra_where = isset($rule["extra_where"])
            ? (string) $rule["extra_where"]
            : "";
        if ($extra_where !== "") {
            $ew_re =
                "/^(\s*AND\s+[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*" .
                '\s*(?:=|!=|<>|>=|<=|>|<)\s*(?:\d+|\'[a-zA-Z0-9_\-]+\')\s*)+$/i';
            if (preg_match($ew_re, $extra_where) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function fallback_integrity_scan(
        array $rules,
        float $start_time,
        int $max_exec,
        array &$results,
        int &$total_issues
    ): void {
        global $wpdb;

        foreach ($rules as $type => $rule) {
            if (
                !is_array($rule) ||
                !self::is_valid_integrity_rule($rule, $type)
            ) {
                continue;
            }
            if (microtime(true) - $start_time > $max_exec) {
                $results[] = [
                    "type" => "timeout",
                    "label" => __("Scan paused (Time Limit)", "optistate"),
                    "count" => 0,
                    "child_table" => "...",
                    "parent_table" => "...",
                    "samples" => [],
                ];
                break;
            }

            $child_table = $wpdb->prefix . $rule["child_table"];
            if (!OPTISTATE_Utils::table_exists($child_table)) {
                continue;
            }
            $parent_table = $wpdb->prefix . $rule["parent_table"];
            $extra_where = isset($rule["extra_where"])
                ? $rule["extra_where"]
                : "";

            $sql = "SELECT COUNT(*) FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where";
            $count = (int) $wpdb->get_var($sql);

            if ($count > 0) {
                $total_issues += $count;
                $context_col = $rule["context_col"];
                $sample_sql = "SELECT c.{$rule["child_key"]} as fk_id, SUBSTRING(c.$context_col, 1, 50) as context FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where LIMIT 3";
                $samples = $wpdb->get_results($sample_sql);

                $results[] = [
                    "type" => $type,
                    "label" => $rule["label"],
                    "count" => $count,
                    "child_table" => $rule["child_table"],
                    "parent_table" => $rule["parent_table"],
                    "samples" => $samples,
                ];
            }
        }
    }
    public static function validate_column_name(
        string $column_name,
        string $table_name
    ): bool {
        global $wpdb;
        $clean_column = preg_replace('/\(\d+\)$/', "", $column_name);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $clean_column)) {
            return false;
        }

        $escaped_table = OPTISTATE_Utils::validate_table_name($table_name);
        if (!$escaped_table) {
            return false;
        }
        static $column_cache = [];
        $cache_key = strtolower($table_name);
        if (!isset($column_cache[$cache_key])) {
            $raw = $wpdb->get_col("SHOW COLUMNS FROM $escaped_table");
            $column_cache[$cache_key] = $raw
                ? array_map("strtolower", $raw)
                : [];
        }

        return in_array(
            strtolower($clean_column),
            $column_cache[$cache_key],
            true
        );
    }

    public static function check_disk_space_for_index(string $table_name): array
    {
        global $wpdb;
        $result = [
            "success" => false,
            "available_space" => 0,
            "required_space" => 0,
            "message" => "",
        ];

        $status = OPTISTATE_Utils::get_table_status($table_name);
        if (!$status) {
            $result["message"] = __(
                "Unable to determine table size.",
                "optistate"
            );
            return $result;
        }

        $table_size =
            (float) ($status["DATA_LENGTH"] ?? 0) +
            (float) ($status["INDEX_LENGTH"] ?? 0);
        $required_space = $table_size * 2;

        $free_space = false;
        $datadir = $wpdb->get_var("SELECT @@datadir");
        if ($datadir && is_dir($datadir)) {
            $free_space = @disk_free_space($datadir);
        }
        if ($free_space === false) {
            $free_space = @disk_free_space(WP_CONTENT_DIR);
            if ($free_space === false) {
                $free_space = @disk_free_space(ABSPATH);
            }
        }

        if ($free_space === false) {
            $result["success"] = true;
            $result["message"] = __(
                "Note: Environment prevents disk space verification. Proceeding with safety checks.",
                "optistate"
            );
            return $result;
        }

        $result["available_space"] = $free_space;
        $result["required_space"] = $required_space;
        $safety_buffer = OPTISTATE_Utils::DISK_SAFETY_BUFFER_BYTES;

        if ($free_space >= $required_space + $safety_buffer) {
            $result["success"] = true;
            $result["message"] = __(
                "Sufficient disk space available.",
                "optistate"
            );
        } else {
            $result["success"] = false;
            $result["message"] = sprintf(
                __(
                    "Insufficient Disk Space! Available: %s, Required (Est): %s",
                    "optistate"
                ),
                size_format($free_space, 2),
                size_format($required_space + $safety_buffer, 2)
            );
        }

        return $result;
    }

    public static function verify_index_operation(
        string $escaped_table,
        string $index_name,
        string $type
    ): bool {
        global $wpdb;
        $check = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW INDEX FROM $escaped_table WHERE Key_name = %s",
                $index_name
            )
        );
        return $type === "add" ? !empty($check) : empty($check);
    }

    public static function mark_index_task_error(
        OPTISTATE_Process_Store $process_store,
        string $task_id,
        array $task,
        string $message
    ): void {
        $task["status"] = "error";
        $task["message"] = $message;
        $process_store->set($task_id, $task, self::INDEX_TASK_TTL);
    }
    public static function get_optimize_tables_transient_key(): string
    {
        $user_id = get_current_user_id();
        return "optistate_optimize_tables_state_" . ($user_id ? $user_id : 0);
    }

    public static function should_skip_table_optimization(array $table): bool
    {
        if (
            !isset($table["DATA_FREE"]) ||
            empty($table["DATA_FREE"]) ||
            intval($table["DATA_FREE"]) < 1024
        ) {
            return true;
        }
        if (!isset($table["TABLE_ROWS"]) || intval($table["TABLE_ROWS"]) == 0) {
            return true;
        }
        if (
            isset($table["ENGINE"]) &&
            strtoupper($table["ENGINE"]) === "MEMORY"
        ) {
            return true;
        }
        if (
            isset($table["TABLE_TYPE"]) &&
            $table["TABLE_TYPE"] !== "BASE TABLE"
        ) {
            return true;
        }
        return false;
    }

    public static function push_detail(array &$results, array $detail): void
    {
        if (!isset($results["details"]) || !is_array($results["details"])) {
            $results["details"] = [];
        }

        if (count($results["details"]) < self::MAX_DETAIL_ROWS) {
            $results["details"][] = $detail;
            return;
        }

        $results["details_truncated"] = true;
    }

    public static function optimize_table_enterprise(
        string $table_name,
        string $engine
    ): array {
        global $wpdb;
        $escaped_table = OPTISTATE_Utils::validate_table_name($table_name);
        if (!$escaped_table) {
            return [
                "success" => false,
                "error" => "Invalid table name",
                "method" => null,
            ];
        }

        $engine = strtoupper($engine);

        if ($engine === "INNODB") {
            $attempts = [
                [
                    "sql" => "ALTER TABLE $escaped_table ENGINE=InnoDB, ALGORITHM=INPLACE, LOCK=NONE",
                    "method" => "Online DDL (Lock-Free)",
                ],
                [
                    "sql" => "ALTER TABLE $escaped_table ENGINE=InnoDB, ALGORITHM=INPLACE, LOCK=SHARED",
                    "method" => "Online DDL (Shared Lock)",
                ],
                [
                    "sql" => "ALTER TABLE $escaped_table ENGINE=InnoDB",
                    "method" => "Table Rebuild",
                ],
            ];

            $last_error = "";
            foreach ($attempts as $attempt) {
                $suppress = $wpdb->suppress_errors(true);
                $result = $wpdb->query($attempt["sql"]);
                $err = $wpdb->last_error;
                $wpdb->suppress_errors($suppress);

                if ($result !== false) {
                    return [
                        "success" => true,
                        "method" => $attempt["method"],
                        "error" => null,
                    ];
                }
                if (!empty($err)) {
                    $last_error = $err;
                }
            }

            OPTISTATE_Utils::log_critical_error(
                "Table optimize failed (InnoDB)",
                [
                    "table" => $table_name,
                    "engine" => $engine,
                    "error" => $last_error,
                ]
            );
            return [
                "success" => false,
                "error" => $last_error,
                "method" => null,
            ];
        }
        if ($engine === "MYISAM") {
            $suppress = $wpdb->suppress_errors(true);
            $result = $wpdb->query("OPTIMIZE TABLE $escaped_table");
            $err = $wpdb->last_error;
            $wpdb->suppress_errors($suppress);

            if ($result !== false) {
                return [
                    "success" => true,
                    "method" => "Standard (MyISAM Locked)",
                    "error" => null,
                ];
            }

            OPTISTATE_Utils::log_critical_error(
                "Table optimize failed (MyISAM)",
                ["table" => $table_name, "error" => $err]
            );

            return [
                "success" => false,
                "error" => $err,
                "method" => null,
            ];
        }

        $suppress = $wpdb->suppress_errors(true);
        $result = $wpdb->query("OPTIMIZE TABLE $escaped_table");
        $err = $wpdb->last_error;
        $wpdb->suppress_errors($suppress);

        if ($result === false) {
            OPTISTATE_Utils::log_critical_error(
                "Table optimize failed (generic)",
                [
                    "table" => $table_name,
                    "engine" => $engine,
                    "error" => $err,
                ]
            );
        }

        return [
            "success" => $result !== false,
            "error" => $result === false ? $err : null,
            "method" => "Standard",
        ];
    }

    public static function optimize_with_lock_retry(
        string $table_name,
        string $engine,
        int $max_retries = 3
    ): array {
        $retry_delay = 1;
        $result = ["success" => false, "error" => "Unknown", "method" => null];

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $result = self::optimize_table_enterprise($table_name, $engine);
            if ($result["success"]) {
                return $result;
            }

            $err = (string) ($result["error"] ?? "");
            if (
                $attempt < $max_retries &&
                (stripos($err, "Lock wait") !== false ||
                    stripos($err, "deadlock") !== false)
            ) {
                sleep($retry_delay);
                $retry_delay *= 2;
                continue;
            }
            break;
        }

        return $result;
    }
}