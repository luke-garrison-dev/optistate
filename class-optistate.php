<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}
final class OPTISTATE
{
    const PLUGIN_NAME = 'WP Optimal State (Pro)';
    const VERSION     = '1.4.3';
    const OPTION_NAME          = 'optistate_settings';
    const NONCE_ACTION         = 'optistate_nonce';
    const BACKUP_NONCE_ACTION  = 'optistate_backup_nonce';
    const STATS_TRANSIENT      = 'optistate_db_metrics';
    const STATS_CACHE_DURATION = 12 * HOUR_IN_SECONDS;

    const DIR_CHECK_TRANSIENT = 'optistate_system_check';

    const DIR_CHECK_TIME = 48 * HOUR_IN_SECONDS;

    const DIR_CHECK_RETRY = 15 * MINUTE_IN_SECONDS;

    const LOG_RETENTION_LIMIT = 250;
    const DUPLICATE_SCAN_MAX_ROWS = 500000;

    const BACKUP_DIR_NAME = 'optistate/db-backups';
    const TEMP_DIR_NAME   = 'optistate/db-restore-temp';
    const CACHE_DIR_NAME  = 'optistate/page-cache';

    const TRACKING_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'gclid',
        'msclkid',
        'mc_cid',
        'mc_eid',
        '_ga',
        'ref',
        'source',
    ];

    const DEFAULT_BOT_LIST = "MJ12bot\nAhrefsBot\nSemrushBot\nDotBot\nPetalBot\nBytespider\nMauibot\nMegaIndex\nSerpstatBot\nBLEXBot\nDataForSeoBot\nAspiegelBot\nGPTBot\nClaudeBot\nMeta-ExternalAgent\nCCBot\nGrokBot\nDeepseekBot\nApplebot-Extended";

    const HTACCESS_RULES_BACKUP = [
        '# WP Optimal State - Secure Backup Directory',
        'Options -Indexes',
        '<IfModule mod_authz_core.c>',
        '  Require all denied',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        '  Order deny,allow',
        '  Deny from all',
        '</IfModule>',
    ];

    const HTACCESS_RULES_TEMP = [
        '# WP Optimal State - Secure Temp Restore Directory',
        'Options -Indexes',
        '<IfModule mod_authz_core.c>',
        '  Require all denied',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        '  Order deny,allow',
        '  Deny from all',
        '</IfModule>',
    ];

    const HTACCESS_RULES_TRASH = [
        '# WP Optimal State - Secure Trash Directory',
        'Options -Indexes',
        '<IfModule mod_authz_core.c>',
        '  Require all denied',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        '  Order deny,allow',
        '  Deny from all',
        '</IfModule>',
    ];

    const HTACCESS_RULES_CACHE = [
        '# WP Optimal State - Secure Cache Directory',
        'Options -Indexes',
        '<IfModule mod_authz_core.c>',
        '  Require all denied',
        '  <FilesMatch "\.html$">',
        '    Require all granted',
        '  </FilesMatch>',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        '  Order deny,allow',
        '  Deny from all',
        '  <FilesMatch "\.html$">',
        '    Allow from all',
        '  </FilesMatch>',
        '</IfModule>',
    ];
    private const CACHE_KEYS_STATS = [
        'optistate_stats_cache',
        self::STATS_TRANSIENT,
        'optistate_health_score',
        'optistate_site_size_factor',
        'optistate_config_constants',
        'optistate_stats_heavy_v1',
        'optistate_system_stats_v2',
        'optistate_upload_folder_size',
    ];

    private const CACHE_KEYS_GLOBAL = [
        'optistate_db_size_cache',
    ];

    private const LAZY_SERVICES = [
        'process_store',
        'advanced_tools',
        'cleanup_functions',
        'search_replace_engine',
        'legacy_scanner',
        'trash_manager',
        'performance_audit',
        'health_score',
        'db_backup_manager',
        'server_caching',
        'login_protection',
        'two_factor',
    ];

    private array $services = [];

    public ?OPTISTATE_Settings_Manager $settings_manager = null;
    public ?OPTISTATE_Performance_Manager $performance_manager = null;

    private ?OPTISTATE_Admin_Interface $admin_interface = null;

    private ?string $mysql_version_cache = null;
    private array $directory_check_cache = [];

    public ?WP_Filesystem_Base $wp_filesystem = null;

    public string $backup_dir = '';
    public string $temp_dir   = '';
    public string $cache_dir  = '';

    private ?array $upload_dir_info = null;
    private ?string $init_error     = null;

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {

        if (is_admin()) {
            add_action('admin_notices', [$this, 'display_init_error_notice']);
        }

        try {

            $this->settings_manager    = new OPTISTATE_Settings_Manager($this);
            $this->performance_manager = new OPTISTATE_Performance_Manager($this);

            $this->resolve_directories();

            $settings = $this->settings_manager->get_persistent_settings();

            $is_admin_context = is_admin()
                || wp_doing_cron()
                || (defined('WP_CLI') && WP_CLI);

            $this->boot_server_caching($settings);
            $this->boot_login_protection($settings);
            if (!empty($settings['enable_two_factor']) || $is_admin_context) {
                $this->get_service('two_factor');
            }

            if (is_admin()) {
                $this->init_admin();
            }

if ($is_admin_context) {
    $this->register_ajax_handlers();
    $this->maybe_verify_directories();
    $this->instantiate_admin_services();
}

            $this->performance_manager->apply_performance_optimizations();
            $this->register_core_hooks();

            if (get_option('optistate_maintenance_mode_active')) {
                add_action('template_redirect', [$this, 'show_maintenance_page_for_visitors'], 1);
            }
        } catch (Throwable $e) {
            $this->init_error = $e->getMessage();

            OPTISTATE_Utils::log_critical_error(
                'OPTISTATE constructor failed: ' . $e->getMessage(),
                [
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }
private function instantiate_admin_services(): void {
    $services = [
        'db_backup_manager',
        'advanced_tools',
        'login_protection',
        'cleanup_functions',
        'legacy_scanner',
        'trash_manager',
        'performance_audit',
        'health_score',
        'search_replace_engine',
    ];
    foreach ($services as $service) {
        $this->get_service($service);
    }
}

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new RuntimeException('OPTISTATE is a singleton and cannot be unserialized.');
    }

    public function has_fatal_error(): bool
    {
        return $this->init_error !== null;
    }

    public function get_fatal_error(): ?string
    {
        return $this->init_error;
    }
    public function get_upload_basedir(): string
    {
        if (!is_array($this->upload_dir_info) || empty($this->upload_dir_info['basedir'])) {
            return '';
        }
 
        return trailingslashit((string) $this->upload_dir_info['basedir']);
    }
    private function resolve_directories(): void
    {
        $this->upload_dir_info = wp_upload_dir(null, false);

        if (!is_array($this->upload_dir_info) || empty($this->upload_dir_info['basedir']) || !is_string($this->upload_dir_info['basedir'])) {
            $reason = is_array($this->upload_dir_info) && !empty($this->upload_dir_info['error'])
                ? (string) $this->upload_dir_info['error']
                : __('The WordPress uploads directory could not be resolved.', 'optistate');

            throw new RuntimeException($reason);
        }

        $base_dir = $this->get_upload_basedir();

        $this->backup_dir = $base_dir . self::BACKUP_DIR_NAME . '/';
        $this->temp_dir   = $base_dir . self::TEMP_DIR_NAME . '/';
        $this->cache_dir  = $base_dir . self::CACHE_DIR_NAME . '/';
    }

    private function boot_server_caching(array $settings): void
    {
        if (empty($settings['performance_features']['server_caching']['enabled'])) {
            return;
        }

        $caching = $this->get_service('server_caching');

        if ($caching instanceof OPTISTATE_Server_Caching) {
            $caching->early_cache_check();
            $caching->maybe_register_hooks();
        }
    }

    private function boot_login_protection(array $settings): void
    {
        $required = !empty($settings['login_protect_enabled'])
            || !empty($settings['ip_blocker_enabled'])
            || !empty($settings['login_captcha_enabled']);

        if (!$required) {
            return;
        }

        $protection = $this->get_service('login_protection');

        if ($protection instanceof OPTISTATE_Login_Protection) {
            $protection->init_hooks();
        }
    }

    private function init_admin(): void
    {
        $this->admin_interface = new OPTISTATE_Admin_Interface($this);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_notices', [$this, 'display_permission_warnings']);
        add_action('admin_notices', [$this, 'display_restore_completion_notice']);
        add_action('init', [$this->settings_manager, 'handle_settings_download']);
    }

    public function get_service(string $name)
    {
        if (array_key_exists($name, $this->services)) {
            return $this->services[$name];
        }

        if (!in_array($name, self::LAZY_SERVICES, true)) {
            return null;
        }

        $this->services[$name] = null;

        try {
            $this->services[$name] = $this->create_service($name);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                sprintf('Failed to construct service "%s": %s', $name, $e->getMessage()),
                ['file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }

        return $this->services[$name];
    }

    private function create_service(string $name)
    {
        switch ($name) {
            case 'process_store':
                return new OPTISTATE_Process_Store();

            case 'advanced_tools':
                return new OPTISTATE_Advanced_Tools($this, $this->get_service('process_store'));

            case 'cleanup_functions':
                return new OPTISTATE_Cleanup_Functions($this);

            case 'search_replace_engine':
                return new OPTISTATE_Search_Replace($this);

            case 'legacy_scanner':
                return new OPTISTATE_Legacy_Scanner($this);

            case 'trash_manager':
                return new OPTISTATE_Trash_Manager($this);

            case 'performance_audit':
                return new OPTISTATE_Performance_Audit($this);

            case 'health_score':
                return new OPTISTATE_Health_Score($this);

            case 'server_caching':
                return new OPTISTATE_Server_Caching($this);

            case 'login_protection':
                return new OPTISTATE_Login_Protection($this);

            case 'db_backup_manager':
                $settings = $this->settings_manager->get_persistent_settings();

                return new OPTISTATE_Backup_Manager(
                    $this,
                    (int) ($settings['max_backups'] ?? 3),
                    $this->get_service('process_store')
                );

            case 'two_factor':
                $settings = $this->settings_manager->get_persistent_settings();

                return new OPTISTATE_TwoFactor($this, !empty($settings['enable_two_factor']));
        }

        return null;
    }

    public function __get(string $name)
    {
        if (in_array($name, self::LAZY_SERVICES, true)) {
            return $this->get_service($name);
        }

        trigger_error(
            sprintf('Undefined property OPTISTATE::$%s', esc_html($name)),
            E_USER_NOTICE
        );

        return null;
    }

    public function __isset(string $name): bool
    {
        return in_array($name, self::LAZY_SERVICES, true)
            && $this->get_service($name) !== null;
    }
    
    private function register_core_hooks(): void
    {
        add_action('optistate_async_backup_complete', [$this, 'execute_post_backup_tasks']);
        add_action('optistate_scheduled_cleanup', [$this, 'run_scheduled_cleanup']);

add_action('optistate_run_pagespeed_worker', function ($task_id = null): void {
    if (!is_string($task_id) || $task_id === '') {
        return;
    }

    $service = $this->get_service('performance_audit');

    if ($service) {
        $service->run_pagespeed_worker($task_id);
    }
});

        add_action('optistate_hourly_cleanup', function (): void {
            $service = $this->get_service('login_protection');

            if ($service) {
                $service->cleanup_login_records();
            }
        });

        add_action('admin_init', [$this, 'maybe_reschedule_cron']);
        add_action('deleted_user', [$this->settings_manager, 'cleanup_deleted_user_from_access_list']);
    }

    private function register_ajax_handlers(): void
    {
        add_action('wp_ajax_optistate_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_optistate_get_optimization_log', [$this, 'ajax_get_optimization_log']);
        add_action('wp_ajax_optistate_purge_page_cache', [$this, 'ajax_purge_page_cache']);
        add_action('wp_ajax_optistate_get_cache_stats', [$this, 'ajax_get_cache_stats']);
        add_action('wp_ajax_optistate_start_preload', [$this, 'ajax_start_preload']);
        add_action('wp_ajax_optistate_stop_preload', [$this, 'ajax_stop_preload']);
        add_action('wp_ajax_optistate_get_preload_status', [$this, 'ajax_get_preload_status']);
        add_action('wp_ajax_optistate_download_error_log', [$this, 'ajax_download_error_log']);
        add_action('wp_ajax_optistate_download_activity_log', [$this, 'ajax_download_activity_log']);
        add_action('wp_ajax_optistate_download_htaccess', [$this, 'ajax_download_htaccess']);
        add_action('wp_ajax_optistate_apply_preset', [$this, 'ajax_apply_preset']);
        add_action('wp_ajax_optistate_save_max_backups', [$this->settings_manager, 'ajax_save_max_backups']);
        add_action('wp_ajax_optistate_save_auto_settings', [$this->settings_manager, 'ajax_save_auto_settings']);
        add_action('wp_ajax_optistate_export_settings', [$this->settings_manager, 'ajax_export_settings']);
        add_action('wp_ajax_optistate_import_settings', [$this->settings_manager, 'ajax_import_settings']);
        add_action('wp_ajax_optistate_save_user_access', [$this->settings_manager, 'ajax_save_user_access']);
        add_action('wp_ajax_optistate_save_one_click_extra_items', [$this->settings_manager, 'ajax_save_one_click_extra_items']);
        add_action('wp_ajax_optistate_get_performance_features', [$this->performance_manager, 'ajax_get_performance_features']);
        add_action('wp_ajax_optistate_save_performance_features', [$this->performance_manager, 'ajax_save_performance_features']);
        add_action('wp_ajax_optistate_check_htaccess_status', [$this->performance_manager, 'ajax_check_htaccess_status']);
        add_action('wp_ajax_optistate_cron_manager_action', [$this->performance_manager, 'ajax_cron_manager_action']);

    }

    private function get_dynamic_cache_keys(): array
    {
        $db_hash = md5(DB_NAME);

        return [
            'optistate_table_analysis_' . $db_hash,
            'optistate_index_analysis_' . $db_hash,
            'optistate_backup_list_' . DB_NAME,
        ];
    }

    public function clear_stats_cache(): void
    {
        foreach (self::CACHE_KEYS_STATS as $key) {
            delete_transient($key);
            wp_cache_delete($key, 'optistate');
        }

        wp_cache_delete(self::STATS_TRANSIENT, 'optistate');
        wp_cache_delete('optistate_table_analysis_' . md5(DB_NAME), 'optistate');
    }

    public function invalidate_plugin_caches(): void
    {
        $this->clear_stats_cache();

        $keys = array_merge(self::CACHE_KEYS_GLOBAL, $this->get_dynamic_cache_keys());

        foreach ($keys as $key) {
            delete_transient($key);
            wp_cache_delete($key, 'optistate');
        }

        OPTISTATE_Utils::invalidate_table_cache();
        $this->clear_directory_existence_cache();

        wp_cache_delete('alloptions', 'options');

        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('optistate_processes');
        }
    }

    public function get_filesystem(): WP_Filesystem_Base
    {
        if ($this->wp_filesystem instanceof WP_Filesystem_Base) {
            return $this->wp_filesystem;
        }

        $fs = $this->init_wp_filesystem();

        if ($fs instanceof WP_Filesystem_Base) {
            return $fs;
        }

        throw new RuntimeException('Unable to initialize filesystem.');
    }

    public function init_wp_filesystem(): ?WP_Filesystem_Base
    {
        if ($this->wp_filesystem instanceof WP_Filesystem_Base) {
            return $this->wp_filesystem;
        }

        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base) {
            $this->wp_filesystem = $wp_filesystem;

            return $this->wp_filesystem;
        }

        if (!class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }

        if (class_exists('WP_Filesystem_Direct')) {
            $this->wp_filesystem = new WP_Filesystem_Direct(null);

            return $this->wp_filesystem;
        }

        OPTISTATE_Utils::log_critical_error('WP_Filesystem initialization failed');

        return null;
    }

    public function clear_directory_existence_cache(?string $path = null): void
    {
        $this->directory_check_cache = [];

        if ($path !== null) {
            delete_transient('optistate_dir_exists_' . md5($path));

            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_optistate_dir_exists_') . '%',
                $wpdb->esc_like('_transient_timeout_optistate_dir_exists_') . '%'
            )
        );

        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('options');
        } else {
            wp_cache_delete('alloptions', 'options');
        }
    }

    public function ensure_directory(string $path, int $permissions = 0755, ?array $htaccess_rules = null): bool
    {
        $cache_key = md5($path . '|' . $permissions . '|' . serialize($htaccess_rules));

        if (isset($this->directory_check_cache[$cache_key])) {
            return $this->directory_check_cache[$cache_key];
        }

        try {
            $fs = $this->get_filesystem();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'ensure_directory could not obtain a filesystem: ' . $e->getMessage(),
                ['path' => $path]
            );

            return $this->directory_check_cache[$cache_key] = false;
        }

        if (!$fs->is_dir($path)) {
            if (!wp_mkdir_p($path)) {
                OPTISTATE_Utils::log_critical_error('Failed to create directory', ['path' => $path]);

                return $this->directory_check_cache[$cache_key] = false;
            }

            $fs->chmod($path, $permissions);
        }

        $secured = true;

        if (is_array($htaccess_rules)) {

            $secured = $this->secure_directory($path, $htaccess_rules);
        }

        return $this->directory_check_cache[$cache_key] = $secured;
    }

    private function maybe_verify_directories(): void
    {
        if (false !== get_transient(self::DIR_CHECK_TRANSIENT)) {
            return;
        }

        $ok = $this->ensure_directories_exist();

        set_transient(
            self::DIR_CHECK_TRANSIENT,
            $ok ? 'ok' : 'fail',
            $ok ? self::DIR_CHECK_TIME : self::DIR_CHECK_RETRY
        );
    }

    private function ensure_directories_exist(): bool
    {

        $backup = $this->ensure_directory($this->backup_dir, 0755, self::HTACCESS_RULES_BACKUP);
        $temp   = $this->ensure_directory($this->temp_dir, 0750, self::HTACCESS_RULES_TEMP);
        $cache  = $this->ensure_directory($this->cache_dir, 0755, self::HTACCESS_RULES_CACHE);

        return $backup && $temp && $cache;
    }

    public function secure_directory(string $dir_path, array $htaccess_rules): bool
    {
        try {
            $fs = $this->get_filesystem();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'secure_directory could not obtain a filesystem: ' . $e->getMessage(),
                ['dir' => $dir_path]
            );

            return false;
        }

        if (!$fs->is_dir($dir_path)) {
            return false;
        }

        $dir_path = trailingslashit($dir_path);

        $htaccess_file = $dir_path . '.htaccess';

        if (!$fs->exists($htaccess_file)) {
            $written = $fs->put_contents(
                $htaccess_file,
                implode(PHP_EOL, $htaccess_rules) . PHP_EOL,
                FS_CHMOD_FILE
            );

            if ($written === false) {
                OPTISTATE_Utils::log_critical_error(
                    'Failed to write .htaccess in secure_directory',
                    ['file' => $htaccess_file, 'dir' => $dir_path]
                );

                return false;
            }
        }

        $index_file = $dir_path . 'index.php';

        if (!$fs->exists($index_file)) {
            $fs->put_contents(
                $index_file,
                "<?php\n// Silence is golden\n// WP Optimal State Secure Directory\nhttp_response_code(403);\nexit;\n",
                FS_CHMOD_FILE
            );
        }

        $index_html = $dir_path . 'index.html';

        if (!$fs->exists($index_html)) {
            $fs->put_contents(
                $index_html,
                '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Access Denied</h1></body></html>',
                FS_CHMOD_FILE
            );
        }

        return true;
    }

    private function check_required_permissions()
    {
        $issues = [];

        try {
            $fs = $this->get_filesystem();
        } catch (Throwable $e) {
            return [__('WP_Filesystem is not initialized. File operations cannot proceed.', 'optistate')];
        }

        $targets = [
            $this->backup_dir => __('Backup directory is not writable. Database backups cannot be created.', 'optistate'),
            $this->temp_dir   => __('Temporary restore directory is not writable. Database restores cannot run.', 'optistate'),
            $this->cache_dir  => __('Page cache directory is not writable. Cached pages cannot be stored.', 'optistate'),
        ];

        foreach ($targets as $dir => $unwritable_message) {
            if ($dir === '') {
                continue;
            }

            if (!$fs->is_dir($dir)) {
                if (!wp_mkdir_p($dir)) {
                    $issues[] = sprintf(

                        __('Directory could not be created: %s', 'optistate'),
                        $dir
                    );
                }

                continue;
            }

            if (!$fs->is_writable($dir)) {
                $issues[] = $unwritable_message;
            }
        }

        return empty($issues) ? true : $issues;
    }

    private function get_store_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'optistate_core_data';
    }

    public function get_store_data(string $key, $default = null)
    {
        global $wpdb;

        $table = OPTISTATE_Utils::escape_identifier($this->get_store_table());

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT data_value FROM {$table} WHERE data_key = %s", $key)
        );

        if (!$row) {
            return $default;
        }

        $data = json_decode($row->data_value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            OPTISTATE_Utils::log_critical_error(
                'JSON decode error in get_store_data',
                ['key' => $key, 'error' => json_last_error_msg()]
            );

            return $default;
        }

        return $data;
    }

    public function set_store_data(string $key, $data): bool
    {
        global $wpdb;

        $json = wp_json_encode($data);

        if ($json === false) {
            OPTISTATE_Utils::log_critical_error('JSON encode error in set_store_data', ['key' => $key]);

            return false;
        }

        $table = OPTISTATE_Utils::escape_identifier($this->get_store_table());

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (data_key, data_value, updated_at)
                 VALUES (%s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                     data_value = VALUES(data_value),
                     updated_at = VALUES(updated_at)",
                $key,
                $json,
                current_time('mysql')
            )
        );

        if ($result === false) {
            OPTISTATE_Utils::log_critical_error(
                'Failed to set store data',
                ['key' => $key, 'error' => $wpdb->last_error]
            );

            return false;
        }

        return true;
    }

    public function delete_store_data(string $key): bool
    {
        global $wpdb;

        $result = $wpdb->delete($this->get_store_table(), ['data_key' => $key], ['%s']);

        if ($result === false) {
            OPTISTATE_Utils::log_critical_error(
                'Failed to delete store data',
                ['key' => $key, 'error' => $wpdb->last_error]
            );

            return false;
        }

        return true;
    }

    public function recreate_core_data_table(): void
    {

        OPTISTATE_Utils::clear_table_existence_cache($this->get_store_table());
        OPTISTATE_Settings_Manager::reset_table_cache();

        OPTISTATE_Activation::create_core_data_table();
    }

    public function log_entry(
        string $operation,
        string $type = 'manual',
        string $backup_filename = '',
        array $extra_data = []
    ): bool {
        if ($operation === '') {
            return false;
        }

        $username = $this->resolve_log_username($extra_data);

        if (strpos($operation, '{username}') !== false) {
            $operation = str_replace('{username}', $username, $operation);
        }

        $operation = wp_strip_all_tags($operation);

        $type = in_array($type, ['manual', 'scheduled', 'error'], true) ? $type : 'manual';

        $is_failure = $type === 'error' || !empty($extra_data['is_failure']);

        $suffix = __('(check error logs)', 'optistate');

        if ($is_failure && strpos($operation, $suffix) === false) {
            $operation .= ' ' . $suffix;
        }

        $timestamp = microtime(true);

        static $request_id = null;

        if ($request_id === null) {
            $request_id = uniqid('req_', true);
        }

        $log_entry = [
            'timestamp'  => $timestamp,
            'type'       => $type,
            'date'       => wp_date(
                OPTISTATE_Utils::get_cached_option('date_format') . ' ' . OPTISTATE_Utils::get_cached_option('time_format'),
                (int) $timestamp
            ),

            'operation'  => $operation,
            'user'       => wp_strip_all_tags($username),
            'request_id' => $request_id,
        ];

        if ($type === 'error' && isset($extra_data['error_code'])) {
            $log_entry['error_code'] = sanitize_text_field((string) $extra_data['error_code']);
        }

        if (isset($extra_data['details'])) {
            $log_entry['details'] = is_scalar($extra_data['details'])
                ? wp_strip_all_tags((string) $extra_data['details'])
                : array_map(
                    static function ($value) {
                        return is_scalar($value) ? wp_strip_all_tags((string) $value) : '';
                    },
                    (array) $extra_data['details']
                );
        }

        if ($backup_filename !== '') {
            $log_entry['backup_filename'] = basename($backup_filename);

            $size = $this->get_backup_file_size($backup_filename);

            if ($size !== null) {
                $log_entry['file_size'] = $size;
            }
        }

        $log_key = sprintf(
            'log_%.6F_%s%s',
            $timestamp,
            uniqid('', true),
            wp_generate_password(6, false, false)
        );

        $result = $this->set_store_data($log_key, $log_entry);

        if ($result === false && $this->store_table_is_missing()) {
            $this->recreate_core_data_table();
            $result = $this->set_store_data($log_key, $log_entry);
        }

        $this->maybe_prune_log();

        return $result;
    }

    private function resolve_log_username(array $extra_data): string
    {
        if (!empty($extra_data['user_id'])) {
            $user = get_userdata((int) $extra_data['user_id']);

            if ($user) {
                return $user->display_name !== '' ? $user->display_name : $user->user_login;
            }

            return 'the system';
        }

        $current_user = wp_get_current_user();

        if ($current_user && $current_user->exists()) {
            return $current_user->display_name !== '' ? $current_user->display_name : $current_user->user_login;
        }

        return 'the system';
    }

    private function get_backup_file_size(string $backup_filename): ?int
    {
        try {
            $fs = $this->get_filesystem();
        } catch (Throwable $e) {
            return null;
        }

        $full_path = trailingslashit($this->backup_dir) . basename($backup_filename);

        if (!$fs->exists($full_path)) {
            return null;
        }

        $size = $fs->size($full_path);

        return is_int($size) ? $size : null;
    }

    private function store_table_is_missing(): bool
    {
        global $wpdb;

        if (isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli && $wpdb->dbh->errno === 1146) {
            return true;
        }

        return is_string($wpdb->last_error) && strpos($wpdb->last_error, "doesn't exist") !== false;
    }
    private function maybe_prune_log(): void
    {
        $prune_key = 'optistate_last_log_prune';

        if (false !== get_transient($prune_key)) {
            return;
        }

        set_transient($prune_key, 1, 2 * HOUR_IN_SECONDS);

        global $wpdb;

        $table = OPTISTATE_Utils::escape_identifier($this->get_store_table());
        $like  = $wpdb->esc_like('log_') . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE data_key LIKE %s
                   AND data_key NOT IN (
                       SELECT data_key FROM (
                           SELECT data_key
                           FROM {$table}
                           WHERE data_key LIKE %s
                           ORDER BY updated_at DESC, data_key DESC
                           LIMIT %d
                       ) AS keep_set
                   )",
                $like,
                $like,
                self::LOG_RETENTION_LIMIT
            )
        );
    }

    public function get_optimization_log(): array
    {
        global $wpdb;

        $table = OPTISTATE_Utils::escape_identifier($this->get_store_table());
        $like  = $wpdb->esc_like('log_') . '%';

        $sql = $wpdb->prepare(
            "SELECT data_value
             FROM {$table}
             WHERE data_key LIKE %s
             ORDER BY updated_at DESC, data_key DESC
             LIMIT %d",
            $like,
            self::LOG_RETENTION_LIMIT
        );

        $suppress = $wpdb->suppress_errors(true);

        $rows = $wpdb->get_results($sql);

        if (!empty($wpdb->last_error) && !OPTISTATE_Utils::table_exists($this->get_store_table())) {
            $this->recreate_core_data_table();
            $rows = $wpdb->get_results($sql);
        }

        $wpdb->suppress_errors($suppress);

        if (empty($rows)) {
            return [];
        }

        $log_entries = [];

        foreach ($rows as $row) {
            $decoded = json_decode($row->data_value, true);

            if (!is_array($decoded)) {
                continue;
            }

            if (!isset($decoded['timestamp']) || !is_numeric($decoded['timestamp'])) {
                $decoded['timestamp'] = 0;
            }

            $log_entries[] = $decoded;
        }

        return $log_entries;
    }

    private function get_mysql_version(): string
    {
        if ($this->mysql_version_cache === null) {
            global $wpdb;

            $this->mysql_version_cache = (string) $wpdb->get_var('SELECT VERSION()');
        }

        return $this->mysql_version_cache;
    }

    public function get_total_database_size(bool $force_refresh = false): float
    {
        if (!$force_refresh) {
            $full_stats = get_transient(self::STATS_TRANSIENT);

            if (is_array($full_stats) && isset($full_stats['total_db_size_bytes'])) {
                return (float) $full_stats['total_db_size_bytes'];
            }
        }

        global $wpdb;

        $collect = static function () use ($wpdb): float {
            $query = $wpdb->prepare(
                "SELECT /*+ MAX_EXECUTION_TIME(60000) */ SUM(data_length + index_length)
                 FROM information_schema.TABLES
                 WHERE table_schema = %s AND table_type = 'BASE TABLE'",
                DB_NAME
            );

            return (float) $wpdb->get_var($query);
        };

        $total_db_size = $force_refresh
            ? (float) OPTISTATE_Utils::with_stats_expiry_disabled($collect)
            : $collect();

        if ($total_db_size === 0.0) {
            $tables = $wpdb->get_results(
                'SHOW TABLE STATUS FROM ' . OPTISTATE_Utils::escape_identifier(DB_NAME)
            );

            if ($tables) {
                foreach ($tables as $table) {
                    $total_db_size += (float) $table->Data_length + (float) $table->Index_length;
                }
            }
        }

        return $total_db_size;
    }

    private function get_system_statistics(bool $force_refresh = false): array
    {
        return OPTISTATE_Utils::get_or_set_transient(
            'optistate_system_stats_v2',
            function (): array {
                $stats = [];

                $stats['server_type'] = OPTISTATE_Utils::detect_server_type();
                $stats['os']          = PHP_OS . ' ' . php_uname('r');

                $this->collect_shell_statistics($stats);
                $this->collect_disk_statistics($stats);

                $stats['mysql_version']    = $this->get_mysql_version() ?: 'N/A';
                $stats['php_version']      = PHP_VERSION;
                $stats['wp_version']       = get_bloginfo('version');

                $wp_memory_limit                = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : '40M';
                $stats['wp_memory_limit']       = $wp_memory_limit;
                $stats['wp_memory_limit_bytes'] = (int) wp_convert_hr_to_bytes((string) $wp_memory_limit);
                $stats['php_memory_limit']      = (int) wp_convert_hr_to_bytes((string) ini_get('memory_limit'));

                $current_theme         = wp_get_theme();
                $stats['active_theme'] = $current_theme->get('Name') ?: $current_theme->get_stylesheet();

                $active_plugins = get_option('active_plugins', []);

                if (is_multisite()) {
                    $active_plugins = array_merge(
                        $active_plugins,
                        array_keys(get_site_option('active_sitewide_plugins', []))
                    );
                }

                $stats['active_plugins_count'] = count($active_plugins);
                $stats['total_ram']            = $this->detect_total_ram();
                $stats['upload_folder_size']   = $this->get_upload_folder_size();
                $stats['error_logging']        = $this->get_error_logging_status();
                $stats['htaccess_info']        = $this->get_htaccess_info();

                $stats['persistent_cache_status'] = wp_using_ext_object_cache()
                    ? __('Enabled', 'optistate')
                    : __('Disabled', 'optistate');

                return apply_filters('optistate_system_stats', $stats);
            },
            self::STATS_CACHE_DURATION,
            $force_refresh
        );
    }

    private function collect_shell_statistics(array &$stats): void
    {
        if (!OPTISTATE_Utils::is_function_available('shell_exec')) {
            return;
        }

        $timeout = @is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 3 ' : '';

        $combined = @shell_exec(
            'cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d= -f2 | tr -d "\""; '
            . $timeout . 'quota -uw 2>/dev/null | tail -1; '
            . $timeout . 'df -B1 ' . escapeshellarg(ABSPATH) . ' 2>/dev/null | tail -1'
        );

        if ($combined === null) {
            return;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $combined))));

        if (empty($lines)) {
            return;
        }

        $first = $lines[0];

        if (strpos($first, '=') === false && !preg_match('/^\d/', $first) && $first !== '') {
            $stats['os'] = trim($first, '"');
        }

        foreach ($lines as $line) {

            if (preg_match('/^\s*\S+\s+(\d+)\s+(\d+)\s+(\d+)\s+\d+%\s+\S/', $line, $matches)) {
                $stats['disk_total'] = (int) $matches[1];
                $stats['disk_free']  = (int) $matches[3];

                continue;
            }

            if (preg_match('/^\s*\S+\s+(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $matches)) {
                $used  = (int) $matches[1];
                $quota = (int) $matches[2] > 0 ? (int) $matches[2] : (int) $matches[3];

                if ($quota > 0) {
                    $stats['disk_total'] = $quota * 1024;
                    $stats['disk_free']  = max(0, ($quota - $used) * 1024);
                }
            }
        }
    }

    private function collect_disk_statistics(array &$stats): void
    {
        if (!isset($stats['disk_total'], $stats['disk_free'])) {
            foreach ([WP_CONTENT_DIR, ABSPATH] as $path) {
                if (!is_dir($path)) {
                    continue;
                }

                $total = @disk_total_space($path);
                $free  = @disk_free_space($path);

                if ($total !== false && $free !== false) {
                    $stats['disk_total'] = (int) $total;
                    $stats['disk_free']  = (int) $free;

                    break;
                }
            }
        }

        if (!isset($stats['disk_total']) || $stats['disk_total'] <= 0) {
            $stats['disk_total'] = 0;
            $stats['disk_free']  = 0;
            $stats['disk_used']  = 0;

            return;
        }

        $stats['disk_free'] = max(0, (int) $stats['disk_free']);
        $stats['disk_used'] = max(0, (int) $stats['disk_total'] - (int) $stats['disk_free']);
    }

    private function detect_total_ram(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 0;
        }

        $ram_bytes = 0;

        if (is_readable('/sys/fs/cgroup/memory.max')) {
            $cgroup_max = trim((string) @file_get_contents('/sys/fs/cgroup/memory.max'));

            if (is_numeric($cgroup_max) && (int) $cgroup_max > 0) {
                $ram_bytes = (int) $cgroup_max;
            }
        }

        if ($ram_bytes === 0 && is_readable('/sys/fs/cgroup/memory/memory.limit_in_bytes')) {
            $cgroup_limit = trim((string) @file_get_contents('/sys/fs/cgroup/memory/memory.limit_in_bytes'));

            if (is_numeric($cgroup_limit) && (int) $cgroup_limit > 0 && (int) $cgroup_limit < 9223372036854771712) {
                $ram_bytes = (int) $cgroup_limit;
            }
        }

        if ($ram_bytes === 0 && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');

            if ($meminfo !== false && preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $meminfo, $matches)) {
                $ram_bytes = (int) $matches[1] * 1024;
            }
        }

        if ($ram_bytes > 0 && $ram_bytes < 1024 * 1024 * 1024 * 1024) {
            return $ram_bytes;
        }

        return 0;
    }

    private function get_htaccess_info(): array
    {
        $info = $this->performance_manager->get_htaccess_info();

        return [
            'path'            => $info['path'],
            'exists'          => $info['exists'],
            'size'            => $info['size'],
            'mtime'           => $info['mtime'],
            'writable'        => $info['writable'],
            'size_formatted'  => size_format($info['size'], 2),
            'mtime_formatted' => $info['mtime']
                ? OPTISTATE_Utils::format_timestamp($info['mtime'])
                : __('Unknown', 'optistate'),
        ];
    }

    private function get_upload_folder_size(): string
    {
        $upload_path = $this->get_upload_basedir();
 
        return OPTISTATE_Utils::get_or_set_transient(
            'optistate_upload_folder_size',
            static function () use ($upload_path): string {
                if ($upload_path === '' || !is_dir($upload_path)) {
                    return __('N/A', 'optistate');
                }
 
                $size_bytes = 0;
 
                if (OPTISTATE_Utils::is_function_available('exec') && PHP_OS_FAMILY !== 'Windows') {
                    $timeout = @is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 3 ' : '';
 
                    $output     = [];
                    $return_var = 0;
 
                    exec($timeout . 'du -sb ' . escapeshellarg($upload_path) . ' 2>/dev/null', $output, $return_var);
 
                    if ($return_var === 0 && isset($output[0])) {
                        $parts = preg_split('/\s+/', trim($output[0]));
 
                        if (isset($parts[0]) && is_numeric($parts[0])) {
                            $size_bytes = (int) $parts[0];
                        }
                    }
                }
 
                if ($size_bytes === 0) {
                    $start_time = microtime(true);
 
                    $result = OPTISTATE_Utils::get_folder_size(
                        $upload_path,
                        50000,
                        5,
                        false,
                        static function () use ($start_time): bool {
                            static $iteration = 0;
 
                            if (++$iteration % 500 === 0) {
                                return microtime(true) - $start_time > 2.0;
                            }
 
                            return false;
                        }
                    );
 
                    $size_bytes = (int) $result['size'];
                }
 
                return $size_bytes > 0 ? size_format($size_bytes, 2) : __('< 1 KB', 'optistate');
            },
            self::STATS_CACHE_DURATION
        );
    }

