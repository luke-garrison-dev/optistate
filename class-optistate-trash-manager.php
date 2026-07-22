<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Trash_Manager
{
    private OPTISTATE $main_plugin;
    private ?object $wp_filesystem;
    private string $trash_table;
    private string $trash_dir;
    private const TRASH_MAX_AGE = 14 * DAY_IN_SECONDS;
    private static ?bool $table_exists_cache = null;
    private const META_TRASH_MAX_ROWS = 100000;
    private const META_TRASH_MAX_FILE_SIZE = 50 * 1024 * 1024;

    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        $this->wp_filesystem = $main_plugin->get_filesystem();
        $upload_dir = wp_upload_dir();
        $this->trash_dir =
            trailingslashit($upload_dir["basedir"]) . "optistate/trash/";
        global $wpdb;
        $this->trash_table = $wpdb->prefix . "optistate_trash";
        $this->ensure_table_exists();
        $this->ensure_trash_directory();
        add_action("wp_ajax_optistate_delete_all_trash", [
            $this,
            "ajax_delete_all_trash",
        ]);
    }
    public function ensure_table_exists(): void
    {
        if (self::$table_exists_cache === true) {
            return;
        }
        if (get_transient("optistate_trash_table_exists") !== false) {
            self::$table_exists_cache = true;
            return;
        }
        global $wpdb;
        OPTISTATE_Utils::clear_table_existence_cache($this->trash_table);
        if (!function_exists("dbDelta")) {
            require_once ABSPATH . "wp-admin/includes/upgrade.php";
        }
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->trash_table} (id bigint(20) NOT NULL AUTO_INCREMENT, trash_key varchar(191) NOT NULL, type enum('folder','table','option','postmeta','commentmeta','usermeta','termmeta') NOT NULL, original_name varchar(255) NOT NULL, trash_path_or_name varchar(255) NOT NULL, meta longtext, size bigint(20) DEFAULT 0, deleted_at bigint(20) NOT NULL, expires_at bigint(20) DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY trash_key (trash_key), KEY type (type), KEY expires_at (expires_at), KEY deleted_at (deleted_at)) {$charset_collate};";
        try {
            dbDelta($sql);
            $exists = (bool) $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $this->trash_table)
            );
            if ($exists) {
                self::$table_exists_cache = true;
                set_transient(
                    "optistate_trash_table_exists",
                    true,
                    48 * HOUR_IN_SECONDS
                );
            }
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ensure_table_exists failed for trash table",
                ["error" => $e->getMessage()]
            );
        }
    }
    private function ensure_trash_directory(bool $force = false): void
    {
        if (!$force && get_transient("optistate_trash_dir_checked")) {
            return;
        }
        $this->main_plugin->ensure_directory(
            $this->trash_dir,
            0755,
            OPTISTATE::HTACCESS_RULES_TRASH
        );
        set_transient(
            "optistate_trash_dir_checked",
            true,
            OPTISTATE::DIR_CHECK_TIME
        );
    }
    private function generate_trash_key(
        string $type,
        string $identifier
    ): string {
        return $type .
            "_" .
            md5($identifier . "_" . time() . "_" . bin2hex(random_bytes(8)));
    }
    public function move_to_trash(
        string $type,
        string $identifier,
        array $extra = []
    ) {
        $this->ensure_table_exists();
        global $wpdb;
        if (!OPTISTATE_Utils::table_exists($this->trash_table)) {
            $this->main_plugin->log_entry(
                "❌ Cannot move to trash: trash table does not exist.",
                "error"
            );
            return false;
        }
        $valid_types = [
            "folder",
            "table",
            "option",
            "postmeta",
            "commentmeta",
            "usermeta",
            "termmeta",
        ];
        if (!in_array($type, $valid_types, true)) {
            return false;
        }
        $trash_key = $this->generate_trash_key($type, $identifier);
        $deleted_at = time();
        $expires_at = $deleted_at + self::TRASH_MAX_AGE;
        $size = 0;
        switch ($type) {
            case "folder":
                if (!$this->wp_filesystem->is_dir($this->trash_dir)) {
                    $this->ensure_trash_directory(true);
                }
                $source_path = $identifier;
                if (!is_dir($source_path)) {
                    return false;
                }
                $folder_stats = OPTISTATE_Utils::get_folder_size(
                    $source_path,
                    50000,
                    5,
                    false
                );
                $size = $folder_stats["size"] ?? 0;
                $file_count = $folder_stats["file_count"] ?? 0;
                $basename = basename($source_path);
                $unique_suffix = "-" . time() . "-" . bin2hex(random_bytes(6));
                $trash_name = $basename . $unique_suffix;
                $trash_path = $this->trash_dir . $trash_name;
                $extra["original_path"] = $source_path;
                $extra["relative_path"] = $this->get_relative_path(
                    $source_path
                );
                $extra["basename"] = $basename;
                $extra["file_count"] = $file_count;
                $inserted = $wpdb->insert(
                    $this->trash_table,
                    [
                        "trash_key" => $trash_key,
                        "type" => $type,
                        "original_name" => $basename,
                        "trash_path_or_name" => $trash_path,
                        "meta" => wp_json_encode($extra),
                        "size" => $size,
                        "deleted_at" => $deleted_at,
                        "expires_at" => $expires_at,
                    ],
                    ["%s", "%s", "%s", "%s", "%s", "%d", "%d", "%d"]
                );
                if ($inserted === false) {
                    $this->main_plugin->log_entry(
                        "❌ Failed to insert trash record for folder: {$basename}",
                        "error"
                    );
                    return false;
                }
                if (
                    !$this->wp_filesystem->move($source_path, $trash_path, true)
                ) {
                    $wpdb->delete($this->trash_table, [
                        "trash_key" => $trash_key,
                    ]);
                    $this->main_plugin->log_entry(
                        "❌ Failed to move folder to trash: {$source_path}",
                        "error"
                    );
                    return false;
                }
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "🗑 Moved legacy %s to trash: %s by {username}",
                            "optistate"
                        ),
                        $type,
                        $extra["relative_path"]
                    )
                );
                $this->main_plugin->clear_stats_cache();
                return $trash_key;
            case "table":
                $table_name = $identifier;
                $safe_table = OPTISTATE_Utils::validate_table_name($table_name);
                if (!$safe_table) {
                    return false;
                }
                $info = OPTISTATE_Utils::with_stats_expiry_disabled(
                    function () use ($wpdb, $table_name) {
                        return $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT data_length + index_length AS size, table_rows FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                                DB_NAME,
                                $table_name
                            )
                        );
                    }
                );
                $size = (int) ($info->size ?? 0);
                $row_count = (int) ($info->table_rows ?? 0);
                if ($size === 0) {
                    $like_table = $wpdb->esc_like($table_name);
                    $status = $wpdb->get_row(
                        "SHOW TABLE STATUS LIKE '{$like_table}'",
                        ARRAY_A
                    );
                    if ($status) {
                        $size =
                            (int) $status["Data_length"] +
                            (int) $status["Index_length"];
                        $row_count = (int) $status["Rows"];
                    }
                }
                $prefix = $wpdb->prefix . "trash_";
                $trash_table_name = OPTISTATE_Utils::generate_safe_table_name(
                    $table_name . "_" . time(),
                    $prefix
                );
                $max_attempts = 10;
                $attempt = 0;
                while (OPTISTATE_Utils::table_exists($trash_table_name)) {
                    if (++$attempt >= $max_attempts) {
                        $this->main_plugin->log_entry(
                            "❌ Could not generate unique trash table name for: {$table_name}",
                            "error"
                        );
                        return false;
                    }
                    $trash_table_name = OPTISTATE_Utils::generate_safe_table_name(
                        $table_name .
                            "_" .
                            time() .
                            "_" .
                            bin2hex(random_bytes(2)),
                        $prefix
                    );
                }
                $extra["row_count"] = $row_count;
                $extra["original_table"] = $table_name;
                $result = $wpdb->insert(
                    $this->trash_table,
                    [
                        "trash_key" => $trash_key,
                        "type" => $type,
                        "original_name" => $table_name,
                        "trash_path_or_name" => $trash_table_name,
                        "meta" => wp_json_encode($extra),
                        "size" => $size,
                        "deleted_at" => $deleted_at,
                        "expires_at" => $expires_at,
                    ],
                    ["%s", "%s", "%s", "%s", "%s", "%d", "%d", "%d"]
                );
                if ($result === false) {
                    return false;
                }
                $safe_trash = OPTISTATE_Utils::escape_identifier(
                    trim($trash_table_name, "`")
                );
                $renamed = OPTISTATE_Utils::without_foreign_key_checks(
                    function () use ($safe_table, $safe_trash, $wpdb) {
                        return $wpdb->query(
                            "RENAME TABLE {$safe_table} TO {$safe_trash}"
                        );
                    }
                );
                if ($renamed === false) {
                    $wpdb->delete($this->trash_table, [
                        "trash_key" => $trash_key,
                    ]);
                    return false;
                }
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "🗑 Moved legacy %s to trash: %s by {username}",
                            "optistate"
                        ),
                        $type,
                        $table_name
                    )
                );
                $this->main_plugin->clear_stats_cache();
                return $trash_key;
            case "option":
                $option_name = $identifier;
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
                        $option_name
                    )
                );
                if (!$row) {
                    return false;
                }
                $option_value = $row->option_value;
                $autoload = $row->autoload ?: "no";
                $trash_option_name =
                    "_optistate_trash_" . md5($option_name . "_" . time());
                $wpdb->query("START TRANSACTION");
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
                    $wpdb->delete(
                        $wpdb->options,
                        ["option_name" => $trash_option_name],
                        ["%s"]
                    );
                    return false;
                }
                $meta = [
                    "original_option" => $option_name,
                    "autoload" => $autoload,
                ];
                $inserted_meta = $wpdb->insert(
                    $this->trash_table,
                    [
                        "trash_key" => $trash_key,
                        "type" => $type,
                        "original_name" => $option_name,
                        "trash_path_or_name" => $trash_option_name,
                        "meta" => wp_json_encode($meta),
                        "size" => strlen($option_value),
                        "deleted_at" => $deleted_at,
                        "expires_at" => $expires_at,
                    ],
                    ["%s", "%s", "%s", "%s", "%s", "%d", "%d", "%d"]
                );
                if ($inserted_meta === false) {
                    $wpdb->query("ROLLBACK");
                    return false;
                }
                $wpdb->query("COMMIT");
                wp_cache_delete($option_name, "options");
                wp_cache_delete("alloptions", "options");
                wp_cache_delete("notoptions", "options");
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "🗑 Moved legacy %s to trash: %s by {username}",
                            "optistate"
                        ),
                        $type,
                        $option_name
                    )
                );
                $this->main_plugin->clear_stats_cache();
                return $trash_key;
            case "postmeta":
            case "commentmeta":
            case "usermeta":
            case "termmeta":
                $meta_key = $identifier;
                $table_map = [
                    "postmeta" => [
                        "table" => $wpdb->postmeta,
                        "id_col" => "post_id",
                        "meta_id_col" => "meta_id",
                    ],
                    "commentmeta" => [
                        "table" => $wpdb->commentmeta,
                        "id_col" => "comment_id",
                        "meta_id_col" => "meta_id",
                    ],
                    "usermeta" => [
                        "table" => $wpdb->usermeta,
                        "id_col" => "user_id",
                        "meta_id_col" => "umeta_id",
                    ],
                    "termmeta" => [
                        "table" => $wpdb->termmeta,
                        "id_col" => "term_id",
                        "meta_id_col" => "meta_id",
                    ],
                ];
                $info = $table_map[$type];
                $table = $info["table"];
                $meta_id_col = $info["meta_id_col"];
                wp_raise_memory_limit("admin");
                $count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s",
                        $meta_key
                    )
                );
                if ($count === 0) {
                    return false;
                }
                if ($count > self::META_TRASH_MAX_ROWS) {
                    $this->main_plugin->log_entry(
                        "⚠️ Trash move aborted: meta key " .
                            $meta_key .
                            " has " .
                            number_format($count) .
                            " rows (exceeds " .
                            number_format(self::META_TRASH_MAX_ROWS) .
                            " limit)",
                        "error"
                    );
                    return false;
                }
                if (!$this->wp_filesystem->is_dir($this->trash_dir)) {
                    $this->ensure_trash_directory(true);
                }
                $file_path = $this->trash_dir . $trash_key . ".json";
                $handle = @fopen($file_path, "wb");
                if (!$handle) {
                    $this->main_plugin->log_entry(
                        "❌ Failed to open trash file for writing: {$file_path}",
                        "error"
                    );
                    return false;
                }
                if (!flock($handle, LOCK_EX)) {
                    fclose($handle);
                    @unlink($file_path);
                    $this->main_plugin->log_entry(
                        "❌ Failed to acquire exclusive lock on trash file: {$file_path}",
                        "error"
                    );
                    return false;
                }
                if (fwrite($handle, "[") === false) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    @unlink($file_path);
                    $this->main_plugin->log_entry(
                        "❌ Failed to write opening bracket to trash file: {$file_path}",
                        "error"
                    );
                    return false;
                }
                $chunk_size = 1000;
                $last_id = 0;
                $first_row = true;
                $any_written = false;
                while (true) {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM {$table} WHERE meta_key = %s AND {$meta_id_col} > %d ORDER BY {$meta_id_col} ASC LIMIT %d",
                            $meta_key,
                            $last_id,
                            $chunk_size
                        ),
                        ARRAY_A
                    );
                    if (empty($rows)) {
                        break;
                    }
                    foreach ($rows as $row) {
                        if (!$first_row) {
                            if (fwrite($handle, ",") === false) {
                                flock($handle, LOCK_UN);
                                fclose($handle);
                                @unlink($file_path);
                                $this->main_plugin->log_entry(
                                    "❌ Write error (comma) to trash file: {$file_path}",
                                    "error"
                                );
                                return false;
                            }
                        }
                        $first_row = false;
                        $any_written = true;
                        $last_id = (int) $row[$meta_id_col];
                        unset($row[$meta_id_col]);
                        $json_row = json_encode(
                            $row,
                            JSON_INVALID_UTF8_SUBSTITUTE
                        );
                        if (
                            json_last_error() !== JSON_ERROR_NONE ||
                            $json_row === false
                        ) {
                            $encoded_row = [
                                "_optistate_encoded" => true,
                                "data" => base64_encode(serialize($row)),
                            ];
                            $json_row = json_encode($encoded_row);
                        }
                        if (fwrite($handle, $json_row) === false) {
                            flock($handle, LOCK_UN);
                            fclose($handle);
                            @unlink($file_path);
                            $this->main_plugin->log_entry(
                                "❌ Write error (row) to trash file: {$file_path}",
                                "error"
                            );
                            return false;
                        }
                    }
                    usleep(5000);
                }
                if (fwrite($handle, "]") === false) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    @unlink($file_path);
                    $this->main_plugin->log_entry(
                        "❌ Write error (closing bracket) to trash file: {$file_path}",
                        "error"
                    );
                    return false;
                }
                flock($handle, LOCK_UN);
                fclose($handle);
                if (!$any_written) {
                    @unlink($file_path);
                    return false;
                }
                clearstatcache(true, $file_path);
                $file_size = filesize($file_path);
                if ($file_size === false || $file_size === 0) {
                    @unlink($file_path);
                    $this->main_plugin->log_entry(
                        "❌ Trash file is empty or unreadable after write: {$file_path}",
                        "error"
                    );
                    return false;
                }
                $meta_store = [
                    "original_key" => $meta_key,
                    "row_count" => $count,
                    "meta_id_col" => $meta_id_col,
                ];
                $inserted = $wpdb->insert(
                    $this->trash_table,
                    [
                        "trash_key" => $trash_key,
                        "type" => $type,
                        "original_name" => $meta_key,
                        "trash_path_or_name" => $file_path,
                        "meta" => wp_json_encode($meta_store),
                        "size" => $file_size,
                        "deleted_at" => $deleted_at,
                        "expires_at" => $expires_at,
                    ],
                    ["%s", "%s", "%s", "%s", "%s", "%d", "%d", "%d"]
                );
                if ($inserted === false) {
                    @unlink($file_path);
                    return false;
                }
                $deleted = $wpdb->delete($table, ["meta_key" => $meta_key]);
                if ($deleted === false) {
                    $wpdb->delete($this->trash_table, [
                        "trash_key" => $trash_key,
                    ]);
                    @unlink($file_path);
                    return false;
                }
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            "🗑 Moved legacy %s to trash: %s by {username}",
                            "optistate"
                        ),
                        $type,
                        $meta_key
                    )
                );
                $this->main_plugin->clear_stats_cache();
                return $trash_key;
            default:
                return false;
        }
    }
    public function restore_from_trash(string $trash_key): bool
    {
        global $wpdb;
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->trash_table} WHERE trash_key = %s",
                $trash_key
            )
        );
        if (!$item) {
            return false;
        }
        $type = $item->type;
        $meta = !empty($item->meta) ? json_decode($item->meta, true) : [];
        $trash_deleted = false;
        switch ($type) {
            case "folder":
                $trash_path = $item->trash_path_or_name;
                $original_path = $meta["original_path"] ?? "";
                if (
                    !$this->wp_filesystem->exists($trash_path) ||
                    !$this->wp_filesystem->is_dir($trash_path)
                ) {
                    return false;
                }
                if (empty($original_path)) {
                    return false;
                }
                $normalized_target = wp_normalize_path($original_path);
                if (
                    strpos($normalized_target, "/../") !== false ||
                    strpos($normalized_target, "\\..\\") !== false ||
                    strpos($normalized_target, "/..\\") !== false
                ) {
                    $this->main_plugin->log_entry(
                        "❌ Folder restore blocked: path traversal detected",
                        "error"
                    );
                    return false;
                }
                $upload_dir = wp_upload_dir();
                $valid_base_paths = array_filter([
                    wp_normalize_path(realpath($upload_dir["basedir"])),
                    wp_normalize_path(realpath(WP_CONTENT_DIR)),
                    wp_normalize_path(realpath(WP_PLUGIN_DIR)),
                    wp_normalize_path(realpath(get_theme_root())),
                    wp_normalize_path(realpath(WPMU_PLUGIN_DIR)),
                ]);
                if (empty($valid_base_paths)) {
                    $this->main_plugin->log_entry(
                        "❌ Folder restore blocked: could not determine safe base paths",
                        "error"
                    );
                    return false;
                }
                $is_valid = false;
                $target_slashed = trailingslashit($normalized_target);
                foreach ($valid_base_paths as $base_path) {
                    if (
                        strpos($target_slashed, trailingslashit($base_path)) ===
                        0
                    ) {
                        $is_valid = true;
                        break;
                    }
                }
                if (!$is_valid) {
                    $this->main_plugin->log_entry(
                        "❌ Folder restore blocked: target outside allowed directories",
                        "error"
                    );
                    return false;
                }
                if ($this->wp_filesystem->exists($original_path)) {
                    return false;
                }
                $parent = dirname($original_path);
                if (!$this->wp_filesystem->is_dir($parent)) {
                    wp_mkdir_p($parent);
                }
                if (
                    !$this->wp_filesystem->move(
                        $trash_path,
                        $original_path,
                        true
                    )
                ) {
                    return false;
                }
                $meta_file = $trash_path . ".meta";
                if ($this->wp_filesystem->exists($meta_file)) {
                    $this->wp_filesystem->delete($meta_file);
                }
                break;
            case "table":
                $trash_table = $item->trash_path_or_name;
                $original_table = $meta["original_table"] ?? "";
                if (
                    empty($original_table) ||
                    !OPTISTATE_Utils::table_exists($trash_table)
                ) {
                    return false;
                }
                if (OPTISTATE_Utils::table_exists($original_table)) {
                    return false;
                }
                $safe_trash = OPTISTATE_Utils::escape_identifier(
                    trim($trash_table, "`")
                );
                $safe_original = OPTISTATE_Utils::escape_identifier(
                    trim($original_table, "`")
                );
                $renamed = OPTISTATE_Utils::without_foreign_key_checks(
                    function () use ($safe_trash, $safe_original, $wpdb) {
                        return $wpdb->query(
                            "RENAME TABLE {$safe_trash} TO {$safe_original}"
                        );
                    }
                );
                if ($renamed === false) {
                    return false;
                }
                break;
            case "option":
                $trash_option = $item->trash_path_or_name;
                $original_option = $meta["original_option"] ?? "";
                if (empty($original_option)) {
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
                $original_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
                        $original_option
                    )
                );
                if ($original_exists !== null) {
                    return false;
                }
                $autoload = $meta["autoload"] ?? "no";
                $wpdb->query("START TRANSACTION");
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
                    $wpdb->delete(
                        $wpdb->options,
                        ["option_name" => $original_option],
                        ["%s"]
                    );
                    return false;
                }
                $trash_removed = $wpdb->delete($this->trash_table, [
                    "trash_key" => $trash_key,
                ]);
                if ($trash_removed === false) {
                    $wpdb->query("ROLLBACK");
                    $wpdb->delete(
                        $wpdb->options,
                        ["option_name" => $original_option],
                        ["%s"]
                    );
                    return false;
                }
                $wpdb->query("COMMIT");
                wp_cache_delete($original_option, "options");
                wp_cache_delete("alloptions", "options");
                wp_cache_delete("notoptions", "options");
                $trash_deleted = true;
                break;
            case "postmeta":
            case "commentmeta":
            case "usermeta":
            case "termmeta":
                wp_raise_memory_limit("admin");
                $file_path = $item->trash_path_or_name;
                if (empty($file_path) || !file_exists($file_path)) {
                    return false;
                }
                $file_size = filesize($file_path);
                if (
                    $file_size === false ||
                    $file_size > self::META_TRASH_MAX_FILE_SIZE
                ) {
                    $this->main_plugin->log_entry(
                        "❌ Meta restore aborted: file too large or unreadable ({$file_path})",
                        "error"
                    );
                    return false;
                }
                $json_content = file_get_contents($file_path);
                if ($json_content === false) {
                    return false;
                }
                $entries = json_decode($json_content, true);
                if (
                    json_last_error() !== JSON_ERROR_NONE ||
                    !is_array($entries) ||
                    empty($entries)
                ) {
                    $this->main_plugin->log_entry(
                        "❌ Meta restore failed: invalid JSON in trash file",
                        "error"
                    );
                    return false;
                }
                unset($json_content);
                $table_map = [
                    "postmeta" => [
                        "table" => $wpdb->postmeta,
                        "meta_id_col" => "meta_id",
                    ],
                    "commentmeta" => [
                        "table" => $wpdb->commentmeta,
                        "meta_id_col" => "meta_id",
                    ],
                    "usermeta" => [
                        "table" => $wpdb->usermeta,
                        "meta_id_col" => "umeta_id",
                    ],
                    "termmeta" => [
                        "table" => $wpdb->termmeta,
                        "meta_id_col" => "meta_id",
                    ],
                ];
                $info = $table_map[$type];
                $table = $info["table"];
                $meta_id_col = $info["meta_id_col"];
                $wpdb->query("START TRANSACTION");
                $batch = [];
                $batch_size = 100;
                foreach ($entries as $entry) {
                    if (
                        is_array($entry) &&
                        isset($entry["_optistate_encoded"]) &&
                        $entry["_optistate_encoded"] === true
                    ) {
                        $decoded = base64_decode($entry["data"] ?? "", true);
                        $entry =
                            $decoded !== false
                                ? unserialize($decoded, [
                                    "allowed_classes" => false,
                                ])
                                : false;
                        if (!is_array($entry)) {
                            $wpdb->query("ROLLBACK");
                            $this->main_plugin->log_entry(
                                "❌ Failed to decode a fallback-encoded row while restoring from trash",
                                "error"
                            );
                            return false;
                        }
                    }
                    unset($entry[$meta_id_col]);
                    $batch[] = $entry;
                    if (count($batch) >= $batch_size) {
                        if (!$this->bulk_insert_batch($wpdb, $table, $batch)) {
                            $wpdb->query("ROLLBACK");
                            return false;
                        }
                        $batch = [];
                        usleep(10000);
                    }
                }
                if (!empty($batch)) {
                    if (!$this->bulk_insert_batch($wpdb, $table, $batch)) {
                        $wpdb->query("ROLLBACK");
                        return false;
                    }
                }
                $wpdb->query("COMMIT");
                $wpdb->delete($this->trash_table, ["trash_key" => $trash_key]);
                @unlink($file_path);
                $trash_deleted = true;
                break;
            default:
                return false;
        }
        if (!$trash_deleted) {
            $wpdb->delete($this->trash_table, ["trash_key" => $trash_key]);
        }
        $this->main_plugin->log_entry(
            "↩ Restored from trash: " .
                ($meta["relative_path"] ?? $item->original_name) .
                " (" .
                $type .
                ") by {username}"
        );
        $this->main_plugin->clear_stats_cache();
        return true;
    }
    public function permanently_delete(
        string $trash_key,
        string $log_type = "manual",
        bool $suppress_log = false
    ): bool {
        global $wpdb;
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->trash_table} WHERE trash_key = %s",
                $trash_key
            )
        );
        if (!$item) {
            return false;
        }
        $type = $item->type;
        $meta = !empty($item->meta) ? json_decode($item->meta, true) : [];
        switch ($type) {
            case "folder":
                $trash_path = $item->trash_path_or_name;
                if ($this->wp_filesystem->exists($trash_path)) {
                    $this->wp_filesystem->delete($trash_path, true);
                }
                $meta_file = $trash_path . ".meta";
                if ($this->wp_filesystem->exists($meta_file)) {
                    $this->wp_filesystem->delete($meta_file);
                }
                break;
            case "table":
                $trash_table = $item->trash_path_or_name;
                if (OPTISTATE_Utils::table_exists($trash_table)) {
                    $safe_trash = OPTISTATE_Utils::escape_identifier(
                        trim($trash_table, "`")
                    );
                    OPTISTATE_Utils::without_foreign_key_checks(
                        function () use ($safe_trash, $wpdb) {
                            $wpdb->query("DROP TABLE {$safe_trash}");
                            return true;
                        }
                    );
                }
                break;
            case "option":
                $trash_option = $item->trash_path_or_name;
                $option_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
                        $trash_option
                    )
                );
                if ($option_id !== null) {
                    $wpdb->delete(
                        $wpdb->options,
                        ["option_name" => $trash_option],
                        ["%s"]
                    );
                    wp_cache_delete($trash_option, "options");
                    wp_cache_delete("alloptions", "options");
                }
                break;
            case "postmeta":
            case "commentmeta":
            case "usermeta":
            case "termmeta":
                $file_path = $item->trash_path_or_name;
                if (!empty($file_path) && file_exists($file_path)) {
                    @unlink($file_path);
                }
                break;
            default:
                return false;
        }
        $wpdb->delete($this->trash_table, ["trash_key" => $trash_key]);
        if (!$suppress_log) {
            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        "🗑 Permanently deleted from trash: %s (%s) by {username}",
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
    public function list_trash_items(array $filters = []): array
    {
        global $wpdb;
        $where = ["1=1"];
        $params = [];
        if (!empty($filters["type"])) {
            $where[] = "type = %s";
            $params[] = $filters["type"];
        }
        if (!empty($filters["search"])) {
            $where[] = "original_name LIKE %s";
            $params[] = "%" . $wpdb->esc_like($filters["search"]) . "%";
        }
        $where_sql = implode(" AND ", $where);
        $limit = absint($filters["limit"] ?? 200);
        $offset = absint($filters["offset"] ?? 0);
        $sql = "SELECT * FROM {$this->trash_table} WHERE {$where_sql} ORDER BY deleted_at DESC";
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        $items = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($items)) {
            return [];
        }
        foreach ($items as &$item) {
            $decoded_meta = !empty($item["meta"])
                ? json_decode($item["meta"], true)
                : [];
            $item["display_name"] = $item["original_name"];
            $item["human_time"] =
                human_time_diff($item["deleted_at"], time()) . " ago";
            $item["size_human"] = $item["size"]
                ? size_format($item["size"], 2)
                : "0 B";
            if ($item["type"] === "folder") {
                $item["display_path"] =
                    $decoded_meta["relative_path"] ?? $item["original_name"];
            } elseif ($item["type"] === "table") {
                $item["display_path"] =
                    $decoded_meta["original_table"] ?? $item["original_name"];
            } elseif ($item["type"] === "option") {
                $item["display_path"] =
                    $decoded_meta["original_option"] ?? $item["original_name"];
            } else {
                $item["display_path"] = $item["original_name"];
            }
        }
        unset($item);
        return $items;
    }
    public function cleanup_trash(): int
    {
        global $wpdb;
        if (!OPTISTATE_Utils::table_exists($this->trash_table)) {
            return 0;
        }
        $now = time();
        $expired = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT trash_key FROM {$this->trash_table} WHERE expires_at > 0 AND expires_at < %d LIMIT 100",
                $now
            )
        );
        $count = 0;
        $start_time = microtime(true);
        foreach ($expired as $key) {
            if (microtime(true) - $start_time > 20.0) {
                break;
            }
            if ($this->permanently_delete($key, "scheduled", true)) {
                $count++;
            }
        }
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
        return $count;
    }
    public function delete_all_trash(): array
    {
        wp_raise_memory_limit("admin");
        OPTISTATE_Utils::safe_set_time_limit(180);
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->trash_table}"
        );
        if ($total === 0) {
            return ["deleted" => 0, "remaining" => 0, "completed" => true];
        }
        $keys = $wpdb->get_col("SELECT trash_key FROM {$this->trash_table}");
        $deleted = 0;
        $start_time = microtime(true);
        $max_exec = (int) ini_get("max_execution_time");
        if ($max_exec <= 0) {
            $max_exec = 180;
        }
        $time_limit = max(10, $max_exec * 0.8);
        foreach ($keys as $key) {
            if ($this->permanently_delete($key, "manual", true)) {
                $deleted++;
            }
            if ($deleted % 10 === 0) {
                if (microtime(true) - $start_time >= $time_limit) {
                    break;
                }
            }
        }
        $remaining = $total - $deleted;
        $log_message = sprintf(
            __("🗑 Permanently deleted %s items from trash", "optistate"),
            number_format_i18n($deleted)
        );
        if ($remaining > 0) {
            $log_message .= sprintf(
                __(" (partial – %s items remain)", "optistate"),
                number_format_i18n($remaining)
            );
        }
        $this->main_plugin->log_entry($log_message . " by {username}");
        return [
            "deleted" => $deleted,
            "remaining" => $remaining,
            "completed" => $remaining === 0,
        ];
    }
    public function ajax_delete_all_trash(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("delete_all_trash", 30)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        try {
            $result = $this->delete_all_trash();
            $deleted = $result["deleted"];
            $remaining = $result["remaining"];
            $completed = $result["completed"];
            $this->main_plugin->clear_stats_cache();
            $response_message = $completed
                ? sprintf(
                    __("Deleted %s items from trash.", "optistate"),
                    number_format_i18n($deleted)
                )
                : sprintf(
                    __(
                        "Deleted %s items (timeout protection – %s items remain).<br>Run again to continue.",
                        "optistate"
                    ),
                    number_format_i18n($deleted),
                    number_format_i18n($remaining)
                );
            OPTISTATE_Utils::send_json_success([
                "message" => $response_message,
                "deleted" => $deleted,
                "remaining" => $remaining,
                "completed" => $completed,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "delete_all_trash failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An error occurred while deleting all trash items.",
                    "optistate"
                )
            );
        }
    }
    private function get_relative_path(string $path): string
    {
        $abspath = wp_normalize_path(ABSPATH);
        $path = wp_normalize_path($path);
        return ltrim(str_replace($abspath, "", $path), "/");
    }
    private function bulk_insert_batch($wpdb, string $table, array $batch): bool
    {
        if (empty($batch)) {
            return true;
        }
        $columns = array_keys($batch[0]);
        $placeholder_row =
            "(" . implode(",", array_fill(0, count($columns), "%s")) . ")";
        $placeholders = array_fill(0, count($batch), $placeholder_row);
        $values = [];
        foreach ($batch as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col];
            }
        }
        $quoted_columns = array_map(
            [OPTISTATE_Utils::class, "escape_identifier"],
            $columns
        );
        $sql = $wpdb->prepare(
            "INSERT INTO " .
                $table .
                " (" .
                implode(",", $quoted_columns) .
                ") VALUES " .
                implode(",", $placeholders),
            ...$values
        );
        if ($wpdb->query($sql) === false) {
            foreach ($batch as $single_row) {
                if ($wpdb->insert($table, $single_row) === false) {
                    return false;
                }
            }
        }
        return true;
    }
}