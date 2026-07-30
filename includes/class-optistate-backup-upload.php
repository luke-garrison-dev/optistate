<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Backup_Upload
{
    private OPTISTATE $main_plugin;
    private OPTISTATE_Process_Store $process_store;
    private ?object $wp_filesystem;
    private string $backup_dir;

    public function __construct(
        OPTISTATE $main_plugin,
        OPTISTATE_Process_Store $process_store,
        ?object $wp_filesystem,
        string $backup_dir
    ) {
        $this->main_plugin = $main_plugin;
        $this->process_store = $process_store;
        $this->wp_filesystem = $wp_filesystem;
        $this->backup_dir = $backup_dir;
        $this->register_hooks();
    }

    public function register_hooks(): void
    {
        add_action("init", [$this, "handle_download_backup"]);
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
            if (empty($filename)) {
                wp_die(__("Invalid filename.", "optistate"));
            }
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
        if (
            !$this->main_plugin->verify_ajax_request(
                OPTISTATE::BACKUP_NONCE_ACTION
            )
        ) {
            return;
        }
        $original_time_limit = (int) ini_get("max_execution_time");
        OPTISTATE_Utils::safe_set_time_limit(300);
        try {
            $chunk_index = isset($_POST["chunk_index"])
                ? absint($_POST["chunk_index"])
                : 0;
            $total_chunks = isset($_POST["total_chunks"])
                ? absint($_POST["total_chunks"])
                : 1;
            $file_name = isset($_POST["file_name"])
                ? basename(sanitize_text_field(wp_unslash($_POST["file_name"])))
                : "";
            $file_size = isset($_POST["file_size"])
                ? absint($_POST["file_size"])
                : 0;
            $upload_id = isset($_POST["upload_id"])
                ? sanitize_text_field(wp_unslash($_POST["upload_id"]))
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
                        OPTISTATE_Utils::send_rate_limit_error();
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
                OPTISTATE_Backup_Utilities::protect_temp_directory(
                    $this->main_plugin,
                    $temp_dir
                );
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
                            OPTISTATE_Backup_Utilities::cleanup_failed_upload(
                                $this->wp_filesystem,
                                $this->process_store,
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
                            OPTISTATE_Backup_Utilities::cleanup_failed_upload(
                                $this->wp_filesystem,
                                $this->process_store,
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
                            OPTISTATE_Backup_Utilities::scan_sql_for_php_threats(
                                $sample
                            )
                        ) {
                            OPTISTATE_Backup_Utilities::cleanup_failed_upload(
                                $this->wp_filesystem,
                                $this->process_store,
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
                            OPTISTATE_Backup_Utilities::cleanup_failed_upload(
                                $this->wp_filesystem,
                                $this->process_store,
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
                            OPTISTATE_Backup_Utilities::cleanup_failed_upload(
                                $this->wp_filesystem,
                                $this->process_store,
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
}