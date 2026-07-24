<?php
if (!defined("ABSPATH")) {
    exit();
}

class OPTISTATE_Advanced_Tools
{
    private const AUTOLOAD_BACKUP_KEY = "optistate_autoload_backup";
    private const BATCH_SIZE_CHECK = 20;
    private const BATCH_SIZE_REPAIR = 5;
    private const BATCH_SIZE_OPTIMIZE = 5;
    private const BATCH_SIZE_FETCH_STANDARD = 1000;
    private const BATCH_SIZE_FETCH_CLI = 2000;
    private const BATCH_SIZE_UPDATE = 100;

    private OPTISTATE $main_plugin;
    private OPTISTATE_Process_Store $process_store;
    private $plugin_prefix_map = null;
    private static $essential_regex = null;
    private static $plugin_map_cache_for_deletion = null;
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

    private static function compile_patterns(): void
    {
        if (self::$essential_regex !== null) {
            return;
        }
        $patterns = [
            '/^active_plugins$/',
            '/^template$/',
            '/^stylesheet$/',
            '/^siteurl$/',
            '/^home$/',
            '/^rewrite_rules$/',
            '/^cron$/',
            '/^wp_user_roles$/',
            '/^blogname$/',
            '/^admin_email$/',
            '/^permalink_structure$/',
            '/^show_on_front$/',
            '/^page_on_front$/',
            '/^page_for_posts$/',
            "/^theme_mods_/",
            "/^widget_/",
            "/^sidebars_widgets/",
        ];
        $essential_parts = [];
        foreach ($patterns as $pattern) {
            $essential_parts[] = preg_replace(
                '~^/\^?|/[a-zA-Z]*$~',
                "",
                $pattern
            );
        }
        self::$essential_regex =
            "/^(?:" . implode("|", $essential_parts) . ")/";
    }

    public function __construct(
        OPTISTATE $main_plugin,
        OPTISTATE_Process_Store $process_store
    ) {
        $this->main_plugin = $main_plugin;
        $this->process_store = $process_store;
        $this->process_store->ensure_table_exists();

        add_action("wp_ajax_optistate_optimize_autoload", [
            $this,
            "ajax_optimize_autoload",
        ]);
        add_action("wp_ajax_optistate_preview_autoload_options", [
            $this,
            "ajax_preview_autoload_options",
        ]);
        add_action("wp_ajax_optistate_restore_autoload_backup", [
            $this,
            "ajax_restore_autoload_backup",
        ]);
        add_action("wp_ajax_optistate_initiate_analyze_repair", [
            $this,
            "ajax_initiate_analyze_repair",
        ]);
        add_action("wp_ajax_optistate_run_analyze_repair_chunk", [
            $this,
            "ajax_run_analyze_repair_chunk",
        ]);
        add_action("wp_ajax_optistate_analyze_indexes", [
            $this,
            "ajax_analyze_indexes",
        ]);
        add_action("wp_ajax_optistate_manage_index", [
            $this,
            "ajax_manage_index",
        ]);
        add_action("wp_ajax_optistate_check_index_status", [
            $this,
            "ajax_check_index_status",
        ]);
        add_action("wp_ajax_optistate_scan_integrity", [
            $this,
            "ajax_scan_integrity",
        ]);
        add_action("wp_ajax_optistate_fix_integrity", [
            $this,
            "ajax_fix_integrity",
        ]);
        add_action("wp_ajax_optistate_get_table_analysis", [
            $this,
            "ajax_get_table_analysis",
        ]);
        add_action("optistate_run_index_chunk", [
            $this,
            "run_index_chunk_worker",
        ]);
        add_action("wp_ajax_optistate_optimize_tables", [
            $this,
            "ajax_optimize_tables",
        ]);
        add_action("wp_ajax_optistate_delete_table", [
            $this,
            "ajax_delete_table",
        ]);
    }

    public function ajax_get_table_analysis(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("table_analysis", 5)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        try {
            $cache_key = "optistate_table_analysis_" . md5(DB_NAME);
            $cached_analysis = wp_cache_get($cache_key, "optistate");
            if (is_array($cached_analysis)) {
                OPTISTATE_Utils::send_json_success($cached_analysis);
                return;
            }

            global $wpdb;
            OPTISTATE_Utils::preload_all_table_statuses();

            $core_table_definitions = [
                "commentmeta" => __(
                    "Comment Meta: Stores custom fields and extra data for comments.",
                    "optistate"
                ),
                "comments" => __(
                    "Comments: Contains all comments on posts, pages, and attachments.",
                    "optistate"
                ),
                "links" => __(
                    "Links: Stores blogroll links. Deprecated and rarely used in modern sites.",
                    "optistate"
                ),
                "options" => __(
                    "Options: Stores sitewide settings, plugin/theme configurations, and cached data.",
                    "optistate"
                ),
                "postmeta" => __(
                    "Post Meta: Contains custom fields and extra data for posts, pages, and any custom post types (e.g., products, events).",
                    "optistate"
                ),
                "posts" => __(
                    "Posts: Stores all content, including posts, pages, attachments, and revisions.",
                    "optistate"
                ),
                "termmeta" => __(
                    "Term Meta: Stores custom fields and extra data for taxonomy terms (categories, tags).",
                    "optistate"
                ),
                "terms" => __(
                    "Terms: Stores the names and slugs for all categories, tags, and custom taxonomy terms.",
                    "optistate"
                ),
                "term_relationships" => __(
                    "Term Relationships: Links posts (from wp_posts) to their terms (from wp_terms).",
                    "optistate"
                ),
                "term_taxonomy" => __(
                    "Term Taxonomy: Defines the taxonomy (e.g., category, tag) for each term in wp_terms.",
                    "optistate"
                ),
                "usermeta" => __(
                    "User Meta: Stores extra user data, like first/last name, and user preferences.",
                    "optistate"
                ),
                "users" => __(
                    "Users: Stores all user accounts, including login names, hashed passwords, and emails.",
                    "optistate"
                ),
                "blogmeta" => __(
                    "Blog Meta (Multisite): Stores extra data for sites in the network.",
                    "optistate"
                ),
                "blogs" => __(
                    "Blogs (Multisite): Stores information about each site in the network.",
                    "optistate"
                ),
                "registration_log" => __(
                    "Registration Log (Multisite): Stores log of new user registrations.",
                    "optistate"
                ),
                "signups" => __(
                    "Signups (Multisite): Stores user signups, used when new blog/user registration is enabled.",
                    "optistate"
                ),
                "site" => __(
                    "Site (Multisite): Stores network‑wide site data.",
                    "optistate"
                ),
                "sitemeta" => __(
                    "Site Meta (Multisite): Stores extra network‑wide site meta data.",
                    "optistate"
                ),
            ];

            $prefix_pattern =
                "/^" . preg_quote($wpdb->base_prefix, "/") . "(\d+_)?/";
            $tables = OPTISTATE_Utils::get_all_tables();

            if (empty($tables)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Failed to retrieve table information", "optistate"),
                ]);
                return;
            }

            $analysis = [
                "core_tables" => [],
                "plugin_tables" => [],
                "totals" => [
                    "total_tables" => 0,
                    "core_count" => 0,
                    "plugin_count" => 0,
                    "total_size" => 0,
                    "core_size" => 0,
                    "plugin_size" => 0,
                    "total_rows" => 0,
                ],
                "db_name" => DB_NAME,
            ];

            $date_format =
                OPTISTATE_Utils::get_cached_option("date_format") .
                " " .
                OPTISTATE_Utils::get_cached_option("time_format");
            $now = time();
            $this->build_plugin_prefix_map();

            foreach ($tables as $table_name) {
                $status = OPTISTATE_Utils::get_table_status($table_name);
                if (!$status) {
                    continue;
                }
                $base_name = preg_replace($prefix_pattern, "", $table_name);
                $is_core = OPTISTATE_Utils::is_core_table($table_name);

                $is_optistate_processes =
                    $table_name === $wpdb->prefix . "optistate_processes";
                $is_optistate_metadata =
                    $table_name === $wpdb->prefix . "optistate_backup_metadata";
                $is_optistate_login =
                    $table_name ===
                    $wpdb->prefix . OPTISTATE_Login_Protection::TABLE_NAME;
                $is_optistate_core =
                    $table_name === $wpdb->prefix . "optistate_core_data";
                $is_optistate_trash =
                    $table_name === $wpdb->prefix . "optistate_trash";
                $is_trash_table =
                    strpos($table_name, $wpdb->prefix . "trash_") === 0;

                $is_optistate =
                    $is_optistate_processes ||
                    $is_optistate_metadata ||
                    $is_optistate_login ||
                    $is_optistate_core ||
                    $is_optistate_trash ||
                    $is_trash_table;

                $description = $is_core
                    ? $core_table_definitions[$base_name]
                    : __("Third-party plugin/theme table", "optistate");
                $is_identified_in_map = false;
                $matched_plugin_data = null;

                if ($is_optistate_processes) {
                    $description = __(
                        "WP Optimal State Plugin: Ensures reliability in sensitive database operations by persisting backup/restore states to prevent timeouts.",
                        "optistate"
                    );
                } elseif ($is_optistate_metadata) {
                    $description = __(
                        "WP Optimal State Plugin: Stores metadata for generated database backups to verify file integrity and enforce retention limits.",
                        "optistate"
                    );
                } elseif ($is_optistate_login) {
                    $description = __(
                        "WP Optimal State Plugin: Stores login attempts and block records for the Login Protection feature. Used to prevent brute-force attacks.",
                        "optistate"
                    );
                } elseif ($is_optistate_core) {
                    $description = __(
                        "WP Optimal State Plugin: Stores plugin settings, optimization logs, and other persistent data required for core functionality.",
                        "optistate"
                    );
                } elseif ($is_optistate_trash) {
                    $description = __(
                        "WP Optimal State Plugin: Stores metadata for all items moved to trash. These items can be restored within 14 days via the Cleanup tab → Legacy Plugin Data Scanner → Trash.",
                        "optistate"
                    );
                } elseif ($is_trash_table) {
                    $description = __(
                        "Table moved to the trash - will be automatically removed after 14 days. You can restore it via the Cleanup tab → Legacy Plugin Data Scanner → Trash.",
                        "optistate"
                    );
                } elseif (!$is_core) {
                    $base_lower = strtolower($base_name);
                    foreach ($this->plugin_prefix_map as $prefix => $data) {
                        if (strpos($base_lower, $prefix) === 0) {
                            $description = sprintf(
                                __("➔ %s (%s)", "optistate"),
                                esc_html($data["name"]),
                                __("Plugin/Theme", "optistate")
                            );
                            $is_identified_in_map = true;
                            $matched_plugin_data = $data;
                            break;
                        }
                    }
                }

                $updated_local_formatted = isset($status["UPDATE_TIME"])
                    ? mysql2date($date_format, $status["UPDATE_TIME"], true)
                    : __("Unknown", "optistate");
                $created_local_formatted = isset($status["CREATE_TIME"])
                    ? mysql2date($date_format, $status["CREATE_TIME"], true)
                    : __("Unknown", "optistate");

                $is_abandoned = false;
                $abandoned_text = "";
                $abandoned_threshold = 2592000;

                if (
                    !($is_core || $is_optistate) &&
                    isset($status["UPDATE_TIME"])
                ) {
                    $update_ts = strtotime($status["UPDATE_TIME"]);
                    if (
                        $update_ts &&
                        $now - $update_ts > $abandoned_threshold
                    ) {
                        $is_abandoned = true;
                        $abandoned_text = __(
                            "This table has not been accessed in over 30 days. It may belong to a deactivated or uninstalled plugin or theme.",
                            "optistate"
                        );
                    }
                }

                $can_delete = false;
                if ($is_abandoned) {
                    if ($is_identified_in_map && $matched_plugin_data) {
                        $is_installed_or_active = $this->main_plugin->legacy_scanner->is_item_active_or_installed(
                            $matched_plugin_data
                        );
                        $can_delete = !$is_installed_or_active;
                    } else {
                        $can_delete = true;
                    }
                }

                $overhead_bytes = isset($status["DATA_FREE"])
                    ? (int) $status["DATA_FREE"]
                    : 0;

                $table_info = [
                    "name" => $table_name,
                    "rows" => isset($status["TABLE_ROWS"])
                        ? (int) $status["TABLE_ROWS"]
                        : 0,
                    "data_size" => isset($status["DATA_LENGTH"])
                        ? (int) $status["DATA_LENGTH"]
                        : 0,
                    "index_size" => isset($status["INDEX_LENGTH"])
                        ? (int) $status["INDEX_LENGTH"]
                        : 0,
                    "total_size" =>
                        (isset($status["DATA_LENGTH"])
                            ? (int) $status["DATA_LENGTH"]
                            : 0) +
                        (isset($status["INDEX_LENGTH"])
                            ? (int) $status["INDEX_LENGTH"]
                            : 0),
                    "overhead" => $overhead_bytes,
                    "engine" => $status["ENGINE"] ?? "",
                    "collation" => $status["TABLE_COLLATION"] ?? "",
                    "updated" => $updated_local_formatted,
                    "created" => $created_local_formatted,
                    "description" => $description,
                    "is_core" => $is_core || $is_optistate,
                    "is_abandoned" => $is_abandoned,
                    "abandoned_text" => $abandoned_text,
                    "is_identified_in_map" => $is_identified_in_map,
                    "can_delete" => $can_delete,
                ];

                if ($is_core || $is_optistate) {
                    $analysis["core_tables"][] = $table_info;
                    $analysis["totals"]["core_count"]++;
                    $analysis["totals"]["core_size"] +=
                        $table_info["total_size"];
                } else {
                    $analysis["plugin_tables"][] = $table_info;
                    $analysis["totals"]["plugin_count"]++;
                    $analysis["totals"]["plugin_size"] +=
                        $table_info["total_size"];
                }

                $analysis["totals"]["total_size"] += $table_info["total_size"];
                $analysis["totals"]["total_rows"] += $table_info["rows"];
                $analysis["totals"]["total_tables"]++;
            }

