<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_TwoFactor
{
    private OPTISTATE $main_plugin;
    private const BACKUP_CODE_COUNT = 10;
    private const BACKUP_CODE_LENGTH = 8;
    private const MAX_2FA_ATTEMPTS = 6;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{64}$/';
    private bool $globally_enabled;
    private bool $completing_2fa_login = false;
    private static array $secret_cache = [];
    private static array $secret_bytes_cache = [];
    private static array $last_slice_cache = [];
    public function __construct(
        OPTISTATE $main_plugin,
        bool $globally_enabled = false
    ) {
        $this->main_plugin = $main_plugin;
        $this->globally_enabled = $globally_enabled;
        $this->register_ajax_handlers();
        if ($this->globally_enabled) {
            $this->init_hooks();
            add_action("admin_init", [
                $this,
                "verify_user_2fa_status_integrity",
            ]);
        }
    }
    public function verify_user_2fa_status_integrity(): void
    {
        $user_id = get_current_user_id();
        if ($user_id && $this->is_user_enabled($user_id)) {
            try {
                if (!$this->get_user_secret($user_id)) {
                    delete_user_meta($user_id, "optistate_2fa_enabled");
                    delete_user_meta($user_id, "optistate_2fa_verified");
                }
            } catch (Throwable $e) {
                delete_user_meta($user_id, "optistate_2fa_enabled");
                delete_user_meta($user_id, "optistate_2fa_verified");
                OPTISTATE_Utils::log_critical_error(
                    "2FA integrity check failed for user $user_id: " .
                        $e->getMessage(),
                    ["trace" => $e->getTraceAsString()]
                );
            }
        }
    }
    private function register_ajax_handlers(): void
    {
        add_action("wp_ajax_optistate_generate_2fa_secret", [
            $this,
            "ajax_generate_secret",
        ]);
        add_action("wp_ajax_optistate_verify_2fa_code", [
            $this,
            "ajax_verify_code",
        ]);
        add_action("wp_ajax_optistate_regenerate_backup_codes", [
            $this,
            "ajax_regenerate_backup_codes",
        ]);
        add_action("wp_ajax_optistate_save_two_factor_setting", [
            $this,
            "ajax_save_global_setting",
        ]);
        add_action("wp_ajax_optistate_admin_reset_2fa", [
            $this,
            "ajax_admin_reset_2fa",
        ]);
    }
    private function init_hooks(): void
    {
        add_action("show_user_profile", [$this, "user_profile_fields"]);
        add_action("edit_user_profile", [$this, "user_profile_fields"]);
        add_action("personal_options_update", [
            $this,
            "save_user_profile_fields",
        ]);
        add_action("edit_user_profile_update", [
            $this,
            "save_user_profile_fields",
        ]);
        add_filter("authenticate", [$this, "deny_xmlrpc_for_2fa_users"], 30, 3);
        add_action("wp_login", [$this, "intercept_login_for_2fa"], 1, 2);
        add_action("login_form_optistate_2fa", [$this, "handle_2fa_screen"]);
    }
    public function is_globally_enabled(): bool
    {
        return $this->globally_enabled;
    }
    public function is_user_enabled(int $user_id): bool
    {
        return (bool) get_user_meta($user_id, "optistate_2fa_enabled", true);
    }
    private function get_user_secret(int $user_id): ?string
    {
        if (array_key_exists($user_id, self::$secret_cache)) {
            return self::$secret_cache[$user_id];
        }
        $encrypted = get_user_meta($user_id, "optistate_2fa_secret", true);
        if (empty($encrypted)) {
            self::$secret_cache[$user_id] = null;
            return null;
        }
        $secret = OPTISTATE_Utils::decrypt_data($encrypted);
        self::$secret_cache[$user_id] = $secret;
        return $secret;
    }
    private function get_user_secret_bytes(int $user_id): ?string
    {
        if (array_key_exists($user_id, self::$secret_bytes_cache)) {
            return self::$secret_bytes_cache[$user_id];
        }
        $secret = $this->get_user_secret($user_id);
        if ($secret === null) {
            self::$secret_bytes_cache[$user_id] = null;
            return null;
        }
        $bytes = $this->base32_decode($secret);
        self::$secret_bytes_cache[$user_id] = $bytes;
        return $bytes;
    }
    private function set_user_secret(int $user_id, string $secret): void
    {
        $encrypted = OPTISTATE_Utils::encrypt_data($secret);
        update_user_meta($user_id, "optistate_2fa_secret", $encrypted);
        unset(
            self::$secret_cache[$user_id],
            self::$secret_bytes_cache[$user_id]
        );
        self::$secret_cache[$user_id] = $secret;
    }
    private function generate_secret(): string
    {
        $bytes = random_bytes(16);
        return $this->base32_encode($bytes);
    }
    private function base32_encode(string $bytes): string
    {
        $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
        $result = "";
        $bits = 0;
        $value = 0;
        for ($i = 0; $i < strlen($bytes); $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $result .= $alphabet[($value >> $bits - 5) & 31];
                $bits -= 5;
            }
            $value &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $result .= $alphabet[($value << 5 - $bits) & 31];
        }
        return $result;
    }
    private function base32_decode(string $data): string
    {
        $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
        $data = strtoupper(str_replace(" ", "", $data));
        $data = rtrim($data, "=");
        if (!preg_match('/^[A-Z2-7]+$/', $data)) {
            return "";
        }
        $bits = 0;
        $value = 0;
        $result = "";
        for ($i = 0; $i < strlen($data); $i++) {
            $index = strpos($alphabet, $data[$i]);
            $value = ($value << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $result .= chr(($value >> $bits - 8) & 0xff);
                $bits -= 8;
            }
            $value &= (1 << $bits) - 1;
        }
        return $result;
    }
    private function get_totp_code_from_bytes(
        string $secret_bytes,
        int $time_slice
    ): string {
        $hash = hash_hmac(
            "sha1",
            pack("N*", 0, $time_slice),
            $secret_bytes,
            true
        );
        $offset = ord($hash[19]) & 0xf;
        $binary =
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff);
        $otp = $binary % 1000000;
        return str_pad((string) $otp, 6, "0", STR_PAD_LEFT);
    }
    public function verify_totp(
        string $secret,
        string $code,
        int $user_id = 0
    ): bool {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }
        if ($user_id > 0) {
            $secret_bytes = $this->get_user_secret_bytes($user_id);
        } else {
            $secret_bytes = $this->base32_decode($secret);
        }
        if ($secret_bytes === "" || $secret_bytes === null) {
            return false;
        }
        $time = (int) floor(time() / 30);
        $last_used_slice = 0;
        if ($user_id > 0) {
            if (!array_key_exists($user_id, self::$last_slice_cache)) {
                self::$last_slice_cache[$user_id] = (int) get_user_meta(
                    $user_id,
                    "optistate_2fa_last_slice",
                    true
                );
            }
            $last_used_slice = self::$last_slice_cache[$user_id];
        }
        for ($i = -1; $i <= 1; $i++) {
            $slice = $time + $i;
            if ($slice <= $last_used_slice) {
                continue;
            }
            $test = $this->get_totp_code_from_bytes($secret_bytes, $slice);
            if (hash_equals($test, $code)) {
                if ($user_id > 0) {
                    update_user_meta(
                        $user_id,
                        "optistate_2fa_last_slice",
                        $slice
                    );
                    self::$last_slice_cache[$user_id] = $slice;
                }
                return true;
            }
        }
        return false;
    }
    private function generate_backup_codes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $code = "";
            for ($j = 0; $j < self::BACKUP_CODE_LENGTH; $j++) {
                $code .= random_int(0, 9);
            }
            $codes[] = $code;
        }
        return $codes;
    }
    private function hash_backup_codes(array $codes): array
    {
        return array_map(function ($code) {
            return wp_hash_password($code);
        }, $codes);
    }
    private function verify_backup_code(int $user_id, string $code): bool
    {
        $stored = get_user_meta($user_id, "optistate_2fa_backup_codes", true);
        if (!is_array($stored)) {
            return false;
        }
        $code = trim($code);
        foreach ($stored as $index => $hash) {
            if (wp_check_password($code, $hash, $user_id)) {
                unset($stored[$index]);
                update_user_meta(
                    $user_id,
                    "optistate_2fa_backup_codes",
                    array_values($stored)
                );
                return true;
            }
        }
        return false;
    }
    private function read_challenge_token(string $token): ?array
    {
        $raw = get_transient("optistate_2fa_token_" . $token);
        if ($raw === false || $raw === null || $raw === "") {
            return null;
        }
        if (is_array($raw) && isset($raw["user_id"])) {
            $uid = (int) $raw["user_id"];
            if ($uid <= 0) {
                return null;
            }
            return [
                "user_id" => $uid,
                "attempts" => isset($raw["attempts"])
                    ? (int) $raw["attempts"]
                    : 0,
            ];
        }
        $uid = (int) $raw;
        return $uid > 0 ? ["user_id" => $uid, "attempts" => 0] : null;
    }
    private function write_challenge_token(
        string $token,
        int $user_id,
        int $attempts,
        int $ttl = 300
    ): void {
        set_transient(
            "optistate_2fa_token_" . $token,
            ["user_id" => $user_id, "attempts" => $attempts],
            $ttl
        );
    }
    public function intercept_login_for_2fa(
        string $user_login,
        WP_User $user
    ): void {
        if ($this->completing_2fa_login) {
            return;
        }
        if (!$this->is_globally_enabled()) {
            return;
        }
        if (
            !$this->is_user_enabled($user->ID) ||
            !get_user_meta($user->ID, "optistate_2fa_verified", true)
        ) {
            return;
        }
        if (
            (defined("REST_REQUEST") && REST_REQUEST) ||
            (defined("WP_CLI") && WP_CLI) ||
            wp_doing_ajax()
        ) {
            return;
        }
        wp_clear_auth_cookie();
        $token = wp_generate_password(64, false);
        $this->write_challenge_token(
            $token,
            (int) $user->ID,
            0,
            5 * MINUTE_IN_SECONDS
        );
        $redirect_to = isset($_POST["redirect_to"])
            ? esc_url_raw(wp_unslash($_POST["redirect_to"]))
            : admin_url();
        $remember = isset($_POST["rememberme"]) ? "1" : "0";
        $two_factor_url = add_query_arg(
            [
                "action" => "optistate_2fa",
                "token" => $token,
                "redirect_to" => urlencode($redirect_to),
                "rememberme" => $remember,
            ],
            wp_login_url()
        );
        wp_safe_redirect($two_factor_url);
        exit();
    }
    public function deny_xmlrpc_for_2fa_users(
        $user,
        string $username,
        string $password
    ) {
        if (!$this->is_globally_enabled()) {
            return $user;
        }
        if (!($user instanceof WP_User)) {
            return $user;
        }
        if (
            !$this->is_user_enabled($user->ID) ||
            !get_user_meta($user->ID, "optistate_2fa_verified", true)
        ) {
            return $user;
        }
        if (defined("XMLRPC_REQUEST") && XMLRPC_REQUEST) {
            return new WP_Error(
                "optistate_2fa_required",
                __("2FA is required. XML-RPC access is blocked.", "optistate")
            );
        }
        return $user;
    }
    public function handle_2fa_screen(): void
    {
        try {
            $error_message = "";
            $token = isset($_REQUEST["token"])
                ? sanitize_text_field(wp_unslash($_REQUEST["token"]))
                : "";
            if (!preg_match(self::TOKEN_PATTERN, $token)) {
                wp_safe_redirect(wp_login_url());
                exit();
            }
            $challenge = $this->read_challenge_token($token);
            if ($challenge === null) {
                wp_safe_redirect(wp_login_url());
                exit();
            }
            $user_id = $challenge["user_id"];
            $user = get_userdata($user_id);
            if (!$user) {
                delete_transient("optistate_2fa_token_" . $token);
                wp_safe_redirect(wp_login_url());
                exit();
            }
            if ($_SERVER["REQUEST_METHOD"] === "POST") {
                $code = isset($_POST["optistate_2fa_code"])
                    ? sanitize_text_field(
                        wp_unslash($_POST["optistate_2fa_code"])
                    )
                    : "";
                $secret = $this->get_user_secret($user_id);
                if (
                    ($secret && $this->verify_totp($secret, $code, $user_id)) ||
                    $this->verify_backup_code($user_id, $code)
                ) {
                    delete_transient("optistate_2fa_token_" . $token);
                    $remember =
                        isset($_POST["rememberme"]) &&
                        $_POST["rememberme"] === "1";
                    $this->completing_2fa_login = true;
                    wp_set_auth_cookie($user_id, $remember);
                    do_action("wp_login", $user->user_login, $user);
                    $redirect_to = isset($_POST["redirect_to"])
                        ? esc_url_raw(
                            wp_unslash(urldecode($_POST["redirect_to"]))
                        )
                        : admin_url();
                    wp_safe_redirect($redirect_to);
                    exit();
                } else {
                    $challenge["attempts"]++;
                    do_action(
                        "wp_login_failed",
                        $user->user_login,
                        new WP_Error("invalid_2fa", "Failed 2FA attempt.")
                    );
                    if ($challenge["attempts"] >= self::MAX_2FA_ATTEMPTS) {
                        delete_transient("optistate_2fa_token_" . $token);
                        wp_safe_redirect(wp_login_url());
                        exit();
                    }
                    $this->write_challenge_token(
                        $token,
                        $user_id,
                        $challenge["attempts"],
                        5 * MINUTE_IN_SECONDS
                    );
                    $manual_url = esc_url(
                        OPTISTATE_PLUGIN_URL . "manual/v1-4-3.html#ch-9-6-1"
                    );
                    $error_message = sprintf(
                        __(
                            'Invalid two-factor authentication code.<br>Please try again.<br><br>Auth App unavailable?<br>➝ Enter a backup code.<br><br>Lost access to app and backup codes?<br>➝ <a href="%s" target="_blank">Read the manual</a> for recovery instructions.',
                            "optistate"
                        ),
                        $manual_url
                    );
                }
            }
            $wp_error = !empty($error_message)
                ? new WP_Error("invalid_2fa", $error_message)
                : null;
            login_header(
                __("Two-Factor Authentication", "optistate"),
                "",
                $wp_error
            );
            $redirect_to_field = isset($_REQUEST["redirect_to"])
                ? esc_url_raw(wp_unslash($_REQUEST["redirect_to"]))
                : admin_url();
            $remember_field = isset($_REQUEST["rememberme"])
                ? sanitize_text_field(wp_unslash($_REQUEST["rememberme"]))
                : "0";
            ?> <form name="loginform" id="loginform" action="<?php echo esc_url(
     add_query_arg("action", "optistate_2fa", wp_login_url())
 ); ?>" method="post"> <p> <label for="optistate_2fa_code"><?php esc_html_e(
    "Authentication Code (2FA)",
    "optistate"
); ?><br /> <input type="text" name="optistate_2fa_code" id="optistate_2fa_code" class="input" value="" size="20" maxlength="8" autocapitalize="off" autocomplete="one-time-code" autofocus="autofocus" placeholder="From Auth App" style="margin: 4px 0 6px 0px;" /></label> </p> <input type="hidden" name="token" value="<?php echo esc_attr(
    $token
); ?>" /> <input type="hidden" name="redirect_to" value="<?php echo esc_attr(
    $redirect_to_field
); ?>" /> <input type="hidden" name="rememberme" value="<?php echo esc_attr(
    $remember_field
); ?>" /> <input type="hidden" name="log" value="<?php echo esc_attr(
    $user->user_login
); ?>" /> <p class="submit"> <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e(
    "Verify Code",
    "optistate"
); ?>" /> </p> </form> <p id="nav"> <a href="<?php echo esc_url(
    wp_login_url()
); ?>"><?php esc_html_e(
    "&larr; Cancel and go back to login",
    "optistate"
); ?></a> </p> <?php
login_footer();
exit();
        } catch (Throwable $e) {
            try {
                OPTISTATE_Utils::log_critical_error(
                    "2FA screen error: " . $e->getMessage(),
                    ["trace" => $e->getTraceAsString()]
                );
                login_header(__("Two-Factor Authentication", "optistate"));
                echo '<div id="login_error"><strong>';
                esc_html_e(
                    "A system error occurred. Please try again later or contact the administrator.",
                    "optistate"
                );
                echo "</strong></div>";
                echo '<p><a href="' .
                    esc_url(wp_login_url()) .
                    '">' .
                    esc_html__("&larr; Back to login", "optistate") .
                    "</a></p>";
                login_footer();
            } catch (Throwable $e2) {
            }
            exit();
        }
    }
    private function admin_reset_js(int $target_user_id): void
    {
        $nonce = wp_create_nonce(
            "optistate_2fa_admin_reset_" . $target_user_id
        ); ?> <script type="text/javascript"> jQuery(document).ready(function($) { $('#optistate-2fa-admin-reset').on('click', function() { if (!confirm('<?php echo esc_js(__("This will disable and reset Two-Factor Authentication for this user, allowing them to log in without a code. Continue?", "optistate")); ?>')) { return; } var $btn = $(this); var $status = $('#optistate-2fa-admin-reset-status'); $btn.prop('disabled', true); $.post(ajaxurl, { action: 'optistate_admin_reset_2fa', nonce: <?php echo wp_json_encode($nonce); ?>, user_id: <?php echo (int) $target_user_id; ?> }) .done(function(response) { if (response.success) { $status.css({color: 'green', 'font-weight': 'bold'}).text('✓ <?php echo esc_js(__("2FA has been reset for this user.", "optistate")); ?>'); location.reload(); } else { var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js(__("An error occurred.", "optistate")); ?>'; $status.css({color: 'red', 'font-weight': 'bold'}).text('✗ ' + msg); } }) .fail(function(xhr) { var msg = '<?php echo esc_js(__("Network error. Please refresh and try again.", "optistate")); ?>'; if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) { msg = xhr.responseJSON.data.message; } else if (xhr.status === 403) { msg = '<?php echo esc_js(__("Access denied. Please refresh the page.", "optistate")); ?>'; } $status.css({color: 'red', 'font-weight': 'bold'}).text('✗ ' + msg); }) .always(function() { $btn.prop('disabled', false); }); }); }); </script> <?php
    }
    public function ajax_admin_reset_2fa(): void
    {
        try {
            if (!$this->is_globally_enabled()) {
                OPTISTATE_Utils::send_json_error(
                    __("2FA is disabled.", "optistate")
                );
                return;
            }
            $this->main_plugin->settings_manager->check_user_access();
            $target_user_id = isset($_POST["user_id"])
                ? absint($_POST["user_id"])
                : 0;
            if (!$target_user_id) {
                OPTISTATE_Utils::send_json_error(
                    __("Invalid user.", "optistate")
                );
                return;
            }
            check_ajax_referer(
                "optistate_2fa_admin_reset_" . $target_user_id,
                "nonce"
            );
            if ($target_user_id === get_current_user_id()) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Use your own profile page to manage your own 2FA settings.",
                        "optistate"
                    )
                );
                return;
            }
            $target_user = get_userdata($target_user_id);
            if (!$target_user) {
                OPTISTATE_Utils::send_json_error(
                    __("User not found.", "optistate")
                );
                return;
            }
            delete_user_meta($target_user_id, "optistate_2fa_verified");
            delete_user_meta($target_user_id, "optistate_2fa_secret");
            delete_user_meta($target_user_id, "optistate_2fa_backup_codes");
            unset(
                self::$secret_cache[$target_user_id],
                self::$secret_bytes_cache[$target_user_id]
            );
            self::$secret_cache[$target_user_id] = null;
            update_user_meta($target_user_id, "optistate_2fa_enabled", 0);
            $this->main_plugin->log_entry(
                sprintf(
                    "🔑 " .
                        __(
                            "Two-Factor Authentication reset for %s by {username}",
                            "optistate"
                        ),
                    $target_user->user_login
                )
            );
            OPTISTATE_Utils::send_json_success([
                "message" => __(
                    "2FA has been reset for this user.",
                    "optistate"
                ),
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_admin_reset_2fa failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred. Please try again.",
                    "optistate"
                )
            );
        }
    }
    public function user_profile_fields(WP_User $user): void
    {
        if (!$this->is_globally_enabled()) {
            return;
        }
        $is_self = (int) $user->ID === get_current_user_id();
        $enabled = $this->is_user_enabled($user->ID);
        $verified = (bool) get_user_meta(
            $user->ID,
            "optistate_2fa_verified",
            true
        );
        $secret = $is_self ? $this->get_user_secret($user->ID) : null;
        $has_secret = $is_self
            ? !empty($secret)
            : (bool) get_user_meta($user->ID, "optistate_2fa_secret", true);
        ?> <br> <h2><?php esc_html_e("Two-Factor Authentication", "optistate"); ?></h2> <table class="form-table"> <tr> <th scope="row"><?php esc_html_e("Enable 2FA", "optistate"); ?></th> <td> <?php if ($is_self): ?> <label> <input type="checkbox" name="optistate_2fa_enabled" value="1" <?php checked($enabled); ?> /> <?php esc_html_e("Enable two-factor authentication for this account", "optistate"); ?> </label> <?php else: ?> <?php if ($enabled && current_user_can("manage_options")): ?> <em><?php esc_html_e("Only the account owner can enable or disable 2FA for this account.", "optistate"); ?><br> <?php esc_html_e("If they have lost access, an administrator can reset it below.", "optistate"); ?></em> <p class="os-mt-10"> <button type="button" class="button" id="optistate-2fa-admin-reset"><?php esc_html_e("Reset 2FA for this user", "optistate"); ?></button> <span id="optistate-2fa-admin-reset-status"></span> </p> <?php else: ?> <em><?php esc_html_e("Only the account owner can enable or disable 2FA for this account.", "optistate"); ?></em> <?php endif; ?> <?php endif; ?> </td> </tr> <tr> <th scope="row"><?php esc_html_e("Status", "optistate"); ?></th> <td> <?php
$status_text = "";
$status_color = "";
if ($enabled) {
    if ($has_secret) {
        if ($verified) {
            $status_text = __("✅ Verified and Active", "optistate");
            $status_color = "#328937";
        } else {
            $status_text = __(
                "⏳ Inactive / Pending configuration",
                "optistate"
            );
            $status_color = "#ED850E";
        }
    } else {
        $status_text = __("❌ Error: missing secret", "optistate");
        $status_color = "#C22A2D";
    }
} else {
    $status_text = __("⛔ Disabled", "optistate");
    $status_color = "#898989";
}
?> <span style="color:<?php echo esc_attr($status_color); ?>; font-weight:bold;"> <?php echo esc_html($status_text); ?> </span> </td> </tr> <?php if ($is_self && $enabled): ?> <tr> <th scope="row"><?php esc_html_e("Secret Key", "optistate"); ?></th> <td> <?php if ($has_secret): ?> <div id="optistate-2fa-secret-display"> <p><code class="os-2fa-secret-code"><?php echo esc_html($secret); ?></code></p> <p><button type="button" class="button os-mt-5" id="optistate-2fa-regenerate-secret"><?php esc_html_e("Regenerate Secret", "optistate"); ?></button></p><br> <p class="description"><?php esc_html_e("Copy the Secret Key into your authenticator app, or scan the QR code below.", "optistate"); ?></p> <div id="optistate-2fa-qr-container" class="os-mt-6"> <div id="optistate-2fa-qr-initial"></div> <script type="text/javascript"> (function() { var otpauthUri = <?php echo wp_json_encode($this->get_otpauth_uri($user->user_login, $secret)); ?>; var renderInitialQr = function() { var el = document.getElementById('optistate-2fa-qr-initial'); if (el && typeof QRCode !== 'undefined') { new QRCode(el, { text: otpauthUri, width: 200, height: 200 }); } }; if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', renderInitialQr); } else { renderInitialQr(); } })(); </script> </div> </div> <div id="optistate-2fa-verify-section"><br> <p><?php esc_html_e("To confirm setup, enter the current code from your authenticator app:", "optistate"); ?></p> <input type="text" id="optistate-2fa-verify-code" placeholder="6-digit code" maxlength="6" class="os-2fa-verify-input" /> <button type="button" class="button button-primary os-2fa-verify-btn" id="optistate-2fa-verify-btn"><?php esc_html_e("Verify", "optistate"); ?></button> <p class="os-mt-5"><span id="optistate-2fa-verify-status"></span></p> </div> <?php $backup_codes_raw = get_transient("optistate_2fa_backup_raw_" . $user->ID); ?> <?php if (!empty($backup_codes_raw) && is_array($backup_codes_raw)): ?> <div id="optistate-2fa-backup-codes" class="os-mt-30"> <h4><?php esc_html_e("BACKUP CODES (save these now)", "optistate"); ?></h4> <?php esc_html_e("Each code can only be used once. Store them in a safe place. You will need them to log in if you lose access to the Authenticator app.", "optistate"); ?> <div class="os-2fa-backup-codes-list"> <?php foreach ($backup_codes_raw as $code): ?> <code class="os-2fa-backup-code"><?php echo esc_html($code); ?></code> <?php endforeach; ?> </div> <p class="description"><?php esc_html_e("These codes will not be shown again. Please save them now.", "optistate"); ?></p> <button type="button" class="button os-mt-10" id="optistate-2fa-regenerate-backup"><?php esc_html_e("Regenerate Backup Codes", "optistate"); ?></button> </div> <?php endif; ?> <?php else: ?> <p><?php esc_html_e("No secret key set. Click the button below to generate one.", "optistate"); ?></p> <button type="button" class="button" id="optistate-2fa-generate-secret"><?php esc_html_e("Generate Secret", "optistate"); ?></button> <div id="optistate-2fa-secret-generated" class="os-display-none"></div> <?php endif; ?> </td> </tr> <?php endif; ?> </table> <?php if (
     $is_self
 ) {
     wp_nonce_field("optistate_2fa_profile", "optistate_2fa_nonce");
     $this->profile_js();
 } elseif ($enabled && current_user_can("manage_options")) {
     $this->admin_reset_js((int) $user->ID);
 }
    }
    public function get_otpauth_uri(string $username, string $secret): string
    {
        $site = get_bloginfo("name");
        $label = rawurlencode($site . " (" . $username . ")");
        $issuer = rawurlencode($site);
        return "otpauth://totp/{$label}?secret=" .
            rawurlencode($secret) .
            "&issuer={$issuer}";
    }
    private function profile_js(): void
    {
        $nonce = wp_create_nonce(
            "optistate_2fa_ajax"
        ); ?> <script type="text/javascript"> var optistate2fa = { ajax_nonce: <?php echo wp_json_encode(
     $nonce
 ); ?> }; </script> <script type="text/javascript"> jQuery(document).ready(function($) { function handleAjaxError(xhr, defaultMsg) { var msg = defaultMsg; if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) { msg = xhr.responseJSON.data.message; } else if (xhr.responseJSON && xhr.responseJSON.message) { msg = xhr.responseJSON.message; } else if (xhr.status === 429) { msg = '<?php echo esc_js(
     __("Rate limit exceeded. Please wait a moment.", "optistate")
 ); ?>'; } else if (xhr.status === 403) { msg = '<?php echo esc_js(
    __("Access denied. Please refresh the page.", "optistate")
); ?>'; } return msg; } $('#optistate-2fa-generate-secret').on('click', function() { var $btn = $(this); $btn.prop('disabled', true); $.post(ajaxurl, { action: 'optistate_generate_2fa_secret', nonce: optistate2fa.ajax_nonce }) .done(function(response) { if (response.success) { $('#optistate-2fa-secret-generated').html(response.data.html).show(); var qrEl = document.getElementById('optistate-2fa-qr-generated'); if (qrEl && typeof QRCode !== 'undefined' && response.data.otpauth_uri) { new QRCode(qrEl, { text: response.data.otpauth_uri, width: 200, height: 200 }); } } else { alert(response.data.message); } }) .fail(function(xhr) { alert(handleAjaxError(xhr, '<?php echo esc_js(
    __("Network error. Please refresh and try again.", "optistate")
); ?>')); }) .always(function() { $btn.prop('disabled', false); }); }); $('#optistate-2fa-verify-btn').on('click', function() { var $btn = $(this); var code = $('#optistate-2fa-verify-code').val(); if (code.length !== 6) { alert('<?php echo esc_js(
    __("Please enter a 6-digit code.", "optistate")
); ?>'); return; } $btn.prop('disabled', true); $.post(ajaxurl, { action: 'optistate_verify_2fa_code', nonce: optistate2fa.ajax_nonce, code: code }) .done(function(response) { if (response.success) { $('#optistate-2fa-verify-status').html('<span style="color:green; font-weight: bold;">✓ <?php echo esc_js(
    __("Verified! Two-Factor Authentication is now active.", "optistate")
); ?></span>'); location.reload(); } else { $('#optistate-2fa-verify-status').html('<span style="color:red; font-weight: bold;">✗ ' + escapeHtml(response.data.message) + '</span>'); } }) .fail(function(xhr) { var msg = handleAjaxError(xhr, '<?php echo esc_js(
    __("Verification failed. Please try again.", "optistate")
); ?>'); $('#optistate-2fa-verify-status').html('<span style="color:red; font-weight: bold;">✗ ' + escapeHtml(msg) + '</span>'); }) .always(function() { $btn.prop('disabled', false); }); }); $('#optistate-2fa-regenerate-backup').on('click', function() { var $btn = $(this); $btn.prop('disabled', true); $.post(ajaxurl, { action: 'optistate_regenerate_backup_codes', nonce: optistate2fa.ajax_nonce }) .done(function(response) { if (response.success) { alert('<?php echo esc_js(
    __("Backup codes regenerated. Please save them now.", "optistate")
); ?>'); location.reload(); } else { alert(response.data.message); } }) .fail(function(xhr) { alert(handleAjaxError(xhr, '<?php echo esc_js(
    __("Network error. Please refresh and try again.", "optistate")
); ?>')); }) .always(function() { $btn.prop('disabled', false); }); }); $('#optistate-2fa-regenerate-secret').on('click', function() { if (!confirm('<?php echo esc_js(
    __(
        "Regenerating the secret will invalidate all existing authenticator app configurations and backup codes. Continue?",
        "optistate"
    )
); ?>')) { return; } var $btn = $(this); $btn.prop('disabled', true); $.post(ajaxurl, { action: 'optistate_generate_2fa_secret', nonce: optistate2fa.ajax_nonce, regenerate: 1 }) .done(function(response) { if (response.success) { location.reload(); } else { alert(response.data.message); } }) .fail(function(xhr) { alert(handleAjaxError(xhr, '<?php echo esc_js(
    __("Network error. Please refresh and try again.", "optistate")
); ?>')); }) .always(function() { $btn.prop('disabled', false); }); }); function escapeHtml(text) { if (!text) return ''; var div = document.createElement('div'); div.textContent = text; return div.innerHTML; } }); </script> <?php
    }
    public function save_user_profile_fields(int $user_id): void
    {
        if (!$this->is_globally_enabled()) {
            return;
        }
        if ($user_id !== get_current_user_id()) {
            return;
        }
        if (
            !isset($_POST["optistate_2fa_nonce"]) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST["optistate_2fa_nonce"])),
                "optistate_2fa_profile"
            )
        ) {
            return;
        }
        $enabled = isset($_POST["optistate_2fa_enabled"])
            ? (int) $_POST["optistate_2fa_enabled"]
            : 0;
        if (!$enabled) {
            delete_user_meta($user_id, "optistate_2fa_verified");
            delete_user_meta($user_id, "optistate_2fa_secret");
            delete_user_meta($user_id, "optistate_2fa_backup_codes");
            unset(
                self::$secret_cache[$user_id],
                self::$secret_bytes_cache[$user_id]
            );
            self::$secret_cache[$user_id] = null;
        }
        update_user_meta($user_id, "optistate_2fa_enabled", $enabled);
        if ($enabled && !$this->get_user_secret($user_id)) {
            try {
                $secret = $this->generate_secret();
                $this->set_user_secret($user_id, $secret);
                $codes = $this->generate_backup_codes();
                $hashed = $this->hash_backup_codes($codes);
                update_user_meta(
                    $user_id,
                    "optistate_2fa_backup_codes",
                    $hashed
                );
                set_transient(
                    "optistate_2fa_backup_raw_" . $user_id,
                    $codes,
                    5 * MINUTE_IN_SECONDS
                );
            } catch (Throwable $e) {
                delete_user_meta($user_id, "optistate_2fa_enabled");
                delete_user_meta($user_id, "optistate_2fa_secret");
                delete_user_meta($user_id, "optistate_2fa_verified");
                delete_user_meta($user_id, "optistate_2fa_backup_codes");
                unset(
                    self::$secret_cache[$user_id],
                    self::$secret_bytes_cache[$user_id]
                );
                self::$secret_cache[$user_id] = null;
                OPTISTATE_Utils::log_critical_error(
                    "2FA setup failed for user $user_id: " . $e->getMessage(),
                    ["trace" => $e->getTraceAsString()]
                );
            }
        }
    }
    public function ajax_generate_secret(): void
    {
        try {
            if (!$this->is_globally_enabled()) {
                OPTISTATE_Utils::send_json_error(
                    __("2FA is disabled.", "optistate")
                );
                return;
            }
            check_ajax_referer("optistate_2fa_ajax", "nonce");
            $user_id = get_current_user_id();
            if (!$user_id) {
                wp_die(-1);
            }
            $secret = $this->generate_secret();
            $this->set_user_secret($user_id, $secret);
            self::$secret_cache[$user_id] = $secret;
            delete_user_meta($user_id, "optistate_2fa_backup_codes");
            delete_user_meta($user_id, "optistate_2fa_verified");
            $codes = $this->generate_backup_codes();
            $hashed = $this->hash_backup_codes($codes);
            update_user_meta($user_id, "optistate_2fa_backup_codes", $hashed);
            set_transient(
                "optistate_2fa_backup_raw_" . $user_id,
                $codes,
                5 * MINUTE_IN_SECONDS
            );
            $user = get_userdata($user_id);
            $otpauth_uri = $this->get_otpauth_uri($user->user_login, $secret);
            $html =
                "<p><strong>" .
                esc_html__("Secret:", "optistate") .
                "</strong> <code>" .
                esc_html($secret) .
                "</code></p>";
            $html .= '<div id="optistate-2fa-qr-generated"></div>';
            $html .=
                '<p class="description">' .
                esc_html__(
                    "Scan this QR code with your authenticator app.",
                    "optistate"
                ) .
                "</p>";
            OPTISTATE_Utils::send_json_success([
                "html" => $html,
                "otpauth_uri" => $otpauth_uri,
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_generate_secret failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred. Please try again.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_verify_code(): void
    {
        try {
            if (!$this->is_globally_enabled()) {
                OPTISTATE_Utils::send_json_error(
                    __("2FA is disabled.", "optistate")
                );
                return;
            }
            check_ajax_referer("optistate_2fa_ajax", "nonce");
            $user_id = get_current_user_id();
            if (!$user_id) {
                wp_die(-1);
            }
            $code = isset($_POST["code"])
                ? sanitize_text_field(wp_unslash($_POST["code"]))
                : "";
            $secret = $this->get_user_secret($user_id);
            if (!$secret) {
                OPTISTATE_Utils::send_json_error(
                    __("No secret key found.", "optistate")
                );
                return;
            }
            if ($this->verify_totp($secret, $code, $user_id)) {
                update_user_meta($user_id, "optistate_2fa_verified", 1);
                OPTISTATE_Utils::send_json_success();
            } else {
                OPTISTATE_Utils::send_json_error(
                    __("Invalid code. Please try again.", "optistate")
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_verify_code failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred. Please try again.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_regenerate_backup_codes(): void
    {
        try {
            if (!$this->is_globally_enabled()) {
                OPTISTATE_Utils::send_json_error(
                    __("2FA is disabled.", "optistate")
                );
                return;
            }
            check_ajax_referer("optistate_2fa_ajax", "nonce");
            $user_id = get_current_user_id();
            if (!$user_id) {
                wp_die(-1);
            }
            $codes = $this->generate_backup_codes();
            $hashed = $this->hash_backup_codes($codes);
            update_user_meta($user_id, "optistate_2fa_backup_codes", $hashed);
            set_transient(
                "optistate_2fa_backup_raw_" . $user_id,
                $codes,
                5 * MINUTE_IN_SECONDS
            );
            OPTISTATE_Utils::send_json_success([
                "message" => __(
                    "Backup codes regenerated. Please save them.",
                    "optistate"
                ),
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_regenerate_backup_codes failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred. Please try again.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_save_global_setting(): void
    {
        try {
            check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
            $this->main_plugin->settings_manager->check_user_access();
            if (!OPTISTATE_Utils::check_rate_limit("save_settings", 3)) {
                OPTISTATE_Utils::send_json_error(
                    OPTISTATE_Utils::get_rate_limit_message(true),
                    429
                );
                return;
            }
            $enabled = isset($_POST["enabled"]) && $_POST["enabled"] === "1";
            $settings = $this->main_plugin->settings_manager->get_persistent_settings();
            $current = !empty($settings["enable_two_factor"]);
            if ($current !== $enabled) {
                $settings["enable_two_factor"] = $enabled;
                $this->main_plugin->settings_manager->save_persistent_settings(
                    $settings
                );
                $this->main_plugin->log_entry(
                    "🔑 " .
                        ($enabled
                            ? __(
                                "Two-Factor Authentication Enabled by {username}",
                                "optistate"
                            )
                            : __(
                                "Two-Factor Authentication Disabled by {username}",
                                "optistate"
                            ))
                );
                wp_cache_delete("alloptions", "options");
            }
            OPTISTATE_Utils::send_json_success([
                "message" => __(
                    "2FA settings saved successfully.",
                    "optistate"
                ),
            ]);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "ajax_save_global_setting failed: " . $e->getMessage(),
                ["trace" => $e->getTraceAsString()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while saving the setting.",
                    "optistate"
                )
            );
        }
    }
}