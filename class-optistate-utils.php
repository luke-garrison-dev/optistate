<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

class OPTISTATE_Utils
{

    const LCP_IMAGE_THRESHOLD = 2;

    private const CACHED_OPTIONS_LIMIT = 100;

    private const SESSION_VAR_NAME_PATTERN = '/^[A-Za-z0-9_]{1,64}$/';

    public const CORE_TABLES = [
        'commentmeta',
        'comments',
        'links',
        'options',
        'postmeta',
        'posts',
        'termmeta',
        'terms',
        'term_relationships',
        'term_taxonomy',
        'usermeta',
        'users',
        'blogmeta',
        'blogs',
        'site',
        'sitemeta',
        'registration_log',
        'signups',
    ];

    private static array $cached_options = [];

    private static ?array $disabled_functions = null;

    private static array $trusted_proxy_cache = [];

    private static array $query_cache_config = [];

    private static array $version_cache = [];

    private static ?array $table_list_cache = null;

    private static array $table_statuses_cache = [];

    private static array $table_exists_static_cache = [];

    private static array $table_creation_cache = [];

    private static array $bot_rules_cache = [];

    private static ?string $caching_rules_cache = null;

    private static ?string $security_headers_rules_cache = null;

    private static ?array $cloudflare_ips_cache = null;

    private static ?string $mysql_version_cache = null;

    public static function is_function_available(string $function_name): bool
    {
        if (!function_exists($function_name)) {
            return false;
        }

        if (self::$disabled_functions === null) {
            $disabled = ini_get('disable_functions');

            self::$disabled_functions = is_string($disabled) && $disabled !== ''
                ? array_flip(array_filter(array_map('trim', explode(',', $disabled))))
                : [];
        }

        return !isset(self::$disabled_functions[$function_name]);
    }

    public static function safe_set_time_limit(int $seconds): void
    {
        if (!self::is_function_available('set_time_limit')) {
            return;
        }

        @set_time_limit($seconds);
    }

    public static function detect_server_type(): string
    {
        static $server_type = null;

        if ($server_type !== null) {
            return $server_type;
        }

        $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';

        if (stripos($software, 'nginx') !== false) {
            $server_type = 'nginx';
        } elseif (stripos($software, 'litespeed') !== false) {
            $server_type = 'litespeed';
        } elseif (stripos($software, 'apache') !== false || function_exists('apache_get_modules')) {
            $server_type = 'apache';
        } elseif (isset($_SERVER['HTTP_X_LITESPEED_CACHE']) || isset($_SERVER['HTTP_X_LSCACHE'])) {

            $server_type = 'litespeed';
        } else {
            $server_type = 'unknown';
        }

        return $server_type;
    }

    public static function get_cached_option(string $option_name, $default = false)
    {
        if (array_key_exists($option_name, self::$cached_options)) {
            $value = self::$cached_options[$option_name];
            unset(self::$cached_options[$option_name]);
            self::$cached_options[$option_name] = $value;

            return $value;
        }

        if (count(self::$cached_options) >= self::CACHED_OPTIONS_LIMIT) {
            $oldest_key = array_key_first(self::$cached_options);

            if ($oldest_key !== null) {
                unset(self::$cached_options[$oldest_key]);
            }
        }

        $value = get_option($option_name, $default);

        self::$cached_options[$option_name] = $value;

        return $value;
    }

    public static function clear_cached_options(): void
    {
        self::$cached_options = [];
    }

    private static function get_mysql_version(): string
    {
        if (self::$mysql_version_cache === null) {
            global $wpdb;

            self::$mysql_version_cache = (string) $wpdb->get_var('SELECT VERSION()');
        }

        return self::$mysql_version_cache;
    }

    public static function get_all_tables(): array
    {
        if (self::$table_list_cache !== null) {
            return self::$table_list_cache;
        }

        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');

        self::$table_list_cache = is_array($tables) ? $tables : [];

        return self::$table_list_cache;
    }

    public static function get_table_status(string $table_name, bool $force = false): ?array
    {
        if (!$force && array_key_exists($table_name, self::$table_statuses_cache)) {
            return self::$table_statuses_cache[$table_name];
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE,
                        ENGINE, TABLE_COLLATION, UPDATE_TIME, CREATE_TIME
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $table_name
            ),
            ARRAY_A
        );

