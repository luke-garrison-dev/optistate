<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Backup_Engine
{
    private OPTISTATE $main_plugin;
    private string $backup_dir;
    private OPTISTATE_Process_Store $process_store;
    private ?object $wp_filesystem;
    private array $row_length_cache = [];
    private const TARGET_BATCH_SIZE = 8 * 1024 * 1024;
    private const MIN_BATCH_LIMIT = 25;
    private const MAX_BATCH_LIMIT = 50000;
    private const MAX_OFFSET_BATCH_LIMIT = 5000;
    private const OFFSET_REDUCTION_FACTOR = 0.65;
    private const CACHE_TTL = 600;
    private const MAX_ROW_LENGTH_CACHE_SIZE = 400;
    private static string $binary_pattern = "/\b(blob|binary|varbinary|tinyblob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/i";
    public function __construct(
        OPTISTATE $main_plugin,
        string $backup_dir,
        OPTISTATE_Process_Store $process_store,
        ?object $wp_filesystem
    ) {
        $this->main_plugin = $main_plugin;
        $this->backup_dir = $backup_dir;
        $this->process_store = $process_store;
        if (!$wp_filesystem) {
            $wp_filesystem = $this->main_plugin->get_filesystem();
        }
        $this->wp_filesystem = $wp_filesystem;
    }
    public function initiate_chunked_backup(
        string $filename,
        array $extra_data = []
    ): string {
        try {
            global $wpdb;
            if (!function_exists("gzopen")) {
                throw new Exception(
                    __(
                        "The PHP zlib extension is required for backups. Please enable it.",
                        "optistate"
                    )
                );
            }
            if (!preg_match('/\.sql\.gz$/i', $filename)) {
                $filename =
                    preg_replace('/\.sql$/i', "", $filename) . ".sql.gz";
            }
            $filename =
                preg_replace('/(\.sql)?(\.gz)?$/i', "", $filename) . ".sql.gz";
            try {
                $random_suffix = bin2hex(random_bytes(7));
            } catch (\Throwable $e) {
                $random_suffix = md5(
                    uniqid((string) wp_rand(), true) . microtime()
                );
            }
            $filename =
                preg_replace('/\.sql\.gz$/i', "", $filename) .
                "_" .
                $random_suffix .
                ".sql.gz";
            $filepath = $this->backup_dir . $filename;
            if ($this->wp_filesystem->exists($filepath)) {
                throw new Exception(
                    __(
                        "Backup file collision detected. Please try again.",
                        "optistate"
                    )
                );
            }
            $space_check = OPTISTATE_Backup_Utilities::check_sufficient_disk_space(
                $this->wp_filesystem,
                null,
                $this->main_plugin->get_total_database_size(false)
            );
            if (!$space_check["success"]) {
                throw new Exception($space_check["message"]);
            }
            $marker_created = @file_put_contents(
                $filepath . ".lock",
                getmypid(),
                LOCK_EX
            );
            if ($marker_created === false) {
                throw new Exception(
                    __("Failed to create backup lock file.", "optistate")
                );
            }
            $tables_result = $wpdb->get_results("SHOW FULL TABLES", ARRAY_N);
            if (empty($tables_result)) {
                throw new Exception(
                    __("No database tables found.", "optistate")
                );
            }
            $base_tables = [];
            $view_tables = [];
            $table_types = [];
            $excluded_tables = OPTISTATE_Utils::get_all_excluded_tables();
            foreach ($tables_result as $row) {
                $table_name = $row[0];
                $table_type = $row[1] ?? "BASE TABLE";
                if (!in_array($table_name, $excluded_tables, true)) {
                    $table_types[$table_name] = $table_type;
                    if (strtoupper($table_type) === "VIEW") {
                        $view_tables[] = $table_name;
                    } else {
                        $base_tables[] = $table_name;
                    }
                }
            }
            $all_tables = array_merge($base_tables, $view_tables);
            try {
                $random_key = bin2hex(random_bytes(14));
            } catch (\Throwable $e) {
                $random_key = md5(
                    uniqid((string) wp_rand(), true) . microtime()
                );
            }
            $transient_key = "optistate_backup_" . $random_key;
            $state = [
                "filepath" => $filepath,
                "filename" => $filename,
                "uncompressed_size" => 0,
                "all_tables" => array_values($all_tables),
                "table_types" => $table_types,
                "total_tables" => count($all_tables),
                "current_table_index" => 0,
                "current_table_data_offset" => 0,
                "primary_key" => null,
                "primary_key_type" => "numeric",
                "status" => "init",
                "start_time" => time(),
                "checksum" => "",
                "user_id" => get_current_user_id(),
                "backup_has_transactions" => true,
                "state_version" => 0,
                "active_worker" => null,
                "worker_ping" => 0,
                "tables_list" => $all_tables,
            ];
            if (!empty($extra_data) && is_array($state)) {
                $state = array_merge($state, $extra_data);
            }
            $this->process_store->set($transient_key, $state, DAY_IN_SECONDS);
            return $transient_key;
        } catch (Throwable $e) {
            if (
                isset($filepath) &&
                $this->wp_filesystem &&
                $this->wp_filesystem->exists($filepath . ".lock")
            ) {
                $this->wp_filesystem->delete($filepath . ".lock");
            }
            OPTISTATE_Utils::log_critical_error(
                "Initiate chunked backup failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            throw new Exception(
                esc_html__("Failed to initiate backup: ", "optistate") .
                    $e->getMessage(),
                0,
                $e
            );
        }
    }
    public function process_chunk(
        string $transient_key,
        bool $is_silent_worker = false
    ): array {
        wp_raise_memory_limit("admin");
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(240);
        $worker_id = uniqid("worker_", true);
        $handle_reference = null;
        $cleanup_function = function () use (&$handle_reference) {
            if ($handle_reference !== null && is_resource($handle_reference)) {
                @gzclose($handle_reference);
            }
        };
        register_shutdown_function($cleanup_function);
        try {
            if (
                empty($transient_key) ||
                strpos($transient_key, "optistate_backup_") !== 0
            ) {
                throw new Exception(
                    __("Invalid backup session key.", "optistate")
                );
            }
            if (connection_aborted()) {
                throw new Exception(
                    __("Connection aborted during backup.", "optistate")
                );
            }
            $state = $this->process_store->atomic_update(
                $transient_key,
                function ($current_state) use ($worker_id) {
                    if ($current_state === false || $current_state === null) {
                        return false;
                    }
                    if (
                        !empty($current_state["active_worker"]) &&
                        $current_state["active_worker"] !== $worker_id
                    ) {
                        if (
                            isset($current_state["worker_ping"]) &&
                            time() - $current_state["worker_ping"] < 45
                        ) {
                            return false;
                        }
                    }
                    $current_state["active_worker"] = $worker_id;
                    $current_state["worker_ping"] = time();
                    return $current_state;
                }
            );
            if ($state === false) {
                $current_state_check = $this->process_store->get(
                    $transient_key
                );
                if ($current_state_check === false) {
                    throw new Exception(
                        __("Backup session expired or not found.", "optistate")
                    );
                }
                return ["status" => "skipped", "state" => $current_state_check];
            }
            $memory_limit = wp_convert_hr_to_bytes(
                ini_get("memory_limit") ?: "128M"
            );
            if (
                $memory_limit > 0 &&
                memory_get_usage(true) > $memory_limit * 0.75
            ) {
                $this->optimize_memory();
            }
            if (!$is_silent_worker && empty($state["is_manual"])) {
                $state["active_worker"] = null;
                $this->process_store->set(
                    $transient_key,
                    $state,
                    DAY_IN_SECONDS
                );
                return ["status" => "skipped", "state" => $state];
            }
            global $wpdb;
            if (!$wpdb->check_connection(false)) {
                throw new Exception(
                    __(
                        "Database connection lost before backup started.",
                        "optistate"
                    )
                );
            }
            $config = OPTISTATE_Backup_Utilities::get_adaptive_worker_config();
            $max_chunk_time = $config["max_worker_time"];
            $result = $this->perform_backup_chunk(
                $state,
                $max_chunk_time,
                $handle_reference,
                $transient_key
            );
            $result["state"]["active_worker"] = null;
            if ($result["status"] === "done") {
                $done_state = $result["state"];
                $lock_path = $done_state["filepath"] . ".lock";
                if (file_exists($lock_path)) {
                    @unlink($lock_path);
                }
                return ["status" => "done", "state" => $done_state];
            } else {
                return [
                    "status" => "running",
                    "state" => $result["state"],
                    "reschedule_delay" => $config["reschedule_delay"],
                ];
            }
        } catch (Throwable $e) {
            $this->handle_backup_worker_error(
                $transient_key,
                $state ?? [],
                $e,
                $is_silent_worker
            );
            return ["status" => "error", "message" => $e->getMessage()];
        } finally {
            if ($handle_reference !== null && is_resource($handle_reference)) {
                @gzclose($handle_reference);
                $handle_reference = null;
            }
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
        }
    }
    private function perform_backup_chunk(
        array $state,
        int $max_chunk_time,
        &$handle_reference,
        string $transient_key
    ): array {
        global $wpdb;
        $handle = null;
        $chunk_start_time = time();
        $original_time_limit = (int) ini_get("max_execution_time");
        $needed_time = $max_chunk_time + 60;
        OPTISTATE_Utils::safe_set_time_limit($needed_time);
        $filepath = $state["filepath"];
        try {
            $gz_mode = $state["status"] === "init" ? "wb6" : "ab6";
            if (
                $state["status"] !== "init" &&
                !$this->wp_filesystem->exists($filepath)
            ) {
                throw new Exception(
                    __(
                        "Backup file missing before continuing chunked write.",
                        "optistate"
                    )
                );
            }
            $handle = @gzopen($filepath, $gz_mode);
            if (!$handle) {
                throw new Exception(
                    __("Failed to open backup file for writing.", "optistate")
                );
            }
            $handle_reference = $handle;
            if (!isset($state["uncompressed_size"])) {
                $state["uncompressed_size"] = 0;
            }
            while (time() - $chunk_start_time < $max_chunk_time) {
                switch ($state["status"]) {
                    case "init":
                        if ($this->wp_filesystem->exists($filepath)) {
                            $this->wp_filesystem->chmod($filepath, 0600);
                        }
                        $header = $this->generate_phpmyadmin_header();
                        $header_len = strlen($header);
                        if (gzwrite($handle, $header) !== $header_len) {
                            throw new Exception(
                                __(
                                    "Failed to write backup header.",
                                    "optistate"
                                )
                            );
                        }
                        $state["uncompressed_size"] += $header_len;
                        $trans_start = "START TRANSACTION;\n\n";
                        $trans_len = strlen($trans_start);
                        if (gzwrite($handle, $trans_start) !== $trans_len) {
                            throw new Exception(
                                __(
                                    "Failed to write transaction start.",
                                    "optistate"
                                )
                            );
                        }
                        $state["uncompressed_size"] += $trans_len;
                        $state["status"] = "tables";
                        break;
                    case "tables":
                        if (
                            $state["current_table_index"] >=
                            $state["total_tables"]
                        ) {
                            $state["status"] = "footer";
                            break;
                        }
                        $table_name =
                            $state["all_tables"][$state["current_table_index"]];
                        $table_type =
                            $state["table_types"][$table_name] ?? "BASE TABLE";
                        $escaped_table = OPTISTATE_Utils::escape_identifier(
                            $table_name
                        );
                        $is_view = strtoupper($table_type) === "VIEW";
                        $table_structure =
                            "-- --------------------------------------------------------\n\n--\n-- Structure for " .
                            ($is_view ? "view" : "table") .
                            " `{$table_name}`\n--\n\n";
                        $table_structure .=
                            "DROP " .
                            ($is_view ? "VIEW" : "TABLE") .
                            " IF EXISTS {$escaped_table};\n";
                        $create_query = $is_view
                            ? "SHOW CREATE VIEW {$escaped_table}"
                            : "SHOW CREATE TABLE {$escaped_table}";
                        $create_table = $wpdb->get_row($create_query, ARRAY_N);
                        if ($create_table && isset($create_table[1])) {
                            $create_statement = $create_table[1];
                            $create_statement = OPTISTATE_Backup_Utilities::normalize_table_definition(
                                $create_statement,
                                true
                            );
                            if (!$is_view) {
                                $table_status = $wpdb->get_row(
                                    $wpdb->prepare(
                                        "SHOW TABLE STATUS LIKE %s",
                                        $table_name
                                    ),
                                    ARRAY_A
                                );
                                if (
                                    $table_status &&
                                    isset($table_status["Auto_increment"]) &&
                                    $table_status["Auto_increment"] > 0
                                ) {
                                    $auto_inc_value =
                                        $table_status["Auto_increment"];
                                    if (
                                        strpos(
                                            $create_statement,
                                            "AUTO_INCREMENT="
                                        ) !== false
                                    ) {
                                        $create_statement = preg_replace(
                                            "/AUTO_INCREMENT=\d+/",
                                            "AUTO_INCREMENT=" . $auto_inc_value,
                                            $create_statement
                                        );
                                    } elseif (
                                        preg_match(
                                            "/(ENGINE=\w+)/i",
                                            $create_statement,
                                            $matches
                                        )
                                    ) {
                                        $create_statement = str_replace(
                                            $matches[1],
                                            "AUTO_INCREMENT=" .
                                                $auto_inc_value .
                                                " " .
                                                $matches[1],
                                            $create_statement
                                        );
                                    }
                                }
                            }
                            $table_structure .= $create_statement . ";\n\n";
                        } else {
                            throw new Exception(
                                sprintf(
                                    __(
                                        "Failed to get structure for table: %s",
                                        "optistate"
                                    ),
                                    $table_name
                                )
                            );
                        }
                        $struct_len = strlen($table_structure);
                        if (
                            gzwrite($handle, $table_structure) !== $struct_len
                        ) {
                            throw new Exception(
                                sprintf(
                                    __(
                                        "Failed to write structure for table: %s",
                                        "optistate"
                                    ),
                                    $table_name
                                )
                            );
                        }
                        $state["uncompressed_size"] += $struct_len;
                        if ($is_view) {
                            $state["current_table_index"]++;
                            $state["current_table_data_offset"] = 0;
                            $state["primary_key"] = null;
                            $state["status"] = "tables";
                            break;
                        }
                        $columns = $wpdb->get_results(
                            "SHOW COLUMNS FROM {$escaped_table}",
                            ARRAY_A
                        );
                        $column_names = [];
                        $column_types = [];
                        foreach ($columns as $column) {
                            if (
                                strpos($column["Extra"], "GENERATED") !== false
                            ) {
                                continue;
                            }
                            $column_names[] =
                                "`" .
                                str_replace("`", "``", $column["Field"]) .
                                "`";
                            $column_types[$column["Field"]] = $column["Type"];
                        }
                        $state["table_column_list"] = implode(
                            ", ",
                            $column_names
                        );
                        $state["table_column_types"] = $column_types;
                        $primary_key_columns = [];
                        $pk_column_types = [];
                        foreach ($columns as $column) {
                            if ($column["Key"] === "PRI") {
                                $field_name = $column["Field"];
                                $field_type = $column["Type"] ?? "";
                                $primary_key_columns[] = $field_name;
                                $pk_column_types[$field_name] = $field_type;
                            }
                        }
                        $can_use_keyset_pagination = false;
                        $selected_primary_key = null;
                        $fallback_reason = "";
                        if (count($primary_key_columns) === 1) {
                            $pk_candidate = $primary_key_columns[0];
                            $pk_type = $pk_column_types[$pk_candidate];
                            $numeric_types =
                                "/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)/i";
                            if (preg_match($numeric_types, $pk_type)) {
                                $can_use_keyset_pagination = true;
                                $selected_primary_key = $pk_candidate;
                                $state["primary_key_type"] = "numeric";
                            } else {
                                $fallback_reason = "Non-numeric primary key ($pk_candidate $pk_type) – will try unique index";
                            }
                        }
                        if (!$can_use_keyset_pagination) {
                            $unique_keys = $wpdb->get_results(
                                "SHOW INDEX FROM {$escaped_table} WHERE Non_unique = 0 AND Seq_in_index = 1"
                            );
                            foreach ($unique_keys as $key) {
                                $col_name = $key->Column_name;
                                $col_type = $wpdb->get_var(
                                    $wpdb->prepare(
                                        "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                                        $table_name,
                                        $col_name
                                    )
                                );
                                if ($col_type) {
                                    $can_use_keyset_pagination = true;
                                    $selected_primary_key = $col_name;
                                    $state["primary_key_type"] = in_array(
                                        strtolower($col_type),
                                        [
                                            "tinyint",
                                            "smallint",
                                            "mediumint",
                                            "int",
                                            "bigint",
                                            "decimal",
                                            "numeric",
                                            "float",
                                            "double",
                                            "real",
                                        ]
                                    )
                                        ? "numeric"
                                        : "string";
                                    $fallback_reason =
                                        "Using unique index $col_name (" .
                                        $state["primary_key_type"] .
                                        ")";
                                    break;
                                }
                            }
                        }
                        if ($can_use_keyset_pagination) {
                            $state["primary_key"] = $selected_primary_key;
                            $state["pagination_method"] = "keyset";
                        } else {
                            $state["primary_key"] = null;
                            $state["pagination_method"] = "offset";
                            $fallback_reason =
                                $fallback_reason ?:
                                "No unique index found; using OFFSET";
                        }
                        $state["primary_key_metadata"] = [
                            "detected_keys" => $primary_key_columns,
                            "key_types" => $pk_column_types,
                            "pagination_method" => $state["pagination_method"],
                            "can_optimize" => $can_use_keyset_pagination,
                            "fallback_reason" => $fallback_reason,
                            "detection_timestamp" => time(),
                        ];
                        if (
                            isset($state["primary_key_type"]) &&
                            $state["primary_key_type"] === "string"
                        ) {
                            $state["current_table_data_offset"] = null;
                        } else {
                            $state["current_table_data_offset"] = 0;
                        }
                        $data_header = "--\n-- Dumping data for table `{$table_name}`\n--\n\n";
                        $data_header_len = strlen($data_header);
                        if (
                            gzwrite($handle, $data_header) !== $data_header_len
                        ) {
                            throw new Exception(
                                __("Failed to write data header.", "optistate")
                            );
                        }
                        $state["uncompressed_size"] += $data_header_len;
                        $state["status"] = "data";
                        break;
                    case "data":
                        $table_name =
                            $state["all_tables"][$state["current_table_index"]];
                        $column_list = isset($state["table_column_list"])
                            ? $state["table_column_list"]
                            : "";
                        $column_types = isset($state["table_column_types"])
                            ? $state["table_column_types"]
                            : [];
                        $primary_key_type = isset($state["primary_key_type"])
                            ? $state["primary_key_type"]
                            : "numeric";
                        $result = $this->backup_table_data_chunked(
                            $table_name,
                            $state["primary_key"],
                            $state["current_table_data_offset"],
                            $handle,
                            $chunk_start_time,
                            $max_chunk_time,
                            $column_list,
                            $column_types,
                            $transient_key,
                            $primary_key_type,
                            $state
                        );
                        if ($result["status"] === "done") {
                            if (gzwrite($handle, "\n") !== 1) {
                                throw new Exception(
                                    __(
                                        "Failed to write table data newline.",
                                        "optistate"
                                    )
                                );
                            }
                            $state["uncompressed_size"] += 1;
                            $state["current_table_index"]++;
                            $state["current_table_data_offset"] = 0;
                            $state["primary_key"] = null;
                            $state["status"] = "tables";
                            unset(
                                $state["table_column_list"],
                                $state["table_column_types"],
                                $state["primary_key_metadata"],
                                $state["pagination_method"]
                            );
                        } else {
                            $state["current_table_data_offset"] =
                                $result["offset"];
                        }
                        break;
                    case "footer":
                        $footer =
                            "COMMIT;\n\n/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
                        $footer_len = strlen($footer);
                        if (gzwrite($handle, $footer) !== $footer_len) {
                            throw new Exception(
                                __(
                                    "Failed to write backup footer.",
                                    "optistate"
                                )
                            );
                        }
                        $state["uncompressed_size"] += $footer_len;
                        $state["status"] = "done";
                        break 2;
                }
            }
            if ($handle !== null && is_resource($handle)) {
                @gzclose($handle);
                $handle = null;
                $handle_reference = null;
            }
            $this->wp_filesystem->chmod($filepath, 0600);
            $status = $state["status"] === "done" ? "done" : "running";
            return ["status" => $status, "state" => $state];
        } catch (Throwable $e) {
            if ($handle !== null && is_resource($handle)) {
                @gzclose($handle);
                $handle_reference = null;
            }
            OPTISTATE_Utils::log_critical_error(
                "Backup chunk failed: " . $e->getMessage(),
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "user_id" => $state["user_id"] ?? null,
                    "backup_file" => $state["filename"] ?? null,
                ]
            );
            throw $e;
        } finally {
            if ($handle !== null && is_resource($handle)) {
                @gzclose($handle);
                $handle_reference = null;
            }
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
        }
    }
    private function backup_table_data_chunked(
        string $table_name,
        $primary_key,
        $offset,
        $file_handle,
        int $start_time,
        int $max_duration,
        string $column_list,
        array $column_types,
        string $transient_key,
        string $primary_key_type = "numeric",
        array &$state = []
    ): array {
        global $wpdb;
        static $memory_limit_bytes = null,
            $unsafe_memory_threshold = null;
        if ($memory_limit_bytes === null) {
            $memory_limit_str = ini_get("memory_limit");
            $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit_str);
            $unsafe_memory_threshold =
                $memory_limit_bytes > 0
                    ? $memory_limit_bytes * 0.8
                    : 256 * 1024 * 1024;
        }
        $binary_columns = [];
        $binary_pattern = self::$binary_pattern;
        foreach ($column_types as $col_name => $type) {
            if (preg_match($binary_pattern, strtolower($type))) {
                $binary_columns[$col_name] = true;
            }
        }
        $is_offset_method = empty($primary_key);
        $batch_size = $this->get_adaptive_batch_limit(
            $table_name,
            $is_offset_method
        );
        $check_frequency = min(100, max(1, (int) ($batch_size / 10)));
        $check_counter = 0;
        $safe_table = OPTISTATE_Utils::escape_identifier($table_name);
        $offset_batch_size = min($batch_size * 3, 1500);
        if ($primary_key) {
            if ($primary_key_type === "numeric") {
                $is_first_batch = $offset === 0;
            } else {
                $is_first_batch = $offset === null || $offset === "";
            }
        } else {
            $is_first_batch = $offset === 0;
        }
        $exclude_trash_condition = "";
        $exclude_trash_value = null;
        if ($table_name === $wpdb->options) {
            $exclude_trash_condition = " AND `option_name` NOT LIKE %s";
            $exclude_trash_value = $wpdb->esc_like("_optistate_trash_") . "%";
        }
        if ($primary_key) {
            $safe_primary_key =
                "`" . str_replace("`", "``", $primary_key) . "`";
            if ($is_first_batch) {
                $query = $wpdb->prepare(
                    "SELECT " .
                        $column_list .
                        " FROM {$safe_table} WHERE 1=1 {$exclude_trash_condition} ORDER BY {$safe_primary_key} ASC LIMIT %d",
                    ...$exclude_trash_value
                        ? [$exclude_trash_value, $batch_size]
                        : [$batch_size]
                );
            } else {
                if ($primary_key_type === "numeric") {
                    $query = $wpdb->prepare(
                        "SELECT " .
                            $column_list .
                            " FROM {$safe_table} WHERE {$safe_primary_key} > %d {$exclude_trash_condition} ORDER BY {$safe_primary_key} ASC LIMIT %d",
                        ...$exclude_trash_value
                            ? [$offset, $exclude_trash_value, $batch_size]
                            : [$offset, $batch_size]
                    );
                } else {
                    $query = $wpdb->prepare(
                        "SELECT " .
                            $column_list .
                            " FROM {$safe_table} WHERE {$safe_primary_key} > %s {$exclude_trash_condition} ORDER BY {$safe_primary_key} ASC LIMIT %d",
                        ...$exclude_trash_value
                            ? [$offset, $exclude_trash_value, $batch_size]
                            : [$offset, $batch_size]
                    );
                }
            }
        } else {
            $query = $wpdb->prepare(
                "SELECT " .
                    $column_list .
                    " FROM {$safe_table} WHERE 1=1 {$exclude_trash_condition} LIMIT %d OFFSET %d",
                ...$exclude_trash_value
                    ? [$exclude_trash_value, $offset_batch_size, $offset]
                    : [$offset_batch_size, $offset]
            );
        }
        $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
        static $session_initialized = false;
        if (!$session_initialized) {
            $db->query("SET SESSION net_read_timeout = 120");
            $db->query("SET SESSION wait_timeout = 600");
            $db->query("SET SESSION SQL_BIG_SELECTS=1");
            $db->query(
                "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ"
            );
            $session_initialized = true;
        }
        $db->query("START TRANSACTION WITH CONSISTENT SNAPSHOT");
        $result = $db->query($query);
        if (!$result) {
            $db_error = $db->error;
            $db->query("ROLLBACK");
            throw new Exception(
                sprintf(
                    __("Failed to read data from table '%s': %s", "optistate"),
                    $table_name,
                    $db_error
                )
            );
        }
        $row_count = 0;
        $total_row_count = 0;
        $insert_header = !empty($column_list)
            ? "INSERT INTO {$safe_table} ({$column_list}) VALUES "
            : "INSERT INTO {$safe_table} VALUES ";
        $row_buffer = [];
        $buffer_size = 0;
        $target_bytes = self::TARGET_BATCH_SIZE;
        $max_rows_per_flush = 5000;
        $flush_count = 0;
        $last_pk_value = $offset;
        if (!is_numeric($last_pk_value) && $last_pk_value !== null) {
        } else {
            if ($last_pk_value === null) {
                $last_pk_value = 0;
            }
        }
        try {
            $process_row = function ($row) use (
                &$row_buffer,
                &$buffer_size,
                &$row_count,
                &$total_row_count,
                &$flush_count,
                $primary_key,
                $insert_header,
                $file_handle,
                $target_bytes,
                $max_rows_per_flush,
                &$last_pk_value,
                $offset,
                $unsafe_memory_threshold,
                $db,
                $binary_columns,
                $transient_key,
                $primary_key_type,
                &$state
            ) {
                $row_count++;
                $total_row_count++;
                if ($primary_key) {
                    $last_pk_value = $row[$primary_key];
                } else {
                    $last_pk_value = $offset + $total_row_count;
                }
                $row_string = OPTISTATE_Backup_Utilities::format_row_for_sql(
                    $row,
                    $db,
                    $binary_columns
                );
                $row_size = strlen($row_string);
                $row_buffer[] = $row_string;
                $buffer_size += $row_size;
                if (
                    $buffer_size >= $target_bytes ||
                    $row_count >= $max_rows_per_flush
                ) {
                    if (!empty($state) && isset($state["uncompressed_size"])) {
                        $header_len = strlen($insert_header);
                        $rows_data = implode(",", $row_buffer) . ";\n";
                        $rows_data_len = strlen($rows_data);
                        $state["uncompressed_size"] +=
                            $header_len + $rows_data_len;
                    }
                    self::flush_buffer(
                        $row_buffer,
                        $insert_header,
                        $file_handle
                    );
                    $row_buffer = [];
                    $buffer_size = 0;
                    $row_count = 0;
                    $flush_count++;
                    if ($flush_count % 5 === 0) {
                        $this->process_store->touch(
                            $transient_key,
                            DAY_IN_SECONDS
                        );
                    }
                    if (
                        memory_get_usage(true) >
                        $unsafe_memory_threshold * 0.85
                    ) {
                        $this->optimize_memory();
                        if (function_exists("gc_collect_cycles")) {
                            gc_collect_cycles();
                        }
                    }
                }
            };
            while ($row = mysqli_fetch_assoc($result)) {
                $process_row($row);
                if (++$check_counter >= $check_frequency) {
                    $check_counter = 0;
                    if (time() - $start_time >= $max_duration) {
                        if (!empty($row_buffer)) {
                            if (
                                !empty($state) &&
                                isset($state["uncompressed_size"])
                            ) {
                                $header_len = strlen($insert_header);
                                $rows_data = implode(",", $row_buffer) . ";\n";
                                $rows_data_len = strlen($rows_data);
                                $state["uncompressed_size"] +=
                                    $header_len + $rows_data_len;
                            }
                            self::flush_buffer(
                                $row_buffer,
                                $insert_header,
                                $file_handle
                            );
                        }
                        mysqli_free_result($result);
                        $db->query("COMMIT");
                        return [
                            "status" => "running",
                            "offset" => $last_pk_value,
                        ];
                    }
                }
            }
            mysqli_free_result($result);
            if (!empty($row_buffer)) {
                if (!empty($state) && isset($state["uncompressed_size"])) {
                    $header_len = strlen($insert_header);
                    $rows_data = implode(",", $row_buffer) . ";\n";
                    $rows_data_len = strlen($rows_data);
                    $state["uncompressed_size"] += $header_len + $rows_data_len;
                }
                self::flush_buffer($row_buffer, $insert_header, $file_handle);
            }
            $db->query("COMMIT");
            $limit_check = $is_offset_method ? $offset_batch_size : $batch_size;
            $has_more = $total_row_count >= $limit_check;
            return [
                "status" => $has_more ? "running" : "done",
                "offset" => $last_pk_value,
            ];
        } catch (Throwable $e) {
            if ($result instanceof mysqli_result) {
                mysqli_free_result($result);
            }
            $db->query("ROLLBACK");
            throw $e;
        }
    }
    private static function flush_buffer(
        array &$buffer,
        string $header,
        $file_handle
    ): void {
        if (empty($buffer)) {
            return;
        }
        if (gzwrite($file_handle, $header) !== strlen($header)) {
            throw new Exception(
                __("Failed to write backup header to disk.", "optistate")
            );
        }
        $rows = implode(",", $buffer);
        $rows_data = $rows . ";\n";
        if (gzwrite($file_handle, $rows_data) !== strlen($rows_data)) {
            throw new Exception(
                __("Failed to write row data to disk.", "optistate")
            );
        }
        $buffer = [];
    }
    private function get_adaptive_batch_limit(
        string $table_name,
        bool $is_offset_method = false
    ): int {
        global $wpdb;
        static $max_allowed_packet_cache = null;
        if ($max_allowed_packet_cache === null) {
            $max_allowed_packet_cache = (int) $wpdb->get_var(
                "SELECT @@max_allowed_packet"
            );
        }
        $max_allowed = $max_allowed_packet_cache;
        if (isset($this->row_length_cache[$table_name])) {
            $avg_row_length = $this->row_length_cache[$table_name];
        } else {
            $cache_key = "optistate_rowlen_all_" . md5(DB_NAME);
            $cached_lengths = OPTISTATE_Utils::get_or_set_transient(
                $cache_key,
                function () use ($wpdb) {
                    $lengths = [];
                    $results = $wpdb->get_results(
                        "SELECT TABLE_NAME, AVG_ROW_LENGTH FROM information_schema.tables WHERE table_schema = DATABASE()",
                        ARRAY_A
                    );
                    if ($results) {
                        foreach ($results as $row) {
                            $lengths[$row["TABLE_NAME"]] =
                                !empty($row["AVG_ROW_LENGTH"]) &&
                                $row["AVG_ROW_LENGTH"] > 0
                                    ? (int) $row["AVG_ROW_LENGTH"]
                                    : 1024;
                        }
                    }
                    return $lengths;
                },
                self::CACHE_TTL
            );
            $this->row_length_cache = array_merge(
                $this->row_length_cache,
                $cached_lengths
            );
            if (
                !isset($this->row_length_cache[$table_name]) ||
                $this->row_length_cache[$table_name] === 1024
            ) {
                $avg = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT AVG_ROW_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                        $table_name
                    )
                );
                $this->row_length_cache[$table_name] =
                    $avg !== null && $avg > 0 ? (int) $avg : 1024;
            }
            if (
                count($this->row_length_cache) > self::MAX_ROW_LENGTH_CACHE_SIZE
            ) {
                $this->row_length_cache = array_slice(
                    $this->row_length_cache,
                    -self::MAX_ROW_LENGTH_CACHE_SIZE,
                    self::MAX_ROW_LENGTH_CACHE_SIZE,
                    true
                );
            }
            $avg_row_length = $this->row_length_cache[$table_name];
        }
        $avg_row_length = max(1, $avg_row_length);
        $limit = (int) floor(self::TARGET_BATCH_SIZE / $avg_row_length);
        if ($max_allowed > 0) {
            $packet_limit = (int) (($max_allowed * 0.9) / $avg_row_length);
            $limit = min($limit, max(self::MIN_BATCH_LIMIT, $packet_limit));
        }
        $memory_limit = wp_convert_hr_to_bytes(ini_get("memory_limit"));
        if ($memory_limit > 0) {
            $memory_usage = memory_get_usage(true);
            $available_memory = max(0, $memory_limit - $memory_usage);
            $memory_limit_rows =
                (int) (($available_memory * 0.6) / ($avg_row_length * 2));
            if ($memory_limit_rows > 0) {
                $limit = min($limit, $memory_limit_rows);
            }
        }
        $limit = max(self::MIN_BATCH_LIMIT, min(self::MAX_BATCH_LIMIT, $limit));
        if ($is_offset_method) {
            $limit = (int) ($limit * self::OFFSET_REDUCTION_FACTOR);
            $limit = max(
                self::MIN_BATCH_LIMIT,
                min(self::MAX_OFFSET_BATCH_LIMIT, $limit)
            );
        }
        return $limit;
    }
    private function generate_phpmyadmin_header(): string
    {
        global $wpdb;
        $server_info = $wpdb->get_var("SELECT VERSION()") ?: "5.7.0";
        $db_charset = $wpdb->get_var("SELECT @@character_set_database");
        $db_collation = $wpdb->get_var("SELECT @@collation_database");
        $charset =
            $db_charset ?: (defined("DB_CHARSET") ? DB_CHARSET : "utf8mb4");
        $collation = $db_collation ?: (defined("DB_COLLATE") ? DB_COLLATE : "");
        $header =
            "-- phpMyAdmin SQL Dump\n-- Created by WP Optimal State Plugin\n-- version 5.2.1\n-- https://www.phpmyadmin.net/\n--\n-- Host: " .
            DB_HOST .
            "\n-- Generation Time: " .
            gmdate("M d, Y") .
            " at " .
            gmdate("h:i A") .
            " UTC\n-- Server version: " .
            $server_info .
            "\n-- PHP Version: " .
            PHP_VERSION .
            "\n-- Database: `" .
            DB_NAME .
            "`\n-- --------------------------------------------------------\n\n";
        $header .=
            "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        if ($collation) {
            $header .=
                "/*!40101 SET NAMES " .
                $charset .
                " COLLATE " .
                $collation .
                " */;\n";
        } else {
            $header .= "/*!40101 SET NAMES " . $charset . " */;\n";
        }
        $header .=
            "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n/*!40103 SET TIME_ZONE='+00:00' */;\n/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n";
        return $header;
    }
    private function optimize_memory(): void
    {
        if (function_exists("gc_collect_cycles")) {
            gc_collect_cycles();
        }
        if (count($this->row_length_cache) > self::MAX_ROW_LENGTH_CACHE_SIZE) {
            $this->row_length_cache = array_slice(
                $this->row_length_cache,
                -self::MAX_ROW_LENGTH_CACHE_SIZE,
                self::MAX_ROW_LENGTH_CACHE_SIZE,
                true
            );
        }
    }
    public function handle_backup_worker_error(
        string $transient_key,
        array $state,
        Throwable $exception,
        bool $is_silent_worker
    ): void {
        if (!empty($state["user_id"])) {
            $this->process_store->delete(
                "optistate_manual_backup_user_" . $state["user_id"]
            );
        }
        $filename_to_log = $state["filename"] ?? "unknown_backup.sql";
        $log_type =
            !empty($state["is_scheduled"]) ||
            strpos($filename_to_log, "SAFETY-RESTORE-") === 0
                ? "scheduled"
                : "manual";
        $current_table = isset(
            $state["all_tables"][$state["current_table_index"]]
        )
            ? $state["all_tables"][$state["current_table_index"]]
            : "unknown";
        $extra_details = [
            "transient_key" => $transient_key,
            "exception_file" => basename($exception->getFile()),
            "exception_line" => $exception->getLine(),
            "current_table" => $current_table,
            "table_offset" => $state["current_table_data_offset"] ?? 0,
            "is_scheduled" => !empty($state["is_scheduled"]),
            "memory_usage" => size_format(memory_get_usage(true), 2),
        ];
        $this->main_plugin->log_entry(
            "❌ " .
                sprintf(
                    __("Backup Failed: %s", "optistate"),
                    $exception->getMessage()
                ),
            $log_type,
            $filename_to_log,
            $extra_details
        );
        OPTISTATE_Utils::log_critical_error(
            "Backup worker error: " . $exception->getMessage(),
            [
                "file" => $exception->getFile(),
                "line" => $exception->getLine(),
                "user_id" => $state["user_id"] ?? null,
                "backup_file" => $filename_to_log,
                "transient_key" => $transient_key,
                "current_table" => $current_table,
                "table_offset" => $state["current_table_data_offset"] ?? 0,
            ]
        );
        $this->process_store->delete($transient_key);
        if (isset($state["filepath"]) && is_string($state["filepath"])) {
            $normalized_path = wp_normalize_path($state["filepath"]);
            $normalized_dir = wp_normalize_path($this->backup_dir);
            if (
                strpos($normalized_path, $normalized_dir) === 0 &&
                $this->wp_filesystem->exists($normalized_path)
            ) {
                $this->wp_filesystem->delete($normalized_path);
            }
            $lock_path = $normalized_path . ".lock";
            if (file_exists($lock_path)) {
                @unlink($lock_path);
            }
        }
        if (!$is_silent_worker) {
            $this->process_store->set(
                $transient_key . "_complete",
                ["status" => "error", "message" => $exception->getMessage()],
                300
            );
        }
    }
}