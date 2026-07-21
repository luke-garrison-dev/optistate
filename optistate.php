<?php
/**
 * Plugin Name: Optimal State
 * Plugin URI: https://spiritualseek.com/wp-content/uploads/2025/11/WP_Optimal_State_PRO_User_Manual.html
 * Description: Advanced WordPress optimization suite featuring integrated database cleanup and backup tools, page caching, and diagnostic tools.
 * Version: 1.4.3
 * Author: Luke Garrison
 * Author URI: https://spiritualseek.com/wp-content/uploads/2025/11/WP_Optimal_State_PRO_User_Manual.html
 * Text Domain: optistate
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.5
 * Requires PHP: 7.4
 */

if (!defined("ABSPATH")) {
    exit();
}

define("OPTISTATE_PLUGIN_FILE", __FILE__);
define("OPTISTATE_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("OPTISTATE_PLUGIN_URL", plugin_dir_url(__FILE__));
define("OPTISTATE_INCLUDES_DIR", OPTISTATE_PLUGIN_DIR . "includes/");

$optistate_class_map = [
    "OPTISTATE" => OPTISTATE_INCLUDES_DIR . "class-optistate.php",
    "OPTISTATE_Settings_Manager" => OPTISTATE_INCLUDES_DIR . "class-optistate-settings-manager.php",
    "OPTISTATE_Performance_Manager" => OPTISTATE_INCLUDES_DIR . "class-optistate-performance-manager.php",
    "OPTISTATE_Backup_Manager" => OPTISTATE_INCLUDES_DIR . "class-optistate-backup-manager.php",
    "OPTISTATE_Process_Store" => OPTISTATE_INCLUDES_DIR . "class-optistate-process-store.php",
    "OPTISTATE_Server_Caching" => OPTISTATE_INCLUDES_DIR . "class-optistate-server-caching.php",
    "OPTISTATE_TwoFactor" => OPTISTATE_INCLUDES_DIR . "class-optistate-twofactor.php",
    "OPTISTATE_Login_Protection" => OPTISTATE_INCLUDES_DIR . "class-optistate-login-protection.php",
    "OPTISTATE_Trash_Manager" => OPTISTATE_INCLUDES_DIR . "class-optistate-trash-manager.php",
    "OPTISTATE_Search_Replace" => OPTISTATE_INCLUDES_DIR . "class-optistate-search-replace.php",
    "OPTISTATE_Performance_Audit" => OPTISTATE_INCLUDES_DIR . "class-optistate-performance-audit.php",
    "OPTISTATE_Advanced_Tools" => OPTISTATE_INCLUDES_DIR . "class-optistate-advanced-tools.php",
    "OPTISTATE_Cleanup_Functions" => OPTISTATE_INCLUDES_DIR . "class-optistate-cleanup-functions.php",
    "OPTISTATE_Health_Score" => OPTISTATE_INCLUDES_DIR . "class-optistate-health-score.php",
    "OPTISTATE_Legacy_Scanner" => OPTISTATE_INCLUDES_DIR . "class-optistate-legacy-scanner.php",
    "OPTISTATE_Admin_Interface" => OPTISTATE_INCLUDES_DIR . "class-optistate-admin-interface.php",
    "OPTISTATE_Activation" => OPTISTATE_INCLUDES_DIR . "class-optistate-activation.php",
    "OPTISTATE_Presets" => OPTISTATE_INCLUDES_DIR . "class-optistate-presets.php",
    "OPTISTATE_Backup_Engine" => OPTISTATE_INCLUDES_DIR . "class-optistate-backup-engine.php",
    "OPTISTATE_Backup_Utilities" => OPTISTATE_INCLUDES_DIR . "class-optistate-backup-utilities.php",
    "OPTISTATE_DB_Wrapper" => OPTISTATE_INCLUDES_DIR . "class-optistate-db-wrapper.php",
    "OPTISTATE_Restore_Engine" => OPTISTATE_INCLUDES_DIR . "class-optistate-restore-engine.php",
    "OPTISTATE_SQL_Parser" => OPTISTATE_INCLUDES_DIR . "class-optistate-sql-parser.php",
    "OPTISTATE_Utils" => OPTISTATE_INCLUDES_DIR . "class-optistate-utils.php",
];

spl_autoload_register(function ($class) use ($optistate_class_map) {
    if (strpos($class, "OPTISTATE") !== 0) {
        return;
    }
    if (isset($optistate_class_map[$class])) {
        require $optistate_class_map[$class];
    }
});

function optistate_maybe_load_textdomain(): void {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined("WP_CLI") && WP_CLI)) {
        load_plugin_textdomain(
            "optistate",
            false,
            dirname(plugin_basename(OPTISTATE_PLUGIN_FILE)) . "/languages"
        );
    }
}
add_action("init", "optistate_maybe_load_textdomain", 10);

function optistate_activate(): void {
    optistate_maybe_load_textdomain();
    OPTISTATE_Activation::activate();
}

function optistate_deactivate(): void {
    OPTISTATE_Activation::deactivate();
}

register_activation_hook(__FILE__, "optistate_activate");
register_deactivation_hook(__FILE__, "optistate_deactivate");

add_action("init", ["OPTISTATE", "instance"], 5);

function optistate_upgrade(): void {
    $current_version = get_option("optistate_db_version", "0");
    if (version_compare($current_version, OPTISTATE::VERSION, ">=")) {
        return;
    }
    OPTISTATE_Activation::create_core_data_table();
    update_option("optistate_db_version", OPTISTATE::VERSION);
}
add_action("plugins_loaded", "optistate_upgrade");