<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Backup_Utilities
{
    private const DISK_SAFETY_BUFFER_BYTES = 100 * 1024 * 1024;

    public static function cleanup_old_temp_files_daily(
        OPTISTATE $main_plugin,
        OPTISTATE_Process_Store $process_store
    ): void {
        $process_store->cleanup();
        $restore_in_progress = $process_store->get("optistate_restore_in_progress");
        if ($restore_in_progress) {
            if (is_array($restore_in_progress) && !empty($restore_in_progress["restore_key"])) {
                return;
            }
            if (is_string($restore_in_progress) && $restore_in_progress !== "") {
                return;
            }
        }

        $filesystem = $main_plugin->get_filesystem();
        if ($filesystem) {
            $upload_dir = wp_upload_dir();
            $temp_dir =
                trailingslashit($upload_dir["basedir"]) .
                OPTISTATE::TEMP_DIR_NAME .
                "/";
            $temp_max_age = 2 * HOUR_IN_SECONDS;
            OPTISTATE_Utils::cleanup_temp_files(
                $filesystem,
                $temp_dir,
                null,
                $temp_max_age,
                [
                    ".sql",
                    ".sql.gz",
                    "decompressed-",
                    "restore-temp-",
                    ".tmp",
                    ".partial",
                    ".lock",
                ]
            );
            $backup_dir =
                trailingslashit($upload_dir["basedir"]) .
                OPTISTATE::BACKUP_DIR_NAME .
                "/";
            self::cleanup_partial_backups($filesystem, $backup_dir);
        }

        try {
            global $wpdb;
            $login_table =
                $wpdb->prefix . OPTISTATE_Login_Protection::TABLE_NAME;
            if (OPTISTATE_Utils::table_exists($login_table)) {
                $current_time = time();
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM `$login_table` WHERE blocked_until < %d AND updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
                        $current_time
                    )
                );
            }
            $db_name = defined("DB_NAME") ? DB_NAME : "";
            $query = $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND (TABLE_NAME LIKE 'optistate_old_%%' OR TABLE_NAME LIKE 'optistate_temp_%%')",
                $db_name
            );
            $old_tables = $wpdb->get_col($query);
            if (!empty($old_tables)) {
                try {
                    $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
                    $table_chunks = array_chunk($old_tables, 50);
                    foreach ($table_chunks as $chunk) {
                        $tables_to_drop = [];
                        foreach ($chunk as $table) {
                            $tables_to_drop[] = OPTISTATE_Utils::escape_identifier(
                                $table
                            );
                        }
                        $wpdb->query(
                            "DROP TABLE IF EXISTS " .
                                implode(", ", $tables_to_drop)
                        );
                    }
                } finally {
                    $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
            OPTISTATE_DB_Wrapper::close_if_instantiated();
        } catch (Throwable $e) {
            global $wpdb;
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
            OPTISTATE_DB_Wrapper::close_if_instantiated();
        }
        if (get_option("optistate_maintenance_mode_active")) {
            delete_option("optistate_maintenance_mode_active");
        }
    }

    private static function cleanup_partial_backups(
        $filesystem,
        string $backup_dir
    ): void {
        if (!$filesystem->is_dir($backup_dir)) {
            return;
        }
        $files = $filesystem->dirlist($backup_dir);
        if (empty($files)) {
            return;
        }
        global $wpdb;
        $meta_table = $wpdb->prefix . "optistate_backup_metadata";
        $known_filenames = $wpdb->get_col("SELECT filename FROM {$meta_table}");
        $known_filenames_lookup = is_array($known_filenames)
            ? array_flip($known_filenames)
            : [];
        foreach ($files as $filename => $fileinfo) {
            if ($fileinfo["type"] !== "f") {
                continue;
            }
            if (!preg_match('/\.sql\.gz$/i', $filename)) {
                continue;
            }
            $exists = isset($known_filenames_lookup[$filename]);
            if (!$exists) {
                $file_path = $backup_dir . $filename;
                $lock_path = $file_path . ".lock";
                if ($filesystem->exists($lock_path)) {
                    $lock_mtime = $filesystem->mtime($lock_path);
                    if ($lock_mtime && time() - $lock_mtime < 2 * HOUR_IN_SECONDS) {
                        continue;
                    }
                }
                $file_mtime = $filesystem->mtime($file_path);
                if ($file_mtime && time() - $file_mtime > HOUR_IN_SECONDS) {
                    $deleted = $filesystem->delete($file_path);
                    if (!$deleted) {
                        OPTISTATE_Utils::log_critical_error(
                            "cleanup_partial_backups: failed to delete orphaned backup",
                            ["file" => $file_path, "filename" => $filename]
                        );
                    }
                    if ($filesystem->exists($lock_path)) {
                        $filesystem->delete($lock_path);
                    }
                }
            }
        }
    }

    public static function check_sufficient_disk_space(
        $filesystem,
        ?string $backup_filepath = null,
        int $current_db_size = 0
    ): array {
        if (!$filesystem) {
            return [
                "success" => false,
                "message" => esc_html__(
                    "Filesystem not initialized for space check.",
                    "optistate"
                ),
            ];
        }
        if (!$backup_filepath && $current_db_size === 0) {
            return ["success" => true];
        }
        $free_space = false;
        if (!self::is_php_function_disabled("disk_free_space")) {
            try {
                $free_space = @disk_free_space(WP_CONTENT_DIR);
            } catch (\Throwable $e) {
                $free_space = false;
            }
        }
        if ($free_space === false) {
            return ["success" => true];
        }
        $required_space = 0;
        $safety_buffer = self::DISK_SAFETY_BUFFER_BYTES;
        if ($backup_filepath && $filesystem->exists($backup_filepath)) {
            $backup_file_size = $filesystem->size($backup_filepath);
            $is_compressed = preg_match('/\.gz$/i', $backup_filepath);
            $estimated_decompressed_size = $is_compressed
                ? $backup_file_size * 5
                : $backup_file_size;
            if (
                $is_compressed &&
                $estimated_decompressed_size > $free_space * 0.9
            ) {
                return [
                    "success" => false,
                    "message" => sprintf(
                        __(
                            "Insufficient Disk Space for Decompression.<br>Required: %s<br>Available: %s",
                            "optistate"
                        ),
                        size_format($estimated_decompressed_size, 2),
                        size_format($free_space, 2)
                    ),
                ];
            }
            $base_size = max($current_db_size, $estimated_decompressed_size);
            $required_space = $base_size * 2.5;
        } else {
            $required_space = $current_db_size * 1.2;
        }
        if ($free_space < $required_space + $safety_buffer) {
            $message = sprintf(
                esc_html__(
                    "Insufficient Disk Space!<br>Available: %s<br>Required (Est): %s",
                    "optistate"
                ),
                size_format($free_space, 2),
                size_format($required_space + $safety_buffer, 2)
            );
            return ["success" => false, "message" => $message];
        }
        return ["success" => true];
    }

    private static function is_php_function_disabled(
        string $function_name
    ): bool {
        if (!function_exists($function_name)) {
            return true;
        }
        $disabled_functions = @ini_get("disable_functions");
        $disabled_string = is_string($disabled_functions)
            ? $disabled_functions
            : "";
        if ($disabled_string === "") {
            return false;
        }
        $disabled_list = array_map(
            "trim",
            explode(",", strtolower($disabled_string))
        );
        return in_array(strtolower($function_name), $disabled_list, true);
    }

    public static function verify_backup_file(
        $filesystem,
        string $filepath,
        bool $skip_integrity_check = false,
        bool $check_core_tables = true,
        bool $check_db_name = true
    ): array {
        if (!$filesystem) {
            return [
                "valid" => false,
                "message" => esc_html__("Filesystem error.", "optistate"),
            ];
        }
        if (
            !$filesystem->exists($filepath) ||
            $filesystem->size($filepath) < 100
        ) {
            return [
                "valid" => false,
                "message" => esc_html__(
                    "Backup file missing or too small.",
                    "optistate"
                ),
            ];
        }
        $filename = basename($filepath);
        if ($skip_integrity_check) {
            return [
                "valid" => true,
                "message" => esc_html__("File accessible.", "optistate"),
            ];
        }
        $is_temp =
            strpos($filename, "restore-temp-") === 0 ||
            strpos($filename, "decompressed-") === 0;
        global $wpdb;
        $table_name = $wpdb->prefix . "optistate_backup_metadata";
        $metadata = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT file_size, created_timestamp, database_name FROM {$table_name} WHERE filename = %s",
                $filename
            ),
            ARRAY_A
        );
        if (!$is_temp && $metadata) {
            $actual_size = $filesystem->size($filepath);
            $expected_size = (int) $metadata["file_size"];
            if ($actual_size !== $expected_size) {
                return [
                    "valid" => false,
                    "message" => sprintf(
                        __(
                            'File size mismatch! Expected: %1$s, Found: %2$s (Possible corruption).',
                            "optistate"
                        ),
                        size_format($expected_size, 2),
                        size_format($actual_size, 2)
                    ),
                ];
            }
        }
        if ($metadata && isset($metadata["database_name"])) {
            $stored_db = $metadata["database_name"];
            if (!hash_equals($stored_db, DB_NAME)) {
                return [
                    "valid" => false,
                    "message" => sprintf(
                        __("Validation failed: Database name mismatch.<br>The backup was created for database '%1\$s', but the current database is '%2\$s'.", "optistate"),
                        esc_html($stored_db),
                        esc_html(DB_NAME)
                    ),
                ];
            }
        }
        if (preg_match('/\.gz$/i', $filepath)) {
            if (!function_exists("gzopen")) {
                return [
                    "valid" => false,
                    "message" => esc_html__(
                        "Gzip support is not available on this server.",
                        "optistate"
                    ),
                ];
            }
            $gz_handle = @gzopen($filepath, "rb");
            if (!$gz_handle) {
                return [
                    "valid" => false,
                    "message" => esc_html__(
                        "Cannot open gzip file.",
                        "optistate"
                    ),
                ];
            }
            $test_read = @gzread($gz_handle, 4096);
            @gzclose($gz_handle);
            if ($test_read === false) {
                return [
                    "valid" => false,
                    "message" => esc_html__(
                        "Gzip file corrupted.",
                        "optistate"
                    ),
                ];
            }
        }
        if ($check_core_tables) {
            $core_table_suffixes = ["_options", "_posts", "_users"];
            $found_tables = [];
            $is_gzipped = preg_match('/\.gz$/i', $filepath);
            $handle = null;
            try {
                $handle = $is_gzipped
                    ? @gzopen($filepath, "rb")
                    : @fopen($filepath, "rb");
                if (!$handle) {
                } else {
                    $buffer = "";
                    $current_delimiter = ";";
                    $max_statements = 5000;
                    $checked = 0;
                    while (
                        ($statement = OPTISTATE_SQL_Parser::read_statement(
                            $handle,
                            $buffer,
                            $is_gzipped,
                            $current_delimiter
                        )) !== null
                    ) {
                        $checked++;
                        if ($checked > $max_statements) {
                            break;
                        }
                        $trim_line = trim($statement);
                        if (empty($trim_line)) {
                            continue;
                        }
                        foreach ($core_table_suffixes as $suffix) {
                            if (
                                !in_array($suffix, $found_tables) &&
                                strpos($trim_line, $suffix) !== false
                            ) {
                                $found_tables[] = $suffix;
                            }
                        }
                        if (count($found_tables) >= 3) {
                            break;
                        }
                    }
                    if ($is_gzipped) {
                        @gzclose($handle);
                    } else {
                        @fclose($handle);
                    }
                }
            } catch (\Exception $e) {
            }
            if (count($found_tables) < 3) {
                $missing = array_diff($core_table_suffixes, $found_tables);
                return [
                    "valid" => false,
                    "message" => sprintf(
                        __(
                            "Validation Failed: Missing core WordPress tables: %s",
                            "optistate"
                        ),
                        implode(", ", $missing)
                    ),
                ];
            }
        }
        if ($check_db_name) {
            $is_gzipped = preg_match('/\.gz$/i', $filepath);
            $handle = null;
            try {
                $handle = $is_gzipped
                    ? @gzopen($filepath, "rb")
                    : @fopen($filepath, "rb");
                if ($handle) {
                    $header = $is_gzipped
                        ? @gzread($handle, 8192)
                        : @fread($handle, 8192);
                    if ($header !== false) {
                        $file_db_name = null;
                        if (
                            preg_match(
                                '/^(?:--|#)\s*Database:\s*[`\'"]?(.+?)[`\'"]?\s*$/m',
                                $header,
                                $matches
                            )
                        ) {
                            $file_db_name = trim($matches[1]);
                        }
                        if ($file_db_name === null) {
                            if (
                                preg_match(
                                    '/CREATE\s+DATABASE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?([^`\'";\s]+)[`\'"]?/i',
                                    $header,
                                    $matches
                                )
                            ) {
                                $file_db_name = trim($matches[1]);
                            }
                        }
                        if ($file_db_name === null) {
                            if (
                                preg_match(
                                    '/USE\s+[`\'"]?([^`\'";\s]+)[`\'"]?/i',
                                    $header,
                                    $matches
                                )
                            ) {
                                $file_db_name = trim($matches[1]);
                            }
                        }
                        if (
                            $file_db_name !== null &&
                            !hash_equals($file_db_name, DB_NAME)
                        ) {
                            if ($is_gzipped) {
                                @gzclose($handle);
                            } else {
                                @fclose($handle);
                            }
                            return [
                                "valid" => false,
                                "message" => __(
                                    "Validation failed: Database name mismatch.<br>The current database and the backup to be restored have different names!",
                                    "optistate"
                                ),
                            ];
                        }
                    }
                    if ($is_gzipped) {
                        @gzclose($handle);
                    } else {
                        @fclose($handle);
                    }
                }
            } catch (\Exception $e) {
            }
        }
        return [
            "valid" => true,
            "message" => esc_html__(
                "Backup verified successfully.",
                "optistate"
            ),
            "metadata" => $metadata ?? null,
        ];
    }

    public static function is_shell_exec_available(): bool
    {
        return !self::is_php_function_disabled("shell_exec");
    }
    public static function is_exec_available(): bool
    {
        return !self::is_php_function_disabled("exec");
    }

    public static function format_row_for_sql(
        array $row,
        $dbh = null,
        array $binary_columns = []
    ): string {
        if (!$dbh) {
            global $wpdb;
            if (isset($wpdb->dbh)) {
                $dbh = $wpdb->dbh;
            }
        }
        $row_values = [];
        $use_mysqli = $dbh instanceof mysqli;
        foreach ($row as $column_name => $value) {
            if ($value === null) {
                $row_values[] = "NULL";
            } elseif (is_int($value)) {
                $row_values[] = $value;
            } elseif (is_float($value)) {
                $row_values[] = sprintf("%.17G", $value);
            } else {
                $string_val = (string) $value;
                $is_binary = isset($binary_columns[$column_name]);
                if ($is_binary) {
                    if ($string_val === "") {
                        $row_values[] = "''";
                    } else {
                        $hex_value = bin2hex($string_val);
                        $row_values[] = "0x" . $hex_value;
                    }
                } else {
                    if ($use_mysqli) {
                        $escaped = $dbh->real_escape_string($string_val);
                    } else {
                        $escaped = addslashes($string_val);
                    }
                    $row_values[] = "'" . $escaped . "'";
                }
            }
        }
        return "(" . implode(",", $row_values) . ")";
    }

    public static function get_gzip_path()
    {
        static $gzip_path = null;
        if ($gzip_path !== null) {
            return $gzip_path;
        }
        $gzip_path = OPTISTATE_Utils::get_or_set_transient(
            "optistate_gzip_binary_path",
            function () {
                if (!self::is_shell_exec_available()) {
                    return false;
                }
                $paths = ["/bin/gzip", "/usr/bin/gzip", "/usr/local/bin/gzip"];
                $timeout_cmd = @is_executable("/usr/bin/timeout")
                    ? "/usr/bin/timeout 2 "
                    : "";
                $which_output = trim(
                    (string) @shell_exec(
                        $timeout_cmd . "which gzip 2>/dev/null"
                    )
                );
                if ($which_output !== "") {
                    $paths[] = $which_output;
                }
                foreach (array_unique($paths) as $path) {
                    if (@is_executable($path)) {
                        return $path;
                    }
                }
                return false;
            },
            MONTH_IN_SECONDS
        );
        return $gzip_path;
    }
    public static function get_statement_type(string $sql): string
    {
        $sql = ltrim($sql);
        if ($sql === '') {
            return 'EMPTY';
        }
        $upper = strtoupper(substr($sql, 0, 20));

        if (str_starts_with($upper, 'INSERT')) {
            return 'INSERT';
        }
        if (str_starts_with($upper, 'REPLACE')) {
            return 'REPLACE';
        }
        if (str_starts_with($upper, 'UPDATE')) {
            return 'UPDATE';
        }
        if (str_starts_with($upper, 'DELETE')) {
            return 'DELETE';
        }
        if (str_starts_with($upper, 'TRUNCATE')) {
            return 'TRUNCATE';
        }
        if (str_starts_with($upper, 'CREATE')) {
            return 'CREATE';
        }
        if (str_starts_with($upper, 'DROP')) {
            return 'DROP T';
        }
        if (str_starts_with($upper, 'ALTER')) {
            return 'ALTER ';
        }
        if (str_starts_with($upper, 'SET ')) {
            return 'SET ';
        }
        if (str_starts_with($upper, 'START ') || str_starts_with($upper, 'BEGIN')) {
            return 'START ';
        }
        if (str_starts_with($upper, 'COMMIT')) {
            return 'COMMIT';
        }
        if (str_starts_with($upper, 'LOCK T')) {
            return 'LOCK T';
        }
        if (str_starts_with($upper, 'UNLOCK')) {
            return 'UNLOCK';
        }
        if (str_starts_with($upper, 'DELIMITER')) {
            return 'DELIMITER';
        }
        if ($sql[0] === '/' || $sql[0] === '-' || $sql[0] === '#') {
            return 'COMMENT';
        }
        return strtoupper(substr($sql, 0, 6));
    }

    public static function validate_insert_column_list(
        string $insert_query
    ): bool {
        if (
            preg_match(
                "/INSERT INTO [^(]+\(([^)]+)\) VALUES/i",
                $insert_query,
                $matches
            )
        ) {
            $columns = array_map("trim", explode(",", $matches[1]));
            foreach ($columns as $col) {
                $col_clean = trim($col, '`"\' ');
                if (!preg_match('/^[a-zA-Z0-9_$.]+$/', $col_clean)) {
                    return false;
                }
                if (
                    preg_match(
                        "/\b(UNION|SELECT|DELETE|UPDATE|DROP|ALTER|EXEC)\b/i",
                        $col_clean
                    )
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function normalize_table_definition(
        string $create_statement,
        bool $preserve_auto_increment = true
    ): string {
        return self::normalize_create_table(
            $create_statement,
            $preserve_auto_increment,
            false
        );
    }
    public static function normalize_create_table(
        string $create_statement,
        bool $preserve_auto_inc = true,
        bool $add_row_format = false,
        string $mysql_version = ""
    ): string {
        $segments = self::split_sql_preserving_literals($create_statement);
        $normalize = function (string $sql) use ($preserve_auto_inc): string {
            if ($preserve_auto_inc) {
                $sql = preg_replace('/AUTO_INCREMENT\s*=\s*(\d+)/i', 'AUTO_INCREMENT=$1', $sql);
            } else {
                $sql = preg_replace('/AUTO_INCREMENT\s*=\s*\d+/i', '', $sql);
            }
            $sql = preg_replace('/ENGINE\s*=\s*(\w+)/i', 'ENGINE=$1', $sql);
            $sql = preg_replace('/CHARSET\s*=\s*(\w+)/i', 'CHARSET=$1', $sql);
            $sql = preg_replace('/COLLATE\s*=\s*(\w+)/i', 'COLLATE=$1', $sql);
            $sql = preg_replace('/ROW_FORMAT\s*=\s*(FIXED|COMPACT|REDUNDANT)/i', '', $sql);
            return $sql;
        };
        foreach ($segments as &$seg) {
            if ($seg["type"] === "sql") {
                $seg["value"] = $normalize($seg["value"]);
            }
        }
        unset($seg);
        $out = "";
        foreach ($segments as $seg) {
            $out .= $seg["value"];
        }

        if ($add_row_format &&
            stripos($out, "ENGINE=InnoDB") !== false &&
            stripos($out, "ROW_FORMAT") === false) {
            $out = preg_replace('/(ENGINE=InnoDB)/i', '$1 ROW_FORMAT=DYNAMIC', $out);
        }
        if ($mysql_version && version_compare($mysql_version, "8.0.0", "<")) {
            $out = str_replace("utf8mb4_0900_ai_ci", "utf8mb4_unicode_520_ci", $out);
        }
        $out = preg_replace('/\)\s*ENGINE/', ') ENGINE', $out);
        return trim($out) . ";";
    }
    private static function split_sql_preserving_literals(string $sql): array
    {
        $out = [];
        $len = strlen($sql);
        $i = 0;
        $buf_type = "sql";
        $buf = "";
        while ($i < $len) {
            $c = $sql[$i];
            if ($buf_type === "sql" && ($c === "'" || $c === '"' || $c === '`')) {
                if ($buf !== "") {
                    $out[] = ["type" => "sql", "value" => $buf];
                    $buf = "";
                }
                $quote = $c;
                $lit = $c;
                $i++;
                while ($i < $len) {
                    $ch = $sql[$i];
                    if ($ch === "\\" && $i + 1 < $len) {
                        $lit .= $ch . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $lit .= $ch . $ch;
                            $i += 2;
                            continue;
                        }
                        $lit .= $ch;
                        $i++;
                        break;
                    }
                    $lit .= $ch;
                    $i++;
                }
                $out[] = ["type" => "quoted", "value" => $lit];
                continue;
            }
            if ($buf_type === "sql" && $c === "/" && $i + 1 < $len && $sql[$i + 1] === "*") {
                if ($buf !== "") {
                    $out[] = ["type" => "sql", "value" => $buf];
                    $buf = "";
                }
                $end = strpos($sql, "*/", $i + 2);
                $piece = $end === false ? substr($sql, $i) : substr($sql, $i, $end - $i + 2);
                $out[] = ["type" => "quoted", "value" => $piece];
                $i += strlen($piece);
                continue;
            }
            $buf .= $c;
            $i++;
        }
        if ($buf !== "") {
            $out[] = ["type" => "sql", "value" => $buf];
        }
        return $out;
    }

    public static function get_adaptive_worker_config(): array
    {
        static $config_cache = null;
        if ($config_cache !== null) {
            return $config_cache;
        }
        $memory_limit = wp_convert_hr_to_bytes(ini_get("memory_limit"));
        $max_execution_time = (int) ini_get("max_execution_time");
        if ($max_execution_time <= 0) {
            $max_execution_time = 60;
        }
        $cpu_cores = 1;
        if (self::is_shell_exec_available()) {
            if (PHP_OS_FAMILY === "Windows") {
                $cpu_info = @shell_exec(
                    "wmic cpu get NumberOfLogicalProcessors 2>NUL"
                );
                if (
                    $cpu_info &&
                    preg_match_all("/\d+/", (string) $cpu_info, $matches) &&
                    !empty($matches[0])
                ) {
                    $cpu_cores = max(1, (int) $matches[0][0]);
                }
            } else {
                $cpu_info = @shell_exec("nproc 2>/dev/null");
                if (
                    $cpu_info !== null &&
                    $cpu_info !== false &&
                    is_numeric(trim((string) $cpu_info))
                ) {
                    $cpu_cores = max(1, (int) trim((string) $cpu_info));
                }
            }
        }
        $memory_score = min(100, ($memory_limit / (512 * 1024 * 1024)) * 50);
        $time_score = min(100, ($max_execution_time / 60) * 30);
        $cpu_score = min(100, ($cpu_cores / 4) * 20);
        $performance_score = $memory_score + $time_score + $cpu_score;
        if ($performance_score >= 70) {
            $config = [
                "chunks_per_run" => 5,
                "reschedule_delay" => 2,
                "max_worker_time" => min(
                    40,
                    (int) ($max_execution_time * 0.85)
                ),
            ];
        } elseif ($performance_score >= 40) {
            $config = [
                "chunks_per_run" => 3,
                "reschedule_delay" => 3,
                "max_worker_time" => min(25, (int) ($max_execution_time * 0.8)),
            ];
        } else {
            $config = [
                "chunks_per_run" => 2,
                "reschedule_delay" => 4,
                "max_worker_time" => min(
                    15,
                    (int) ($max_execution_time * 0.75)
                ),
            ];
        }
        $config["chunks_per_run"] = max(1, $config["chunks_per_run"]);
        $config["reschedule_delay"] = max(1, $config["reschedule_delay"]);
        $config["max_worker_time"] = max(10, $config["max_worker_time"]);
        $config_cache = $config;
        return $config;
    }

    public static function is_internal_backup(
        $filesystem,
        string $filepath
    ): bool {
        if (!$filesystem || !$filesystem->exists($filepath)) {
            return false;
        }
        $is_gzipped = preg_match('/\.gz$/i', $filepath);
        $line = false;
        try {
            if ($is_gzipped) {
                if (function_exists("gzopen")) {
                    $handle = @gzopen($filepath, "rb");
                    if ($handle) {
                        $line = @gzgets($handle, 1024);
                        @gzclose($handle);
                    }
                }
            } else {
                if (function_exists("fopen")) {
                    $handle = @fopen($filepath, "rb");
                    if ($handle) {
                        $line = @fgets($handle, 1024);
                        @fclose($handle);
                    }
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        if ($line === false || $line === null) {
            return false;
        }
        return strpos($line, "-- Created by WP Optimal State Plugin") !== false;
    }

    public static function scan_sql_for_php_threats(string $sample): bool
    {
        return (bool) preg_match(
            '/<\?php|<\?=|<\s*\?|script\s*language\s*=\s*["\']?php["\']?|eval\s*\(|exec\s*\(|system\s*\(|passthru\s*\(|shell_exec\s*\(|base64_decode/i',
            $sample
        );
    }

    public static function cleanup_failed_upload(
        ?object $wp_filesystem,
        OPTISTATE_Process_Store $process_store,
        string $path,
        string $session_key
    ): bool {
        $upload_dir      = wp_upload_dir();
        $temp_dir        = trailingslashit($upload_dir["basedir"]) . OPTISTATE::TEMP_DIR_NAME . "/";
        $normalized_path = wp_normalize_path($path);
        $normalized_temp = wp_normalize_path($temp_dir);

        if (strpos($normalized_path, $normalized_temp) !== 0) {
            $process_store->delete($session_key);
            return false;
        }

        if (strpos($normalized_path, "..") !== false) {
            $process_store->delete($session_key);
            return false;
        }

        if ($wp_filesystem && $wp_filesystem->exists($normalized_path)) {
            $deleted = $wp_filesystem->delete($normalized_path);
            if (!$deleted) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to delete failed upload file",
                    ["path" => $normalized_path]
                );
            }
        }

        $process_store->delete($session_key);
        return true;
    }

    public static function protect_temp_directory(
        OPTISTATE $main_plugin,
        string $temp_dir
    ): void {
        $rules = [
            "# WP Optimal State - Secure Temp Restore Directory",
            "Options -Indexes",
            "<IfModule mod_authz_core.c>",
            " Require all denied",
            "</IfModule>",
            "<IfModule !mod_authz_core.c>",
            " Order deny,allow",
            " Deny from all",
            "</IfModule>",
        ];
        $main_plugin->ensure_directory($temp_dir, 0750, $rules);
    }
}