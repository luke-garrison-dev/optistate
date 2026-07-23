<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Restore_Engine
{
    private OPTISTATE $main_plugin;
    private string $backup_dir;
    private OPTISTATE_Process_Store $process_store;
    private ?object $wp_filesystem;
    private ?object $restore_db = null;
    private int $last_transaction_commit_queries = 0;

    public function __construct(
        OPTISTATE $main_plugin,
        string $backup_dir,
        OPTISTATE_Process_Store $process_store,
        ?object $wp_filesystem
    ) {
        $this->main_plugin = $main_plugin;
        $this->backup_dir = $backup_dir;
        $this->process_store = $process_store;
        if ($wp_filesystem) {
            $this->wp_filesystem = $wp_filesystem;
        } else {
            $this->wp_filesystem = $this->main_plugin->get_filesystem();
        }
    }

    private function register_lock_cleanup_handler(string $lock_name): void
    {
        $cleanup_handler = function () use ($lock_name) {
            try {
                $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
                if ($db) {
                    @$db->query(
                        "SELECT RELEASE_LOCK('" .
                            $db->real_escape_string($lock_name) .
                            "')"
                    );
                }
            } catch (\Throwable $e) {
            }
        };
        register_shutdown_function($cleanup_handler);
    }

    private function acquire_lock_with_stale_check(
        string $lock_name,
        int $timeout = 5
    ): bool {
        $lock_name =
            strlen($lock_name) > 64
                ? "opt_lock_" . md5($lock_name)
                : $lock_name;
        $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
        $result = $db->query(
            "SELECT GET_LOCK('" . $db->real_escape_string($lock_name) . "', 0)"
        );
        if ($result) {
            $row = $result->fetch_row();
            if ($row && (int) $row[0] === 1) {
                $result->free();
                return true;
            }
            $result->free();
        }
        $result = $db->query(
            "SELECT GET_LOCK('" .
                $db->real_escape_string($lock_name) .
                "', $timeout)"
        );
        if ($result) {
            $row = $result->fetch_row();
            $success = $row && $row[0] == 1;
            $result->free();
            return $success;
        }
        return false;
    }

    public function initiate_master_restore(
        string $sql_filepath,
        string $log_filename,
        string $button_selector,
        array $uploaded_file_info = [],
        int $user_id = 0
    ): array {
        $lock_acquired = false;
        $lock_name_raw = "optistate_master_restore_global";
        $lock_name =
            strlen($lock_name_raw) > 64
                ? "opt_master_" . md5($lock_name_raw)
                : $lock_name_raw;
        try {
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
            $lock_success = $this->acquire_lock_with_stale_check($lock_name, 5);
            if (!$lock_success) {
                throw new Exception(
                    __(
                        "Another restore operation is pending or in progress. Please wait and try again.",
                        "optistate"
                    )
                );
            }
            $lock_acquired = true;
            $this->register_lock_cleanup_handler($lock_name);
            if (!$this->wp_filesystem->exists($sql_filepath)) {
                throw new Exception(
                    esc_html__("SQL file not found: ", "optistate") .
                        $sql_filepath
                );
            }
            $verification = OPTISTATE_Backup_Utilities::verify_backup_file(
                $this->wp_filesystem,
                $sql_filepath,
                false,
                true,
                true
            );
            if (!$verification["valid"]) {
                throw new Exception($verification["message"]);
            }
            if (empty($uploaded_file_info["skip_space_check"])) {
                $space_check = OPTISTATE_Backup_Utilities::check_sufficient_disk_space(
                    $this->wp_filesystem,
                    $sql_filepath,
                    $this->main_plugin->get_total_database_size(false)
                );
                if (!$space_check["success"]) {
                    throw new Exception($space_check["message"]);
                }
            }
            OPTISTATE_Utils::activate_maintenance_mode();
            $this->main_plugin->log_entry(
                "▶ " .
                    sprintf(
                        __(
                            "Database Restore Started by {username} (%s)",
                            "optistate"
                        ),
                        $log_filename
                    ),
                "manual",
                $log_filename,
                ["user_id" => $user_id]
            );
            $safety_filename =
                "SAFETY-RESTORE-" . current_time("Y-m-d") . ".sql.gz";
            $safety_filepath =
                trailingslashit($this->backup_dir) . $safety_filename;
            $backup_engine = new OPTISTATE_Backup_Engine(
                $this->main_plugin,
                $this->backup_dir,
                $this->process_store,
                $this->wp_filesystem
            );
            $safety_transient_key = $backup_engine->initiate_chunked_backup(
                $safety_filename,
                ["is_safety_backup" => true, "user_id" => $user_id]
            );
            $this->process_store->set(
                "optistate_safety_backup",
                $safety_filepath,
                2 * HOUR_IN_SECONDS
            );
            $master_restore_key =
                "optistate_master_restore_" . bin2hex(random_bytes(14));
            $temp_filename = basename($sql_filepath);
            $is_internal_backup = OPTISTATE_Backup_Utilities::is_internal_backup(
                $this->wp_filesystem,
                $sql_filepath
            );
            $master_state = [
                "status" => "safety_backup_starting",
                "message" => __("CREATING SAFETY BACKUP ....", "optistate"),
                "start_time" => time(),
                "restore_target" => [
                    "type" => "temp_path",
                    "value" => $temp_filename,
                ],
                "safety_backup_key" => $safety_transient_key,
                "restore_key" => null,
                "button_selector" => $button_selector,
                "backup_charset" => $verification["metadata"] ?? null,
                "is_internal_backup" => $is_internal_backup,
            ];
            $this->process_store->set(
                $master_restore_key,
                $master_state,
                2 * HOUR_IN_SECONDS
            );
            $this->process_store->set(
                "optistate_restore_in_progress",
                $master_restore_key,
                2 * HOUR_IN_SECONDS
            );
            $this->process_store->set(
                "optistate_last_restore_filename",
                $log_filename,
                2 * HOUR_IN_SECONDS
            );
            $temp_transient_key = "optistate_temp_restore_" . $temp_filename;
            $settings = $this->main_plugin->settings_manager->get_persistent_settings();
            $security_disabled = !empty($settings["disable_restore_security"]);
            $transient_data = [
                "path" => $sql_filepath,
                "original_name" => $log_filename,
                "size" => $this->wp_filesystem->size($sql_filepath),
                "uploaded" => time(),
                "user_id" => $user_id,
                "ip_address" => OPTISTATE_Utils::get_client_ip(
                    !empty($settings["cloudflare_enabled"]),
                    []
                ),
                "is_decompressed_backup" => !empty($uploaded_file_info)
                    ? false
                    : true,
                "security_disabled" => $security_disabled,
                "backup_charset" => $verification["metadata"] ?? null,
            ];
            if (!empty($uploaded_file_info)) {
                $allowed_overrides = [
                    "security_disabled",
                    "is_decompressed_backup",
                    "temp_transient_to_delete",
                    "temp_filepath_to_delete",
                    "backup_charset",
                ];
                foreach ($allowed_overrides as $allowed_key) {
                    if (array_key_exists($allowed_key, $uploaded_file_info)) {
                        $transient_data[$allowed_key] =
                            $uploaded_file_info[$allowed_key];
                    }
                }
            }
            $this->process_store->set(
                $temp_transient_key,
                $transient_data,
                2 * HOUR_IN_SECONDS
            );
            $db->query("SELECT RELEASE_LOCK('$lock_name')");
            wp_schedule_single_event(
                time(),
                "optistate_run_safety_backup_chunk",
                [$master_restore_key]
            );
            return [
                "status" => "starting",
                "master_restore_key" => $master_restore_key,
                "message" => __(
                    "Safety backup initiated... Restore will proceed in background.",
                    "optistate"
                ),
            ];
        } catch (Exception $e) {
            if ($lock_acquired) {
                $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
                @$db->query("SELECT RELEASE_LOCK('$lock_name')");
            }
            OPTISTATE_Utils::deactivate_maintenance_mode();
            $this->release_restore_lock();
            $this->process_store->delete("optistate_restore_in_progress");
            OPTISTATE_Utils::log_critical_error(
                "Master restore initiation failed: " . $e->getMessage(),
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "user_id" => $user_id,
                    "sql_file" => basename($sql_filepath),
                ]
            );
            throw $e;
        }
    }

    public function initiate_chunked_restore(
        string $filepath,
        string $log_filename,
        array $uploaded_file_info = []
    ): string {
        try {
            if (!$this->wp_filesystem) {
                throw new Exception(
                    esc_html__("Filesystem not initialized.", "optistate")
                );
            }
            $total_size = $this->wp_filesystem->size($filepath);
            if ($total_size === false || $total_size < 100) {
                throw new Exception(
                    esc_html__("Invalid or empty backup file.", "optistate")
                );
            }
            try {
                $transient_key =
                    "optistate_restore_" . bin2hex(random_bytes(14));
            } catch (\Throwable $e) {
                $transient_key =
                    "optistate_restore_" .
                    md5(uniqid((string) wp_rand(), true) . microtime());
            }
            global $wpdb;
            $meta_table = $wpdb->prefix . "optistate_backup_metadata";
            $is_internal = OPTISTATE_Backup_Utilities::is_internal_backup(
                $this->wp_filesystem,
                $filepath
            );
            $total_statements_estimate = 0;
            if (preg_match('/\.gz$/i', $filepath)) {
                $total_statements_estimate = (int) (($total_size * 5) / 500);
            } else {
                $total_statements_estimate = (int) ($total_size / 500);
            }
            $state = [
                "filepath" => $filepath,
                "log_filename" => $log_filename,
                "file_pointer" => 0,
                "total_size" => $total_size,
                "temp_tables_created" => [],
                "executed_queries" => 0,
                "start_time" => time(),
                "status" => "init",
                "query_buffer" => "",
                "line_buffer" => "",
                "current_delimiter" => ";",
                "in_multi_line_comment" => false,
                "batch_counter" => 0,
                "uploaded_file_info" => $uploaded_file_info,
                "deferred_indexes" => [],
                "resume_attempts" => 0,
                "last_error" => "",
                "is_internal_backup" => $is_internal,
                "total_statements_estimate" => $total_statements_estimate,
                "restore_key" => $transient_key,
            ];
            $this->process_store->set($transient_key, $state, DAY_IN_SECONDS);
            return $transient_key;
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "initiate_chunked_restore failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            throw new Exception(
                esc_html__("Failed to initiate restore: ", "optistate") .
                    esc_html($e->getMessage())
            );
        }
    }

    public function process_restore_chunk(string $restore_key): array
    {
        $restore_state = $this->process_store->get($restore_key);
        if ($restore_state === false) {
            throw new Exception(
                esc_html__("Restore session expired.", "optistate")
            );
        }
        $result = $this->perform_restore_core($restore_state);
        if ($result["status"] === "done") {
            return [
                "status" => "done",
                "message" => $result["message"],
                "state" => $result["state"],
            ];
        }
        $this->process_store->set(
            $restore_key,
            $result["state"],
            DAY_IN_SECONDS
        );
        return ["status" => "running", "state" => $result["state"]];
    }

    public function decompress_file(
        string $source_gz_path,
        string $dest_sql_path
    ) {
        wp_raise_memory_limit("admin");
        $original_time_limit_raw = ini_get("max_execution_time");
        $original_time_limit =
            $original_time_limit_raw === false ||
            !is_numeric($original_time_limit_raw)
                ? 0
                : (int) $original_time_limit_raw;
        OPTISTATE_Utils::safe_set_time_limit(0);
        $gz_handle = null;
        $sql_handle = null;
        try {
            if (!$this->wp_filesystem) {
                throw new Exception(
                    esc_html__("Filesystem not initialized.", "optistate")
                );
            }
            $use_cli = defined("WP_CLI") && WP_CLI && php_sapi_name() === "cli";
            $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === "WIN";
            if (
                !$is_windows &&
                ($use_cli ||
                    OPTISTATE_Backup_Utilities::is_shell_exec_available())
            ) {
                $source_path_shell = escapeshellarg($source_gz_path);
                $dest_path_shell = escapeshellarg($dest_sql_path);
                $pigz_path = @exec(
                    "which pigz 2>/dev/null",
                    $pigz_output,
                    $pigz_return
                );
                if ($pigz_return === 0 && !empty($pigz_path)) {
                    $timeout_cmd = @is_executable("/usr/bin/timeout")
                        ? "/usr/bin/timeout 60 "
                        : "";
                    $command =
                        $timeout_cmd .
                        $pigz_path .
                        " -d -c " .
                        $source_path_shell .
                        " > " .
                        $dest_path_shell .
                        " 2>&1";
                    @exec($command, $output, $return_var);
                    if (
                        $return_var === 0 &&
                        $this->wp_filesystem->exists($dest_sql_path) &&
                        $this->wp_filesystem->size($dest_sql_path) > 0
                    ) {
                        $this->wp_filesystem->chmod($dest_sql_path, 0600);
                        return true;
                    }
                }
                $gzip_path = OPTISTATE_Backup_Utilities::get_gzip_path();
                if ($gzip_path !== false) {
                    $timeout_cmd = @is_executable("/usr/bin/timeout")
                        ? "/usr/bin/timeout 60 "
                        : "";
                    $command =
                        $timeout_cmd .
                        $gzip_path .
                        " -d -c " .
                        $source_path_shell .
                        " > " .
                        $dest_path_shell .
                        " 2>&1";
                    @exec($command, $output, $return_var);
                    if (
                        $return_var === 0 &&
                        $this->wp_filesystem->exists($dest_sql_path) &&
                        $this->wp_filesystem->size($dest_sql_path) > 0
                    ) {
                        $this->wp_filesystem->chmod($dest_sql_path, 0600);
                        return true;
                    }
                }
            }
            if (!function_exists("gzopen")) {
                throw new Exception(
                    esc_html__("GZIP functions not available.", "optistate")
                );
            }
            $progress_key = "optistate_decompress_" . md5($source_gz_path);
            $progress = $this->process_store->get($progress_key);
            $source_size = filesize($source_gz_path);
            if ($progress === false) {
                $progress = [
                    "source_bytes_read" => 0,
                    "dest_bytes_written" => 0,
                    "started" => time(),
                    "source_size" => $source_size,
                ];
            }
            if (time() - $progress["started"] > 7200) {
                if ($this->wp_filesystem->exists($dest_sql_path)) {
                    $this->wp_filesystem->delete($dest_sql_path);
                }
                $this->process_store->delete($progress_key);
                $progress = [
                    "source_bytes_read" => 0,
                    "dest_bytes_written" => 0,
                    "started" => time(),
                    "source_size" => $source_size,
                ];
            }
            $resuming = $progress["dest_bytes_written"] > 0;
            if ($resuming) {
                if (!$this->wp_filesystem->exists($dest_sql_path)) {
                    $resuming = false;
                    $progress["dest_bytes_written"] = 0;
                } else {
                    $actual_size = $this->wp_filesystem->size($dest_sql_path);
                    if ($actual_size !== $progress["dest_bytes_written"]) {
                        $this->wp_filesystem->delete($dest_sql_path);
                        $resuming = false;
                        $progress["dest_bytes_written"] = 0;
                    }
                }
            }
            $gz_handle = @gzopen($source_gz_path, "rb");
            if (!$gz_handle) {
                throw new Exception(
                    esc_html__("Cannot open GZIP file.", "optistate")
                );
            }
            $write_mode = $resuming ? "ab" : "wb";
            $sql_handle = @fopen($dest_sql_path, $write_mode);
            if (!$sql_handle) {
                throw new Exception(
                    esc_html__("Cannot open destination file.", "optistate")
                );
            }
            if ($resuming) {
                $seek_position = $progress["dest_bytes_written"];
                if (gzseek($gz_handle, $seek_position) === -1) {
                    $this->process_store->delete($progress_key);
                    $this->wp_filesystem->delete($dest_sql_path);
                    throw new Exception(
                        "GZIP stream seek failed. Restarting process."
                    );
                }
                fseek($sql_handle, 0, SEEK_END);
            }
            $chunk_size = 1024 * 1024;
            $start_time = time();
            $max_chunk_time = 20;
            $bytes_written_this_session = 0;
            $last_time_check_bytes = 0;
            $memory_limit = wp_convert_hr_to_bytes(ini_get("memory_limit"));
            $is_unlimited_memory = $memory_limit <= 0;
            $max_decompressed_size = $is_unlimited_memory
                ? 4294967296
                : $memory_limit * 20;
            while (!gzeof($gz_handle)) {
                if (
                    $bytes_written_this_session > 0 &&
                    $bytes_written_this_session - $last_time_check_bytes >=
                        10 * $chunk_size
                ) {
                    $last_time_check_bytes = $bytes_written_this_session;
                    if (time() - $start_time >= $max_chunk_time) {
                        $progress[
                            "dest_bytes_written"
                        ] += $bytes_written_this_session;
                        $progress["source_bytes_read"] = gztell($gz_handle);
                        $this->process_store->set(
                            $progress_key,
                            $progress,
                            2 * HOUR_IN_SECONDS
                        );
                        return "INCOMPLETE";
                    }
                }
                $current_memory = memory_get_usage(true);
                if (
                    !$is_unlimited_memory &&
                    $current_memory > $memory_limit * 0.85
                ) {
                    throw new Exception(
                        sprintf(
                            __(
                                "Decompression stopped: memory usage critical at %s of %s limit",
                                "optistate"
                            ),
                            size_format($current_memory),
                            size_format($memory_limit)
                        )
                    );
                }
                $written = @stream_copy_to_stream(
                    $gz_handle,
                    $sql_handle,
                    $chunk_size
                );
                if ($written === false || $written === 0) {
                    if (gzeof($gz_handle)) {
                        break;
                    }
                    throw new Exception(
                        esc_html__(
                            "Disk write error or stream failure during decompression",
                            "optistate"
                        )
                    );
                }
                $bytes_written_this_session += $written;
                $total_written =
                    $progress["dest_bytes_written"] +
                    $bytes_written_this_session;
                if ($total_written > $max_decompressed_size) {
                    throw new Exception(
                        sprintf(
                            __(
                                "Decompressed file exceeds safe size limit (%s)",
                                "optistate"
                            ),
                            size_format($max_decompressed_size)
                        )
                    );
                }
            }
            fclose($sql_handle);
            gzclose($gz_handle);
            $sql_handle = null;
            $gz_handle = null;
            if (!$this->wp_filesystem->exists($dest_sql_path)) {
                throw new Exception(
                    esc_html__(
                        "Decompressed file not found after write operation",
                        "optistate"
                    )
                );
            }
            $final_size = $this->wp_filesystem->size($dest_sql_path);
            if ($final_size < 100) {
                $this->wp_filesystem->delete($dest_sql_path);
                throw new Exception(
                    esc_html__(
                        "Decompressed file is suspiciously small - possible corruption",
                        "optistate"
                    )
                );
            }
            $this->wp_filesystem->chmod($dest_sql_path, 0600);
            $this->process_store->delete($progress_key);
            return true;
        } catch (Exception $e) {
            if ($sql_handle) {
                @fclose($sql_handle);
            }
            if ($gz_handle) {
                @gzclose($gz_handle);
            }
            if ($this->wp_filesystem->exists($dest_sql_path)) {
                $this->wp_filesystem->delete($dest_sql_path);
            }
            OPTISTATE_Utils::log_critical_error(
                "Decompression failed: " . $e->getMessage(),
                [
                    "source" => basename($source_gz_path),
                    "dest" => basename($dest_sql_path),
                ]
            );
            throw $e;
        } finally {
            if ($gz_handle !== null && is_resource($gz_handle)) {
                @gzclose($gz_handle);
            }
            if ($sql_handle !== null && is_resource($sql_handle)) {
                @fclose($sql_handle);
            }
            if ($original_time_limit > 0) {
                OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
            }
        }
    }

    public function perform_rollback(): bool
    {
        $instant_rollback_tables = $this->process_store->get(
            "optistate_instant_rollback_tables"
        );
        if (
            $instant_rollback_tables === false ||
            !is_array($instant_rollback_tables)
        ) {
            return false;
        }
        OPTISTATE_Utils::log_critical_error("Perform rollback started", [
            "table_count" => count($instant_rollback_tables),
        ]);
        $db = null;
        try {
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
            return OPTISTATE_Utils::without_foreign_key_checks(function () use (
                $instant_rollback_tables,
                $db
            ) {
                return OPTISTATE_Utils::transaction(function () use (
                    $instant_rollback_tables,
                    $db
                ) {
                    $renames = [];
                    $trash_tables = [];
                    foreach (
                        $instant_rollback_tables
                        as $original_table => $old_table
                    ) {
                        $trash_name = OPTISTATE_Utils::generate_safe_table_name(
                            $original_table,
                            "optistate_trash_",
                            64
                        );
                        if (
                            !preg_match('/^[a-zA-Z0-9_]+$/', $original_table) ||
                            !preg_match('/^[a-zA-Z0-9_]+$/', $old_table) ||
                            !preg_match('/^[a-zA-Z0-9_]+$/', $trash_name)
                        ) {
                            throw new Exception("Rollback aborted: invalid table name in rollback map.");
                        }
                        $renames[] = sprintf(
                            "%s TO %s, %s TO %s",
                            OPTISTATE_Utils::escape_identifier($original_table),
                            OPTISTATE_Utils::escape_identifier($trash_name),
                            OPTISTATE_Utils::escape_identifier($old_table),
                            OPTISTATE_Utils::escape_identifier($original_table)
                        );
                        $trash_tables[] = OPTISTATE_Utils::escape_identifier($trash_name);
                    }
                    if (!empty($renames)) {
                        $query = "RENAME TABLE " . implode(", ", $renames);
                        $result = $db->query($query);
                        if ($result === false) {
                            $error = $db->error;
                            OPTISTATE_Utils::log_critical_error(
                                "Atomic Rollback Rename Failed",
                                ["error" => $error]
                            );
                            throw new Exception(
                                "Atomic Rollback Rename Failed: " . $error
                            );
                        }
                    }
                    if (!empty($trash_tables)) {
                        $db->query(
                            "DROP TABLE IF EXISTS " .
                                implode(", ", $trash_tables)
                        );
                    }
                    return true;
                }, $db);
            }, $db);
        } catch (Throwable $e) {
            if ($db) {
                try {
                    $db->query("ROLLBACK");
                    $db->query("SET FOREIGN_KEY_CHECKS = 1");
                    $db->query("SET AUTOCOMMIT = 1");
                } catch (Throwable $t) {
                }
                OPTISTATE_DB_Wrapper::get_instance()->close();
                $db = null;
            }
            OPTISTATE_Utils::log_critical_error(
                "Rollback crashed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            $this->process_store->set(
                "optistate_rollback_status",
                "failed",
                HOUR_IN_SECONDS
            );
            return false;
        }
    }

    public function cleanup_old_tables_after_restore(): void
    {
        try {
            global $wpdb;
            $query = $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND (TABLE_NAME LIKE 'optistate_old_%%' OR TABLE_NAME LIKE 'optistate_temp_%%')",
                DB_NAME
            );
            $stray_tables = $wpdb->get_col($query);
            if ($stray_tables && count($stray_tables) > 0) {
                $db = $this->get_restore_db();
                OPTISTATE_Utils::without_foreign_key_checks(function () use (
                    $db,
                    $stray_tables
                ) {
                    foreach (array_chunk($stray_tables, 20) as $chunk) {
                        $tables = array_map(function ($t) {
                            return OPTISTATE_Utils::escape_identifier($t);
                        }, $chunk);
                        $db->query(
                            "DROP TABLE IF EXISTS " . implode(", ", $tables)
                        );
                    }
                }, $db);
                $this->close_restore_db();
            }
        } catch (Exception $e) {
            if (defined("WP_DEBUG") && WP_DEBUG) {
                OPTISTATE_Utils::log_critical_error(
                    "cleanup_old_tables_after_restore() failed",
                    ["message" => $e->getMessage()]
                );
            }
        } finally {
            try {
                OPTISTATE_DB_Wrapper::get_instance()->close();
            } catch (Exception $e) {
            }
        }
    }

    public function cleanup_temp_tables(array $temp_tables_created): void
    {
        if (empty($temp_tables_created)) {
            return;
        }
        try {
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
            OPTISTATE_Utils::without_foreign_key_checks(function () use (
                $db,
                $temp_tables_created
            ) {
                foreach (
                    $temp_tables_created
                    as $original_table => $temp_table
                ) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp_table)) {
                        continue;
                    }
                    $db->query(
                        "DROP TABLE IF EXISTS " .
                            OPTISTATE_Utils::escape_identifier($temp_table)
                    );
                    $old_table = OPTISTATE_Utils::generate_safe_table_name(
                        $original_table,
                        "optistate_old_",
                        64
                    );
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $old_table)) {
                        continue;
                    }
                    $db->query(
                        "DROP TABLE IF EXISTS " .
                            OPTISTATE_Utils::escape_identifier($old_table)
                    );
                }
            }, $db);
            OPTISTATE_DB_Wrapper::get_instance()->close();
        } catch (Exception $e) {
            OPTISTATE_Utils::log_critical_error(
                "Cleanup of temp tables failed: " . $e->getMessage(),
                ["tables" => array_keys($temp_tables_created)]
            );
        }
    }

    public function get_restore_db()
    {
        $this->restore_db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
        return $this->restore_db;
    }

    public function close_restore_db(): void
    {
        OPTISTATE_DB_Wrapper::get_instance()->close();
        $this->restore_db = null;
    }

    public function acquire_restore_lock(int $timeout = 0): bool
    {
        global $wpdb;
        $lock_name_raw = $wpdb->prefix . "optistate_restore_lock";
        $lock_name =
            strlen($lock_name_raw) > 64
                ? "opt_rest_" . md5($lock_name_raw)
                : $lock_name_raw;
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT GET_LOCK(%s, %d)",
                $lock_name,
                absint($timeout)
            )
        );
        if ($result === "1") {
            $this->process_store->set(
                "optistate_mysql_lock_holder",
                [
                    "lock_name" => $lock_name,
                    "acquired_at" => time(),
                    "connection_id" => $wpdb->get_var("SELECT CONNECTION_ID()"),
                    "server_id" => gethostname(),
                    "process_id" => getmypid(),
                ],
                2 * HOUR_IN_SECONDS
            );
            return true;
        }
        return false;
    }

    public function release_restore_lock(): bool
    {
        global $wpdb;
        $lock_info = $this->process_store->get("optistate_mysql_lock_holder");
        if (!$lock_info || empty($lock_info["lock_name"])) {
            return false;
        }
        $lock_name = $lock_info["lock_name"];
        $result = $wpdb->get_var(
            $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)
        );
        $this->process_store->delete("optistate_mysql_lock_holder");
        return $result === "1";
    }

    private function validate_charset_compatibility(
        ?array $backup_charset_info,
        string $current_db_charset
    ): array {
        $backup_charset =
            isset($backup_charset_info["charset"]) &&
            $backup_charset_info["charset"] !== null
                ? strtolower((string) $backup_charset_info["charset"])
                : null;
        if (!$backup_charset) {
            return [
                "compatible" => true,
                "action" => "use_database_charset",
                "warning" => __(
                    "Backup charset unknown - using database default",
                    "optistate"
                ),
            ];
        }
        $current_charset = strtolower((string) $current_db_charset);
        $backup_normalized =
            $backup_charset === "utf8mb3" ? "utf8" : $backup_charset;
        $current_normalized =
            $current_charset === "utf8mb3" ? "utf8" : $current_charset;
        if (
            $backup_normalized === "utf8mb4" &&
            $current_normalized === "utf8"
        ) {
            return [
                "compatible" => false,
                "error" => __(
                    "Cannot restore UTF8MB4 backup to UTF8 database - data loss would occur (emoji and special characters will be corrupted). Please upgrade your database to UTF8MB4 first.",
                    "optistate"
                ),
            ];
        }
        if (
            $backup_normalized === "utf8" &&
            $current_normalized === "utf8mb4"
        ) {
            return [
                "compatible" => true,
                "action" => "use_backup_charset",
                "message" => __(
                    "Backup is UTF8, will restore as UTF8 (compatible with current UTF8MB4 database)",
                    "optistate"
                ),
            ];
        }
        return [
            "compatible" => true,
            "action" => "use_backup_charset",
            "message" => sprintf(
                __("Charset compatible: %s", "optistate"),
                $backup_normalized
            ),
        ];
    }

    private function clear_trash_table_after_restore(): void
    {
        global $wpdb;
        $trash_table = $wpdb->prefix . "optistate_trash";
        try {
            $table_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $wpdb->esc_like($trash_table)
                )
            );
            if ($table_exists !== $trash_table) {
                return;
            }
            $result = $wpdb->query(
                "TRUNCATE TABLE " .
                    OPTISTATE_Utils::escape_identifier($trash_table)
            );
            if ($result === false) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to truncate trash table after restore",
                    ["error" => $wpdb->last_error]
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Exception while clearing trash table after restore",
                ["message" => $e->getMessage()]
            );
        }
    }

    private function perform_restore_core(array $state): array
    {
        wp_raise_memory_limit("admin");
        global $wpdb;
        $db = null;
        $handle = null;
        $chunk_start_time = microtime(true);
        $max_chunk_time = 18;
        $original_time_limit_raw = ini_get("max_execution_time");
        $original_time_limit =
            $original_time_limit_raw === false ||
            !is_numeric($original_time_limit_raw)
                ? 0
                : (int) $original_time_limit_raw;
        $needed_time = $max_chunk_time + 90;
        OPTISTATE_Utils::safe_set_time_limit($needed_time);
        try {
            $filepath = $state["filepath"];
            if (
                !$this->wp_filesystem->exists($filepath) ||
                !$this->wp_filesystem->is_readable($filepath)
            ) {
                throw new Exception(
                    esc_html__(
                        "Backup file not found or not readable.",
                        "optistate"
                    )
                );
            }
            $is_gzipped = substr($filepath, -3) === ".gz";
            if (
                $is_gzipped &&
                (int) $state["file_pointer"] === 0 &&
                $this->should_decompress_before_restore($filepath)
            ) {
                $upload_dir = wp_upload_dir();
                $temp_dir =
                    trailingslashit($upload_dir["basedir"]) .
                    OPTISTATE::TEMP_DIR_NAME .
                    "/";
                try {
                    $temp_sql_path =
                        $temp_dir .
                        "large-restore-" .
                        bin2hex(random_bytes(16)) .
                        ".sql";
                } catch (\Throwable $e) {
                    $temp_sql_path =
                        $temp_dir .
                        "large-restore-" .
                        md5(uniqid((string) wp_rand(), true)) .
                        ".sql";
                }
                $decompress_result = $this->decompress_file(
                    $filepath,
                    $temp_sql_path
                );
                if ($decompress_result === true) {
                    $state["original_filepath"] = $filepath;
                    $state["decompressed_temp_file"] = $temp_sql_path;
                    $filepath = $temp_sql_path;
                    $state["filepath"] = $filepath;
                    $is_gzipped = false;
                }
            }
            $handle = $is_gzipped
                ? @gzopen($filepath, "rb")
                : @fopen($filepath, "rb");
            if (!$handle) {
                throw new Exception(
                    esc_html__(
                        "Failed to open backup file for reading.",
                        "optistate"
                    )
                );
            }
            if ($state["file_pointer"] > 0) {
                if ($is_gzipped) {
                    if (gzseek($handle, $state["file_pointer"]) === -1) {
                        throw new Exception(
                            sprintf(
                                __(
                                    "Failed to seek to position %s in compressed file. File may be corrupted.",
                                    "optistate"
                                ),
                                number_format($state["file_pointer"])
                            )
                        );
                    }
                } else {
                    fseek($handle, $state["file_pointer"]);
                }
            }
            $current_transaction_size = $state["current_transaction_size"] ?? 0;
            $temp_tables_created = $state["temp_tables_created"];
            $state["deferred_indexes"] = $state["deferred_indexes"] ?? [];
            $is_internal_backup = $state["is_internal_backup"] ?? false;
            $restore_key_for_deferred = $state["restore_key"] ?? "restore_deferred";
            try {
                $db = $this->get_restore_db();
                $db_wrapper = OPTISTATE_DB_Wrapper::get_instance();
                $db_connection = $db_wrapper->get_connection();
                $executed_queries = $state["executed_queries"];
                $batch_counter = $state["batch_counter"];
                $stream_buffer = $state["line_buffer"] ?? "";
                $current_delimiter = $state["current_delimiter"] ?? ";";
                $transaction_max_size = 25 * 1024 * 1024;
                if (
                    isset($state["exclude_patterns_cache"]) &&
                    is_array($state["exclude_patterns_cache"])
                ) {
                    $exclude_patterns = $state["exclude_patterns_cache"];
                    if (!isset($state["excluded_table_names"])) {
                        $state["excluded_table_names"] = array_keys(
                            $exclude_patterns
                        );
                    }
                } else {
                    $excluded_tables = OPTISTATE_Utils::get_all_excluded_tables();
                    $exclude_patterns = [];
                    foreach ($excluded_tables as $table) {
                        $exclude_patterns[strtolower($table)] = true;
                    }
                    $state["exclude_patterns_cache"] = $exclude_patterns;
                    $state["excluded_table_names"] = array_keys(
                        $exclude_patterns
                    );
                }
                $backup_has_transactions =
                    $state["backup_has_transactions"] ?? true;
                if ($state["status"] === "init") {
                    $current_db_charset = $wpdb->get_var(
                        "SELECT @@character_set_database"
                    );
                    $backup_charset_info =
                        $state["uploaded_file_info"]["backup_charset"] ?? null;
                    $charset_check = $this->validate_charset_compatibility(
                        $backup_charset_info,
                        $current_db_charset
                    );
                    if (!$charset_check["compatible"]) {
                        throw new Exception($charset_check["error"]);
                    }
                    $this->configure_db_session(
                        $db_connection,
                        $charset_check,
                        $backup_charset_info
                    );
                    $state["status"] = "running";
                    $state["charset_locked"] = true;
                }
                $security_disabled = false;
                if (isset($state["uploaded_file_info"]["security_disabled"])) {
                    $security_disabled =
                        (bool) $state["uploaded_file_info"][
                            "security_disabled"
                        ];
                }
                $chunk_start_pointer = $state["file_pointer"];
                $queries_at_chunk_start = $state["executed_queries"];
                $last_stmt_type = $state["last_stmt_type"] ?? null;
                $loop_counter = 0;
                while (
                    ($sql_statement = OPTISTATE_SQL_Parser::read_statement(
                        $handle,
                        $stream_buffer,
                        $is_gzipped,
                        $current_delimiter
                    )) !== null
                ) {
                    if (++$loop_counter % 50 === 0) {
                        $chunk_elapsed = microtime(true) - $chunk_start_time;
                        if (
                            $chunk_elapsed >= $max_chunk_time - 2 ||
                            connection_aborted()
                        ) {
                            if ($db_wrapper->in_transaction()) {
                                $db_wrapper->commit();
                                $current_transaction_size = 0;
                                $batch_counter = 0;
                                $this->last_transaction_commit_queries = $executed_queries;
                            }
                            $state["file_pointer"] = $is_gzipped
                                ? gztell($handle)
                                : ftell($handle);
                            if (
                                $state["file_pointer"] === $chunk_start_pointer &&
                                $executed_queries === $queries_at_chunk_start
                            ) {
                                $state["stuck_counter"] =
                                    ($state["stuck_counter"] ?? 0) + 1;
                                if ($state["stuck_counter"] > 3) {
                                    throw new Exception(
                                        sprintf(
                                            __(
                                                "Restore process stuck at byte %s. The parser cannot advance past the current statement.",
                                                "optistate"
                                            ),
                                            $chunk_start_pointer
                                        )
                                    );
                                }
                            } else {
                                $state["stuck_counter"] = 0;
                            }
                            $state["line_buffer"] = $stream_buffer;
                            $state["current_delimiter"] = $current_delimiter;
                            $state[
                                "temp_tables_created"
                            ] = $temp_tables_created;
                            $state["executed_queries"] = $executed_queries;
                            $state["batch_counter"] = $batch_counter;
                            $state["last_stmt_type"] = $last_stmt_type;
                            $state[
                                "current_transaction_size"
                            ] = $current_transaction_size;
                            $state["total_statements_estimate"] =
                                $state["total_statements_estimate"] ?? 0;
                            if ($state["total_statements_estimate"] > 0) {
                                $state["progress_percent"] = min(
                                    99,
                                    (int) (($executed_queries /
                                        $state["total_statements_estimate"]) *
                                        100)
                                );
                            } else {
                                $state["progress_percent"] = min(
                                    99,
                                    (int) (($state["file_pointer"] /
                                        $state["total_size"]) *
                                        100)
                                );
                            }
                            if (
                                $executed_queries >
                                    $state["total_statements_estimate"] * 0.5 &&
                                $state["file_pointer"] <
                                    $state["total_size"] * 0.5
                            ) {
                                $state["total_statements_estimate"] *= 2;
                                $state["progress_percent"] = min(
                                    99,
                                    (int) (($executed_queries /
                                        $state["total_statements_estimate"]) *
                                        100)
                                );
                            }
                            if ($is_gzipped) {
                                gzclose($handle);
                            } else {
                                fclose($handle);
                            }
                            return [
                                "status" => "running",
                                "state" => $state,
                                "message" => esc_html__(
                                    "RESTORING DATABASE ....",
                                    "optistate"
                                ),
                            ];
                        }
                    }
                    $trim_line = trim($sql_statement);
                    if ($trim_line === "") {
                        continue;
                    }
                    if (
                        !$this->should_process_statement(
                            $trim_line,
                            $security_disabled
                        )
                    ) {
                        continue;
                    }
                    $stmt_type = OPTISTATE_Backup_Utilities::get_statement_type(
                        $trim_line
                    );
                    $query_to_run = $trim_line;
                    if ($stmt_type === "START " || $stmt_type === "COMMIT") {
                        continue;
                    }
                    if ($stmt_type === "INSERT") {
                        if (
                            !OPTISTATE_Backup_Utilities::validate_insert_column_list(
                                $trim_line
                            )
                        ) {
                            throw new Exception(
                                __(
                                    "Security: Invalid column list detected in INSERT statement",
                                    "optistate"
                                )
                            );
                        }
                        if (
                            preg_match(
                                '/INSERT\s+INTO\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
                                $query_to_run,
                                $table_match
                            )
                        ) {
                            $original_table = $table_match[1];
                            if (
                                isset(
                                    $exclude_patterns[
                                        strtolower($original_table)
                                    ]
                                )
                            ) {
                                continue;
                            }
                            if (isset($temp_tables_created[$original_table])) {
                                $query_to_run = OPTISTATE_SQL_Parser::fast_insert_rewrite(
                                    $query_to_run,
                                    $original_table,
                                    $temp_tables_created[$original_table]
                                );
                            }
                        }
                    } elseif (
                        $stmt_type === "CREATE" ||
                        $stmt_type === "DROP T" ||
                        $stmt_type === "ALTER "
                    ) {
                        $rewrite_result = OPTISTATE_SQL_Parser::rewrite_ddl(
                            $query_to_run,
                            $stmt_type,
                            $temp_tables_created,
                            $state["excluded_table_names"]
                        );
                        if ($rewrite_result["skip"]) {
                            continue;
                        }
                        if (empty($rewrite_result["temp_table"])) {
                            if (isset($state["restore_key"])) {
                                $this->process_store->atomic_update(
                                    $state["restore_key"],
                                    function ($s) use ($query_to_run) {
                                        if ($s === false) {
                                            return false;
                                        }
                                        $s["last_error"] =
                                            "Skipped unsafe/unparsable DDL: " .
                                            substr($query_to_run, 0, 50);
                                        return $s;
                                    }
                                );
                            }
                            continue;
                        }
                        $query_to_run = $rewrite_result["query"];
                        $original_table = $rewrite_result["original_table"];
                        $temp_table = $rewrite_result["temp_table"];
                        if ($stmt_type === "CREATE" && $temp_table) {
                            if (!$is_internal_backup) {
                                $query_to_run = OPTISTATE_SQL_Parser::clean_create_statement(
                                    $query_to_run
                                );
                                $query_to_run = $this->normalize_restore_create_statement(
                                    $query_to_run
                                );
                            }
                            $settings = $this->main_plugin->settings_manager->get_persistent_settings();
                            if (empty($settings["skip_index_parsing"])) {
                                $parsing_result = OPTISTATE_SQL_Parser::parse_create_table_for_indexes(
                                    $query_to_run,
                                    $temp_table
                                );
                                $query_to_run =
                                    $parsing_result["create_table_query"];
                                if (!empty($parsing_result["alter_queries"])) {
                                    $deferred_key =
                                        $restore_key_for_deferred .
                                        "_deferred_" .
                                        md5($temp_table);
                                    $this->process_store->set(
                                        $deferred_key,
                                        [
                                            "table" => $temp_table,
                                            "queries" =>
                                                $parsing_result[
                                                    "alter_queries"
                                                ],
                                            "created_at" => time(),
                                        ],
                                        DAY_IN_SECONDS
                                    );
                                    $tables_key =
                                        $restore_key_for_deferred .
                                        "_deferred_tables";
                                    $tables_with_deferred =
                                        $this->process_store->get(
                                            $tables_key
                                        ) ?: [];
                                    if (
                                        !in_array(
                                            $temp_table,
                                            $tables_with_deferred,
                                            true
                                        )
                                    ) {
                                        $tables_with_deferred[] = $temp_table;
                                        $this->process_store->set(
                                            $tables_key,
                                            $tables_with_deferred,
                                            DAY_IN_SECONDS
                                        );
                                    }
                                }
                            }
                        }
                    }
                    $queries_since_last_commit =
                        $executed_queries -
                        $this->last_transaction_commit_queries;
                    $should_commit = false;
                    $requires_immediate_commit = false;
                    if ($stmt_type === "INSERT") {
                        if (
                            $current_transaction_size > $transaction_max_size ||
                            $queries_since_last_commit >= 1000
                        ) {
                            $should_commit = true;
                        }
                    } elseif (
                        $stmt_type === "CREATE" ||
                        $stmt_type === "DROP T" ||
                        $stmt_type === "ALTER "
                    ) {
                        $requires_immediate_commit = true;
                    }
                    if (
                        $requires_immediate_commit &&
                        $db_wrapper->in_transaction()
                    ) {
                        $db_wrapper->commit();
                        $current_transaction_size = 0;
                        $batch_counter = 0;
                        $this->last_transaction_commit_queries = $executed_queries;
                    }
                    if (
                        $stmt_type === "INSERT" &&
                        !$db_wrapper->in_transaction() &&
                        $backup_has_transactions
                    ) {
                        $db_wrapper->begin_transaction();
                    }
                    if (!$db_wrapper->force_commit_if_needed()) {
                        throw new Exception(
                            __(
                                "Transaction integrity lost during connection refresh.",
                                "optistate"
                            )
                        );
                    }
                    $new_connection = $db_wrapper->get_connection();
                    if ($new_connection !== $db_connection) {
                        $db_connection = $new_connection;
                    }
                    if (
                        $executed_queries > 0 &&
                        $executed_queries % 100 === 0
                    ) {
                        @$db_connection->query("SELECT 1");
                        $elapsed_time = microtime(true) - $chunk_start_time;
                        if ($elapsed_time > 240) {
                            if ($db_wrapper->in_transaction()) {
                                $db_wrapper->commit();
                                $current_transaction_size = 0;
                                $batch_counter = 0;
                                $this->last_transaction_commit_queries = $executed_queries;
                            }
                            $this->close_restore_db();
                            $db_wrapper = OPTISTATE_DB_Wrapper::get_instance();
                            $db_connection = $db_wrapper->get_connection();
                            if (
                                $stmt_type === "INSERT" &&
                                $backup_has_transactions
                            ) {
                                $db_wrapper->begin_transaction();
                            }
                            $chunk_start_time = microtime(true);
                        }
                    }
                    $result = $db_wrapper->query($query_to_run);
                    if ($result === false) {
                        $error_msg = $db_wrapper->get_error();
                        $errno = $db_wrapper->get_errno();
                        if ($errno === 2006 || $errno === 2013) {
                            throw new Exception(
                                __(
                                    "Database connection dropped during transaction. The chunk will be safely retried.",
                                    "optistate"
                                )
                            );
                        }
                        if (
                            $errno === 1153 &&
                            $stmt_type === "INSERT" &&
                            isset($original_table) &&
                            isset($temp_tables_created[$original_table])
                        ) {
                            if ($db_wrapper->in_transaction()) {
                                $db_wrapper->rollback();
                            }
                            $retry_success = OPTISTATE_SQL_Parser::split_and_retry_insert(
                                $db_connection,
                                $query_to_run,
                                $original_table,
                                $temp_tables_created[$original_table]
                            );
                            if ($retry_success) {
                                $result = true;
                                $error_msg = "";
                            } else {
                                $error_msg =
                                    "Packet too large and split retry failed.";
                            }
                        }
                        if (!$result) {
                            $is_ignorable = $this->is_ignorable_error(
                                $error_msg,
                                $stmt_type,
                                $query_to_run
                            );
                            if (!$is_ignorable) {
                                if ($db_wrapper->in_transaction()) {
                                    $db_wrapper->rollback();
                                }
                                $error_context = [
                                    "stmt_type" => $stmt_type,
                                    "query_preview" => substr(
                                        $query_to_run,
                                        0,
                                        500
                                    ),
                                    "errno" => $errno,
                                ];
                                OPTISTATE_Utils::log_critical_error(
                                    "Restore query failed: " . $error_msg,
                                    $error_context
                                );
                                throw new Exception(
                                    __("SQL Error: ", "optistate") . $error_msg
                                );
                            }
                        }
                    }
                    if ($requires_immediate_commit) {
                        if ($backup_has_transactions) {
                            $db_wrapper->begin_transaction();
                        }
                    } elseif ($should_commit && $db_wrapper->in_transaction()) {
                        $db_wrapper->commit();
                        $current_transaction_size = 0;
                        $batch_counter = 0;
                        $this->last_transaction_commit_queries = $executed_queries;
                    }
                    if ($result !== false) {
                        $executed_queries++;
                        $batch_counter++;
                        $current_transaction_size += strlen($query_to_run);
                    }
                    $last_stmt_type = $stmt_type;
                }
                if ($db_wrapper->in_transaction()) {
                    $db_wrapper->commit();
                }
                $deferred_indexes_map = $this->collect_deferred_indexes(
                    $restore_key_for_deferred
                );
                $index_result = ["success" => true];
                if (!empty($deferred_indexes_map)) {
                    $index_result = $this->apply_deferred_indexes(
                        $db_connection,
                        $deferred_indexes_map,
                        $restore_key_for_deferred
                    );
                }
                if (!empty($temp_tables_created)) {
                    $verification = $this->verify_temp_tables(
                        $db_connection,
                        $temp_tables_created
                    );
                    if (!$verification["valid"]) {
                        $this->cleanup_temp_tables($temp_tables_created);
                        throw new Exception(
                            __("Verification Failed: ", "optistate") .
                                $verification["message"]
                        );
                    }
                    $swap_result = $this->swap_temp_tables_to_live(
                        $db_connection,
                        $temp_tables_created
                    );
                    if (!$swap_result["success"]) {
                        throw new Exception($swap_result["message"]);
                    }
                }
                $this->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $this->main_plugin->log_entry(
                    "🏁 " .
                        sprintf(
                            __("Database Restore Completed (%s)", "optistate"),
                            $state["log_filename"]
                        ),
                    "scheduled",
                    $state["log_filename"],
                    ["queries_executed" => $executed_queries]
                );
                if (
                    isset($state["decompressed_temp_file"]) &&
                    $this->wp_filesystem->exists(
                        $state["decompressed_temp_file"]
                    )
                ) {
                    $this->wp_filesystem->delete(
                        $state["decompressed_temp_file"]
                    );
                }
                $this->process_store->delete(
                    "optistate_instant_rollback_tables"
                );
                $this->cleanup_old_tables_after_restore();
                $this->close_restore_db();
                $this->clear_trash_table_after_restore();
                $total_time = time() - $state["start_time"];
                $message = sprintf(
                    __(
                        'Database restored successfully!<br>Completion time: %1$s seconds.<br>Restored tables: %2$s.<br>',
                        "optistate"
                    ),
                    number_format_i18n($total_time),
                    number_format_i18n(count($temp_tables_created))
                );
                if (!empty($index_result["warnings"])) {
                    $message .= sprintf(
                        __(
                            "Warning: %s. See the error log for details.<br>",
                            "optistate"
                        ),
                        $index_result["warnings"]
                    );
                }
                update_option(
                    "optistate_restore_completed",
                    [
                        "timestamp" => time(),
                        "filename" => $state["log_filename"],
                        "queries" => $executed_queries,
                        "tables" => count($temp_tables_created),
                        "duration" => $total_time,
                    ],
                    false
                );
                if ($is_gzipped) {
                    gzclose($handle);
                } else {
                    fclose($handle);
                }
                return [
                    "status" => "done",
                    "state" => $state,
                    "message" => $message,
                ];
            } catch (Exception $e) {
                $this->cleanup_temp_tables_on_failure(
                    $temp_tables_created ?? []
                );
                $db_wrapper_cleanup = OPTISTATE_DB_Wrapper::get_instance();
                if ($db_wrapper_cleanup->in_transaction()) {
                    $db_wrapper_cleanup->rollback();
                }
                $state["temp_tables_created"] = $temp_tables_created;
                $this->close_restore_db();
                throw $e;
            }
        } finally {
            if (isset($handle) && is_resource($handle)) {
                if ($is_gzipped) {
                    @gzclose($handle);
                } else {
                    @fclose($handle);
                }
            }
            if ($original_time_limit > 0) {
                OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
            }
        }
    }

    private function cleanup_temp_tables_on_failure(
        array $temp_tables_created
    ): void {
        try {
            global $wpdb;
            $query = $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE 'optistate_temp_%%'",
                DB_NAME
            );
            $stray_tables = $wpdb->get_col($query);
            $db = OPTISTATE_DB_Wrapper::get_instance()->get_connection();
            OPTISTATE_Utils::without_foreign_key_checks(function () use (
                $db,
                $stray_tables
            ) {
                $tables_to_drop = [];
                if ($stray_tables && count($stray_tables) > 0) {
                    foreach ($stray_tables as $table) {
                        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                            continue;
                        }
                        $tables_to_drop[] = OPTISTATE_Utils::escape_identifier(
                            $table
                        );
                    }
                }
                if (!empty($tables_to_drop)) {
                    $batches = array_chunk($tables_to_drop, 10);
                    foreach ($batches as $batch) {
                        $db->query(
                            "DROP TABLE IF EXISTS " . implode(", ", $batch)
                        );
                    }
                }
            }, $db);
        } catch (Exception $cleanup_ex) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to clean temp tables on failure: " .
                    $cleanup_ex->getMessage(),
                []
            );
        }
    }

    private function collect_deferred_indexes(string $restore_key): array
    {
        $deferred_indexes = [];
        $tables_with_deferred = $this->process_store->get($restore_key . "_deferred_tables") ?: [];

        if (empty($tables_with_deferred)) {
            return [];
        }

        $keys = [];
        foreach ($tables_with_deferred as $table_name) {
            $keys[] = $restore_key . "_deferred_" . md5($table_name);
        }

        $all_data = $this->process_store->get_multiple($keys);

        foreach ($tables_with_deferred as $table_name) {
            $deferred_key = $restore_key . "_deferred_" . md5($table_name);
            if (isset($all_data[$deferred_key]["queries"])) {
                $deferred_indexes[$table_name] = $all_data[$deferred_key]["queries"];
            }
            $this->process_store->delete($deferred_key);
        }

        $this->process_store->delete($restore_key . "_deferred_tables");

        return $deferred_indexes;
    }