        if (!$row) {

            $row = $wpdb->get_row(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table_name)),
                ARRAY_A
            );
        }

        self::$table_statuses_cache[$table_name] = $row ?: null;

        return self::$table_statuses_cache[$table_name];
    }

    public static function preload_all_table_statuses(): void
    {
        if (!empty(self::$table_statuses_cache)) {
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE,
                        ENGINE, TABLE_COLLATION, UPDATE_TIME, CREATE_TIME
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s',
                DB_NAME
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            self::$table_statuses_cache[$row['TABLE_NAME']] = $row;
        }
    }

    public static function invalidate_table_cache(): void
    {
        self::$table_statuses_cache = [];
        self::$table_list_cache     = null;

        delete_transient('optistate_table_statuses_' . DB_NAME);
        delete_transient('optistate_all_tables_' . DB_NAME);
    }

    public static function table_exists(string $table_name): bool
    {
        if (isset(self::$table_exists_static_cache[$table_name])) {
            return self::$table_exists_static_cache[$table_name];
        }

        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);

        $exists = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
        );

        $wpdb->suppress_errors($suppress);

        self::$table_exists_static_cache[$table_name] = $exists;

        return $exists;
    }

    public static function clear_table_existence_cache(?string $table_name = null): void
    {
        if ($table_name !== null && $table_name !== '') {
            delete_transient('optistate_tbl_exists_' . md5($table_name));

            unset(
                self::$table_exists_static_cache[$table_name],
                self::$table_creation_cache[$table_name]
            );

            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_optistate_tbl_exists_') . '%',
                $wpdb->esc_like('_transient_timeout_optistate_tbl_exists_') . '%'
            )
        );

        self::$table_exists_static_cache = [];
        self::$table_creation_cache      = [];
    }

    public static function create_table_if_not_exists(string $table_name, string $sql, bool $use_dbdelta = true): bool
    {
        if (isset(self::$table_creation_cache[$table_name])) {
            return self::$table_creation_cache[$table_name];
        }

        $cache_key = 'optistate_tbl_exists_' . md5($table_name);

        $exists = (bool) self::get_or_set_transient(
            $cache_key,
            static function () use ($table_name, $sql, $use_dbdelta): bool {
                global $wpdb;

                $suppress = $wpdb->suppress_errors(true);

                $already_present = (bool) $wpdb->get_var(
                    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
                );

                $wpdb->suppress_errors($suppress);

                if ($already_present) {
                    return true;
                }

                $charset_collate = $wpdb->get_charset_collate();
                $escaped_table   = self::escape_identifier($table_name);

                if ($use_dbdelta) {
                    if (!function_exists('dbDelta')) {
                        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                    }

                    $full_sql = "CREATE TABLE {$table_name} ({$sql}) {$charset_collate}";

                    try {
                        $result = dbDelta($full_sql);

                        $success = isset($result[$table_name])
                            && stripos((string) $result[$table_name], 'created table') !== false;
                    } catch (\Throwable $e) {
                        self::log_critical_error(
                            "dbDelta threw an exception for table {$table_name}: " . $e->getMessage()
                        );

                        $success = false;
                    }

                    if (!$success) {
                        $success = (bool) $wpdb->get_var(
                            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
                        );
                    }
                } else {
                    $success = false !== $wpdb->query(
                        "CREATE TABLE IF NOT EXISTS {$escaped_table} ({$sql}) {$charset_collate}"
                    );
                }

                if ($success) {
                    return true;
                }

                self::log_critical_error(
                    'Table creation failed',
                    ['table' => $table_name, 'error' => $wpdb->last_error]
                );

                return false;
            },
            DAY_IN_SECONDS
        );

        self::$table_creation_cache[$table_name] = $exists;

        if ($exists) {
            self::$table_exists_static_cache[$table_name] = true;
        }

        return $exists;
    }

    public static function is_core_table(string $table_name): bool
    {
        global $wpdb;

        $base = preg_replace(
            '/^' . preg_quote($wpdb->base_prefix, '/') . '(\d+_)?/',
            '',
            $table_name
        );

        return in_array((string) $base, self::CORE_TABLES, true);
    }

    public static function generate_safe_table_name(string $original, string $prefix, int $max_length = 64): string
    {
        $original = (string) preg_replace('/[^a-zA-Z0-9_]/', '', $original);

        $full = $prefix . $original;

        if (strlen($full) <= $max_length) {
            return $full;
        }

        $hash            = substr(md5($original), 0, 8);
        $compact_prefix  = $prefix . $hash . '_';
        $available       = max(0, $max_length - strlen($compact_prefix));

        return $compact_prefix . substr($original, 0, $available);
    }

    public static function validate_table_name(string $table_name)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
            return false;
        }

        if (!self::table_exists($table_name)) {
            return false;
        }

        return self::escape_identifier($table_name);
    }

    public static function escape_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function get_optistate_core_excluded_tables(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        global $wpdb;

        $process_store = new OPTISTATE_Process_Store();

        $cache = [
            $process_store->get_table_name(),
            $wpdb->prefix . 'optistate_backup_metadata',
            $wpdb->prefix . OPTISTATE_Login_Protection::TABLE_NAME,
            $wpdb->prefix . 'optistate_core_data',
            $wpdb->prefix . 'optistate_trash',
        ];

        return $cache;
    }

    public static function get_all_excluded_tables(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        global $wpdb;

        $excluded = self::get_optistate_core_excluded_tables();

        $like_clauses = [];
        $like_values  = [];

        foreach (['optistate_old_', 'optistate_temp_', 'trash_'] as $prefix) {
            $like_clauses[] = 'TABLE_NAME LIKE %s';
            $like_values[]  = $wpdb->esc_like($wpdb->prefix . $prefix) . '%';
        }

        $query = $wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s AND (' . implode(' OR ', $like_clauses) . ')',
            DB_NAME,
            ...$like_values
        );

        $extra_tables = $wpdb->get_col($query);

        if (is_array($extra_tables)) {
            $excluded = array_merge($excluded, $extra_tables);
        }

        $cache = array_values(array_unique($excluded));

        return $cache;
    }

    private static function is_valid_session_var_name(string $name): bool
    {
        return (bool) preg_match(self::SESSION_VAR_NAME_PATTERN, $name);
    }

    private static function normalize_session_var_value($value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        if ($value === '') {
            return "''";
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return $value;
        }

        if (strcasecmp($value, 'DEFAULT') === 0) {
            return 'DEFAULT';
        }

        if (preg_match("/^'[^'\\\\]*'$/", $value)) {
            return $value;
        }

        if (preg_match('/^[A-Za-z0-9_,\- ]{1,255}$/', $value)) {
            return "'" . esc_sql($value) . "'";
        }

        return null;
    }

    public static function set_session_vars(array $vars, $db = null): void
    {
        if ($db === null) {
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
        }

        foreach ($vars as $var => $value) {
            $statement = self::build_session_var_statement((string) $var, $value);

            if ($statement === null) {
                continue;
            }

            try {
                $db->query($statement);
            } catch (\Throwable $e) {
                self::log_critical_error("Failed to set session variable {$var}: " . $e->getMessage());
            }
        }
    }

    private static function build_session_var_statement(string $var, $value): ?string
    {
        if (!self::is_valid_session_var_name($var)) {
            self::log_critical_error('Rejected malformed session variable name', ['name' => $var]);

            return null;
        }

        $literal = self::normalize_session_var_value($value);

        if ($literal === null) {
            self::log_critical_error('Rejected unsafe session variable value', ['name' => $var]);

            return null;
        }

        return "SET SESSION {$var} = {$literal}";
    }

    public static function with_session_vars(array $vars, callable $callback, $db = null)
    {
        global $wpdb;

        $originals = [];

        if ($db === null) {
            foreach ($vars as $var => $value) {
                $statement = self::build_session_var_statement((string) $var, $value);

                if ($statement === null) {
                    continue;
                }

                if (!self::is_valid_session_var_name((string) $var)) {
                    continue;
                }

                $originals[$var] = $wpdb->get_var("SELECT @@SESSION.{$var}");

                $wpdb->query($statement);
            }

            try {
                return $callback();
            } finally {
                foreach ($originals as $var => $original) {
                    if ($original === null) {
                        continue;
                    }

                    $restore = self::build_session_var_statement((string) $var, $original);

                    if ($restore !== null) {
                        $wpdb->query($restore);
                    }
                }
            }
        }

        foreach ($vars as $var => $value) {
            $statement = self::build_session_var_statement((string) $var, $value);

            if ($statement === null) {
                continue;
            }

            try {
                $result = $db->query("SELECT @@SESSION.{$var}");

                if ($result instanceof mysqli_result) {
                    $row             = $result->fetch_row();
                    $originals[$var] = $row[0] ?? null;
                    $result->free();
                } else {
                    $originals[$var] = null;
                }
            } catch (\Throwable $e) {
                $originals[$var] = null;
            }

            try {
                $db->query($statement);
            } catch (\Throwable $e) {
                self::log_critical_error("Failed to set session variable {$var}: " . $e->getMessage());
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($originals as $var => $original) {
                if ($original === null) {
                    continue;
                }

                $restore = self::build_session_var_statement((string) $var, $original);

                if ($restore === null) {
                    continue;
                }

                try {
                    $db->query($restore);
                } catch (\Throwable $e) {
                    self::log_critical_error("Failed to restore session variable {$var}: " . $e->getMessage());
                }
            }
        }
    }

    public static function with_stats_expiry_disabled(callable $callback)
    {
        global $wpdb;

        $orig_expiry = null;
        $modified    = false;

        if (version_compare(self::get_mysql_version(), '8.0.0', '>=')) {
            $var_exists = $wpdb->get_var("SHOW VARIABLES LIKE 'information_schema_stats_expiry'");

            if ($var_exists) {
                try {
                    $orig_expiry = $wpdb->get_var('SELECT @@SESSION.information_schema_stats_expiry');
                    $wpdb->query('SET SESSION information_schema_stats_expiry = 0');
                    $modified = true;
                } catch (\Throwable $e) {
                    self::log_critical_error('Failed to set stats_expiry: ' . $e->getMessage());
                    $modified = false;
                }
            }
        }

        try {
            return $callback();
        } finally {
            if ($modified && $orig_expiry !== null) {
                try {
                    $wpdb->query('SET SESSION information_schema_stats_expiry = ' . (int) $orig_expiry);
                } catch (\Throwable $e) {
                    self::log_critical_error('Failed to restore stats_expiry: ' . $e->getMessage());
                }
            }
        }
    }

    public static function without_foreign_key_checks(callable $callback, $db = null)
    {
        return self::with_session_vars(['FOREIGN_KEY_CHECKS' => 0], $callback, $db);
    }

    public static function transaction(callable $callback, $db = null)
    {
        global $wpdb;

        if ($db === null) {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \Exception('Failed to start transaction on wpdb: ' . $wpdb->last_error);
            }

            try {
                $result = $callback();

                if ($wpdb->query('COMMIT') === false) {
                    throw new \Exception('Failed to commit transaction on wpdb: ' . $wpdb->last_error);
                }

                return $result;
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');

                throw $e;
            }
        }

        $db_wrapper = OPTISTATE_DB_Wrapper::get_instance();

        try {
            $db_wrapper->begin_transaction();
        } catch (\Throwable $e) {
            self::log_critical_error('Begin transaction failed: ' . $e->getMessage());

            throw new \Exception('Database transaction start failed: ' . $e->getMessage());
        }

        try {
            $result = $callback();

            $db_wrapper->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $db_wrapper->rollback();
            } catch (\Throwable $rollback_e) {
                self::log_critical_error('Rollback failed after transaction error: ' . $rollback_e->getMessage());
            }

            throw $e;
        }
    }

    private static function get_derived_key(): string
    {
        static $derived_key = null;

        if ($derived_key !== null) {
            return $derived_key;
        }

        $salt_constants = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'];

        $has_salts = false;

        foreach ($salt_constants as $constant) {
            if (defined($constant) && is_string(constant($constant)) && constant($constant) !== '') {
                $has_salts = true;

                break;
            }
        }

        $salt_parts = [
            defined('AUTH_KEY') ? AUTH_KEY : '',
            defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
            defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '',
            defined('NONCE_KEY') ? NONCE_KEY : '',
            defined('DB_NAME') ? DB_NAME : '',
            $GLOBALS['table_prefix'] ?? '',
            get_option('siteurl'),
        ];

        $secret = implode('|', $salt_parts);

        if (!$has_salts) {
            $fallback = get_option('optistate_fallback_encryption_key');

            if (!is_string($fallback) || $fallback === '') {
                try {
                    $fallback = bin2hex(random_bytes(32));
                } catch (\Throwable $e) {
                    $fallback = bin2hex(
                        hash('sha256', uniqid((string) wp_rand(), true) . time() . DB_NAME, true)
                    );

                    self::log_critical_error(
                        'random_bytes failed for fallback encryption key, using degraded entropy. Error: '
                        . $e->getMessage()
                    );
                }

                update_option('optistate_fallback_encryption_key', $fallback, false);
            }

            $secret = $fallback;

            self::log_critical_error(
                'No WordPress salts defined; using fallback encryption key stored in options. '
                . 'Please define AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, and NONCE_KEY in wp-config.php.'
            );
        }

        $derived_key = hash('sha256', $secret, true);

        return $derived_key;
    }

    public static function encrypt_data($data)
    {
        if (empty($data) || !is_string($data)) {
            return $data;
        }

        if (!function_exists('openssl_encrypt')) {
            return $data;
        }

        $method     = 'AES-256-CBC';
        $key        = self::get_derived_key();
        $iv_length  = (int) openssl_cipher_iv_length($method);

        try {
            $iv = random_bytes($iv_length);
        } catch (\Throwable $e) {
            $iv = openssl_random_pseudo_bytes($iv_length);

            self::log_critical_error('random_bytes failed in encrypt_data, using fallback IV: ' . $e->getMessage());
        }

        $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            return $data;
        }

        return 'enc:' . base64_encode($iv . $encrypted);
    }

    public static function decrypt_data($data)
    {
        if (empty($data) || !is_string($data)) {
            return $data;
        }

        if (strpos($data, 'enc:') !== 0) {
            return $data;
        }

        if (!function_exists('openssl_decrypt')) {
            return $data;
        }

        $method  = 'AES-256-CBC';
        $key     = self::get_derived_key();
        $payload = base64_decode(substr($data, 4), true);

        if ($payload === false) {
            return $data;
        }

        $iv_length = (int) openssl_cipher_iv_length($method);

        if (strlen($payload) < $iv_length) {
            return $data;
        }

        $decrypted = openssl_decrypt(
            substr($payload, $iv_length),
            $method,
            $key,
            OPENSSL_RAW_DATA,
            substr($payload, 0, $iv_length)
        );

        if ($decrypted === false) {
            return $data;
        }

        return $decrypted;
    }

    public static function is_encrypted($value): bool
    {
        return is_string($value) && strpos($value, 'enc:') === 0;
    }

    public static function check_rate_limit(string $action, int $duration_in_seconds = 10): bool
    {
        $user_id = get_current_user_id();

        if ($user_id === 0) {
            return false;
        }

        $key = "optistate_rl_{$user_id}_{$action}";

        if (get_transient($key) !== false) {
            return false;
        }

        set_transient($key, 1, $duration_in_seconds);

        return true;
    }

    public static function get_rate_limit_message(bool $is_save = false): string
    {
        return $is_save
            ? __('Please wait a moment before saving again.', 'optistate')
            : __('Rate limit exceeded. Try again in a moment.', 'optistate');
    }

    public static function sanitize_error_message(string $message): string
    {
        return wp_kses(
            $message,
            [
                'br'     => [],
                'strong' => [],
                'em'     => [],
                'b'      => [],
                'i'      => [],
                'span'   => ['class' => true],
                'a'      => ['href' => true, 'target' => true, 'rel' => true],
                'code'   => [],
            ]
        );
    }

    public static function send_json_error($message, int $status = 400, array $extra = []): void
    {
        if (is_array($message)) {
            $extra   = array_merge($message, $extra);
            $message = $extra['message'] ?? __('An error occurred.', 'optistate');

            unset($extra['message']);
        }

        $response = ['message' => self::sanitize_error_message((string) $message)];

        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }

        wp_send_json_error($response, $status);
    }

    public static function send_json_success(array $data = [], int $status = 200): void
    {
        if (isset($data['message']) && is_string($data['message'])) {
            $data['message'] = self::sanitize_error_message($data['message']);
        }

        wp_send_json_success($data, $status);
    }

    public static function get_or_set_transient(string $key, callable $callback, $expiration = 0, bool $force = false)
    {
        if (!$force) {
            $cached = get_transient($key);

            if ($cached !== false) {
                return $cached;
            }
        }

        try {
            $value = $callback();
        } catch (\Throwable $e) {
            self::log_critical_error("get_or_set_transient callback failed for key '{$key}': " . $e->getMessage());

            return false;
        }

        set_transient($key, $value, (int) $expiration);

        return $value;
    }

    public static function log_critical_error(string $message, array $context = []): void
    {
        $log_message = '[WP Optimal State] ' . $message;

        if (!empty($context)) {
            $safe_context = [];

            if (isset($context['file'])) {
                $safe_context['file'] = basename((string) $context['file']);
            }

            if (isset($context['line'])) {
                $safe_context['line'] = (int) $context['line'];
            }

            if (isset($context['user_id'])) {
                $safe_context['user_id'] = (int) $context['user_id'];
            }

            if (isset($context['backup_file'])) {
                $safe_context['backup_file'] = basename((string) $context['backup_file']);
            }

            if (isset($context['table'])) {
                $safe_context['table'] = (string) $context['table'];
            }

            if (isset($context['error']) && is_scalar($context['error'])) {
                $safe_context['error'] = (string) $context['error'];
            }

            $safe_context['memory_usage'] = size_format(memory_get_usage(true), 2);
            $safe_context['peak_memory']  = size_format(memory_get_peak_usage(true), 2);

            if (defined('WP_START_TIMESTAMP')) {
                $safe_context['exec_time_sec'] = round(microtime(true) - WP_START_TIMESTAMP, 2);
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $trace     = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                $trace_str = '';

                foreach ($trace as $i => $frame) {
                    $trace_str .= sprintf(
                        ' #%d %s(%s)',
                        $i,
                        $frame['function'] ?? '?',
                        $frame['line'] ?? '?'
                    );
                }

                $safe_context['trace'] = $trace_str;
            }

            $encoded = wp_json_encode($safe_context);

            if ($encoded !== false) {
                $log_message .= ' | ' . $encoded;
            }
        }

        error_log($log_message);
    }

    public static function get_folder_size(
        string $path,
        int $max_files = 50000,
        int $max_depth = 5,
        bool $check_sensitive = false,
        ?callable $stop_callback = null
    ): array {
        $stats = ['size' => 0, 'sensitive' => false, 'file_count' => 0];

        if (!is_dir($path)) {
            return $stats;
        }

        $sensitive_pattern = '/\.(sql|backup|log|key|pem|crt|env|bak|old|db|sqlite|gz)$'
            . '|^(config\.php|wp-config\.php|credentials\.json|debug\.log|error_log|\.htaccess|\.htpasswd)$'
            . '|^backup-.*\.zip$/i';

        if (!$check_sensitive && $stop_callback === null && self::is_function_available('shell_exec')) {
            $output = @shell_exec('du -sb ' . escapeshellarg($path) . ' 2>/dev/null');

            if ($output !== null && preg_match('/^(\d+)\s+/', $output, $matches)) {
                $stats['size'] = (int) $matches[1];

                return $stats;
            }
        }

        try {
            $inner = new RecursiveDirectoryIterator(
                $path,
                FilesystemIterator::SKIP_DOTS
                | FilesystemIterator::FOLLOW_SYMLINKS
                | FilesystemIterator::CURRENT_AS_FILEINFO
            );

            $iterator = new RecursiveIteratorIterator($inner, RecursiveIteratorIterator::LEAVES_ONLY);
            $iterator->setMaxDepth($max_depth);

            foreach ($iterator as $file) {
                if ($stop_callback !== null && $stop_callback()) {
                    break;
                }

                if ($stats['file_count'] >= $max_files) {
                    break;
                }

                if (!$file->isFile()) {
                    continue;
                }

                $stats['size'] += (int) $file->getSize();
                $stats['file_count']++;

                if ($check_sensitive && !$stats['sensitive'] && preg_match($sensitive_pattern, $file->getFilename())) {
                    $stats['sensitive'] = true;
                }
            }
        } catch (\Throwable $e) {
            self::log_critical_error('Failed to scan folder stats: ' . $e->getMessage(), ['path' => $path]);
        }

        return $stats;
    }

    public static function cleanup_temp_files(
        $wp_filesystem,
        string $temp_dir,
        ?string $specific_file = null,
        int $max_age = 0,
        array $patterns = ['.sql', '.sql.gz', 'decompressed-', 'restore-temp-']
    ): bool {
        if (!$wp_filesystem || !$wp_filesystem->is_dir($temp_dir)) {
            return true;
        }

        $temp_dir = trailingslashit($temp_dir);

        if ($specific_file !== null && $specific_file !== '') {
            $filepath = $temp_dir . basename($specific_file);

            if (!$wp_filesystem->exists($filepath)) {
                return true;
            }

            try {
                return (bool) $wp_filesystem->delete($filepath);
            } catch (\Throwable $e) {
                self::log_critical_error("Failed to delete temp file {$filepath}: " . $e->getMessage());

                return false;
            }
        }

        $files = $wp_filesystem->dirlist($temp_dir);

        if (empty($files)) {
            return true;
        }

        $now = time();

        foreach ($files as $filename => $fileinfo) {
            if (($fileinfo['type'] ?? '') !== 'f') {
                continue;
            }

            $match = false;

            foreach ($patterns as $pattern) {
                if (strpos((string) $filename, $pattern) !== false) {
                    $match = true;

                    break;
                }
            }

            if (!$match) {
                continue;
            }

            if ($max_age > 0) {
                $mtime = isset($fileinfo['lastmodunix'])
                    ? (int) $fileinfo['lastmodunix']
                    : (int) $wp_filesystem->mtime($temp_dir . $filename);

                if ($mtime && $now - $mtime < $max_age) {
                    continue;
                }
            }

            $wp_filesystem->delete($temp_dir . $filename);
        }

        return true;
    }

    public static function local_time_to_timestamp(string $time_string): int
    {
        try {
            $tz = wp_timezone();

            if (!$tz instanceof DateTimeZone) {
                $tz = new DateTimeZone('UTC');
            }

            $now    = new DateTimeImmutable('now', $tz);
            $target = DateTimeImmutable::createFromFormat('H:i', $time_string, $tz);

            if ($target === false) {
                $target = new DateTimeImmutable('tomorrow midnight', $tz);
            }

            if ($target <= $now) {
                $target = $target->modify('+1 day');
            }

            return $target->getTimestamp();
        } catch (\Throwable $e) {
            self::log_critical_error(
                'local_time_to_timestamp failed',
                ['time_string' => $time_string, 'error' => $e->getMessage()]
            );

            return time() + 3600;
        }
    }

    public static function format_timestamp($timestamp): string
    {
        $timestamp = (int) $timestamp;

        if (!$timestamp) {
            return __('N/A', 'optistate');
        }

        $format = self::get_cached_option('date_format') . ' ' . self::get_cached_option('time_format');

        return wp_date($format, $timestamp);
    }

    public static function get_failure_type_label(string $failure_type): string
    {
        $labels = [
            'backup_failed'  => __('Backup Creation Failed', 'optistate'),
            'cleanup_failed' => __('Cleanup Operations Failed', 'optistate'),
            'exception'      => __('Unexpected Exception', 'optistate'),
        ];

        return $labels[$failure_type] ?? __('Unknown Failure', 'optistate');
    }

    public static function force_plain_text_mail_type($content_type): string
    {
        return 'text/plain';
    }

    public static function get_client_ip(bool $cloudflare_enabled = false, array $trusted_proxies = []): string
    {
        static $cache = [];

        $trusted_cache_key = ($cloudflare_enabled ? '1' : '0') . '|' . implode(',', $trusted_proxies);

        if (!isset(self::$trusted_proxy_cache[$trusted_cache_key])) {
            $default_trusted = ['127.0.0.1', '::1'];

            if ($cloudflare_enabled) {
                $default_trusted = array_merge($default_trusted, self::get_cloudflare_ip_ranges());
            }

            self::$trusted_proxy_cache[$trusted_cache_key] = (array) apply_filters(
                'optistate_trusted_proxies',
                array_merge($default_trusted, $trusted_proxies)
            );
        }

        $trusted = self::$trusted_proxy_cache[$trusted_cache_key];

        $cache_key = ($cloudflare_enabled ? '1' : '0') . '|' . implode(',', $trusted);

        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ip          = $remote_addr;

        $is_trusted = false;

        foreach ($trusted as $range) {
            if (self::ip_in_range($ip, (string) $range)) {
                $is_trusted = true;

                break;
            }
        }

        if ($is_trusted) {
            if ($cloudflare_enabled && isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $cf_ip = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);

                if (self::is_valid_public_ip($cf_ip)) {
                    $ip = $cf_ip;
                }
            } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = self::resolve_forwarded_ip((string) $_SERVER['HTTP_X_FORWARDED_FOR'], $trusted, $ip);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);

            if ($packed !== false && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                $ipv4 = inet_ntop(substr($packed, 12));

                if ($ipv4 !== false) {
                    $ip = $ipv4;
                }
            }
        }

        $ip = (string) preg_replace('/[^a-fA-F0-9:.]/', '', $ip);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = $remote_addr;
        }

        $cache[$cache_key] = substr($ip, 0, 45);

        return $cache[$cache_key];
    }

    private static function resolve_forwarded_ip(string $header, array $trusted, string $fallback): string
    {
        $chain = array_map('trim', explode(',', $header));

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $current = $chain[$i];

            if (!filter_var($current, FILTER_VALIDATE_IP)) {
                continue;
            }

            $is_proxy = false;

            foreach ($trusted as $proxy_range) {
                if (self::ip_in_range($current, (string) $proxy_range)) {
                    $is_proxy = true;

                    break;
                }
            }

            if (!$is_proxy) {
                return $current;
            }
        }

        $first = isset($chain[0]) ? trim($chain[0]) : '';

        return filter_var($first, FILTER_VALIDATE_IP) ? $first : $fallback;
    }

    public static function ip_in_range(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::ipv6_in_range($ip, $range);
        }

        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, '');

        if (!ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);

        if ($ip_long === false || $subnet_long === false) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    private static function ipv6_in_range(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, '');

        if (!ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 128) {
            return false;
        }

        $ip_packed     = inet_pton($ip);
        $subnet_packed = inet_pton($subnet);

        if ($ip_packed === false || $subnet_packed === false || strlen($subnet_packed) !== 16) {
            return false;
        }

        $bytes          = intdiv($bits, 8);
        $remaining_bits = $bits % 8;

        if ($bytes > 0 && substr($ip_packed, 0, $bytes) !== substr($subnet_packed, 0, $bytes)) {
            return false;
        }

        if ($remaining_bits > 0) {
            $mask = 0xff << (8 - $remaining_bits) & 0xff;

            if ((ord($ip_packed[$bytes]) & $mask) !== (ord($subnet_packed[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    public static function is_valid_public_ip(string $ip): bool
    {
        if (apply_filters('optistate_allow_private_ip_in_headers', false)) {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    public static function get_cloudflare_ip_ranges(): array
    {
        if (self::$cloudflare_ips_cache !== null) {
            return self::$cloudflare_ips_cache;
        }

        $ranges = self::get_or_set_transient(
            'optistate_cloudflare_ips',
            static function (): array {
                return [
                    '173.245.48.0/20',
                    '103.21.244.0/22',
                    '103.22.200.0/22',
                    '103.31.4.0/22',
                    '141.101.64.0/18',
                    '108.162.192.0/18',
                    '190.93.240.0/20',
                    '188.114.96.0/20',
                    '197.234.240.0/22',
                    '198.41.128.0/17',
                    '162.158.0.0/15',
                    '104.16.0.0/13',
                    '104.24.0.0/14',
                    '172.64.0.0/13',
                    '131.0.72.0/22',
                    '2400:cb00::/32',
                    '2606:4700::/32',
                    '2803:f800::/32',
                    '2405:b500::/32',
                    '2405:8100::/32',
                    '2a06:98c0::/29',
                    '2c0f:f248::/32',
                ];
            },
            WEEK_IN_SECONDS
        );

        self::$cloudflare_ips_cache = is_array($ranges) ? $ranges : [];

        return self::$cloudflare_ips_cache;
    }

    public static function validate_ip_or_cidr(string $string): bool
    {
        if (filter_var($string, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (strpos($string, '/') === false) {
            return false;
        }

        $parts = explode('/', $string, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$ip, $bits] = $parts;

        if (!filter_var($ip, FILTER_VALIDATE_IP) || !ctype_digit($bits)) {
            return false;
        }

        $bits_int = (int) $bits;
        $version  = strpos($ip, ':') !== false ? 6 : 4;

        if ($version === 4) {
            return $bits_int <= 32;
        }

        return $bits_int <= 128;
    }

    public static function get_bot_rules(string $user_agents_string, bool $include_whitelist_bypass = false): string
    {
        if ($user_agents_string === '') {
            return '';
        }

        $cache_key = md5($user_agents_string . ($include_whitelist_bypass ? '1' : '0'));

        if (isset(self::$bot_rules_cache[$cache_key])) {
            return self::$bot_rules_cache[$cache_key];
        }

        $bots = array_filter(array_map('trim', explode("\n", $user_agents_string)));

        if (empty($bots)) {
            self::$bot_rules_cache[$cache_key] = '';

            return '';
        }

        $rules = [
            '# ============================================================',
            '# BEGIN WP Optimal State Bot Blocking',
            '# ============================================================',
            '<IfModule mod_setenvif.c>',
        ];

        foreach ($bots as $bot) {
            $bot = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $bot);
            $bot = trim($bot);

            if ($bot === '') {
                continue;
            }

            $safe_bot = addcslashes($bot, '."^$*+?[]{}()|\\');
            $safe_bot = str_replace(' ', '[[:space:]]', $safe_bot);

            $rules[] = sprintf('  SetEnvIfNoCase User-Agent "%s" bad_bot', $safe_bot);
        }

        $rules[] = '';
        $rules[] = '  # Allow access to admin area and login page (prevent admin lockout)';
        $rules[] = '  SetEnvIf Request_URI "^/wp-admin/" allow_admin';
        $rules[] = '  SetEnvIf Request_URI "^/wp-login\.php" allow_admin';
        $rules[] = '  SetEnvIf Request_URI "^/wp-includes/" allow_admin';
        $rules[] = '  SetEnvIf Request_URI "^/wp-content/.*\.(css|js|png|jpg|jpeg|gif|webp|svg|woff|woff2|ttf|eot)$" allow_admin';
        $rules[] = '';
        $rules[] = '  <IfModule mod_authz_core.c>';
        $rules[] = '    <RequireAny>';

        if ($include_whitelist_bypass) {
            $rules[] = '      Require env OptiWhitelisted';
        }

        $rules[] = '      Require env allow_admin';
        $rules[] = '      <RequireAll>';
        $rules[] = '        Require all granted';
        $rules[] = '        Require not env bad_bot';
        $rules[] = '      </RequireAll>';
        $rules[] = '    </RequireAny>';
        $rules[] = '  </IfModule>';
        $rules[] = '';
        $rules[] = '  <IfModule !mod_authz_core.c>';
        $rules[] = '    Order Deny,Allow';

        if ($include_whitelist_bypass) {
            $rules[] = '    Allow from env=OptiWhitelisted';
        }

        $rules[] = '    Allow from env=allow_admin';
        $rules[] = '    Deny from env=bad_bot';
        $rules[] = '    Allow from all';
        $rules[] = '  </IfModule>';
        $rules[] = '</IfModule>';
        $rules[] = '# ============================================================';
        $rules[] = '# END WP Optimal State Bot Blocking';
        $rules[] = '# ============================================================';

        $final = implode(PHP_EOL, $rules);

        self::$bot_rules_cache[$cache_key] = $final;

        return $final;
    }

    public static function get_ip_block_rules(string $raw_ips, bool $include_whitelist_bypass = true): string
    {
        $ips = array_filter(array_map('trim', explode("\n", $raw_ips)));
        $ips = array_filter($ips, [__CLASS__, 'validate_ip_or_cidr']);

        if (empty($ips)) {
            return '';
        }

        $rules = '# BEGIN WP Optimal State IP Blocking' . PHP_EOL;
        $rules .= '<IfModule mod_setenvif.c>' . PHP_EOL;

        foreach ($ips as $ip) {
            $safe_ip = str_replace('"', '\"', preg_quote(sanitize_text_field($ip), '/'));

            $rules .= '  SetEnvIf X-Forwarded-For "^' . $safe_ip . '$" OptiBlockedIP' . PHP_EOL;
            $rules .= '  SetEnvIf X-Real-IP "^' . $safe_ip . '$" OptiBlockedIP' . PHP_EOL;
            $rules .= '  SetEnvIf CF-Connecting-IP "^' . $safe_ip . '$" OptiBlockedIP' . PHP_EOL;
        }

        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '<IfModule mod_authz_core.c>' . PHP_EOL;
        $rules .= '  <RequireAny>' . PHP_EOL;

        if ($include_whitelist_bypass) {
            $rules .= '    Require env OptiWhitelisted' . PHP_EOL;
        }

        $rules .= '    <RequireAll>' . PHP_EOL;
        $rules .= '      Require all granted' . PHP_EOL;

        foreach ($ips as $ip) {
            $rules .= '      Require not ip ' . sanitize_text_field($ip) . PHP_EOL;
        }

        $rules .= '      Require not env OptiBlockedIP' . PHP_EOL;
        $rules .= '    </RequireAll>' . PHP_EOL;
        $rules .= '  </RequireAny>' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '<IfModule !mod_authz_core.c>' . PHP_EOL;
        $rules .= '  Order Deny,Allow' . PHP_EOL;

        if ($include_whitelist_bypass) {
            $rules .= '  Allow from env=OptiWhitelisted' . PHP_EOL;
        }

        $rules .= '  Allow from all' . PHP_EOL;

        foreach ($ips as $ip) {
            $rules .= '  Deny from ' . sanitize_text_field($ip) . PHP_EOL;
        }

        $rules .= '  Deny from env=OptiBlockedIP' . PHP_EOL;
        $rules .= '</IfModule>' . PHP_EOL;
        $rules .= '# END WP Optimal State IP Blocking';

        return $rules;
    }

    public static function get_caching_rules(): string
    {
        if (self::$caching_rules_cache !== null) {
            return self::$caching_rules_cache;
        }

        $rules = [
            '# ============================================================',
            '# BEGIN WP Optimal State Caching',
            '# ============================================================',
            '',
            '# ------------------------------',
            '# 1. EXPIRATION HEADERS (mod_expires)',
            '# ------------------------------',
            '<IfModule mod_expires.c>',
            '  ExpiresActive On',
            '  ExpiresDefault "access plus 30 days"',
            '  ExpiresByType image/jpeg "access plus 1 year"',
            '  ExpiresByType image/png "access plus 1 year"',
            '  ExpiresByType image/gif "access plus 1 year"',
            '  ExpiresByType image/webp "access plus 1 year"',
            '  ExpiresByType image/avif "access plus 1 year"',
            '  ExpiresByType image/svg+xml "access plus 1 year"',
            '  ExpiresByType image/x-icon "access plus 1 year"',
            '  ExpiresByType font/woff2 "access plus 1 year"',
            '  ExpiresByType font/woff "access plus 1 year"',
            '  ExpiresByType font/ttf "access plus 1 year"',
            '  ExpiresByType font/otf "access plus 1 year"',
            '  ExpiresByType video/mp4 "access plus 1 year"',
            '  ExpiresByType video/webm "access plus 1 year"',
            '  ExpiresByType audio/mpeg "access plus 1 year"',
            '  ExpiresByType audio/ogg "access plus 1 year"',
            '  ExpiresByType application/wasm "access plus 1 year"',
            '  ExpiresByType text/css "access plus 1 month"',
            '  ExpiresByType application/javascript "access plus 1 month"',
            '  ExpiresByType application/x-javascript "access plus 1 month"',
            '  ExpiresByType text/html "access plus 24 hours"',
            '</IfModule>',
            '',
            '# ------------------------------',
            '# 2. CACHE-CONTROL HEADERS (mod_headers)',
            '# ------------------------------',
            '<IfModule mod_headers.c>',
            '',
            '  <FilesMatch "\.(css|js|ico|jpg|jpeg|png|gif|webp|avif|svg|woff2|woff|ttf|eot|mp4|webm|mp3|ogg|aac|m4a|flac|wasm)$">',
            '    Header set Cache-Control "max-age=31536000, public, immutable"',
            '  </FilesMatch>',
            '',
            '  <FilesMatch "\.(html|htm)$">',
            '    Header set Cache-Control "public, max-age=86400, must-revalidate" env=!PHP_CACHE_HEADERS',
            '  </FilesMatch>',
            '',
            '  <FilesMatch "^(wp-config\.php|readme\.html|license\.txt|xmlrpc\.php)$">',
            '    Header set Cache-Control "no-cache, no-store, must-revalidate"',
            '    Header set Pragma "no-cache"',
            '    Header set Expires "0"',
            '  </FilesMatch>',
            '',
            '  <If "%{REQUEST_URI} =~ m#^/wp-login\.php# || %{REQUEST_URI} =~ m#^/wp-admin/#">',
            '    Header set Cache-Control "no-cache, no-store, must-revalidate"',
            '    Header set Pragma "no-cache"',
            '    Header set Expires "0"',
            '  </If>',
            '',
            '  Header set Vary "Accept-Encoding"',
            '',
            '  Header unset ETag',
            '  FileETag None',
            '',
            '</IfModule>',
            '',
            '# ------------------------------',
            '# 3. COMPRESSION (mod_brotli + mod_deflate)',
            '# ------------------------------',
            '',
            '<IfModule mod_brotli.c>',
            '  AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/xml text/javascript',
            '  AddOutputFilterByType BROTLI_COMPRESS application/javascript application/json application/xml application/xhtml+xml',
            '  AddOutputFilterByType BROTLI_COMPRESS application/rss+xml application/wasm',
            '  AddOutputFilterByType BROTLI_COMPRESS image/svg+xml',
            '</IfModule>',
            '',
            '<IfModule mod_deflate.c>',
            '  DeflateCompressionLevel 6',
            '  AddOutputFilterByType DEFLATE text/plain',
            '  AddOutputFilterByType DEFLATE text/html',
            '  AddOutputFilterByType DEFLATE text/css',
            '  AddOutputFilterByType DEFLATE text/xml',
            '  AddOutputFilterByType DEFLATE text/javascript',
            '  AddOutputFilterByType DEFLATE application/javascript',
            '  AddOutputFilterByType DEFLATE application/json',
            '  AddOutputFilterByType DEFLATE application/xml',
            '  AddOutputFilterByType DEFLATE application/xhtml+xml',
            '  AddOutputFilterByType DEFLATE application/rss+xml',
            '  AddOutputFilterByType DEFLATE application/wasm',
            '  AddOutputFilterByType DEFLATE image/svg+xml',
            '  AddOutputFilterByType DEFLATE image/x-icon',
            '  AddOutputFilterByType DEFLATE font/woff',
            '  AddOutputFilterByType DEFLATE font/woff2',
            '  SetEnvIfNoCase Request_URI "\.(?:gz|br|zip|bz2|rar|7z|xz)$" no-gzip dont-vary',
            '  SetEnvIfNoCase Request_URI "\.(?:jpg|jpeg|png|gif|webp|avif|ico)$" no-gzip dont-vary',
            '  SetEnvIfNoCase Request_URI "\.(?:woff|woff2|ttf|otf|eot)$" no-gzip dont-vary',
            '  SetEnvIfNoCase Request_URI "\.(?:mp4|webm|avi|mov|mkv|flv|ogv)$" no-gzip dont-vary',
            '  SetEnvIfNoCase Request_URI "\.(?:mp3|ogg|aac|m4a|flac|wav|opus)$" no-gzip dont-vary',
            '  SetEnvIfNoCase Request_URI "\.pdf$" no-gzip dont-vary',
            '  SetEnvIfNoCase Request_URI "\.swf$" no-gzip dont-vary',
            '</IfModule>',
            '',
            '# ------------------------------',
            '# 4. PERFORMANCE TUNING',
            '# ------------------------------',
            '',
            'Options -Indexes',
            '',
            '# ============================================================',
            '# END WP Optimal State Caching',
            '# ============================================================',
        ];

        self::$caching_rules_cache = implode(PHP_EOL, $rules);

        return self::$caching_rules_cache;
    }

    public static function get_security_headers_rules(bool $optional_headers = false): string
    {
        if (self::$security_headers_rules_cache !== null && !$optional_headers) {
            return self::$security_headers_rules_cache;
        }

        $rules = [
            '# ============================================================',
            '# BEGIN WP Optimal State Security Headers',
            '# ============================================================',
            '',
            '<IfModule mod_headers.c>',
            '',
            '  Header always set X-Content-Type-Options "nosniff"',
            '  Header always set X-Frame-Options "SAMEORIGIN"',
            '  Header always set Referrer-Policy "strict-origin-when-cross-origin"',
            '  Header unset X-Powered-By',
            '  Header always set X-Permitted-Cross-Domain-Policies "none"',
            '  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=(), gyroscope=(), accelerometer=(), magnetometer=(), fullscreen=(self)"',
            '  Header always set Cross-Origin-Resource-Policy "same-site"',
            '  Header always set Content-Security-Policy "frame-ancestors \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'"',
        ];

        if ($optional_headers) {
            $rules[] = '  Header always set Cross-Origin-Opener-Policy "same-origin"';
            $rules[] = '  Header always set Cross-Origin-Embedder-Policy "require-corp"';
            $rules[] = '  Header always set X-DNS-Prefetch-Control "off"';
        }

        $rules[] = '';
        $rules[] = '</IfModule>';
        $rules[] = '';

        if ($optional_headers) {
            $rules[] = '# ------------------------------';
            $rules[] = '# HSTS with includeSubDomains and preload (optional)';
            $rules[] = '# ------------------------------';
            $rules[] = '<IfModule mod_ssl.c>';
            $rules[] = '  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"';
            $rules[] = '</IfModule>';
        } else {
            $rules[] = '# ------------------------------';
            $rules[] = '# HTTPS / HSTS (only on SSL vhosts)';
            $rules[] = '# ------------------------------';
            $rules[] = '<IfModule mod_ssl.c>';
            $rules[] = '  Header always set Strict-Transport-Security "max-age=31536000"';
            $rules[] = '</IfModule>';
        }

        $rules[] = '';
        $rules[] = '# ============================================================';
        $rules[] = '# END WP Optimal State Security Headers';
        $rules[] = '# ============================================================';

        $final = implode(PHP_EOL, $rules);

        if (!$optional_headers) {
            self::$security_headers_rules_cache = $final;
        }

        return $final;
    }

    public static function deny_bot_access(): void
    {
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/plain');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        echo 'Access Denied';
        exit();
    }

    public static function apply_header_cleanups(array $settings): void
    {
        if (!empty($settings['rest_api_link'])) {
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('template_redirect', 'rest_output_link_header', 11);
        }

        if (!empty($settings['shortlink'])) {
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        }

        if (!empty($settings['rsd_link'])) {
            remove_action('wp_head', 'rsd_link');
        }

        if (!empty($settings['wlwmanifest'])) {
            remove_action('wp_head', 'wlwmanifest_link');
        }

        if (!empty($settings['wp_generator'])) {
            remove_action('wp_head', 'wp_generator');
        }

        if (!empty($settings['feed_links'])) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        if (!empty($settings['post_relational_links'])) {
            remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
            remove_action('wp_head', 'index_rel_link');
            remove_action('wp_head', 'start_post_rel_link');
            remove_action('wp_head', 'parent_post_rel_link');
            remove_action('wp_head', 'rel_canonical');
        }

        if (!empty($settings['xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', '__return_empty_array');
        }
    }

    public static function disable_emoji_scripts(): void
    {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        add_filter('emoji_svg_url', '__return_false');
        add_filter('tiny_mce_plugins', [__CLASS__, 'remove_tinymce_emoji']);
    }

    public static function disable_xmlrpc(): void
    {
        add_filter('xmlrpc_enabled', '__return_false');
        remove_action('wp_head', 'rsd_link');
        add_filter('xmlrpc_methods', '__return_empty_array');
    }

    public static function disable_self_pingbacks(): void
    {
        add_action('pre_ping', [__CLASS__, 'filter_self_pingbacks']);
    }

    public static function remove_tinymce_emoji($plugins): array
    {
        return is_array($plugins) ? array_values(array_diff($plugins, ['wpemoji'])) : [];
    }

    public static function filter_self_pingbacks(&$links): void
    {
        if (!is_array($links)) {
            return;
        }

        $home = (string) get_option('home');

        if ($home === '') {
            return;
        }

        foreach ($links as $key => $link) {
            if (is_string($link) && strpos($link, $home) === 0) {
                unset($links[$key]);
            }
        }
    }

    public static function font_opt_resource_hints($urls, string $relation_type)
    {
        if ($relation_type === 'preconnect' && is_array($urls)) {
            $urls[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'];
            $urls[] = ['href' => 'https://fonts.googleapis.com', 'crossorigin' => ''];
        }

        return $urls;
    }

    public static function font_opt_remove_google_fonts(): void
    {
        global $wp_styles;

        if (!isset($wp_styles->queue) || !is_array($wp_styles->queue)) {
            return;
        }

        foreach ($wp_styles->queue as $handle) {
            $src = $wp_styles->registered[$handle]->src ?? '';

            if (strpos((string) $src, 'fonts.googleapis.com') !== false
                || strpos((string) $src, 'fonts.gstatic.com') !== false) {
                wp_dequeue_style($handle);
            }
        }
    }

    public static function add_async_decoding($filtered_image, $context, $attachment_id): string
    {
        $filtered_image = (string) $filtered_image;
        $context        = is_string($context) ? $context : '';

        static $image_processed_count = 0;

        $high_priority_contexts = ['header', 'hero', 'banner', 'above-fold', 'lcp', 'logo', 'masthead'];

        $result = preg_replace_callback(
            '/<img\s+([^>]*?)>/is',
            static function ($matches) use (&$image_processed_count, $context, $high_priority_contexts): string {
                $original_attributes = $matches[1];

                $image_processed_count++;

                $has_decoding      = preg_match('/\bdecoding\s*=/i', $original_attributes);
                $has_loading       = preg_match('/\bloading\s*=/i', $original_attributes);
                $has_fetchpriority = preg_match('/\bfetchpriority\s*=/i', $original_attributes);

                $is_priority = $image_processed_count <= self::LCP_IMAGE_THRESHOLD;

                if (!$is_priority && $context !== '') {
                    $context_lower = strtolower($context);

                    foreach ($high_priority_contexts as $ctx) {
                        if (strpos($context_lower, $ctx) !== false) {
                            $is_priority = true;

                            break;
                        }
                    }
                }

                if ($has_decoding && $has_loading && (!$is_priority || $has_fetchpriority)) {
                    return $matches[0];
                }

                $to_add = [];

                if (!$has_decoding) {
                    $to_add[] = 'decoding="async"';
                }

                if (!$has_loading) {
                    $to_add[] = $is_priority ? 'loading="eager"' : 'loading="lazy"';
                }

                if ($is_priority && !$has_fetchpriority) {
                    $to_add[] = 'fetchpriority="high"';
                }

                return empty($to_add)
                    ? $matches[0]
                    : '<img ' . implode(' ', $to_add) . ' ' . ltrim($original_attributes) . '>';
            },
            $filtered_image
        );

        return $result ?? $filtered_image;
    }

    public static function apply_heartbeat_optimization(string $mode): void
    {
        switch ($mode) {
            case 'slow':
                add_filter('heartbeat_settings', static function ($settings) {
                    $settings['interval'] = max((int) ($settings['interval'] ?? 0), 120);

                    return $settings;
                });

                break;

            case 'disable_admin':
                add_action('admin_enqueue_scripts', static function (): void {
                    wp_deregister_script('heartbeat');
                    wp_register_script('heartbeat', '', [], false, true);
                }, 100);

                break;

            case 'disable_frontend':
                add_action('wp_enqueue_scripts', static function (): void {
                    wp_deregister_script('heartbeat');
                }, 100);

                break;

            case 'disable_all':
                add_action('wp_enqueue_scripts', static function (): void {
                    wp_deregister_script('heartbeat');
                }, 100);

                add_action('admin_enqueue_scripts', static function (): void {
                    wp_deregister_script('heartbeat');
                    wp_register_script('heartbeat', '', [], false, true);
                }, 100);

                break;
        }
    }

    public static function apply_revision_limit(string $mode): void
    {
        if (defined('WP_POST_REVISIONS')) {
            return;
        }

        $values = ['limit_3' => 3, 'limit_5' => 5, 'limit_10' => 10, 'disable' => false];

        if (array_key_exists($mode, $values)) {
            define('WP_POST_REVISIONS', $values[$mode]);
        }
    }

    public static function apply_trash_days(string $mode): void
    {
        if (defined('EMPTY_TRASH_DAYS')) {
            return;
        }

        $values = [
            'disable' => 0,
            'days_7'  => 7,
            'days_14' => 14,
            'days_30' => 30,
            'days_60' => 60,
            'days_90' => 90,
        ];

        if (array_key_exists($mode, $values)) {
            define('EMPTY_TRASH_DAYS', $values[$mode]);
        }
    }

    public static function init_query_cache_config(array $config): void
    {
        self::$query_cache_config = wp_parse_args($config, [
            'excluded_post_types' => [],
            'excluded_ids'        => [],
            'ttl_main'            => 43200,
            'ttl_secondary'       => 86400,
            'max_cache_size'      => 500,
            'flush_on_comments'   => true,
            'flush_on_save'       => true,
        ]);
    }

    public static function intercept_query($posts, $query)
    {
        if ($posts !== null || !empty($query->query_vars['suppress_filters'])) {
            return $posts;
        }

        if (!self::should_cache_query($query)) {
            return $posts;
        }

        $query_type = $query->is_main_query() ? 'main' : 'secondary';
        $cache_key  = self::generate_query_cache_key($query, $query_type);

        $cached = wp_cache_get($cache_key, 'optistate_query_cache');

        if (is_array($cached) && isset($cached['posts'])) {
            $query->found_posts   = (int) $cached['found_posts'];
            $query->max_num_pages = (int) $cached['max_num_pages'];
            $query->post_count    = count($cached['posts']);

            return $cached['posts'];
        }

        return null;
    }

    public static function cache_query_results($posts, $query)
    {

        if (!is_array($posts)) {
            return $posts;
        }

        if (!empty($query->query_vars['suppress_filters'])) {
            return $posts;
        }

        if (!self::should_cache_query($query)) {
            return $posts;
        }

        if (count($posts) > (int) (self::$query_cache_config['max_cache_size'] ?? 500)) {
            return $posts;
        }

        $query_type = $query->is_main_query() ? 'main' : 'secondary';
        $cache_key  = self::generate_query_cache_key($query, $query_type);

        $ttl = $query_type === 'main'
            ? (int) (self::$query_cache_config['ttl_main'] ?? 43200)
            : (int) (self::$query_cache_config['ttl_secondary'] ?? 86400);

        wp_cache_set(
            $cache_key,
            [
                'posts'         => $posts,
                'found_posts'   => isset($query->found_posts) ? (int) $query->found_posts : count($posts),
                'max_num_pages' => isset($query->max_num_pages) ? (int) $query->max_num_pages : 1,
            ],
            'optistate_query_cache',
            $ttl
        );

        return $posts;
    }

    private static function should_cache_query($query): bool
    {
        if (!is_object($query) || !method_exists($query, 'get')) {
            return false;
        }

        if (is_user_logged_in() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }

        if (isset($query->query_vars['cache_results']) && false === $query->query_vars['cache_results']) {
            return false;
        }

        if ($query->get('orderby') === 'rand') {
            return false;
        }

        $post_type = $query->get('post_type');

        if (!empty($post_type) && !empty(self::$query_cache_config['excluded_post_types'])) {
            $excluded = self::$query_cache_config['excluded_post_types'];

            if (is_array($post_type)) {
                foreach ($post_type as $type) {
                    if (is_scalar($type) && isset($excluded[$type])) {
                        return false;
                    }
                }
            } elseif (is_scalar($post_type) && isset($excluded[$post_type])) {
                return false;
            }
        }

        $excluded_ids = self::$query_cache_config['excluded_ids'] ?? [];

        if (!empty($excluded_ids)) {
            $p = $query->get('p');

            if ($p && isset($excluded_ids[$p])) {
                return false;
            }

            $page_id = $query->get('page_id');

            if ($page_id && isset($excluded_ids[$page_id])) {
                return false;
            }

            $post_in = $query->get('post__in');

            if (!empty($post_in) && is_array($post_in)) {
                foreach ($post_in as $id) {
                    if (isset($excluded_ids[$id])) {
                        return false;
                    }
                }
            }
        }

        return (bool) apply_filters('optistate_should_cache_query', true, $query);
    }

    public static function generate_query_cache_key($query, string $query_type): string
    {
        $relevant_keys = [
            'post_type', 'p', 'page_id', 'category__in', 'tag__in', 'posts_per_page',
            'orderby', 'order', 'paged', 's', 'meta_key', 'meta_value', 'meta_compare',
            'tax_query', 'post__in', 'post__not_in', 'year', 'monthnum', 'day', 'author',
            'author__in', 'author__not_in', 'category_name', 'tag', 'tag_id', 'cat',
            'category__and', 'category__not_in', 'tag__and', 'tag__not_in', 'post_parent',
            'post_parent__in', 'post_parent__not_in', 'post_status', 'has_password',
            'post_mime_type', 'perm', 'comment_count',
        ];

        $filtered = array_intersect_key($query->query_vars, array_flip($relevant_keys));

        foreach ($filtered as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = wp_json_encode($value);
            }
        }

        $context = [
            'v'    => self::get_version($query_type),
            'vars' => $filtered,
            'blog' => get_current_blog_id(),
        ];

        return 'os_q_' . $query_type . '_' . md5((string) wp_json_encode($context));
    }

    private static function get_version(string $type): int
    {
        if (isset(self::$version_cache[$type])) {
            return self::$version_cache[$type];
        }

        $key     = 'optistate_cv_' . $type;
        $version = (int) wp_cache_get($key, 'optistate_query_cache');

        if (!$version) {
            $version = (int) get_option($key, time());

            wp_cache_set($key, $version, 'optistate_query_cache', 0);
        }

        self::$version_cache[$type] = $version;

        return $version;
    }

    public static function flush_cache_group_main(): void
    {
        self::increment_version('main');
    }

    public static function flush_cache_group_secondary(): void
    {
        self::increment_version('secondary');
    }

    private static function increment_version(string $type): void
    {
        $key = 'optistate_cv_' . $type;

        $new_val = wp_cache_incr($key, 1, 'optistate_query_cache');

        if (false === $new_val) {
            $new_val = (int) get_option($key, time()) + 1;

            update_option($key, $new_val, true);
            wp_cache_set($key, $new_val, 'optistate_query_cache', 0);
        }

        self::$version_cache[$type] = (int) $new_val;
    }

    public static function activate_maintenance_mode(): void
    {
        update_option('optistate_maintenance_mode_active', true, false);
    }

    public static function deactivate_maintenance_mode(): void
    {
        delete_option('optistate_maintenance_mode_active');
    }
}
