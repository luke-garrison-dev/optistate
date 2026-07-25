<?php
if (!defined("ABSPATH")) {
    exit();
}

class OPTISTATE_Advanced_Tools
{
    private const BATCH_SIZE_CHECK = 20;
    private const BATCH_SIZE_REPAIR = 5;
    private const BATCH_SIZE_OPTIMIZE = 5;
    private const ABANDONED_THRESHOLD = 30 * DAY_IN_SECONDS;
    private OPTISTATE $main_plugin;
    private OPTISTATE_Process_Store $process_store;
    private ?array $plugin_prefix_map = null;
    private static $plugin_map_cache_for_deletion = null;
    public function __construct(
        OPTISTATE $main_plugin,
        OPTISTATE_Process_Store $process_store
    ) {
        $this->main_plugin = $main_plugin;
        $this->process_store = $process_store;
        $this->process_store->ensure_table_exists();

        add_action("wp_ajax_optistate_optimize_autoload", static function () use (
            $main_plugin
        ): void {
            OPTISTATE_Tools_Utilities::run_optimize_autoload($main_plugin);
        });
        add_action("wp_ajax_optistate_preview_autoload_options", static function () use (
            $main_plugin
        ): void {
            OPTISTATE_Tools_Utilities::run_preview_autoload_options($main_plugin);
        });
        add_action("wp_ajax_optistate_restore_autoload_backup", static function () use (
            $main_plugin
        ): void {
            OPTISTATE_Tools_Utilities::run_restore_autoload_backup($main_plugin);
        });
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
        add_action("wp_ajax_optistate_scan_integrity", static function () use (
            $main_plugin
        ): void {
            OPTISTATE_Tools_Utilities::run_scan_integrity($main_plugin);
        });
        add_action("wp_ajax_optistate_fix_integrity", static function () use (
            $main_plugin
        ): void {
            OPTISTATE_Tools_Utilities::run_fix_integrity($main_plugin);
        });
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
            OPTISTATE_Utils::send_rate_limit_error();
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
                    "message" => __(
                        "Failed to retrieve table information",
                        "optistate"
                    ),
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

            $optistate_processes_table = $wpdb->prefix . "optistate_processes";
            $optistate_metadata_table = $wpdb->prefix . "optistate_backup_metadata";
            $optistate_login_table =
                $wpdb->prefix . OPTISTATE_Login_Protection::TABLE_NAME;
            $optistate_core_table = $wpdb->prefix . "optistate_core_data";
            $optistate_trash_table = $wpdb->prefix . "optistate_trash";
            $trash_table_prefix = $wpdb->prefix . "trash_";
            $unknown_label = __("Unknown", "optistate");

            foreach ($tables as $table_name) {
                $status = OPTISTATE_Utils::get_table_status($table_name);
                if (!$status) {
                    continue;
                }
                $base_name = preg_replace($prefix_pattern, "", $table_name);
                $is_core = OPTISTATE_Utils::is_core_table($table_name);

                $is_optistate_processes =
                    $table_name === $optistate_processes_table;
                $is_optistate_metadata =
                    $table_name === $optistate_metadata_table;
                $is_optistate_login = $table_name === $optistate_login_table;
                $is_optistate_core = $table_name === $optistate_core_table;
                $is_optistate_trash = $table_name === $optistate_trash_table;
                $is_trash_table =
                    strpos($table_name, $trash_table_prefix) === 0;

                $is_optistate =
                    $is_optistate_processes ||
                    $is_optistate_metadata ||
                    $is_optistate_login ||
                    $is_optistate_core ||
                    $is_optistate_trash ||
                    $is_trash_table;
                $description = $is_core
                    ? ($core_table_definitions[$base_name] ?? __("WordPress Core Table", "optistate"))
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
                    : $unknown_label;
                $created_local_formatted = isset($status["CREATE_TIME"])
                    ? mysql2date($date_format, $status["CREATE_TIME"], true)
                    : $unknown_label;

                $is_abandoned = false;
                $abandoned_text = "";

                if (
                    !($is_core || $is_optistate) &&
                    isset($status["UPDATE_TIME"])
                ) {
                    $update_ts = strtotime($status["UPDATE_TIME"]);
                    if (
                        $update_ts &&
                        $now - $update_ts > self::ABANDONED_THRESHOLD
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

        if (!OPTISTATE_Tools_Utilities::require_post()) {
            return;
        }

        if (!OPTISTATE_Utils::check_rate_limit("heavy_op", 20)) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }

        try {
            $this->process_store->ensure_table_exists();

            $session_tracker_key = OPTISTATE_Tools_Utilities::get_analyze_session_key();
            $prev_session_key = $this->process_store->get($session_tracker_key);
            if (
                is_string($prev_session_key) &&
                OPTISTATE_Tools_Utilities::is_valid_analyze_key($prev_session_key)
            ) {
                $this->process_store->delete($prev_session_key);
            }
            $table_names = OPTISTATE_Tools_Utilities::get_base_tables();
            if (empty($table_names)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "No tables found in the database.",
                        "optistate"
                    ),
                ]);
                return;
            }
            $valid_table_names = array_values(array_filter($table_names, function ($name) {
                return preg_match('/^[a-zA-Z0-9_]+$/', $name);
            }));

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

            $transient_key = OPTISTATE_Tools_Utilities::ANALYZE_KEY_PREFIX . $unique_hash;
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
                "total_to_repair" => 0,
                "total_to_optimize" => 0,
            ];