private function apply_deferred_indexes(
        $db,
        array $deferred_indexes,
        string $restore_key
    ): array {
        if (empty($deferred_indexes)) {
            return ["success" => true];
        }
        $failed_indexes = [];
        foreach ($deferred_indexes as $temp_table => $queries) {
            foreach ($queries as $query) {
                $result = $db->query($query);
                if ($result === false) {
                    $error = $db->error;
                    $is_ignorable =
                        strpos($error, "Duplicate key name") !== false ||
                        strpos($error, "already exists") !== false;
                    if (!$is_ignorable) {
                        $failed_indexes[] = [
                            "table" => $temp_table,
                            "query" => $query,
                            "error" => $error,
                        ];
                        OPTISTATE_Utils::log_critical_error(
                            "Failed to apply deferred index",
                            [
                                "table" => $temp_table,
                                "error" => $error,
                                "query" => substr($query, 0, 200),
                            ]
                        );
                    }
                }
            }
        }
        if (!empty($failed_indexes)) {
            $this->process_store->set(
                $restore_key . "_failed_indexes",
                $failed_indexes,
                DAY_IN_SECONDS
            );
            return [
                "success" => true,
                "warnings" => count($failed_indexes) . " indexes failed",
            ];
        }
        return ["success" => true];
    }

    private function should_process_statement(
        string $trim_line,
        bool $security_disabled = false
    ): bool {
        if ($security_disabled) {
            return !empty(trim($trim_line));
        }
        if (empty($trim_line)) {
            return false;
        }
        if (preg_match("/^\s*DROP\s+(DATABASE|SCHEMA)\s+/i", $trim_line)) {
            return false;
        }
        if (
            ($trim_line[0] === "I" || $trim_line[0] === "i") &&
            stripos($trim_line, "INSERT") === 0
        ) {
            return true;
        }
        $first_char = $trim_line[0];
        if ($first_char === "-" && substr($trim_line, 0, 3) === "-- ") {
            if (
                strpos($trim_line, "-- phpMyAdmin SQL Dump") === 0 ||
                strpos($trim_line, "-- Host:") === 0 ||
                strpos($trim_line, "-- Generation Time:") === 0 ||
                strpos($trim_line, "-- Server version:") === 0 ||
                strpos($trim_line, "-- PHP Version:") === 0 ||
                strpos($trim_line, "-- Database:") === 0
            ) {
                return false;
            }
        }
        if (
            $first_char === "/" &&
            isset($trim_line[1]) &&
            $trim_line[1] === "*" &&
            isset($trim_line[2]) &&
            $trim_line[2] === "!" &&
            preg_match("/^\/\*!\d{5} SET @/", $trim_line)
        ) {
            return true;
        }
        if ($first_char === "S" || $first_char === "s") {
            if (stripos($trim_line, "SET ") === 0) {
                $upper_line = strtoupper($trim_line);
                if (
                    strpos($upper_line, "SQL_LOG_BIN") !== false ||
                    strpos($upper_line, "GLOBAL.") !== false ||
                    strpos($upper_line, "SET GLOBAL") !== false ||
                    strpos($upper_line, "GTID_NEXT") !== false ||
                    strpos($upper_line, "GTID_PURGED") !== false ||
                    strpos($upper_line, "@OLD_") !== false ||
                    strpos($upper_line, "=@OLD_") !== false ||
                    strpos($upper_line, "SET USER") !== false ||
                    strpos($upper_line, "SET ROLE") !== false ||
                    strpos($upper_line, "SET @") !== false ||
                    $upper_line === "START TRANSACTION;" ||
                    (strpos($upper_line, "USER") !== false &&
                        strpos($upper_line, "SET ") !== false)
                ) {
                    return false;
                }
            }
        }
        if ($first_char === "-" || $first_char === "/" || $first_char === "#") {
            if (substr($trim_line, 0, 3) !== "/*!") {
                return false;
            }
            $upper_trim = strtoupper($trim_line);
            if (
                strpos($upper_trim, "=@OLD_") !== false ||
                strpos($upper_trim, "SET @OLD_") !== false ||
                strpos($upper_trim, "@OLD_CHARACTER_SET") !== false ||
                strpos($upper_trim, "@OLD_COLLATION") !== false ||
                strpos($upper_trim, "SET NAMES") !== false ||
                strpos($upper_trim, "SET CHARACTER_SET") !== false
            ) {
                return false;
            }
        }
        if ($first_char === "C" || $first_char === "S" || $first_char === "U") {
            $upper_trim = strtoupper($trim_line);
            if (
                $upper_trim === "COMMIT;" ||
                $upper_trim === "START TRANSACTION;"
            ) {
                return false;
            }
            if (
                strpos($upper_trim, "CREATE DATABASE") === 0 ||
                strpos($upper_trim, "USE ") === 0
            ) {
                return false;
            }
        }
        if ($first_char === "L" || $first_char === "U") {
            $upper_6 = strtoupper(substr($trim_line, 0, 6));
            if ($upper_6 === "LOCK T" || $upper_6 === "UNLOCK") {
                return false;
            }
        }
        if ($first_char === "C") {
            $upper_query = strtoupper($trim_line);
            if (
                strpos($upper_query, "CREATE PROCEDURE") === 0 ||
                strpos($upper_query, "CREATE FUNCTION") === 0 ||
                strpos($upper_query, "CREATE TRIGGER") === 0 ||
                strpos($upper_query, "CREATE EVENT") === 0 ||
                strpos($upper_query, "CREATE DEFINER") === 0 ||
                strpos($upper_query, "/*!50003 CREATE") === 0
            ) {
                return false;
            }
        }
        return true;
    }

    private function is_ignorable_error(
        string $error_msg,
        string $stmt_type,
        string $query_to_run
    ): bool {
        return ($stmt_type === "DROP T" &&
            strpos($error_msg, "doesn't exist") !== false) ||
            ($stmt_type === "CREATE" &&
                strpos($error_msg, "already exists") !== false) ||
            strpos($error_msg, "Duplicate column") !== false ||
            strpos($query_to_run, "DISABLE KEYS") !== false ||
            strpos($query_to_run, "ENABLE KEYS") !== false ||
            strpos($error_msg, "Duplicate key name") !== false ||
            strpos($error_msg, "already has a trigger") !== false ||
            strpos($error_msg, "Duplicate procedure") !== false ||
            strpos($error_msg, "Duplicate function") !== false ||
            strpos($error_msg, "Duplicate event") !== false;
    }

    private function verify_temp_tables($db, array $temp_tables_created): array
    {
        if (empty($temp_tables_created)) {
            return [
                "valid" => false,
                "message" => esc_html__(
                    "No temporary tables were created during restore.",
                    "optistate"
                ),
            ];
        }
        $found_options_table_key = null;
        $found_posts_table_key = null;
        $found_users_table_key = null;
        foreach ($temp_tables_created as $original_table => $temp_table) {
            if (
                $found_options_table_key === null &&
                substr($original_table, -8) === "_options"
            ) {
                $found_options_table_key = $original_table;
            }
            if (
                $found_posts_table_key === null &&
                substr($original_table, -6) === "_posts"
            ) {
                $found_posts_table_key = $original_table;
            }
            if (
                $found_users_table_key === null &&
                substr($original_table, -6) === "_users"
            ) {
                $found_users_table_key = $original_table;
            }
        }
        if (
            !$found_options_table_key ||
            !$found_posts_table_key ||
            !$found_users_table_key
        ) {
            $missing = [];
            if (!$found_options_table_key) {
                $missing[] = "..._options";
            }
            if (!$found_posts_table_key) {
                $missing[] = "..._posts";
            }
            if (!$found_users_table_key) {
                $missing[] = "..._users";
            }
            return [
                "valid" => false,
                "message" => sprintf(
                    __(
                        "<br>Required WordPress core tables (%s) were not found in the backup.",
                        "optistate"
                    ),
                    implode(", ", $missing)
                ),
            ];
        }
        $core_table_keys_to_check = [
            $found_options_table_key,
            $found_posts_table_key,
            $found_users_table_key,
        ];
        global $wpdb;
        $temp_table_names = [];
        foreach ($core_table_keys_to_check as $core_table_key) {
            $temp_table_name = $temp_tables_created[$core_table_key];
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp_table_name)) {
                return [
                    "valid" => false,
                    "message" =>
                        "Security Warning: Invalid table name format detected.",
                ];
            }
            $temp_table_names[$core_table_key] = $temp_table_name;
        }
        $placeholders = implode(
            ",",
            array_fill(0, count($temp_table_names), "%s")
        );
        $existence_query = $wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ($placeholders)",
            array_merge([DB_NAME], array_values($temp_table_names))
        );
        $existing_tables = array_flip((array) $wpdb->get_col($existence_query));
        foreach ($core_table_keys_to_check as $core_table_key) {
            $temp_table_name = $temp_table_names[$core_table_key];
            if (!isset($existing_tables[$temp_table_name])) {
                return [
                    "valid" => false,
                    "message" => sprintf(
                        __(
                            'Temporary table %1$s (for %2$s) was not created successfully.',
                            "optistate"
                        ),
                        $temp_table_name,
                        $core_table_key
                    ),
                ];
            }
            if ($core_table_key === $found_options_table_key) {
                $has_rows = $wpdb->get_var(
                    "SELECT 1 FROM `" . esc_sql($temp_table_name) . "` LIMIT 1"
                );
                if ($has_rows === null) {
                    return [
                        "valid" => false,
                        "message" => sprintf(
                            __(
                                "Temporary table %s is empty. Restore may be corrupted.",
                                "optistate"
                            ),
                            $temp_table_name
                        ),
                    ];
                }
            }
        }
        $temp_options_table = $temp_tables_created[$found_options_table_key];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp_options_table)) {
            return [
                "valid" => false,
                "message" => "Invalid options table name format.",
            ];
        }
        $siteurl_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `" .
                    esc_sql($temp_options_table) .
                    "` WHERE option_name = %s",
                "siteurl"
            )
        );
        if (!$siteurl_exists) {
            return [
                "valid" => false,
                "message" => esc_html__(
                    "Critical WordPress option 'siteurl' not found in restored data.",
                    "optistate"
                ),
            ];
        }
        $columns_result = $db->query(
            "SHOW COLUMNS FROM `" . esc_sql($temp_options_table) . "`"
        );
        $columns = [];
        if ($columns_result) {
            while ($row = $columns_result->fetch_assoc()) {
                $columns[] = $row["Field"];
            }
            $columns_result->free();
        }
        $required_columns = [
            "option_id",
            "option_name",
            "option_value",
            "autoload",
        ];
        $missing_columns = array_diff($required_columns, $columns);
        if (!empty($missing_columns)) {
            return [
                "valid" => false,
                "message" => sprintf(
                    __(
                        "Critical table structure invalid. Missing columns in options table: %s",
                        "optistate"
                    ),
                    implode(", ", $missing_columns)
                ),
            ];
        }
        $keys_result = $db->query(
            "SHOW KEYS FROM `" .
                esc_sql($temp_options_table) .
                "` WHERE Key_name = 'PRIMARY'"
        );
        $has_primary_key = false;
        if ($keys_result) {
            $has_primary_key = $keys_result->num_rows > 0;
            $keys_result->free();
        }
        if (!$has_primary_key) {
            return [
                "valid" => false,
                "message" => __(
                    "Critical table missing primary key. Backup may be corrupted.",
                    "optistate"
                ),
            ];
        }
        return [
            "valid" => true,
            "message" => sprintf(
                __(
                    "All %s temporary tables verified successfully.",
                    "optistate"
                ),
                number_format_i18n(count($temp_tables_created))
            ),
        ];
    }

    private function ensure_connection_alive(): void
    {
        $db = OPTISTATE_DB_Wrapper::get_instance();
        $connection = $db->get_connection();
        $ping = @$connection->query("SELECT 1");
        if ($ping === false) {
            throw new Exception(
                __(
                    "Database connection lost before critical operation. Restore aborted to prevent data corruption.",
                    "optistate"
                )
            );
        }
        if ($ping instanceof mysqli_result) {
            $ping->free();
        }
    }

    private function swap_temp_tables_to_live(
        $db,
        array $temp_tables_created
    ): array {
        if (empty($temp_tables_created)) {
            return [
                "success" => false,
                "message" => esc_html__("No tables to swap.", "optistate"),
            ];
        }
        return OPTISTATE_Utils::with_session_vars(
            [
                "FOREIGN_KEY_CHECKS" => 0,
                "UNIQUE_CHECKS" => 0,
                "AUTOCOMMIT" => 0,
            ],
            function () use ($db, $temp_tables_created) {
                global $wpdb;
                $old_prefix = "optistate_old_";
                $old_table_map = [];
                foreach (
                    $temp_tables_created
                    as $original_table => $temp_table_name
                ) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $original_table)) {
                        return [
                            "success" => false,
                            "message" => __(
                                "Security validation failed: Invalid original table name format.",
                                "optistate"
                            ),
                        ];
                    }
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp_table_name)) {
                        return [
                            "success" => false,
                            "message" => __(
                                "Security validation failed: Invalid temporary table name format.",
                                "optistate"
                            ),
                        ];
                    }
                    $old_table = OPTISTATE_Utils::generate_safe_table_name(
                        $original_table,
                        $old_prefix,
                        64
                    );
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $old_table)) {
                        return [
                            "success" => false,
                            "message" => __(
                                "Security validation failed: Generated backup table name is invalid.",
                                "optistate"
                            ),
                        ];
                    }
                    $old_table_map[$original_table] = $old_table;
                }
                $original_tables = array_keys($temp_tables_created);
                $placeholders = implode(
                    ",",
                    array_fill(0, count($original_tables), "%s")
                );
                $existence_query = $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ($placeholders)",
                    array_merge([DB_NAME], $original_tables)
                );
                $existing_tables = array_flip(
                    (array) $wpdb->get_col($existence_query)
                );
                $temp_table_names = array_values($temp_tables_created);
                $temp_placeholders = implode(
                    ",",
                    array_fill(0, count($temp_table_names), "%s")
                );
                $temp_existence_query = $wpdb->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ($temp_placeholders)",
                    array_merge([DB_NAME], $temp_table_names)
                );
                $existing_temp_tables = array_flip(
                    (array) $wpdb->get_col($temp_existence_query)
                );
                $all_renames = [];
                $tables_to_cleanup = [];
                $instant_rollback_tables = [];
                foreach (
                    $temp_tables_created
                    as $original_table => $temp_table_name
                ) {
                    if (!isset($existing_temp_tables[$temp_table_name])) {
                        continue;
                    }
                    $live_table = $original_table;
                    $old_table = $old_table_map[$original_table];
                    if (isset($existing_tables[$live_table])) {
                        $all_renames[] = sprintf(
                            "%s TO %s",
                            OPTISTATE_Utils::escape_identifier($live_table),
                            OPTISTATE_Utils::escape_identifier($old_table)
                        );
                        $tables_to_cleanup[] = $old_table;
                        $instant_rollback_tables[$original_table] = $old_table;
                    }
                    $all_renames[] = sprintf(
                        "%s TO %s",
                        OPTISTATE_Utils::escape_identifier($temp_table_name),
                        OPTISTATE_Utils::escape_identifier($live_table)
                    );
                }
                if (empty($all_renames)) {
                    return [
                        "success" => false,
                        "message" => __(
                            "No valid rename operations generated. Restore integrity failed.",
                            "optistate"
                        ),
                    ];
                }
                $this->process_store->set(
                    "optistate_instant_rollback_tables",
                    $instant_rollback_tables,
                    2 * HOUR_IN_SECONDS
                );
                $this->ensure_connection_alive();
                $rename_query = "RENAME TABLE " . implode(", ", $all_renames);
                $result = $db->query($rename_query);
                if ($result === false) {
                    throw new Exception(
                        "Atomic RENAME TABLE failed: " .
                            ($db instanceof OPTISTATE_DB_Wrapper
                                ? $db->get_error()
                                : $db->error)
                    );
                }
                if (class_exists("OPTISTATE_Utils")) {
                    OPTISTATE_Utils::invalidate_table_cache();
                }
                $this->ensure_connection_alive();
                $fk_check = $this->verify_foreign_keys_after_swap(
                    $db,
                    array_keys($temp_tables_created)
                );
                if (!$fk_check["success"]) {
                    throw new Exception(
                        __(
                            "Post-swap foreign-key verification failed: ",
                            "optistate"
                        ) . $fk_check["message"]
                    );
                }
                return [
                    "success" => true,
                    "message" => sprintf(
                        __(
                            "Successfully swapped %d table(s) atomically.",
                            "optistate"
                        ),
                        count($instant_rollback_tables)
                    ),
                    "cleaned_up" => [],
                    "fk_report" => $fk_check,
                ];
            },
            $db
        );
    }

    private function verify_foreign_keys_after_swap(
        $db,
        array $swapped_tables
    ): array {
        if (empty($swapped_tables)) {
            return ["success" => true, "message" => "no tables"];
        }
        global $wpdb;
        $placeholders = implode(
            ",",
            array_fill(0, count($swapped_tables), "%s")
        );
        $sql = $wpdb->prepare(
            "SELECT kcu.TABLE_NAME AS child_table, kcu.COLUMN_NAME AS child_column, kcu.REFERENCED_TABLE_NAME AS parent_table, kcu.REFERENCED_COLUMN_NAME AS parent_column, kcu.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE kcu WHERE kcu.TABLE_SCHEMA = %s AND kcu.REFERENCED_TABLE_SCHEMA = %s AND (kcu.REFERENCED_TABLE_NAME IN ($placeholders) OR kcu.TABLE_NAME IN ($placeholders))",
            array_merge([DB_NAME, DB_NAME], $swapped_tables, $swapped_tables)
        );
        $fks = $wpdb->get_results($sql, ARRAY_A);
        if (empty($fks)) {
            return [
                "success" => true,
                "message" => "no foreign keys touch the swapped tables",
            ];
        }
        $db->query("SET SESSION FOREIGN_KEY_CHECKS = 1");
        $orphans = [];
        foreach ($fks as $fk) {
            $child_q = OPTISTATE_Utils::escape_identifier($fk["child_table"]);
            $child_c = OPTISTATE_Utils::escape_identifier($fk["child_column"]);
            $parent_q = OPTISTATE_Utils::escape_identifier($fk["parent_table"]);
            $parent_c = OPTISTATE_Utils::escape_identifier(
                $fk["parent_column"]
            );
            $probe = $wpdb->get_var(
                "SELECT c.$child_c FROM $child_q c LEFT JOIN $parent_q p ON p.$parent_c = c.$child_c WHERE c.$child_c IS NOT NULL AND p.$parent_c IS NULL LIMIT 1"
            );
            if ($probe !== null) {
                $orphans[] = sprintf(
                    "%s.%s → %s.%s (constraint %s)",
                    $fk["child_table"],
                    $fk["child_column"],
                    $fk["parent_table"],
                    $fk["parent_column"],
                    $fk["CONSTRAINT_NAME"]
                );
            }
        }
        if (!empty($orphans)) {
            return [
                "success" => false,
                "message" => sprintf(
                    __("Orphaned FK references detected: %s", "optistate"),
                    implode("; ", array_slice($orphans, 0, 5))
                ),
                "orphans" => $orphans,
            ];
        }
        return [
            "success" => true,
            "message" => sprintf("verified %d FK(s)", count($fks)),
        ];
    }

    private function normalize_restore_create_statement(
        string $create_statement
    ): string {
        global $wpdb;
        return OPTISTATE_Backup_Utilities::normalize_create_table(
            $create_statement,
            true,
            true,
            $wpdb->db_version()
        );
    }

    private function configure_db_session(
        $db,
        ?array $charset_check = null,
        ?array $backup_charset_info = null
    ): void {
        $db_wrapper = OPTISTATE_DB_Wrapper::get_instance();
        if ($charset_check && isset($charset_check["action"])) {
            if (
                $charset_check["action"] === "use_backup_charset" &&
                $backup_charset_info
            ) {
                $charset = $backup_charset_info["charset"];
            } else {
                global $wpdb;
                $charset = $wpdb->get_var("SELECT @@character_set_database");
            }
        } else {
            $charset = defined("DB_CHARSET") ? DB_CHARSET : "utf8mb4";
        }
        $collate = defined("DB_COLLATE") ? DB_COLLATE : "";
        if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $charset)) {
            throw new Exception(
                sprintf(
                    __(
                        'Invalid charset value detected during restore session setup: "%s". Restore aborted.',
                        "optistate"
                    ),
                    esc_html($charset)
                )
            );
        }
        if (
            !empty($collate) &&
            !preg_match('/^[a-zA-Z0-9_]+$/', (string) $collate)
        ) {
            throw new Exception(
                sprintf(
                    __(
                        'Invalid collation value detected during restore session setup: "%s". Restore aborted.',
                        "optistate"
                    ),
                    esc_html($collate)
                )
            );
        }
        if ($collate) {
            $db_wrapper->set_session_state(
                "SET NAMES '{$charset}' COLLATE '{$collate}'"
            );
        } else {
            $db_wrapper->set_session_state("SET NAMES '{$charset}'");
        }
        $db_wrapper->set_session_state(
            "SET character_set_client = '{$charset}'"
        );
        $db_wrapper->set_session_state(
            "SET character_set_connection = '{$charset}'"
        );
        $db_wrapper->set_session_state(
            "SET character_set_results = '{$charset}'"
        );
        $db_wrapper->set_session_state(
            "SET character_set_server = '{$charset}'"
        );
        $db_wrapper->set_session_state(
            "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"
        );
        $db_wrapper->set_session_state(
            "SET SESSION SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'"
        );
        $db_wrapper->set_session_state("SET SESSION time_zone = '+00:00'");
        $db_wrapper->set_session_state("SET SESSION AUTOCOMMIT = 0");
        $db_wrapper->set_session_state("SET SESSION FOREIGN_KEY_CHECKS = 0");
        $db_wrapper->set_session_state("SET SESSION UNIQUE_CHECKS = 0");
    }

    private function should_decompress_before_restore(string $filepath): bool
    {
        $file_size = $this->wp_filesystem->size($filepath);
        $estimated_decompressed_size = $file_size * 5;
        $free_space = @disk_free_space(dirname($filepath));
        if ($free_space === false) {
            return false;
        }
        $required_space = $estimated_decompressed_size * 1.2;
        return $free_space >= $required_space;
    }
}