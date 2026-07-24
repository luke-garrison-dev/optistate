<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Trash_Manager
{
    private OPTISTATE $main_plugin;

    private ?object $wp_filesystem = null;
    private bool $filesystem_failed = false;

    private ?string $trash_table_cache = null;
    private ?string $trash_table_sql_cache = null;
    private ?string $trash_dir_cache = null;

    private static ?bool $table_exists_cache = null;
    private static bool $table_verified = false;
    private static bool $table_cache_dirty = false;

    private const TRASH_MAX_AGE = 14 * DAY_IN_SECONDS;
    private const TABLE_TRANSIENT = "optistate_trash_table_exists";
    private const TABLE_TRANSIENT_TTL = 48 * HOUR_IN_SECONDS;
    private const DIR_TRANSIENT = "optistate_trash_dir_checked";

    private const META_TRASH_MAX_ROWS = 100000;
    private const META_TRASH_MAX_FILE_SIZE = 50 * 1024 * 1024;
    private const META_EXPORT_CHUNK = 1000;
    private const META_RESTORE_BATCH = 100;

    private const DELETE_ALL_RATE_LIMIT = 2;
    private const META_CACHE_ID_LIMIT = 200000;

    private const PURGE_BATCH = 50;
    private const CLEANUP_BATCH = 100;
    private const CLEANUP_TIME_LIMIT = 20.0;

    private const VALID_TYPES = [
        "folder",
        "table",
        "option",
        "postmeta",
        "commentmeta",
        "usermeta",
        "termmeta",
    ];
    private const META_TABLE_MAP = [
        "postmeta" => [
            "property" => "postmeta",
            "id_col" => "meta_id",
            "object_col" => "post_id",
            "cache_group" => "post_meta",
        ],
        "commentmeta" => [
            "property" => "commentmeta",
            "id_col" => "meta_id",
            "object_col" => "comment_id",
            "cache_group" => "comment_meta",
        ],
        "usermeta" => [
            "property" => "usermeta",
            "id_col" => "umeta_id",
            "object_col" => "user_id",
            "cache_group" => "user_meta",
        ],
        "termmeta" => [
            "property" => "termmeta",
            "id_col" => "meta_id",
            "object_col" => "term_id",
            "cache_group" => "term_meta",
        ],
    ];
    private const META_COLUMN_MAP = [
        "postmeta" => ["post_id", "meta_key", "meta_value"],
        "commentmeta" => ["comment_id", "meta_key", "meta_value"],
        "usermeta" => ["user_id", "meta_key", "meta_value"],
        "termmeta" => ["term_id", "meta_key", "meta_value"],
    ];

    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;

        add_action("wp_ajax_optistate_list_trash_items", [
            $this,
            "ajax_list_trash_items",
        ]);
        add_action("wp_ajax_optistate_restore_trash_item", [
            $this,
            "ajax_restore_trash_item",
        ]);
        add_action("wp_ajax_optistate_permanently_delete_trash_item", [
            $this,
            "ajax_permanently_delete_trash_item",
        ]);
        add_action("wp_ajax_optistate_delete_all_trash", [
            $this,
            "ajax_delete_all_trash",
        ]);
    }
    private function trash_table(): string
    {
        if ($this->trash_table_cache === null) {
            global $wpdb;

            $this->trash_table_cache = $wpdb->prefix . "optistate_trash";
        }

        return $this->trash_table_cache;
    }
    private function trash_table_sql(): string
    {
        if ($this->trash_table_sql_cache === null) {
            $this->trash_table_sql_cache = OPTISTATE_Utils::escape_identifier(
                $this->trash_table()
            );
        }

        return $this->trash_table_sql_cache;
    }
    private function trash_dir(): string
    {
        if ($this->trash_dir_cache === null) {
            $upload_dir = wp_get_upload_dir();

            $base =
                isset($upload_dir["basedir"]) &&
                is_string($upload_dir["basedir"]) &&
                $upload_dir["basedir"] !== ""
                    ? $upload_dir["basedir"]
                    : WP_CONTENT_DIR;

            $this->trash_dir_cache =
                trailingslashit(wp_normalize_path($base)) . "optistate/trash/";
        }

        return $this->trash_dir_cache;
    }
    private function is_post_request(): bool
    {
        return isset($_SERVER["REQUEST_METHOD"]) &&
            $_SERVER["REQUEST_METHOD"] === "POST";
    }
    private function read_trash_key(): string
    {
        if (!isset($_POST["key"]) || !is_scalar($_POST["key"])) {
            return "";
        }

        $key = strtolower(trim((string) wp_unslash($_POST["key"])));

        return preg_match('/^[a-z]+_[a-f0-9]{32}$/', $key) === 1 ? $key : "";
    }
    private function fs(): ?object
    {
        if ($this->wp_filesystem !== null) {
            return $this->wp_filesystem;
        }

        if ($this->filesystem_failed) {
            return null;
        }

        try {
            $this->wp_filesystem = $this->main_plugin->get_filesystem();
        } catch (Throwable $e) {
            $this->filesystem_failed = true;

            OPTISTATE_Utils::log_critical_error(
                "Trash manager could not obtain a filesystem",
                ["error" => $e->getMessage()]
            );

            return null;
        }

        return $this->wp_filesystem;
    }
    public function ensure_table_exists(): void
    {
        if (self::$table_exists_cache === true) {
            return;
        }

        if (get_transient(self::TABLE_TRANSIENT) !== false) {
            self::$table_exists_cache = true;

            return;
        }

        $this->create_table();
    }
    private function table_ready(): bool
    {
        if (self::$table_verified) {
            return true;
        }

        if (!OPTISTATE_Utils::table_exists($this->trash_table())) {
            $this->create_table();

            if (!OPTISTATE_Utils::table_exists($this->trash_table())) {
                return false;
            }
        }

        self::$table_verified = true;
        self::$table_exists_cache = true;

        return true;
    }
    private function create_table(): void
    {
        global $wpdb;

        OPTISTATE_Utils::clear_table_existence_cache($this->trash_table());

        if (!function_exists("dbDelta")) {
            require_once ABSPATH . "wp-admin/includes/upgrade.php";
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->trash_table()} (
 id bigint(20) NOT NULL AUTO_INCREMENT,
 trash_key varchar(191) NOT NULL,
 type varchar(20) NOT NULL,
 original_name varchar(255) NOT NULL,
 trash_path_or_name varchar(500) NOT NULL,
 meta longtext,
 size bigint(20) DEFAULT 0,
 deleted_at bigint(20) NOT NULL,
 expires_at bigint(20) DEFAULT 0,
 PRIMARY KEY  (id),
 UNIQUE KEY trash_key (trash_key),
 KEY type (type),
 KEY expires_at (expires_at),
 KEY deleted_at (deleted_at)
) {$charset_collate};";

        try {
            dbDelta($sql);

            OPTISTATE_Utils::clear_table_existence_cache($this->trash_table());

            if (OPTISTATE_Utils::table_exists($this->trash_table())) {
                self::$table_exists_cache = true;

                set_transient(
                    self::TABLE_TRANSIENT,
                    true,
                    self::TABLE_TRANSIENT_TTL
                );
            } else {
                OPTISTATE_Utils::log_critical_error(
                    "Trash table could not be created",
                    ["table" => $this->trash_table(), "error" => $wpdb->last_error]
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ensure_table_exists failed for trash table",
                ["error" => $e->getMessage()]
            );
        }
    }

    private function ensure_trash_directory(bool $force = false): bool
    {
        if (!$force && get_transient(self::DIR_TRANSIENT)) {
            return true;
        }

        $created = $this->main_plugin->ensure_directory(
            $this->trash_dir(),
            0755,
            OPTISTATE::HTACCESS_RULES_TRASH
        );

        if ($created) {
            set_transient(
                self::DIR_TRANSIENT,
                true,
                OPTISTATE::DIR_CHECK_TIME
            );
        }

        return $created;
    }
    private function require_trash_directory(): bool
    {
        $fs = $this->fs();

        if ($fs === null) {
            return false;
        }

        if (!$fs->is_dir($this->trash_dir())) {
            $this->ensure_trash_directory(true);
        } else {
            $this->ensure_trash_directory();
        }

        return $fs->is_dir($this->trash_dir());
    }
    private function random_hex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Throwable $e) {
            return substr(
                md5(uniqid((string) wp_rand(), true)),
                0,
                max(2, $bytes * 2)
            );
        }
    }

    private function generate_trash_key(
        string $type,
        string $identifier
    ): string {
        return $type .
            "_" .
            md5($identifier . "_" . microtime(true) . "_" . $this->random_hex(8));
    }

    private function get_relative_path(string $path): string
    {
        $abspath = trailingslashit(wp_normalize_path(ABSPATH));
        $normalized = wp_normalize_path($path);

        if (strpos($normalized, $abspath) === 0) {
            return substr($normalized, strlen($abspath));
        }

        return $normalized;
    }
    private function path_has_traversal(string $path): bool
    {
        foreach (explode("/", wp_normalize_path($path)) as $segment) {
            if ($segment === "..") {
                return true;
            }
        }

        return false;
    }
    private function resolve_intended_path(string $path): string
    {
        $normalized = wp_normalize_path($path);
        $pending = [];
        $ancestor = $normalized;

        while (true) {
            $real = @realpath($ancestor);

            if ($real !== false) {
                $resolved = wp_normalize_path($real);

                if (!empty($pending)) {
                    $resolved =
                        trailingslashit($resolved) . implode("/", $pending);
                }

                return $resolved;
            }

            $parent = dirname($ancestor);

            if ($parent === $ancestor || $parent === "" || $parent === ".") {
                return $normalized;
            }

            array_unshift($pending, basename($ancestor));

            $ancestor = $parent;
        }
    }
    private function get_valid_base_paths(): array
    {
        $upload_dir = wp_get_upload_dir();

        $paths = [
            $upload_dir["basedir"] ?? "",
            defined("WP_CONTENT_DIR") ? WP_CONTENT_DIR : "",
            defined("WP_PLUGIN_DIR") ? WP_PLUGIN_DIR : "",
            get_theme_root(),
            defined("WPMU_PLUGIN_DIR") ? WPMU_PLUGIN_DIR : "",
        ];

        $resolved = [];

        foreach ($paths as $path) {
            if (!is_string($path) || $path === "") {
                continue;
            }

            $real = @realpath($path);

            if ($real !== false) {
                $resolved[] = wp_normalize_path($real);
            }
        }

        return array_values(array_unique($resolved));
    }

    private function mark_table_cache_dirty(): void
    {
        if (self::$table_cache_dirty) {
            return;
        }

        self::$table_cache_dirty = true;

        add_action("shutdown", [$this, "flush_table_cache"], 1);
    }
    public function flush_table_cache(): void
    {
        if (!self::$table_cache_dirty) {
            return;
        }

        self::$table_cache_dirty = false;

        OPTISTATE_Utils::invalidate_table_cache();
    }
    private function is_inside_trash_dir(string $path): bool
    {
        if ($path === "") {
            return false;
        }

        $base = trailingslashit(wp_normalize_path($this->trash_dir()));
        $target = trailingslashit(wp_normalize_path($path));

        return $target !== $base && strpos($target, $base) === 0;
    }
    private function log_foreign_artifact(string $path, string $context): void
    {
        OPTISTATE_Utils::log_critical_error(
            "Trash artefact path outside the trash directory was ignored",
            ["context" => $context, "path" => $path]
        );
    }
    private function purge_meta_object_cache(
        string $cache_group,
        array $object_ids
    ): void {
        if ($cache_group === "") {
            return;
        }

        if ($this->can_flush_cache_group()) {
            wp_cache_flush_group($cache_group);

            return;
        }

        foreach ($object_ids as $object_id => $ignored) {
            wp_cache_delete((int) $object_id, $cache_group);
        }
    }
    private function can_flush_cache_group(): bool
    {
        static $supported = null;

        if ($supported === null) {
            $supported =
                function_exists("wp_cache_flush_group") &&
                function_exists("wp_cache_supports") &&
                wp_cache_supports("flush_group");
        }

        return $supported;
    }
    private function is_inside_allowed_base(string $path): bool
    {
        $target = trailingslashit(wp_normalize_path($path));

        foreach ($this->get_valid_base_paths() as $base) {
            $base_slashed = trailingslashit($base);

            if (
                $target !== $base_slashed &&
                strpos($target, $base_slashed) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    private function decode_meta(?string $raw): array
    {
        if ($raw === null || $raw === "") {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
    public function move_to_trash(
        string $type,
        string $identifier,
        array $extra = []
    ) {
        if (!in_array($type, self::VALID_TYPES, true) || $identifier === "") {
            return false;
        }

        $this->ensure_table_exists();

        if (!$this->table_ready()) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Cannot move to trash: the trash table is unavailable.",
                        "optistate"
                    ),
                "error"
            );

            return false;
        }

        $trash_key = $this->generate_trash_key($type, $identifier);
        $deleted_at = time();
        $expires_at = $deleted_at + self::TRASH_MAX_AGE;

        switch ($type) {
            case "folder":
                return $this->trash_folder(
                    $identifier,
                    $trash_key,
                    $deleted_at,
                    $expires_at,
                    $extra
                );

            case "table":
                return $this->trash_table_item(
                    $identifier,
                    $trash_key,
                    $deleted_at,
                    $expires_at,
                    $extra
                );

            case "option":
                return $this->trash_option(
                    $identifier,
                    $trash_key,
                    $deleted_at,
                    $expires_at
                );

            default:
                return $this->trash_meta(
                    $type,
                    $identifier,
                    $trash_key,
                    $deleted_at,
                    $expires_at
                );
        }
    }
    private function insert_record(
        string $trash_key,
        string $type,
        string $original_name,
        string $target,
        array $meta,
        int $size,
        int $deleted_at,
        int $expires_at
    ): bool {
        global $wpdb;

        $encoded_meta = wp_json_encode($meta);

        if (!is_string($encoded_meta)) {
            OPTISTATE_Utils::log_critical_error(
                "Refusing to create a trash record with unencodable metadata",
                ["type" => $type, "name" => $original_name]
            );

            return false;
        }

        $inserted = $wpdb->insert(
            $this->trash_table(),
            [
                "trash_key" => $trash_key,
                "type" => $type,
                "original_name" => $original_name,
                "trash_path_or_name" => $target,
                "meta" => $encoded_meta,
                "size" => $size,
                "deleted_at" => $deleted_at,
                "expires_at" => $expires_at,
            ],
            ["%s", "%s", "%s", "%s", "%s", "%d", "%d", "%d"]
        );

        if ($inserted === false) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to insert trash record",
                [
                    "type" => $type,
                    "name" => $original_name,
                    "error" => $wpdb->last_error,
                ]
            );

            return false;
        }

        return true;
    }

    private function delete_record(string $trash_key): bool
    {
        global $wpdb;

        $result = $wpdb->delete(
            $this->trash_table(),
            ["trash_key" => $trash_key],
            ["%s"]
        );

        if ($result === false) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to remove trash record - manual cleanup may be required",
                ["trash_key" => $trash_key, "error" => $wpdb->last_error]
            );

            return false;
        }

        return true;
    }
    private function trash_folder(
        string $source_path,
        string $trash_key,
        int $deleted_at,
        int $expires_at,
        array $extra
    ) {
        $fs = $this->fs();

        if ($fs === null) {
            return false;
        }

        $source_path = wp_normalize_path($source_path);

        if (!@is_dir($source_path)) {
            return false;
        }

        if (!$this->require_trash_directory()) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Cannot move folder to trash: the trash directory is unavailable.",
                        "optistate"
                    ),
                "error"
            );

            return false;
        }

        $stats = OPTISTATE_Utils::get_folder_size($source_path, 50000, 5, false);
        $size = (int) ($stats["size"] ?? 0);
        $file_count = (int) ($stats["file_count"] ?? 0);

        $basename = basename($source_path);
        $trash_name =
            $basename . "-" . time() . "-" . $this->random_hex(6);
        $trash_path = $this->trash_dir() . $trash_name;

        $extra["original_path"] = $source_path;
        $extra["relative_path"] = $this->get_relative_path($source_path);
        $extra["basename"] = $basename;
        if ($file_count > 0) {
            $extra["file_count"] = $file_count;
        }

        if (
            !$this->insert_record(
                $trash_key,
                "folder",
                $basename,
                $trash_path,
                $extra,
                $size,
                $deleted_at,
                $expires_at
            )
        ) {
            return false;
        }

        if (!$this->relocate_directory($source_path, $trash_path)) {
            $this->delete_record($trash_key);

            $this->main_plugin->log_entry(
                sprintf(
                    __("❌ Failed to move folder to trash: %s", "optistate"),
                    $extra["relative_path"]
                ),
                "error"
            );

            return false;
        }

        $this->main_plugin->log_entry(
            sprintf(
                __("🗑 Moved legacy %1\$s to trash: %2\$s by {username}", "optistate"),
                "folder",
                $extra["relative_path"]
            )
        );

        $this->main_plugin->clear_stats_cache();

        return $trash_key;
    }
    private function relocate_directory(string $from, string $to): bool
    {
        $fs = $this->fs();

        if ($fs === null) {
            return false;
        }

        if ($fs->move($from, $to, true)) {
            return true;
        }

        if (!$this->copy_directory($from, $to)) {
            if ($fs->exists($to)) {
                $fs->delete($to, true);
            }

            return false;
        }

        if (!$fs->delete($from, true)) {
            $fs->delete($to, true);

            OPTISTATE_Utils::log_critical_error(
                "Trash move fallback could not remove the source directory",
                ["source" => $from]
            );

            return false;
        }

        return true;
    }
    private function copy_directory(string $from, string $to, int $depth = 0): bool
    {
        $fs = $this->fs();

        if ($fs === null || $depth > 32) {
            return false;
        }

        if (!$fs->is_dir($to) && !wp_mkdir_p($to)) {
            return false;
        }

        $entries = $fs->dirlist($from, true, false);

        if (!is_array($entries)) {
            return false;
        }

        foreach ($entries as $name => $entry) {
            $source = trailingslashit($from) . $name;
            $target = trailingslashit($to) . $name;

            if (($entry["type"] ?? "") === "d") {
                if (!$this->copy_directory($source, $target, $depth + 1)) {
                    return false;
                }

                continue;
            }

            if (!$fs->copy($source, $target, true, FS_CHMOD_FILE)) {
                return false;
            }
        }

        return true;
    }
    private function trash_table_item(
        string $table_name,
        string $trash_key,
        int $deleted_at,
        int $expires_at,
        array $extra
    ) {
        global $wpdb;

        $safe_table = OPTISTATE_Utils::validate_table_name($table_name);

        if (!$safe_table) {
            return false;
        }

        $info = OPTISTATE_Utils::with_stats_expiry_disabled(static function () use (
            $wpdb,
            $table_name
        ) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT data_length + index_length AS size, table_rows
                     FROM information_schema.tables
                     WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $table_name
                )
            );
        });

        $size = (int) ($info->size ?? 0);
        $row_count = (int) ($info->table_rows ?? 0);

        if ($size === 0) {
            $status = $wpdb->get_row(
                $wpdb->prepare(
                    "SHOW TABLE STATUS LIKE %s",
                    $wpdb->esc_like($table_name)
                ),
                ARRAY_A
            );

            if (is_array($status)) {
                $size =
                    (int) ($status["Data_length"] ?? 0) +
                    (int) ($status["Index_length"] ?? 0);
                $row_count = (int) ($status["Rows"] ?? 0);
            }
        }

        $prefix = $wpdb->prefix . "trash_";
        $trash_table_name = OPTISTATE_Utils::generate_safe_table_name(
            $table_name . "_" . time(),
            $prefix
        );

        $attempt = 0;

        while (OPTISTATE_Utils::table_exists($trash_table_name)) {
            if (++$attempt >= 10) {
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "❌ Could not generate a unique trash table name for: %s",
                            "optistate"
                        ),
                        $table_name
                    ),
                    "error"
                );

                return false;
            }

            $trash_table_name = OPTISTATE_Utils::generate_safe_table_name(
                $table_name . "_" . time() . "_" . $this->random_hex(2),
                $prefix
            );
        }

        $extra["row_count"] = $row_count;
        $extra["original_table"] = $table_name;

        if (
            !$this->insert_record(
                $trash_key,
                "table",
                $table_name,
                $trash_table_name,
                $extra,
                $size,
                $deleted_at,
                $expires_at
            )
        ) {
            return false;
        }

        $safe_trash = OPTISTATE_Utils::escape_identifier(
            trim($trash_table_name, "`")
        );

        $renamed = OPTISTATE_Utils::without_foreign_key_checks(static function () use (
            $safe_table,
            $safe_trash,
            $wpdb
        ) {
            return $wpdb->query("RENAME TABLE {$safe_table} TO {$safe_trash}");
        });

        if ($renamed === false) {
            $this->delete_record($trash_key);

            $this->main_plugin->log_entry(
                sprintf(
                    __("❌ Failed to move table to trash: %s", "optistate"),
                    $table_name
                ),
                "error"
            );

            return false;
        }

        OPTISTATE_Utils::clear_table_existence_cache($table_name);
        OPTISTATE_Utils::clear_table_existence_cache($trash_table_name);

        $this->mark_table_cache_dirty();

        $this->main_plugin->log_entry(
            sprintf(
                __("🗑 Moved legacy %1\$s to trash: %2\$s by {username}", "optistate"),
                "table",
                $table_name
            )
        );

        $this->main_plugin->clear_stats_cache();

        return $trash_key;
    }
    private function trash_option(
        string $option_name,
        string $trash_key,
        int $deleted_at,
        int $expires_at
    ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
                $option_name
            )
        );

        if (!$row) {
            return false;
        }

        $option_value = (string) $row->option_value;
        $autoload = $row->autoload !== "" ? (string) $row->autoload : "no";
        $trash_option_name =
            "_optistate_trash_" . md5($option_name . "_" . microtime(true));

        if ($wpdb->query("START TRANSACTION") === false) {
            OPTISTATE_Utils::log_critical_error(
                "move_to_trash: could not start transaction for option",
                ["option" => $option_name, "error" => $wpdb->last_error]
            );

            return false;
        }

        $inserted = $wpdb->insert(
            $wpdb->options,
            [
                "option_name" => $trash_option_name,
                "option_value" => $option_value,
                "autoload" => "no",
            ],
            ["%s", "%s", "%s"]
        );

        if ($inserted === false) {
            $wpdb->query("ROLLBACK");

            return false;
        }

        $deleted = $wpdb->delete(
            $wpdb->options,
            ["option_name" => $option_name],
            ["%s"]
        );

        if ($deleted === false) {
            $wpdb->query("ROLLBACK");

            return false;
        }

        $record = $this->insert_record(
            $trash_key,
            "option",
            $option_name,
            $trash_option_name,
            ["original_option" => $option_name, "autoload" => $autoload],
            strlen($option_value),
            $deleted_at,
            $expires_at
        );

        if (!$record) {
            $wpdb->query("ROLLBACK");

            return false;
        }

        if ($wpdb->query("COMMIT") === false) {
            $wpdb->query("ROLLBACK");

            OPTISTATE_Utils::log_critical_error(
                "move_to_trash: COMMIT failed for option",
                ["option" => $option_name, "error" => $wpdb->last_error]
            );

            return false;
        }

        $this->flush_option_caches($option_name);

        $this->main_plugin->log_entry(
            sprintf(
                __("🗑 Moved legacy %1\$s to trash: %2\$s by {username}", "optistate"),
                "option",
                $option_name
            )
        );

        $this->main_plugin->clear_stats_cache();

        return $trash_key;
    }

    private function flush_option_caches(string $option_name): void
    {
        wp_cache_delete($option_name, "options");
        wp_cache_delete("alloptions", "options");
        wp_cache_delete("notoptions", "options");
    }
    private function trash_meta(
        string $type,
        string $meta_key,
        string $trash_key,
        int $deleted_at,
        int $expires_at
    ) {
        global $wpdb;

        $info = self::META_TABLE_MAP[$type];
        $table = $wpdb->{$info["property"]};

        if (empty($table)) {
            return false;
        }

        $id_column = $info["id_col"];
        $object_column = $info["object_col"];
        $cache_group = $info["cache_group"];
        $safe_table = OPTISTATE_Utils::escape_identifier($table);
        $safe_id_column = OPTISTATE_Utils::escape_identifier($id_column);

        wp_raise_memory_limit("admin");
        OPTISTATE_Utils::safe_set_time_limit(180);

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$safe_table} WHERE meta_key = %s",
                $meta_key
            )
        );

        if ($count === 0) {
            return false;
        }

        if ($count > self::META_TRASH_MAX_ROWS) {
            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        "⚠️ Trash move aborted: meta key %1\$s has %2\$s rows, which exceeds the %3\$s row limit.",
                        "optistate"
                    ),
                    $meta_key,
                    number_format_i18n($count),
                    number_format_i18n(self::META_TRASH_MAX_ROWS)
                ),
                "error"
            );

            return false;
        }

        if (!$this->require_trash_directory()) {
            return false;
        }
        $file_path = $this->trash_dir() . $trash_key . ".ndjson";
        $handle = @fopen($file_path, "wb");

        if (!$handle) {
            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        "❌ Failed to open the trash file for writing: %s",
                        "optistate"
                    ),
                    $file_path
                ),
                "error"
            );

            return false;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            @unlink($file_path);

            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        "❌ Failed to acquire an exclusive lock on the trash file: %s",
                        "optistate"
                    ),
                    $file_path
                ),
                "error"
            );

            return false;
        }

        $last_id = 0;
        $written = 0;
        $write_failed = false;
        $collect_ids = !$this->can_flush_cache_group();
        $object_ids = [];

        while (true) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$safe_table}
                     WHERE meta_key = %s AND {$safe_id_column} > %d
                     ORDER BY {$safe_id_column} ASC
                     LIMIT %d",
                    $meta_key,
                    $last_id,
                    self::META_EXPORT_CHUNK
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            $buffer = "";

            foreach ($rows as $row) {
                $last_id = (int) $row[$id_column];

                if (
                    $collect_ids &&
                    isset($row[$object_column]) &&
                    count($object_ids) < self::META_CACHE_ID_LIMIT
                ) {
                    $object_ids[(int) $row[$object_column]] = true;
                }

                unset($row[$id_column]);
                $encoded = json_encode($row);

                if ($encoded === false || json_last_error() !== JSON_ERROR_NONE) {
                    $encoded = json_encode([
                        "_optistate_encoded" => true,
                        "data" => base64_encode(serialize($row)),
                    ]);
                }

                if ($encoded === false) {
                    $write_failed = true;
                    break;
                }

                $buffer .= $encoded . "\n";
                $written++;
            }

            if ($write_failed || fwrite($handle, $buffer) === false) {
                $write_failed = true;
                break;
            }

            unset($rows, $buffer);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        if ($write_failed || $written === 0) {
            @unlink($file_path);

            if ($write_failed) {
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "❌ Failed to write the trash file: %s",
                            "optistate"
                        ),
                        $file_path
                    ),
                    "error"
                );
            }

            return false;
        }

        clearstatcache(true, $file_path);
        $file_size = filesize($file_path);

        if ($file_size === false || $file_size === 0) {
            @unlink($file_path);

            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        "❌ The trash file is empty or unreadable after writing: %s",
                        "optistate"
                    ),
                    $file_path
                ),
                "error"
            );

            return false;
        }

        if (
            !$this->insert_record(
                $trash_key,
                $type,
                $meta_key,
                $file_path,
                [
                    "original_key" => $meta_key,
                    "row_count" => $written,
                    "meta_id_col" => $id_column,
                    "max_meta_id" => $last_id,
                    "format" => "ndjson",
                ],
                (int) $file_size,
                $deleted_at,
                $expires_at
            )
        ) {
            @unlink($file_path);

            return false;
        }
        $removed = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$safe_table}
                 WHERE meta_key = %s AND {$safe_id_column} <= %d",
                $meta_key,
                $last_id
            )
        );

        if ($removed === false) {
            $this->delete_record($trash_key);
            @unlink($file_path);

            OPTISTATE_Utils::log_critical_error(
                "move_to_trash: failed to remove exported meta rows",
                ["meta_key" => $meta_key, "error" => $wpdb->last_error]
            );

            return false;
        }

        $this->purge_meta_object_cache($cache_group, $object_ids);

        unset($object_ids);

        $this->main_plugin->log_entry(
            sprintf(
                __("🗑 Moved legacy %1\$s to trash: %2\$s by {username}", "optistate"),
                $type,
                $meta_key
            )
        );

        $this->main_plugin->clear_stats_cache();

        return $trash_key;
    }
    public function restore_from_trash(string $trash_key): bool
    {
        global $wpdb;

        if ($trash_key === "" || !$this->table_ready()) {
            return false;
        }

        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->trash_table_sql()} WHERE trash_key = %s",
                $trash_key
            )
        );

        if (!$item) {
            return false;
        }

        $type = (string) $item->type;
        $meta = $this->decode_meta($item->meta);
        $record_already_removed = false;

        switch ($type) {
            case "folder":
                if (!$this->restore_folder($item, $meta)) {
                    return false;
                }
                break;

            case "table":
                if (!$this->restore_table($item, $meta)) {
                    return false;
                }
                break;

            case "option":
                if (!$this->restore_option($item, $meta, $trash_key)) {
                    return false;
                }

                $record_already_removed = true;
                break;

            case "postmeta":
            case "commentmeta":
            case "usermeta":
            case "termmeta":
                if (!$this->restore_meta($item, $meta, $type)) {
                    return false;
                }
                break;

            default:
                return false;
        }

        if (!$record_already_removed) {
            $this->delete_record($trash_key);
        }

        $this->main_plugin->log_entry(
            sprintf(
                __("↩ Restored from trash: %1\$s (%2\$s) by {username}", "optistate"),
                $meta["relative_path"] ?? $item->original_name,
                $type
            )
        );

        $this->main_plugin->clear_stats_cache();

        return true;
    }

    private function restore_folder(object $item, array $meta): bool
    {
        $fs = $this->fs();

        if ($fs === null) {
            return false;
        }

        $trash_path = (string) $item->trash_path_or_name;
        $original_path = (string) ($meta["original_path"] ?? "");

        if (!$this->is_inside_trash_dir($trash_path)) {
            $this->log_foreign_artifact($trash_path, "restore_folder");

            return false;
        }

        if (!$fs->exists($trash_path) || !$fs->is_dir($trash_path)) {
            return false;
        }

        if ($original_path === "" || $this->path_has_traversal($original_path)) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Folder restore blocked: the stored path is invalid.",
                        "optistate"
                    ),
                "error"
            );

            return false;
        }

        $normalized_target = wp_normalize_path($original_path);
        $resolved_target = $this->resolve_intended_path($normalized_target);
        if (
            untrailingslashit($resolved_target) !==
            untrailingslashit($normalized_target)
        ) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Folder restore blocked: the destination does not resolve to its recorded location.",
                        "optistate"
                    ),
                "error"
            );

            return false;
        }

        if (!$this->is_inside_allowed_base($resolved_target)) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Folder restore blocked: the destination is outside the allowed directories.",
                        "optistate"
                    ),
                "error"
            );

            return false;
        }

        if ($fs->exists($original_path)) {
            return false;
        }

        $parent = dirname($original_path);

        if (!$fs->is_dir($parent) && !wp_mkdir_p($parent)) {
            return false;
        }

        return $this->relocate_directory($trash_path, $original_path);
    }

    private function restore_table(object $item, array $meta): bool
    {
        global $wpdb;

        $trash_table = (string) $item->trash_path_or_name;
        $original_table = (string) ($meta["original_table"] ?? "");

        if (
            $original_table === "" ||
            !preg_match('/^[A-Za-z0-9_]+$/', $original_table) ||
            !preg_match('/^[A-Za-z0-9_]+$/', $trash_table) ||
            !OPTISTATE_Utils::table_exists($trash_table) ||
            OPTISTATE_Utils::table_exists($original_table)
        ) {
            return false;
        }

        $safe_trash = OPTISTATE_Utils::escape_identifier($trash_table);
        $safe_original = OPTISTATE_Utils::escape_identifier($original_table);

        $renamed = OPTISTATE_Utils::without_foreign_key_checks(static function () use (
            $safe_trash,
            $safe_original,
            $wpdb
        ) {
            return $wpdb->query("RENAME TABLE {$safe_trash} TO {$safe_original}");
        });

        if ($renamed === false) {
            OPTISTATE_Utils::log_critical_error(
                "restore_from_trash: RENAME failed",
                ["table" => $original_table, "error" => $wpdb->last_error]
            );

            return false;
        }

        OPTISTATE_Utils::clear_table_existence_cache($trash_table);
        OPTISTATE_Utils::clear_table_existence_cache($original_table);

        $this->mark_table_cache_dirty();

        return true;
    }

    private function restore_option(
        object $item,
        array $meta,
        string $trash_key
    ): bool {
        global $wpdb;

        $trash_option = (string) $item->trash_path_or_name;
        $original_option = (string) ($meta["original_option"] ?? "");

        if ($trash_option === "" || $original_option === "") {
            return false;
        }

        $trash_value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $trash_option
            )
        );

        if ($trash_value === null) {
            return false;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
                $original_option
            )
        );

        if ($exists !== null) {
            return false;
        }

        $autoload = (string) ($meta["autoload"] ?? "no");

        if ($wpdb->query("START TRANSACTION") === false) {
            return false;
        }

        $restored = $wpdb->insert(
            $wpdb->options,
            [
                "option_name" => $original_option,
                "option_value" => $trash_value,
                "autoload" => $autoload,
            ],
            ["%s", "%s", "%s"]
        );

        if ($restored === false) {
            $wpdb->query("ROLLBACK");

            return false;
        }

        $deleted = $wpdb->delete(
            $wpdb->options,
            ["option_name" => $trash_option],
            ["%s"]
        );

        if ($deleted === false) {
            $wpdb->query("ROLLBACK");

            return false;
        }

        $removed = $wpdb->delete(
            $this->trash_table(),
            ["trash_key" => $trash_key],
            ["%s"]
        );

        if ($removed === false) {
            $wpdb->query("ROLLBACK");

            return false;
        }

        if ($wpdb->query("COMMIT") === false) {
            $wpdb->query("ROLLBACK");

            OPTISTATE_Utils::log_critical_error(
                "restore_from_trash: COMMIT failed for option restore",
                ["option" => $original_option, "error" => $wpdb->last_error]
            );

            return false;
        }

        $this->flush_option_caches($original_option);

        return true;
    }

    private function restore_meta(object $item, array $meta, string $type): bool
    {
        global $wpdb;

        $file_path = (string) $item->trash_path_or_name;

        if (!$this->is_inside_trash_dir($file_path)) {
            $this->log_foreign_artifact($file_path, "restore_meta");

            return false;
        }

        if (!@is_file($file_path)) {
            return false;
        }

        $info = self::META_TABLE_MAP[$type];
        $table = $wpdb->{$info["property"]};

        if (empty($table)) {
            return false;
        }

        $object_column = $info["object_col"];
        $cache_group = $info["cache_group"];

        wp_raise_memory_limit("admin");
        OPTISTATE_Utils::safe_set_time_limit(300);

        $expected_rows = (int) ($meta["row_count"] ?? 0);
        if ($expected_rows > 0) {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM " .
                        OPTISTATE_Utils::escape_identifier($table) .
                        " WHERE meta_key = %s",
                    (string) $item->original_name
                )
            );

            if ($existing >= $expected_rows) {
                @unlink($file_path);

                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "ℹ️ Metadata for %s is already present; the trash entry was discarded without re-inserting rows.",
                            "optistate"
                        ),
                        (string) $item->original_name
                    ),
                    "manual"
                );

                return true;
            }
        }

        $entries = $this->open_meta_entries($file_path, $meta);

        if ($entries === null) {
            return false;
        }

        $columns = self::META_COLUMN_MAP[$type];

        if ($wpdb->query("START TRANSACTION") === false) {
            return false;
        }

        $batch = [];
        $failed = false;
        $collect_ids = !$this->can_flush_cache_group();
        $object_ids = [];

        foreach ($entries as $entry) {
            if ($entry === false) {
                $failed = true;
                break;
            }

            if (
                $collect_ids &&
                isset($entry[$object_column]) &&
                count($object_ids) < self::META_CACHE_ID_LIMIT
            ) {
                $object_ids[(int) $entry[$object_column]] = true;
            }

            $batch[] = $entry;

            if (count($batch) >= self::META_RESTORE_BATCH) {
                if (!$this->bulk_insert_batch($table, $batch, $columns)) {
                    $failed = true;
                    break;
                }

                $batch = [];
            }
        }

        if (!$failed && !empty($batch)) {
            $failed = !$this->bulk_insert_batch($table, $batch, $columns);
        }

        if ($failed) {
            $wpdb->query("ROLLBACK");

            OPTISTATE_Utils::log_critical_error(
                "restore_from_trash: metadata restore failed",
                ["type" => $type, "file" => basename($file_path)]
            );

            return false;
        }

        if ($wpdb->query("COMMIT") === false) {
            $wpdb->query("ROLLBACK");

            return false;
        }

        $this->purge_meta_object_cache($cache_group, $object_ids);

        unset($object_ids);

        @unlink($file_path);

        return true;
    }
    private function open_meta_entries(string $file_path, array $meta): ?iterable
    {
        $format = (string) ($meta["format"] ?? "");

        if ($format !== "ndjson") {
            $probe = @file_get_contents($file_path, false, null, 0, 8);
            $format = is_string($probe) && strpos(ltrim($probe), "[") === 0
                ? "legacy"
                : "ndjson";
        }

        if ($format === "ndjson") {
            return $this->stream_ndjson_entries($file_path);
        }

        return $this->load_legacy_entries($file_path);
    }

    private function stream_ndjson_entries(string $file_path): ?iterable
    {
        $handle = @fopen($file_path, "rb");

        if (!$handle) {
            return null;
        }

        return (function () use ($handle, $file_path) {
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);

                    if ($line === "") {
                        continue;
                    }

                    $decoded = json_decode($line, true);

                    if (!is_array($decoded)) {
                        OPTISTATE_Utils::log_critical_error(
                            "restore_from_trash: malformed row in trash file",
                            ["file" => basename($file_path)]
                        );

                        yield false;

                        return;
                    }

                    yield $this->decode_entry($decoded);
                }
            } finally {
                fclose($handle);
            }
        })();
    }

    private function load_legacy_entries(string $file_path): ?iterable
    {
        clearstatcache(true, $file_path);
        $file_size = filesize($file_path);

        if ($file_size === false || $file_size > self::META_TRASH_MAX_FILE_SIZE) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Metadata restore aborted: the trash file is too large or unreadable.",
                        "optistate"
                    ),
                "error"
            );

            return null;
        }
        $memory_limit = wp_convert_hr_to_bytes((string) ini_get("memory_limit"));

        if (
            $memory_limit > 0 &&
            memory_get_usage(true) + $file_size * 8 > $memory_limit
        ) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Metadata restore aborted: not enough memory is available to decode the trash file. Increase the PHP memory limit and try again.",
                        "optistate"
                    ),
                "error"
            );

            return null;
        }

        $contents = @file_get_contents($file_path);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        unset($contents);

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($decoded) ||
            empty($decoded)
        ) {
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Metadata restore failed: the trash file contains invalid JSON.",
                        "optistate"
                    ),
                "error"
            );

            return null;
        }

        $entries = [];

        foreach ($decoded as $row) {
            $entries[] = is_array($row) ? $this->decode_entry($row) : false;
        }

        return $entries;
    }
    private function decode_entry(array $entry)
    {
        if (empty($entry["_optistate_encoded"])) {
            return $entry;
        }

        $decoded = base64_decode((string) ($entry["data"] ?? ""), true);

        if ($decoded === false) {
            return false;
        }

        $unserialized = @unserialize($decoded, ["allowed_classes" => false]);

        return is_array($unserialized) ? $unserialized : false;
    }
    private function bulk_insert_batch(
        string $table,
        array $batch,
        array $allowed_columns
    ): bool {
        global $wpdb;

        if (empty($batch)) {
            return true;
        }

        $columns = array_keys($batch[0]);
        $unknown = array_diff($columns, $allowed_columns);

        if (!empty($unknown) || empty($columns)) {
            OPTISTATE_Utils::log_critical_error(
                "bulk_insert_batch: unexpected columns in restore data - aborting restore",
                [
                    "table" => $table,
                    "unknown_columns" => array_values($unknown),
                    "allowed_columns" => $allowed_columns,
                ]
            );

            return false;
        }

        $quoted_columns = [];

        foreach ($columns as $column) {
            $quoted_columns[] = OPTISTATE_Utils::escape_identifier($column);
        }

        $rows_sql = [];
        $values = [];

        foreach ($batch as $row) {
            $placeholders = [];

            foreach ($columns as $column) {
                $value = array_key_exists($column, $row) ? $row[$column] : null;

                if ($value === null) {
                    $placeholders[] = "NULL";

                    continue;
                }

                $placeholders[] = "%s";
                $values[] = $value;
            }

            $rows_sql[] = "(" . implode(",", $placeholders) . ")";
        }

        $sql =
            "INSERT INTO " .
            OPTISTATE_Utils::escape_identifier($table) .
            " (" .
            implode(",", $quoted_columns) .
            ") VALUES " .
            implode(",", $rows_sql);
        $prepared = empty($values) ? $sql : $wpdb->prepare($sql, $values);

        if ($wpdb->query($prepared) !== false) {
            return true;
        }
        foreach ($batch as $row) {
            if ($wpdb->insert($table, $row) === false) {
                OPTISTATE_Utils::log_critical_error(
                    "bulk_insert_batch: row insert failed",
                    ["table" => $table, "error" => $wpdb->last_error]
                );

                return false;
            }
        }

        return true;
    }
    public function permanently_delete(
        string $trash_key,
        string $log_type = "manual",
        bool $suppress_log = false
    ): bool {
        global $wpdb;

        if ($trash_key === "" || !$this->table_ready()) {
            return false;
        }

        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->trash_table_sql()} WHERE trash_key = %s",
                $trash_key
            )
        );

        if (!$item) {
            return false;
        }

        $type = (string) $item->type;
        $meta = $this->decode_meta($item->meta);

        if (!in_array($type, self::VALID_TYPES, true)) {
            $this->delete_record($trash_key);

            return false;
        }

        if (!$this->remove_artifact($item, $type)) {
            OPTISTATE_Utils::log_critical_error(
                "permanently_delete: the stored item could not be removed",
                ["trash_key" => $trash_key, "type" => $type]
            );

            return false;
        }

        if (!$this->delete_record($trash_key)) {
            return false;
        }

        if (!$suppress_log) {
            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        "🗑 Permanently deleted from trash: %1\$s (%2\$s) by {username}",
                        "optistate"
                    ),
                    $meta["relative_path"] ?? $item->original_name,
                    $type
                ),
                $log_type
            );
        }

        $this->main_plugin->clear_stats_cache();

        return true;
    }
    private function remove_artifact(object $item, string $type): bool
    {
        global $wpdb;

        $target = (string) $item->trash_path_or_name;

        if ($target === "") {
            return true;
        }

        switch ($type) {
            case "folder":
                if (!$this->is_inside_trash_dir($target)) {
                    $this->log_foreign_artifact($target, "permanently_delete");

                    return true;
                }

                $fs = $this->fs();

                if ($fs === null) {
                    return !@file_exists($target);
                }

                if (!$fs->exists($target)) {
                    return true;
                }

                $fs->delete($target, true);
                clearstatcache();

                return !$fs->exists($target);

            case "table":
                if (!preg_match('/^[A-Za-z0-9_]+$/', $target)) {
                    return true;
                }

                if (!OPTISTATE_Utils::table_exists($target)) {
                    return true;
                }

                $safe_table = OPTISTATE_Utils::escape_identifier($target);

                OPTISTATE_Utils::without_foreign_key_checks(static function () use (
                    $safe_table,
                    $wpdb
                ) {
                    return $wpdb->query("DROP TABLE IF EXISTS {$safe_table}");
                });

                OPTISTATE_Utils::clear_table_existence_cache($target);

                $this->mark_table_cache_dirty();

                return !OPTISTATE_Utils::table_exists($target);

            case "option":
                $deleted = $wpdb->delete(
                    $wpdb->options,
                    ["option_name" => $target],
                    ["%s"]
                );

                if ($deleted === false) {
                    return false;
                }

                $this->flush_option_caches($target);

                return true;

            default:
                if (!$this->is_inside_trash_dir($target)) {
                    $this->log_foreign_artifact($target, "permanently_delete");

                    return true;
                }

                if (!@file_exists($target)) {
                    return true;
                }

                @unlink($target);
                clearstatcache(true, $target);

                return !@file_exists($target);
        }
    }
    private function build_list_conditions(array $filters): array
    {
        global $wpdb;

        $where = ["1=1"];
        $params = [];

        if (
            !empty($filters["type"]) &&
            in_array($filters["type"], self::VALID_TYPES, true)
        ) {
            $where[] = "type = %s";
            $params[] = $filters["type"];
        }

        if (!empty($filters["search"]) && is_string($filters["search"])) {
            $where[] = "original_name LIKE %s";
            $params[] = "%" . $wpdb->esc_like($filters["search"]) . "%";
        }

        return ["sql" => implode(" AND ", $where), "params" => $params];
    }
    public function count_trash_items(array $filters = []): int
    {
        global $wpdb;

        if (!$this->table_ready()) {
            return 0;
        }

        $conditions = $this->build_list_conditions($filters);

        $sql =
            "SELECT COUNT(*) FROM {$this->trash_table_sql()} WHERE " .
            $conditions["sql"];

        if (!empty($conditions["params"])) {
            $sql = $wpdb->prepare($sql, $conditions["params"]);
        }

        return (int) $wpdb->get_var($sql);
    }
    public function list_trash_items(array $filters = []): array
    {
        global $wpdb;

        if (!$this->table_ready()) {
            return [];
        }

        $conditions = $this->build_list_conditions($filters);
        $params = $conditions["params"];

        $limit = absint($filters["limit"] ?? 200);
        $limit = $limit > 0 ? min($limit, 500) : 200;
        $offset = absint($filters["offset"] ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT trash_key, type, original_name, meta, size,
                        deleted_at, expires_at
                 FROM {$this->trash_table_sql()}
                 WHERE " .
                    $conditions["sql"] .
                    "
                 ORDER BY deleted_at DESC, id DESC
                 LIMIT %d OFFSET %d",
                $params
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $now = time();
        $items = [];

        foreach ($rows as $row) {
            $meta = $this->decode_meta($row["meta"] ?? null);
            $type = (string) $row["type"];
            $original_name = (string) $row["original_name"];
            $size = (int) $row["size"];
            $deleted_at = (int) $row["deleted_at"];

            if ($type === "folder") {
                $display_path = (string) ($meta["relative_path"] ??
                    $original_name);
            } elseif ($type === "table") {
                $display_path = (string) ($meta["original_table"] ??
                    $original_name);
            } elseif ($type === "option") {
                $display_path = (string) ($meta["original_option"] ??
                    $original_name);
            } else {
                $display_path = $original_name;
            }

            $items[] = [
                "trash_key" => (string) $row["trash_key"],
                "type" => $type,
                "original_name" => $original_name,
                "display_name" => $original_name,
                "display_path" => $display_path,
                "size" => $size,
                "size_human" => $size > 0 ? size_format($size, 2) : "0 B",
                "deleted_at" => $deleted_at,
                "expires_at" => (int) $row["expires_at"],
                "human_time" => sprintf(
                    __("%s ago", "optistate"),
                    human_time_diff($deleted_at, $now)
                ),
            ];
        }

        return $items;
    }
    public function cleanup_trash(): int
    {
        global $wpdb;

        if (!OPTISTATE_Utils::table_exists($this->trash_table())) {
            return 0;
        }

        $now = time();
        $last_id = 0;
        $count = 0;
        $start_time = microtime(true);
        $processed = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, trash_key FROM {$this->trash_table_sql()}
                     WHERE id > %d AND expires_at > 0 AND expires_at < %d
                     ORDER BY id ASC
                     LIMIT %d",
                    $last_id,
                    $now,
                    self::PURGE_BATCH
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $last_id = (int) $row["id"];
                $processed++;

                if (
                    $this->permanently_delete(
                        (string) $row["trash_key"],
                        "scheduled",
                        true
                    )
                ) {
                    $count++;
                }

                if (
                    microtime(true) - $start_time > self::CLEANUP_TIME_LIMIT ||
                    $processed >= self::CLEANUP_BATCH
                ) {
                    break 2;
                }
            }

            $batch_size = count($rows);
        } while ($batch_size === self::PURGE_BATCH);

        if ($count > 0) {
            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        "🗑 Automatically deleted %s expired trash items",
                        "optistate"
                    ),
                    number_format_i18n($count)
                ),
                "scheduled"
            );
        }

        $this->flush_table_cache();

        return $count;
    }
    public function delete_all_trash(): array
    {
        global $wpdb;

        $empty = [
            "deleted" => 0,
            "failed" => 0,
            "remaining" => 0,
            "completed" => true,
        ];

        if (!$this->table_ready()) {
            return $empty;
        }

        wp_raise_memory_limit("admin");
        OPTISTATE_Utils::safe_set_time_limit(180);

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->trash_table_sql()}"
        );

        if ($total === 0) {
            return $empty;
        }

        $max_execution = (int) ini_get("max_execution_time");

        if ($max_execution <= 0) {
            $max_execution = 180;
        }

        $time_limit = max(10.0, $max_execution * 0.8);
        $start_time = microtime(true);

        $last_id = 0;
        $deleted = 0;
        $failed = 0;
        $stopped_early = false;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, trash_key FROM {$this->trash_table_sql()}
                     WHERE id > %d
                     ORDER BY id ASC
                     LIMIT %d",
                    $last_id,
                    self::PURGE_BATCH
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $last_id = (int) $row["id"];

                if (
                    $this->permanently_delete(
                        (string) $row["trash_key"],
                        "manual",
                        true
                    )
                ) {
                    $deleted++;
                } else {
                    $failed++;
                }

                if (microtime(true) - $start_time >= $time_limit) {
                    $stopped_early = true;

                    break 2;
                }
            }

            $batch_size = count($rows);
        } while ($batch_size === self::PURGE_BATCH);
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->trash_table_sql()}"
        );

        $log_message = sprintf(
            __("🗑 Permanently deleted %s items from trash", "optistate"),
            number_format_i18n($deleted)
        );

        if ($stopped_early && $remaining > 0) {
            $log_message .= sprintf(
                __(" (partial - %s items remain)", "optistate"),
                number_format_i18n($remaining)
            );
        }

        if ($failed > 0) {
            $log_message .= sprintf(
                __(" (%s items could not be removed)", "optistate"),
                number_format_i18n($failed)
            );
        }

        $this->flush_table_cache();

        $this->main_plugin->log_entry($log_message . " by {username}");

        return [
            "deleted" => $deleted,
            "failed" => $failed,
            "remaining" => $remaining,
            "completed" => !$stopped_early,
        ];
    }

    public function ajax_list_trash_items(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        $items = $this->list_trash_items();

        OPTISTATE_Utils::send_json_success([
            "items" => $items,
            "shown" => count($items),
            "total" => $this->count_trash_items(),
        ]);
    }
    public function ajax_restore_trash_item(): void
    {
        if (!$this->is_post_request()) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request method.", "optistate"),
                405
            );

            return;
        }

        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        $key = $this->read_trash_key();

        if ($key === "") {
            OPTISTATE_Utils::send_json_error(
                __("Invalid trash item key.", "optistate")
            );

            return;
        }

        try {
            if ($this->restore_from_trash($key)) {
                OPTISTATE_Utils::send_json_success([
                    "message" => __("Item restored successfully.", "optistate"),
                ]);

                return;
            }

            OPTISTATE_Utils::send_json_error(
                __("Failed to restore item.", "optistate")
            );
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error("Restore from trash failed", [
                "error" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
            ]);

            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while restoring the item.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_permanently_delete_trash_item(): void
    {
        if (!$this->is_post_request()) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request method.", "optistate"),
                405
            );

            return;
        }

        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        $key = $this->read_trash_key();

        if ($key === "") {
            OPTISTATE_Utils::send_json_error(
                __("Invalid trash item key.", "optistate")
            );

            return;
        }

        try {
            $deleted = $this->permanently_delete($key);

            $this->flush_table_cache();

            if ($deleted) {
                OPTISTATE_Utils::send_json_success([
                    "message" => __("Item permanently deleted.", "optistate"),
                ]);

                return;
            }

            OPTISTATE_Utils::send_json_error(
                __("Failed to delete item permanently.", "optistate")
            );
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Permanent delete from trash failed",
                [
                    "error" => $e->getMessage(),
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                ]
            );

            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while deleting the item.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_delete_all_trash(): void
    {
        if (!$this->is_post_request()) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request method.", "optistate"),
                405
            );

            return;
        }

        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();

        if (
            !OPTISTATE_Utils::check_rate_limit(
                "delete_all_trash",
                self::DELETE_ALL_RATE_LIMIT
            )
        ) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );

            return;
        }

        try {
            $result = $this->delete_all_trash();

            $this->main_plugin->clear_stats_cache();

            if (!$result["completed"]) {
                $message = sprintf(
                    __(
                        "Deleted %1\$s items (timeout protection - %2\$s items remain).<br>Run again to continue.",
                        "optistate"
                    ),
                    number_format_i18n($result["deleted"]),
                    number_format_i18n($result["remaining"])
                );
            } elseif ($result["failed"] > 0) {
                $message = sprintf(
                    __(
                        "Deleted %1\$s items from trash. %2\$s items could not be removed and were left in place.",
                        "optistate"
                    ),
                    number_format_i18n($result["deleted"]),
                    number_format_i18n($result["failed"])
                );
            } else {
                $message = sprintf(
                    __("Deleted %s items from trash.", "optistate"),
                    number_format_i18n($result["deleted"])
                );
            }

            OPTISTATE_Utils::send_json_success([
                "message" => $message,
                "deleted" => $result["deleted"],
                "failed" => $result["failed"],
                "remaining" => $result["remaining"],
                "completed" => $result["completed"],
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "delete_all_trash failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );

            OPTISTATE_Utils::send_json_error(
                __(
                    "An error occurred while deleting all trash items.",
                    "optistate"
                )
            );
        }
    }
}