            if (
                !OPTISTATE_Tools_Utilities::save_analyze_state(
                    $this->process_store,
                    $transient_key,
                    $state
                ) ||
                !$this->process_store->set(
                    $session_tracker_key,
                    $transient_key,
                    OPTISTATE_Tools_Utilities::ANALYZE_STATE_TTL
                )
            ) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Could not create the analysis session. Please try again.",
                        "optistate"
                    ),
                ]);
                return;
            }

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

        if (!OPTISTATE_Tools_Utilities::require_post()) {
            return;
        }

        $original_time_limit = (int) ini_get("max_execution_time");
        $disable_functions = ini_get("disable_functions");
        $is_disabled =
            !empty($disable_functions) &&
            in_array(
                "set_time_limit",
                array_map("trim", explode(",", strtolower($disable_functions))),
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
            if (!OPTISTATE_Tools_Utilities::is_valid_analyze_key($transient_key)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Invalid or missing session key.",
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

            OPTISTATE_Tools_Utilities::mark_db_lock_acquired($lock_name);
            OPTISTATE_Tools_Utilities::register_lock_release_on_shutdown($lock_name);
            $state = OPTISTATE_Tools_Utilities::load_analyze_state(
                $this->process_store,
                $transient_key
            );
            if ($state === null) {
                OPTISTATE_Tools_Utilities::release_db_lock($lock_name);
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Session expired. Please start over.",
                        "optistate"
                    ),
                ]);
                return;
            }

            if (!isset($state["current_step"])) {
                OPTISTATE_Tools_Utilities::delete_analyze_state(
                    $this->process_store,
                    $transient_key
                );
                OPTISTATE_Tools_Utilities::release_db_lock($lock_name);
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Corrupted analysis session. Please start over.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $use_cli_optimization = OPTISTATE_Tools_Utilities::is_cli_context();

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
                            $corrupted_tables = array_filter(
                                $state["table_statuses"],
                                function ($status) {
                                    return !empty($status["corrupted"]);
                                }
                            );
                            $state["final_results"]["corrupted"] = count(
                                $corrupted_tables
                            );
                            $state["total_to_repair"] = count(
                                $state["tables_to_repair"]
                            );
                            OPTISTATE_Tools_Utilities::save_analyze_state(
                                $this->process_store,
                                $transient_key,
                                $state
                            );
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
                                                    $state["final_results"][
                                                        "failed"
                                                    ]++;
                                                }
                                                continue;
                                            }
                                            [
                                                $needs_repair,
                                                $error_message,
                                                $is_ok,
                                            ] = OPTISTATE_Tools_Utilities::evaluate_check_table_rows(
                                                $results_by_table[$table_name]
                                            );
                                            OPTISTATE_Tools_Utilities::apply_check_result_to_state(
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
                                            $state["final_results"]["failed"]++;
                                        }
                                        continue;
                                    }
                                    [
                                        $needs_repair,
                                        $error_message,
                                        $is_ok,
                                    ] = OPTISTATE_Tools_Utilities::evaluate_check_table_rows(
                                        $check_results
                                    );
                                    OPTISTATE_Tools_Utilities::apply_check_result_to_state(
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
                        OPTISTATE_Tools_Utilities::save_analyze_state(
                            $this->process_store,
                            $transient_key,
                            $state
                        );
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
                            OPTISTATE_Tools_Utilities::save_analyze_state(
                                $this->process_store,
                                $transient_key,
                                $state
                            );
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
                                    $opt_result = OPTISTATE_Tools_Utilities::optimize_with_lock_retry(
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
                        $total_to_repair = (int) ($state["total_to_repair"] ?? 0);
                        $remaining_repair = count($state["tables_to_repair"]);
                        $processed_repair = $total_to_repair - $remaining_repair;
                        $repair_percentage = $total_to_repair > 0
                            ? min(99, (int) round($processed_repair / $total_to_repair * 100))
                            : 99;

                        OPTISTATE_Tools_Utilities::save_analyze_state(
                            $this->process_store,
                            $transient_key,
                            $state
                        );
                        OPTISTATE_Utils::send_json_success([
                            "status" => "running",
                            "step" => __("Repairing...", "optistate"),
                            "percentage" => $repair_percentage,
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
                        $state["total_to_optimize"] = count(
                            $state["tables_to_optimize"]
                        );
                        $state["current_step"] = "optimize";
                        OPTISTATE_Tools_Utilities::save_analyze_state(
                            $this->process_store,
                            $transient_key,
                            $state
                        );
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
                            OPTISTATE_Tools_Utilities::save_analyze_state(
                                $this->process_store,
                                $transient_key,
                                $state
                            );
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
                                $table_name_with_db = $optimize_row["Table"];
                                $clean_table_name =
                                    strpos($table_name_with_db, ".") !== false
                                        ? substr(
                                            $table_name_with_db,
                                            strpos($table_name_with_db, ".") + 1
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
                                        $optimize_results_by_table[$table_name]
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
                                if ($optimize_successful && !$optimize_error) {
                                    $optimized_count_in_batch++;
                                    if (
                                        isset(
                                            $state["table_statuses"][
                                                $table_name
                                            ]
                                        )
                                    ) {
                                        $state["table_statuses"][$table_name][
                                            "optimized"
                                        ] = true;
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
                                        $state["table_statuses"][$table_name][
                                            "optimized"
                                        ] = false;
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
                        $total_to_opt = (int) ($state["total_to_optimize"] ?? 0);
                        $remaining_opt = count($state["tables_to_optimize"]);
                        $processed_opt = $total_to_opt - $remaining_opt;
                        $opt_percentage = $total_to_opt > 0
                            ? min(99, (int) round($processed_opt / $total_to_opt * 100))
                            : 99;

                        OPTISTATE_Tools_Utilities::save_analyze_state(
                            $this->process_store,
                            $transient_key,
                            $state
                        );
                        OPTISTATE_Utils::send_json_success([
                            "status" => "running",
                            "step" => __("Optimizing...", "optistate"),
                            "percentage" => $opt_percentage,
                        ]);
                        return;

                    case "done":
                        OPTISTATE_Tools_Utilities::delete_analyze_state(
                            $this->process_store,
                            $transient_key
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
                        OPTISTATE_Tools_Utilities::invalidate_analysis_caches();
                        $problem_details = [];
                        $ok_details = [];
                        foreach ($state["table_statuses"] as $table_status) {
                            if (
                                !empty($table_status["corrupted"]) ||
                                !empty($table_status["error"])
                            ) {
                                $problem_details[] = $table_status;
                            } else {
                                $ok_details[] = $table_status;
                            }
                        }

                        $final_details = array_slice(
                            $problem_details,
                            0,
                            OPTISTATE_Tools_Utilities::MAX_DETAIL_ROWS
                        );
                        $remaining_budget =
                            OPTISTATE_Tools_Utilities::MAX_DETAIL_ROWS - count($final_details);
                        if ($remaining_budget > 0) {
                            $final_details = array_merge(
                                $final_details,
                                array_slice($ok_details, 0, $remaining_budget)
                            );
                        }

                        $state["final_results"]["details"] = $final_details;
                        $state["final_results"]["details_truncated"] =
                            count($problem_details) + count($ok_details) >
                            count($final_details);

                        OPTISTATE_Utils::send_json_success([
                            "status" => "done",
                            "results" => $state["final_results"],
                        ]);
                        return;
                }
            } catch (Throwable $e) {
                OPTISTATE_Tools_Utilities::delete_analyze_state(
                    $this->process_store,
                    $transient_key
                );
                $step = isset($state["current_step"])
                    ? $state["current_step"]
                    : "unknown";
                $table_index = sprintf(
                    "check:%d/repair:%d/optimize:%d",
                    isset($state["tables_to_check"])
                        ? count($state["tables_to_check"])
                        : 0,
                    isset($state["tables_to_repair"])
                        ? count($state["tables_to_repair"])
                        : 0,
                    isset($state["tables_to_optimize"])
                        ? count($state["tables_to_optimize"])
                        : 0
                );

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
            OPTISTATE_Tools_Utilities::delete_analyze_state(
                $this->process_store,
                $transient_key
            );
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
                OPTISTATE_Tools_Utilities::release_db_lock($lock_name);
            }
        }
    }
    public function ajax_analyze_indexes(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("analyze_indexes", 10)) {
            OPTISTATE_Utils::send_rate_limit_error();
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
                                        "SHOW COLUMNS FROM " .
                                            OPTISTATE_Utils::escape_identifier(
                                                $table
                                            )
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

        if (!OPTISTATE_Tools_Utilities::require_post()) {
            return;
        }

        if (!OPTISTATE_Utils::check_rate_limit("manage_index", 2)) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }

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
            if (!in_array($action_type, ["add", "drop"], true)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Unsupported index operation.",
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
                    if (!OPTISTATE_Tools_Utilities::validate_column_name($col_def, $table)) {
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

            $space_warning = "";
            if ($action_type === "add") {
                $space_check = OPTISTATE_Tools_Utilities::check_disk_space_for_index(
                    $table
                );
                if (!$space_check["success"]) {
                    OPTISTATE_Utils::send_json_error([
                        "message" => $space_check["message"],
                    ]);
                    return;
                }
                if (empty($space_check["available_space"])) {
                    $space_warning = (string) $space_check["message"];
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
                OPTISTATE_Tools_Utilities::INDEX_TASK_TTL
            );

            wp_schedule_single_event(time(), "optistate_run_index_chunk", [
                $task_id,
            ]);

            $msg =
                $action_type === "add"
                    ? __("Index creation started in background.", "optistate")
                    : __("Index removal started in background.", "optistate");

            $response = [
                "status" => "processing",
                "task_id" => $task_id,
                "message" => $msg,
            ];

            if ($space_warning !== "") {
                $response["warning"] = $space_warning;
            }

            OPTISTATE_Utils::send_json_success($response);
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
            if (
                !is_array($task) ||
                !isset($task["status"], $task["escaped_table"], $task["table"], $task["index_name"]) ||
                !in_array($task["status"], ["pending", "running"], true)
            ) {
                return;
            }

            global $wpdb;
            $task["status"] = "running";
            $this->process_store->set($task_id, $task, OPTISTATE_Tools_Utilities::INDEX_TASK_TTL);

            $table = $task["escaped_table"];
            $table_raw = $task["table"];
            $index_name = $task["index_name"];
            $type = isset($task["type"]) ? (string) $task["type"] : "";
            if (!in_array($type, ["add", "drop"], true)) {
                OPTISTATE_Tools_Utilities::mark_index_task_error(
                    $this->process_store,
                    $task_id,
                    $task,
                    __("Unsupported index operation.", "optistate")
                );
                return;
            }

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $index_name)) {
                OPTISTATE_Tools_Utilities::mark_index_task_error(
                    $this->process_store,
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
                        OPTISTATE_Tools_Utilities::mark_index_task_error(
                            $this->process_store,
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
                        OPTISTATE_Tools_Utilities::mark_index_task_error(
                            $this->process_store,
                            $task_id,
                            $task,
                            sprintf(
                                __("Invalid column name: %s", "optistate"),
                                $col_name
                            )
                        );
                        return;
                    }
                    if (!OPTISTATE_Tools_Utilities::validate_column_name($col, $table_raw)) {
                        OPTISTATE_Tools_Utilities::mark_index_task_error(
                            $this->process_store,
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
            } elseif ($type === "drop") {
                $escaped_index_name = "`" . esc_sql($index_name) . "`";
                $sql = "ALTER TABLE $table DROP INDEX $escaped_index_name";
            } else {
                OPTISTATE_Tools_Utilities::mark_index_task_error(
                    $this->process_store,
                    $task_id,
                    $task,
                    __("Unsupported index operation.", "optistate")
                );
                return;
            }

            $max_retries = 3;
            $retry_delay = 1;
            $success = false;
            $last_error = null;
            $orig_lock_wait       = $wpdb->get_var("SELECT @@SESSION.lock_wait_timeout");
            $orig_innodb_lock_wait = $wpdb->get_var("SELECT @@SESSION.innodb_lock_wait_timeout");

            $wpdb->query("SET SESSION lock_wait_timeout = 60");
            $wpdb->query("SET SESSION innodb_lock_wait_timeout = 60");

            try {
                for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
                    try {
                        $suppress = $wpdb->suppress_errors(true);

                        $result = $wpdb->query(
                            $sql . " , ALGORITHM=INPLACE, LOCK=NONE"
                        );
                        $error = $wpdb->last_error;

                        if ($result === false) {
                            $result = $wpdb->query($sql);
                            $error = $wpdb->last_error;
                        }

                        $wpdb->suppress_errors($suppress);

                        if ($result !== false) {
                            $success = true;
                            break;
                        }

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
            } finally {
                if ($orig_lock_wait !== null) {
                    $wpdb->query("SET SESSION lock_wait_timeout = " . (int) $orig_lock_wait);
                }
                if ($orig_innodb_lock_wait !== null) {
                    $wpdb->query("SET SESSION innodb_lock_wait_timeout = " . (int) $orig_innodb_lock_wait);
                }
            }

            if (!$success) {
                $error_detail =
                    $last_error ?: "Unknown error during ALTER TABLE";
                OPTISTATE_Tools_Utilities::mark_index_task_error(
                    $this->process_store,
                    $task_id,
                    $task,
                    $error_detail
                );
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
            if (
                OPTISTATE_Tools_Utilities::verify_index_operation($table, $index_name, $type)
            ) {
                $task["status"] = "done";
                $this->process_store->set(
                    $task_id,
                    $task,
                    OPTISTATE_Tools_Utilities::INDEX_TASK_TTL
                );

                OPTISTATE_Tools_Utilities::invalidate_analysis_caches();
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
                OPTISTATE_Tools_Utilities::mark_index_task_error(
                    $this->process_store,
                    $task_id,
                    $task,
                    $error_detail
                );
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
            OPTISTATE_Tools_Utilities::mark_index_task_error(
                $this->process_store,
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

    public function ajax_delete_table(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        if (!OPTISTATE_Tools_Utilities::require_post()) {
            return;
        }
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit("delete_table", 5)) {
            OPTISTATE_Utils::send_rate_limit_error();
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
                OPTISTATE_Utils::send_json_error(
                    [
                        "message" => __(
                            "Security check failed: Invalid or non-existent table name.",
                            "optistate"
                        ),
                    ],
                    400
                );
                return;
            }
            if (OPTISTATE_Utils::is_core_table($table_name)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Protected: Cannot delete WordPress core tables or plugin critical data.",
                        "optistate"
                    ),
                ]);
                return;
            }

            $optistate_excluded = OPTISTATE_Utils::get_optistate_core_excluded_tables();
            $optistate_excluded_lower = array_map("strtolower", $optistate_excluded);
            if (in_array(strtolower($table_name), $optistate_excluded_lower, true)) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Protected: Cannot delete WordPress core tables or plugin critical data.",
                        "optistate"
                    ),
                ]);
                return;
            }

            global $wpdb;

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

            if ($update_time && time() - $update_time < self::ABANDONED_THRESHOLD) {
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
                OPTISTATE_Tools_Utilities::invalidate_analysis_caches();
                OPTISTATE_Tools_Utilities::flush_base_table_cache();

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
                OPTISTATE_Utils::log_critical_error(
                    "Trash unavailable; falling back to irreversible DROP TABLE",
                    ["table" => $table_name]
                );

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
                                "🗑️ Permanently deleted Database Table '%s' (trash unavailable) by {username}",
                                "optistate"
                            ),
                            $table_name
                        )
                    );
                    $this->main_plugin->clear_stats_cache();
                    OPTISTATE_Utils::invalidate_table_cache();
                    OPTISTATE_Tools_Utilities::invalidate_analysis_caches();
                    OPTISTATE_Tools_Utilities::flush_base_table_cache();

                    OPTISTATE_Utils::send_json_success([
                        "message" => sprintf(
                            __(
                                "Table '%s' was permanently deleted. The trash was unavailable, so this deletion cannot be undone.",
                                "optistate"
                            ),
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

    public function ajax_optimize_tables(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (!OPTISTATE_Tools_Utilities::require_post()) {
            return;
        }

        $state_key = OPTISTATE_Tools_Utilities::get_optimize_tables_transient_key();
        $is_continuation = (bool) get_transient($state_key);
        if (
            !$is_continuation &&
            !OPTISTATE_Utils::check_rate_limit("heavy_op", 20)
        ) {
            OPTISTATE_Utils::send_rate_limit_error();
            return;
        }
        global $wpdb;
        $lock_name = "optistate_opt_" . md5($state_key);
        $lock_acquired = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_name)
        );

        if ($lock_acquired !== 1) {
            OPTISTATE_Utils::send_json_success([
                "status" => "running",
                "percentage" => 0,
            ]);
            return;
        }

        OPTISTATE_Tools_Utilities::mark_db_lock_acquired($lock_name);
        OPTISTATE_Tools_Utilities::register_lock_release_on_shutdown($lock_name);

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
            OPTISTATE_Tools_Utilities::invalidate_analysis_caches();

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
        } finally {
            OPTISTATE_Tools_Utilities::release_db_lock($lock_name);
        }
    }

    public function perform_optimize_tables(bool $return_data = false)
    {
        wp_raise_memory_limit("admin");
        global $wpdb;

        $is_ajax = wp_doing_ajax();
        $is_cli = defined("WP_CLI") && WP_CLI;
        $is_cron = wp_doing_cron();

        $transient_key = OPTISTATE_Tools_Utilities::get_optimize_tables_transient_key();
        $start_time = microtime(true);
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(900);

        $allow_chunking = $is_ajax && !$is_cron && !$is_cli;
        $chunk_time_budget = 20;
        $original_lock_wait_timeout = null;

        try {
            $state = get_transient($transient_key);

            if (!$state || !is_array($state) || $is_cron || $is_cli) {
                $tables = OPTISTATE_Tools_Utilities::get_base_tables();
                $table_data = [];
                OPTISTATE_Utils::preload_all_table_statuses();

                foreach ($tables as $table_name) {
                    $status = OPTISTATE_Utils::get_table_status($table_name);
                    if ($status) {
                        $table_data[] = [
                            "TABLE_NAME" => $table_name,
                            "ENGINE" => $status["ENGINE"] ?? "",
                            "TABLE_TYPE" => "BASE TABLE",
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
                        "details_truncated" => false,
                        "status" => "done",
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
                        "details_truncated" => false,
                    ],
                ];
            }

            $tables = $state["tables"];
            $total_count = $state["total_count"];
            $current_index = $state["current_index"];
            $results = $state["results"];
            $orig_val = $wpdb->get_var("SELECT @@SESSION.lock_wait_timeout");
            if ($orig_val !== null) {
                $original_lock_wait_timeout = (int) $orig_val;
            }
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

                if (OPTISTATE_Tools_Utilities::should_skip_table_optimization($table)) {
                    $results["skipped"]++;
                    OPTISTATE_Tools_Utilities::push_detail($results, [
                        "table" => $table_name,
                        "status" => "skipped",
                        "reason" => __(
                            "No overhead or not supported",
                            "optistate"
                        ),
                    ]);
                } else {
                    $opt_result = OPTISTATE_Tools_Utilities::optimize_table_enterprise(
                        $table_name,
                        $table["ENGINE"]
                    );
                    if ($opt_result["success"]) {
                        $results["optimized"]++;
                        $results["reclaimed"] += $initial_overhead;
                        OPTISTATE_Tools_Utilities::push_detail($results, [
                            "table" => $table_name,
                            "status" => "optimized",
                            "method" => $opt_result["method"],
                        ]);
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
                    $expiry = max(
                        HOUR_IN_SECONDS,
                        (int) ($est_remaining + 300)
                    );
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
            if ($original_lock_wait_timeout !== null) {
                $wpdb->query(
                    "SET SESSION lock_wait_timeout = {$original_lock_wait_timeout}"
                );
            }
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
        }
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
        }
    }
}