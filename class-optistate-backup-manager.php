<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Backup_Manager
{
    private OPTISTATE $main_plugin;
    private string $backup_dir;
    private int $max_backups;
    private ?object $wp_filesystem;
    private OPTISTATE_Process_Store $process_store;
    private ?OPTISTATE_Backup_Engine $backup_engine = null;
    private ?OPTISTATE_Restore_Engine $restore_engine = null;
    private static ?bool $metadata_columns_checked = null;
    public function __construct(
        OPTISTATE $main_plugin,
        int $max_backups_setting = 3,
        ?OPTISTATE_Process_Store $process_store = null
    ) {
        $this->main_plugin = $main_plugin;
        $this->wp_filesystem = $this->main_plugin->get_filesystem();
        $this->max_backups = $max_backups_setting;
        $this->process_store =
            $process_store instanceof OPTISTATE_Process_Store
                ? $process_store
                : new OPTISTATE_Process_Store();
        $upload_dir = wp_upload_dir();
        $this->backup_dir =
            trailingslashit($upload_dir["basedir"]) .
            OPTISTATE::BACKUP_DIR_NAME .
            "/";
        if (false === get_transient("optistate_backup_table_check")) {
            $this->create_backup_metadata_table();
            $this->ensure_metadata_table_columns();
            if (!$this->ensure_secure_backup_dir()) {
                add_action("admin_notices", [
                    $this,
                    "display_backup_permission_warning",
                ]);
            }
            set_transient(
                "optistate_backup_table_check",
                true,
                OPTISTATE::DIR_CHECK_TIME
            );
        }
        $this->max_backups = max(1, min(10, intval($max_backups_setting)));
        $this->backup_engine = new OPTISTATE_Backup_Engine(
            $this->main_plugin,
            $this->backup_dir,
            $this->process_store,
            $this->wp_filesystem
        );
        $this->restore_engine = new OPTISTATE_Restore_Engine(
            $this->main_plugin,
            $this->backup_dir,
            $this->process_store,
            $this->wp_filesystem
        );
        $this->register_hooks();
    }
    private function register_hooks(): void
    {
        add_action("wp_ajax_optistate_create_backup", [
            $this,
            "ajax_create_backup",
        ]);
        add_action("wp_ajax_optistate_check_backup_status", [
            $this,
            "ajax_check_backup_status",
        ]);
        add_action(
            "optistate_run_manual_backup_chunk",
            [$this, "run_manual_backup_chunk_worker"],
            10,
            1
        );
        add_action("wp_ajax_optistate_delete_backup", [
            $this,
            "ajax_delete_backup",
        ]);
        add_action("wp_ajax_optistate_restore_backup", [
            $this,
            "ajax_restore_backup",
        ]);
        add_action("wp_ajax_optistate_upload_restore_file", [
            $this,
            "ajax_upload_restore_file",
        ]);
        add_action("wp_ajax_optistate_restore_from_file", [
            $this,
            "ajax_restore_from_file",
        ]);
        add_action("wp_ajax_optistate_check_decompression_status", [
            $this,
            "ajax_check_decompression_status",
        ]);
        add_action(
            "optistate_run_decompression_chunk",
            [$this, "run_decompression_chunk_worker"],
            10,
            1
        );
        add_action("optistate_hourly_cleanup", [
            $this,
            "cleanup_old_temp_files_daily",
        ]);
        add_action("init", [$this, "schedule_daily_cleanup"]);
        add_action("init", [$this, "handle_download_backup"]);
        add_action("init", [$this, "protect_backup_directory"]);
        add_action(
            "optistate_run_rollback_cron",
            [$this, "run_rollback_cron_job"],
            10,
            1
        );
        add_action("wp_ajax_optistate_get_restore_status", [
            $this,
            "ajax_get_restore_status",
        ]);
        add_action(
            "optistate_run_safety_backup_chunk",
            [$this, "run_safety_backup_chunk_worker"],
            10,
            1
        );
        add_action(
            "optistate_run_restore_init",
            [$this, "run_restore_init_worker"],
            10,
            1
        );
        add_action(
            "optistate_run_restore_chunk",
            [$this, "run_restore_chunk_worker"],
            10,
            1
        );
        add_action("wp_ajax_optistate_check_manual_backup_on_load", [
            $this,
            "ajax_check_manual_backup_on_load",
        ]);
        add_action("admin_notices", [$this, "display_rollback_status_notice"]);
        add_action(
            "optistate_run_silent_backup_chunk",
            [$this, "run_silent_backup_chunk_worker"],
            10,
            1
        );
        add_action("wp_ajax_optistate_check_restore_status", [
            $this,
            "ajax_check_restore_status",
        ]);
    }
    public function get_backups(): array
    {
        $cache_key = "optistate_backup_list_" . DB_NAME;
        $cached = wp_cache_get($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $dirlist = $this->wp_filesystem->dirlist($this->backup_dir);
        if (empty($dirlist)) {
            return [];
        }
        global $wpdb;
        $table_name = $wpdb->prefix . "optistate_backup_metadata";
        $this->ensure_metadata_table_columns();
        $all_metadata = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT filename, created_timestamp, tables_list, uncompressed_size FROM {$table_name} WHERE database_name = %s",
                DB_NAME
            ),
            OBJECT_K
        );
        if ($all_metadata === null) {
            $all_metadata = [];
        }
        $backup_types = [];
        $log_entries = $this->main_plugin->get_optimization_log();
        if (is_array($log_entries)) {
            foreach ($log_entries as $entry) {
                if (
                    !empty($entry["backup_filename"]) &&
                    !empty($entry["type"])
                ) {
                    $backup_types[$entry["backup_filename"]] = strtoupper(
                        $entry["type"]
                    );
                }
            }
        }
        $backup_list = [];
        $download_nonce = wp_create_nonce("optistate_backup_nonce");
        foreach ($dirlist as $filename => $fileinfo) {
            if (
                $fileinfo["type"] !== "f" ||
                !preg_match('/\.sql(\.gz)?$/i', $filename)
            ) {
                continue;
            }
            $file = trailingslashit($this->backup_dir) . $filename;
            $file_timestamp = isset($all_metadata[$filename]->created_timestamp)
                ? $all_metadata[$filename]->created_timestamp
                : $fileinfo["lastmodunix"] ?? time();
            $formatted_date = OPTISTATE_Utils::format_timestamp(
                $file_timestamp
            );
            $download_url = add_query_arg(
                [
                    "action" => "optistate_backup_download",
                    "file" => rawurlencode($filename),
                    "_wpnonce" => $download_nonce,
                ],
                admin_url()
            );
            $file_size = $fileinfo["size"] ?? 0;
            $verification = $this->quick_verify_backup_status($file);
            $type = isset($backup_types[$filename])
                ? $backup_types[$filename]
                : "MANUAL";
            if (strpos($filename, "SAFETY-RESTORE-") === 0) {
                $type = "SCHEDULED";
            }
            $tables_list = [];
            if (
                isset($all_metadata[$filename]->tables_list) &&
                !empty($all_metadata[$filename]->tables_list)
            ) {
                $tables_list = json_decode(
                    $all_metadata[$filename]->tables_list,
                    true
                );
                if (!is_array($tables_list)) {
                    $tables_list = [];
                }
            }
            $uncompressed_size = isset(
                $all_metadata[$filename]->uncompressed_size
            )
                ? (int) $all_metadata[$filename]->uncompressed_size
                : 0;
            $backup_list[] = [
                "filename" => $filename,
                "date" => $formatted_date,
                "size" => size_format($file_size, 2),
                "size_bytes" => $file_size,
                "timestamp" => $file_timestamp,
                "filepath" => $file,
                "download_url" => $download_url,
                "verified" => $verification["valid"],
                "verification_message" => $verification["message"],
                "type" => $type,
                "table_count" => count($tables_list),
                "tables_list" => $tables_list,
                "uncompressed_size" => $uncompressed_size,
                "uncompressed_size_formatted" => $uncompressed_size
                    ? size_format($uncompressed_size, 2)
                    : "",
            ];
        }
        usort($backup_list, function ($a, $b) {
            return $b["timestamp"] - $a["timestamp"];
        });
        wp_cache_set($cache_key, $backup_list, 5 * MINUTE_IN_SECONDS);
        return $backup_list;
    }
    private function invalidate_backup_cache(): void
    {
        wp_cache_delete("optistate_backup_list_" . DB_NAME);
    }
    public function create_backup_silent(bool $is_scheduled = false): bool
    {
        $this->main_plugin->get_filesystem();
        if (
            !wp_doing_cron() &&
            !(defined("WP_CLI") && WP_CLI) &&
            !wp_doing_ajax()
        ) {
            if (!current_user_can("manage_options")) {
                return false;
            }
        }
        OPTISTATE_Utils::clear_table_existence_cache();
        $this->main_plugin->clear_directory_existence_cache();
        $this->ensure_required_tables_exist();
        if (!$this->ensure_secure_backup_dir()) {
            return false;
        }
        try {
            $date_part = current_time("Y-m-d");
            $filename = "BACKUP-" . $date_part . ".sql.gz";
            $extra_data = [];
            if ($is_scheduled) {
                $extra_data["is_scheduled"] = true;
            }
            $transient_key = $this->backup_engine->initiate_chunked_backup(
                $filename,
                $extra_data
            );
            wp_schedule_single_event(
                time(),
                "optistate_run_silent_backup_chunk",
                [$transient_key]
            );
            return true;
        } catch (Throwable $e) {
            $filename_to_log = isset($filename)
                ? $filename
                : "unknown_backup.sql";
            $this->main_plugin->log_entry(
                "❌ " .
                    sprintf(
                        __("Backup Failed (%s)", "optistate"),
                        $e->getMessage()
                    ),
                "scheduled",
                $filename_to_log
            );
            if (isset($transient_key)) {
                $this->process_store->delete($transient_key);
            }
            return false;
        }
    }
    public function ajax_create_backup(): void
    {
        try {
            check_ajax_referer("optistate_backup_nonce", "nonce");
            $this->main_plugin->settings_manager->check_user_access();
            OPTISTATE_Utils::clear_table_existence_cache();
            $this->main_plugin->clear_directory_existence_cache();
            $this->ensure_required_tables_exist();
            $this->clear_all_integrity_caches();
            if (!$this->ensure_secure_backup_dir()) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Backup directory is not writable. Please check file permissions.",
                        "optistate"
                    )
                );
                return;
            }
            if (!OPTISTATE_Utils::check_rate_limit("create_backup", 60)) {
                OPTISTATE_Utils::send_json_error(
                    OPTISTATE_Utils::get_rate_limit_message(false),
                    429
                );
                return;
            }
            $date_part = current_time("Y-m-d");
            $filename = "BACKUP-" . $date_part . ".sql.gz";
            $extra_data = [
                "is_manual" => true,
                "user_id" => get_current_user_id(),
            ];
            if (isset($_POST["one_click"]) && $_POST["one_click"] === "1") {
                $extra_data["log_type"] = "scheduled";
            }
            $transient_key = $this->backup_engine->initiate_chunked_backup(
                $filename,
                $extra_data
            );
            $this->process_store->set(
                "optistate_manual_backup_user_" . get_current_user_id(),
                $transient_key,
                DAY_IN_SECONDS
            );
            wp_schedule_single_event(
                time(),
                "optistate_run_manual_backup_chunk",
                [$transient_key]
            );
            OPTISTATE_Utils::send_json_success([
                "message" => __(
                    "Backup initiated... Processing in background.",
                    "optistate"
                ),
                "status" => "starting",
                "transient_key" => $transient_key,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Backup creation failed in ajax",
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "message" => $e->getMessage(),
                    "user_id" => get_current_user_id(),
                ]
            );
            OPTISTATE_Utils::send_json_error(
                __("Backup failed to start: ", "optistate") . $e->getMessage(),
                500
            );
        }
    }
    public function ajax_check_backup_status(): void
    {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $transient_key = isset($_POST["transient_key"])
            ? sanitize_text_field($_POST["transient_key"])
            : "";
        if (
            empty($transient_key) ||
            strpos($transient_key, "optistate_backup_") !== 0
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid backup session.", "optistate"),
                400,
                ["status" => "error"]
            );
        }
        try {
            $completion_data = $this->process_store->get(
                $transient_key . "_complete"
            );
            if ($completion_data !== false) {
                $this->process_store->delete($transient_key . "_complete");
                if ($completion_data["status"] === "done") {
                    $this->invalidate_backup_cache();
                    $backups = $this->get_backups();
                    OPTISTATE_Utils::send_json_success([
                        "status" => "done",
                        "message" => $completion_data["message"],
                        "backups" => $backups,
                    ]);
                } else {
                    OPTISTATE_Utils::send_json_error(
                        $completion_data["message"],
                        400,
                        ["status" => "error"]
                    );
                }
                return;
            }
            $state = $this->process_store->get($transient_key);
            if ($state === false) {
                OPTISTATE_Utils::send_json_error(
                    sprintf(
                        __(
                            "Backup session expired or completed.<br>If this issue persists, try deactivating and reactivating the plugin.",
                            "optistate"
                        )
                    ),
                    400,
                    ["status" => "error"]
                );
                return;
            }
            $status =
                isset($state["status"]) && $state["status"] === "compressing"
                    ? "compressing"
                    : "running";
            $message =
                $status === "compressing"
                    ? __("COMPRESSING ....", "optistate")
                    : __("BACKING UP ....", "optistate");
            OPTISTATE_Utils::send_json_success([
                "status" => $status,
                "message" => $message,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_check_backup_status failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while checking backup status.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_restore_backup(): void
    {
        try {
            check_ajax_referer("optistate_backup_nonce", "nonce");
            $this->main_plugin->settings_manager->check_user_access();
            $this->ensure_required_tables_exist();
            if (is_multisite()) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "🛑 Safety Stop! Database restore is not supported on Multisite installations to prevent network-wide data loss.",
                        "optistate"
                    )
                );
                return;
            }
            $this->clear_all_integrity_caches();
            $step = isset($_POST["step"])
                ? sanitize_key($_POST["step"])
                : "init";
            if ($step !== "init") {
                OPTISTATE_Utils::send_json_error(
                    __("Invalid request step.", "optistate")
                );
                return;
            }
            $class_instance = $this;
            register_shutdown_function(function () use ($class_instance) {
                $error = error_get_last();
                if (
                    $error !== null &&
                    in_array(
                        $error["type"],
                        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                        true
                    )
                ) {
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    $class_instance->restore_engine->release_restore_lock();
                    $class_instance->process_store->delete(
                        "optistate_restore_in_progress"
                    );
                }
            });
            if (!$this->restore_engine->acquire_restore_lock(0)) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "A restore process is already in progress (Lock Active). Please wait.",
                        "optistate"
                    )
                );
                return;
            }
            $existing_process = $this->process_store->get(
                "optistate_restore_in_progress"
            );
            if ($existing_process !== false) {
                $this->restore_engine->release_restore_lock();
                OPTISTATE_Utils::send_json_error(
                    __(
                        "A restore process is already running. Please wait for it to complete.",
                        "optistate"
                    )
                );
                return;
            }
            $this->process_store->set(
                "optistate_restore_in_progress",
                ["status" => "init", "start_time" => time()],
                2 * HOUR_IN_SECONDS
            );
            $filename = isset($_POST["filename"])
                ? basename(sanitize_text_field(wp_unslash($_POST["filename"])))
                : "";
            $filepath = trailingslashit($this->backup_dir) . $filename;
            if (!$this->wp_filesystem->exists($filepath)) {
                throw new Exception(
                    __("Backup file not found: ", "optistate") .
                        esc_html($filename)
                );
            }
            $file_size = $this->wp_filesystem->size($filepath);
            if ($file_size < 100) {
                throw new Exception(
                    __("Backup file is too small or empty.", "optistate")
                );
            }
            $verification = OPTISTATE_Backup_Utilities::verify_backup_file(
                $this->wp_filesystem,
                $filepath,
                false
            );
            if ($verification["valid"] === false) {
                throw new Exception(
                    sprintf(
                        __("Restore Aborted: %s", "optistate"),
                        $verification["message"]
                    )
                );
            }
            if (!OPTISTATE_Utils::check_rate_limit("restore_backup", 60)) {
                throw new Exception(
                    OPTISTATE_Utils::get_rate_limit_message(false)
                );
            }
            $normalized_path = wp_normalize_path($filepath);
            $normalized_dir = wp_normalize_path($this->backup_dir);
            if (strpos($normalized_path, $normalized_dir) !== 0) {
                throw new Exception(__("Invalid file path.", "optistate"));
            }
            $button_selector =
                '.restore-backup[data-file="' . esc_attr($filename) . '"]';
            if (preg_match('/\.sql\.gz$/i', $filepath)) {
                $upload_dir = wp_upload_dir();
                $temp_dir =
                    trailingslashit($upload_dir["basedir"]) .
                    OPTISTATE::TEMP_DIR_NAME .
                    "/";
                if (!$this->wp_filesystem->is_dir($temp_dir)) {
                    if (
                        !$this->wp_filesystem->mkdir(
                            $temp_dir,
                            FS_CHMOD_DIR,
                            true
                        )
                    ) {
                        throw new Exception(
                            __(
                                "Failed to create temp directory for decompression.",
                                "optistate"
                            )
                        );
                    }
                }
                $temp_decompressed_path =
                    $temp_dir .
                    "decompressed-" .
                    bin2hex(random_bytes(14)) .
                    ".sql";
                $decompression_key =
                    "optistate_decompress_task_" . bin2hex(random_bytes(14));
                $task_data = [
                    "status" => "pending",
                    "source_path" => $filepath,
                    "dest_path" => $temp_decompressed_path,
                    "log_filename" => $filename,
                    "button_selector" => $button_selector,
                    "source_size" => $this->wp_filesystem->size($filepath),
                    "master_restore_key" => null,
                    "uploaded_file_info" => [
                        "temp_filepath_to_delete" => $temp_decompressed_path,
                    ],
                    "is_upload" => false,
                    "user_id" => get_current_user_id(),
                ];
                $this->process_store->set(
                    $decompression_key,
                    $task_data,
                    2 * HOUR_IN_SECONDS
                );
                wp_schedule_single_event(
                    time(),
                    "optistate_run_decompression_chunk",
                    [$decompression_key]
                );
                OPTISTATE_Utils::send_json_success([
                    "status" => "decompressing",
                    "decompression_key" => $decompression_key,
                    "message" => __("Decompression started...", "optistate"),
                ]);
                return;
            }
            $final_sql_path = $filepath;
            $settings = $this->main_plugin->settings_manager->get_persistent_settings();
            $security_active = empty($settings["disable_restore_security"]);
            if ($security_active) {
                $handle = @fopen($final_sql_path, "r");
                if (!$handle) {
                    throw new Exception(
                        __(
                            "Failed to open file for security scan.",
                            "optistate"
                        )
                    );
                }
                $sample = fread($handle, 32768);
                fclose($handle);
                if ($sample === false) {
                    throw new Exception(
                        __(
                            "Failed to read file for security scan.",
                            "optistate"
                        )
                    );
                }
                if (
                    preg_match(
                        '/<\?php|<\?=|<\s*\?|script\s*language\s*=\s*["\']?php["\']?|eval\s*\(|exec\s*\(|system\s*\(|passthru\s*\(|shell_exec\s*\(|base64_decode/i',
                        $sample
                    )
                ) {
                    throw new Exception(
                        __(
                            "Security risk detected. The backup file contains suspicious code.",
                            "optistate"
                        )
                    );
                }
            }
            $uploaded_file_info = ["security_disabled" => !$security_active];
            $response = $this->restore_engine->initiate_master_restore(
                $final_sql_path,
                $filename,
                $button_selector,
                $uploaded_file_info,
                get_current_user_id()
            );
            OPTISTATE_Utils::send_json_success($response);
        } catch (Throwable $e) {
            OPTISTATE_Utils::deactivate_maintenance_mode();
            $this->restore_engine->release_restore_lock();
            $this->process_store->delete("optistate_restore_in_progress");
            $this->process_store->delete("optistate_last_restore_filename");
            if (
                isset($temp_decompressed_path) &&
                $this->wp_filesystem->exists($temp_decompressed_path)
            ) {
                $this->wp_filesystem->delete($temp_decompressed_path);
            }
            $this->main_plugin->log_entry(
                "⚠️ " .
                    __("Database Restore Failed", "optistate") .
                    " + ⏪ " .
                    __("Rollback Succeeded", "optistate"),
                "scheduled",
                $filename ?? "unknown",
                ["details" => $e->getMessage()]
            );
            OPTISTATE_Utils::log_critical_error(
                "Restore initiation failed: " . $e->getMessage(),
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "user_id" => get_current_user_id(),
                ]
            );
            OPTISTATE_Utils::send_json_error(
                __("Failed to initiate restore: ", "optistate") .
                    $e->getMessage(),
                500
            );
        }
    }
    public function ajax_delete_backup(): void
    {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        try {
            $filename = isset($_POST["filename"])
                ? basename(wp_unslash($_POST["filename"]))
                : "";
            if (!preg_match('/\.sql(\.gz)?$/i', $filename)) {
                OPTISTATE_Utils::send_json_error(
                    __("Security violation: Invalid file type.", "optistate")
                );
                return;
            }
            if (
                strpos($filename, "..") !== false ||
                strpos($filename, "/") !== false ||
                strpos($filename, "\\") !== false
            ) {
                OPTISTATE_Utils::send_json_error(
                    __("Invalid filename.", "optistate")
                );
                return;
            }
            $filepath = $this->backup_dir . $filename;
            $normalized_path = wp_normalize_path($filepath);
            $normalized_dir = wp_normalize_path($this->backup_dir);
            if (strpos($normalized_path, $normalized_dir) !== 0) {
                OPTISTATE_Utils::send_json_error(
                    __("Invalid file path.", "optistate")
                );
                return;
            }
            if (!$this->wp_filesystem->exists($filepath)) {
                OPTISTATE_Utils::send_json_error(
                    __("Backup file not found.", "optistate")
                );
                return;
            }
            global $wpdb;
            $table_name = $wpdb->prefix . "optistate_backup_metadata";
            $wpdb->delete($table_name, ["filename" => $filename], ["%s"]);
            delete_transient("optistate_backup_integrity_" . md5($filename));
            $files_to_delete = [$filepath];
            $success = true;
            $errors = [];
            foreach ($files_to_delete as $file_to_delete) {
                if (
                    $this->wp_filesystem->exists($file_to_delete) &&
                    !$this->wp_filesystem->delete($file_to_delete)
                ) {
                    $success = false;
                    $errors[] = basename($file_to_delete);
                }
            }
            if ($success) {
                $this->main_plugin->log_entry(
                    "🗑️ " .
                        sprintf(
                            __(
                                "Backup Deleted by {username} (%s)",
                                "optistate"
                            ),
                            $filename
                        ),
                    "manual"
                );
                $this->invalidate_backup_cache();
                OPTISTATE_Utils::send_json_success([
                    "message" => __(
                        "Backup and all associated data deleted successfully!",
                        "optistate"
                    ),
                ]);
            } else {
                OPTISTATE_Utils::log_critical_error(
                    "Backup deletion failed for file: " . $filename,
                    ["backup_file" => $filename, "errors" => $errors]
                );
                OPTISTATE_Utils::send_json_error(
                    __("Failed to delete some files.", "optistate")
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_delete_backup failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while deleting the backup.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_check_restore_status(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        try {
            $master_restore_key = $this->process_store->get(
                "optistate_restore_in_progress"
            );
            if (
                $master_restore_key === false ||
                !is_string($master_restore_key) ||
                strpos($master_restore_key, "optistate_master_restore_") !== 0
            ) {
                $recent_restore_marker = $this->process_store->get(
                    "optistate_last_completed_restore"
                );
                if ($recent_restore_marker !== false) {
                    if (get_option("optistate_maintenance_mode_active")) {
                        OPTISTATE_Utils::deactivate_maintenance_mode();
                    }
                    OPTISTATE_Utils::send_json_success([
                        "status" => "completed_recently",
                        "message" => __(
                            "Restore completed successfully.",
                            "optistate"
                        ),
                        "completed_at" =>
                            $recent_restore_marker["completed_at"] ?? time(),
                    ]);
                    return;
                }
                if (get_option("optistate_maintenance_mode_active")) {
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    OPTISTATE_Utils::send_json_success([
                        "status" => "stalled",
                        "message" => __(
                            "Found stuck maintenance mode. Cleared.",
                            "optistate"
                        ),
                    ]);
                    return;
                }
                OPTISTATE_Utils::send_json_success([
                    "status" => "none",
                    "message" => "No restore in progress.",
                ]);
                return;
            }
            $master_state = $this->process_store->get($master_restore_key);
            if ($master_state === false) {
                $recent_restore_marker = $this->process_store->get(
                    "optistate_last_completed_restore"
                );
                if ($recent_restore_marker !== false) {
                    if (get_option("optistate_maintenance_mode_active")) {
                        OPTISTATE_Utils::deactivate_maintenance_mode();
                    }
                    OPTISTATE_Utils::send_json_success([
                        "status" => "completed_recently",
                        "message" => __(
                            "Restore completed successfully.",
                            "optistate"
                        ),
                    ]);
                    return;
                }
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->process_store->delete("optistate_restore_in_progress");
                OPTISTATE_Utils::send_json_success([
                    "status" => "stalled",
                    "message" => __(
                        "Restore process expired. Aborting.",
                        "optistate"
                    ),
                ]);
                return;
            }
            OPTISTATE_Utils::send_json_success([
                "status" => "running",
                "master_restore_key" => $master_restore_key,
                "button_selector" => $master_state["button_selector"] ?? "",
                "message" => "Restore in progress. Resuming monitoring...",
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_check_restore_status failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while checking restore status.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_check_manual_backup_on_load(): void
    {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            OPTISTATE_Utils::send_json_success(["status" => "none"]);
            return;
        }
        try {
            $transient_key = $this->process_store->get(
                "optistate_manual_backup_user_" . $user_id
            );
            if (
                empty($transient_key) ||
                strpos($transient_key, "optistate_backup_") !== 0
            ) {
                OPTISTATE_Utils::send_json_success(["status" => "none"]);
                return;
            }
            $state = $this->process_store->get($transient_key);
            if ($state === false) {
                $this->process_store->delete(
                    "optistate_manual_backup_user_" . $user_id
                );
                OPTISTATE_Utils::send_json_success(["status" => "stalled"]);
                return;
            }
            OPTISTATE_Utils::send_json_success([
                "status" => "running",
                "transient_key" => $transient_key,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_check_manual_backup_on_load failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_success(["status" => "error"]);
        }
    }
    public function run_manual_backup_chunk_worker(string $transient_key): void
    {
        $this->execute_backup_worker(
            $transient_key,
            false,
            "optistate_run_manual_backup_chunk",
            "manual",
            false,
            $transient_key . "_complete"
        );
    }
    public function run_silent_backup_chunk_worker(string $transient_key): void
    {
        $this->execute_backup_worker(
            $transient_key,
            true,
            "optistate_run_silent_backup_chunk",
            "scheduled",
            true,
            ""
        );
    }
    private function execute_backup_worker(
        string $transient_key,
        bool $is_silent,
        string $schedule_hook,
        string $log_type,
        bool $is_scheduled = false,
        string $completion_key = ""
    ): void {
        wp_raise_memory_limit("admin");
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(240);
        if (
            empty($transient_key) ||
            strpos($transient_key, "optistate_backup_") !== 0
        ) {
            return;
        }
        if (connection_aborted()) {
            $this->cleanup_failed_backup($transient_key, "Connection aborted");
            return;
        }
        $initial_state = $this->process_store->get($transient_key);
        if ($initial_state === false) {
            return;
        }
        if (
            isset($initial_state["log_type"]) &&
            !empty($initial_state["log_type"])
        ) {
            $log_type = $initial_state["log_type"];
        }
        $result = $this->backup_engine->process_chunk(
            $transient_key,
            $is_silent
        );
        if ($result["status"] === "error") {
            return;
        }
        if ($result["status"] === "skipped") {
            wp_schedule_single_event(time() + 5, $schedule_hook, [
                $transient_key,
            ]);
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
            return;
        }
        if ($result["status"] === "done") {
            $updated_state = $result["state"];
            $updated_state["status"] = "compressing";
            $this->process_store->set(
                $transient_key,
                $updated_state,
                DAY_IN_SECONDS
            );
            $this->enforce_backup_limit();
            $this->save_backup_metadata(
                $updated_state["filepath"],
                $updated_state["filename"],
                $updated_state["start_time"],
                $updated_state["tables_list"] ?? [],
                $updated_state["uncompressed_size"] ?? 0
            );
            $operation_text = $is_scheduled
                ? sprintf(
                    __("Scheduled Backup Created (%s)", "optistate"),
                    $updated_state["filename"]
                )
                : sprintf(
                    __("Backup Created by {username} (%s)", "optistate"),
                    $updated_state["filename"]
                );
            $this->main_plugin->log_entry(
                "💾 " . $operation_text,
                $log_type,
                $updated_state["filename"],
                ["user_id" => $updated_state["user_id"] ?? null]
            );
            $this->process_store->delete($transient_key);
            if (!empty($updated_state["user_id"])) {
                $this->process_store->delete(
                    "optistate_manual_backup_user_" . $updated_state["user_id"]
                );
            }
            if ($is_scheduled) {
                do_action(
                    "optistate_async_backup_complete",
                    $updated_state["filename"]
                );
            } else {
                $complete_key = $completion_key ?: $transient_key . "_complete";
                $this->process_store->set(
                    $complete_key,
                    [
                        "status" => "done",
                        "filename" => $updated_state["filename"],
                        "message" => __(
                            "Backup created successfully!",
                            "optistate"
                        ),
                    ],
                    300
                );
            }
            $this->invalidate_backup_cache();
        } else {
            $this->process_store->set(
                $transient_key,
                $result["state"],
                DAY_IN_SECONDS
            );
            wp_schedule_single_event(
                time() + $result["reschedule_delay"],
                $schedule_hook,
                [$transient_key]
            );
        }
        OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
    }
    public function run_safety_backup_chunk_worker(
        string $master_restore_key
    ): void {
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(240);
        if (!$this->restore_engine->acquire_restore_lock(5)) {
            wp_schedule_single_event(
                time() + 5,
                "optistate_run_safety_backup_chunk",
                [$master_restore_key]
            );
            return;
        }
        $lock_acquired = true;
        try {
            if (
                empty($master_restore_key) ||
                strpos($master_restore_key, "optistate_master_restore_") !== 0
            ) {
                return;
            }
            $master_state = $this->process_store->get($master_restore_key);
            if (
                $master_state === false ||
                !isset($master_state["safety_backup_key"])
            ) {
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $lock_acquired = false;
                return;
            }
            $safety_backup_key = $master_state["safety_backup_key"];
            $safety_state = $this->process_store->get($safety_backup_key);
            if ($safety_state === false) {
                $master_state["status"] = "error";
                $master_state["message"] = __(
                    "Safety backup session expired.",
                    "optistate"
                );
                $this->process_store->set(
                    $master_restore_key,
                    $master_state,
                    10 * MINUTE_IN_SECONDS
                );
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $lock_acquired = false;
                return;
            }
            $result = $this->backup_engine->process_chunk(
                $safety_backup_key,
                true
            );
            if ($result["status"] === "error") {
                $master_state["status"] = "error";
                $master_state["message"] =
                    __("Safety backup failed: ", "optistate") .
                    $result["message"];
                $this->process_store->set(
                    $master_restore_key,
                    $master_state,
                    10 * MINUTE_IN_SECONDS
                );
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $this->process_store->delete("optistate_last_restore_filename");
                $this->process_store->delete("optistate_safety_backup");
                $lock_acquired = false;
                return;
            }
            if ($result["status"] === "done") {
                $safety_filepath = $result["state"]["filepath"] ?? "";
                $safety_filename =
                    $result["state"]["filename"] ?? basename($safety_filepath);
                $verification = OPTISTATE_Backup_Utilities::verify_backup_file(
                    $this->wp_filesystem,
                    $safety_filepath,
                    true,
                    true,
                    true
                );
                if (empty($verification["valid"])) {
                    $reason =
                        isset($verification["message"]) &&
                        $verification["message"] !== ""
                            ? $verification["message"]
                            : __("unknown verification failure", "optistate");
                    $this->main_plugin->log_entry(
                        "❌ " .
                            sprintf(
                                __(
                                    "Safety backup verification failed (%s): %s",
                                    "optistate"
                                ),
                                $safety_filename,
                                $reason
                            ),
                        "scheduled",
                        $safety_filename
                    );
                    OPTISTATE_Utils::log_critical_error(
                        "Safety backup deep-verification failed — restore aborted",
                        [
                            "filepath" => $safety_filepath,
                            "filename" => $safety_filename,
                            "reason" => $reason,
                        ]
                    );
                    if (
                        $safety_filepath !== "" &&
                        $this->wp_filesystem->exists($safety_filepath)
                    ) {
                        $this->wp_filesystem->delete($safety_filepath);
                    }
                    delete_transient(
                        "optistate_backup_integrity_" . md5($safety_filename)
                    );
                    $this->process_store->delete($safety_backup_key);
                    if (!empty($result["state"]["user_id"])) {
                        $this->process_store->delete(
                            "optistate_manual_backup_user_" .
                                $result["state"]["user_id"]
                        );
                    }
                    $master_state["status"] = "error";
                    $master_state["message"] =
                        __("Safety backup verification failed: ", "optistate") .
                        $reason;
                    $this->process_store->set(
                        $master_restore_key,
                        $master_state,
                        10 * MINUTE_IN_SECONDS
                    );
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    $this->restore_engine->release_restore_lock();
                    $this->process_store->delete(
                        "optistate_restore_in_progress"
                    );
                    $this->process_store->delete(
                        "optistate_last_restore_filename"
                    );
                    $this->process_store->delete("optistate_safety_backup");
                    $lock_acquired = false;
                    return;
                }
                $this->process_store->delete($safety_backup_key);
                $this->enforce_backup_limit();
                $this->save_backup_metadata(
                    $result["state"]["filepath"],
                    $result["state"]["filename"],
                    $result["state"]["start_time"],
                    $result["state"]["tables_list"] ?? [],
                    $result["state"]["uncompressed_size"] ?? 0
                );
                $this->main_plugin->log_entry(
                    "💾 " .
                        sprintf(
                            __("Scheduled Backup Created (%s)", "optistate"),
                            $result["state"]["filename"]
                        ),
                    "scheduled",
                    $result["state"]["filename"]
                );
                if (!empty($result["state"]["user_id"])) {
                    $this->process_store->delete(
                        "optistate_manual_backup_user_" .
                            $result["state"]["user_id"]
                    );
                }
                $master_state["status"] = "restore_starting";
                $master_state["message"] = __(
                    "SAFETY BACKUP COMPLETE ....",
                    "optistate"
                );
                $this->process_store->set(
                    $master_restore_key,
                    $master_state,
                    2 * HOUR_IN_SECONDS
                );
                wp_schedule_single_event(time(), "optistate_run_restore_init", [
                    $master_restore_key,
                ]);
            } elseif ($result["status"] === "running") {
                $this->process_store->set(
                    $safety_backup_key,
                    $result["state"],
                    DAY_IN_SECONDS
                );
                $master_state["status"] = "safety_backup_running";
                $master_state["message"] = sprintf(
                    __("CREATING SAFETY BACKUP ....", "optistate")
                );
                $this->process_store->set(
                    $master_restore_key,
                    $master_state,
                    2 * HOUR_IN_SECONDS
                );
                wp_schedule_single_event(
                    time() + $result["reschedule_delay"],
                    "optistate_run_safety_backup_chunk",
                    [$master_restore_key]
                );
            } elseif ($result["status"] === "skipped") {
                wp_schedule_single_event(
                    time() + 5,
                    "optistate_run_safety_backup_chunk",
                    [$master_restore_key]
                );
            }
            $this->invalidate_backup_cache();
        } catch (Throwable $e) {
            $filename_to_log = isset($safety_state["filename"])
                ? $safety_state["filename"]
                : "unknown_safety_backup.sql";
            $this->main_plugin->log_entry(
                "❌ " .
                    sprintf(
                        __("Backup Failed (%s)", "optistate"),
                        $e->getMessage()
                    ),
                "scheduled",
                $filename_to_log,
                ["user_id" => $safety_state["user_id"] ?? null]
            );
            OPTISTATE_Utils::log_critical_error(
                "Safety backup failed: " . $e->getMessage(),
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "user_id" => $safety_state["user_id"] ?? null,
                    "backup_file" => $filename_to_log,
                ]
            );
            $this->process_store->delete($safety_backup_key);
            if (!empty($safety_state["user_id"])) {
                $this->process_store->delete(
                    "optistate_manual_backup_user_" . $safety_state["user_id"]
                );
            }
            if (
                $safety_state &&
                isset($safety_state["filepath"]) &&
                $this->wp_filesystem->exists($safety_state["filepath"])
            ) {
                $this->wp_filesystem->delete($safety_state["filepath"]);
            }
            $master_state["status"] = "error";
            $master_state["message"] =
                __("Safety backup failed: ", "optistate") . $e->getMessage();
            $this->process_store->set(
                $master_restore_key,
                $master_state,
                10 * MINUTE_IN_SECONDS
            );
            OPTISTATE_Utils::deactivate_maintenance_mode();
            $this->restore_engine->release_restore_lock();
            $this->process_store->delete("optistate_restore_in_progress");
            $this->process_store->delete("optistate_last_restore_filename");
            $this->process_store->delete("optistate_safety_backup");
            $lock_acquired = false;
        } finally {
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
            if ($lock_acquired) {
                $this->restore_engine->release_restore_lock();
            }
        }
    }
    public function run_restore_init_worker(string $master_restore_key): void
    {
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(300);
        if (!$this->restore_engine->acquire_restore_lock(5)) {
            wp_schedule_single_event(time() + 5, "optistate_run_restore_init", [
                $master_restore_key,
            ]);
            return;
        }
        $lock_acquired = true;
        $class_instance = $this;
        register_shutdown_function(function () use (
            $class_instance,
            $master_restore_key
        ) {
            $error = error_get_last();
            if (
                $error !== null &&
                in_array(
                    $error["type"],
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                    true
                )
            ) {
                $master_state = $class_instance->process_store->get(
                    $master_restore_key
                );
                if ($master_state) {
                    $master_state["status"] = "error";
                    $master_state["message"] =
                        __("Fatal error during restore init: ", "optistate") .
                        esc_html($error["message"]);
                    $class_instance->process_store->set(
                        $master_restore_key,
                        $master_state,
                        10 * MINUTE_IN_SECONDS
                    );
                }
                if (
                    $class_instance->process_store->get(
                        "optistate_safety_backup"
                    )
                ) {
                    $class_instance->process_store->set(
                        "optistate_last_restore_error",
                        $error["message"],
                        HOUR_IN_SECONDS
                    );
                    $class_instance->trigger_async_rollback(
                        $master_restore_key
                    );
                } else {
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    $class_instance->process_store->delete(
                        "optistate_restore_in_progress"
                    );
                }
                $class_instance->restore_engine->release_restore_lock();
            }
        });
        try {
            if (
                empty($master_restore_key) ||
                strpos($master_restore_key, "optistate_master_restore_") !== 0
            ) {
                return;
            }
            $master_state = $this->process_store->get($master_restore_key);
            if (
                $master_state === false ||
                !isset($master_state["restore_target"])
            ) {
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->process_store->delete("optistate_restore_in_progress");
                $lock_acquired = false;
                return;
            }
            $target_data = $master_state["restore_target"];
            $log_filename = "unknown_file";
            try {
                if ($target_data["type"] !== "temp_path") {
                    throw new Exception(
                        __(
                            "Invalid restore target type. Expected temp_path.",
                            "optistate"
                        )
                    );
                }
                $temp_filename = $target_data["value"];
                $temp_transient_key =
                    "optistate_temp_restore_" . $temp_filename;
                $file_info = $this->process_store->get($temp_transient_key);
                if (!$file_info || !isset($file_info["path"])) {
                    throw new Exception(
                        __("Temp file session expired or invalid.", "optistate")
                    );
                }
                $filepath = $file_info["path"];
                $log_filename = $file_info["original_name"] ?? $temp_filename;
                if (!$this->wp_filesystem->exists($filepath)) {
                    throw new Exception(
                        __("SQL file not found: ", "optistate") .
                            basename($filepath)
                    );
                }
                $uploaded_file_info = [
                    "temp_transient_to_delete" => $temp_transient_key,
                ];
                if (!empty($file_info["is_decompressed_backup"])) {
                    $uploaded_file_info["temp_filepath_to_delete"] = $filepath;
                }
                $restore_key = $this->restore_engine->initiate_chunked_restore(
                    $filepath,
                    $log_filename,
                    $uploaded_file_info
                );
                $master_state["status"] = "restore_running";
                $master_state["message"] = __(
                    "RESTORING DATABASE ....",
                    "optistate"
                );
                $master_state["restore_key"] = $restore_key;
                $this->process_store->set(
                    $master_restore_key,
                    $master_state,
                    2 * HOUR_IN_SECONDS
                );
                $this->process_store->set(
                    "optistate_current_restore_key",
                    $restore_key,
                    90 * MINUTE_IN_SECONDS
                );
                wp_schedule_single_event(
                    time(),
                    "optistate_run_restore_chunk",
                    [$master_restore_key]
                );
            } catch (Throwable $e) {
                $master_state["status"] = "error";
                $master_state["message"] =
                    __("Restore failed to start: ", "optistate") .
                    $e->getMessage();
                $this->process_store->set(
                    $master_restore_key,
                    $master_state,
                    10 * MINUTE_IN_SECONDS
                );
                OPTISTATE_Utils::log_critical_error(
                    "Restore init worker failed: " . $e->getMessage(),
                    [
                        "file" => $e->getFile(),
                        "line" => $e->getLine(),
                        "user_id" => get_current_user_id(),
                        "backup_file" => $log_filename,
                    ]
                );
                $will_rollback = (bool) $this->process_store->get(
                    "optistate_safety_backup"
                );
                $this->main_plugin->log_entry(
                    "⚠️ " .
                        __("Database Restore Failed", "optistate") .
                        ($will_rollback
                            ? " + ⏪ " . __("Rolling Back...", "optistate")
                            : ""),
                    "scheduled",
                    $log_filename,
                    ["details" => $e->getMessage()]
                );
                if ($will_rollback) {
                    $this->process_store->set(
                        "optistate_last_restore_error",
                        $e->getMessage(),
                        HOUR_IN_SECONDS
                    );
                    wp_schedule_single_event(
                        time(),
                        "optistate_run_rollback_cron",
                        [$master_restore_key]
                    );
                    $lock_acquired = false;
                } else {
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    $this->process_store->delete(
                        "optistate_restore_in_progress"
                    );
                    $this->restore_engine->release_restore_lock();
                    $lock_acquired = false;
                }
            }
        } finally {
            if ($lock_acquired) {
                $this->restore_engine->release_restore_lock();
            }
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
        }
    }
    public function run_restore_chunk_worker(string $master_restore_key): void
    {
        wp_raise_memory_limit("admin");
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(300);
        if (!$this->restore_engine->acquire_restore_lock(5)) {
            wp_schedule_single_event(
                time() + 5,
                "optistate_run_restore_chunk",
                [$master_restore_key]
            );
            return;
        }
        $lock_acquired = true;
        $class_instance = $this;
        register_shutdown_function(function () use (
            $class_instance,
            $master_restore_key
        ) {
            $error = error_get_last();
            if (
                $error !== null &&
                in_array(
                    $error["type"],
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                    true
                )
            ) {
                $fatal_state = [
                    "status" => "error",
                    "message" =>
                        __("Fatal error during restore: ", "optistate") .
                        esc_html($error["message"]),
                    "expiration" => time() + 10 * MINUTE_IN_SECONDS,
                ];
                $updated = $class_instance->process_store->atomic_update(
                    $master_restore_key,
                    function ($master_state) use ($fatal_state) {
                        if (!$master_state) {
                            return false;
                        }
                        return $fatal_state;
                    }
                );
                if ($updated === false) {
                    $fresh_store = new OPTISTATE_Process_Store();
                    $fresh_store->set($master_restore_key, $fatal_state);
                }
                set_transient(
                    "optistate_restore_error_" . $master_restore_key,
                    [
                        "message" => $fatal_state["message"],
                        "timestamp" => time(),
                    ],
                    5 * MINUTE_IN_SECONDS
                );
                if (
                    $class_instance->process_store->get(
                        "optistate_instant_rollback_tables"
                    )
                ) {
                    try {
                        $class_instance->trigger_async_rollback(
                            $master_restore_key
                        );
                    } catch (Throwable $t) {
                        OPTISTATE_Utils::deactivate_maintenance_mode();
                    }
                } else {
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    $class_instance->process_store->delete(
                        "optistate_restore_in_progress"
                    );
                    $class_instance->process_store->delete(
                        "optistate_last_restore_filename"
                    );
                }
                $class_instance->restore_engine->release_restore_lock();
            }
        });
        $restore_key = null;
        try {
            if (
                empty($master_restore_key) ||
                strpos($master_restore_key, "optistate_master_restore_") !== 0
            ) {
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $lock_acquired = false;
                return;
            }
            $master_state = $this->process_store->get($master_restore_key);
            if (
                $master_state === false ||
                !isset($master_state["restore_key"])
            ) {
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $lock_acquired = false;
                return;
            }
            $restore_key = $master_state["restore_key"];
            $result = $this->restore_engine->process_restore_chunk(
                $restore_key
            );
            if ($result["status"] === "done") {
                $this->process_store->delete($restore_key);
                if (!empty($result["state"]["user_id"])) {
                    $this->process_store->delete(
                        "optistate_manual_backup_user_" .
                            $result["state"]["user_id"]
                    );
                }
                $settings = $this->main_plugin->settings_manager->get_persistent_settings();
                $two_factor_was_enabled = !empty(
                    $settings["enable_two_factor"]
                );
                $this->main_plugin->settings_manager->save_persistent_settings([
                    "enable_two_factor" => false,
                ]);
                $message_suffix = "";
                if ($two_factor_was_enabled) {
                    $this->main_plugin->log_entry(
                        "🔑 " .
                            __(
                                "Two-Factor Authentication automatically disabled after database restore",
                                "optistate"
                            ),
                        "scheduled"
                    );
                    $message_suffix =
                        " " .
                        __(
                            "Two-Factor Authentication has been automatically disabled for safety.<br>",
                            "optistate"
                        );
                }
                $this->process_store->atomic_update(
                    $master_restore_key,
                    function ($current_master_state) use (
                        $result,
                        $message_suffix
                    ) {
                        if (!$current_master_state) {
                            return false;
                        }
                        $current_master_state["status"] = "done";
                        $current_master_state["message"] =
                            $result["message"] . $message_suffix;
                        $current_master_state["expiration"] =
                            time() + 10 * MINUTE_IN_SECONDS;
                        return $current_master_state;
                    }
                );
                $this->process_store->set(
                    "optistate_last_completed_restore",
                    [
                        "master_restore_key" => $master_restore_key,
                        "completed_at" => time(),
                        "status" => "done",
                    ],
                    60
                );
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $this->invalidate_backup_cache();
                $lock_acquired = false;
            } else {
                $this->process_store->set(
                    $restore_key,
                    $result["state"],
                    DAY_IN_SECONDS
                );
                $this->process_store->atomic_update(
                    $master_restore_key,
                    function ($current_master_state) {
                        if (!$current_master_state) {
                            return false;
                        }
                        $current_master_state["status"] = "restore_running";
                        $current_master_state["message"] = sprintf(
                            __("RESTORING DATABASE ....", "optistate")
                        );
                        $current_master_state["expiration"] =
                            time() + 2 * HOUR_IN_SECONDS;
                        return $current_master_state;
                    }
                );
                wp_schedule_single_event(
                    time(),
                    "optistate_run_restore_chunk",
                    [$master_restore_key]
                );
            }
        } catch (Throwable $e) {
            $error_message = $e->getMessage();
            if (isset($restore_key)) {
                $restore_state = $this->process_store->get($restore_key);
                $log_filename =
                    $restore_state["log_filename"] ?? "unknown_restore";
            } else {
                $restore_state = false;
                $log_filename = "unknown_restore";
            }
            OPTISTATE_Utils::log_critical_error(
                "Restore chunk failed: " . $error_message,
                [
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                    "user_id" => $restore_state["user_id"] ?? null,
                    "backup_file" => $log_filename,
                ]
            );
            if ($restore_state) {
                if (
                    !empty(
                        $restore_state["uploaded_file_info"][
                            "temp_filepath_to_delete"
                        ]
                    )
                ) {
                    $temp_file = basename(
                        $restore_state["uploaded_file_info"][
                            "temp_filepath_to_delete"
                        ]
                    );
                    $this->cleanup_all_temp_sql_files($temp_file);
                }
                if (
                    !empty(
                        $restore_state["uploaded_file_info"][
                            "temp_transient_to_delete"
                        ]
                    )
                ) {
                    $this->process_store->delete(
                        $restore_state["uploaded_file_info"][
                            "temp_transient_to_delete"
                        ]
                    );
                }
            }
            $this->cleanup_all_temp_sql_files();
            if (
                $this->process_store->get("optistate_instant_rollback_tables")
            ) {
                wp_schedule_single_event(
                    time(),
                    "optistate_run_rollback_cron",
                    [$master_restore_key]
                );
                $rollback_state = [
                    "status" => "rollback_starting",
                    "message" => __(
                        "Restore failed! Rolling back...",
                        "optistate"
                    ),
                    "expiration" => time() + 10 * MINUTE_IN_SECONDS,
                ];
                $updated = $this->process_store->atomic_update(
                    $master_restore_key,
                    function ($current_master_state) use ($rollback_state) {
                        if (!$current_master_state) {
                            return false;
                        }
                        return $rollback_state;
                    }
                );
                if ($updated === false) {
                    $fresh_store = new OPTISTATE_Process_Store();
                    $fresh_store->set($master_restore_key, $rollback_state);
                }
                set_transient(
                    "optistate_restore_error_" . $master_restore_key,
                    [
                        "message" => $rollback_state["message"],
                        "timestamp" => time(),
                    ],
                    5 * MINUTE_IN_SECONDS
                );
                $this->main_plugin->log_entry(
                    "⚠️ " .
                        __("Database Restore Failed", "optistate") .
                        " + ⏪ " .
                        __("Rolling Back...", "optistate"),
                    "scheduled",
                    $log_filename,
                    ["details" => $error_message]
                );
                $this->restore_engine->close_restore_db();
                if (isset($restore_key)) {
                    $this->process_store->delete($restore_key);
                }
                $this->process_store->delete("optistate_current_restore_key");
                $lock_acquired = false;
            } else {
                OPTISTATE_Utils::deactivate_maintenance_mode();
                if (
                    $restore_state &&
                    isset($restore_state["temp_tables_created"]) &&
                    !empty($restore_state["temp_tables_created"])
                ) {
                    try {
                        $this->restore_engine->cleanup_temp_tables(
                            $restore_state["temp_tables_created"]
                        );
                    } catch (Exception $db_e) {
                    }
                }
                $error_state = [
                    "status" => "error",
                    "message" =>
                        __("Restore failed: ", "optistate") . $error_message,
                    "expiration" => time() + 10 * MINUTE_IN_SECONDS,
                ];
                $updated = $this->process_store->atomic_update(
                    $master_restore_key,
                    function ($current_master_state) use ($error_state) {
                        if (!$current_master_state) {
                            return false;
                        }
                        return $error_state;
                    }
                );
                if ($updated === false) {
                    $fresh_store = new OPTISTATE_Process_Store();
                    $fresh_store->set($master_restore_key, $error_state);
                }
                set_transient(
                    "optistate_restore_error_" . $master_restore_key,
                    [
                        "message" => $error_state["message"],
                        "timestamp" => time(),
                    ],
                    5 * MINUTE_IN_SECONDS
                );
                $this->main_plugin->log_entry(
                    "⚠️ " .
                        __("Database Restore Failed", "optistate") .
                        " + ⏪ " .
                        __("Rollback Succeeded", "optistate"),
                    "scheduled",
                    $filename ?? "unknown",
                    ["details" => $e->getMessage()]
                );
                $this->restore_engine->close_restore_db();
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $this->process_store->delete("optistate_last_restore_filename");
                if (isset($restore_key)) {
                    $this->process_store->delete($restore_key);
                }
                $this->process_store->delete("optistate_current_restore_key");
                $lock_acquired = false;
            }
        } finally {
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
            if ($lock_acquired) {
                $this->restore_engine->release_restore_lock();
            }
        }
    }
    public function run_decompression_chunk_worker(
        string $decompression_key
    ): void {
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(180);
        if (!$this->restore_engine->acquire_restore_lock(5)) {
            wp_schedule_single_event(
                time() + 5,
                "optistate_run_decompression_chunk",
                [$decompression_key]
            );
            return;
        }
        $lock_acquired = true;
        try {
            if (
                empty($decompression_key) ||
                strpos($decompression_key, "optistate_decompress_task_") !== 0
            ) {
                return;
            }
            $task_data = $this->process_store->get($decompression_key);
            if ($task_data === false) {
                return;
            }
            try {
                if (empty($task_data["space_check_passed"])) {
                    $space_check = OPTISTATE_Backup_Utilities::check_sufficient_disk_space(
                        $this->wp_filesystem,
                        $task_data["source_path"],
                        $this->main_plugin->get_total_database_size(false)
                    );
                    if (!$space_check["success"]) {
                        throw new Exception($space_check["message"]);
                    }
                    $task_data["space_check_passed"] = true;
                    $this->process_store->set(
                        $decompression_key,
                        $task_data,
                        2 * HOUR_IN_SECONDS
                    );
                }
                $result = $this->restore_engine->decompress_file(
                    $task_data["source_path"],
                    $task_data["dest_path"]
                );
                if ($result === "INCOMPLETE") {
                    $progress_key =
                        "optistate_decompress_" .
                        md5($task_data["source_path"]);
                    $decompress_progress = $this->process_store->get(
                        $progress_key
                    );
                    if (
                        $decompress_progress &&
                        isset($decompress_progress["dest_bytes_written"])
                    ) {
                        $task_data["status"] = "decompressing";
                        $task_data["bytes_written"] =
                            $decompress_progress["dest_bytes_written"];
                    } else {
                        $task_data["status"] = "decompressing";
                        $task_data["bytes_written"] = 0;
                    }
                    $this->process_store->set(
                        $decompression_key,
                        $task_data,
                        2 * HOUR_IN_SECONDS
                    );
                    wp_schedule_single_event(
                        time() + 3,
                        "optistate_run_decompression_chunk",
                        [$decompression_key]
                    );
                } elseif ($result === true) {
                    $final_sql_path = $task_data["dest_path"];
                    $log_filename = $task_data["log_filename"];
                    $button_selector = $task_data["button_selector"];
                    $uploaded_file_info = $task_data["uploaded_file_info"];
                    $settings = $this->main_plugin->settings_manager->get_persistent_settings();
                    $security_active = empty(
                        $settings["disable_restore_security"]
                    );
                    if ($security_active) {
                        $handle = @fopen($final_sql_path, "r");
                        if (!$handle) {
                            throw new Exception(
                                __(
                                    "Failed to open decompressed file for security scan.",
                                    "optistate"
                                )
                            );
                        }
                        $sample = fread($handle, 32768);
                        fclose($handle);
                        if ($sample === false) {
                            throw new Exception(
                                __(
                                    "Failed to read decompressed file for security scan.",
                                    "optistate"
                                )
                            );
                        }
                        if (
                            preg_match(
                                '/<\?php|<\?=|<\s*\?|script\s*language\s*=\s*["\']?php["\']?|eval\s*\(|exec\s*\(|system\s*\(|passthru\s*\(|shell_exec\s*\(|base64_decode/i',
                                $sample
                            )
                        ) {
                            throw new Exception(
                                __(
                                    "Security risk detected. The decompressed file contains suspicious code.",
                                    "optistate"
                                )
                            );
                        }
                    }
                    $user_id = isset($task_data["user_id"])
                        ? absint($task_data["user_id"])
                        : 0;
                    $response = $this->restore_engine->initiate_master_restore(
                        $final_sql_path,
                        $log_filename,
                        $button_selector,
                        $uploaded_file_info,
                        $user_id
                    );
                    if (
                        !empty($task_data["is_upload"]) &&
                        !empty($task_data["uploaded_gz_path"])
                    ) {
                        if (
                            $this->wp_filesystem->exists(
                                $task_data["uploaded_gz_path"]
                            )
                        ) {
                            $this->wp_filesystem->delete(
                                $task_data["uploaded_gz_path"]
                            );
                        }
                    }
                    $task_data["status"] = "restore_starting";
                    $task_data["master_restore_key"] =
                        $response["master_restore_key"];
                    $this->process_store->set(
                        $decompression_key,
                        $task_data,
                        10 * MINUTE_IN_SECONDS
                    );
                } else {
                    throw new Exception(
                        __("Unknown decompression error.", "optistate")
                    );
                }
            } catch (Throwable $e) {
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->process_store->delete("optistate_restore_in_progress");
                $this->process_store->delete("optistate_last_restore_filename");
                $task_data["status"] = "error";
                $task_data["message"] = $e->getMessage();
                $this->process_store->set(
                    $decompression_key,
                    $task_data,
                    10 * MINUTE_IN_SECONDS
                );
                if (
                    !empty($task_data["is_upload"]) &&
                    !empty($task_data["uploaded_gz_path"])
                ) {
                    if (
                        $this->wp_filesystem->exists(
                            $task_data["uploaded_gz_path"]
                        )
                    ) {
                        $this->wp_filesystem->delete(
                            $task_data["uploaded_gz_path"]
                        );
                    }
                }
                if ($this->wp_filesystem->exists($task_data["dest_path"])) {
                    $this->wp_filesystem->delete($task_data["dest_path"]);
                }
                OPTISTATE_Utils::log_critical_error(
                    "Decompression chunk worker failed: " . $e->getMessage(),
                    [
                        "file" => $e->getFile(),
                        "line" => $e->getLine(),
                        "source_path" => $task_data["source_path"] ?? "",
                        "user_id" => $task_data["user_id"] ?? null,
                    ]
                );
                $this->main_plugin->log_entry(
                    "⚠️ " .
                        __("Database Restore Failed", "optistate") .
                        " + ⏪ " .
                        __("Rollback Succeeded", "optistate"),
                    "scheduled",
                    $task_data["log_filename"],
                    ["details" => $e->getMessage()]
                );
            }
        } finally {
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
            if ($lock_acquired) {
                $this->restore_engine->release_restore_lock();
            }
        }
    }
    public function run_rollback_cron_job(
        ?string $master_restore_key = null
    ): void {
        $class_instance = $this;
        register_shutdown_function(function () use (
            $class_instance,
            $master_restore_key
        ) {
            $error = error_get_last();
            if (
                $error !== null &&
                in_array(
                    $error["type"],
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                    true
                )
            ) {
                OPTISTATE_Utils::log_critical_error(
                    "Fatal error during rollback",
                    ["message" => $error["message"]]
                );
                try {
                    if ($master_restore_key) {
                        $error_state = [
                            "status" => "rollback_failed",
                            "message" =>
                                __("Rollback fatal error: ", "optistate") .
                                esc_html($error["message"]),
                            "expiration" => time() + 10 * MINUTE_IN_SECONDS,
                        ];
                        $fresh_store = new OPTISTATE_Process_Store();
                        $fresh_store->set($master_restore_key, $error_state);
                        set_transient(
                            "optistate_restore_error_" . $master_restore_key,
                            [
                                "message" => $error_state["message"],
                                "timestamp" => time(),
                            ],
                            5 * MINUTE_IN_SECONDS
                        );
                    }
                    $class_instance->process_store->set(
                        "optistate_rollback_status",
                        "failed",
                        HOUR_IN_SECONDS
                    );
                    $class_instance->restore_engine->release_restore_lock();
                } catch (Throwable $e) {
                }
            }
        });
        try {
            $instant_rollback_tables = $this->process_store->get(
                "optistate_instant_rollback_tables"
            );
            if (
                $instant_rollback_tables === false ||
                !is_array($instant_rollback_tables)
            ) {
                try {
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    $this->restore_engine->release_restore_lock();
                    $this->process_store->delete(
                        "optistate_restore_in_progress"
                    );
                } catch (Throwable $e) {
                }
                return;
            }
            OPTISTATE_Utils::log_critical_error(
                "Attempting INSTANT rollback via cron (Safe Mode)..."
            );
            $result = $this->restore_engine->perform_rollback();
            if ($result) {
                $this->process_store->delete(
                    "optistate_instant_rollback_tables"
                );
                $this->process_store->set(
                    "optistate_rollback_status",
                    "success",
                    HOUR_IN_SECONDS
                );
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->restore_engine->cleanup_old_tables_after_restore();
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                $this->cleanup_all_temp_sql_files();
                $this->main_plugin->log_entry(
                    "✅ " . __("INSTANT Rollback Succeeded.", "optistate")
                );
                if ($master_restore_key) {
                    $master_state = $this->process_store->get(
                        $master_restore_key
                    );
                    if ($master_state) {
                        $master_state["status"] = "rollback_done";
                        $master_state["message"] = __(
                            "Restore failed, but safety rollback succeeded! Your site is safe.",
                            "optistate"
                        );
                        $this->process_store->set(
                            $master_restore_key,
                            $master_state,
                            10 * MINUTE_IN_SECONDS
                        );
                    }
                }
            } else {
                if ($master_restore_key) {
                    $failed_state = [
                        "status" => "rollback_failed",
                        "message" => __(
                            "Restore failed and automatic rollback also failed. Please check your site integrity and restore from a manual backup if needed.",
                            "optistate"
                        ),
                        "expiration" => time() + 10 * MINUTE_IN_SECONDS,
                    ];
                    $fresh_store = new OPTISTATE_Process_Store();
                    $fresh_store->set($master_restore_key, $failed_state);
                    set_transient(
                        "optistate_restore_error_" . $master_restore_key,
                        [
                            "message" => $failed_state["message"],
                            "timestamp" => time(),
                        ],
                        5 * MINUTE_IN_SECONDS
                    );
                }
                OPTISTATE_Utils::log_critical_error("Instant Rollback FAILED.");
                $this->process_store->set(
                    "optistate_rollback_status",
                    "failed",
                    HOUR_IN_SECONDS
                );
                OPTISTATE_Utils::log_critical_error(
                    "Instant rollback failed during cron execution",
                    [
                        "master_restore_key" => $master_restore_key,
                        "rollback_tables_count" => count(
                            $instant_rollback_tables
                        ),
                    ]
                );
                try {
                    OPTISTATE_Utils::deactivate_maintenance_mode();
                    $this->restore_engine->release_restore_lock();
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Rollback cron job failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            if ($master_restore_key) {
                $error_state = [
                    "status" => "rollback_failed",
                    "message" =>
                        __("Rollback cron failed: ", "optistate") .
                        $e->getMessage(),
                    "expiration" => time() + 10 * MINUTE_IN_SECONDS,
                ];
                $fresh_store = new OPTISTATE_Process_Store();
                $fresh_store->set($master_restore_key, $error_state);
                set_transient(
                    "optistate_restore_error_" . $master_restore_key,
                    [
                        "message" => $error_state["message"],
                        "timestamp" => time(),
                    ],
                    5 * MINUTE_IN_SECONDS
                );
            }
            try {
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $this->restore_engine->release_restore_lock();
            } catch (Throwable $t) {
            }
        }
    }
    private function trigger_async_rollback(string $master_restore_key): void
    {
        wp_schedule_single_event(time(), "optistate_run_rollback_cron", [
            $master_restore_key,
        ]);
    }
    public function handle_download_backup(): void
    {
        if (
            !isset($_GET["action"]) ||
            $_GET["action"] !== "optistate_backup_download"
        ) {
            return;
        }
        if (!isset($_GET["file"]) || !isset($_GET["_wpnonce"])) {
            wp_die(__("Invalid download request.", "optistate"));
        }
        if (!wp_verify_nonce($_GET["_wpnonce"], "optistate_backup_nonce")) {
            wp_die(__("Security verification failed.", "optistate"));
        }
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("download_backup", 5)) {
            wp_die(OPTISTATE_Utils::get_rate_limit_message(false));
        }
        try {
            $filename = isset($_GET["file"])
                ? basename(wp_unslash($_GET["file"]))
                : "";
            $filename = str_replace(chr(0), "", $filename);
            if (!preg_match('/^[a-zA-Z0-9._-]+\.sql(\.gz)?$/i', $filename)) {
                wp_die(
                    __("Security violation: Invalid file type.", "optistate")
                );
            }
            if (
                strpos($filename, "..") !== false ||
                strpos($filename, "/") !== false ||
                strpos($filename, "\\") !== false
            ) {
                wp_die(
                    __(
                        "Security violation: Path traversal attempt detected.",
                        "optistate"
                    )
                );
            }
            if (empty($filename)) {
                wp_die(__("Invalid filename.", "optistate"));
            }
            $this->main_plugin->log_entry(
                "📥 " .
                    sprintf(
                        __(
                            "Backup file downloaded by {username} (%s)",
                            "optistate"
                        ),
                        $filename
                    )
            );
            $filepath = $this->backup_dir . $filename;
            $real_file = realpath($filepath);
            $real_backup_dir = realpath($this->backup_dir);
            if (
                $real_file === false ||
                $real_backup_dir === false ||
                $real_backup_dir . DIRECTORY_SEPARATOR . $filename !==
                    $real_file
            ) {
                wp_die(
                    __(
                        "Security violation: Unauthorized file path.",
                        "optistate"
                    )
                );
            }
            if (strpos($real_file, $real_backup_dir) !== 0) {
                wp_die(
                    __(
                        "Security violation: Unauthorized file path.",
                        "optistate"
                    )
                );
            }
            if (!$this->wp_filesystem->exists($filepath)) {
                wp_die(__("File not found.", "optistate"));
            }
            $file_size = $this->wp_filesystem->size($filepath);
            OPTISTATE_Utils::safe_set_time_limit(3600);
            if (function_exists("apache_setenv")) {
                @apache_setenv("no-gzip", 1);
            }
            @ini_set("zlib.output_compression", "Off");
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            $content_type = preg_match('/\.gz$/i', $filename)
                ? "application/gzip"
                : "application/sql";
            header("Content-Type: " . $content_type);
            header("Content-Description: File Transfer");
            header(
                'Content-Disposition: attachment; filename="' . $filename . '"'
            );
            header("Content-Length: " . $file_size);
            header("Accept-Ranges: bytes");
            header(
                "Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0"
            );
            header("Pragma: no-cache");
            header("Expires: 0");
            header("X-Content-Type-Options: nosniff");
            header("X-Frame-Options: DENY");
            $offset = 0;
            if (isset($_SERVER["HTTP_RANGE"])) {
                if (
                    preg_match(
                        "/bytes=(\d+)-(\d+)?/",
                        $_SERVER["HTTP_RANGE"],
                        $matches
                    )
                ) {
                    $offset = intval($matches[1]);
                    $end = isset($matches[2])
                        ? intval($matches[2])
                        : $file_size - 1;
                    header("HTTP/1.1 206 Partial Content");
                    header("Content-Range: bytes $offset-$end/$file_size");
                    header("Content-Length: " . ($end - $offset + 1));
                }
            }
            $handle = @fopen($filepath, "rb");
            if ($handle === false) {
                wp_die(__("Cannot open file.", "optistate"));
            }
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            $chunk_size = 8 * 1024 * 1024;
            $max_execution_total = 3600;
            $script_start = time();
            while (!feof($handle) && !connection_aborted()) {
                if (time() - $script_start > $max_execution_total) {
                    break;
                }
                $data = fread($handle, $chunk_size);
                if ($data === false) {
                    break;
                }
                echo $data;
                if (ob_get_length() > 0) {
                    ob_flush();
                }
                flush();
            }
            fclose($handle);
            exit();
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "handle_download_backup failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            wp_die(
                __(
                    "An unexpected error occurred while downloading the backup.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_upload_restore_file(): void
    {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(300);
        try {
            $chunk_index = isset($_POST["chunk_index"])
                ? absint($_POST["chunk_index"])
                : 0;
            $total_chunks = isset($_POST["total_chunks"])
                ? absint($_POST["total_chunks"])
                : 1;
            $file_name_raw = isset($_POST["file_name"])
                ? sanitize_text_field($_POST["file_name"])
                : "";
            $file_name = basename($file_name_raw);
            $file_size = isset($_POST["file_size"])
                ? absint($_POST["file_size"])
                : 0;
            $upload_id = isset($_POST["upload_id"])
                ? sanitize_text_field($_POST["upload_id"])
                : "";
            if (!preg_match('/^[a-f0-9]{32}$/', $upload_id)) {
                OPTISTATE_Utils::send_json_error(
                    __("Invalid upload identifier.", "optistate")
                );
                return;
            }
            global $wpdb;
            $lock_name = "optistate_upload_lock_" . $upload_id;
            $is_locked = $wpdb->get_var(
                $wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name)
            );
            if (!$is_locked) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Concurrency limit reached. Please retry chunk.",
                        "optistate"
                    ),
                    409,
                    ["code" => "lock_contention"]
                );
                return;
            }
            try {
                if ($chunk_index === 0) {
                    if (
                        !OPTISTATE_Utils::check_rate_limit(
                            "upload_restore_file",
                            60
                        )
                    ) {
                        OPTISTATE_Utils::send_json_error(
                            OPTISTATE_Utils::get_rate_limit_message(false),
                            429
                        );
                        return;
                    }
                }
                if (
                    empty($file_name) ||
                    empty($upload_id) ||
                    $total_chunks < 1
                ) {
                    OPTISTATE_Utils::send_json_error(
                        __("Invalid upload parameters.", "optistate")
                    );
                    return;
                }
                $path_info = pathinfo($file_name);
                $extension = isset($path_info["extension"])
                    ? strtolower($path_info["extension"])
                    : "";
                if ($extension === "gz") {
                    $basename = isset($path_info["filename"])
                        ? $path_info["filename"]
                        : "";
                    if (!preg_match('/\.sql$/i', $basename)) {
                        OPTISTATE_Utils::send_json_error(
                            __(
                                "Compressed files must be .sql.gz format.",
                                "optistate"
                            )
                        );
                        return;
                    }
                }
                $file_name_lower = strtolower($file_name);
                $extension_count = substr_count($file_name_lower, ".");
                if ($extension_count > 2) {
                    OPTISTATE_Utils::send_json_error(
                        __("Invalid filename format.", "optistate")
                    );
                    return;
                }
                $is_gzip = preg_match('/\.sql\.gz$/i', $file_name);
                $filename_without_ext = pathinfo($file_name, PATHINFO_FILENAME);
                if (
                    preg_match(
                        '/\.(php|phtml|php5|phar|exe|sh|cgi)$/i',
                        $filename_without_ext
                    )
                ) {
                    OPTISTATE_Utils::send_json_error(
                        __(
                            "Invalid filename. Security risk detected (double extension).",
                            "optistate"
                        )
                    );
                    return;
                }
                $max_size = 5000 * 1024 * 1024;
                if ($file_size > $max_size) {
                    OPTISTATE_Utils::send_json_error(
                        __(
                            "File is too large. Maximum size is 5GB.",
                            "optistate"
                        )
                    );
                    return;
                }
                if (
                    !isset($_FILES["chunk"]) ||
                    !is_uploaded_file($_FILES["chunk"]["tmp_name"])
                ) {
                    OPTISTATE_Utils::send_json_error(
                        __("Invalid chunk upload.", "optistate")
                    );
                    return;
                }
                $chunk = $_FILES["chunk"];
                $chunk_tmp = $chunk["tmp_name"];
                if ($chunk_index === 0) {
                    $tmp_path_check = $chunk_tmp;
                    if (preg_match('/\.gz$/i', $file_name)) {
                        $content = $this->wp_filesystem->get_contents(
                            $tmp_path_check
                        );
                        $bytes =
                            $content !== false ? substr($content, 0, 2) : "";
                        if ($bytes !== "\x1F\x8B") {
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "Security Error: Invalid GZIP file signature.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                    } else {
                        $content = $this->wp_filesystem->get_contents(
                            $tmp_path_check
                        );
                        if ($content !== false) {
                            $content = substr($content, 0, 512);
                        } else {
                            $content = "";
                        }
                        if (preg_match("/<\?php/i", $content)) {
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "Security Error: PHP code detected in SQL file.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                        if (
                            !preg_match(
                                "/(CREATE|INSERT|DROP|ALTER|--|#)/i",
                                $content
                            )
                        ) {
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "Security Error: File does not appear to be valid SQL.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                    }
                }
                $max_chunk_size = 5 * 1024 * 1024;
                if ($chunk["size"] > $max_chunk_size) {
                    OPTISTATE_Utils::send_json_error(
                        __("Chunk size too large.", "optistate")
                    );
                    return;
                }
                $upload_dir = wp_upload_dir();
                $temp_dir =
                    trailingslashit($upload_dir["basedir"]) .
                    OPTISTATE::TEMP_DIR_NAME .
                    "/";
                if (!$this->wp_filesystem->is_dir($temp_dir)) {
                    if (!wp_mkdir_p($temp_dir)) {
                        OPTISTATE_Utils::send_json_error(
                            __(
                                "Failed to create temporary directory.",
                                "optistate"
                            )
                        );
                        return;
                    }
                    $this->wp_filesystem->chmod($temp_dir, 0755);
                }
                $this->protect_temp_directory($temp_dir);
                $temp_filename_base = "restore-temp-" . $upload_id;
                $temp_path =
                    $temp_dir .
                    $temp_filename_base .
                    ($is_gzip ? ".sql.gz" : ".sql");
                $normalized_path = wp_normalize_path($temp_path);
                $normalized_dir = wp_normalize_path($temp_dir);
                if (strpos($normalized_path, $normalized_dir) !== 0) {
                    OPTISTATE_Utils::send_json_error(
                        __("Invalid file path.", "optistate")
                    );
                    return;
                }
                $session_key = "optistate_upload_session_" . $upload_id;
                $session_data = $this->process_store->get($session_key);
                if ($chunk_index === 0) {
                    $session_data = [
                        "started" => time(),
                        "user_id" => get_current_user_id(),
                        "file_name" => $file_name,
                        "file_size" => $file_size,
                        "total_chunks" => $total_chunks,
                        "received_chunks" => [],
                    ];
                    $this->process_store->set(
                        $session_key,
                        $session_data,
                        60 * MINUTE_IN_SECONDS
                    );
                    if ($this->wp_filesystem->exists($temp_path)) {
                        $this->wp_filesystem->delete($temp_path);
                    }
                    $this->wp_filesystem->touch($temp_path);
                    $this->wp_filesystem->chmod($temp_path, 0600);
                } else {
                    if (!$session_data) {
                        if ($this->wp_filesystem->exists($temp_path)) {
                            $this->wp_filesystem->delete($temp_path);
                        }
                        OPTISTATE_Utils::send_json_error(
                            __(
                                "Upload session expired. Please start over.",
                                "optistate"
                            )
                        );
                        return;
                    }
                    if ($session_data["user_id"] !== get_current_user_id()) {
                        OPTISTATE_Utils::send_json_error(
                            __("Invalid upload session.", "optistate")
                        );
                        return;
                    }
                    if (
                        in_array($chunk_index, $session_data["received_chunks"])
                    ) {
                        OPTISTATE_Utils::send_json_error(
                            __("Duplicate chunk detected.", "optistate"),
                            409,
                            ["code" => "duplicate_chunk"]
                        );
                        return;
                    }
                }
                $chunk_data = $this->wp_filesystem->get_contents($chunk_tmp);
                if ($chunk_data === false) {
                    throw new Exception(
                        __("Failed to read uploaded chunk.", "optistate")
                    );
                }
                $handle = @fopen($temp_path, "ab");
                if ($handle === false) {
                    throw new Exception(
                        __(
                            "Failed to open temp file for appending.",
                            "optistate"
                        )
                    );
                }
                $written = @fwrite($handle, $chunk_data);
                @fclose($handle);
                if ($written === false || $written !== strlen($chunk_data)) {
                    throw new Exception(
                        __("Failed to write chunk data.", "optistate")
                    );
                }
                $session_data["received_chunks"][] = $chunk_index;
                $this->process_store->set(
                    $session_key,
                    $session_data,
                    60 * MINUTE_IN_SECONDS
                );
                $this->wp_filesystem->delete($chunk_tmp);
                if ($chunk_index === $total_chunks - 1) {
                    if (
                        count($session_data["received_chunks"]) !==
                        $total_chunks
                    ) {
                        $this->wp_filesystem->delete($temp_path);
                        $this->process_store->delete($session_key);
                        OPTISTATE_Utils::send_json_error(
                            sprintf(
                                __(
                                    'Upload incomplete. Missing chunks: %1$s of %2$s',
                                    "optistate"
                                ),
                                number_format_i18n(
                                    $total_chunks -
                                        count($session_data["received_chunks"])
                                ),
                                number_format_i18n($total_chunks)
                            )
                        );
                        return;
                    }
                    $final_size = $this->wp_filesystem->size($temp_path);
                    if ($final_size !== $file_size) {
                        $this->wp_filesystem->delete($temp_path);
                        $this->process_store->delete($session_key);
                        OPTISTATE_Utils::send_json_error(
                            sprintf(
                                __(
                                    'File size mismatch. Expected %1$s, got %2$s.',
                                    "optistate"
                                ),
                                size_format($file_size, 2),
                                size_format($final_size, 2)
                            )
                        );
                        return;
                    }
                    if ($is_gzip) {
                        $temp_sql_gz_filename = basename($temp_path);
                        $settings = $this->main_plugin->settings_manager->get_persistent_settings();
                        $security_disabled = !empty(
                            $settings["disable_restore_security"]
                        );
                        $transient_data = [
                            "path" => $temp_path,
                            "original_name" => $file_name,
                            "size" => $final_size,
                            "uploaded" => time(),
                            "user_id" => get_current_user_id(),
                            "ip_address" => OPTISTATE_Utils::get_client_ip(
                                !empty($settings["cloudflare_enabled"]),
                                []
                            ),
                            "chunks_received" => $total_chunks,
                            "is_compressed" => true,
                            "security_disabled" => $security_disabled,
                        ];
                        $this->process_store->set(
                            "optistate_temp_restore_" . $temp_sql_gz_filename,
                            $transient_data,
                            2 * HOUR_IN_SECONDS
                        );
                        $this->process_store->delete($session_key);
                        OPTISTATE_Utils::send_json_success([
                            "message" => __(
                                "Compressed file uploaded successfully!",
                                "optistate"
                            ),
                            "temp_path" => $temp_sql_gz_filename,
                            "file_name" => $file_name,
                            "file_size" =>
                                size_format($final_size, 2) .
                                " (" .
                                __("compressed", "optistate") .
                                ")",
                            "complete" => true,
                        ]);
                        return;
                    }
                    $final_sql_path = $temp_path;
                    $temp_sql_filename = basename($temp_path);
                    $settings = $this->main_plugin->settings_manager->get_persistent_settings();
                    $security_active = empty(
                        $settings["disable_restore_security"]
                    );
                    if ($security_active) {
                        $handle = @fopen($final_sql_path, "r");
                        if (!$handle) {
                            $this->cleanup_failed_upload(
                                $final_sql_path,
                                $session_key
                            );
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "Failed to open final file for security scan.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                        $sample = fread($handle, 32768);
                        fclose($handle);
                        if ($sample === false) {
                            $this->cleanup_failed_upload(
                                $final_sql_path,
                                $session_key
                            );
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "Failed to read file for security scan.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                        if (
                            preg_match(
                                '/<\?php|<\?=|<\s*\?|script\s*language\s*=\s*["\']?php["\']?|eval\s*\(|exec\s*\(|system\s*\(|passthru\s*\(|shell_exec\s*\(|base64_decode/i',
                                $sample
                            )
                        ) {
                            $this->cleanup_failed_upload(
                                $final_sql_path,
                                $session_key
                            );
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "Security risk detected. The uploaded file contains suspicious code.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                        if (
                            !preg_match(
                                "/(?:CREATE|INSERT|DROP|ALTER|UPDATE|SELECT|SET|USE|LOCK|UNLOCK)/i",
                                $sample
                            )
                        ) {
                            $this->cleanup_failed_upload(
                                $final_sql_path,
                                $session_key
                            );
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "File does not appear to be a valid SQL file.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                    }
                    if (function_exists("finfo_open")) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $final_mime = finfo_file($finfo, $final_sql_path);
                        $allowed_mimes = [
                            "text/plain",
                            "text/x-sql",
                            "application/sql",
                            "application/x-sql",
                            "application/octet-stream",
                        ];
                        if (!in_array($final_mime, $allowed_mimes, true)) {
                            $this->cleanup_failed_upload(
                                $final_sql_path,
                                $session_key
                            );
                            OPTISTATE_Utils::send_json_error(
                                __(
                                    "Security error: Invalid file type.",
                                    "optistate"
                                )
                            );
                            return;
                        }
                    }
                    $this->wp_filesystem->chmod($final_sql_path, 0600);
                    $final_sql_size = $this->wp_filesystem->size(
                        $final_sql_path
                    );
                    $security_disabled = !empty(
                        $settings["disable_restore_security"]
                    );
                    $transient_data = [
                        "path" => $final_sql_path,
                        "original_name" => $file_name,
                        "size" => $final_sql_size,
                        "uploaded" => time(),
                        "user_id" => get_current_user_id(),
                        "ip_address" => OPTISTATE_Utils::get_client_ip(
                            !empty($settings["cloudflare_enabled"]),
                            []
                        ),
                        "chunks_received" => $total_chunks,
                        "security_disabled" => $security_disabled,
                    ];
                    $this->process_store->set(
                        "optistate_temp_restore_" . $temp_sql_filename,
                        $transient_data,
                        2 * HOUR_IN_SECONDS
                    );
                    $this->process_store->delete($session_key);
                    OPTISTATE_Utils::send_json_success([
                        "message" => __(
                            "File uploaded successfully!",
                            "optistate"
                        ),
                        "temp_path" => $temp_sql_filename,
                        "file_name" => $file_name,
                        "file_size" => size_format($final_sql_size, 2),
                        "complete" => true,
                    ]);
                } else {
                    $progress = round(
                        (($chunk_index + 1) / $total_chunks) * 100
                    );
                    $this->process_store->set(
                        $session_key,
                        $session_data,
                        60 * MINUTE_IN_SECONDS
                    );
                    OPTISTATE_Utils::send_json_success([
                        "message" => sprintf(
                            __('Uploading chunk %1$s of %2$s', "optistate"),
                            number_format_i18n($chunk_index + 1),
                            number_format_i18n($total_chunks)
                        ),
                        "progress" => $progress,
                        "complete" => false,
                    ]);
                }
            } finally {
                $wpdb->query(
                    $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error("Chunked upload failed", [
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "user_id" => get_current_user_id(),
                "file_name" => isset($file_name) ? $file_name : "unknown",
            ]);
            OPTISTATE_Utils::send_json_error(
                __("Upload failed: ", "optistate") . $e->getMessage(),
                500
            );
        } finally {
            OPTISTATE_Utils::safe_set_time_limit($original_time_limit);
        }
    }
    public function ajax_restore_from_file(): void
    {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $this->ensure_required_tables_exist();
        if (is_multisite()) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "🛑 Safety Stop! Database restore is not supported on Multisite installations to prevent network-wide data loss.",
                    "optistate"
                )
            );
            return;
        }
        $step = isset($_POST["step"]) ? sanitize_key($_POST["step"]) : "init";
        if ($step !== "init") {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request step.", "optistate")
            );
            return;
        }
        $class_instance = $this;
        register_shutdown_function(function () use ($class_instance) {
            $error = error_get_last();
            if (
                $error !== null &&
                in_array(
                    $error["type"],
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                    true
                )
            ) {
                OPTISTATE_Utils::deactivate_maintenance_mode();
                $class_instance->restore_engine->release_restore_lock();
                $class_instance->process_store->delete(
                    "optistate_restore_in_progress"
                );
            }
        });
        if (!$this->restore_engine->acquire_restore_lock(0)) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "A restore process is already in progress (Lock Active). Please wait.",
                    "optistate"
                )
            );
            return;
        }
        $existing_process = $this->process_store->get(
            "optistate_restore_in_progress"
        );
        if ($existing_process !== false) {
            $this->restore_engine->release_restore_lock();
            OPTISTATE_Utils::send_json_error(
                __(
                    "A restore process is already running. Please wait for it to complete.",
                    "optistate"
                )
            );
            return;
        }
        $this->process_store->set(
            "optistate_restore_in_progress",
            ["status" => "init", "start_time" => time()],
            2 * HOUR_IN_SECONDS
        );
        try {
            $temp_filename = isset($_POST["temp_path"])
                ? sanitize_text_field($_POST["temp_path"])
                : "";
            $temp_filename = basename($temp_filename);
            if (!OPTISTATE_Utils::check_rate_limit("restore_backup", 60)) {
                $this->restore_engine->release_restore_lock();
                $this->process_store->delete("optistate_restore_in_progress");
                OPTISTATE_Utils::send_json_error(
                    OPTISTATE_Utils::get_rate_limit_message(false),
                    429
                );
                return;
            }
            if (
                empty($temp_filename) ||
                !preg_match(
                    '/^(restore-temp-[a-f0-9]{32}\.(sql|sql\.gz)|decompressed-[a-f0-9]{32}\.sql)$/i',
                    $temp_filename
                )
            ) {
                throw new Exception(
                    __("Invalid or missing file identifier.", "optistate") .
                        " ($temp_filename)"
                );
            }
            $file_info = $this->process_store->get(
                "optistate_temp_restore_" . $temp_filename
            );
            if (
                !$file_info ||
                !isset($file_info["path"]) ||
                (int) $file_info["user_id"] !== get_current_user_id()
            ) {
                throw new Exception(
                    __(
                        "File session expired or is invalid. Please upload again.",
                        "optistate"
                    )
                );
            }
            $filepath = $file_info["path"];
            if (!$this->wp_filesystem->exists($filepath)) {
                throw new Exception(
                    __("Uploaded file not found.", "optistate")
                );
            }
            $log_filename = $file_info["original_name"] ?? $temp_filename;
            $button_selector = "#optistate-restore-file-btn";
            $is_compressed =
                !empty($file_info["is_compressed"]) ||
                preg_match('/\.gz$/i', $filepath);
            if ($is_compressed) {
                $upload_dir = wp_upload_dir();
                $temp_dir =
                    trailingslashit($upload_dir["basedir"]) .
                    OPTISTATE::TEMP_DIR_NAME .
                    "/";
                $upload_id = preg_match(
                    "/restore-temp-([a-f0-9]{32})/",
                    $temp_filename,
                    $matches
                )
                    ? $matches[1]
                    : bin2hex(random_bytes(14));
                $decompressed_path =
                    $temp_dir . "decompressed-" . $upload_id . ".sql";
                try {
                    $decompression_key =
                        "optistate_decompress_task_" .
                        bin2hex(random_bytes(14));
                    $task_data = [
                        "status" => "pending",
                        "source_path" => $filepath,
                        "dest_path" => $decompressed_path,
                        "log_filename" => $log_filename,
                        "button_selector" => $button_selector,
                        "source_size" => $this->wp_filesystem->size($filepath),
                        "master_restore_key" => null,
                        "uploaded_file_info" => [
                            "temp_transient_to_delete" =>
                                "optistate_temp_restore_" .
                                basename($decompressed_path),
                            "temp_filepath_to_delete" => $decompressed_path,
                        ],
                        "is_upload" => true,
                        "upload_session_key" => null,
                        "uploaded_gz_path" => $filepath,
                        "user_id" => get_current_user_id(),
                    ];
                    $this->process_store->set(
                        $decompression_key,
                        $task_data,
                        2 * HOUR_IN_SECONDS
                    );
                    wp_schedule_single_event(
                        time(),
                        "optistate_run_decompression_chunk",
                        [$decompression_key]
                    );
                    OPTISTATE_Utils::send_json_success([
                        "status" => "decompressing",
                        "decompression_key" => $decompression_key,
                        "message" => __(
                            "Decompression started...",
                            "optistate"
                        ),
                    ]);
                } catch (Throwable $e) {
                    if ($this->wp_filesystem->exists($decompressed_path)) {
                        $this->wp_filesystem->delete($decompressed_path);
                    }
                    throw $e;
                }
                return;
            }
            $settings = $this->main_plugin->settings_manager->get_persistent_settings();
            $security_active = empty($settings["disable_restore_security"]);
            if ($security_active) {
                $handle = @fopen($filepath, "r");
                if (!$handle) {
                    throw new Exception(
                        __(
                            "Failed to open file for security scan.",
                            "optistate"
                        )
                    );
                }
                $sample = fread($handle, 32768);
                fclose($handle);
                if ($sample === false) {
                    throw new Exception(
                        __(
                            "Failed to read file for security scan.",
                            "optistate"
                        )
                    );
                }
                if (
                    preg_match(
                        '/<\?php|<\?=|<\s*\?|script\s*language\s*=\s*["\']?php["\']?|eval\s*\(|exec\s*\(|system\s*\(|passthru\s*\(|shell_exec\s*\(|base64_decode/i',
                        $sample
                    )
                ) {
                    throw new Exception(
                        __(
                            "Security risk detected. The file contains suspicious code.",
                            "optistate"
                        )
                    );
                }
            }
            $response = $this->restore_engine->initiate_master_restore(
                $filepath,
                $log_filename,
                $button_selector,
                [
                    "temp_transient_to_delete" =>
                        "optistate_temp_restore_" . $temp_filename,
                    "security_disabled" => !$security_active,
                ],
                get_current_user_id()
            );
            OPTISTATE_Utils::send_json_success($response);
        } catch (Throwable $e) {
            OPTISTATE_Utils::deactivate_maintenance_mode();
            $this->restore_engine->release_restore_lock();
            $this->process_store->delete("optistate_restore_in_progress");
            $this->process_store->delete("optistate_last_restore_filename");
            $this->main_plugin->log_entry(
                "⚠️ " .
                    __("Database Restore Failed", "optistate") .
                    " + ⏪ " .
                    __("Rollback Succeeded", "optistate"),
                "scheduled",
                $temp_filename ?? "unknown",
                ["details" => $e->getMessage()]
            );
            OPTISTATE_Utils::log_critical_error("Restore from file failed", [
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "temp_filename" => isset($temp_filename)
                    ? $temp_filename
                    : "unknown",
                "user_id" => get_current_user_id(),
            ]);
            OPTISTATE_Utils::send_json_error(
                __("Failed to initiate restore: ", "optistate") .
                    $e->getMessage(),
                500
            );
        }
    }
    public function ajax_check_decompression_status(): void
    {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $decompression_key = isset($_POST["decompression_key"])
            ? sanitize_text_field($_POST["decompression_key"])
            : "";
        if (
            empty($decompression_key) ||
            strpos($decompression_key, "optistate_decompress_task_") !== 0
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid decompression key.", "optistate"),
                400,
                ["status" => "error"]
            );
            return;
        }
        try {
            $task_data = $this->process_store->get($decompression_key);
            if ($task_data === false) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Decompression session expired or not found. Please try again.",
                        "optistate"
                    ),
                    400,
                    ["status" => "error"]
                );
                return;
            }
            $status = $task_data["status"];
            if ($status === "pending" || $status === "decompressing") {
                OPTISTATE_Utils::send_json_success([
                    "status" => "decompressing",
                    "message" => sprintf(
                        __("DECOMPRESSING BACKUP ....", "optistate")
                    ),
                ]);
            } elseif ($status === "restore_starting") {
                $this->process_store->delete($decompression_key);
                OPTISTATE_Utils::send_json_success([
                    "status" => "restore_starting",
                    "message" => __(
                        "Decompression complete! Initiating restore...",
                        "optistate"
                    ),
                    "master_restore_key" => $task_data["master_restore_key"],
                ]);
            } elseif ($status === "error") {
                $this->process_store->delete($decompression_key);
                OPTISTATE_Utils::send_json_success([
                    "status" => "error",
                    "message" =>
                        $task_data["message"] ??
                        __("Decompression failed.", "optistate"),
                ]);
            } else {
                OPTISTATE_Utils::send_json_error(
                    __("Unknown decompression status.", "optistate"),
                    400,
                    ["status" => "error"]
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_check_decompression_status failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while checking decompression status.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_get_restore_status(): void
    {
        check_ajax_referer("optistate_backup_nonce", "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $master_restore_key = isset($_POST["master_restore_key"])
            ? sanitize_text_field($_POST["master_restore_key"])
            : "";
        if (
            empty($master_restore_key) ||
            strpos($master_restore_key, "optistate_master_restore_") !== 0
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid restore key.", "optistate"),
                400,
                ["status" => "error"]
            );
        }
        try {
            $master_state = $this->process_store->get($master_restore_key);
            if ($master_state === false) {
                $global_lock = $this->process_store->get(
                    "optistate_restore_in_progress"
                );
                if ($global_lock === false) {
                    OPTISTATE_Utils::send_json_success([
                        "status" => "not_running",
                        "message" => sprintf(
                            __(
                                "Error! Restore process not found or finished.<br>If this issue persists, try deactivating and reactivating the plugin.",
                                "optistate"
                            )
                        ),
                    ]);
                } else {
                    OPTISTATE_Utils::send_json_success([
                        "status" => "starting",
                        "message" => __("INITIALIZING ....", "optistate"),
                    ]);
                }
                return;
            }
            if (
                $master_state["status"] === "restore_running" ||
                $master_state["status"] === "rollback_starting"
            ) {
                $error_transient = get_transient(
                    "optistate_restore_error_" . $master_restore_key
                );
                if ($error_transient !== false) {
                    $error_state = [
                        "status" => "error",
                        "message" =>
                            $error_transient["message"] ??
                            __("Restore failed (fallback).", "optistate"),
                        "expiration" => time() + 10 * MINUTE_IN_SECONDS,
                    ];
                    $fresh_store = new OPTISTATE_Process_Store();
                    $fresh_store->set($master_restore_key, $error_state);
                    delete_transient(
                        "optistate_restore_error_" . $master_restore_key
                    );
                    $master_state = $error_state;
                }
            }
            OPTISTATE_Utils::send_json_success([
                "status" => $master_state["status"],
                "message" => $master_state["message"],
                "button_selector" => $master_state["button_selector"] ?? "",
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_get_restore_status failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while fetching restore status.",
                    "optistate"
                )
            );
        }
    }
    private function cleanup_failed_backup(
        string $transient_key,
        string $reason = "",
        array $context = []
    ): void {
        $state = $this->process_store->get($transient_key);
        if (
            $state &&
            isset($state["filepath"]) &&
            $this->wp_filesystem->exists($state["filepath"])
        ) {
            $this->wp_filesystem->delete($state["filepath"]);
        }
        if ($state && !empty($state["user_id"])) {
            $this->process_store->delete(
                "optistate_manual_backup_user_" . $state["user_id"]
            );
        }
        $this->process_store->delete($transient_key);
        if (!empty($reason)) {
            $filename = $state["filename"] ?? "unknown";
            $this->main_plugin->log_entry(
                "🗑️ " . __("Backup cleanup: ", "optistate") . $reason,
                "error",
                $filename,
                array_merge(["transient_key" => $transient_key], $context)
            );
        }
    }
    private function cleanup_failed_upload(
        string $path,
        string $session_key
    ): bool {
        $upload_dir = wp_upload_dir();
        $temp_dir =
            trailingslashit($upload_dir["basedir"]) .
            OPTISTATE::TEMP_DIR_NAME .
            "/";
        $normalized_path = wp_normalize_path($path);
        $normalized_temp_dir = wp_normalize_path($temp_dir);
        if (strpos($normalized_path, $normalized_temp_dir) !== 0) {
            $this->process_store->delete($session_key);
            return false;
        }
        if (strpos($normalized_path, "..") !== false) {
            $this->process_store->delete($session_key);
            return false;
        }
        if ($this->wp_filesystem->exists($normalized_path)) {
            $deleted = $this->wp_filesystem->delete($normalized_path);
            if (!$deleted) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to delete failed upload file",
                    ["path" => $normalized_path]
                );
            }
        }
        $this->process_store->delete($session_key);
        return true;
    }
    private function cleanup_all_temp_sql_files(
        ?string $specific_file = null
    ): bool {
        $upload_dir = wp_upload_dir();
        $temp_dir =
            trailingslashit($upload_dir["basedir"]) .
            OPTISTATE::TEMP_DIR_NAME .
            "/";
        return OPTISTATE_Utils::cleanup_temp_files(
            $this->wp_filesystem,
            $temp_dir,
            $specific_file
        );
    }
    private function enforce_backup_limit(): void
    {
        $max_backups = (int) $this->max_backups;
        if (
            $max_backups < 1 ||
            !$this->wp_filesystem ||
            !$this->wp_filesystem->is_dir($this->backup_dir)
        ) {
            return;
        }
        $backup_dir = rtrim($this->backup_dir, "/") . "/";
        $backup_files = [];
        $list = $this->wp_filesystem->dirlist($backup_dir, true, false);
        if (!is_array($list) || empty($list)) {
            return;
        }
        foreach ($list as $filename => $file_details) {
            if (
                !isset($file_details["name"]) ||
                (isset($file_details["type"]) && $file_details["type"] === "d")
            ) {
                continue;
            }
            $filename = $file_details["name"];
            if (!preg_match('/\.sql(\.gz)?$/i', $filename)) {
                continue;
            }
            if (strpos($filename, "SAFETY-RESTORE-") === 0) {
                continue;
            }
            $timestamp = isset($file_details["lastmodunix"])
                ? (int) $file_details["lastmodunix"]
                : 0;
            $backup_files[] = [
                "filename" => $filename,
                "created_timestamp" => $timestamp,
                "fullpath" => $backup_dir . $filename,
            ];
        }
        $current_count = count($backup_files);
        if ($current_count <= $max_backups) {
            return;
        }
        usort($backup_files, function ($a, $b) {
            return $a["created_timestamp"] <=> $b["created_timestamp"];
        });
        $to_delete = array_slice(
            $backup_files,
            0,
            $current_count - $max_backups
        );
        if (empty($to_delete)) {
            return;
        }
        global $wpdb;
        $meta_table = $wpdb->prefix . "optistate_backup_metadata";
        $filenames = array_column($to_delete, "filename");
        $placeholders = implode(",", array_fill(0, count($filenames), "%s"));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $meta_table WHERE filename IN ($placeholders)",
                $filenames
            )
        );
        foreach ($to_delete as $file) {
            $filepath = $file["fullpath"];
            $filename = $file["filename"];
            if ($this->wp_filesystem->exists($filepath)) {
                $this->wp_filesystem->delete($filepath);
            }
            delete_transient("optistate_backup_integrity_" . md5($filename));
        }
        $this->invalidate_backup_cache();
    }
    private function save_backup_metadata(
        string $filepath,
        string $filename,
        int $start_time,
        array $tables_list = [],
        int $uncompressed_size = 0
    ): bool {
        global $wpdb;
        if (!$this->wp_filesystem || !$this->wp_filesystem->exists($filepath)) {
            return false;
        }
        $this->ensure_metadata_table_columns();
        try {
            $file_size = $this->wp_filesystem->size($filepath);
            $file_mtime = $this->wp_filesystem->mtime($filepath);
            $table_name = $wpdb->prefix . "optistate_backup_metadata";
            $tables_list_json = !empty($tables_list)
                ? wp_json_encode($tables_list)
                : null;
            $created_at = current_time("mysql");
            $sql = $wpdb->prepare(
                "INSERT INTO {$table_name} (filename, database_name, file_size, uncompressed_size, created_timestamp, created_at, tables_list) VALUES (%s, %s, %d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE database_name = VALUES(database_name), file_size = VALUES(file_size), uncompressed_size = VALUES(uncompressed_size), created_timestamp = VALUES(created_timestamp), created_at = VALUES(created_at), tables_list = VALUES(tables_list)",
                $filename,
                DB_NAME,
                (int) $file_size,
                (int) $uncompressed_size,
                (int) $file_mtime,
                $created_at,
                $tables_list_json
            );
            $result = $wpdb->query($sql);
            if ($result === false) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to upsert backup metadata",
                    [
                        "filename" => $filename,
                        "filepath" => $filepath,
                        "error" => $wpdb->last_error,
                    ]
                );
                return false;
            }
            return true;
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to save backup metadata",
                [
                    "filename" => $filename,
                    "filepath" => $filepath,
                    "error" => $e->getMessage(),
                ]
            );
            return false;
        }
    }
    private function quick_verify_backup_status(string $filepath): array
    {
        $filename = basename($filepath);
        $cache_key = "optistate_backup_integrity_" . md5($filename);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        $result = OPTISTATE_Backup_Utilities::verify_backup_file(
            $this->wp_filesystem,
            $filepath,
            false
        );
        if ($result["valid"]) {
            set_transient($cache_key, $result, 24 * HOUR_IN_SECONDS);
        }
        return $result;
    }
    private function clear_all_integrity_caches(): void
    {
        $files = $this->wp_filesystem->dirlist($this->backup_dir);
        if (!empty($files)) {
            foreach ($files as $filename => $fileinfo) {
                delete_transient(
                    "optistate_backup_integrity_" . md5($filename)
                );
            }
        }
    }
    private function ensure_required_tables_exist(): void
    {
        global $wpdb;
        $meta_table = $wpdb->prefix . "optistate_backup_metadata";
        if (!OPTISTATE_Utils::table_exists($meta_table)) {
            $this->create_backup_metadata_table();
        }
        $this->process_store->ensure_table_exists();
        $core_table = $wpdb->prefix . "optistate_core_data";
        if (!OPTISTATE_Utils::table_exists($core_table)) {
            $this->main_plugin->recreate_core_data_table();
        }
    }
    private function ensure_metadata_table_columns(): void
    {
        if (self::$metadata_columns_checked === true) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . "optistate_backup_metadata";
        $column_exists = $wpdb->get_var(
            "SHOW COLUMNS FROM {$table_name} LIKE 'uncompressed_size'"
        );
        if (!$column_exists) {
            $wpdb->query(
                "ALTER TABLE {$table_name} ADD COLUMN uncompressed_size bigint(20) DEFAULT NULL AFTER file_size"
            );
            if ($wpdb->last_error) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to add uncompressed_size column",
                    ["error" => $wpdb->last_error]
                );
                return;
            }
        }
        self::$metadata_columns_checked = true;
    }
    private function create_backup_metadata_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "optistate_backup_metadata";
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (id bigint(20) NOT NULL AUTO_INCREMENT, filename varchar(255) NOT NULL, database_name varchar(64) NOT NULL, file_size bigint(20) NOT NULL, created_timestamp bigint(20) NOT NULL, created_at datetime NOT NULL, tables_list longtext DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY filename (filename), KEY created_timestamp (created_timestamp)) $charset_collate;";
        if (!OPTISTATE_Utils::table_exists($table_name)) {
            $result = $wpdb->query($sql);
            if ($result === false) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to create backup metadata table",
                    ["error" => $wpdb->last_error]
                );
            }
            return;
        }
        if (!function_exists("dbDelta")) {
            require_once ABSPATH . "wp-admin/includes/upgrade.php";
        }
        try {
            dbDelta($sql);
        } catch (Exception $e) {
            OPTISTATE_Utils::log_critical_error(
                "dbDelta failed for backup metadata table",
                ["error" => $e->getMessage()]
            );
        }
    }
    private function ensure_secure_backup_dir(): bool
    {
        $rules = [
            "# WP Optimal State - Secure Backup Directory",
            "Options -Indexes",
            "<IfModule mod_authz_core.c>",
            " Require all denied",
            "</IfModule>",
            "<IfModule !mod_authz_core.c>",
            " Order deny,allow",
            " Deny from all",
            "</IfModule>",
        ];
        return $this->main_plugin->ensure_directory(
            $this->backup_dir,
            0755,
            $rules
        );
    }
    public function protect_backup_directory(): void
    {
        $this->ensure_secure_backup_dir();
    }
    private function protect_temp_directory(string $temp_dir): void
    {
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
        $this->main_plugin->ensure_directory($temp_dir, 0750, $rules);
    }
    public function schedule_daily_cleanup(): void
    {
        if (!wp_next_scheduled("optistate_hourly_cleanup")) {
            wp_schedule_event(time(), "hourly", "optistate_hourly_cleanup");
        }
    }
    public function cleanup_old_temp_files_daily(): void
    {
        try {
            OPTISTATE_Backup_Utilities::cleanup_old_temp_files_daily(
                $this->main_plugin,
                $this->process_store
            );
            if (isset($this->main_plugin->trash_manager)) {
                $this->main_plugin->trash_manager->cleanup_trash();
            }
            if (isset($this->main_plugin->performance_manager)) {
                $this->main_plugin->performance_manager->cleanup_orphaned_cron_state();
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "cleanup_old_temp_files_daily failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
        }
    }
    public function display_rollback_status_notice(): void
    {
        $status = $this->process_store->get("optistate_rollback_status");
        if (!$status) {
            return;
        }
        $this->process_store->delete("optistate_rollback_status");
        if ($status === "success") {
            echo '<div class="notice notice-success is-dismissible">';
            echo "<h3>" .
                __("WP Optimal State: Rollback Succeeded", "optistate") .
                "</h3>";
            echo "<p>" .
                __(
                    "A recent database restore failed, but the automatic rollback to your safety backup was successful. Your site is now back to its previous state.",
                    "optistate"
                ) .
                "</p>";
            echo "</div>";
        } elseif ($status === "failed") {
            echo '<div class="notice notice-error">';
            echo "<h3>" .
                __(
                    "WP Optimal State: POTENTIAL ROLLBACK FAILURE",
                    "optistate"
                ) .
                "</h3>";
            echo "<p>" .
                __(
                    "A database restore failed, and the automatic rollback may have failed as well. Check your site to ensure that it is working properly.",
                    "optistate"
                ) .
                "</p>";
            $allowed_html = ["code" => []];
            echo "<p><strong>" .
                __("What to do:", "optistate") .
                "</strong> " .
                wp_kses(
                    sprintf(
                        __(
                            "A safety backup file may still exist. If your site is broken, please check the <code>%s</code> folder for a file named 'SAFETY-RESTORE-...' and restore it manually or contact support.",
                            "optistate"
                        ),
                        esc_html(wp_normalize_path($this->backup_dir))
                    ),
                    $allowed_html
                ) .
                "</p>";
            echo "</div>";
        } elseif ($status === "failed_no_backup") {
            echo '<div class="notice notice-error">';
            echo "<h3>" .
                __("WP Optimal State: Rollback Failed", "optistate") .
                "</h3>";
            echo "<p>" .
                __(
                    "A database restore failed, but the rollback could not run because the safety backup information was missing or expired. Please check your site's integrity.",
                    "optistate"
                ) .
                "</p>";
            echo "</div>";
        }
    }
}