private function get_debug_log_path(): ?string
{
    $candidates = [];

    if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
        $path = WP_DEBUG_LOG;
        if (!preg_match('#^(/|[a-zA-Z]:\\\\)#', $path)) {
            $path = ABSPATH . $path;
        }
        $candidates[] = $path;
    }

    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
        $candidates[] = WP_CONTENT_DIR . '/debug.log';
    }

    $ini_log = ini_get('error_log');
    if (is_string($ini_log) && $ini_log !== '' && $ini_log !== 'syslog' && strpos($ini_log, 'php://') !== 0) {
        $candidates[] = $ini_log;
    }

    $candidates[] = WP_CONTENT_DIR . '/debug.log';

    foreach ($candidates as $file) {
        $file = wp_normalize_path($file);

        if (file_exists($file)) {
            $real = realpath($file);
            if ($real === false) {
                continue;
            }
            $real = wp_normalize_path($real);
            if (!$this->is_path_allowed($real)) {
                continue;
            }
            return $real;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            continue;
        }

        $real_dir = realpath($dir);
        if ($real_dir === false) {
            continue;
        }
        $real_dir = wp_normalize_path($real_dir);

        if ($this->is_path_allowed($real_dir)) {
            return $file;
        }
    }

    return null;
}

private function is_path_allowed(string $path): bool
{
    $allowed_roots = [
        wp_normalize_path(ABSPATH),
        wp_normalize_path(WP_CONTENT_DIR),
    ];

    $path = rtrim($path, '/\\');
    foreach ($allowed_roots as $root) {
        $root = rtrim($root, '/\\');
        if (strpos($path, $root . '/') === 0 || $path === $root) {
            return true;
        }
    }
    return false;
}

    private function get_error_logging_status(): string
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return 'OFF';
        }

        $log_file = $this->get_debug_log_path();

        if ($log_file === null || !file_exists($log_file)) {
            return 'ON / 0 B';
        }

        $size_bytes = @filesize($log_file);

        if ($size_bytes === false || $size_bytes < 0) {
            $size_bytes = 0;
        }

        return 'ON / ' . ($size_bytes > 0 ? size_format($size_bytes, 2) : '0 B');
    }

    public function get_combined_database_statistics(bool $force_refresh = false): array
    {
        wp_raise_memory_limit('admin');

        $cache_key = self::STATS_TRANSIENT;

        if (!$force_refresh) {
            $cached_stats = get_transient($cache_key);

            if (is_array($cached_stats)) {
                if (!isset($cached_stats['system_stats'])) {
                    $cached_stats['system_stats'] = $this->get_system_statistics();
                    set_transient($cache_key, $cached_stats, self::STATS_CACHE_DURATION);
                }

                return $cached_stats;
            }
        }

        global $wpdb;

        $warnings = [];
        $suppress = $wpdb->suppress_errors(true);

        try {
            $stats = $this->get_empty_statistics();

            $this->collect_post_statistics($stats, $warnings);
            $this->collect_comment_statistics($stats, $warnings);
            $this->collect_orphan_statistics($stats, $warnings);
            $this->collect_transient_statistics($stats, $warnings);
            $this->collect_duplicate_statistics($stats, $warnings);
            $this->collect_action_scheduler_statistics($stats, $warnings);
            $this->collect_oembed_statistics($stats, $warnings);
            $this->collect_woocommerce_statistics($stats, $warnings);
            $this->collect_taxonomy_statistics($stats, $warnings);
            $this->collect_schema_statistics($stats, $warnings, $force_refresh);
            $this->collect_autoload_statistics($stats, $warnings);
            $this->collect_creation_date($stats, $warnings);

            $stats['formatted_total_size'] = size_format($stats['total_db_size_bytes'], 2);
            $stats['system_stats']         = $this->get_system_statistics($force_refresh);

            if (!empty($warnings)) {
                $stats['_warning'] = __('Some statistics could not be retrieved due to query timeouts or server limitations. Cached or partial data shown.', 'optistate');

                OPTISTATE_Utils::log_critical_error('Stats collection warnings', ['warnings' => $warnings]);
            }

            set_transient($cache_key, $stats, self::STATS_CACHE_DURATION);

            return $stats;
        } catch (Throwable $e) {
            $fallback = get_transient(self::STATS_TRANSIENT);

            if (!is_array($fallback)) {
                $fallback = $this->get_empty_statistics();
            }

            $fallback['_warning'] = __('Database statistics collection failed. Showing cached data.', 'optistate');

            OPTISTATE_Utils::log_critical_error('Fatal error in stats collection', ['message' => $e->getMessage()]);

            return $fallback;
        } finally {
            $wpdb->suppress_errors($suppress);
        }
    }

    private function get_empty_statistics(): array
    {
        return [
            'post_revisions'          => 0,
            'auto_drafts'             => 0,
            'trashed_posts'           => 0,
            'spam_comments'           => 0,
            'trashed_comments'        => 0,
            'unapproved_comments'     => 0,
            'pingbacks'               => 0,
            'trackbacks'              => 0,
            'orphaned_postmeta'       => 0,
            'orphaned_commentmeta'    => 0,
            'orphaned_relationships'  => 0,
            'orphaned_usermeta'       => 0,
            'orphaned_termmeta'       => 0,
            'expired_transients'      => 0,
            'all_transients'          => 0,
            'duplicate_postmeta'      => 0,
            'duplicate_commentmeta'   => 0,
            'duplicate_usermeta'      => 0,
            'duplicate_termmeta'      => 0,
            'duplicates_skipped'      => false,
            'action_scheduler'        => 0,
            'oembed_cache'            => 0,
            'woo_bloat'               => 0,
            'empty_taxonomies'        => 0,
            'total_tables_count'      => 0,
            'raw_table_overhead_bytes'=> 0.0,
            'total_indexes_size_bytes'=> 0.0,
            'total_db_size_bytes'     => 0.0,
            'table_overhead_bytes'    => 0.0,
            'table_overhead'          => size_format(0, 2),
            'engine_distribution'     => [],
            'index_to_data_ratio'     => 'N/A',
            'autoload_size_bytes'     => 0,
            'autoload_options'        => 0,
            'autoload_size'           => size_format(0, 2),
            'db_creation_date'        => __('Unknown', 'optistate'),
            'formatted_total_size'    => size_format(0, 2),
        ];
    }

    private function collect_post_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_type, post_status, COUNT(*) AS count
             FROM {$wpdb->posts}
             WHERE (post_type = 'revision' AND post_parent != 0)
                OR post_status IN ('auto-draft', 'trash')
             GROUP BY post_type, post_status",
            ARRAY_A
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Posts aggregates: ' . $wpdb->last_error;

            return;
        }

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $count = isset($row['count']) ? absint($row['count']) : 0;

            if (isset($row['post_type']) && $row['post_type'] === 'revision') {
                $stats['post_revisions'] += $count;
            }

            if (!isset($row['post_status'])) {
                continue;
            }

            if ($row['post_status'] === 'auto-draft') {
                $stats['auto_drafts'] += $count;
            }

            if ($row['post_status'] === 'trash') {
                $stats['trashed_posts'] += $count;
            }
        }
    }

    private function collect_comment_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT
                 SUM(CASE WHEN comment_approved = 'spam'  THEN 1 ELSE 0 END) AS spam_comments,
                 SUM(CASE WHEN comment_approved = 'trash' THEN 1 ELSE 0 END) AS trashed_comments,
                 SUM(CASE WHEN comment_approved = '0'     THEN 1 ELSE 0 END) AS unapproved_comments,
                 SUM(CASE WHEN comment_type = 'pingback'  THEN 1 ELSE 0 END) AS pingbacks,
                 SUM(CASE WHEN comment_type = 'trackback' THEN 1 ELSE 0 END) AS trackbacks
             FROM {$wpdb->comments}",
            ARRAY_A
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Comment counts: ' . $wpdb->last_error;

            return;
        }

        if (!is_array($row)) {
            return;
        }

        $stats['spam_comments']       = absint($row['spam_comments']);
        $stats['trashed_comments']    = absint($row['trashed_comments']);
        $stats['unapproved_comments'] = absint($row['unapproved_comments']);
        $stats['pingbacks']           = absint($row['pingbacks']);
        $stats['trackbacks']          = absint($row['trackbacks']);
    }

    private function collect_orphan_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT /*+ MAX_EXECUTION_TIME(30000) */
                 (SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                  WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = pm.post_id)) AS orphaned_postmeta,
                 (SELECT COUNT(*) FROM {$wpdb->commentmeta} cm
                  WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->comments} c WHERE c.comment_ID = cm.comment_id)) AS orphaned_commentmeta,
                 (SELECT COUNT(*) FROM {$wpdb->usermeta} um
                  WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = um.user_id)) AS orphaned_usermeta,
                 (SELECT COUNT(*) FROM {$wpdb->termmeta} tm
                  LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
                  WHERE t.term_id IS NULL) AS orphaned_termmeta",
            ARRAY_A
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Orphan scan: ' . $wpdb->last_error;
        } elseif (is_array($row)) {
            foreach ($row as $key => $value) {
                $stats[$key] = absint($value);
            }
        }

        $links_join       = '';
        $links_null_check = '1=1';

        if (isset($wpdb->links)) {
            $links_join       = "LEFT JOIN {$wpdb->links} l ON tr.object_id = l.link_id";
            $links_null_check = 'l.link_id IS NULL';
        }

        $relationships = $wpdb->get_var(
            "SELECT /*+ MAX_EXECUTION_TIME(60000) */ COUNT(*)
             FROM {$wpdb->term_relationships} tr
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
             LEFT JOIN {$wpdb->users} u ON tr.object_id = u.ID
             {$links_join}
             WHERE tt.term_taxonomy_id IS NULL
                OR (p.ID IS NULL AND u.ID IS NULL AND {$links_null_check})"
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Orphaned relationships: ' . $wpdb->last_error;

            return;
        }

        $stats['orphaned_relationships'] = absint($relationships);
    }

    private function collect_transient_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT /*+ MAX_EXECUTION_TIME(15000) */
                     (SELECT COUNT(*) FROM {$wpdb->options}
                      WHERE (option_name LIKE %s OR option_name LIKE %s)
                        AND option_name NOT LIKE %s
                        AND option_name NOT LIKE %s
                        AND option_value < %d) AS expired_transients,
                     (SELECT COUNT(*) FROM {$wpdb->options}
                      WHERE (option_name LIKE %s OR option_name LIKE %s)
                        AND option_name NOT LIKE %s
                        AND option_name NOT LIKE %s
                        AND option_name NOT LIKE %s
                        AND option_name NOT LIKE %s) AS all_transients",
                $wpdb->esc_like('_transient_timeout_') . '%',
                $wpdb->esc_like('_site_transient_timeout_') . '%',
                $wpdb->esc_like('_transient_timeout_wc_') . '%',
                $wpdb->esc_like('_transient_timeout_oembed_') . '%',
                time(),
                $wpdb->esc_like('_transient_') . '%',
                $wpdb->esc_like('_site_transient_') . '%',
                $wpdb->esc_like('_transient_timeout_') . '%',
                $wpdb->esc_like('_site_transient_timeout_') . '%',
                $wpdb->esc_like('_transient_wc_') . '%',
                $wpdb->esc_like('_transient_oembed_') . '%'
            ),
            ARRAY_A
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Transient counts: ' . $wpdb->last_error;

            return;
        }

        if (!is_array($row)) {
            return;
        }

        $stats['expired_transients'] = absint($row['expired_transients']);
        $stats['all_transients']     = absint($row['all_transients']);
    }

    private function collect_duplicate_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $sizes = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT TABLE_NAME, TABLE_ROWS
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s, %s, %s)',
                DB_NAME,
                $wpdb->postmeta,
                $wpdb->commentmeta,
                $wpdb->usermeta,
                $wpdb->termmeta
            ),
            OBJECT_K
        );

        $too_large = static function (string $table) use ($sizes): bool {
            return isset($sizes[$table]) && (int) $sizes[$table]->TABLE_ROWS > self::DUPLICATE_SCAN_MAX_ROWS;
        };

        $targets = [
            'duplicate_postmeta'    => [$wpdb->postmeta, 'post_id'],
            'duplicate_commentmeta' => [$wpdb->commentmeta, 'comment_id'],
            'duplicate_usermeta'    => [$wpdb->usermeta, 'user_id'],
            'duplicate_termmeta'    => [$wpdb->termmeta, 'term_id'],
        ];

        $selects = [];

        foreach ($targets as $key => [$table, $owner_column]) {
            if ($too_large($table)) {
                $stats['duplicates_skipped'] = true;

                continue;
            }

            $escaped = OPTISTATE_Utils::escape_identifier($table);

            $selects[] = "(SELECT COALESCE(SUM(cnt - 1), 0) FROM (
                    SELECT COUNT(*) AS cnt
                    FROM {$escaped}
                    WHERE meta_key != ''
                    GROUP BY {$owner_column}, meta_key, MD5(meta_value)
                    HAVING cnt > 1
                ) AS d_{$key}) AS {$key}";
        }

        if (empty($selects)) {
            return;
        }

        $row = $wpdb->get_row(
            'SELECT /*+ MAX_EXECUTION_TIME(60000) */ ' . implode(', ', $selects),
            ARRAY_A
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Duplicate meta scan: ' . $wpdb->last_error;

            return;
        }

        if (!is_array($row)) {
            return;
        }

        foreach ($row as $key => $value) {
            $stats[$key] = absint($value);
        }
    }

    private function collect_action_scheduler_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $actions_table = $wpdb->prefix . 'actionscheduler_actions';

        if (!OPTISTATE_Utils::table_exists($actions_table)) {
            return;
        }

        $actions = OPTISTATE_Utils::escape_identifier($actions_table);
        $logs    = OPTISTATE_Utils::escape_identifier($wpdb->prefix . 'actionscheduler_logs');
        $claims  = OPTISTATE_Utils::escape_identifier($wpdb->prefix . 'actionscheduler_claims');
        $groups  = OPTISTATE_Utils::escape_identifier($wpdb->prefix . 'actionscheduler_groups');

        $children = [
            [
                'table' => $actions_table,
                'label' => 'Action Scheduler actions',
                'query' => "SELECT COUNT(*) FROM {$actions} WHERE status IN ('complete', 'failed', 'canceled')",
            ],
            [
                'table' => $wpdb->prefix . 'actionscheduler_logs',
                'label' => 'Action Scheduler logs',
                'query' => "SELECT COUNT(*) FROM {$logs} l
                            LEFT JOIN {$actions} a ON a.action_id = l.action_id
                            WHERE a.action_id IS NULL OR a.status IN ('complete', 'failed', 'canceled')",
            ],
            [
                'table' => $wpdb->prefix . 'actionscheduler_claims',
                'label' => 'Action Scheduler claims',
                'query' => "SELECT COUNT(*) FROM {$claims} c
                            WHERE NOT EXISTS (
                                SELECT 1 FROM {$actions} a
                                WHERE a.claim_id = c.claim_id
                                  AND a.status NOT IN ('complete', 'failed', 'canceled')
                            )",
            ],
            [
                'table' => $wpdb->prefix . 'actionscheduler_groups',
                'label' => 'Action Scheduler groups',
                'query' => "SELECT COUNT(*) FROM {$groups} g
                            WHERE NOT EXISTS (
                                SELECT 1 FROM {$actions} a
                                WHERE a.group_id = g.group_id
                                  AND a.status NOT IN ('complete', 'failed', 'canceled')
                            )",
            ],
        ];

        $total = 0;

        foreach ($children as $child) {
            if (!OPTISTATE_Utils::table_exists($child['table'])) {
                continue;
            }

            $count = absint($wpdb->get_var($child['query']));

            if ($wpdb->last_error) {
                $warnings[] = $child['label'] . ': ' . $wpdb->last_error;

                continue;
            }

            $total += $count;
        }

        $stats['action_scheduler'] = $total;
    }

    private function collect_oembed_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $count = 0;

        $meta = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                    $wpdb->esc_like('_oembed_') . '%'
                )
            )
        );

        if ($wpdb->last_error) {
            $warnings[] = 'oEmbed postmeta: ' . $wpdb->last_error;
        } else {
            $count += $meta;
        }

        $options = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options}
                     WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like('_oembed_') . '%',
                    $wpdb->esc_like('_transient_oembed_') . '%',
                    $wpdb->esc_like('_transient_timeout_oembed_') . '%'
                )
            )
        );

        if ($wpdb->last_error) {
            $warnings[] = 'oEmbed options: ' . $wpdb->last_error;
        } else {
            $count += $options;
        }

        $stats['oembed_cache'] = $count;
    }

    private function collect_woocommerce_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $now = time();

        $patterns = [
            [
                'timeout' => '_transient_timeout_wc_',
                'value'   => '_transient_wc_',
                'exclude' => ['_transient_timeout_wc_var_', '_transient_wc_var_'],
            ],
            [
                'timeout' => '_transient_timeout_wc_var_',
                'value'   => '_transient_wc_var_',
                'exclude' => [],
            ],
            [
                'timeout' => '_wc_session_expires_',
                'value'   => '_wc_session_',
                'exclude' => [],
            ],
        ];

        $subqueries = [];

        foreach ($patterns as $pattern) {
            $timeout_exclusion = '';
            $value_exclusion   = '';

            if (!empty($pattern['exclude'])) {
                $timeout_exclusion = $wpdb->prepare(
                    ' AND option_name NOT LIKE %s',
                    $wpdb->esc_like($pattern['exclude'][0]) . '%'
                );

                $value_exclusion = $wpdb->prepare(
                    ' AND option_name NOT LIKE %s',
                    $wpdb->esc_like($pattern['exclude'][1]) . '%'
                );
            }

            $subqueries[] = $wpdb->prepare(
                "(SELECT COUNT(*) FROM {$wpdb->options}
                  WHERE option_name LIKE %s AND option_value < %d{$timeout_exclusion})",
                $wpdb->esc_like($pattern['timeout']) . '%',
                $now
            );

            $subqueries[] = $wpdb->prepare(
                "(SELECT COUNT(*) FROM {$wpdb->options} outer_options
                  WHERE option_name LIKE %s
                    AND option_name NOT LIKE %s
                    AND option_name NOT LIKE %s
                    {$value_exclusion}
                    AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->options} AS timeout
                        WHERE timeout.option_name = REPLACE(outer_options.option_name, %s, %s)
                          AND timeout.option_value >= %d
                    ))",
                $wpdb->esc_like($pattern['value']) . '%',
                $wpdb->esc_like('_transient_timeout_') . '%',
                $wpdb->esc_like('_wc_session_expires_') . '%',
                $pattern['value'],
                $pattern['timeout'],
                $now
            );
        }

        $session_table = $wpdb->prefix . 'woocommerce_sessions';

        if (OPTISTATE_Utils::table_exists($session_table)) {
            $subqueries[] = $wpdb->prepare(
                '(SELECT COUNT(*) FROM ' . OPTISTATE_Utils::escape_identifier($session_table) . ' WHERE session_expiry < %d)',
                $now
            );
        }

        $total = $wpdb->get_var(
            'SELECT /*+ MAX_EXECUTION_TIME(30000) */ ' . implode(' + ', $subqueries) . ' AS woo_bloat'
        );

        if ($wpdb->last_error) {
            $warnings[] = 'WooCommerce bloat: ' . $wpdb->last_error;

            return;
        }

        $stats['woo_bloat'] = absint($total);
    }

    private function collect_taxonomy_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $cleanup = $this->get_service('cleanup_functions');

        if (!$cleanup) {
            return;
        }

        $buckets = $cleanup->get_taxonomy_buckets();

        $actionable = array_merge($buckets['registered'] ?? [], $buckets['orphan'] ?? []);

        if (empty($actionable)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($actionable), '%s'));

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT tt.term_taxonomy_id)
                 FROM {$wpdb->term_taxonomy} tt
                 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                 WHERE tt.count = 0
                   AND t.slug != 'uncategorized'
                   AND tt.taxonomy NOT IN ('nav_menu', 'link_category', 'post_format')
                   AND tt.taxonomy IN ({$placeholders})",
                ...$actionable
            )
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Empty taxonomies: ' . $wpdb->last_error;

            return;
        }

        $stats['empty_taxonomies'] = absint($count);
    }

    private function collect_schema_statistics(array &$stats, array &$warnings, bool $force_refresh): void
    {
        global $wpdb;

        $cache_key = 'optistate_stats_heavy_v1';

        $heavy = get_transient($cache_key);

        if ($heavy === false || $force_refresh) {
            $heavy = OPTISTATE_Utils::with_stats_expiry_disabled(static function () use ($wpdb): array {
                $totals = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT /*+ MAX_EXECUTION_TIME(120000) */
                             COUNT(*) AS total_tables,
                             COALESCE(SUM(data_free), 0) AS total_overhead,
                             COALESCE(SUM(index_length), 0) AS total_indexes,
                             COALESCE(SUM(data_length + index_length), 0) AS total_size
                         FROM information_schema.TABLES
                         WHERE table_schema = %s',
                        DB_NAME
                    )
                );

                $engine_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT /*+ MAX_EXECUTION_TIME(60000) */
                             engine, COUNT(*) AS table_count, SUM(data_length + index_length) AS total_size
                         FROM information_schema.TABLES
                         WHERE table_schema = %s AND engine IS NOT NULL
                         GROUP BY engine
                         ORDER BY total_size DESC',
                        DB_NAME
                    ),
                    ARRAY_A
                );

                $engine_distribution = [];

                if (is_array($engine_rows)) {
                    foreach ($engine_rows as $row) {
                        $engine_distribution[$row['engine']] = [
                            'count' => (int) $row['table_count'],
                            'size'  => (float) $row['total_size'],
                        ];
                    }
                }

                return [
                    'total_tables'        => $totals ? (int) $totals->total_tables : 0,
                    'total_overhead'      => $totals ? (float) $totals->total_overhead : 0.0,
                    'total_indexes'       => $totals ? (float) $totals->total_indexes : 0.0,
                    'total_size'          => $totals ? (float) $totals->total_size : 0.0,
                    'engine_distribution' => $engine_distribution,
                ];
            });

            if (!is_array($heavy) || !isset($heavy['total_tables'])) {
                $warnings[] = 'Information schema table stats: ' . ($wpdb->last_error ?: 'no result');

                $heavy = [
                    'total_tables'        => 0,
                    'total_overhead'      => 0.0,
                    'total_indexes'       => 0.0,
                    'total_size'          => 0.0,
                    'engine_distribution' => [],
                ];
            }

            set_transient($cache_key, $heavy, self::STATS_CACHE_DURATION);
        }

        $stats['total_tables_count']       = $heavy['total_tables'];
        $stats['raw_table_overhead_bytes'] = $heavy['total_overhead'];
        $stats['total_indexes_size_bytes'] = $heavy['total_indexes'];
        $stats['total_db_size_bytes']      = $heavy['total_size'];
        $stats['table_overhead_bytes']     = $heavy['total_overhead'];
        $stats['table_overhead']           = size_format($heavy['total_overhead'], 2);

        $engine_summary = [];

        foreach ($heavy['engine_distribution'] as $engine => $data) {
            $engine_summary[$engine] = [
                'count' => $data['count'],
                'size'  => size_format($data['size'], 2),
            ];
        }

        $stats['engine_distribution'] = $engine_summary;

        $data_size = $stats['total_db_size_bytes'] - $stats['total_indexes_size_bytes'];

        $stats['index_to_data_ratio'] = $data_size > 0
            ? round(($stats['total_indexes_size_bytes'] / $data_size) * 100, 2) . '%'
            : 'N/A';
    }

    private function collect_autoload_statistics(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS autoload_count, COALESCE(SUM(LENGTH(option_value)), 0) AS autoload_size
             FROM {$wpdb->options}
             WHERE autoload IN ('on', 'yes', 'auto-on', 'auto')
               AND option_name NOT LIKE '\_transient\_%'
               AND option_name NOT LIKE '\_site\_transient\_%'"
        );

        if ($wpdb->last_error) {
            $warnings[] = 'Autoload data: ' . $wpdb->last_error;

            return;
        }

        $stats['autoload_size_bytes'] = absint($row->autoload_size ?? 0);
        $stats['autoload_options']    = absint($row->autoload_count ?? 0);
        $stats['autoload_size']       = size_format($stats['autoload_size_bytes'], 2);
    }

    private function collect_creation_date(array &$stats, array &$warnings): void
    {
        global $wpdb;

        $created = $wpdb->get_var("SELECT post_date_gmt FROM {$wpdb->posts} ORDER BY ID ASC LIMIT 1");

        if ($wpdb->last_error) {
            $warnings[] = 'DB creation date: ' . $wpdb->last_error;

            return;
        }

        if (!$created || $created === '0000-00-00 00:00:00') {
            return;
        }

        $timestamp = strtotime($created . ' UTC');

        if ($timestamp === false) {
            return;
        }

        $stats['db_creation_date'] = wp_date(OPTISTATE_Utils::get_cached_option('date_format'), $timestamp);
    }

    public function ajax_get_stats(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->settings_manager->check_user_access();

        $force_refresh = isset($_POST['force_refresh'])
            && sanitize_text_field(wp_unslash($_POST['force_refresh'])) === 'true';

        if ($force_refresh && !OPTISTATE_Utils::check_rate_limit('refresh_stats', 5)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429,
                ['stats' => $this->get_combined_database_statistics(false)]
            );

            return;
        }

        try {
            OPTISTATE_Utils::send_json_success($this->get_combined_database_statistics($force_refresh));
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'ajax_get_stats failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            OPTISTATE_Utils::send_json_error(__('An unexpected error occurred while collecting statistics.', 'optistate'));
        }
    }

    public function ajax_get_optimization_log(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->settings_manager->check_user_access();

        $is_manual_refresh = isset($_POST['manual_refresh'])
            && absint(wp_unslash($_POST['manual_refresh'])) === 1;

        if ($is_manual_refresh && !OPTISTATE_Utils::check_rate_limit('refresh_logs', 2)) {
            OPTISTATE_Utils::send_json_error(OPTISTATE_Utils::get_rate_limit_message(false), 429);

            return;
        }

        try {
            OPTISTATE_Utils::send_json_success($this->get_optimization_log());
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'ajax_get_optimization_log failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            OPTISTATE_Utils::send_json_error(__('An unexpected error occurred while fetching the log.', 'optistate'));
        }
    }

    public function ajax_apply_preset(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit('apply_preset', 5)) {
            OPTISTATE_Utils::send_json_error(OPTISTATE_Utils::get_rate_limit_message(false), 429);

            return;
        }

        try {
            $preset_key = isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';

            if ($preset_key === '') {
                OPTISTATE_Utils::send_json_error(__('No preset selected.', 'optistate'));

                return;
            }

            if (!class_exists('OPTISTATE_Presets')) {
                OPTISTATE_Utils::send_json_error(__('Presets are unavailable.', 'optistate'));

                return;
            }

            $presets = OPTISTATE_Presets::get_presets();

            if (!isset($presets[$preset_key])) {
                OPTISTATE_Utils::send_json_error(__('Invalid preset.', 'optistate'));

                return;
            }

            $current_settings = $this->settings_manager->get_persistent_settings();
            $preset_config    = OPTISTATE_Presets::get_preset_config($preset_key);
            $default_settings = $this->settings_manager->get_default_settings();

            $new_settings = array_merge($default_settings, $preset_config);

            foreach (['allowed_users', 'pagespeed_api_key', 'custom_trusted_proxies', 'ip_block_list', 'ip_whitelist'] as $key) {
                if (isset($current_settings[$key])) {
                    $new_settings[$key] = $current_settings[$key];
                }
            }

            $new_settings['last_applied_preset'] = $preset_key === 'default' ? '' : $preset_key;

            if (isset($new_settings['performance_features']) && is_array($new_settings['performance_features'])) {
                $new_settings['performance_features'] = $this->performance_manager->validate_performance_features(
                    $new_settings['performance_features']
                );
            }

            if (!$this->settings_manager->save_persistent_settings($new_settings)) {
                OPTISTATE_Utils::send_json_error(__('Failed to apply preset.', 'optistate'));

                return;
            }

            $this->performance_manager->rebuild_htaccess();
            $this->reschedule_cron_from_settings();

            $caching = $this->get_service('server_caching');

            if ($caching instanceof OPTISTATE_Server_Caching) {
                $caching->purge_entire_cache();
            }

            $this->clear_stats_cache();

            $label = $presets[$preset_key]['label'] ?? $preset_key;

            $this->log_entry(
                sprintf(
                    __('🎛️ Preset "%s" applied by {username}', 'optistate'),
                    $label
                )
            );

            OPTISTATE_Utils::send_json_success([
                'message' => sprintf(
                    __('Preset "%s" applied successfully!<br>Reloading page...', 'optistate'),
                    $label
                ),
                'reload'  => true,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'ajax_apply_preset failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            OPTISTATE_Utils::send_json_error(__('An unexpected error occurred while applying the preset.', 'optistate'));
        }
    }

    private function prepare_download(string $content_type, string $filename, int $length): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $length);
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    public function ajax_download_htaccess(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can("manage_options")) {
            wp_die(esc_html__("Insufficient permissions.", "optistate"), 403);
        }
        $this->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit('download_htaccess', 5)) {
            OPTISTATE_Utils::send_json_error(OPTISTATE_Utils::get_rate_limit_message(false), 429);

            return;
        }

        $info = $this->performance_manager->get_htaccess_info(true, true);

        if (empty($info['exists']) || (int) $info['size'] === 0) {
            OPTISTATE_Utils::send_json_error(__('.htaccess file not found or empty.', 'optistate'));

            return;
        }

        $content = isset($info['content']) ? (string) $info['content'] : '';

        if ($content === '') {
            OPTISTATE_Utils::send_json_error(__('.htaccess file exists but is not readable.', 'optistate'));

            return;
        }

        $this->log_entry('📥 ' . __('.htaccess file downloaded by {username}', 'optistate'));

        $this->prepare_download('text/plain; charset=UTF-8', '.htaccess', strlen($content));

        echo $content;
        exit();
    }

    public function ajax_download_error_log(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
                if (!current_user_can("manage_options")) {
            wp_die(esc_html__("Insufficient permissions.", "optistate"), 403);
        }
        $this->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit('error_log', 5)) {
            OPTISTATE_Utils::send_json_error(OPTISTATE_Utils::get_rate_limit_message(false), 429);

            return;
        }

        try {
            $log_file = $this->get_debug_log_path();

            if ($log_file === null || !file_exists($log_file) || !is_file($log_file)) {
                OPTISTATE_Utils::send_json_error(__('Error log file not found.', 'optistate'));

                return;
            }

            if (!is_readable($log_file)) {
                OPTISTATE_Utils::send_json_error(__('Error log file exists but is not readable.', 'optistate'));

                return;
            }

            clearstatcache(true, $log_file);

            $size = @filesize($log_file);

            if ($size === false || $size === 0) {
                OPTISTATE_Utils::send_json_error(__('Error log file is empty.', 'optistate'));

                return;
            }

            if ($size > 20 * 1024 * 1024) {
                OPTISTATE_Utils::send_json_error(__('Error log file exceeds 20 MB. Please download via FTP.', 'optistate'));

                return;
            }

            $contents = @file_get_contents($log_file, false, null, 0, $size);

            if ($contents === false) {
                OPTISTATE_Utils::send_json_error(__('Error log file could not be read.', 'optistate'));

                return;
            }

            $this->log_entry('📥 ' . __('Error log downloaded by {username}', 'optistate'));

            $this->prepare_download('text/plain; charset=UTF-8', 'debug.log', strlen($contents));

            echo $contents;
            exit();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'ajax_download_error_log failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            OPTISTATE_Utils::send_json_error(__('An error occurred while preparing the download.', 'optistate'));
        }
    }

    public function ajax_download_activity_log(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
                if (!current_user_can("manage_options")) {
            wp_die(esc_html__("Insufficient permissions.", "optistate"), 403);
        }
        $this->settings_manager->check_user_access();

        if (!OPTISTATE_Utils::check_rate_limit('activity_log', 3)) {
            OPTISTATE_Utils::send_json_error(OPTISTATE_Utils::get_rate_limit_message(false), 429);

            return;
        }

        try {
            $log_entries = $this->get_optimization_log();

            if (empty($log_entries)) {
                OPTISTATE_Utils::send_json_error(__('Activity log is empty.', 'optistate'));

                return;
            }

            $json = wp_json_encode(
                [
                    'plugin'      => 'WP Optimal State',
                    'version'     => self::VERSION,
                    'exported_at' => current_time('Y-m-d H:i:s'),
                    'site_url'    => get_site_url(),
                    'log'         => $log_entries,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );

            if ($json === false) {
                OPTISTATE_Utils::send_json_error(__('Failed to encode log data.', 'optistate'));

                return;
            }

            $this->log_entry('📥 ' . __('Activity log downloaded by {username}', 'optistate'));

            $this->prepare_download(
                'application/json; charset=UTF-8',
                'optistate-activity-log-' . gmdate('Y-m-d-His') . '.json',
                strlen($json)
            );

            echo $json;
            exit();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'ajax_download_activity_log failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            OPTISTATE_Utils::send_json_error(__('An error occurred while preparing the download.', 'optistate'));
        }
    }

    private function delegate_to_server_caching(string $method, string $error_message): void
    {
        $caching = $this->get_service('server_caching');

        if (!$caching instanceof OPTISTATE_Server_Caching) {
            OPTISTATE_Utils::send_json_error(__('Server caching is disabled or not available.', 'optistate'));

            return;
        }

        try {
            $caching->{$method}();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                $method . ' failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            OPTISTATE_Utils::send_json_error($error_message);
        }
    }

    public function ajax_purge_page_cache(): void
    {
        $this->delegate_to_server_caching(
            'ajax_purge_page_cache',
            __('An unexpected error occurred while purging the cache.', 'optistate')
        );
    }

    public function ajax_get_cache_stats(): void
    {
        $this->delegate_to_server_caching(
            'ajax_get_cache_stats',
            __('An unexpected error occurred while fetching cache stats.', 'optistate')
        );
    }

    public function ajax_start_preload(): void
    {
        $this->delegate_to_server_caching(
            'ajax_start_preload',
            __('An unexpected error occurred while starting the preload.', 'optistate')
        );
    }

    public function ajax_stop_preload(): void
    {
        $this->delegate_to_server_caching(
            'ajax_stop_preload',
            __('An unexpected error occurred while stopping the preload.', 'optistate')
        );
    }

    public function ajax_get_preload_status(): void
    {
        $this->delegate_to_server_caching(
            'ajax_get_preload_status',
            __('An unexpected error occurred while fetching preload status.', 'optistate')
        );
    }

    public function update_cron_schedule(int $days, string $time = '02:00'): void
    {
        wp_clear_scheduled_hook('optistate_scheduled_cleanup');

        if ($days <= 0) {
            return;
        }

        try {
            $timezone = wp_timezone();

            if (!$timezone instanceof DateTimeZone) {
                $timezone = new DateTimeZone('UTC');

                $this->log_entry(
                    '⚠️ ' . __('Invalid timezone detected, falling back to UTC', 'optistate'),
                    'error'
                );
            }

            if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                $time = '02:00';

                $this->log_entry(
                    '⚠️ ' . __('Invalid time format in cron settings, reset to 02:00', 'optistate'),
                    'error'
                );
            }

            [$hour, $minute] = array_map('intval', explode(':', $time));

            $now    = new DateTime('now', $timezone);
            $target = clone $now;

            $target->setTime($hour, $minute, 0);

            if ($target <= $now) {
                $target->add(new DateInterval('P1D'));
            }

            if ($days > 1) {
                $target->add(new DateInterval('P' . ($days - 1) . 'D'));
            }

            $target->setTime($hour, $minute, 0);

            wp_schedule_single_event($target->getTimestamp(), 'optistate_scheduled_cleanup');
        } catch (Throwable $e) {
            $this->log_entry(
                '⚠️ ' . sprintf(
                    __('Cron scheduling failed: %s', 'optistate'),
                    $e->getMessage()
                ),
                'error'
            );
        }
    }

    public function reschedule_cron_from_settings(): void
    {
        $settings = $this->settings_manager->get_persistent_settings();

        $this->update_cron_schedule(
            (int) $settings['auto_optimize_days'],
            (string) $settings['auto_optimize_time']
        );
    }

    public function maybe_reschedule_cron(): void
    {
        $settings = $this->settings_manager->get_persistent_settings();

        $days = (int) $settings['auto_optimize_days'];

        if ($days > 0 && !wp_next_scheduled('optistate_scheduled_cleanup')) {
            $this->update_cron_schedule($days, (string) $settings['auto_optimize_time']);
        }
    }

    public function run_scheduled_cleanup(): void
    {
        try {
            if (!wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
                return;
            }

            $backup_manager = $this->get_service('db_backup_manager');

            if (!$backup_manager) {
                $this->log_entry('❌ ' . __('Scheduled Backup Failed to Start', 'optistate'), 'scheduled', '', ['is_failure' => true]);
                $this->reschedule_cron_from_settings();

                return;
            }

            if (!$backup_manager->create_backup_silent(true)) {
                $this->log_entry('❌ ' . __('Scheduled Backup Failed to Start', 'optistate'), 'scheduled', '', ['is_failure' => true]);

                $this->send_scheduled_failure_notification(
                    'backup_failed',
                    __('Could not initiate background backup task', 'optistate')
                );

                $this->reschedule_cron_from_settings();

                return;
            }

            $this->reschedule_cron_from_settings();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'run_scheduled_cleanup failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            $this->reschedule_cron_from_settings();
        }
    }

    public function execute_post_backup_tasks(string $backup_filename): void
    {
        try {
            $settings    = $this->settings_manager->get_persistent_settings();
            $backup_only = !empty($settings['auto_backup_only']);

            $cleanup_results = ['status' => 'skipped'];

            if (!$backup_only) {
                $cleanup = $this->get_service('cleanup_functions');

                if ($cleanup) {
                    $cleanup_results = $cleanup->perform_optimizations(true);

                    $total_cleaned = is_array($cleanup_results) ? array_sum(array_filter($cleanup_results, 'is_numeric')) : 0;

                    $this->log_entry(
                        sprintf(
                            '🧹 ' . __('Scheduled One-Click Optimization Completed (%s items deleted)', 'optistate'),
                            number_format_i18n($total_cleaned)
                        ),
                        'scheduled'
                    );
                }

                $tools = $this->get_service('advanced_tools');

                if ($tools) {
                    $table_results   = $tools->perform_optimize_tables(true);
                    $optimized_count = is_array($table_results) && isset($table_results['optimized'])
                        ? (int) $table_results['optimized']
                        : 0;

                    $this->log_entry(
                        sprintf(
                            '⚡ ' . __('Scheduled Table Optimization Completed (%s optimized)', 'optistate'),
                            number_format_i18n($optimized_count)
                        ),
                        'scheduled'
                    );
                }
            }

            if (!empty($settings['email_notifications'])) {
                $sent = $this->send_scheduled_cleanup_notification(
                    is_array($cleanup_results) ? $cleanup_results : [],
                    $backup_filename,
                    $backup_only
                );

                if ($sent) {
                    $this->log_entry('📧 ' . __('Email Notification Sent (scheduled tasks)', 'optistate'), 'scheduled');
                } else {
                    $this->log_entry(
                        '❌ ' . __('Email Notification Failed (scheduled tasks)', 'optistate'),
                        'scheduled',
                        '',
                        ['is_failure' => true]
                    );
                }
            }

            $this->clear_stats_cache();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                'execute_post_backup_tasks failed: ' . $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );
        }
    }

    private function get_mail_from_name(): string
    {
        $name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $name = wp_strip_all_tags((string) $name);

        return trim(str_replace(["\r", "\n"], ' ', $name));
    }

    private function send_scheduled_cleanup_notification(
        array $cleanup_results = [],
        string $backup_filename = '',
        bool $backup_only = false
    ): bool {
        $settings = $this->settings_manager->get_persistent_settings();

        if (empty($settings['email_notifications'])) {
            return false;
        }

        $admin_email_raw = get_option('admin_email');

        if (!is_email($admin_email_raw)) {
            return false;
        }

        $admin_email = sanitize_email($admin_email_raw);
        $site_name   = $this->get_mail_from_name();
        $site_url    = esc_url_raw(get_site_url());

        if ($backup_only) {
            $subject = __('WP Optimal State: Database Backup Completed', 'optistate');
            $message = sprintf(

                __("Hello,\n\nYour scheduled database backup for %s has been completed successfully.\n\n", 'optistate'),
                $site_name
            );
        } else {
            $subject = __('WP Optimal State: Database Optimization Completed', 'optistate');
            $message = sprintf(

                __("Hello,\n\nYour scheduled database optimization and backup for %s has been completed successfully.\n\n", 'optistate'),
                $site_name
            );
        }

        $message .= sprintf(__("Site: %s\n", 'optistate'), $site_url);
        $message .= sprintf(__("Completed: %s\n", 'optistate'), OPTISTATE_Utils::format_timestamp(time()));

        if ($backup_filename !== '') {
            $message .= sprintf(__("Backup Created: %s\n", 'optistate'), $backup_filename);
        } else {
            $message .= __("Backup: No new backup created (may have reached limit)\n", 'optistate');
        }

        if ($backup_only) {
            $message .= __("\nCleanup operations were skipped as per your 'Backup Only' setting.\n", 'optistate');
        } elseif (!empty($cleanup_results) && !isset($cleanup_results['status'])) {
            $message .= "\n" . __('Cleanup Results:', 'optistate') . "\n----------------------------\n";

            $total_cleaned = 0;

            foreach ($cleanup_results as $item => $count) {
                if (!is_numeric($count) || $count <= 0) {
                    continue;
                }

                $message .= sprintf("- %s: %s\n", ucfirst(str_replace('_', ' ', (string) $item)), number_format_i18n($count));
                $total_cleaned += $count;
            }

            $message .= $total_cleaned > 0
                ? sprintf(__("\nTotal items cleaned: %s\n", 'optistate'), number_format_i18n($total_cleaned))
                : __("\nNo cleanup was needed - database was already optimized.\n", 'optistate');
        } else {
            $message .= __("\nCleanup Results: No items needed cleaning\n", 'optistate');
        }

        $message .= "\n" . __('----------------------------', 'optistate') . "\n";
        $message .= "\n" . __("PLUGIN SETTINGS:\n", 'optistate');
        $message .= sprintf(__("Dashboard: %s\n", 'optistate'), esc_url_raw(admin_url('admin.php?page=optistate')));

        $message .= sprintf(
            __("Automatic tasks: %1\$s every %2\$d days at %3\$s\n", 'optistate'),
            $backup_only ? __('Backup Only', 'optistate') : __('Backup & Cleanup', 'optistate'),
            absint($settings['auto_optimize_days']),
            wp_strip_all_tags(wp_date('g:i A', OPTISTATE_Utils::local_time_to_timestamp((string) $settings['auto_optimize_time'])))
        );

        $message .= "\n" . __('This is an automated alert from WP Optimal State plugin.', 'optistate');
        $message .= "\n" . __('You are receiving this because email notifications are enabled in the plugin settings.', 'optistate');

        return $this->dispatch_plain_text_mail($admin_email, $subject, $message, $site_name);
    }

    private function send_scheduled_failure_notification(
        string $failure_type,
        string $failure_message,
        array $details = []
    ): bool {
        $settings = $this->settings_manager->get_persistent_settings();

        if (empty($settings['email_notifications'])) {
            return false;
        }

        if (!in_array($failure_type, ['backup_failed', 'cleanup_failed', 'exception'], true)) {
            return false;
        }

        $admin_email_raw = get_option('admin_email');

        if (!is_email($admin_email_raw)) {
            return false;
        }

        $admin_email = sanitize_email($admin_email_raw);
        $site_name   = $this->get_mail_from_name();
        $site_url    = esc_url_raw(get_site_url());

        $subject = __('WP Optimal State: Scheduled Database Maintenance Failed', 'optistate');

        $message = sprintf(

            __("ALERT: Scheduled database maintenance for %s has failed.\n\n", 'optistate'),
            $site_name
        );

        $message .= sprintf(__("Site: %s\n", 'optistate'), $site_url);
        $message .= sprintf(__("Failed: %s\n", 'optistate'), wp_strip_all_tags(OPTISTATE_Utils::format_timestamp(time())));
        $message .= str_repeat('-', 60) . "\n\n";
        $message .= __("FAILURE DETAILS:\n", 'optistate');
        $message .= sprintf(__("Type: %s\n", 'optistate'), OPTISTATE_Utils::get_failure_type_label($failure_type));
        $message .= sprintf(__("Error: %s\n\n", 'optistate'), wp_strip_all_tags($failure_message));

        if (!empty($details['error_code'])) {
            $message .= sprintf(__("Error Code: %s\n", 'optistate'), sanitize_text_field((string) $details['error_code']));
        }

        $causes = isset($details['possible_causes']) && is_array($details['possible_causes']) && !empty($details['possible_causes'])
            ? $details['possible_causes']
            : $this->get_default_failure_causes($failure_type);

        if ($failure_type === 'backup_failed') {
            $message .= __("BACKUP STATUS:\n", 'optistate');
            $message .= __("✗ Database backup creation FAILED\n", 'optistate');
            $message .= __("✗ Cleanup operations were NOT performed (for safety)\n\n", 'optistate');
            $message .= $this->format_cause_list($causes);
            $message .= "\n" . __("RECOMMENDED ACTIONS:\n", 'optistate');
            $message .= '1. ' . __('Check available disk space on your server', 'optistate') . "\n";
            $message .= '2. ' . __('Verify backup directory permissions (wp-content/uploads/optistate/db-backups/)', 'optistate') . "\n";
            $message .= '3. ' . __('Review PHP error logs for detailed error messages', 'optistate') . "\n";
            $message .= '4. ' . __('Try creating a manual backup from the plugin dashboard', 'optistate') . "\n";
            $message .= '5. ' . __('Contact your hosting provider if the issue persists', 'optistate') . "\n";
        } elseif ($failure_type === 'cleanup_failed') {
            $message .= __("BACKUP STATUS:\n", 'optistate');
            $message .= __("✓ Database backup created successfully\n", 'optistate');

            if (isset($details['backup_filename'])) {
                $message .= sprintf(

                    __("💾 Backup file: %s\n", 'optistate'),
                    sanitize_file_name((string) $details['backup_filename'])
                );
            }

            $message .= "\n" . __("CLEANUP STATUS:\n", 'optistate');
            $message .= __("✗ Database cleanup operations FAILED\n\n", 'optistate');
            $message .= $this->format_cause_list($causes);
            $message .= "\n" . __("RECOMMENDED ACTIONS:\n", 'optistate');
            $message .= '1. ' . __('Check database connection status', 'optistate') . "\n";
            $message .= '2. ' . __('Verify database user has sufficient privileges', 'optistate') . "\n";
            $message .= '3. ' . __('Review PHP and MySQL error logs', 'optistate') . "\n";
            $message .= '4. ' . __('Try running optimization manually from the plugin dashboard', 'optistate') . "\n";
            $message .= '5. ' . __('Consider increasing PHP memory_limit and max_execution_time', 'optistate') . "\n";
            $message .= '6. ' . __('Contact your hosting provider if the issue persists', 'optistate') . "\n";
        } else {
            $message .= __("EXCEPTION DETAILS:\n", 'optistate');

            if (isset($details['exception_message'])) {
                $message .= sprintf(

                    __("Message: %s\n", 'optistate'),
                    wp_strip_all_tags((string) $details['exception_message'])
                );
            }

            if (isset($details['exception_file'], $details['exception_line'])) {
                $message .= sprintf(

                    __("Location: %1\$s (line %2\$d)\n", 'optistate'),
                    basename(sanitize_text_field((string) $details['exception_file'])),
                    absint($details['exception_line'])
                );
            }

            $message .= __("\nFull stack trace has been logged to PHP error logs.\n", 'optistate');
            $message .= "\n" . __("RECOMMENDED ACTIONS:\n", 'optistate');
            $message .= '1. ' . __('Review the error details above', 'optistate') . "\n";
            $message .= '2. ' . __('Check PHP error logs for more information', 'optistate') . "\n";
            $message .= '3. ' . __('Verify server resources (memory, disk space, CPU)', 'optistate') . "\n";
            $message .= '4. ' . __('Contact your hosting provider with the error details', 'optistate') . "\n";
            $message .= '5. ' . __('If the issue persists, contact WP Optimal State support', 'optistate') . "\n";
        }

        $message .= "\n" . str_repeat('-', 60) . "\n" . __("WHAT HAPPENS NEXT?\n", 'optistate');
        $message .= '• ' . __('The scheduled task will retry at the next scheduled time', 'optistate') . "\n";
        $message .= '• ' . __('Your database has NOT been modified', 'optistate') . "\n";
        $message .= '• ' . __('You can try running the operations manually from your WordPress admin', 'optistate') . "\n";
        $message .= "\n" . __("PLUGIN SETTINGS:\n", 'optistate');
        $message .= sprintf(__("Dashboard: %s\n", 'optistate'), esc_url_raw(admin_url('admin.php?page=optistate')));

        $message .= sprintf(

            __("Automatic tasks: Every %1\$d days at %2\$s\n", 'optistate'),
            absint($settings['auto_optimize_days']),
            wp_strip_all_tags(wp_date('g:i A', OPTISTATE_Utils::local_time_to_timestamp((string) $settings['auto_optimize_time'])))
        );

        $message .= "\n" . __('This is an automated alert from WP Optimal State plugin.', 'optistate');
        $message .= "\n" . __('You are receiving this because email notifications are enabled in the plugin settings.', 'optistate');

        return $this->dispatch_plain_text_mail(
            $admin_email,
            $subject,
            $message,
            $site_name,
            ['X-Priority: 1', 'X-Mailer: WP Optimal State Plugin']
        );
    }

    private function get_default_failure_causes(string $failure_type): array
    {
        if ($failure_type === 'backup_failed') {
            return [
                __('Insufficient free disk space on the server', 'optistate'),
                __('The backup directory is not writable', 'optistate'),
                __('The PHP process ran out of memory or execution time', 'optistate'),
                __('The database connection was interrupted', 'optistate'),
            ];
        }

        if ($failure_type === 'cleanup_failed') {
            return [
                __('The database user lacks the required privileges', 'optistate'),
                __('A query exceeded the server execution time limit', 'optistate'),
                __('The database connection was interrupted', 'optistate'),
                __('Another process holds a conflicting table lock', 'optistate'),
            ];
        }

        return [__('An unexpected runtime error occurred', 'optistate')];
    }

    private function format_cause_list(array $causes): string
    {
        if (empty($causes)) {
            return '';
        }

        $out = __("POSSIBLE CAUSES:\n", 'optistate');

        foreach ($causes as $cause) {
            $out .= '• ' . wp_strip_all_tags((string) $cause) . "\n";
        }

        return $out;
    }

    private function dispatch_plain_text_mail(
        string $to,
        string $subject,
        string $message,
        string $from_name,
        array $extra_headers = []
    ): bool {
        if (!function_exists('wp_mail')) {
            return false;
        }

        $headers = array_merge(
            [
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . $from_name . ' <' . $to . '>',
            ],
            $extra_headers
        );

        add_filter('wp_mail_content_type', ['OPTISTATE_Utils', 'force_plain_text_mail_type'], 999);

        try {
            $sent = wp_mail($to, $subject, $message, $headers);
        } catch (Throwable $e) {
            $sent = false;
        } finally {
            remove_filter('wp_mail_content_type', ['OPTISTATE_Utils', 'force_plain_text_mail_type'], 999);
        }

        return (bool) $sent;
    }

    public function display_init_error_notice(): void
    {
        if ($this->init_error === null || !current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('WP Optimal State could not initialise properly:', 'optistate'); ?></strong>
                <?php echo esc_html($this->init_error); ?>
            </p>
            <p><?php esc_html_e('Please check the error logs for details.', 'optistate'); ?></p>
        </div>
        <?php
    }

    public function display_permission_warnings(): void
    {
        $screen = get_current_screen();

        if (!$screen || strpos($screen->id, 'optistate') === false || !current_user_can('manage_options')) {
            return;
        }

        $permission_issues = $this->check_required_permissions();

        if ($permission_issues === true) {
            return;
        }

        ?>
        <div class="notice notice-error is-dismissible">
            <h3><?php esc_html_e('WP Optimal State - Permission Issues', 'optistate'); ?></h3>
            <p><?php esc_html_e('The following issues prevent the plugin from functioning properly:', 'optistate'); ?></p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <?php foreach ($permission_issues as $issue) : ?>
                    <li><?php echo esc_html($issue); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>
                <strong><?php esc_html_e('How to fix:', 'optistate'); ?></strong><br>
                <?php esc_html_e('Please ensure the following directories have write permissions (typically 755 or higher):', 'optistate'); ?>
            </p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li><code><?php echo esc_html($this->backup_dir); ?></code></li>
                <li><code><?php echo esc_html($this->temp_dir); ?></code></li>
                <li><code><?php echo esc_html($this->cache_dir); ?></code></li>
            </ul>
            <p><?php esc_html_e('You may need to contact your hosting provider to adjust these permissions.', 'optistate'); ?></p>
        </div>
        <?php
    }

    public function display_restore_completion_notice(): void
    {

        if (!current_user_can('manage_options')) {
            return;
        }

        $settings      = $this->settings_manager->get_persistent_settings();
        $allowed_users = $settings['allowed_users'] ?? [];

        if (!empty($allowed_users)
            && !in_array((int) get_current_user_id(), array_map('intval', $allowed_users), true)) {
            return;
        }

        $restore_completed = get_option('optistate_restore_completed');

        if (!$restore_completed || !is_array($restore_completed)) {
            return;
        }

        delete_option('optistate_restore_completed');

        $timestamp = isset($restore_completed['timestamp']) ? (int) $restore_completed['timestamp'] : 0;
        $filename  = isset($restore_completed['filename']) ? (string) $restore_completed['filename'] : '';
        $queries   = isset($restore_completed['queries']) ? (int) $restore_completed['queries'] : 0;
        $time_ago  = $timestamp > 0 ? human_time_diff($timestamp) : '';

        ?>
        <div class="notice notice-success is-dismissible" style="border-left: 4px solid #46b450; padding: 15px; margin: 20px 0;">
            <h2 style="margin-top: 0; color: #46b450;">
                <span class="dashicons dashicons-yes-alt" style="font-size: 28px; width: 28px; height: 28px; vertical-align: middle;"></span>
                <?php esc_html_e('Database Restore Completed Successfully!', 'optistate'); ?>
            </h2>
            <p style="font-size: 15px; margin: 10px 0;">
                <strong><?php esc_html_e('Your database has been fully restored.', 'optistate'); ?></strong>
            </p>
            <?php if ($filename !== '') : ?>
                <p style="margin: 5px 0;">
                    📁 <strong><?php esc_html_e('Backup file:', 'optistate'); ?></strong>
                    <?php echo esc_html($filename); ?>
                </p>
            <?php endif; ?>
            <?php if ($time_ago !== '') : ?>
                <p style="margin: 5px 0;">
                    ⏰ <strong><?php esc_html_e('Completed:', 'optistate'); ?></strong>
                    <?php
                    echo esc_html(
                        sprintf(
                            __('Less than %s ago', 'optistate'),
                            $time_ago
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>
            <p style="margin: 5px 0;">
                🔢 <strong><?php esc_html_e('Queries executed:', 'optistate'); ?></strong>
                <?php echo esc_html(number_format_i18n($queries)); ?>
            </p>
            <p style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd; color: #666;">
                ℹ️ <?php esc_html_e('You were logged out because the database was replaced, causing your login session to reset.', 'optistate'); ?>
            </p>
        </div>
        <?php
    }

    public function enqueue_admin_assets(string $hook): void
    {
        $allowed_hooks = ['toplevel_page_optistate', 'profile.php', 'user-edit.php'];

        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'optistate-admin-styles',
            OPTISTATE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'optistate-admin-script',
            OPTISTATE_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            self::VERSION,
            true
        );

        wp_localize_script('optistate-admin-script', 'optistate_Ajax', [
            'ajaxurl'                 => admin_url('admin-ajax.php'),
            'nonce'                   => wp_create_nonce(self::NONCE_ACTION),
            'settings_updated'        => isset($_GET['settings-updated'])
                && sanitize_text_field(wp_unslash($_GET['settings-updated'])) === 'true',
            'rate_limit_message'      => OPTISTATE_Utils::get_rate_limit_message(false),
            'rate_limit_save_message' => OPTISTATE_Utils::get_rate_limit_message(true),
        ]);

        wp_localize_script('optistate-admin-script', 'optistate_BackupMgr', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::BACKUP_NONCE_ACTION),
        ]);

        if (in_array($hook, ['profile.php', 'user-edit.php'], true)) {
            $two_factor = $this->get_service('two_factor');

            if ($two_factor instanceof OPTISTATE_TwoFactor && $two_factor->is_globally_enabled()) {
                wp_enqueue_script(
                    'optistate-qrcodejs',
                    OPTISTATE_PLUGIN_URL . 'assets/js/qrcode.min.js',
                    [],
                    self::VERSION,
                    true
                );
            }
        }

        $settings = $this->settings_manager->get_persistent_settings();

        wp_localize_script('optistate-admin-script', 'optistate_OneClickConfig', [
            'all_items'        => OPTISTATE_Cleanup_Functions::get_all_cleanup_items(),
            'extra_items'      => $settings['one_click_extra_items'] ?? [],
            'default_keys'     => OPTISTATE_Cleanup_Functions::get_default_one_click_operations(),
            'one_click_backup' => (bool) ($settings['one_click_backup'] ?? false),
        ]);

        if ($hook !== 'toplevel_page_optistate') {
            return;
        }

        if (!class_exists('OPTISTATE_Presets')) {
            return;
        }

        $preset_data = [];

        foreach (OPTISTATE_Presets::get_presets() as $key => $preset) {
            $preset_data[$key] = ['description' => $preset['description'] ?? ''];
        }

        wp_localize_script('optistate-admin-script', 'optistate_PresetData', ['presets' => $preset_data]);
    }

    public function show_maintenance_page_for_visitors(): void
    {
        if (!get_option('optistate_maintenance_mode_active')) {
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        if (function_exists('is_login') && is_login()) {
            return;
        }

        nocache_headers();

        if (!headers_sent()) {
            header('Retry-After: 120');
        }

        $title = __('Briefly unavailable for scheduled maintenance', 'optistate');

        $message = '<h1>' . __('Briefly unavailable for scheduled maintenance.', 'optistate') . '</h1>'
            . '<p>' . __('We are currently performing critical database maintenance.', 'optistate') . '</p>'
            . '<p>' . __('Please check back in a minute.', 'optistate') . '</p>';

        wp_die(
            wp_kses_post($message),
            esc_html($title),
            ['response' => 503, 'back_link' => false]
        );
    }
}