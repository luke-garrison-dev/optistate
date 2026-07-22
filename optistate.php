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

if (!defined('ABSPATH')) {
    exit();
}

define('OPTISTATE_PLUGIN_FILE', __FILE__);
define('OPTISTATE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OPTISTATE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OPTISTATE_INCLUDES_DIR', OPTISTATE_PLUGIN_DIR . 'includes/');

(static function (): void {
    $class_map = [
        'optistate'                      => 'class-optistate.php',
        'optistate_settings_manager'     => 'class-optistate-settings-manager.php',
        'optistate_performance_manager'  => 'class-optistate-performance-manager.php',
        'optistate_backup_manager'       => 'class-optistate-backup-manager.php',
        'optistate_process_store'        => 'class-optistate-process-store.php',
        'optistate_server_caching'       => 'class-optistate-server-caching.php',
        'optistate_twofactor'            => 'class-optistate-twofactor.php',
        'optistate_login_protection'     => 'class-optistate-login-protection.php',
        'optistate_trash_manager'        => 'class-optistate-trash-manager.php',
        'optistate_search_replace'       => 'class-optistate-search-replace.php',
        'optistate_performance_audit'    => 'class-optistate-performance-audit.php',
        'optistate_advanced_tools'       => 'class-optistate-advanced-tools.php',
        'optistate_cleanup_functions'    => 'class-optistate-cleanup-functions.php',
        'optistate_health_score'         => 'class-optistate-health-score.php',
        'optistate_legacy_scanner'       => 'class-optistate-legacy-scanner.php',
        'optistate_admin_interface'      => 'class-optistate-admin-interface.php',
        'optistate_activation'           => 'class-optistate-activation.php',
        'optistate_presets'              => 'class-optistate-presets.php',
        'optistate_backup_engine'        => 'class-optistate-backup-engine.php',
        'optistate_backup_utilities'     => 'class-optistate-backup-utilities.php',
        'optistate_db_wrapper'           => 'class-optistate-db-wrapper.php',
        'optistate_restore_engine'       => 'class-optistate-restore-engine.php',
        'optistate_sql_parser'           => 'class-optistate-sql-parser.php',
        'optistate_utils'                => 'class-optistate-utils.php',
    ];

    spl_autoload_register(static function ($class) use ($class_map): void {
        if (!is_string($class) || stripos($class, 'OPTISTATE') !== 0) {
            return;
        }

        $key = strtolower($class);

        if (!isset($class_map[$key])) {
            return;
        }

        $file = OPTISTATE_INCLUDES_DIR . $class_map[$key];

        if (is_readable($file)) {
            require_once $file;
        }
    });
})();

function optistate_maybe_load_textdomain(): void {
    static $loaded = false;

    if ($loaded) {
        return;
    }

    if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
        return;
    }

    $loaded = true;

    load_plugin_textdomain(
        'optistate',
        false,
        dirname(plugin_basename(OPTISTATE_PLUGIN_FILE)) . '/languages'
    );
}
add_action('init', 'optistate_maybe_load_textdomain', 1);

function optistate_activate(): void {
    optistate_maybe_load_textdomain();
    OPTISTATE_Activation::activate();
}

function optistate_deactivate(): void {
    optistate_maybe_load_textdomain();
    OPTISTATE_Activation::deactivate();
}

register_activation_hook(__FILE__, 'optistate_activate');
register_deactivation_hook(__FILE__, 'optistate_deactivate');

add_action('init', ['OPTISTATE', 'instance'], 5);

function optistate_upgrade(): void {
    if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
        return;
    }

    $current_version = (string) get_option('optistate_db_version', '0');

    if (version_compare($current_version, OPTISTATE::VERSION, '>=')) {
        return;
    }

    $lock_key = 'optistate_upgrade_lock';

    if (false !== get_transient($lock_key)) {
        return;
    }

    set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

    try {
        OPTISTATE_Activation::create_core_data_table();
        update_option('optistate_db_version', OPTISTATE::VERSION, true);
    } catch (Throwable $e) {
        OPTISTATE_Utils::log_critical_error(
            'Schema upgrade failed: ' . $e->getMessage(),
            ['file' => $e->getFile(), 'line' => $e->getLine()]
        );
    } finally {
        delete_transient($lock_key);
    }
}
add_action('plugins_loaded', 'optistate_upgrade');
