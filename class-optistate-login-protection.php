<?php declare(strict_types=1);
if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Login_Protection
{
    public const TABLE_NAME = "optistate_login_protect";
    private const CIDR_CACHE_KEY = "optistate_cidr_rules";
    private const RULES_VERSION_OPTION = "optistate_ip_rules_version";
    private OPTISTATE $main_plugin;
    private ?string $current_request_ip = null;
    private ?string $current_request_ip_hash = null;

    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        add_action("wp_ajax_optistate_save_login_protection", [
            $this,
            "ajax_save_settings",
        ]);
        add_action("wp_ajax_optistate_unblock_user", [
            $this,
            "ajax_unblock_user",
        ]);
        add_action("wp_ajax_optistate_save_ip_blocker", [
            $this,
            "ajax_save_ip_blocker",
        ]);
    }

    public function init_hooks(): void
    {
        $settings = (array) $this->main_plugin->settings_manager->get_persistent_settings();
        if (!empty($settings["login_protect_enabled"])) {
            add_action("login_init", [$this, "check_login_block_ui"], 1);
            add_filter("authenticate", [$this, "check_login_block_auth"], 1, 3);
            add_filter(
                "authenticate",
                [$this, "clear_on_successful_auth"],
                99,
                3
            );
            add_action(
                "wp_login_failed",
                [$this, "record_failed_login"],
                10,
                2
            );
            add_action("wp_login", [$this, "clear_login_attempts"], 10, 2);
        }
        if (!empty($settings["login_captcha_enabled"])) {
            add_action("login_form", [$this, "add_captcha_field"]);
            add_filter("authenticate", [$this, "validate_captcha"], 50, 3);
        }
        if (!empty($settings["ip_blocker_enabled"])) {
            $this->check_global_ip_block();
        }
    }

    public function create_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $sql =
            "id bigint(20) NOT NULL AUTO_INCREMENT, ip_address varchar(45) NOT NULL, user_agent varchar(255) NOT NULL DEFAULT '', attempts_count int NOT NULL DEFAULT 0, blocked_until bigint NOT NULL DEFAULT 0, created_at bigint NOT NULL DEFAULT 0, updated_at bigint NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY ip_address (ip_address), KEY blocked_until_idx (blocked_until, updated_at), KEY updated_at_idx (updated_at), KEY attempts_count_idx (attempts_count, ip_address)";
        OPTISTATE_Utils::create_table_if_not_exists($table_name, $sql, true);
    }

    private function get_ip_rules_version(): int
    {
        return (int) get_option(self::RULES_VERSION_OPTION, 0);
    }

    private function bump_ip_rules_version(): void
    {
        update_option(
            self::RULES_VERSION_OPTION,
            $this->get_ip_rules_version() + 1,
            false
        );
    }

    public function ajax_save_settings(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("save_settings", 3)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(true),
                429
            );
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (!OPTISTATE_Utils::table_exists($table_name)) {
            $this->create_table();
        }
        $current_settings = (array) $this->main_plugin->settings_manager->get_persistent_settings();
        $enabled = isset($_POST["enabled"]) && $_POST["enabled"] === "true";
        $max_attempts =
            isset($_POST["max_attempts"]) && is_scalar($_POST["max_attempts"])
                ? absint($_POST["max_attempts"])
                : 3;
        $duration =
            isset($_POST["duration"]) && is_scalar($_POST["duration"])
                ? absint($_POST["duration"])
                : 6;
        $cf_enabled =
            isset($_POST["cloudflare"]) && $_POST["cloudflare"] === "true";
        $captcha_enabled =
            isset($_POST["captcha_enabled"]) &&
            $_POST["captcha_enabled"] === "true";
        $max_attempts = max(1, $max_attempts);
        if (!in_array($duration, [1, 3, 6, 12, 24, 48], true)) {
            $duration = 6;
        }
        $status_changed =
            $enabled !==
            (bool) ($current_settings["login_protect_enabled"] ?? false);
        $cf_changed =
            $cf_enabled !==
            (bool) ($current_settings["cloudflare_enabled"] ?? false);
        $params_changed =
            $max_attempts !==
                (int) ($current_settings["login_protect_max_attempts"] ?? 3) ||
            $duration !==
                (int) ($current_settings["login_protect_block_duration"] ?? 6);
        $captcha_changed =
            $captcha_enabled !==
            (bool) ($current_settings["login_captcha_enabled"] ?? false);
        if (
            $status_changed ||
            $params_changed ||
            $cf_changed ||
            $captcha_changed
        ) {
            $new_settings = array_merge($current_settings, [
                "login_protect_enabled" => $enabled,
                "login_protect_max_attempts" => $max_attempts,
                "login_protect_block_duration" => $duration,
                "cloudflare_enabled" => $cf_enabled,
                "login_captcha_enabled" => $captcha_enabled,
            ]);
            $this->main_plugin->settings_manager->save_persistent_settings(
                $new_settings
            );
            $now = time();
            if ($status_changed || $params_changed || $cf_changed) {
                $currently_blocked = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ip_address FROM $table_name WHERE blocked_until > %d AND attempts_count != -1",
                        $now
                    )
                );
                if (!empty($currently_blocked)) {
                    foreach ($currently_blocked as $ip_entry) {
                        $this->clear_ip_transients($ip_entry);
                    }
                    $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM $table_name WHERE blocked_until > %d AND attempts_count != -1",
                            $now
                        )
                    );
                    if (!empty($wpdb->last_error)) {
                        OPTISTATE_Utils::log_critical_error(
                            "Failed to clear blocked entries on settings save",
                            ["error" => $wpdb->last_error]
                        );
                    }
                }
            }
            $username = "{username}";
            if ($status_changed) {
                $log_msg = $enabled
                    ? sprintf(
                        __("Login Protection Activated by %s", "optistate"),
                        $username
                    )
                    : sprintf(
                        __("Login Protection Deactivated by %s", "optistate"),
                        $username
                    );
                $this->main_plugin->log_entry("🛡️ " . $log_msg);
            } elseif (
                $enabled &&
                ($params_changed || $cf_changed || $captcha_changed)
            ) {
                $this->main_plugin->log_entry(
                    "🛡️ " .
                        __(
                            "Login Protection Settings Updated by {username}",
                            "optistate"
                        )
                );
            }
            if (
                $captcha_changed &&
                !$status_changed &&
                !$params_changed &&
                !$cf_changed
            ) {
                $this->main_plugin->log_entry(
                    $captcha_enabled
                        ? "🧮️ " .
                            __(
                                "Login Captcha Enabled by {username}",
                                "optistate"
                            )
                        : "🧮️ " .
                            __(
                                "Login Captcha Disabled by {username}",
                                "optistate"
                            )
                );
            }
            wp_cache_delete("alloptions", "options");
        }
        $reload_needed = $enabled && $status_changed;
        OPTISTATE_Utils::send_json_success([
            "message" => __(
                "Login protection settings saved successfully.",
                "optistate"
            ),
            "reload_needed" => $reload_needed,
        ]);
    }

    private function get_captcha_transient_key(): string
    {
        return "optistate_captcha_" .
            md5($this->get_client_ip() . ($_SERVER["HTTP_USER_AGENT"] ?? ""));
    }

    private function generate_captcha(): array
    {
        $operations = ["add", "subtract", "multiply"];
        $operation = $operations[random_int(0, count($operations) - 1)];
        switch ($operation) {
            case "subtract":
                $num1 = random_int(5, 30);
                $num2 = random_int(1, 10);
                if ($num2 > $num1) {
                    [$num1, $num2] = [$num2, $num1];
                }
                $result = $num1 - $num2;
                break;
            case "multiply":
                $num1 = random_int(2, 10);
                $num2 = random_int(2, 10);
                $result = $num1 * $num2;
                break;
            case "add":
            default:
                $num1 = random_int(5, 30);
                $num2 = random_int(1, 30);
                $result = $num1 + $num2;
                break;
        }
        set_transient(
            $this->get_captcha_transient_key(),
            ["result" => $result, "generated_at" => time()],
            5 * MINUTE_IN_SECONDS
        );
        return [$num1, $num2, $operation];
    }

    public function add_captcha_field(): void
    {
        list(
            $num1,
            $num2,
            $operation,
        ) = $this->generate_captcha(); ?> <p> <label for="optistate_captcha"> <?php switch (
     $operation
 ) {
     case "subtract":
         printf(esc_html__("What is %d − %d ?", "optistate"), $num1, $num2);
         break;
     case "multiply":
         printf(esc_html__("What is %d × %d ?", "optistate"), $num1, $num2);
         break;
     case "add":
     default:
         printf(esc_html__("What is %d + %d ?", "optistate"), $num1, $num2);
         break;
 } ?> </label> <input type="text" name="optistate_captcha" id="optistate_captcha" class="input" value="" size="3" maxlength="3" autocomplete="off" inputmode="numeric" pattern="[0-9]*" /> </p> <?php
    }

    public function validate_captcha($user, string $username, string $password)
    {
        if (empty($_POST["log"]) || empty($_POST["pwd"])) {
            return $user;
        }
        $settings = (array) $this->main_plugin->settings_manager->get_persistent_settings();
        if (empty($settings["login_captcha_enabled"])) {
            return $user;
        }
        if (
            !isset($_POST["optistate_captcha"]) ||
            $_POST["optistate_captcha"] === ""
        ) {
            return new WP_Error(
                "captcha_missing",
                __("Please solve the math captcha.", "optistate")
            );
        }
        $key = $this->get_captcha_transient_key();
        $stored = get_transient($key);
        delete_transient($key);
        if (
            $stored === false ||
            !is_array($stored) ||
            !isset($stored["result"], $stored["generated_at"])
        ) {
            return new WP_Error(
                "captcha_expired",
                __("CAPTCHA has expired. Please try again.", "optistate")
            );
        }
        $generic_error = new WP_Error(
            "captcha_error",
            __("CAPTCHA verification failed. Please try again.", "optistate")
        );
        $elapsed = time() - (int) $stored["generated_at"];
        if ($elapsed < 2) {
            return $generic_error;
        }
        $expected = (int) $stored["result"];
        $input = (int) $_POST["optistate_captcha"];
        if ($input !== $expected) {
            return $generic_error;
        }
        return $user;
    }

    public function check_login_block_ui(): void
    {
        $error = $this->is_access_blocked();
        if (!is_wp_error($error)) {
            return;
        }
        $this->render_blocked_response($error);
    }

    public function check_login_block_auth(
        $user,
        string $username,
        string $password
    ) {
        $error = $this->is_access_blocked();
        if (is_wp_error($error)) {
            wp_clear_auth_cookie();
            $this->render_blocked_response($error);
        }
        return $user;
    }

    private function render_blocked_response(WP_Error $error): void
    {
        $msg = $error->get_error_message();
        $code = 403;
        if (defined("XMLRPC_REQUEST") && XMLRPC_REQUEST) {
            status_header($code);
            nocache_headers();
            header("Content-Type: text/xml; charset=UTF-8");
            die(
                '<?xml version="1.0" encoding="UTF-8"?><methodResponse><fault><value><struct><member><n>faultCode</n><value><int>403</int></value></member><member><n>faultString</n><value><string>' .
                    esc_html($msg) .
                    "</string></value></member></struct></value></fault></methodResponse>"
            );
        }
        if (defined("REST_REQUEST") && REST_REQUEST) {
            status_header($code);
            nocache_headers();
            header("Content-Type: application/json; charset=UTF-8");
            $payload = json_encode([
                "code" => "login_blocked",
                "message" => $msg,
                "data" => ["status" => $code],
            ]);
            if ($payload === false) {
                $payload =
                    '{"code":"login_blocked","message":"Access denied.","data":{"status":403}}';
            }
            die($payload);
        }
        status_header($code);
        nocache_headers();
        $html =
            '<!DOCTYPE html> <html lang="en"> <head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <meta name="robots" content="noindex,nofollow"> <title>' .
            esc_html__("Access Denied", "optistate") .
            '</title> </head> <body> <div align="center" style="font-family: arial; margin-top: 50px;"><span style="font-size: 48px;">🚫</span> <h1>' .
            esc_html__("Access Denied", "optistate") .
            "</h1> <p>" .
            esc_html($msg) .
            "</p> <p>" .
            esc_html__(
                "If you believe this is a mistake, please contact the site administrator.",
                "optistate"
            ) .
            "</p> <div>" .
            esc_html__("-- Protected by WP Optimal State --", "optistate") .
            "</div> </div> </body> </html>";
        die($html);
    }

    public function record_failed_login(
        string $username,
        WP_Error $error = null
    ): void {
        static $already_processed = false;
        if ($already_processed) {
            return;
        }
        $already_processed = true;
        if (
            (defined("WP_CLI") && WP_CLI) ||
            (defined("DOING_CRON") && DOING_CRON)
        ) {
            return;
        }
        if (
            $error !== null &&
            in_array(
                $error->get_error_code(),
                [
                    "invalid_2fa",
                    "captcha_missing",
                    "captcha_expired",
                    "captcha_error",
                ],
                true
            )
        ) {
            return;
        }
        if (
            isset($_POST["log"]) &&
            sanitize_user(wp_unslash($_POST["log"])) !== $username
        ) {
            return;
        }
        $ip = $this->get_client_ip();
        $ip_hash = $this->get_client_ip_hash();
        delete_transient("optistate_clean_" . $ip_hash);
        if (get_transient("optistate_block_" . $ip_hash) !== false) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $settings = (array) $this->main_plugin->settings_manager->get_persistent_settings();
        $max_attempts = max(
            1,
            absint($settings["login_protect_max_attempts"] ?? 3)
        );
        $block_hours = max(
            1,
            absint($settings["login_protect_block_duration"] ?? 6)
        );
        $now = time();
        $user_agent = isset($_SERVER["HTTP_USER_AGENT"])
            ? mb_substr(
                sanitize_text_field(wp_unslash($_SERVER["HTTP_USER_AGENT"])),
                0,
                255,
                "UTF-8"
            )
            : "";
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table_name (ip_address, user_agent, attempts_count, blocked_until, created_at, updated_at) VALUES (%s, %s, 0, 0, %d, %d) ON DUPLICATE KEY UPDATE id = id",
                $ip,
                $user_agent,
                $now,
                $now
            )
        );
        if ($wpdb->last_error) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to seed login protection row",
                ["ip" => $ip, "error" => $wpdb->last_error]
            );
            $wpdb->suppress_errors($suppress);
            return;
        }
        $blocked_until = 0;
        if ($wpdb->query("START TRANSACTION") === false) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to start transaction for login protection",
                ["ip" => $ip]
            );
            $wpdb->suppress_errors($suppress);
            return;
        }
        try {
            $record = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, attempts_count FROM $table_name WHERE ip_address = %s FOR UPDATE",
                    $ip
                )
            );
            if (!empty($wpdb->last_error)) {
                throw new Exception(
                    "SELECT FOR UPDATE failed: " . $wpdb->last_error
                );
            }
            if (!$record) {
                $wpdb->query("ROLLBACK");
                $wpdb->suppress_errors($suppress);
                return;
            }
            if ((int) $record->attempts_count === -1) {
                $wpdb->query("COMMIT");
                $wpdb->suppress_errors($suppress);
                return;
            }
            $new_attempts = (int) $record->attempts_count + 1;
            if ($new_attempts >= $max_attempts) {
                $blocked_until = $now + $block_hours * HOUR_IN_SECONDS;
            }
            $update_result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table_name SET attempts_count = %d, blocked_until = %d, user_agent = %s, updated_at = %d WHERE id = %d",
                    $new_attempts,
                    $blocked_until,
                    $user_agent,
                    $now,
                    $record->id
                )
            );
            if ($update_result === false || $wpdb->last_error) {
                throw new Exception("UPDATE failed: " . $wpdb->last_error);
            }
            if ($wpdb->query("COMMIT") === false) {
                throw new Exception("COMMIT failed: " . $wpdb->last_error);
            }
        } catch (Throwable $e) {
            $wpdb->query("ROLLBACK");
            OPTISTATE_Utils::log_critical_error(
                "Transaction failed during login protection",
                ["ip" => $ip, "error" => $e->getMessage()]
            );
            $wpdb->suppress_errors($suppress);
            return;
        }
        if ($blocked_until > 0) {
            set_transient(
                "optistate_block_" . $ip_hash,
                $blocked_until,
                max(1, $blocked_until - $now)
            );
            $total_key = "optistate_total_blocked_count";
            $options_table = $wpdb->options;
            $updated_rows = (int) $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $options_table SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
                    $total_key
                )
            );
            if ($updated_rows === 0) {
                add_option($total_key, 1, "", "no");
            }
            wp_cache_delete($total_key, "options");
            $current_total = (int) get_option($total_key, 0);
            $baseline_key = "optistate_blocked_24h_baseline";
            $baseline_ts_key = "optistate_blocked_24h_baseline_ts";
            $baseline_ts = (int) get_option($baseline_ts_key, 0);
            if ($baseline_ts === 0 || $now - $baseline_ts >= DAY_IN_SECONDS) {
                update_option($baseline_key, max(0, $current_total - 1), false);
                update_option($baseline_ts_key, $now, false);
            }
        }
        $wpdb->suppress_errors($suppress);
    }

    public function clear_login_attempts(
        string $user_login,
        WP_User $user
    ): void {
        static $cleared_ips = [];
        $settings = (array) $this->main_plugin->settings_manager->get_persistent_settings();
        if (empty($settings["login_protect_enabled"])) {
            return;
        }
        $ip = $this->get_client_ip();
        $ip_hash = $this->get_client_ip_hash();
        if (isset($cleared_ips[$ip_hash])) {
            return;
        }
        $this->clear_ip_transients($ip_hash, true);
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (OPTISTATE_Utils::table_exists($table_name)) {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table_name WHERE ip_address = %s AND attempts_count != -1",
                    $ip
                )
            );
            if ($deleted === false && !empty($wpdb->last_error)) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to clear login attempts on successful login",
                    ["ip" => $ip, "error" => $wpdb->last_error]
                );
            }
        }
        $cleared_ips[$ip_hash] = true;
        do_action("optistate_login_attempts_cleared", $ip, $user_login);
    }

    public function clear_on_successful_auth($user, $username, $password)
    {
        if (!($user instanceof WP_User)) {
            return $user;
        }
        if (apply_filters("optistate_defer_login_attempt_clear", false, $user)) {
            return $user;
        }
        $this->clear_login_attempts((string) $username, $user);
        return $user;
    }

    public function cleanup_login_records(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (!OPTISTATE_Utils::table_exists($table_name)) {
            return 0;
        }
        $now = time();
        $casual_cutoff = $now - DAY_IN_SECONDS;
        $expired_blocked_ips = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ip_address FROM $table_name WHERE blocked_until > 0 AND blocked_until < %d AND attempts_count != -1",
                $now
            )
        );
        $casual_ips = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ip_address FROM $table_name WHERE blocked_until = 0 AND updated_at < %d AND attempts_count != -1",
                $casual_cutoff
            )
        );
        $deleted = 0;
        if (!empty($expired_blocked_ips) || !empty($casual_ips)) {
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table_name WHERE attempts_count != -1 AND ((blocked_until > 0 AND blocked_until < %d) OR (blocked_until = 0 AND updated_at < %d))",
                    $now,
                    $casual_cutoff
                )
            );
            if ($deleted === 0 && !empty($wpdb->last_error)) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to delete stale login entries",
                    ["error" => $wpdb->last_error]
                );
            }
            foreach (
                array_merge($expired_blocked_ips, $casual_ips)
                as $deleted_ip
            ) {
                $this->clear_ip_transients($deleted_ip);
            }
        }
        if ($deleted > 100) {
            $table_status = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT DATA_FREE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                    DB_NAME,
                    $table_name
                )
            );
            if (
                $table_status &&
                isset($table_status->DATA_FREE) &&
                $table_status->DATA_FREE > 1048576
            ) {
                $opt_result = $wpdb->query("OPTIMIZE TABLE $table_name");
                if ($opt_result === false && !empty($wpdb->last_error)) {
                    OPTISTATE_Utils::log_critical_error(
                        "Failed to OPTIMIZE login protection table",
                        ["error" => $wpdb->last_error]
                    );
                }
            }
        }
        return $deleted;
    }

    public function get_client_ip(): string
    {
        if ($this->current_request_ip !== null) {
            return $this->current_request_ip;
        }
        $settings = (array) $this->main_plugin->settings_manager->get_persistent_settings();
        $cf_enabled = !empty($settings["cloudflare_enabled"]);
        $custom_proxies = !empty($settings["custom_trusted_proxies"])
            ? $settings["custom_trusted_proxies"]
            : [];
        $ip = OPTISTATE_Utils::get_client_ip($cf_enabled, $custom_proxies);
        $this->current_request_ip = $ip;
        return $ip;
    }

    private function get_client_ip_hash(): string
    {
        if ($this->current_request_ip_hash !== null) {
            return $this->current_request_ip_hash;
        }
        $ip = $this->get_client_ip();
        $this->current_request_ip_hash = md5($ip);
        return $this->current_request_ip_hash;
    }

    private function is_access_blocked()
    {
        $ip = $this->get_client_ip();
        $ip_hash = $this->get_client_ip_hash();
        static $request_cache = [];
        if (isset($request_cache[$ip_hash])) {
            return $request_cache[$ip_hash];
        }
        $settings = $this->main_plugin->settings_manager->get_persistent_settings();
        $ip_blocker_enabled = !empty($settings["ip_blocker_enabled"]);
        if ($ip_blocker_enabled && $this->is_ip_whitelisted($ip)) {
            $request_cache[$ip_hash] = false;
            return false;
        }
        $block_key = "optistate_block_" . $ip_hash;
        $clean_key = "optistate_clean_" . $ip_hash;
        $now = time();
        $blocked_until = get_transient($block_key);
        if ($blocked_until !== false) {
            $blocked_until = (int) $blocked_until;
            if ($blocked_until > $now) {
                $error = $this->get_block_error($blocked_until);
                $request_cache[$ip_hash] = $error;
                return $error;
            }
            $this->clear_block($ip, $ip_hash);
            $request_cache[$ip_hash] = false;
            return false;
        }
        if (get_transient($clean_key)) {
            $request_cache[$ip_hash] = false;
            return false;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (!OPTISTATE_Utils::table_exists($table_name)) {
            $request_cache[$ip_hash] = false;
            return false;
        }
        $suppress = $wpdb->suppress_errors(true);
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT blocked_until FROM $table_name WHERE ip_address = %s LIMIT 1",
                $ip
            )
        );
        if ($wpdb->last_error) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to query blocked status",
                ["ip" => $ip, "error" => $wpdb->last_error]
            );
        }
        $wpdb->suppress_errors($suppress);
        if ($record) {
            if ($record->blocked_until > $now) {
                $cache_duration = $record->blocked_until - $now;
                set_transient(
                    $block_key,
                    $record->blocked_until,
                    $cache_duration
                );
                $error = $this->get_block_error($record->blocked_until);
                $request_cache[$ip_hash] = $error;
                return $error;
            }
            if ($record->blocked_until > 0) {
                $this->clear_block($ip, $ip_hash);
            }
        } else {
            set_transient($clean_key, 1, 60);
        }
        $request_cache[$ip_hash] = false;
        return false;
    }

    private function clear_block(string $ip, string $ip_hash): void
    {
        delete_transient("optistate_block_" . $ip_hash);
        delete_transient("optistate_clean_" . $ip_hash);
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (OPTISTATE_Utils::table_exists($table_name)) {
            $suppress = $wpdb->suppress_errors(true);
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table_name WHERE ip_address = %s AND attempts_count != -1",
                    $ip
                )
            );
            if ($deleted === false && !empty($wpdb->last_error)) {
                OPTISTATE_Utils::log_critical_error(
                    "Failed to clear block record from table",
                    ["ip" => $ip, "error" => $wpdb->last_error]
                );
            }
            $wpdb->suppress_errors($suppress);
        }
    }

    private function get_block_error(int $blocked_until): WP_Error
    {
        $remaining_seconds = $blocked_until - time();
        $expires_in = max(1, (int) ceil($remaining_seconds / 60));
        return new WP_Error(
            "optistate_login_blocked",
            sprintf(
                _n(
                    "Too many failed login attempts. Access blocked for %s minute.",
                    "Too many failed login attempts. Access blocked for %s minutes.",
                    $expires_in,
                    "optistate"
                ),
                number_format_i18n($expires_in)
            )
        );
    }

    private function clear_ip_transients(
        string $ip_or_hash,
        bool $is_hash = false
    ): void {
        $ip_hash = $is_hash ? $ip_or_hash : md5($ip_or_hash);
        delete_transient("optistate_block_" . $ip_hash);
        delete_transient("optistate_clean_" . $ip_hash);
    }

    public function ajax_unblock_user(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("unblock_user", 10)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(true),
                429
            );
            return;
        }
        if (!isset($_POST["ip_address"])) {
            OPTISTATE_Utils::send_json_error(
                __("IP address is required.", "optistate")
            );
            return;
        }
        $ip_address = sanitize_text_field(wp_unslash($_POST["ip_address"]));
        if (
            $ip_address === "" ||
            !OPTISTATE_Utils::validate_ip_or_cidr($ip_address)
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid IP address or CIDR range.", "optistate"),
                400
            );
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (OPTISTATE_Utils::table_exists($table_name)) {
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->delete($table_name, ["ip_address" => $ip_address], ["%s"]);
            $wpdb->suppress_errors($suppress);
        }
        $settings = $this->main_plugin->settings_manager->get_persistent_settings();
        $ip_block_list = $settings["ip_block_list"] ?? [];
        $list_modified = false;
        if (in_array($ip_address, $ip_block_list, true)) {
            $ip_block_list = array_values(
                array_diff($ip_block_list, [$ip_address])
            );
            $settings["ip_block_list"] = $ip_block_list;
            $this->main_plugin->settings_manager->save_persistent_settings(
                $settings
            );
            $list_modified = true;
        }
        $ip_hash = md5($ip_address);
        delete_transient("optistate_block_" . $ip_hash);
        delete_transient("optistate_clean_" . $ip_hash);
        delete_transient("optistate_global_block_" . $ip_hash);
        delete_transient("optistate_global_clean_" . $ip_hash);
        delete_transient(self::CIDR_CACHE_KEY);
        $this->bump_ip_rules_version();
        delete_transient("optistate_admin_blocked_ip_list");
        if (isset($this->main_plugin->performance_manager)) {
            $this->main_plugin->performance_manager->rebuild_htaccess();
        }
        $context = $list_modified
            ? __("entire website", "optistate")
            : __("login page", "optistate");
        $this->main_plugin->log_entry(
            sprintf(
                "🔓 IP %s unblocked by {username} (%s)",
                $ip_address,
                $context
            )
        );
        OPTISTATE_Utils::send_json_success([
            "message" => sprintf(
                __("User with IP %s has been unblocked.", "optistate"),
                $ip_address
            ),
        ]);
    }

    private function is_ip_whitelisted(string $ip): bool
    {
        $settings = $this->main_plugin->settings_manager->get_persistent_settings();
        $whitelist = $settings["ip_whitelist"] ?? [];
        if (empty($whitelist)) {
            return false;
        }
        if (in_array($ip, $whitelist, true)) {
            return true;
        }
        foreach ($whitelist as $range) {
            if (
                strpos($range, "/") !== false &&
                $this->ip_in_cidr($ip, $range)
            ) {
                return true;
            }
        }
        return false;
    }

    public function ajax_save_ip_blocker(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("save_settings", 3)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(true),
                429
            );
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (!OPTISTATE_Utils::table_exists($table_name)) {
            $this->create_table();
        }
        $current_settings = (array) $this->main_plugin->settings_manager->get_persistent_settings();
        $enabled = isset($_POST["enabled"]) && $_POST["enabled"] === "true";
        $ip_list_raw = isset($_POST["ip_list"])
            ? sanitize_textarea_field(wp_unslash($_POST["ip_list"]))
            : "";
        $ips = array_filter(array_map("trim", explode("\n", $ip_list_raw)));
        if (count($ips) > 200) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Maximum limit reached. You can block up to 200 IP addresses or CIDR ranges.",
                    "optistate"
                ),
                400
            );
            return;
        }
        $valid_ips = [];
        foreach ($ips as $ip) {
            if (OPTISTATE_Utils::validate_ip_or_cidr($ip)) {
                $valid_ips[] = $ip;
            }
        }
        $valid_ips = array_values(array_unique($valid_ips));
        $whitelist_raw = isset($_POST["ip_whitelist"])
            ? sanitize_textarea_field(wp_unslash($_POST["ip_whitelist"]))
            : "";
        $whitelist_ips = array_filter(
            array_map("trim", explode("\n", $whitelist_raw))
        );
        if (count($whitelist_ips) > 200) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Maximum limit reached. You can whitelist up to 200 IP addresses or CIDR ranges.",
                    "optistate"
                ),
                400
            );
            return;
        }
        $valid_whitelist = [];
        foreach ($whitelist_ips as $ip) {
            if (OPTISTATE_Utils::validate_ip_or_cidr($ip)) {
                $valid_whitelist[] = $ip;
            }
        }
        $valid_whitelist = array_values(array_unique($valid_whitelist));
        $current_user_ip = $this->get_client_ip();
        $is_self_blocked = false;
        foreach ($valid_ips as $rule) {
            if ($this->ip_in_cidr($current_user_ip, $rule)) {
                $is_self_blocked = true;
                break;
            }
        }
        if ($is_self_blocked) {
            OPTISTATE_Utils::send_json_error(
                sprintf(
                    __(
                        "Please remove your current IP number (%s) from the list and try again.",
                        "optistate"
                    ),
                    esc_html($current_user_ip)
                ),
                400
            );
            return;
        }
        $status_changed =
            $enabled !==
            (bool) ($current_settings["ip_blocker_enabled"] ?? false);
        $list_changed =
            $valid_ips !== (array) ($current_settings["ip_block_list"] ?? []);
        $whitelist_changed =
            $valid_whitelist !==
            (array) ($current_settings["ip_whitelist"] ?? []);
        if ($status_changed || $list_changed || $whitelist_changed) {
            $new_settings = array_merge($current_settings, [
                "ip_blocker_enabled" => $enabled,
                "ip_block_list" => $valid_ips,
                "ip_whitelist" => $valid_whitelist,
            ]);
            $this->main_plugin->settings_manager->save_persistent_settings(
                $new_settings
            );
            delete_transient(self::CIDR_CACHE_KEY);
            $this->bump_ip_rules_version();
            delete_transient("optistate_admin_blocked_ip_list");
            if (isset($this->main_plugin->performance_manager)) {
                $this->main_plugin->performance_manager->rebuild_htaccess();
            }
            $old_ips = $wpdb->get_col(
                "SELECT ip_address FROM $table_name WHERE attempts_count = -1"
            );
            foreach ($old_ips as $old_ip) {
                delete_transient("optistate_global_block_" . md5($old_ip));
                delete_transient("optistate_global_clean_" . md5($old_ip));
            }
            $wpdb->query("DELETE FROM $table_name WHERE attempts_count = -1");
            if (!empty($valid_ips)) {
                $now = time();
                $blocked_until = 2147483647;
                $user_agent =
                    "Manual IP Block (as per IP Number Blocker settings)";
                $placeholders = [];
                $values = [];
                foreach ($valid_ips as $ip) {
                    delete_transient("optistate_global_clean_" . md5($ip));
                    $placeholders[] = "(%s, %s, -1, %d, %d, %d)";
                    $values[] = $ip;
                    $values[] = $user_agent;
                    $values[] = $blocked_until;
                    $values[] = $now;
                    $values[] = $now;
                }
                $sql =
                    "INSERT INTO $table_name (ip_address, user_agent, attempts_count, blocked_until, created_at, updated_at) VALUES " .
                    implode(", ", $placeholders) .
                    " ON DUPLICATE KEY UPDATE attempts_count = -1, blocked_until = VALUES(blocked_until), updated_at = VALUES(updated_at)";
                $wpdb->query($wpdb->prepare($sql, ...$values));
                foreach ($valid_ips as $ip) {
                    if (strpos($ip, "/") === false) {
                        set_transient(
                            "optistate_global_block_" . md5($ip),
                            "exact",
                            DAY_IN_SECONDS
                        );
                    }
                }
            }
            $username = "{username}";
            if ($status_changed) {
                $log_msg = $enabled
                    ? sprintf(
                        __("IP Blocker Activated by %s", "optistate"),
                        $username
                    )
                    : sprintf(
                        __("IP Blocker Deactivated by %s", "optistate"),
                        $username
                    );
                $this->main_plugin->log_entry("🛑️ " . $log_msg);
            } else {
                $this->main_plugin->log_entry(
                    "🛑️ " .
                        __("IP Blocker List Updated by {username}", "optistate")
                );
            }
            wp_cache_delete("alloptions", "options");
        }
        $reload_needed = $enabled && $status_changed;
        OPTISTATE_Utils::send_json_success([
            "message" => __(
                "IP Blocker settings saved successfully.",
                "optistate"
            ),
            "reload_needed" => $reload_needed,
        ]);
    }

    public function check_global_ip_block(): void
    {
        $ip = $this->get_client_ip();
        $ip_hash = md5($ip);
        $settings = $this->main_plugin->settings_manager->get_persistent_settings();
        $ip_blocker_enabled = !empty($settings["ip_blocker_enabled"]);
        if ($ip_blocker_enabled && $this->is_ip_whitelisted($ip)) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (!OPTISTATE_Utils::table_exists($table_name)) {
            return;
        }
        $block_key = "optistate_global_block_" . $ip_hash;
        $clean_key = "optistate_global_clean_" . $ip_hash;
        $cached_block = get_transient($block_key);
        if ($cached_block !== false) {
            if ($cached_block === "exact") {
                $this->deny_global_access();
                return;
            }
            $cidr_rules = OPTISTATE_Utils::get_or_set_transient(
                self::CIDR_CACHE_KEY,
                function () use ($wpdb, $table_name) {
                    return $wpdb->get_col(
                        "SELECT ip_address FROM $table_name WHERE attempts_count = -1 AND ip_address LIKE '%/%'"
                    );
                },
                6 * HOUR_IN_SECONDS
            );
            if (in_array($cached_block, $cidr_rules, true)) {
                $this->deny_global_access();
                return;
            }
            delete_transient($block_key);
        }
        $cached_clean = get_transient($clean_key);
        if (
            is_array($cached_clean) &&
            isset($cached_clean["v"]) &&
            (int) $cached_clean["v"] === $this->get_ip_rules_version()
        ) {
            return;
        }
        $exact_hit = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM $table_name WHERE ip_address = %s AND attempts_count = -1 LIMIT 1",
                $ip
            )
        );
        if ($exact_hit) {
            set_transient($block_key, "exact", DAY_IN_SECONDS);
            $this->deny_global_access();
            return;
        }
        $cidr_rules = OPTISTATE_Utils::get_or_set_transient(
            self::CIDR_CACHE_KEY,
            function () use ($wpdb, $table_name) {
                return $wpdb->get_col(
                    "SELECT ip_address FROM $table_name WHERE attempts_count = -1 AND ip_address LIKE '%/%'"
                );
            },
            6 * HOUR_IN_SECONDS
        );
        foreach ($cidr_rules as $rule) {
            if ($this->ip_in_cidr($ip, $rule)) {
                set_transient($block_key, $rule, DAY_IN_SECONDS);
                $this->deny_global_access();
                return;
            }
        }
        set_transient(
            $clean_key,
            ["v" => $this->get_ip_rules_version()],
            6 * HOUR_IN_SECONDS
        );
    }

    private function deny_global_access(): void
    {
        if (!headers_sent()) {
            status_header(403);
            nocache_headers();
            header("Content-Type: text/html; charset=UTF-8");
        }
        $msg = esc_html__(
            "Your IP address has been permanently blocked from accessing this website.",
            "optistate"
        );
        $html =
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex,nofollow"><title>' .
            esc_html__("Access Denied", "optistate") .
            '</title></head><body><div align="center" style="font-family: arial; margin-top: 50px;"><span style="font-size: 48px;">🛑</span><h1>' .
            esc_html__("Access Denied", "optistate") .
            "</h1><p>" .
            $msg .
            "</p><div>" .
            esc_html__("-- Protected by WP Optimal State --", "optistate") .
            "</div></div></body></html>";
        die($html);
    }

    public function restore_ip_block_list(array $ips): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        if (!OPTISTATE_Utils::table_exists($table_name)) {
            $this->create_table();
        }
        $old_ips = $wpdb->get_col(
            "SELECT ip_address FROM $table_name WHERE attempts_count = -1"
        );
        foreach ($old_ips as $old_ip) {
            delete_transient("optistate_global_block_" . md5($old_ip));
            delete_transient("optistate_global_clean_" . md5($old_ip));
        }
        $wpdb->query("DELETE FROM $table_name WHERE attempts_count = -1");
        if (!empty($ips)) {
            $now = time();
            $blocked_until = 2147483647;
            $user_agent = "Manual IP Block (Imported)";
            $placeholders = [];
            $values = [];
            $valid_restore_ips = [];
            foreach ($ips as $ip) {
                if (OPTISTATE_Utils::validate_ip_or_cidr($ip)) {
                    delete_transient("optistate_global_clean_" . md5($ip));
                    $placeholders[] = "(%s, %s, -1, %d, %d, %d)";
                    $values[] = $ip;
                    $values[] = $user_agent;
                    $values[] = $blocked_until;
                    $values[] = $now;
                    $values[] = $now;
                    $valid_restore_ips[] = $ip;
                }
            }
            if (!empty($placeholders)) {
                $sql =
                    "INSERT INTO $table_name (ip_address, user_agent, attempts_count, blocked_until, created_at, updated_at) VALUES " .
                    implode(", ", $placeholders) .
                    " ON DUPLICATE KEY UPDATE attempts_count = -1, blocked_until = VALUES(blocked_until), updated_at = VALUES(updated_at)";
                $wpdb->query($wpdb->prepare($sql, ...$values));
                foreach ($valid_restore_ips as $ip) {
                    if (strpos($ip, "/") === false) {
                        set_transient(
                            "optistate_global_block_" . md5($ip),
                            "exact",
                            DAY_IN_SECONDS
                        );
                    }
                }
            }
        }
        delete_transient(self::CIDR_CACHE_KEY);
        $this->bump_ip_rules_version();
        delete_transient("optistate_admin_blocked_ip_list");
    }

    private function ip_in_cidr(string $ip, string $cidr): bool
    {
        return OPTISTATE_Utils::ip_in_range($ip, $cidr);
    }
}