            wp_cache_set($cache_key, $analysis, "optistate", 300);
            OPTISTATE_Utils::send_json_success($analysis);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Table analysis failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred during table analysis.",
                    "optistate"
                ),
            ]);
        }
    }

    public function ajax_initiate_analyze_repair(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("heavy_op", 20)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        try {
            $user_id = get_current_user_id();
            $session_tracker_key = "optistate_analyze_session_{$user_id}";
            $prev_session_key = get_option($session_tracker_key);
            if (
                is_string($prev_session_key) &&
                strpos($prev_session_key, "optistate_analyze_") === 0
            ) {
                delete_option($prev_session_key);
            }

            $table_names = OPTISTATE_Utils::get_all_tables();
            if (empty($table_names)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "No tables found in the database.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $valid_table_names = array_filter($table_names, function ($name) {
                return preg_match('/^[a-zA-Z0-9_]+$/', $name);
            });

            if (empty($valid_table_names)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("No valid tables found.", "optistate"),
                ]);
                return;
            }

            try {
                $unique_hash = bin2hex(random_bytes(14));
            } catch (\Throwable $e) {
                $unique_hash = md5(uniqid(wp_rand(), true));
            }

            $transient_key = "optistate_analyze_" . $unique_hash;
            $state = [
                "current_step" => "check",
                "tables_to_check" => $valid_table_names,
                "tables_to_repair" => [],
                "tables_to_optimize" => [],
                "table_statuses" => [],
                "final_results" => [
                    "analyzed" => 0,
                    "repaired" => 0,
                    "corrupted" => 0,
                    "optimized" => 0,
                    "failed" => 0,
                    "details" => [],
                ],
                "total_tables" => count($valid_table_names),
                "processed_check_count" => 0,
            ];

            update_option($transient_key, $state, "no");
            update_option($session_tracker_key, $transient_key, "no");

            OPTISTATE_Utils::send_json_success([
                "status" => "starting",
                "transient_key" => $transient_key,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Initiate analyze/repair failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred while starting the analysis.",
                    "optistate"
                ),
            ]);
        }
    }

    public function ajax_run_analyze_repair_chunk(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        $original_time_limit = (int) ini_get("max_execution_time");
        $disable_functions = ini_get("disable_functions");
        $is_disabled =
            !empty($disable_functions) &&
            in_array(
                "set_time_limit",
                array_map(
                    "trim",
                    explode(",", strtolower($disable_functions))
                ),
                true
            );
        if (!$is_disabled) {
            try {
                OPTISTATE_Utils::safe_set_time_limit(300);
            } catch (\Throwable $e) {
            }
        }

        try {
            $transient_key = isset($_POST["transient_key"])
                ? sanitize_text_field(wp_unslash($_POST["transient_key"]))
                : "";
            if (
                empty($transient_key) ||
                strpos($transient_key, "optistate_analyze_") !== 0
            ) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Invalid or missing session key.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $state = get_option($transient_key);
            if ($state === false) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Session expired. Please start over.",
                        "optistate"
                    ),
                ]);
                return;
            }

            global $wpdb;
            $lock_name = "optistate_ar_" . md5($transient_key);
            $lock_acquired = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_name)
            );

            if ($lock_acquired !== 1) {
                OPTISTATE_Utils::send_json_success([
                    "status" => "running",
                    "step" => __("Waiting for previous chunk...", "optistate"),
                    "percentage" => 0,
                ]);
                return;
            }

            $this->register_lock_release_on_shutdown($lock_name);
            $state = get_option($transient_key);
            if ($state === false) {
                $wpdb->query(
                    $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)
                );
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Session expired. Please start over.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $use_cli_optimization =
                defined("WP_CLI") && WP_CLI && php_sapi_name() === "cli";

            try {
                switch ($state["current_step"]) {
                    case "check":
                        $check_batch_size = self::BATCH_SIZE_CHECK;
                        $tables_to_check_in_batch_raw = array_slice(
                            $state["tables_to_check"],
                            0,
                            $check_batch_size
                        );

                        if (empty($tables_to_check_in_batch_raw)) {
                            $state["current_step"] = "repair";
                            $state["final_results"]["analyzed"] =
                                $state["total_tables"];
                            $corrupted_tables = array_filter(
                                $state["table_statuses"],
                                function ($status) {
                                    return !empty($status["corrupted"]);
                                }
                            );
                            $state["final_results"]["corrupted"] = count(
                                $corrupted_tables
                            );
                            update_option($transient_key, $state, "no");
                            OPTISTATE_Utils::send_json_success([
                                "status" => "running",
                                "step" => __("Repairing...", "optistate"),
                                "percentage" => 100,
                            ]);
                            return;
                        }

                        $safe_batch_sql = [];
                        $safe_batch_to_check_map = [];
                        foreach ($tables_to_check_in_batch_raw as $table_name) {
                            $safe_table = OPTISTATE_Utils::validate_table_name(
                                $table_name
                            );
                            if ($safe_table) {
                                $safe_batch_sql[] = $safe_table;
                                $safe_batch_to_check_map[
                                    $table_name
                                ] = $safe_table;
                                if (
                                    !isset(
                                        $state["table_statuses"][$table_name]
                                    )
                                ) {
                                    $state["table_statuses"][$table_name] = [
                                        "table" => $table_name,
                                        "corrupted" => false,
                                        "repaired" => false,
                                        "optimized" => false,
                                        "error" => null,
                                    ];
                                }
                            } else {
                                $state["final_results"]["failed"]++;
                                $state["table_statuses"][$table_name] = [
                                    "table" => $table_name,
                                    "corrupted" => false,
                                    "repaired" => false,
                                    "optimized" => false,
                                    "error" => "Invalid name",
                                ];
                            }
                        }

                        if (!empty($safe_batch_sql)) {
                            if (
                                $use_cli_optimization &&
                                count($safe_batch_sql) > 1
                            ) {
                                try {
                                    $placeholders = implode(
                                        ", ",
                                        $safe_batch_sql
                                    );
                                    $check_query = "CHECK TABLE $placeholders";
                                    $check_results = $wpdb->get_results(
                                        $check_query,
                                        ARRAY_A
                                    );
                                    if (
                                        $check_results !== false &&
                                        !empty($check_results)
                                    ) {
                                        $results_by_table = [];
                                        foreach ($check_results as $check_row) {
                                            $table_name_with_db =
                                                $check_row["Table"];
                                            $clean_table_name =
                                                strpos(
                                                    $table_name_with_db,
                                                    "."
                                                ) !== false
                                                    ? substr(
                                                        $table_name_with_db,
                                                        strpos(
                                                            $table_name_with_db,
                                                            "."
                                                        ) + 1
                                                    )
                                                    : $table_name_with_db;
                                            $clean_table_name = trim(
                                                $clean_table_name,
                                                "`"
                                            );
                                            if (
                                                !isset(
                                                    $results_by_table[
                                                        $clean_table_name
                                                    ]
                                                )
                                            ) {
                                                $results_by_table[
                                                    $clean_table_name
                                                ] = [];
                                            }
                                            $results_by_table[
                                                $clean_table_name
                                            ][] = $check_row;
                                        }
                                        foreach (
                                            $tables_to_check_in_batch_raw
                                            as $table_name
                                        ) {
                                            if (
                                                !isset(
                                                    $results_by_table[
                                                        $table_name
                                                    ]
                                                )
                                            ) {
                                                if (
                                                    isset(
                                                        $state[
                                                            "table_statuses"
                                                        ][$table_name]
                                                    )
                                                ) {
                                                    $state["table_statuses"][
                                                        $table_name
                                                    ]["error"] =
                                                        "No CHECK TABLE results returned";
                                                }
                                                continue;
                                            }
                                            [
                                                $needs_repair,
                                                $error_message,
                                                $is_ok,
                                            ] = $this->evaluate_check_table_rows(
                                                $results_by_table[$table_name]
                                            );
                                            $this->apply_check_result_to_state(
                                                $state,
                                                $table_name,
                                                $needs_repair,
                                                $error_message,
                                                $is_ok
                                            );
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    $use_cli_optimization = false;
                                }
                            }

                            if (
                                !$use_cli_optimization ||
                                count($safe_batch_sql) === 1
                            ) {
                                foreach (
                                    $tables_to_check_in_batch_raw
                                    as $table_name
                                ) {
                                    if (
                                        !isset(
                                            $safe_batch_to_check_map[
                                                $table_name
                                            ]
                                        )
                                    ) {
                                        continue;
                                    }
                                    $safe_table =
                                        $safe_batch_to_check_map[$table_name];
                                    $check_query = "CHECK TABLE $safe_table";
                                    $check_results = $wpdb->get_results(
                                        $check_query,
                                        ARRAY_A
                                    );
                                    if (empty($check_results)) {
                                        if (
                                            isset(
                                                $state["table_statuses"][
                                                    $table_name
                                                ]
                                            )
                                        ) {
                                            $state["table_statuses"][
                                                $table_name
                                            ]["error"] =
                                                "No CHECK TABLE results returned";
                                        }
                                        continue;
                                    }
                                    [
                                        $needs_repair,
                                        $error_message,
                                        $is_ok,
                                    ] = $this->evaluate_check_table_rows(
                                        $check_results
                                    );
                                    $this->apply_check_result_to_state(
                                        $state,
                                        $table_name,
                                        $needs_repair,
                                        $error_message,
                                        $is_ok
                                    );
                                }
                            }
                        }

                        $state["tables_to_check"] = array_slice(
                            $state["tables_to_check"],
                            $check_batch_size
                        );
                        $state["processed_check_count"] += count(
                            $tables_to_check_in_batch_raw
                        );
                        $percentage = min(
                            100,
                            round(
                                ($state["processed_check_count"] /
                                    $state["total_tables"]) *
                                    100
                            )
                        );
                        update_option($transient_key, $state, "no");
                        OPTISTATE_Utils::send_json_success([
                            "status" => "running",
                            "step" => __("Checking...", "optistate"),
                            "percentage" => $percentage,
                        ]);
                        return;

                    case "repair":
                        $repair_batch_size = self::BATCH_SIZE_REPAIR;
                        $tables_to_repair_in_batch_raw = array_slice(
                            $state["tables_to_repair"],
                            0,
                            $repair_batch_size
                        );

                        if (empty($tables_to_repair_in_batch_raw)) {
                            $state["current_step"] = "get_large_tables";
                            update_option($transient_key, $state, "no");
                            OPTISTATE_Utils::send_json_success([
                                "status" => "running",
                                "step" => __(
                                    "Finding large tables...",
                                    "optistate"
                                ),
                                "percentage" => 100,
                            ]);
                            return;
                        }

                        $safe_batch_to_repair = [];
                        $safe_batch_to_repair_map = [];
                        foreach (
                            $tables_to_repair_in_batch_raw
                            as $table_name
                        ) {
                            $safe_table = OPTISTATE_Utils::validate_table_name(
                                $table_name
                            );
                            if ($safe_table) {
                                $safe_batch_to_repair[] = $safe_table;
                                $safe_batch_to_repair_map[
                                    $table_name
                                ] = $safe_table;
                            }
                        }

                        if (!empty($safe_batch_to_repair)) {
                            $repaired_count_in_batch = 0;
                            foreach (
                                $tables_to_repair_in_batch_raw
                                as $table_name
                            ) {
                                if (
                                    !isset(
                                        $safe_batch_to_repair_map[$table_name]
                                    )
                                ) {
                                    $state["final_results"]["failed"]++;
                                    continue;
                                }

                                $status = OPTISTATE_Utils::get_table_status(
                                    $table_name
                                );
                                $engine = $status
                                    ? strtoupper($status["ENGINE"] ?? "")
                                    : "";
                                if ($engine === "INNODB") {
                                    $opt_result = $this->_optimize_with_lock_retry(
                                        $table_name,
                                        "InnoDB"
                                    );
                                    if ($opt_result["success"]) {
                                        $repaired_count_in_batch++;
                                        if (
                                            isset(
                                                $state["table_statuses"][
                                                    $table_name
                                                ]
                                            )
                                        ) {
                                            $state["table_statuses"][
                                                $table_name
                                            ]["repaired"] = true;
                                            $state["table_statuses"][
                                                $table_name
                                            ]["error"] = null;
                                        }
                                    } else {
                                        if (
                                            isset(
                                                $state["table_statuses"][
                                                    $table_name
                                                ]
                                            )
                                        ) {
                                            $state["table_statuses"][
                                                $table_name
                                            ]["repaired"] = false;
                                            $state["table_statuses"][
                                                $table_name
                                            ]["error"] =
                                                $opt_result["error"] ?:
                                                "InnoDB optimize failed";
                                        }
                                        $state["final_results"]["failed"]++;
                                    }
                                } else {
                                    $safe_table =
                                        $safe_batch_to_repair_map[$table_name];
                                    $repair_query = "REPAIR TABLE $safe_table";
                                    $repair_results = $wpdb->get_results(
                                        $repair_query,
                                        ARRAY_A
                                    );
                                    if (empty($repair_results)) {
                                        if (
                                            isset(
                                                $state["table_statuses"][
                                                    $table_name
                                                ]
                                            )
                                        ) {
                                            $state["table_statuses"][
                                                $table_name
                                            ]["error"] =
                                                "No REPAIR TABLE results returned";
                                        }
                                        $state["final_results"]["failed"]++;
                                        continue;
                                    }
                                    $repair_successful = false;
                                    $repair_error = null;
                                    foreach ($repair_results as $repair_row) {
                                        $msg_type = strtolower(
                                            trim(
                                                (string) $repair_row["Msg_type"]
                                            )
                                        );
                                        $msg_text = strtolower(
                                            trim(
                                                (string) $repair_row["Msg_text"]
                                            )
                                        );
                                        if (
                                            $msg_type === "status" &&
                                            $msg_text === "ok"
                                        ) {
                                            $repair_successful = true;
                                        }
                                        if ($msg_type === "error") {
                                            $repair_error =
                                                $repair_row["Msg_text"];
                                        }
                                        if (
                                            $msg_type === "warning" &&
                                            (strpos($msg_text, "failed") !==
                                                false ||
                                                strpos($msg_text, "cannot") !==
                                                    false)
                                        ) {
                                            $repair_error =
                                                $repair_row["Msg_text"];
                                        }
                                    }
                                    if ($repair_successful && !$repair_error) {
                                        $repaired_count_in_batch++;
                                        if (
                                            isset(
                                                $state["table_statuses"][
                                                    $table_name
                                                ]
                                            )
                                        ) {
                                            $state["table_statuses"][
                                                $table_name
                                            ]["repaired"] = true;
                                            $state["table_statuses"][
                                                $table_name
                                            ]["error"] = null;
                                        }
                                    } else {
                                        if (
                                            isset(
                                                $state["table_statuses"][
                                                    $table_name
                                                ]
                                            )
                                        ) {
                                            $state["table_statuses"][
                                                $table_name
                                            ]["repaired"] = false;
                                            $state["table_statuses"][
                                                $table_name
                                            ]["error"] =
                                                $repair_error ?:
                                                "Repair failed";
                                        }
                                        $state["final_results"]["failed"]++;
                                    }
                                }
                            }
                            $state["final_results"][
                                "repaired"
                            ] += $repaired_count_in_batch;
                        }

                        $state["tables_to_repair"] = array_slice(
                            $state["tables_to_repair"],
                            $repair_batch_size
                        );
                        update_option($transient_key, $state, "no");
                        OPTISTATE_Utils::send_json_success([
                            "status" => "running",
                            "step" => __("Repairing...", "optistate"),
                            "percentage" => 100,
                        ]);
                        return;

                    case "get_large_tables":
                        OPTISTATE_Utils::preload_all_table_statuses();
                        $large_tables = [];
                        foreach (
                            array_keys($state["table_statuses"])
                            as $table_name
                        ) {
                            $status = OPTISTATE_Utils::get_table_status(
                                $table_name
                            );
                            if (
                                $status &&
                                isset($status["TABLE_ROWS"]) &&
                                (int) $status["TABLE_ROWS"] > 1000
                            ) {
                                $large_tables[] = $table_name;
                            }
                        }
                        $state["tables_to_optimize"] = array_values(
                            array_unique(
                                array_merge(
                                    $state["tables_to_optimize"],
                                    $large_tables
                                )
                            )
                        );
                        $state["current_step"] = "optimize";
                        update_option($transient_key, $state, "no");
                        OPTISTATE_Utils::send_json_success([
                            "status" => "running",
                            "step" => __("Optimizing...", "optistate"),
                            "percentage" => 100,
                        ]);
                        return;

                    case "optimize":
                        $optimize_batch_size = self::BATCH_SIZE_OPTIMIZE;
                        $tables_to_optimize_in_batch_raw = array_slice(
                            $state["tables_to_optimize"],
                            0,
                            $optimize_batch_size
                        );

                        if (empty($tables_to_optimize_in_batch_raw)) {
                            $state["current_step"] = "done";
                            update_option($transient_key, $state, "no");
                            OPTISTATE_Utils::send_json_success([
                                "status" => "running",
                                "step" => __("Finishing up...", "optistate"),
                                "percentage" => 100,
                            ]);
                            return;
                        }

                        $safe_batch_to_optimize = [];
                        $safe_batch_to_optimize_map = [];
                        foreach (
                            $tables_to_optimize_in_batch_raw
                            as $table_name
                        ) {
                            $safe_table = OPTISTATE_Utils::validate_table_name(
                                $table_name
                            );
                            if ($safe_table) {
                                $safe_batch_to_optimize[] = $safe_table;
                                $safe_batch_to_optimize_map[
                                    $table_name
                                ] = $safe_table;
                            }
                        }

                        if (!empty($safe_batch_to_optimize)) {
                            $optimize_query =
                                "OPTIMIZE TABLE " .
                                implode(", ", $safe_batch_to_optimize);
                            $optimize_results = $wpdb->get_results(
                                $optimize_query,
                                ARRAY_A
                            );
                            $optimize_results_by_table = [];
                            foreach ($optimize_results as $optimize_row) {
                                $table_name_with_db =
                                    $optimize_row["Table"];
                                $clean_table_name =
                                    strpos($table_name_with_db, ".") !==
                                    false
                                        ? substr(
                                            $table_name_with_db,
                                            strpos(
                                                $table_name_with_db,
                                                "."
                                            ) + 1
                                        )
                                        : $table_name_with_db;
                                $clean_table_name = trim(
                                    $clean_table_name,
                                    "`"
                                );
                                if (
                                    !isset(
                                        $optimize_results_by_table[
                                            $clean_table_name
                                        ]
                                    )
                                ) {
                                    $optimize_results_by_table[
                                        $clean_table_name
                                    ] = [];
                                }
                                $optimize_results_by_table[
                                    $clean_table_name
                                ][] = $optimize_row;
                            }

                            $optimized_count_in_batch = 0;
                            foreach (
                                $tables_to_optimize_in_batch_raw
                                as $table_name
                            ) {
                                $optimize_successful = false;
                                $optimize_error = null;
                                if (
                                    isset(
                                        $optimize_results_by_table[$table_name]
                                    )
                                ) {
                                    foreach (
                                        $optimize_results_by_table[
                                            $table_name
                                        ]
                                        as $optimize_row
                                    ) {
                                        $msg_type = strtolower(
                                            trim(
                                                (string) $optimize_row[
                                                    "Msg_type"
                                                ]
                                            )
                                        );
                                        $msg_text = strtolower(
                                            trim(
                                                (string) $optimize_row[
                                                    "Msg_text"
                                                ]
                                            )
                                        );
                                        if (
                                            $msg_type === "status" &&
                                            ($msg_text === "ok" ||
                                                strpos(
                                                    $msg_text,
                                                    "table is already up to date"
                                                ) !== false)
                                        ) {
                                            $optimize_successful = true;
                                        }
                                        if (
                                            $msg_type === "error" ||
                                            ($msg_type === "note" &&
                                                strpos(
                                                    $msg_text,
                                                    "not supported"
                                                ) !== false)
                                        ) {
                                            $optimize_error =
                                                $optimize_row["Msg_text"];
                                        }
                                    }
                                }
                                if (
                                    $optimize_successful ||
                                    !$optimize_error
                                ) {
                                    $optimized_count_in_batch++;
                                    if (
                                        isset(
                                            $state["table_statuses"][
                                                $table_name
                                            ]
                                        )
                                    ) {
                                        $state["table_statuses"][
                                            $table_name
                                        ]["optimized"] = true;
                                    } else {
                                        $state["table_statuses"][
                                            $table_name
                                        ] = [
                                            "table" => $table_name,
                                            "corrupted" => false,
                                            "repaired" => false,
                                            "optimized" => true,
                                            "error" => null,
                                        ];
                                    }
                                } else {
                                    if (
                                        isset(
                                            $state["table_statuses"][
                                                $table_name
                                            ]
                                        )
                                    ) {
                                        $state["table_statuses"][
                                            $table_name
                                        ]["optimized"] = false;
                                        if ($optimize_error) {
                                            $state["table_statuses"][
                                                $table_name
                                            ]["error"] = $optimize_error;
                                        }
                                    }
                                }
                            }

                            $state["final_results"][
                                "optimized"
                            ] += $optimized_count_in_batch;
                        }

                        $state["tables_to_optimize"] = array_slice(
                            $state["tables_to_optimize"],
                            $optimize_batch_size
                        );
                        update_option($transient_key, $state, "no");
                        OPTISTATE_Utils::send_json_success([
                            "status" => "running",
                            "step" => __("Optimizing...", "optistate"),
                            "percentage" => 100,
                        ]);
                        return;

                    case "done":
                        delete_option($transient_key);
                        delete_option(
                            "optistate_analyze_session_" . get_current_user_id()
                        );
                        $opt_count = isset($state["final_results"]["optimized"])
                            ? (int) $state["final_results"]["optimized"]
                            : 0;
                        $rep_count = isset($state["final_results"]["repaired"])
                            ? (int) $state["final_results"]["repaired"]
                            : 0;

                        $this->main_plugin->log_entry(
                            sprintf(
                                "🛠️ " .
                                    __(
                                        "Analyzed & Repaired Tables (optimized %s - repaired %s) by {username}",
                                        "optistate"
                                    ),
                                number_format_i18n($opt_count),
                                number_format_i18n($rep_count)
                            )
                        );

                        $this->main_plugin->clear_stats_cache();
                        OPTISTATE_Utils::invalidate_table_cache();

                        $final_details = [];
                        foreach ($state["table_statuses"] as $table_status) {
                            $final_details[] = $table_status;
                        }
                        $state["final_results"]["details"] = $final_details;

                        OPTISTATE_Utils::send_json_success([
                            "status" => "done",
                            "results" => $state["final_results"],
                        ]);
                        return;
                }
            } catch (Throwable $e) {
                delete_option($transient_key);
                delete_option(
                    "optistate_analyze_session_" . get_current_user_id()
                );
                $step = isset($state["current_step"])
                    ? $state["current_step"]
                    : "unknown";
                $table_index = isset($state["current_idx"])
                    ? $state["current_idx"]
                    : "?";

                OPTISTATE_Utils::log_critical_error(
                    "Analyze/repair chunk failed: " . $e->getMessage(),
                    [
                        "file" => $e->getFile(),
                        "line" => $e->getLine(),
                        "step" => $step,
                        "table_index" => $table_index,
                    ]
                );

                $this->main_plugin->log_entry(
                    "❌ " .
                        sprintf(
                            __(
                                "Table analysis/repair failed at step '%s': %s",
                                "optistate"
                            ),
                            $step,
                            $e->getMessage()
                        ),
                    "error",
                    "",
                    ["step" => $step, "table_index" => $table_index]
                );

                OPTISTATE_Utils::send_json_error([
                    "message" => $e->getMessage(),
                ]);
                return;
            }
            delete_option($transient_key);
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unknown error occurred during the chunked process.",
                    "optistate"
                ),
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Run analyze/repair chunk outer error: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __("An unexpected error occurred.", "optistate"),
            ]);
        } finally {
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
            if (isset($lock_acquired, $lock_name) && $lock_acquired === 1) {
                $wpdb->query(
                    $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)
                );
            }
        }
    }

    private function register_lock_release_on_shutdown(string $lock_name): void
    {
        global $wpdb;
        register_shutdown_function(static function () use ($wpdb, $lock_name) {
            try {
                $wpdb->query(
                    $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)
                );
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to release lock '{$lock_name}' on shutdown: " . $e->getMessage()
                );
            }
        });
    }

    private function evaluate_check_table_rows(array $rows): array
    {
        $needs_repair = false;
        $error_message = null;
        $is_ok = false;

        foreach ($rows as $check_row) {
            $msg_type = strtolower(trim((string) $check_row["Msg_type"]));
            $msg_text = strtolower(trim((string) $check_row["Msg_text"]));

            if ($msg_type === "status" && $msg_text === "ok") {
                $is_ok = true;
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
                strpos($msg_text, "corrupt") !== false ||
                strpos($msg_text, "crashed") !== false ||
                strpos($msg_text, "repair by sort") !== false ||
                strpos($msg_text, "repair with keycache") !== false
            ) {
                $needs_repair = true;
                if (!$error_message) {
                    $error_message = $check_row["Msg_text"];
                }
            }
        }

        return [$needs_repair, $error_message, $is_ok];
    }

    private function apply_check_result_to_state(
        array &$state,
        string $table_name,
        bool $needs_repair,
        ?string $error_message,
        bool $is_ok
    ): void {
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

    private function get_autoload_candidates(): array
    {
        global $wpdb;
        $candidates = [];
        $total_size = 0;
        $count = 0;
        self::compile_patterns();

        $use_cli = defined("WP_CLI") && WP_CLI && php_sapi_name() === "cli";
        $start_time = time();
        $max_exec = (int) ini_get("max_execution_time");
        if ($max_exec <= 0) {
            $max_exec = 600;
        }
        $timeout_guard = max(5, $max_exec - 5);

        $sys_limit = ini_get("memory_limit");
        if (empty($sys_limit) || $sys_limit === "-1") {
            $memory_safety_limit = $use_cli
                ? 512 * 1024 * 1024
                : 256 * 1024 * 1024;
        } else {
            $memory_safety_limit =
                (int) (wp_convert_hr_to_bytes($sys_limit) * 0.9);
        }

        $fetch_batch_size = $use_cli
            ? self::BATCH_SIZE_FETCH_CLI
            : self::BATCH_SIZE_FETCH_STANDARD;
        $last_seen_id = 0;

        while (true) {
            if (time() - $start_time >= $timeout_guard) {
                break;
            }
            if (
                function_exists("memory_get_usage") &&
                memory_get_usage(true) > $memory_safety_limit
            ) {
                break;
            }

            $chunk = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_id, option_name, LENGTH(option_value) AS option_size FROM {$wpdb->options} WHERE autoload = 'yes' AND option_id > %d ORDER BY option_id ASC LIMIT %d",
                    $last_seen_id,
                    $fetch_batch_size
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
        ];
    }

    public function ajax_preview_autoload_options(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("preview_autoload", 10)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        try {
            $result = $this->get_autoload_candidates();
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

    public function ajax_restore_autoload_backup(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("restore_autoload_backup", 10)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        try {
            $backup = $this->get_autoload_backup();
            if (!$backup || !is_array($backup)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("No autoload backup found to restore.", "optistate"),
                ]);
                return;
            }

            $count = $this->restore_autoload_backup($backup);
            if ($count === false) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Failed to restore autoload backup.", "optistate"),
                ]);
                return;
            }

            if (is_array($backup) && !empty($backup)) {
                foreach (array_keys($backup) as $option_name) {
                    wp_cache_delete($option_name, "options");
                }
                wp_cache_delete("alloptions", "options");
                wp_cache_delete("notoptions", "options");
            }

            $this->delete_autoload_backup();

            $this->main_plugin->clear_stats_cache();
            $this->main_plugin->log_entry(
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

    private function save_autoload_backup(array $backup_data): void
    {
        if (empty($backup_data)) {
            return;
        }
        $this->main_plugin->set_store_data(
            self::AUTOLOAD_BACKUP_KEY,
            $backup_data
        );
    }

    private function restore_autoload_backup(array $backup)
    {
        global $wpdb;
        $count = 0;
        $batch_size = 500;
        $values = [];
        $placeholders = [];
        $batch_count = 0;
        static $use_row_alias = null;
        if ($use_row_alias === null) {
            $use_row_alias = version_compare(
                OPTISTATE_Utils::get_mysql_version(),
                '8.0.19',
                '>='
            );
        }
        $on_dup = $use_row_alias
            ? ' AS _r ON DUPLICATE KEY UPDATE option_value = _r.option_value, autoload = _r.autoload'
            : ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)';

        foreach ($backup as $option_name => $data) {
            if (!isset($data["value"], $data["autoload"])) {
                continue;
            }
            $values[] = $option_name;
            $values[] = $data["value"];
            $values[] = $data["autoload"];
            $placeholders[] = "(%s, %s, %s)";
            $batch_count++;

            if (count($placeholders) >= $batch_size) {
                $sql =
                    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " .
                    implode(", ", $placeholders) .
                    $on_dup;
                $result = $wpdb->query($wpdb->prepare($sql, ...$values));
                if ($result === false) {
                    OPTISTATE_Utils::log_critical_error(
                        "Autoload backup restore batch failed",
                        ["error" => $wpdb->last_error]
                    );
                    return false;
                }
                $count += $batch_count;
                $batch_count = 0;
                $values = [];
                $placeholders = [];
            }
        }

        if (!empty($placeholders)) {
            $sql =
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " .
                implode(", ", $placeholders) .
                $on_dup;
            $result = $wpdb->query($wpdb->prepare($sql, ...$values));
            if ($result === false) {
                OPTISTATE_Utils::log_critical_error(
                    "Autoload backup restore batch failed",
                    ["error" => $wpdb->last_error]
                );
                return false;
            }
            $count += $batch_count;
        }

        return $count;
    }

    private function delete_autoload_backup(): void
    {
        $this->main_plugin->delete_store_data(self::AUTOLOAD_BACKUP_KEY);
    }

    public function get_autoload_backup(): ?array
    {
        $data = $this->main_plugin->get_store_data(
            self::AUTOLOAD_BACKUP_KEY,
            null
        );
        return is_array($data) ? $data : null;
    }

    public function ajax_optimize_autoload(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("heavy_op", 20)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        wp_raise_memory_limit("admin");
        OPTISTATE_Utils::safe_set_time_limit(600);

        $start_time = time();
        $max_exec = (int) ini_get("max_execution_time");
        if ($max_exec <= 0) {
            $max_exec = 600;
        }
        $timeout_guard = max(5, $max_exec - 5);

        global $wpdb;
        $results = [
            "optimized" => 0,
            "skipped" => 0,
            "total_found" => 0,
            "total_size_reduced" => 0,
            "errors" => [],
            "details" => [],
        ];
        $backup_data = [];

        $use_cli = defined("WP_CLI") && WP_CLI && php_sapi_name() === "cli";
        self::compile_patterns();

        try {
            $total_autoload = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'"
            );
            $results["total_found"] = (int) $total_autoload;

            if ($results["total_found"] === 0) {
                $this->main_plugin->log_entry(
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

            $fetch_batch_size = $use_cli
                ? self::BATCH_SIZE_FETCH_CLI
                : self::BATCH_SIZE_FETCH_STANDARD;
            $update_batch_size = self::BATCH_SIZE_UPDATE;

            $sys_limit = ini_get("memory_limit");
            if (empty($sys_limit) || $sys_limit === "-1") {
                $memory_safety_limit = $use_cli
                    ? 512 * 1024 * 1024
                    : 256 * 1024 * 1024;
            } else {
                $memory_safety_limit =
                    (int) (wp_convert_hr_to_bytes($sys_limit) * 0.9);
            }

            $last_seen_id = 0;
            $update_buffer = [];
            $options_buffer = [];
            $optimized_options = [];

            while (true) {
                if (time() - $start_time >= $timeout_guard) {
                    break;
                }

                $chunk = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT option_id, option_name, option_value, autoload, LENGTH(option_value) AS option_size FROM {$wpdb->options} WHERE autoload = 'yes' AND option_id > %d ORDER BY option_id ASC LIMIT %d",
                        $last_seen_id,
                        $fetch_batch_size
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
                        $update_buffer[] = $name;
                        $options_buffer[] = $option;
                        if (count($update_buffer) >= $update_batch_size) {
                            $optimized_options = $this->process_autoload_update_batch(
                                $options_buffer,
                                $results,
                                $optimized_options,
                                $backup_data
                            );
                            $update_buffer = [];
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
                        if ($reason) {
                            $results["details"][] = [
                                "option" => $name,
                                "size" => size_format($size, 2),
                                "status" => "skipped",
                                "reason" => $reason,
                            ];
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

            if (!empty($update_buffer)) {
                $optimized_options = $this->process_autoload_update_batch(
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

            if (!empty($backup_data)) {
                $this->save_autoload_backup($backup_data);
            }

            if ($results["optimized"] > 0) {
                $this->main_plugin->clear_stats_cache();
            }

            $this->main_plugin->log_entry(
                sprintf(
                    "⚙️ " .
                        __(
                            "Optimized Autoloaded Options (%s) by {username}",
                            "optistate"
                        ),
                    number_format_i18n($results["optimized"])
                )
            );

            OPTISTATE_Utils::send_json_success($results);
        } catch (Throwable $e) {
            $error_message = "Optimization failed: " . $e->getMessage();
            $results["errors"][] = $error_message;

            $this->main_plugin->log_entry(
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

            OPTISTATE_Utils::send_json_error([
                "message" => $error_message,
            ], 500, $results);
        }
    }

    private function process_autoload_update_batch(
        array $options_data,
        array &$results,
        array $optimized_options,
        array &$backup_data
    ): array {
        if (empty($options_data)) {
            return $optimized_options;
        }

        global $wpdb;
        $option_names = array_column($options_data, "option_name");

        foreach ($options_data as $row) {
            $backup_data[$row["option_name"]] = [
                "value" => $row["option_value"],
                "autoload" => $row["autoload"],
            ];
        }

        $size_by_name = [];
        foreach ($options_data as $row) {
            $size_by_name[$row["option_name"]] =
                (int) ($row["option_size"] ?? 0);
        }

        $placeholders = implode(",", array_fill(0, count($option_names), "%s"));
        $query = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ($placeholders)",
            ...$option_names
        );
        $rows_affected = $wpdb->query($query);

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
            $results["details"][] = [
                "option" => $option_name,
                "size" => size_format($size, 2),
                "status" => "optimized",
            ];
        }

        return $optimized_options;
    }

    public function ajax_analyze_indexes(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("analyze_indexes", 10)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        try {
            $cache_key = "optistate_index_analysis_" . md5(DB_NAME);
            $force_refresh =
                isset($_POST["force_refresh"]) &&
                $_POST["force_refresh"] === "true";

            $result_data = OPTISTATE_Utils::get_or_set_transient(
                $cache_key,
                function () {
                    return OPTISTATE_Utils::with_stats_expiry_disabled(
                        function () {
                            global $wpdb;
                            $recommendations = [];
                            $redundant_indexes = [];

                            $wc_lookup_table =
                                $wpdb->prefix . "wc_order_product_lookup";
                            $raw_targets = [
                                $wpdb->options => [
                                    [
                                        ["autoload"],
                                        "autoload",
                                        __(
                                            'Speeds up your site\'s initial load time by organizing auto-loaded settings.',
                                            "optistate"
                                        ),
                                    ],
                                    [
                                        ["autoload", "option_name"],
                                        "idx_autoload_option",
                                        __(
                                            "Allows for much faster retrieval of specific settings without searching the entire table.",
                                            "optistate"
                                        ),
                                    ],
                                ],
                                $wc_lookup_table => [
                                    [
                                        ["product_id", "date_created"],
                                        "idx_wc_prod_lookup",
                                        __(
                                            "Speeds up sales reporting and product purchase history lookups.",
                                            "optistate"
                                        ),
                                    ],
                                ],
                            ];

                            $raw_targets = apply_filters(
                                "optistate_index_targets",
                                $raw_targets
                            );

                            $targets = [];
                            if (is_array($raw_targets)) {
                                foreach ($raw_targets as $table_name => $data) {
                                    $targets[strtolower($table_name)] = $data;
                                }
                            }

                            $db_tables = OPTISTATE_Utils::get_all_tables();
                            if (empty($db_tables)) {
                                throw new Exception(
                                    __(
                                        "Unable to list database tables.",
                                        "optistate"
                                    )
                                );
                            }

                            $db_tables_map = array_flip($db_tables);
                            $hint = '';
                            if (version_compare(OPTISTATE_Utils::get_mysql_version(), '8.0.11', '>=')) {
                                $hint = '/*+ MAX_EXECUTION_TIME(30000) */ ';
                            }

                            $query = $wpdb->prepare(
                                "SELECT {$hint}TABLE_NAME, INDEX_NAME as Key_name, SEQ_IN_INDEX as Seq_in_index, COLUMN_NAME as Column_name, NON_UNIQUE as Non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s",
                                DB_NAME
                            );
                            $all_indexes_raw = $wpdb->get_results(
                                $query,
                                ARRAY_A
                            );

                            $grouped_indexes = [];
                            if (!empty($all_indexes_raw)) {
                                foreach ($all_indexes_raw as $row) {
                                    if (
                                        !isset(
                                            $db_tables_map[$row["TABLE_NAME"]]
                                        )
                                    ) {
                                        continue;
                                    }
                                    $grouped_indexes[
                                        $row["TABLE_NAME"]
                                    ][] = $row;
                                }
                            }

                            foreach ($db_tables as $table) {
                                $raw_indexes = isset($grouped_indexes[$table])
                                    ? $grouped_indexes[$table]
                                    : [];
                                $indexes_info = [];
                                foreach ($raw_indexes as $idx) {
                                    $key_name = $idx["Key_name"];
                                    $seq = $idx["Seq_in_index"];
                                    $col = $idx["Column_name"];
                                    if (!isset($indexes_info[$key_name])) {
                                        $indexes_info[$key_name] = [
                                            "columns" => [],
                                            "non_unique" => $idx["Non_unique"],
                                        ];
                                    }
                                    $indexes_info[$key_name]["columns"][
                                        $seq
                                    ] = $col;
                                }

                                $prepared = [];
                                foreach (
                                    $indexes_info
                                    as $k_prep => $info_prep
                                ) {
                                    if ($k_prep === "PRIMARY") {
                                        continue;
                                    }
                                    ksort($info_prep["columns"]);
                                    $prepared[$k_prep] = [
                                        "cols" => array_values(
                                            $info_prep["columns"]
                                        ),
                                        "non_unique" =>
                                            $info_prep["non_unique"],
                                    ];
                                }

                                $marked_redundant = [];
                                $prepared_keys = array_keys($prepared);
                                $n_keys = count($prepared_keys);
                                for ($i = 0; $i < $n_keys; $i++) {
                                    $key_a = $prepared_keys[$i];
                                    $cols_a = $prepared[$key_a]["cols"];
                                    $a_unique =
                                        $prepared[$key_a]["non_unique"] == 0;
                                    for ($j = $i + 1; $j < $n_keys; $j++) {
                                        $key_b = $prepared_keys[$j];
                                        $cols_b = $prepared[$key_b]["cols"];
                                        $b_unique =
                                            $prepared[$key_b]["non_unique"] ==
                                            0;
                                        $len_a = count($cols_a);
                                        $len_b = count($cols_b);

                                        if (
                                            $len_a <= $len_b &&
                                            $cols_a ===
                                                array_slice($cols_b, 0, $len_a)
                                        ) {
                                            if (
                                                !($a_unique && !$b_unique) &&
                                                !isset(
                                                    $marked_redundant[$key_a]
                                                )
                                            ) {
                                                $marked_redundant[
                                                    $key_a
                                                ] = true;
                                                $redundant_indexes[] = [
                                                    "type" => "redundant",
                                                    "table" => $table,
                                                    "column" => implode(
                                                        ", ",
                                                        $cols_a
                                                    ),
                                                    "index_name" => $key_a,
                                                    "reason" => sprintf(
                                                        __(
                                                            'Redundant: Covered by index "%s" (%s).',
                                                            "optistate"
                                                        ),
                                                        $key_b,
                                                        implode(", ", $cols_b)
                                                    ),
                                                    "action_type" => "drop",
                                                ];
                                                continue;
                                            }
                                        }
                                        if (
                                            $len_b < $len_a &&
                                            $cols_b ===
                                                array_slice($cols_a, 0, $len_b)
                                        ) {
                                            if (
                                                !($b_unique && !$a_unique) &&
                                                !isset(
                                                    $marked_redundant[$key_b]
                                                )
                                            ) {
                                                $marked_redundant[
                                                    $key_b
                                                ] = true;
                                                $redundant_indexes[] = [
                                                    "type" => "redundant",
                                                    "table" => $table,
                                                    "column" => implode(
                                                        ", ",
                                                        $cols_b
                                                    ),
                                                    "index_name" => $key_b,
                                                    "reason" => sprintf(
                                                        __(
                                                            'Redundant: Covered by index "%s" (%s).',
                                                            "optistate"
                                                        ),
                                                        $key_a,
                                                        implode(", ", $cols_a)
                                                    ),
                                                    "action_type" => "drop",
                                                ];
                                            }
                                        }
                                    }
                                }

                                $lower_table = strtolower($table);
                                if (isset($targets[$lower_table])) {
                                    $suggested_indexes = $targets[$lower_table];
                                    $table_columns = $wpdb->get_col(
                                        "SHOW COLUMNS FROM " . OPTISTATE_Utils::escape_identifier($table)
                                    );
                                    $table_columns_map = array_flip(
                                        $table_columns
                                    );

                                    foreach ($suggested_indexes as $target) {
                                        list(
                                            $req_cols,
                                            $suggested_name,
                                            $reason,
                                        ) = $target;
                                        $all_cols_exist = true;
                                        $clean_req_cols = [];
                                        foreach ($req_cols as $raw_col) {
                                            $col_name = preg_replace(
                                                '/\(\d+\)$/',
                                                "",
                                                $raw_col
                                            );
                                            if (
                                                !isset(
                                                    $table_columns_map[
                                                        $col_name
                                                    ]
                                                )
                                            ) {
                                                $all_cols_exist = false;
                                                break;
                                            }
                                            $clean_req_cols[] = $col_name;
                                        }
                                        if (!$all_cols_exist) {
                                            continue;
                                        }

                                        $is_covered = false;
                                        foreach (
                                            $indexes_info
                                            as $key_existing => $info_existing
                                        ) {
                                            ksort($info_existing["columns"]);
                                            $existing_cols = array_values(
                                                $info_existing["columns"]
                                            );
                                            if (
                                                count($existing_cols) >=
                                                count($clean_req_cols)
                                            ) {
                                                $slice = array_slice(
                                                    $existing_cols,
                                                    0,
                                                    count($clean_req_cols)
                                                );
                                                if (
                                                    $slice === $clean_req_cols
                                                ) {
                                                    $is_covered = true;
                                                    break;
                                                }
                                            }
                                        }

                                        if (!$is_covered) {
                                            $recommendations[] = [
                                                "type" => "missing",
                                                "table" => $table,
                                                "column" => implode(
                                                    ", ",
                                                    $req_cols
                                                ),
                                                "raw_columns" => implode(
                                                    ",",
                                                    $req_cols
                                                ),
                                                "index_name" => $suggested_name,
                                                "reason" =>
                                                    "<strong>" .
                                                    __(
                                                        "Missing:",
                                                        "optistate"
                                                    ) .
                                                    "</strong> " .
                                                    $reason,
                                                "status" => "missing",
                                                "action_type" => "add",
                                            ];
                                        }
                                    }
                                }
                            }

                            return [
                                "recommendations" => array_merge(
                                    $recommendations,
                                    $redundant_indexes
                                ),
                            ];
                        }
                    );
                },
                5 * MINUTE_IN_SECONDS,
                $force_refresh
            );

            OPTISTATE_Utils::send_json_success($result_data);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Index analysis failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred while analyzing indexes.",
                    "optistate"
                ),
            ]);
        }
    }

    public function ajax_manage_index(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        try {
            $this->process_store->ensure_table_exists();

            $table = isset($_POST["table"])
                ? sanitize_text_field(wp_unslash($_POST["table"]))
                : "";
            $action_type = isset($_POST["action_type"])
                ? sanitize_text_field(wp_unslash($_POST["action_type"]))
                : "add";
            $index_name = isset($_POST["index_name"])
                ? sanitize_text_field(wp_unslash($_POST["index_name"]))
                : "";
            $raw_columns = isset($_POST["column"])
                ? sanitize_text_field(wp_unslash($_POST["column"]))
                : "";

            if (empty($table) || empty($index_name)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Invalid parameters provided.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $escaped_table = OPTISTATE_Utils::validate_table_name($table);
            if (!$escaped_table) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Invalid or unsafe table name.",
                        "optistate"
                    ),
                ]);
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $index_name)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Invalid index name format.", "optistate"),
                ]);
                return;
            }

            if (strtoupper($index_name) === "PRIMARY") {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "The PRIMARY key index is protected and cannot be modified through this tool.",
                        "optistate"
                    ),
                ]);
                return;
            }

            global $wpdb;

            if ($action_type === "add") {
                if (empty($raw_columns)) {
                    OPTISTATE_Utils::send_json_error([
                        "message" => __(
                            "Columns required for index creation.",
                            "optistate"
                        ),
                    ]);
                    return;
                }
                $columns_dirty = explode(",", $raw_columns);
                $columns_clean = [];
                foreach ($columns_dirty as $col_def) {
                    $col_def = trim($col_def);
                    if (!$this->validate_column_name($col_def, $table)) {
                        OPTISTATE_Utils::send_json_error([
                            "message" => sprintf(
                                __(
                                    'Invalid or non-existent column: "%s"',
                                    "optistate"
                                ),
                                esc_html($col_def)
                            ),
                        ]);
                        return;
                    }
                    $columns_clean[] = $col_def;
                }

                $index_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SHOW INDEX FROM $escaped_table WHERE Key_name = %s",
                        $index_name
                    )
                );
                if ($index_exists) {
                    OPTISTATE_Utils::send_json_error([
                        "message" => __(
                            "Index name already exists.",
                            "optistate"
                        ),
                    ]);
                    return;
                }
            } elseif ($action_type === "drop") {
                $index_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SHOW INDEX FROM $escaped_table WHERE Key_name = %s",
                        $index_name
                    )
                );
                if (!$index_exists) {
                    OPTISTATE_Utils::send_json_error([
                        "message" => __(
                            "Index does not exist, cannot drop.",
                            "optistate"
                        ),
                    ]);
                    return;
                }
            }

            if ($action_type === "add") {
                $space_check = $this->check_disk_space_for_index($table);
                if (!$space_check["success"]) {
                    OPTISTATE_Utils::send_json_error([
                        "message" => $space_check["message"],
                    ]);
                    return;
                }
            }

            try {
                $task_id = "idx_" . bin2hex(random_bytes(8));
            } catch (\Throwable $e) {
                $task_id = "idx_" . substr(md5(uniqid(wp_rand(), true)), 0, 16);
            }

            $task_data = [
                "status" => "pending",
                "type" => $action_type,
                "table" => $table,
                "escaped_table" => $escaped_table,
                "columns" => $action_type === "add" ? $columns_clean : [],
                "index_name" => $index_name,
                "started" => time(),
                "user_id" => get_current_user_id(),
            ];

            $this->process_store->set(
                $task_id,
                $task_data,
                30 * MINUTE_IN_SECONDS
            );

            wp_schedule_single_event(time(), "optistate_run_index_chunk", [
                $task_id,
            ]);

            $msg =
                $action_type === "add"
                    ? __("Index creation started in background.", "optistate")
                    : __("Index removal started in background.", "optistate");

            OPTISTATE_Utils::send_json_success([
                "status" => "processing",
                "task_id" => $task_id,
                "message" => $msg,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Manage index failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred while managing the index.",
                    "optistate"
                ),
            ]);
        }
    }

    public function run_index_chunk_worker(string $task_id): void
    {
        OPTISTATE_Utils::safe_set_time_limit(600);

        try {
            $task = $this->process_store->get($task_id);
            if (!$task || !in_array($task["status"], ["pending", "running"])) {
                return;
            }

            global $wpdb;
            $task["status"] = "running";
            $this->process_store->set($task_id, $task, 30 * MINUTE_IN_SECONDS);

            $table = $task["escaped_table"];
            $table_raw = $task["table"];
            $index_name = $task["index_name"];
            $type = isset($task["type"]) ? $task["type"] : "add";

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $index_name)) {
                $this->_mark_index_task_error(
                    $task_id,
                    $task,
                    __("Invalid index name format.", "optistate")
                );
                return;
            }

            if ($type === "add") {
                $columns = $task["columns"];
                $safe_columns = [];
                foreach ($columns as $col) {
                    if (
                        !preg_match(
                            '/^([a-zA-Z0-9_]+)(\(\d+\))?$/',
                            $col,
                            $matches
                        )
                    ) {
                        $this->_mark_index_task_error(
                            $task_id,
                            $task,
                            sprintf(
                                __("Invalid column format: %s", "optistate"),
                                $col
                            )
                        );
                        return;
                    }
                    $col_name = $matches[1];
                    $prefix_length = isset($matches[2]) ? $matches[2] : "";
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $col_name)) {
                        $this->_mark_index_task_error(
                            $task_id,
                            $task,
                            sprintf(
                                __("Invalid column name: %s", "optistate"),
                                $col_name
                            )
                        );
                        return;
                    }
                    if (!$this->validate_column_name($col, $table_raw)) {
                        $this->_mark_index_task_error(
                            $task_id,
                            $task,
                            sprintf(
                                __("Column does not exist: %s", "optistate"),
                                $col_name
                            )
                        );
                        return;
                    }
                    $safe_columns[] =
                        "`" . esc_sql($col_name) . "`" . $prefix_length;
                }
                $escaped_index_name = "`" . esc_sql($index_name) . "`";
                $sql =
                    "ALTER TABLE $table ADD INDEX $escaped_index_name (" .
                    implode(", ", $safe_columns) .
                    ")";
            } else {
                $escaped_index_name = "`" . esc_sql($index_name) . "`";
                $sql = "ALTER TABLE $table DROP INDEX $escaped_index_name";
            }

            $max_retries = 3;
            $retry_delay = 1;
            $success = false;
            $last_error = null;

            for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
                try {
                    $wpdb->query("SET SESSION lock_wait_timeout = 60");
                    $wpdb->query("SET SESSION innodb_lock_wait_timeout = 60");

                    $suppress = $wpdb->suppress_errors(true);
                    $result = $wpdb->query(
                        $sql . " , ALGORITHM=INPLACE, LOCK=NONE"
                    );
                    $wpdb->suppress_errors($suppress);

                    if ($result === false) {
                        $result = $wpdb->query($sql);
                    }

                    if ($result !== false) {
                        $success = true;
                        break;
                    }

                    $error = $wpdb->last_error;
                    $last_error = $error;

                    if (
                        stripos($error, "Lock wait timeout") !== false ||
                        stripos($error, "deadlock") !== false
                    ) {
                        if ($attempt < $max_retries) {
                            sleep($retry_delay);
                            $retry_delay *= 2;
                            continue;
                        }
                    }
                    break;
                } catch (Throwable $e) {
                    $last_error = $e->getMessage();
                    if (
                        $attempt < $max_retries &&
                        (stripos($last_error, "Lock wait") !== false ||
                            stripos($last_error, "deadlock") !== false)
                    ) {
                        sleep($retry_delay);
                        $retry_delay *= 2;
                        continue;
                    }
                    break;
                }
            }

            if (!$success) {
                $error_detail =
                    $last_error ?: "Unknown error during ALTER TABLE";
                $this->_mark_index_task_error($task_id, $task, $error_detail);
                $this->main_plugin->log_entry(
                    sprintf(
                        __("Failed to modify index %s on %s", "optistate"),
                        $index_name,
                        $table_raw
                    ),
                    "error",
                    "",
                    ["details" => $error_detail]
                );
                OPTISTATE_Utils::log_critical_error(
                    "Index modification failed after retries: " . $error_detail,
                    [
                        "table" => $table_raw,
                        "index" => $index_name,
                        "action" => $type,
                    ]
                );
                return;
            }
            if ($this->verify_index_operation($table, $index_name, $type)) {
                $task["status"] = "done";
                $this->process_store->set(
                    $task_id,
                    $task,
                    30 * MINUTE_IN_SECONDS
                );

                $cache_key = "optistate_index_analysis_" . md5(DB_NAME);
                delete_transient($cache_key);
                $this->main_plugin->clear_stats_cache();
                OPTISTATE_Utils::invalidate_table_cache();

                $user_id = isset($task["user_id"])
                    ? absint($task["user_id"])
                    : 0;
                $user = get_userdata($user_id);
                $username = $user ? $user->display_name : "System";
                $action_label =
                    $type === "add"
                        ? __("Added index", "optistate")
                        : __("Removed index", "optistate");
                $log_message = sprintf(
                    "🔢 %s %s on %s by %s",
                    $action_label,
                    $index_name,
                    $table,
                    $username
                );
                $this->main_plugin->log_entry($log_message);
            } else {
                $error_detail =
                    $wpdb->last_error ?:
                    "Verification failed - schema did not change.";
                $this->_mark_index_task_error($task_id, $task, $error_detail);
                $this->main_plugin->log_entry(
                    sprintf(
                        __("Failed to modify index %s on %s", "optistate"),
                        $index_name,
                        $table_raw
                    ),
                    "error",
                    "",
                    ["details" => $error_detail]
                );
                OPTISTATE_Utils::log_critical_error(
                    "Index modification verification failed: " . $error_detail,
                    [
                        "table" => $table_raw,
                        "index" => $index_name,
                        "action" => $type,
                    ]
                );
            }
        } catch (Throwable $e) {
            $this->_mark_index_task_error(
                $task_id,
                $task ?? ["table" => "unknown"],
                "Worker failed: " . $e->getMessage()
            );
            OPTISTATE_Utils::log_critical_error(
                "Index chunk worker failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
        }
    }

    private function verify_index_operation(
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
        return $type === 'add' ? !empty($check) : empty($check);
    }

    public function ajax_check_index_status(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        try {
            $task_id = isset($_POST["task_id"])
                ? sanitize_text_field(wp_unslash($_POST["task_id"]))
                : "";
            if (empty($task_id)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Invalid Task ID.", "optistate"),
                ]);
                return;
            }

            $task = $this->process_store->get($task_id);
            if (!$task) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Task expired or not found.", "optistate"),
                ]);
                return;
            }

            if ($task["status"] === "done") {
                OPTISTATE_Utils::send_json_success(["status" => "done"]);
            } elseif ($task["status"] === "error") {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Error: ", "optistate") . $task["message"],
                ]);
            } else {
                OPTISTATE_Utils::send_json_success(["status" => "processing"]);
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Check index status failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __("An unexpected error occurred.", "optistate"),
            ]);
        }
    }

    public function ajax_scan_integrity(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("scan_integrity", 10)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
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
                if (!self::is_valid_integrity_rule($rule)) {
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

            $hint = '';
            if (version_compare(OPTISTATE_Utils::get_mysql_version(), '8.0.11', '>=')) {
                $hint = '/*+ MAX_EXECUTION_TIME(30000) */ ';
            }

            $union_sql =
                "SELECT {$hint}rule_type, cnt FROM (" .
                implode(" UNION ALL ", $count_subqueries) .
                ") AS counts";
            $counts = $wpdb->get_results($union_sql, OBJECT_K);

            if ($wpdb->last_error) {
                $this->fallback_integrity_scan(
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
                if (!self::is_valid_integrity_rule($rule)) {
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

    private function fallback_integrity_scan(
        array $rules,
        float $start_time,
        int $max_exec,
        array &$results,
        int &$total_issues
    ): void {
        global $wpdb;

        foreach ($rules as $type => $rule) {
            if (!self::is_valid_integrity_rule($rule)) {
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

    public function ajax_fix_integrity(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        try {
            $type = isset($_POST["type"]) ? sanitize_key($_POST["type"]) : "";
            $rules = self::get_integrity_rules();

            if (!isset($rules[$type])) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Invalid rule type.", "optistate"),
                ]);
                return;
            }

            global $wpdb;
            $rule = $rules[$type];
            $child_table = $wpdb->prefix . $rule["child_table"];
            $parent_table = $wpdb->prefix . $rule["parent_table"];
            $extra_where = isset($rule["extra_where"])
                ? $rule["extra_where"]
                : "";
            $limit = 2000;
            $deleted_count = 0;
            $transaction_started = false;
            $affected_term_ids = [];

            try {
                if ($type === "term_relationships") {
                    $sql_fetch = "SELECT tr.object_id, tr.term_taxonomy_id FROM $child_table tr LEFT JOIN $parent_table tt ON tr.{$rule["child_key"]} = tt.{$rule["parent_key"]} WHERE tt.{$rule["parent_key"]} IS NULL LIMIT $limit";
                    $rows = $wpdb->get_results($sql_fetch);
                    if ($rows) {
                        $affected_term_ids = $wpdb->get_col(
                            "SELECT DISTINCT tt.term_id FROM $parent_table tt INNER JOIN $child_table tr ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                        );
                        $affected_term_ids = array_map(
                            "absint",
                            array_unique($affected_term_ids)
                        );

                        $values = [];
                        foreach ($rows as $row) {
                            $values[] = sprintf(
                                "(%d, %d)",
                                (int) $row->object_id,
                                (int) $row->term_taxonomy_id
                            );
                        }

                        $wpdb->query("START TRANSACTION");
                        $transaction_started = true;

                        $deleted = $wpdb->query(
                            "DELETE FROM $child_table WHERE (object_id, term_taxonomy_id) IN (" .
                                implode(",", $values) .
                                ")"
                        );
                        if ($deleted !== false) {
                            $deleted_count = $deleted;
                        }
                    }
                } else {
                    $pk = $rule["pk"];
                    $ids_sql = "SELECT c.$pk FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where LIMIT $limit";
                    $ids = $wpdb->get_col($ids_sql);
                    if (!empty($ids)) {
                        $ids_string = implode(",", array_map("absint", $ids));
                        $wpdb->query("START TRANSACTION");
                        $transaction_started = true;

                        if ($type === "comments_on_deleted") {
                            $wpdb->query(
                                "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($ids_string)"
                            );
                        } elseif ($type === "child_posts") {
                            $wpdb->query(
                                "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids_string)"
                            );
                            $wpdb->query(
                                "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($ids_string)"
                            );
                        }

                        $deleted = $wpdb->query(
                            "DELETE FROM $child_table WHERE $pk IN ($ids_string)"
                        );
                        if ($deleted !== false) {
                            $deleted_count = $deleted;
                        }
                    }
                }

                if ($transaction_started) {
                    $wpdb->query("COMMIT");
                }
            } catch (Throwable $e) {
                if ($transaction_started) {
                    try {
                        $wpdb->query("ROLLBACK");
                    } catch (Throwable $rollback_err) {
                        OPTISTATE_Utils::log_critical_error(
                            "Integrity fix rollback failed: " . $rollback_err->getMessage(),
                            ["original_error" => $e->getMessage()]
                        );
                    }
                }

                OPTISTATE_Utils::log_critical_error(
                    "Integrity fix failed: " . $e->getMessage(),
                    [
                        "type" => $type,
                        "child_table" => $child_table,
                        "parent_table" => $parent_table,
                    ]
                );

                OPTISTATE_Utils::send_json_error([
                    "message" => __("Database error during fix. Transaction rolled back.", "optistate"),
                ]);
                return;
            }

            $remaining_sql = "SELECT COUNT(c.{$rule["child_key"]}) FROM $child_table c LEFT JOIN $parent_table p ON c.{$rule["child_key"]} = p.{$rule["parent_key"]} WHERE p.{$rule["parent_key"]} IS NULL $extra_where";
            $remaining = (int) $wpdb->get_var($remaining_sql);

            if ($deleted_count > 0) {
                $this->main_plugin->clear_stats_cache();
                $this->main_plugin->log_entry(
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

                if ($remaining === 0 || $deleted_count > 100) {
                    if (
                        function_exists("clean_term_cache") &&
                        !empty($affected_term_ids)
                    ) {
                        clean_term_cache($affected_term_ids);
                        if (function_exists("clean_taxonomy_cache")) {
                            $affected_taxonomies = $wpdb->get_col(
                                "SELECT DISTINCT taxonomy FROM $parent_table LIMIT 20"
                            );
                            if (!empty($affected_taxonomies)) {
                                foreach ($affected_taxonomies as $tax) {
                                    if (!empty($tax) && taxonomy_exists($tax)) {
                                        clean_taxonomy_cache($tax);
                                    }
                                }
                            }
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

    private function validate_column_name(
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
                ? array_map('strtolower', $raw)
                : [];
        }

        return in_array(strtolower($clean_column), $column_cache[$cache_key], true);
    }

    private function check_disk_space_for_index(string $table_name): array
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
        $safety_buffer = 100 * 1024 * 1024;

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
    private static function is_valid_integrity_rule(array $rule): bool
    {
        $id_re = '/^[a-zA-Z0-9_]+$/';
        return preg_match($id_re, $rule['child_table'] ?? '') === 1
            && preg_match($id_re, $rule['parent_table'] ?? '') === 1
            && preg_match($id_re, $rule['child_key'] ?? '') === 1
            && preg_match($id_re, $rule['parent_key'] ?? '') === 1
            && preg_match($id_re, $rule['context_col'] ?? '') === 1;
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
                "label" => __("Orphaned Post Children & Revisions (No Parent)", "optistate"),
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

    private function _mark_index_task_error(
        string $task_id,
        array $task,
        string $message
    ): void {
        $task["status"] = "error";
        $task["message"] = $message;
        $this->process_store->set($task_id, $task, 30 * MINUTE_IN_SECONDS);
    }

    public function ajax_delete_table(): void
    {
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            OPTISTATE_Utils::send_json_error([
                "message" => __("Invalid request method.", "optistate"),
            ], 400);
            return;
        }

        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("delete_table", 5)) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        try {
            $table_name = isset($_POST["table_name"])
                ? sanitize_text_field(wp_unslash($_POST["table_name"]))
                : "";

            if (empty($table_name)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Invalid table name.", "optistate"),
                ]);
                return;
            }

            $escaped_table_name = OPTISTATE_Utils::validate_table_name(
                $table_name
            );
            if ($escaped_table_name === false) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Security check failed: Invalid or non-existent table name.",
                        "optistate"
                    ),
                ], 400);
                return;
            }

            global $wpdb;
            $core_tables = [
                $wpdb->prefix . "posts",
                $wpdb->prefix . "users",
                $wpdb->prefix . "options",
                $wpdb->prefix . "comments",
                $wpdb->prefix . "links",
                $wpdb->prefix . "terms",
                $wpdb->prefix . "term_taxonomy",
                $wpdb->prefix . "term_relationships",
                $wpdb->prefix . "postmeta",
                $wpdb->prefix . "commentmeta",
                $wpdb->prefix . "usermeta",
                $wpdb->prefix . "termmeta",
            ];
            $core_tables = array_merge(
                $core_tables,
                OPTISTATE_Utils::get_optistate_core_excluded_tables()
            );
            $core_tables_lower = array_map("strtolower", $core_tables);

            if (in_array(strtolower($table_name), $core_tables_lower, true)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Protected: Cannot delete WordPress core tables or plugin critical data.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $status = OPTISTATE_Utils::get_table_status($table_name);
            $force_delete = isset($_POST["force"]) && $_POST["force"] === "1";
            $update_time_raw = isset($status["UPDATE_TIME"])
                ? $status["UPDATE_TIME"]
                : null;
            $update_time =
                $update_time_raw && $update_time_raw !== "0000-00-00 00:00:00"
                    ? strtotime($update_time_raw)
                    : 0;

            if (!$force_delete && !$update_time) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Protected: Recent activity for this table cannot be verified. Refusing to delete without an explicit override.",
                        "optistate"
                    ),
                ]);
                return;
            }

            if ($update_time && time() - $update_time < 2592000) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Protected: This table was accessed within the last 30 days and cannot be deleted.",
                        "optistate"
                    ),
                ]);
                return;
            }

            if (self::$plugin_map_cache_for_deletion === null) {
                self::$plugin_map_cache_for_deletion = $this->main_plugin->legacy_scanner->get_legacy_plugin_map();
            }

            $prefix_pattern =
                "/^" . preg_quote($wpdb->base_prefix, "/") . "(\d+_)?/";
            $base_name = preg_replace($prefix_pattern, "", $table_name);
            foreach (self::$plugin_map_cache_for_deletion as $prefix => $data) {
                if (strpos($base_name, $prefix) === 0) {
                    if (
                        $this->main_plugin->legacy_scanner->is_item_active_or_installed(
                            $data
                        )
                    ) {
                        OPTISTATE_Utils::send_json_error([
                            "message" => sprintf(
                                __(
                                    'Protected: This table belongs to the currently installed plugin/theme "%s".',
                                    "optistate"
                                ),
                                esc_html($data["name"])
                            ),
                        ]);
                        return;
                    }
                    break;
                }
            }

            $trash_key = $this->main_plugin->trash_manager->move_to_trash(
                "table",
                $table_name
            );

            if ($trash_key) {
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "🗑️ Moved table '%s' to trash by {username}",
                            "optistate"
                        ),
                        $table_name
                    )
                );
                $this->main_plugin->clear_stats_cache();
                OPTISTATE_Utils::invalidate_table_cache();

                OPTISTATE_Utils::send_json_success([
                    "message" => sprintf(
                        __(
                            "Table '%s' moved to trash.<br>It is restorable within 14 days in:<br>Cleanup tab → Legacy Plugin Data Scanner → Trash.",
                            "optistate"
                        ),
                        $table_name
                    ),
                ]);
            } else {
                $drop_error = "";
                $dropped = OPTISTATE_Utils::without_foreign_key_checks(
                    function () use ($wpdb, $escaped_table_name, &$drop_error) {
                        $suppress = $wpdb->suppress_errors(true);
                        $result = $wpdb->query(
                            "DROP TABLE $escaped_table_name"
                        );
                        $drop_error = $wpdb->last_error;
                        $wpdb->suppress_errors($suppress);
                        return $result;
                    }
                );

                if ($dropped === false) {
                    $error =
                        $drop_error !== "" ? $drop_error : $wpdb->last_error;
                    $is_fk_error =
                        strpos(
                            $error,
                            "referenced by a foreign key constraint"
                        ) !== false ||
                        strpos($error, "foreign key constraint fails") !==
                            false ||
                        strpos(
                            $error,
                            "Cannot delete or update a parent row"
                        ) !== false;

                    if ($is_fk_error) {
                        OPTISTATE_Utils::send_json_error([
                            "message" => __(
                                "Cannot delete table: Other tables depend on this data (Foreign Key Constraint).",
                                "optistate"
                            ),
                        ]);
                    } else {
                        OPTISTATE_Utils::send_json_error([
                            "message" =>
                                __(
                                    "Failed to delete table. Database Error: ",
                                    "optistate"
                                ) . $error,
                        ]);
                        OPTISTATE_Utils::log_critical_error(
                            "Table deletion failed: " . $error,
                            ["table" => $table_name]
                        );
                    }
                } else {
                    $this->main_plugin->log_entry(
                        sprintf(
                            __(
                                "🗑️ Deleted Database Table '%s' by {username}",
                                "optistate"
                            ),
                            $table_name
                        )
                    );
                    $this->main_plugin->clear_stats_cache();
                    OPTISTATE_Utils::invalidate_table_cache();

                    OPTISTATE_Utils::send_json_success([
                        "message" => sprintf(
                            __("Table '%s' successfully deleted.", "optistate"),
                            $table_name
                        ),
                    ]);
                }
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Delete table failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred while deleting the table.",
                    "optistate"
                ),
            ]);
        }
    }

    private function get_optimize_tables_transient_key(): string
    {
        $user_id = get_current_user_id();
        return "optistate_optimize_tables_state_" . ($user_id ? $user_id : 0);
    }

    public function ajax_optimize_tables(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        $is_continuation = (bool) get_transient(
            $this->get_optimize_tables_transient_key()
        );
        if (
            !$is_continuation &&
            !OPTISTATE_Utils::check_rate_limit("heavy_op", 20)
        ) {
            OPTISTATE_Utils::send_json_error([
                "message" => OPTISTATE_Utils::get_rate_limit_message(false),
            ], 429);
            return;
        }

        try {
            $result = $this->perform_optimize_tables(true);
            if (
                is_array($result) &&
                isset($result["status"]) &&
                $result["status"] === "running"
            ) {
                OPTISTATE_Utils::send_json_success($result);
                return;
            }

            $this->main_plugin->clear_stats_cache();
            OPTISTATE_Utils::invalidate_table_cache();

            $count = isset($result["optimized"])
                ? (int) $result["optimized"]
                : 0;

            $this->main_plugin->log_entry(
                sprintf(
                    "⚡ " .
                        __(
                            "Optimized All Tables (%s) by {username}",
                            "optistate"
                        ),
                    number_format_i18n($count)
                )
            );

            OPTISTATE_Utils::send_json_success($result);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Optimize tables failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            OPTISTATE_Utils::send_json_error([
                "message" => __(
                    "An unexpected error occurred during table optimization.",
                    "optistate"
                ),
            ]);
        }
    }

    public function perform_optimize_tables(bool $return_data = false)
    {
        wp_raise_memory_limit("admin");
        global $wpdb;

        $is_ajax = wp_doing_ajax();
        $is_cli = defined("WP_CLI") && WP_CLI;
        $is_cron = wp_doing_cron();

        $transient_key = $this->get_optimize_tables_transient_key();
        $start_time = microtime(true);
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(900);

        $allow_chunking = $is_ajax && !$is_cron && !$is_cli;
        $chunk_time_budget = 20;

        try {
            $state = get_transient($transient_key);

            if (!$state || !is_array($state) || $is_cron || $is_cli) {
                $tables = OPTISTATE_Utils::get_all_tables();
                $table_data = [];
                OPTISTATE_Utils::preload_all_table_statuses();

                foreach ($tables as $table_name) {
                    $status = OPTISTATE_Utils::get_table_status($table_name);
                    if ($status) {
                        $table_data[] = [
                            "TABLE_NAME" => $table_name,
                            "ENGINE" => $status["ENGINE"] ?? "",
                            "TABLE_TYPE" => $status["TABLE_TYPE"] ?? "BASE TABLE",
                            "TABLE_ROWS" => $status["TABLE_ROWS"] ?? 0,
                            "DATA_LENGTH" => $status["DATA_LENGTH"] ?? 0,
                            "INDEX_LENGTH" => $status["INDEX_LENGTH"] ?? 0,
                            "DATA_FREE" => $status["DATA_FREE"] ?? 0,
                        ];
                    }
                }

                if (empty($table_data)) {
                    $empty_results = [
                        "optimized" => 0,
                        "skipped" => 0,
                        "failed" => 0,
                        "reclaimed" => 0,
                        "details" => [],
                    ];
                    if ($return_data) {
                        return $empty_results;
                    }
                    return;
                }

                $state = [
                    "tables" => $table_data,
                    "total_count" => count($table_data),
                    "current_index" => 0,
                    "results" => [
                        "optimized" => 0,
                        "skipped" => 0,
                        "failed" => 0,
                        "reclaimed" => 0,
                        "details" => [],
                    ],
                ];
            }

            $tables = $state["tables"];
            $total_count = $state["total_count"];
            $current_index = $state["current_index"];
            $results = $state["results"];

            $wpdb->query("SET SESSION lock_wait_timeout = 5");

            while ($current_index < $total_count) {
                if (
                    $allow_chunking &&
                    microtime(true) - $start_time >= $chunk_time_budget
                ) {
                    break;
                }

                $table = $tables[$current_index];
                $table_name = $table["TABLE_NAME"];
                $initial_overhead = isset($table["DATA_FREE"])
                    ? intval($table["DATA_FREE"])
                    : 0;

                if (self::should_skip_table_optimization($table)) {
                    $results["skipped"]++;
                    $results["details"][] = [
                        "table" => $table_name,
                        "status" => "skipped",
                        "reason" => __(
                            "No overhead or not supported",
                            "optistate"
                        ),
                    ];
                } else {
                    $opt_result = $this->_optimize_table_enterprise(
                        $table_name,
                        $table["ENGINE"]
                    );
                    if ($opt_result["success"]) {
                        $results["optimized"]++;
                        $results["reclaimed"] += $initial_overhead;
                        $results["details"][] = [
                            "table" => $table_name,
                            "status" => "optimized",
                            "method" => $opt_result["method"],
                        ];
                    } else {
                        $results["failed"]++;
                        $results["details"][] = [
                            "table" => $table_name,
                            "status" => "failed",
                            "error" => $opt_result["error"],
                        ];
                    }
                }

                $usleep_time = $is_cron || $is_cli ? 50000 : 10000;
                usleep($usleep_time);

                $current_index++;
            }

            if ($current_index < $total_count) {
                $state["current_index"] = $current_index;
                $state["results"] = $results;
                $elapsed = microtime(true) - $start_time;
                $processed = $current_index;
                $remaining = $total_count - $processed;
                if ($processed > 0) {
                    $est_remaining = ($elapsed / $processed) * $remaining;
                    $expiry = max(HOUR_IN_SECONDS, (int) ($est_remaining + 300));
                } else {
                    $expiry = HOUR_IN_SECONDS;
                }
                set_transient($transient_key, $state, $expiry);

                $percentage =
                    $total_count > 0
                        ? (int) round(($current_index / $total_count) * 100)
                        : 100;
                $running_result = [
                    "status" => "running",
                    "percentage" => $percentage,
                ];
                if ($return_data) {
                    return $running_result;
                }
                return;
            }

            delete_transient($transient_key);
            $results["status"] = "done";

            if (function_exists("wp_cache_flush_runtime")) {
                wp_cache_flush_runtime();
            } else {
                $optimized_tables = array_filter($results["details"], function (
                    $detail
                ) {
                    return isset($detail["status"]) &&
                        $detail["status"] === "optimized";
                });
                foreach ($optimized_tables as $opt_table) {
                    $table_name = $opt_table["table"];
                    wp_cache_delete($table_name, "tables");
                    wp_cache_delete($table_name, "table_status");
                }
            }

            if ($return_data) {
                return $results;
            }
        } catch (Throwable $e) {
            $this->main_plugin->log_entry(
                "❌ Optimization Process Error: " . $e->getMessage(),
                "error"
            );
            OPTISTATE_Utils::log_critical_error(
                "Table optimization process error: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            return ["status" => "failed", "error" => $e->getMessage()];
        } finally {
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
        }
    }

    private function _optimize_table_enterprise(
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
                $wpdb->last_error = "";
                $result = $wpdb->query($attempt["sql"]);
                $err = $wpdb->last_error;
                $wpdb->suppress_errors($suppress);

                if ($result !== false && empty($err)) {
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
            $result = $wpdb->query("OPTIMIZE TABLE $escaped_table");
            if ($result !== false) {
                return [
                    "success" => true,
                    "method" => "Standard (MyISAM Locked)",
                    "error" => null,
                ];
            }
            if ($result === false) {
                OPTISTATE_Utils::log_critical_error(
                    "Table optimize failed (MyISAM)",
                    ["table" => $table_name, "error" => $wpdb->last_error]
                );
            }
            return [
                "success" => false,
                "error" => $wpdb->last_error,
                "method" => null,
            ];
        }

        $result = $wpdb->query("OPTIMIZE TABLE $escaped_table");
        if ($result === false) {
            OPTISTATE_Utils::log_critical_error(
                "Table optimize failed (generic)",
                [
                    "table" => $table_name,
                    "engine" => $engine,
                    "error" => $wpdb->last_error,
                ]
            );
        }
        return [
            "success" => $result !== false,
            "error" => $result === false ? $wpdb->last_error : null,
            "method" => "Standard",
        ];
    }

    private static function should_skip_table_optimization(array $table): bool
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

    private function build_plugin_prefix_map(): void
    {
        if ($this->plugin_prefix_map !== null) {
            return;
        }

        $plugin_map = $this->main_plugin->legacy_scanner->get_legacy_plugin_map();
        $this->plugin_prefix_map = [];
        foreach ($plugin_map as $prefix => $data) {
            $lower = strtolower(trim($prefix));
            $this->plugin_prefix_map[$lower] = $data;
            $clean = rtrim($lower, "_");
            if ($clean !== $lower) {
                $this->plugin_prefix_map[$clean] = $data;
            }
        }
    }

    private function _optimize_with_lock_retry(
        string $table_name,
        string $engine,
        int $max_retries = 3
    ): array {
        $retry_delay = 1;
        $result = ["success" => false, "error" => "Unknown", "method" => null];

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $result = $this->_optimize_table_enterprise($table_name, $engine);
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