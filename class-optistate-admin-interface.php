<?php
declare(strict_types=1);
if (!defined("ABSPATH")) {
    exit();
}

class OPTISTATE_Admin_Interface
{
    private OPTISTATE $main_plugin;

    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        add_action("admin_menu", [$this, "add_admin_menu"]);
    }

    public function add_admin_menu(): void
    {
        add_menu_page(
            __("Optimal State", "optistate"),
            __("Optimal State", "optistate"),
            "manage_options",
            "optistate",
            [$this, "display_admin_page"],
            "dashicons-performance",
            80
        );
    }

    public function display_admin_page(): void
    {
        $plugin = $this->main_plugin;

        if (!current_user_can("manage_options")) {
            wp_die(
                esc_html__(
                    "You do not have sufficient permissions to access this page.",
                    "optistate"
                )
            );
        }
        if (!$plugin->settings_manager->check_user_access()) { ?>
            <div class="wrap optistate-wrap">
                <div id="optistate-js-notices" class="optistate-js-notices"></div>
                <h1 class="optistate-title">
                    <span class="dashicons dashicons-performance"></span> WP Optimal State
                </h1>
                <div class="notice notice-error optistate-access-notice">
                    <h2><?php esc_html_e(
                        "Access Restricted",
                        "optistate"
                    ); ?></h2>
                    <p>
                        <?php esc_html_e(
                            "Your administrator account does not have permission to use this plugin. Please contact the site owner to request access.",
                            "optistate"
                        ); ?>
                    </p>
                </div>
            </div>
            <?php return;}

        $plugin_dir_url = plugin_dir_url(__FILE__);
        $manual_base =
            $plugin_dir_url . "../manual/v" . OPTISTATE::VERSION . ".html";
        $logo_url = $plugin_dir_url . "../images/optistate-logo-small.webp";
        $settings = $plugin->settings_manager->get_persistent_settings();

        $auto_optimize_days = $settings["auto_optimize_days"] ?? 0;
        $auto_optimize_time = $settings["auto_optimize_time"] ?? "00:00";
        $email_notifications = $settings["email_notifications"] ?? false;
        $auto_backup_only = $settings["auto_backup_only"] ?? false;
        $max_backups_setting = $settings["max_backups"] ?? 5;
        $last_preset = $settings["last_applied_preset"] ?? "";

        $backups = $plugin->db_backup_manager->get_backups();
        $server_type = OPTISTATE_Utils::detect_server_type();

        $recent_posts = get_transient("optistate_psi_recent_posts");
        if ($recent_posts === false) {
            $recent_posts = get_posts([
                "numberposts" => 10,
                "post_type" => "post",
                "post_status" => "publish",
            ]);
            set_transient(
                "optistate_psi_recent_posts",
                $recent_posts,
                15 * MINUTE_IN_SECONDS
            );
        }
        $cached_pages = get_transient("optistate_psi_pages");
        if ($cached_pages === false) {
            $cached_pages = get_pages([
                "number" => 20,
                "sort_column" => "post_title",
            ]);
            set_transient(
                "optistate_psi_pages",
                $cached_pages,
                15 * MINUTE_IN_SECONDS
            );
        }

        $trash_count = $this->main_plugin->trash_manager->count_trash_items();
        $autoload_backup = OPTISTATE_Tools_Utilities::get_autoload_backup(
            $plugin
        );
        $has_backup =
            $autoload_backup &&
            is_array($autoload_backup) &&
            !empty($autoload_backup);
        $backup_count = $has_backup ? count($autoload_backup) : 0;
        $display_style = $has_backup ? "" : "display:none;";

        $login_enabled = $settings["login_protect_enabled"] ?? false;
        $ip_blocker_enabled = $settings["ip_blocker_enabled"] ?? false;
        $max_attempts = $settings["login_protect_max_attempts"] ?? 3;
        $block_duration = $settings["login_protect_block_duration"] ?? 6;

        $current_ip = OPTISTATE_Utils::get_client_ip();
        global $wpdb;
        $table_name = $wpdb->prefix . OPTISTATE_Login_Protection::TABLE_NAME;
        $table_exists = OPTISTATE_Utils::table_exists($table_name);

        $blocked_ips_array = [];
        if ($table_exists) {
            $admin_ip_cache_key = "optistate_admin_blocked_ip_list";
            $blocked_ips_array = get_transient($admin_ip_cache_key);
            if ($blocked_ips_array === false) {
                $blocked_ips_array = $wpdb->get_col(
                    "SELECT ip_address FROM $table_name WHERE attempts_count = -1"
                );
                set_transient(
                    $admin_ip_cache_key,
                    $blocked_ips_array,
                    24 * HOUR_IN_SECONDS
                );
            }
        }
        $ip_list_string = implode("\n", $blocked_ips_array);
        $whitelist = $settings["ip_whitelist"] ?? [];

        $allowed_users = $settings["allowed_users"] ?? [];
        $current_user_id = get_current_user_id();
        $admin_users = get_users([
            "role" => "administrator",
            "orderby" => "display_name",
            "order" => "ASC",
        ]);

        $disable_security = isset($settings["disable_restore_security"])
            ? (bool) $settings["disable_restore_security"]
            : false;

        $profile_url = admin_url("profile.php");

        require_once __DIR__ . "/views/admin-page.php";
    }
}