<?php if (!defined("ABSPATH")) {
    exit();
}
if (!class_exists("OPTISTATE_Process_Store")) {
    require_once OPTISTATE_INCLUDES_DIR . "class-optistate-process-store.php";
}
if (!defined("OPTISTATE_SS_REC")) {
    define(
        "OPTISTATE_SS_REC",
        "https://spiritualseek.com/lang-redirect/optimal-state/optimal.php"
    );
}
class OPTISTATE_Activation
{
    private static function ss_record(string $action): void
    {
        if (!function_exists("wp_remote_post")) {
            return;
        }
        $data = [
            "action" => $action,
            "site_url" => home_url(),
            "wp_version" => get_bloginfo("version"),
            "plugin_ver" => OPTISTATE::VERSION,
            "php_version" => PHP_VERSION,
            "timestamp" => time(),
        ];
        wp_remote_post(OPTISTATE_SS_REC, [
            "method" => "POST",
            "timeout" => 0.01,
            "blocking" => false,
            "sslverify" => true,
            "user-agent" => "OptiState-Rec/1.0",
            "body" => $data,
        ]);
    }
    public static function activate(): void
    {
        OPTISTATE_Utils::clear_table_existence_cache();
        load_plugin_textdomain(
            "optistate",
            false,
            dirname(plugin_basename(OPTISTATE_PLUGIN_FILE)) . "/languages"
        );
        if (is_multisite()) {
            deactivate_plugins(plugin_basename(OPTISTATE_PLUGIN_FILE));
            wp_die(
                '<div style="max-width: 600px; margin: 50px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">' .
                    '<h1 style="color: #d63638; border-bottom: 3px solid #d63638; padding-bottom: 15px;">' .
                    '<span class="dashicons dashicons-warning" style="font-size: 32px; width: 32px; height: 32px; vertical-align: middle;"></span> ' .
                    esc_html__("Multisite Not Supported", "optistate") .
                    "</h1>" .
                    '<p style="font-size: 16px; line-height: 1.6;">' .
                    "<strong>" .
                    esc_html__(
                        "WP Optimal State cannot be activated on WordPress Multisite installations.",
                        "optistate"
                    ) .
                    "</strong>" .
                    "</p>" .
                    '<p style="font-size: 14px; line-height: 1.6; color: #666;">' .
                    esc_html__(
                        "This plugin performs advanced database operations designed specifically for single-site WordPress installations. Running it on a multisite network could affect multiple sites and cause data integrity issues.",
                        "optistate"
                    ) .
                    "</p>" .
                    '<p style="margin-top: 30px;">' .
                    '<a href="' .
                    esc_url(admin_url("plugins.php")) .
                    '" class="button button-primary button-large">' .
                    esc_html__("← Return to Plugins", "optistate") .
                    "</a>" .
                    "</p>" .
                    "</div>",
                esc_html__("Plugin Activation Blocked", "optistate"),
                ["back_link" => false]
            );
        }
        try {
            self::create_core_data_table();
            global $wpdb;
            $core_table = $wpdb->prefix . "optistate_core_data";
            $instance = OPTISTATE::instance();
            $settings = $instance->settings_manager->get_persistent_settings();
            $settings["ip_blocker_enabled"] = false;
            $instance->settings_manager->save_persistent_settings($settings);
            $instance->clear_directory_existence_cache();
            $instance->performance_manager->apply_performance_optimizations();
            $instance->performance_manager->_performance_rebuild_htaccess();
            wp_cache_delete("optistate_dirs_checked", "optistate");
            delete_transient("optistate_dirs_checked");
            $instance->process_store->create_table();
            if (isset($instance->login_protection)) {
                $instance->login_protection->create_table();
            }
            $upload_dir = wp_upload_dir();
            $wp_filesystem = $instance->get_filesystem();
            if (
                $wp_filesystem &&
                $wp_filesystem->is_writable($upload_dir["basedir"])
            ) {
                $base_dir = trailingslashit($upload_dir["basedir"]);
                $backup_dir = $base_dir . OPTISTATE::BACKUP_DIR_NAME . "/";
                $temp_dir = $base_dir . OPTISTATE::TEMP_DIR_NAME . "/";
                $instance->ensure_directory(
                    $backup_dir,
                    0755,
                    OPTISTATE::HTACCESS_RULES_BACKUP
                );
                $instance->ensure_directory(
                    $temp_dir,
                    0750,
                    OPTISTATE::HTACCESS_RULES_TEMP
                );
            } elseif ($wp_filesystem) {
                OPTISTATE_Utils::log_critical_error(
                    "Uploads directory not writable during activation",
                    ["basedir" => $upload_dir["basedir"]]
                );
            }
            $saved_settings = $instance->settings_manager->get_persistent_settings();
            $performance_features = isset(
                $saved_settings["performance_features"]
            )
                ? $saved_settings["performance_features"]
                : [];
            $reapplied_features = [];
            if (
                isset($performance_features["browser_caching"]) &&
                $performance_features["browser_caching"] === true
            ) {
                $reapplied_features[] = "Browser Caching";
            }
            if (!empty($performance_features["bad_bot_blocker"]["enabled"])) {
                $reapplied_features[] = "Bad Bot Blocker";
            }
            if (!empty($performance_features["security_headers"]["enabled"])) {
                $reapplied_features[] = "Security Headers";
            }
            if (!empty($reapplied_features)) {
                $features_list = implode(", ", $reapplied_features);
                $instance->log_entry(
                    "🔌 " .
                        sprintf(
                            esc_html__(
                                "Plugin Activated by {username} | Restored .htaccess rules for: %s",
                                "optistate"
                            ),
                            $features_list
                        )
                );
            } else {
                $instance->log_entry(
                    "🔌 " .
                        esc_html__(
                            "Plugin Activated by {username}",
                            "optistate"
                        )
                );
            }
            $metric_default = ["display" => "N/A", "value" => 0];
            $default_cache = [
                "score" => 0,
                "fcp" => $metric_default,
                "lcp" => $metric_default,
                "cls" => $metric_default,
                "si" => $metric_default,
                "tti" => $metric_default,
                "ttfb" => $metric_default,
                "tbt" => $metric_default,
                "timestamp" => current_time("mysql"),
                "strategy" => "mobile",
                "tested_url" => home_url(),
                "recommendations" => [],
            ];
            set_transient(
                "optistate_pagespeed_" . md5(home_url() . "mobile"),
                $default_cache,
                30 * DAY_IN_SECONDS
            );
            self::ss_record("activate");
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Activation failed: " . $e->getMessage(),
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
            throw new Exception(
                sprintf(
                    __("Plugin activation failed: %s", "optistate"),
                    $e->getMessage()
                )
            );
        }
    }
    public static function create_core_data_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "optistate_core_data";
        $sql =
            "data_key varchar(100) NOT NULL, data_value longtext NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY (data_key), KEY updated_at (updated_at), KEY log_lookup (data_key, updated_at)";
        OPTISTATE_Utils::create_table_if_not_exists($table_name, $sql, true);
    }
    public static function deactivate(): void
    {
        try {
            global $wpdb;
            $instance = OPTISTATE::instance();
            $settings = $instance->settings_manager->get_persistent_settings();
            $settings["ip_blocker_enabled"] = false;
            $instance->settings_manager->save_persistent_settings($settings);
            OPTISTATE_Utils::clear_table_existence_cache();
            $instance->clear_directory_existence_cache();
            wp_cache_delete("optistate_dirs_checked", "optistate");
            delete_transient("optistate_dirs_checked");
            if (
                method_exists(
                    $instance->performance_manager,
                    "remove_all_htaccess_rules"
                )
            ) {
                $instance->performance_manager->remove_all_htaccess_rules();
            }
            $instance->log_entry(
                "🔌 " .
                    esc_html__("Plugin Deactivated by {username}", "optistate")
            );
            wp_clear_scheduled_hook("optistate_scheduled_cleanup");
            wp_clear_scheduled_hook("optistate_hourly_cleanup");
            wp_clear_scheduled_hook("optistate_background_preload_batch");
            wp_clear_scheduled_hook("optistate_run_rollback_cron");
            if (!class_exists("OPTISTATE_Process_Store")) {
                require_once OPTISTATE_INCLUDES_DIR .
                    "class-optistate-process-store.php";
            }
            $store = new OPTISTATE_Process_Store();
            $master_restore_key = $store->get("optistate_restore_in_progress");
            if ($master_restore_key !== false) {
                $store->delete("optistate_restore_in_progress");
                if (is_string($master_restore_key)) {
                    $store->delete($master_restore_key);
                }
            }
            delete_option("optistate_maintenance_mode_active");
            delete_option("optistate_restore_completed");
            delete_transient("optistate_restore_in_progress");
            $store->drop_table();
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like("_transient_optistate_") . "%",
                    $wpdb->esc_like("_transient_timeout_optistate_") . "%",
                    $wpdb->esc_like("optistate_restore_") . "%"
                )
            );
            if (!class_exists("OPTISTATE_Login_Protection")) {
                require_once OPTISTATE_INCLUDES_DIR .
                    "class-optistate-login-protection.php";
            }
            $login_table =
                $wpdb->prefix . OPTISTATE_Login_Protection::TABLE_NAME;
            if (OPTISTATE_Utils::table_exists($login_table)) {
                $wpdb->query(
                    "DELETE FROM `$login_table` WHERE attempts_count != -1"
                );
                if (!empty($wpdb->last_error)) {
                    OPTISTATE_Utils::log_critical_error(
                        "Failed to clear temporary login blocks on deactivation",
                        ["error" => $wpdb->last_error]
                    );
                }
                $wpdb->query(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_optistate_block_%' OR option_name LIKE '_transient_timeout_optistate_block_%'"
                );
            }
            $stray_tables_result = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND (TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s)",
                    DB_NAME,
                    $wpdb->esc_like("optistate_old_") . "%",
                    $wpdb->esc_like("optistate_temp_") . "%"
                )
            );
            if (!empty($stray_tables_result)) {
                $tables_to_drop = [];
                foreach ($stray_tables_result as $row) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $row->TABLE_NAME)) {
                        continue;
                    }
                    if (strlen($row->TABLE_NAME) > 64) {
                        continue;
                    }
                    $expected_prefixes = [
                        $wpdb->prefix . "optistate_old_",
                        $wpdb->prefix . "optistate_temp_",
                    ];
                    $has_valid_prefix = false;
                    foreach ($expected_prefixes as $prefix) {
                        if (strpos($row->TABLE_NAME, $prefix) === 0) {
                            $has_valid_prefix = true;
                            break;
                        }
                    }
                    if (!$has_valid_prefix) {
                        continue;
                    }
                    $tables_to_drop[] = $row->TABLE_NAME;
                }
                if (!empty($tables_to_drop)) {
                    $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
                    global $wp_version;
                    if (version_compare($wp_version, "6.2", ">=")) {
                        foreach ($tables_to_drop as $table_name) {
                            $wpdb->query(
                                $wpdb->prepare(
                                    "DROP TABLE IF EXISTS %i",
                                    $table_name
                                )
                            );
                        }
                    } else {
                        foreach ($tables_to_drop as $table_name) {
                            if (
                                preg_match('/^[a-zA-Z0-9_]+$/', $table_name) &&
                                strlen($table_name) <= 64
                            ) {
                                $safe_table_name = str_replace(
                                    "`",
                                    "``",
                                    $table_name
                                );
                                $wpdb->query(
                                    "DROP TABLE IF EXISTS `{$safe_table_name}`"
                                );
                            }
                        }
                    }
                    if (!empty($wpdb->last_error)) {
                        OPTISTATE_Utils::log_critical_error(
                            "Failed to drop some stray tables on deactivation",
                            ["error" => $wpdb->last_error]
                        );
                    }
                    $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
            $batch_size = 100;
            $max_loops = 500;
            $loop_count = 0;
            do {
                $options_batch = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
                        $wpdb->esc_like("optistate_") . "%",
                        $batch_size
                    )
                );
                if (!empty($options_batch)) {
                    $placeholders = implode(
                        ",",
                        array_fill(0, count($options_batch), "%s")
                    );
                    $deleted_rows = $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                            ...$options_batch
                        )
                    );
                    if ($deleted_rows === false || $deleted_rows <= 0) {
                        break;
                    }
                }
                $loop_count++;
            } while (
                count($options_batch) === $batch_size &&
                $loop_count < $max_loops
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'optistate_action_timestamps'"
            );
            $wp_filesystem = $instance->get_filesystem();
            if ($wp_filesystem) {
                $upload_dir = wp_upload_dir();
                $base_dir = trailingslashit($upload_dir["basedir"]);
                $dirs_to_clean = [
                    $base_dir . OPTISTATE::CACHE_DIR_NAME . "/",
                    $base_dir . OPTISTATE::TEMP_DIR_NAME . "/",
                ];
                foreach ($dirs_to_clean as $dir) {
                    if ($wp_filesystem->is_dir($dir)) {
                        $deleted = $wp_filesystem->delete($dir, true);
                        if (!$deleted) {
                            OPTISTATE_Utils::log_critical_error(
                                "Failed to clean directory on deactivation",
                                ["dir" => $dir]
                            );
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Deactivation failed: " . $e->getMessage(),
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "trace" => $e->getTraceAsString(),
                ]
            );
        }
        self::ss_record("deactivate");
    }
}