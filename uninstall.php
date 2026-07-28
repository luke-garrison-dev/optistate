<?php
/**
 * WP Optimal State - Uninstall Script
 *
 * This file is executed when the plugin is deleted from the WordPress admin.
 * It removes ALL plugin data including files, folders, options, transients,
 * cron jobs, user meta, custom tables, and .htaccess rules.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function optistate_remove_htaccess_rules() {
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    if (!$wp_filesystem) {
        return;
    }

    $htaccess_path = get_home_path() . '.htaccess';

    if (!$wp_filesystem->exists($htaccess_path) || !$wp_filesystem->is_writable($htaccess_path)) {
        return;
    }

    $current_content = $wp_filesystem->get_contents($htaccess_path);
    if ($current_content === false) {
        return;
    }

    $blocks_to_remove = [
        'WP Optimal State IP Whitelist',
        'WP Optimal State IP Blocking',
        'WP Optimal State Bot Blocking',
        'WP Optimal State Caching',
        'WP Optimal State Security Headers',
    ];

    $new_content = $current_content;

    foreach ($blocks_to_remove as $block_name) {
        $pattern = '/# BEGIN ' . preg_quote($block_name, '/') . '.*?# END ' . preg_quote($block_name, '/') . '/is';
        $new_content = preg_replace($pattern, '', $new_content);
    }

    $separator = '# ============================================================';
    $new_content = preg_replace('/^' . preg_quote($separator, '/') . '\s*$\r?\n?/m', '', $new_content);

    $new_content = preg_replace("/\n{3,}/", "\n\n", trim($new_content)) . "\n";

    if (trim($new_content) !== trim($current_content)) {
        $wp_filesystem->put_contents($htaccess_path, $new_content, FS_CHMOD_FILE);
    }
}

function optistate_delete_plugin_directories() {
    $upload_dir = wp_upload_dir();

    $directories_to_delete = array(
        trailingslashit($upload_dir['basedir']) . 'optistate-settings/',
        trailingslashit($upload_dir['basedir']) . 'optistate/db-backups/',
        trailingslashit($upload_dir['basedir']) . 'optistate/db-restore-temp/',
        trailingslashit($upload_dir['basedir']) . 'optistate/page-cache/',
        trailingslashit($upload_dir['basedir']) . 'optistate/trash/',
        trailingslashit($upload_dir['basedir']) . 'optistate/'
    );

    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    foreach ($directories_to_delete as $directory) {
        if ($wp_filesystem && $wp_filesystem->is_dir($directory)) {
            $wp_filesystem->delete($directory, true);
        }
    }
}

function optistate_delete_all_options() {
    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            'optistate_%'
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_optistate_%',
            '_transient_timeout_optistate_%'
        )
    );
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_site_transient_optistate_%',
            '_site_transient_timeout_optistate_%'
        )
    );
}

function optistate_delete_user_meta() {
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            'optistate_%'
        )
    );
}

function optistate_drop_custom_tables() {
    global $wpdb;

    $tables_to_drop = [];

    $tables_to_drop[] = $wpdb->prefix . 'optistate_processes';
    $tables_to_drop[] = $wpdb->prefix . 'optistate_backup_metadata';
    $tables_to_drop[] = $wpdb->prefix . 'optistate_login_protect';
    $tables_to_drop[] = $wpdb->prefix . 'optistate_core_data';
    $tables_to_drop[] = $wpdb->prefix . 'optistate_trash';

    $stray_tables = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES 
             WHERE TABLE_SCHEMA = %s 
             AND (TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s)",
            DB_NAME,
            $wpdb->esc_like($wpdb->prefix . 'optistate_old_') . '%',
            $wpdb->esc_like($wpdb->prefix . 'optistate_temp_') . '%',
            $wpdb->esc_like($wpdb->prefix . 'optistate_trash_table_') . '%'
        )
    );

    if (!empty($stray_tables)) {
        $tables_to_drop = array_merge($tables_to_drop, $stray_tables);
    }
	
    $tables_to_drop = array_unique($tables_to_drop);
    $safe_tables = array_map('esc_sql', $tables_to_drop);
    $safe_tables = array_filter($safe_tables, function($table) {
        return preg_match('/^[a-zA-Z0-9_]+$/', $table);
    });

    if (!empty($safe_tables)) {
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($safe_tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function optistate_clear_all_cron_events() {
    $cron = _get_cron_array();
    if (empty($cron)) {
        return;
    }

    foreach ($cron as $timestamp => $hooks) {
        foreach ($hooks as $hook => $events) {
            if (strpos($hook, 'optistate_') === 0) {
                foreach ($events as $key => $event) {
                    wp_unschedule_event($timestamp, $hook, $event['args'] ?? array());
                }
            }
        }
    }
}

function optistate_uninstall_current_site() {

    optistate_delete_plugin_directories();

    optistate_delete_all_options();

    optistate_drop_custom_tables();

    optistate_clear_all_cron_events();
}

function optistate_uninstall() {

    optistate_remove_htaccess_rules();

    if (is_multisite()) {
        $batch_size = 100;
        $offset = 0;

        do {
            $site_ids = get_sites(array(
                'fields'                 => 'ids',
                'number'                 => $batch_size,
                'offset'                 => $offset,
                'orderby'                => 'id',
                'update_site_cache'      => false,
                'update_site_meta_cache' => false,
            ));

            foreach ($site_ids as $site_id) {
                switch_to_blog((int) $site_id);
                optistate_uninstall_current_site();
                restore_current_blog();
            }

            $offset += $batch_size;
        } while (count($site_ids) === $batch_size);
    } else {
        optistate_uninstall_current_site();
    }

    optistate_delete_user_meta();

    wp_cache_flush();
}

optistate_uninstall();