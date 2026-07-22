<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Server_Caching
{
    private OPTISTATE $main_plugin;
    private string $cache_dir;
    private array $server_caching_settings = [];
    private ?bool $is_mobile_request = null;
    private ?array $combined_consent_patterns = null;
    private ?array $compiled_exclude_patterns = null;
    private ?string $exclude_urls_raw_cache = null;
    private array $cache_path_cache = [];
    private string $cookie_path;
    private string $cookie_domain;
    private static ?string $compiled_presence_regex = null;
    private ?bool $caching_enabled_cache = null;
    private const CONSENT_PRESENCE_PATTERNS = [
        "cookie_notice_accepted",
        "catAccCookies",
        "cli_user_preference",
    ];
    private const CONSENT_VALUE_PATTERNS = [
        "cookieyes-consent" =>
            '/(?:action|consent|analytics|marketing|advertisement|functional|performance|other)\s*[:=]\s*["\']?yes["\']?/i',
        "cky-consent" =>
            '/(?:action|consent|analytics|marketing|advertisement|functional|performance|other)\s*[:=]\s*["\']?yes["\']?/i',
        "complianz_consent_status" => '/^allow$/i',
        "cmplz_marketing" => '/^allow$/i',
        "cmplz_statistics" => '/^allow$/i',
        "cmplz_preferences" => '/^allow$/i',
        "borlabs-cookie" => '/"consented"\s*:\s*true/i',
        "BorlabsCookie" => '/"consented"\s*:\s*true/i',
        "real-cookie-banner" =>
            '/"(?:marketing|analytics|statistics|external-media)"\s*:\s*true/i',
        "moove_gdpr_popup" =>
            '/"(?:third_party|advanced|marketing|analytics)"\s*:\s*["\']?(?:true|1)["\']?/i',
        "CookieConsent" => "/(?:statistics|marketing|preferences)\s*:\s*true/i",
        "viewed_cookie_policy" => '/^yes$/i',
        "cookielawinfo-checkbox-necessary" => '/^yes$/i',
        "cookielawinfo-checkbox-non-necessary" => '/^yes$/i',
        "cookielawinfo-checkbox-analytics" => '/^yes$/i',
        "cookielawinfo-checkbox-marketing" => '/^yes$/i',
        "cookielawinfo-checkbox-advertisement" => '/^yes$/i',
        "cookielawinfo-checkbox-performance" => '/^yes$/i',
        "cookielawinfo-checkbox-functional" => '/^yes$/i',
        "cookielawinfo-checkbox-others" => '/^yes$/i',
        "CookieLawInfoConsent" => '/^yes$/i',
        "OptanonConsent" => "/groups=[^=]*?(?:C000[2-9])%3A1/i",
        "iubenda-cookie-consent" => '/"consent"\s*:\s*true/i',
        "_iub_cs-" => '/"consent"\s*:\s*true/i',
        "cookie_consent" => '/^accept$/i',
        "cc_cookie" => '/"categories"\s*:\s*\[/i',
    ];
    private const ALLOWED_CACHED_HEADERS = [
        "content-security-policy",
        "x-frame-options",
        "x-content-type-options",
        "strict-transport-security",
        "referrer-policy",
        "permissions-policy",
        "link",
        "x-robots-tag",
        "content-language",
    ];
    private const DEFAULT_QUERY_MODE = "include_safe";
    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        $upload_dir = wp_upload_dir();
        $this->cache_dir =
            trailingslashit($upload_dir["basedir"]) .
            OPTISTATE::CACHE_DIR_NAME .
            "/";
        $this->cookie_path =
            defined("COOKIEPATH") && COOKIEPATH ? COOKIEPATH : "/";
        $this->cookie_domain = defined("COOKIE_DOMAIN") ? COOKIE_DOMAIN : "";
    }
    public function maybe_register_hooks(): void
    {
        if (!$this->is_caching_enabled()) {
            return;
        }
        add_action(
            "transition_post_status",
            function ($new_status, $old_status, $post) {
                if ($new_status === $old_status) {
                    return;
                }
                if ($new_status === "publish" || $old_status === "publish") {
                    if (!$this->is_caching_enabled()) {
                        return;
                    }
                    $this->purge_post_and_related_urls((int) $post->ID);
                }
            },
            10,
            3
        );
        add_action("post_updated", [$this, "on_post_updated"], 10, 3);
        add_action(
            "transition_comment_status",
            function ($new_status, $old_status, $comment) {
                if ($new_status === $old_status) {
                    return;
                }
                $relevant = ["approved", "unapproved", "spam", "trash"];
                if (
                    !in_array($new_status, $relevant, true) &&
                    !in_array($old_status, $relevant, true)
                ) {
                    return;
                }
                if (!$this->is_caching_enabled()) {
                    return;
                }
                if (!empty($comment->comment_post_ID)) {
                    $this->purge_post_and_related_urls(
                        (int) $comment->comment_post_ID
                    );
                }
            },
            10,
            3
        );
        add_action("edited_term", [$this, "on_edited_term"], 10, 3);
        add_action("wp_update_nav_menu", [$this, "purge_entire_cache"]);
        add_action("updated_option", function ($option_name) {
            if (
                !is_string($option_name) ||
                strpos($option_name, "widget_") !== 0
            ) {
                return;
            }
            if (!$this->is_caching_enabled()) {
                return;
            }
            $this->purge_entire_cache();
        });
        add_action("customize_save_after", [$this, "purge_entire_cache"]);
        add_action("optistate_background_preload_batch", [
            $this,
            "process_background_preload_batch",
        ]);
        add_action("init", [$this, "validate_consent_for_session"], 20);
        if (is_admin() || (defined("DOING_CRON") && DOING_CRON)) {
            add_action("init", [$this, "check_preload_health"]);
        }
    }
    public function early_cache_check(): void
    {
        if (wp_doing_ajax() || wp_doing_cron() || is_admin()) {
            return;
        }
        if (
            !isset($_SERVER["REQUEST_METHOD"]) ||
            $_SERVER["REQUEST_METHOD"] !== "GET"
        ) {
            return;
        }
        $settings = $this->main_plugin->settings_manager->get_persistent_settings();
        if (
            !is_array($settings) ||
            empty(
                $settings["performance_features"]["server_caching"]["enabled"]
            )
        ) {
            return;
        }
        $defaults = [
            "enabled" => false,
            "lifetime" => 86400,
            "query_string_mode" => self::DEFAULT_QUERY_MODE,
            "exclude_urls" => "",
            "mobile_cache" => false,
            "disable_cookie_check" => false,
        ];
        $this->server_caching_settings = array_merge(
            $defaults,
            $settings["performance_features"]["server_caching"]
        );
        $this->is_mobile_request = !empty(
            $this->server_caching_settings["mobile_cache"]
        )
            ? $this->detect_mobile()
            : false;
        $this->maybe_serve_from_cache();
    }
    public function detect_mobile(): bool
    {
        if ($this->is_mobile_request !== null) {
            return $this->is_mobile_request;
        }
        $settings = $this->get_server_caching_settings();
        if (empty($settings["mobile_cache"])) {
            $this->is_mobile_request = false;
            return false;
        }
        $this->is_mobile_request = wp_is_mobile();
        return $this->is_mobile_request;
    }
    private function get_server_caching_settings(): array
    {
        if (empty($this->server_caching_settings)) {
            $settings = $this->main_plugin->settings_manager->get_persistent_settings();
            $this->server_caching_settings =
                $settings["performance_features"]["server_caching"] ?? [];
        }
        return $this->server_caching_settings;
    }
    private function get_combined_consent_patterns(): array
    {
        if ($this->combined_consent_patterns !== null) {
            return $this->combined_consent_patterns;
        }
        $settings = $this->get_server_caching_settings();
        $presence = self::CONSENT_PRESENCE_PATTERNS;
        $value = self::CONSENT_VALUE_PATTERNS;
        $custom_cookie_string = isset($settings["custom_consent_cookie"])
            ? (string) $settings["custom_consent_cookie"]
            : "";
        if ($custom_cookie_string !== "") {
            $normalized_string = preg_replace(
                '/[\s\r\n]+/',
                ",",
                $custom_cookie_string
            );
            if (is_string($normalized_string)) {
                $custom_patterns = array_filter(
                    array_map("trim", explode(",", $normalized_string))
                );
                if (!empty($custom_patterns)) {
                    $presence = array_values(
                        array_unique(array_merge($presence, $custom_patterns))
                    );
                }
            }
        }
        $this->combined_consent_patterns = [
            "presence" => $presence,
            "value" => $value,
        ];
        self::$compiled_presence_regex = $this->build_presence_regex($presence);
        return $this->combined_consent_patterns;
    }
    private function build_presence_regex(array $presence_patterns): string
    {
        if (empty($presence_patterns)) {
            return "";
        }
        $escaped = array_map(static function ($p) {
            return preg_quote((string) $p, "/");
        }, $presence_patterns);
        return "/(?:^|;\s*)(?:" . implode("|", $escaped) . ")[^=]*=/i";
    }
    private function has_any_consent_cookie_fast(): bool
    {
        if (empty($_SERVER["HTTP_COOKIE"])) {
            return false;
        }
        $header = (string) $_SERVER["HTTP_COOKIE"];
        if (
            preg_match("/(?:^|;\s*)optistate_session_validated\s*=/", $header)
        ) {
            return true;
        }
        if (self::$compiled_presence_regex === null) {
            $this->get_combined_consent_patterns();
        }
        $regex = self::$compiled_presence_regex;
        if ($regex !== "" && preg_match($regex, $header)) {
            return true;
        }
        return false;
    }
    public function validate_consent_for_session(): void
    {
        $server_caching = $this->get_server_caching_settings();
        if (
            empty($server_caching["enabled"]) ||
            !empty($server_caching["disable_cookie_check"])
        ) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        $has_consent = $this->has_any_consent_cookie();
        if ($has_consent) {
            if (!isset($_COOKIE["optistate_consent_flag"])) {
                $this->set_cookie_compat(
                    "optistate_consent_flag",
                    "1",
                    time() + YEAR_IN_SECONDS
                );
            }
            if (!isset($_COOKIE["optistate_session_validated"])) {
                $this->set_cookie_compat("optistate_session_validated", "1", 0);
            }
        } else {
            $past = time() - YEAR_IN_SECONDS;
            if (isset($_COOKIE["optistate_consent_flag"])) {
                $this->set_cookie_compat("optistate_consent_flag", "", $past);
            }
            if (isset($_COOKIE["optistate_session_validated"])) {
                $this->set_cookie_compat(
                    "optistate_session_validated",
                    "",
                    $past
                );
            }
        }
    }
    private function set_cookie_compat(
        string $name,
        string $value,
        int $expires
    ): void {
        $secure = is_ssl();
        $options = [
            "expires" => $expires,
            "path" => $this->cookie_path,
            "domain" => $this->cookie_domain,
            "secure" => $secure,
            "httponly" => true,
            "samesite" => "Lax",
        ];
        if (PHP_VERSION_ID >= 70300) {
            @setcookie($name, $value, $options);
        } else {
            @setcookie(
                $name,
                $value,
                $expires,
                $this->cookie_path,
                $this->cookie_domain,
                $secure,
                true
            );
        }
    }
    public function ensure_directory_and_secure(): bool
    {
        return $this->main_plugin->ensure_directory(
            $this->cache_dir,
            0755,
            OPTISTATE::HTACCESS_RULES_CACHE
        );
    }
    private function get_trusted_host(): string
    {
        static $trusted_host = null;
        if ($trusted_host !== null) {
            return $trusted_host;
        }
        $wp_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $raw_host = isset($_SERVER["HTTP_HOST"])
            ? (string) $_SERVER["HTTP_HOST"]
            : "";
        $raw_host = explode(":", $raw_host)[0];
        if ($wp_host && strcasecmp($raw_host, $wp_host) === 0) {
            $trusted_host = $wp_host;
        } elseif (isset($_SERVER["SERVER_NAME"])) {
            $trusted_host = preg_replace(
                "/[^a-zA-Z0-9\-\.]/",
                "",
                (string) $_SERVER["SERVER_NAME"]
            );
        } else {
            $trusted_host = preg_replace("/[^a-zA-Z0-9\-\.]/", "", $raw_host);
        }
        $trusted_host = strtolower((string) $trusted_host);
        return $trusted_host;
    }
    private function has_any_consent_cookie(): bool
    {
        $settings = $this->get_server_caching_settings();
        if (!empty($settings["disable_cookie_check"])) {
            return true;
        }
        if (empty($_SERVER["HTTP_COOKIE"])) {
            return false;
        }
        static $has_consent_cache = null;
        if ($has_consent_cache !== null) {
            return $has_consent_cache;
        }
        $header = (string) $_SERVER["HTTP_COOKIE"];
        if (
            preg_match("/(?:^|;\s*)optistate_session_validated\s*=/", $header)
        ) {
            $has_consent_cache = true;
            return true;
        }
        if (self::$compiled_presence_regex === null) {
            $this->get_combined_consent_patterns();
        }
        $regex = self::$compiled_presence_regex;
        if ($regex !== "" && preg_match($regex, $header)) {
            $has_consent_cache = true;
            return true;
        }
        $raw_cookies = $this->parse_cookie_header($header);
        if (empty($raw_cookies)) {
            $has_consent_cache = false;
            return false;
        }
        $map = $this->get_combined_consent_patterns();
        foreach ($map["value"] as $name_pattern => $value_regex) {
            foreach ($raw_cookies as $cookie_name => $cookie_value) {
                if (
                    $cookie_name !== $name_pattern &&
                    strpos((string) $cookie_name, (string) $name_pattern) !== 0
                ) {
                    continue;
                }
                $cookie_value = (string) $cookie_value;
                if (preg_match($value_regex, $cookie_value)) {
                    $has_consent_cache = true;
                    return true;
                }
                $decoded_value = rawurldecode($cookie_value);
                if (
                    $decoded_value !== $cookie_value &&
                    preg_match($value_regex, $decoded_value)
                ) {
                    $has_consent_cache = true;
                    return true;
                }
            }
        }
        $has_consent_cache = false;
        return false;
    }
    private function parse_cookie_header(string $raw): array
    {
        $result = [];
        $pairs = explode(";", $raw);
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === "") {
                continue;
            }
            $eq_pos = strpos($pair, "=");
            if ($eq_pos === false) {
                $result[trim($pair)] = "";
                continue;
            }
            $name = trim(substr($pair, 0, $eq_pos));
            $value = substr($pair, $eq_pos + 1);
            if ($name !== "") {
                $result[$name] = $value;
            }
        }
        return $result;
    }
    private function send_cached_page_headers(
        int $lifetime,
        int $file_time
    ): void {
        if (function_exists("apache_setenv")) {
            @apache_setenv("PHP_CACHE_HEADERS", "1");
        }
        $stale_duration = max(3600, $lifetime * 3);
        header(
            "Cache-Control: public, max-age=" .
                $lifetime .
                ", stale-while-revalidate=" .
                $stale_duration .
                ", stale-if-error=" .
                $stale_duration
        );
        header(
            "Expires: " . gmdate("D, d M Y H:i:s \G\M\T", time() + $lifetime)
        );
        header("Last-Modified: " . gmdate("D, d M Y H:i:s \G\M\T", $file_time));
        $charset = get_bloginfo("charset");
        if (empty($charset)) {
            $charset = "UTF-8";
        }
        header("Content-Type: text/html; charset=" . $charset);
        header("Vary: Accept-Encoding");
    }
    private function normalize_uri_for_key(string $raw_uri): string
    {
        $uri = wp_unslash($raw_uri);
        $hash_pos = strpos($uri, "#");
        if ($hash_pos !== false) {
            $uri = substr($uri, 0, $hash_pos);
        }
        return $uri;
    }
    public function maybe_serve_from_cache(): void
    {
        $raw_uri = isset($_SERVER["REQUEST_URI"])
            ? (string) $_SERVER["REQUEST_URI"]
            : "/";
        $request_uri = $this->normalize_uri_for_key($raw_uri);
        $cookie_header = isset($_SERVER["HTTP_COOKIE"])
            ? wp_unslash($_SERVER["HTTP_COOKIE"])
            : null;
        if ($cookie_header !== null) {
            if (
                preg_match(
                    "/(?:^|;\s*)wordpress_logged_in_[^=]*=/i",
                    $cookie_header
                )
            ) {
                return;
            }
        }
        if (isset($_GET["s"])) {
            return;
        }
        if ($cookie_header !== null) {
            if (
                preg_match(
                    "/(?:^|;\s*)(woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session|edd_items_in_cart|wp_edd_session)[^=]*=/i",
                    $cookie_header
                )
            ) {
                return;
            }
        }
        if (!empty($_SERVER["QUERY_STRING"])) {
            foreach (OPTISTATE::TRACKING_PARAMS as $param) {
                if (isset($_GET[$param])) {
                    return;
                }
            }
        }
        $patterns = $this->get_compiled_exclude_patterns();
        if (!empty($patterns)) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $request_uri)) {
                    return;
                }
            }
        }
        $server_settings = $this->get_server_caching_settings();
        $cookie_check_disabled = !empty(
            $server_settings["disable_cookie_check"]
        );
        if (!$cookie_check_disabled && !$this->has_any_consent_cookie_fast()) {
            return;
        }
        $http_host = $this->get_trusted_host();
        if ($http_host === "") {
            return;
        }
        $is_mobile = (bool) $this->is_mobile_request;
        $cache_file = $this->get_cache_path(
            $http_host,
            $request_uri,
            $is_mobile
        );
        if ($cache_file === "" || strpos($cache_file, $this->cache_dir) !== 0) {
            return;
        }
        $stat = @stat($cache_file);
        if ($stat !== false) {
            $lifetime = absint($server_settings["lifetime"] ?? 86400);
            $file_time = (int) $stat["mtime"];
            if (time() - $file_time < $lifetime) {
                $handle = @fopen($cache_file, "rb");
                if ($handle) {
                    if (!flock($handle, LOCK_SH)) {
                        fclose($handle);
                        return;
                    }
                    $header_block = fread($handle, 16384);
                    if (
                        $header_block !== false &&
                        strpos($header_block, "\n\n") !== false
                    ) {
                        $parts = explode("\n\n", $header_block, 2);
                        if (count($parts) === 2) {
                            $headers_text = $parts[0];
                            $body_start = $parts[1];
                            $cached_headers = explode("\n", $headers_text);
                            $our_headers = [
                                "cache-control",
                                "expires",
                                "last-modified",
                                "content-type",
                                "vary",
                            ];
                            foreach ($cached_headers as $h) {
                                $h = trim($h);
                                if ($h === "") {
                                    continue;
                                }
                                $colon = strpos($h, ":");
                                if ($colon === false) {
                                    continue;
                                }
                                $name = strtolower(trim(substr($h, 0, $colon)));
                                if (in_array($name, $our_headers, true)) {
                                    continue;
                                }
                                if (
                                    in_array(
                                        $name,
                                        self::ALLOWED_CACHED_HEADERS,
                                        true
                                    )
                                ) {
                                    header($h, false);
                                }
                            }
                            $this->send_cached_page_headers(
                                $lifetime,
                                $file_time
                            );
                            echo $body_start;
                            fpassthru($handle);
                            flock($handle, LOCK_UN);
                            fclose($handle);
                            exit();
                        }
                    }
                    if (
                        $header_block !== false &&
                        strlen($header_block) >= 100 &&
                        stripos($header_block, "<html") !== false
                    ) {
                        $this->send_cached_page_headers($lifetime, $file_time);
                        echo $header_block;
                        fpassthru($handle);
                        flock($handle, LOCK_UN);
                        fclose($handle);
                        exit();
                    } else {
                        flock($handle, LOCK_UN);
                        fclose($handle);
                        $this->main_plugin
                            ->get_filesystem()
                            ->delete($cache_file);
                        OPTISTATE_Utils::log_critical_error(
                            sprintf(
                                "Cache file invalid (no <html>), deleted. File: %s",
                                $cache_file
                            )
                        );
                    }
                }
            } else {
                $this->main_plugin->get_filesystem()->delete($cache_file);
            }
        }
        ob_start([$this, "capture_and_cache_output"]);
        register_shutdown_function(function () {
            $error = error_get_last();
            if (
                $error !== null &&
                in_array(
                    $error["type"],
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                    true
                )
            ) {
                @ob_end_clean();
            }
        });
    }
    private function should_not_serve_cache(): bool
    {
        if (is_user_logged_in()) {
            return true;
        }
        if (is_customize_preview()) {
            return true;
        }
        if (is_404() || post_password_required()) {
            return true;
        }
        return false;
    }
    private function get_compiled_exclude_patterns(): array
    {
        $settings = $this->get_server_caching_settings();
        $current_exclude_urls = isset($settings["exclude_urls"])
            ? (string) $settings["exclude_urls"]
            : "";
        if (
            $this->compiled_exclude_patterns !== null &&
            $this->exclude_urls_raw_cache === $current_exclude_urls
        ) {
            return $this->compiled_exclude_patterns;
        }
        $this->exclude_urls_raw_cache = $current_exclude_urls;
        if (trim($current_exclude_urls) === "") {
            $this->compiled_exclude_patterns = [];
            return [];
        }
        $excluded_paths = array_filter(
            array_map(
                "trim",
                preg_split('/\r\n|\r|\n/', $current_exclude_urls) ?: []
            ),
            static function ($path) {
                return $path !== "";
            }
        );
        $patterns = [];
        foreach ($excluded_paths as $path) {
            if (strpos($path, "*") === false) {
                $quoted = preg_quote($path, "#");
                $patterns[] = "#^" . $quoted . "\/?$#i";
            } else {
                $quoted = preg_quote($path, "#");
                $safe = str_replace("\*", ".*", $quoted);
                $patterns[] = "#" . $safe . "#i";
            }
        }
        $this->compiled_exclude_patterns = $patterns;
        return $patterns;
    }
    public function capture_and_cache_output(string $buffer): string
    {
        if (strlen($buffer) < 256 || http_response_code() !== 200) {
            return $buffer;
        }
        if ($this->should_not_serve_cache()) {
            return $buffer;
        }
        if (headers_sent()) {
            return $buffer;
        }
        if (substr($buffer, 0, 2) === "\x1f\x8b") {
            $decompressed = @gzdecode($buffer);
            if ($decompressed !== false) {
                $buffer = $decompressed;
            } else {
                return $buffer;
            }
        }
        $headers_list = headers_list();
        $content_type = "text/html";
        $has_set_cookie = false;
        foreach ($headers_list as $header) {
            $lower = strtolower($header);
            if (strpos($lower, "content-type:") === 0) {
                $content_type = trim(substr($header, strpos($header, ":") + 1));
                $content_type = strtok($content_type, ";");
                $content_type = strtolower($content_type);
            }
            if (strpos($lower, "set-cookie:") === 0) {
                $has_set_cookie = true;
                break;
            }
        }
        if ($content_type !== "text/html" || $has_set_cookie) {
            return $buffer;
        }
        try {
            $host = $this->get_trusted_host();
            if ($host === "") {
                return $buffer;
            }
            $raw_uri = isset($_SERVER["REQUEST_URI"])
                ? (string) $_SERVER["REQUEST_URI"]
                : "/";
            $uri = $this->normalize_uri_for_key($raw_uri);
            $is_mobile = $this->detect_mobile();
            $cache_file = $this->get_cache_path($host, $uri, $is_mobile);
            if (
                $cache_file === "" ||
                strpos($cache_file, $this->cache_dir) !== 0
            ) {
                return $buffer;
            }
            $cached_headers = [];
            foreach ($headers_list as $header_line) {
                $colon = strpos($header_line, ":");
                if ($colon === false) {
                    continue;
                }
                $name = strtolower(trim(substr($header_line, 0, $colon)));
                if (in_array($name, self::ALLOWED_CACHED_HEADERS, true)) {
                    $cached_headers[] = $header_line;
                }
            }
            $header_block = implode("\n", $cached_headers);
            $header_block = rtrim($header_block) . "\n\n";
            $minify_html = !empty(
                $this->get_server_caching_settings()["minify_html"]
            );
            $minified_buffer = $minify_html
                ? $this->minify_html($buffer)
                : $buffer;
            if (!headers_sent()) {
                header_remove("Content-Length");
            }
            $full_content =
                $header_block .
                $minified_buffer .
                "\n<!-- Cached by WP Optimal State Plugin -->";
            $this->write_cache_file_atomic($cache_file, $full_content);
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                sprintf("Cache capture exception: %s", $e->getMessage())
            );
        }
        return $buffer;
    }
    private function write_cache_file_atomic(
        string $cache_file,
        string $full_content
    ): bool {
        $cache_dir = dirname($cache_file);
        $this->main_plugin->ensure_directory(
            $cache_dir,
            0755,
            OPTISTATE::HTACCESS_RULES_CACHE
        );
        try {
            $token = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $token = uniqid("tmp_", true);
        }
        $temp_file = $cache_file . ".tmp." . $token;
        $handle = @fopen($temp_file, "wb");
        if ($handle === false) {
            OPTISTATE_Utils::log_critical_error(
                sprintf("Failed to open temp cache file: %s", $temp_file)
            );
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            @unlink($temp_file);
            OPTISTATE_Utils::log_critical_error(
                sprintf(
                    "Failed to acquire lock on temp cache file: %s",
                    $temp_file
                )
            );
            return false;
        }
        $written = fwrite($handle, $full_content);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($written === false || $written !== strlen($full_content)) {
            @unlink($temp_file);
            OPTISTATE_Utils::log_critical_error(
                sprintf(
                    "Failed to write temp cache file. Temp: %s, Written: %d",
                    $temp_file,
                    (int) $written
                )
            );
            return false;
        }
        if (!@rename($temp_file, $cache_file)) {
            @unlink($temp_file);
            OPTISTATE_Utils::log_critical_error(
                sprintf(
                    "Failed to rename cache file. Temp: %s, Target: %s",
                    $temp_file,
                    $cache_file
                )
            );
            return false;
        }
        @chmod($cache_file, 0644);
        return true;
    }
    private function get_safe_query_string(string $query_string): string
    {
        if ($query_string === "") {
            return "";
        }
        static $safe_params_list = null;
        if ($safe_params_list === null) {
            $default_safe_params = [
                "page",
                "paged",
                "lang",
                "replytocom",
                "s",
                "sort",
                "orderby",
                "view",
                "amp",
                "product_cat",
                "product_tag",
                "product-page",
                "brand",
                "eventDisplay",
                "eventDate",
                "forum-page",
            ];
            $user_safe_params = apply_filters(
                "optistate_safe_query_params",
                []
            );
            if (!is_array($user_safe_params)) {
                $user_safe_params = [];
            }
            $validated_user_params = array_filter(
                $user_safe_params,
                static function ($param) {
                    return is_string($param) &&
                        preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $param);
                }
            );
            $safe_params_list = array_values(
                array_unique(
                    array_merge(
                        $default_safe_params,
                        array_values($validated_user_params)
                    )
                )
            );
            if (count($safe_params_list) > 50) {
                $safe_params_list = array_slice($safe_params_list, 0, 50);
            }
        }
        $params = [];
        parse_str($query_string, $params);
        if (empty($params)) {
            return "";
        }
        $safe_params = [];
        foreach ($params as $key => $value) {
            if (in_array($key, $safe_params_list, true)) {
                $s_key = sanitize_text_field((string) $key);
                $s_value = is_array($value)
                    ? array_map("sanitize_text_field", $value)
                    : sanitize_text_field((string) $value);
                $safe_params[$s_key] = $s_value;
            }
        }
        if (empty($safe_params)) {
            return "";
        }
        ksort($safe_params);
        return http_build_query($safe_params);
    }
    private function get_cache_path(
        string $host,
        string $uri,
        ?bool $is_mobile = null
    ): string {
        $settings = $this->get_server_caching_settings();
        $is_mobile = $is_mobile ?? ($this->is_mobile_request ?? false);
        $host = strtolower($host);
        $lookup_key = $host . "|" . $uri . "|" . ($is_mobile ? "1" : "0");
        if (isset($this->cache_path_cache[$lookup_key])) {
            return $this->cache_path_cache[$lookup_key];
        }
        $parsed = wp_parse_url($uri);
        $path = $parsed["path"] ?? "/";
        if ($path !== "/" && !preg_match('/\.[a-zA-Z0-9]{1,8}$/', $path)) {
            $path = rtrim($path, "/") . "/";
        }
        $query = $parsed["query"] ?? "";
        $query_string_mode =
            $settings["query_string_mode"] ?? self::DEFAULT_QUERY_MODE;
        $cache_key_uri = $path;
        if ($query_string_mode === "unique_cache") {
            if ($query !== "") {
                $q_params = [];
                parse_str($query, $q_params);
                ksort($q_params);
                $normalized_query = http_build_query($q_params);
                $cache_key_uri =
                    $path .
                    ($normalized_query !== "" ? "?" . $normalized_query : "");
            }
        } elseif ($query_string_mode === "include_safe") {
            if ($query !== "") {
                $safe_query = $this->get_safe_query_string($query);
                if ($safe_query !== "") {
                    $cache_key_uri = $path . "?" . $safe_query;
                }
            }
        }
        $cache_key = wp_hash($host . "|" . $cache_key_uri);
        if ($is_mobile) {
            $cache_key .= "-mobile";
        }
        $result = $this->cache_dir . $cache_key . ".html";
        $this->cache_path_cache[$lookup_key] = $result;
        return $result;
    }
    public function on_post_updated(
        int $post_id,
        WP_Post $post_after,
        WP_Post $post_before
    ): void {
        if (!$this->is_caching_enabled()) {
            return;
        }
        if (
            $post_before->post_status !== "publish" ||
            $post_after->post_status !== "publish"
        ) {
            return;
        }
        if ($post_before->post_name !== $post_after->post_name) {
            $old_permalink = get_permalink($post_before);
            if (is_string($old_permalink) && $old_permalink !== "") {
                $this->purge_cache_for_url($old_permalink);
            }
        }
        $this->purge_post_and_related_urls($post_id);
    }
    public function on_edited_term(
        int $term_id,
        int $tt_id,
        string $taxonomy
    ): void {
        if (!$this->is_caching_enabled()) {
            return;
        }
        $tax_obj = get_taxonomy($taxonomy);
        if (!$tax_obj || !$tax_obj->public) {
            return;
        }
        $term_link = get_term_link($term_id, $taxonomy);
        if (!is_wp_error($term_link) && is_string($term_link)) {
            $this->purge_cache_for_url($term_link);
        }
    }
    public function ajax_purge_page_cache(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("purge_cache", 30)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        $settings = $this->get_server_caching_settings();
        try {
            $this->purge_entire_cache();
        } catch (Throwable $e) {
            $this->main_plugin->log_entry(
                sprintf(
                    "❌ " . __("Cache Purge Failed: %s", "optistate"),
                    $e->getMessage()
                )
            );
            OPTISTATE_Utils::send_json_error(
                __("Cache purge failed due to an internal error.", "optistate")
            );
            return;
        }
        $this->main_plugin->log_entry(
            "🗑️ " . esc_html__("Page Cache Purged by {username}", "optistate")
        );
        $auto_preload = !empty($settings["auto_preload"]);
        OPTISTATE_Utils::send_json_success([
            "message" => __(
                "Successfully purged the entire page cache.",
                "optistate"
            ),
            "trigger_preload" => $auto_preload,
        ]);
    }
    public function ajax_get_cache_stats(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        try {
            $filesystem = $this->main_plugin->get_filesystem();
        } catch (RuntimeException $e) {
            OPTISTATE_Utils::send_json_error(
                __("WP_Filesystem not initialized.", "optistate")
            );
            return;
        }
        $file_count = 0;
        $total_size = 0;
        $mobile_file_count = 0;
        $newest_file_time = null;
        $oldest_file_time = null;
        $files = $filesystem->dirlist($this->cache_dir);
        $MIN_VALID_TIMESTAMP = 978307200;
        if (!empty($files)) {
            foreach ($files as $file) {
                if (substr($file["name"], -5) !== ".html") {
                    continue;
                }
                if ($file["name"] === "index.html") {
                    continue;
                }
                $file_count++;
                $total_size += (int) $file["size"];
                if (!isset($file["lastmodunix"])) {
                    continue;
                }
                $file_time = (int) $file["lastmodunix"];
                if (strpos($file["name"], "-mobile.html") !== false) {
                    $mobile_file_count++;
                }
                if ($file_time >= $MIN_VALID_TIMESTAMP) {
                    if (
                        $newest_file_time === null ||
                        $file_time > $newest_file_time
                    ) {
                        $newest_file_time = $file_time;
                    }
                    if (
                        $oldest_file_time === null ||
                        $file_time < $oldest_file_time
                    ) {
                        $oldest_file_time = $file_time;
                    }
                }
            }
        }
        $average_size = $file_count > 0 ? $total_size / $file_count : 0;
        $current_time = time();
        $last_write_string =
            $newest_file_time !== null
                ? sprintf(
                    __("%s ago", "optistate"),
                    human_time_diff($newest_file_time, $current_time)
                )
                : __("N/A", "optistate");
        $oldest_page_string =
            $oldest_file_time !== null
                ? sprintf(
                    __("%s ago", "optistate"),
                    human_time_diff($oldest_file_time, $current_time)
                )
                : __("N/A", "optistate");
        OPTISTATE_Utils::send_json_success([
            "file_count" => $file_count,
            "total_size" => size_format($total_size, 2),
            "mobile_file_count" => $mobile_file_count,
            "average_size" => size_format($average_size, 2),
            "last_write" => $last_write_string,
            "oldest_page" => $oldest_page_string,
        ]);
    }
    public function purge_cache_for_url(string $url): void
    {
        if (!$this->is_caching_enabled()) {
            return;
        }
        if ($url === "") {
            return;
        }
        $filesystem = $this->main_plugin->get_filesystem();
        $parsed_url = wp_parse_url($url);
        if (
            !$parsed_url ||
            empty($parsed_url["host"]) ||
            empty($parsed_url["path"])
        ) {
            return;
        }
        $host = strtolower((string) $parsed_url["host"]);
        $path = (string) $parsed_url["path"];
        $uri = !empty($parsed_url["query"])
            ? $path . "?" . $parsed_url["query"]
            : $path;
        $uri = $this->normalize_uri_for_key($uri);
        $cache_path_desktop = $this->get_cache_path($host, $uri, false);
        $settings = $this->get_server_caching_settings();
        $cache_path_mobile = null;
        if (!empty($settings["mobile_cache"])) {
            $cache_path_mobile = $this->get_cache_path($host, $uri, true);
        }
        if (
            $cache_path_desktop !== "" &&
            strpos($cache_path_desktop, $this->cache_dir) === 0 &&
            $filesystem->exists($cache_path_desktop)
        ) {
            $filesystem->delete($cache_path_desktop);
        }
        if (
            $cache_path_mobile !== null &&
            strpos($cache_path_mobile, $this->cache_dir) === 0 &&
            $filesystem->exists($cache_path_mobile)
        ) {
            $filesystem->delete($cache_path_mobile);
        }
    }
    public function purge_post_and_related_urls(int $post_id): void
    {
        if (!$this->is_caching_enabled()) {
            return;
        }
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        $filesystem = $this->main_plugin->get_filesystem();
        if (!$filesystem->is_dir($this->cache_dir)) {
            return;
        }
        $lock_file = $this->cache_dir . ".purge.lock";
        $lock_handle = @fopen($lock_file, "c");
        if ($lock_handle === false) {
            $this->execute_purge_for_post($post);
            return;
        }
        $lock_acquired = false;
        $lock_attempts = 0;
        $max_attempts = 10;
        $start_time = microtime(true);
        $max_wait = 2.0;
        while (
            !$lock_acquired &&
            $lock_attempts < $max_attempts &&
            microtime(true) - $start_time < $max_wait
        ) {
            $lock_acquired = flock($lock_handle, LOCK_EX | LOCK_NB);
            if (!$lock_acquired) {
                usleep(100000);
                $lock_attempts++;
            }
        }
        if (!$lock_acquired) {
            fclose($lock_handle);
            $this->execute_purge_for_post($post);
            return;
        }
        try {
            $this->execute_purge_for_post($post);
        } finally {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
        }
    }
    private function execute_purge_for_post(WP_Post $post): void
    {
        $permalink = get_permalink($post);
        if (is_string($permalink) && $permalink !== "") {
            $this->purge_cache_for_url($permalink);
        }
        $this->purge_cache_for_url(home_url("/"));
        if (get_option("show_on_front") === "page") {
            $posts_page_id = (int) get_option("page_for_posts");
            if ($posts_page_id) {
                $posts_page_link = get_permalink($posts_page_id);
                if (is_string($posts_page_link) && $posts_page_link !== "") {
                    $this->purge_cache_for_url($posts_page_link);
                }
            }
        }
        foreach (["rss2_url", "atom_url", "comments_rss2_url"] as $feed_key) {
            $feed_url = get_bloginfo($feed_key);
            if (is_string($feed_url) && $feed_url !== "") {
                $this->purge_cache_for_url($feed_url);
            }
        }
        $post_type_archive_link = get_post_type_archive_link(
            get_post_type($post)
        );
        if ($post_type_archive_link) {
            $this->purge_cache_for_url($post_type_archive_link);
        }
        $author_url = get_author_posts_url((int) $post->post_author);
        if (is_string($author_url) && $author_url !== "") {
            $this->purge_cache_for_url($author_url);
        }
        $taxonomies = get_object_taxonomies($post, "public");
        if (!empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($post->ID, $taxonomy);
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        $term_link = get_term_link($term, $taxonomy);
                        if (!is_wp_error($term_link) && is_string($term_link)) {
                            $this->purge_cache_for_url($term_link);
                        }
                        $this->purge_paginated_term_archive($term, $taxonomy);
                    }
                }
            }
        }
    }
    private function purge_paginated_term_archive(
        WP_Term $term,
        string $taxonomy
    ): void {
        if (!$this->is_caching_enabled()) {
            return;
        }
        $per_page = max(1, (int) get_option("posts_per_page"));
        $total_pages = (int) ceil($term->count / $per_page);
        if ($total_pages > 1) {
            $term_link = get_term_link($term, $taxonomy);
            if (!is_wp_error($term_link) && is_string($term_link)) {
                $max_pages = min($total_pages, 50);
                for ($i = 2; $i <= $max_pages; $i++) {
                    $this->purge_cache_for_url(
                        trailingslashit($term_link) . "page/" . $i . "/"
                    );
                }
            }
        }
    }
    public function purge_entire_cache(): void
    {
        if (!$this->is_caching_enabled()) {
            return;
        }
        $filesystem = $this->main_plugin->get_filesystem();
        if ($filesystem->is_dir($this->cache_dir)) {
            $filesystem->delete($this->cache_dir, true);
        }
        wp_mkdir_p($this->cache_dir);
        $filesystem->chmod($this->cache_dir, 0755);
        delete_transient("optistate_dir_exists_" . md5($this->cache_dir));
        $this->ensure_directory_and_secure();
        $this->reset_preload_state();
        $this->cache_path_cache = [];
    }
    private function reset_preload_state(): void
    {
        delete_transient("optistate_preload_running");
        delete_transient("optistate_preload_urls");
        delete_transient("optistate_preload_processed");
        delete_transient("optistate_preload_total");
        delete_transient("optistate_preload_urls_remaining");
        delete_transient("optistate_preload_batch_size");
        delete_transient("optistate_preload_hash");
        wp_clear_scheduled_hook("optistate_background_preload_batch");
        $this->release_preload_lock();
    }
    private function is_caching_enabled(): bool
    {
        if ($this->caching_enabled_cache !== null) {
            return $this->caching_enabled_cache;
        }
        $settings = $this->get_server_caching_settings();
        $this->caching_enabled_cache = !empty($settings["enabled"]);
        return $this->caching_enabled_cache;
    }
    private function get_sitemap_urls(): array
    {
        $found_urls = [];
        $sitemaps_from_robots = [];
        $robots_url = home_url("/robots.txt");
        $ssl_verify = (bool) apply_filters("https_ssl_verify", true);
        $response = wp_safe_remote_get($robots_url, [
            "timeout" => 20,
            "sslverify" => $ssl_verify,
            "user-agent" => "WP Optimal State Cache Preloader",
        ]);
        if (
            !is_wp_error($response) &&
            wp_remote_retrieve_response_code($response) === 200
        ) {
            $body = (string) wp_remote_retrieve_body($response);
            if ($body !== "") {
                foreach (explode("\n", $body) as $line) {
                    if (preg_match("/^\s*Sitemap:\s*(.*)/i", $line, $matches)) {
                        $sitemap_url = trim($matches[1]);
                        if (
                            $sitemap_url !== "" &&
                            filter_var($sitemap_url, FILTER_VALIDATE_URL)
                        ) {
                            $sitemaps_from_robots[] = $sitemap_url;
                        }
                    }
                }
            }
        }
        if (!empty($sitemaps_from_robots)) {
            $found_urls = $this->parse_sitemaps_from_list(
                $sitemaps_from_robots
            );
        }
        if (empty($found_urls)) {
            $home_url = trailingslashit(home_url());
            $found_urls = $this->parse_sitemaps_from_list(
                array_map(static fn($s) => $home_url . $s, [
                    "sitemap.xml",
                    "sitemap_index.xml",
                    "wp-sitemap.xml",
                    "sitemap-index.xml",
                    "main-sitemap.xml",
                ])
            );
        }
        $found_urls = array_unique($found_urls);
        $home_url_check = trailingslashit(home_url());
        return array_values(
            array_filter($found_urls, static function ($url) use (
                $home_url_check
            ) {
                return is_string($url) &&
                    $url !== "" &&
                    strpos($url, $home_url_check) === 0;
            })
        );
    }
    private function parse_sitemap(
        string $initial_sitemap_url,
        int $max_depth = 10
    ): array {
        static $ssl_error_logged = false;
        $all_discovered_urls = [];
        $sitemap_queue = [["url" => $initial_sitemap_url, "depth" => 0]];
        $processed_sitemaps = [];
        $xml_options = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        $ssl_verify = (bool) apply_filters("https_ssl_verify", true);
        while (!empty($sitemap_queue)) {
            $current = array_shift($sitemap_queue);
            $current_url = (string) $current["url"];
            $current_depth = (int) $current["depth"];
            if (
                $current_depth > $max_depth ||
                isset($processed_sitemaps[$current_url])
            ) {
                continue;
            }
            $processed_sitemaps[$current_url] = true;
            $response = wp_safe_remote_get($current_url, [
                "timeout" => 30,
                "sslverify" => $ssl_verify,
                "headers" => [
                    "Accept" => "text/xml, application/xml, text/plain, */*",
                ],
                "user-agent" => "WP Optimal State Cache Preloader",
            ]);
            if (is_wp_error($response)) {
                $error_code = (string) $response->get_error_code();
                if (
                    (strpos($error_code, "ssl") !== false ||
                        strpos($error_code, "certificate") !== false) &&
                    !$ssl_error_logged
                ) {
                    $ssl_error_logged = true;
                    OPTISTATE_Utils::log_critical_error(
                        "Sitemap preload SSL error: " .
                            $response->get_error_message(),
                        ["url" => $current_url, "error_code" => $error_code]
                    );
                }
                continue;
            }
            $body = (string) wp_remote_retrieve_body($response);
            if ($body === "" || strlen($body) > 3145728) {
                continue;
            }
            $trimmed_body = ltrim($body);
            if (strpos($trimmed_body, "<") !== 0) {
                continue;
            }
            $prev_disable_entity_loader = null;
            if (
                PHP_VERSION_ID < 80000 &&
                function_exists("libxml_disable_entity_loader")
            ) {
                $prev_disable_entity_loader = libxml_disable_entity_loader(
                    true
                );
            }
            libxml_use_internal_errors(true);
            libxml_clear_errors();
            $xml = @simplexml_load_string(
                $trimmed_body,
                "SimpleXMLElement",
                $xml_options
            );
            if (
                !$xml instanceof SimpleXMLElement &&
                class_exists("DOMDocument")
            ) {
                $dom = new DOMDocument();
                $dom->resolveExternals = false;
                $dom->substituteEntities = false;
                $dom->recover = true;
                if (@$dom->loadXML($trimmed_body, $xml_options)) {
                    $xml = simplexml_import_dom($dom);
                }
            }
            libxml_clear_errors();
            if (
                $prev_disable_entity_loader !== null &&
                function_exists("libxml_disable_entity_loader")
            ) {
                libxml_disable_entity_loader($prev_disable_entity_loader);
            }
            if (!$xml instanceof SimpleXMLElement) {
                continue;
            }
            $namespaces = $xml->getDocNamespaces(true);
            $elements = isset($namespaces[""])
                ? $xml->children($namespaces[""])
                : $xml;
            if (isset($elements->sitemap)) {
                foreach ($elements->sitemap as $sitemap) {
                    if (isset($sitemap->loc)) {
                        $sitemap_queue[] = [
                            "url" => trim((string) $sitemap->loc),
                            "depth" => $current_depth + 1,
                        ];
                    }
                }
            }
            if (isset($elements->url)) {
                foreach ($elements->url as $url) {
                    if (isset($url->loc)) {
                        $clean_url = trim((string) $url->loc);
                        if ($clean_url !== "") {
                            $all_discovered_urls[$clean_url] = true;
                        }
                    }
                }
            }
        }
        return array_keys($all_discovered_urls);
    }
    private function parse_sitemaps_from_list(array $sitemap_urls): array
    {
        $found_urls = [];
        foreach ($sitemap_urls as $sitemap_url) {
            if (!is_string($sitemap_url) || $sitemap_url === "") {
                continue;
            }
            $urls = $this->parse_sitemap($sitemap_url);
            if (!empty($urls)) {
                array_push($found_urls, ...$urls);
            }
        }
        return $found_urls;
    }
    private function preload_url(string $url, bool $is_mobile = false): bool
    {
        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed["host"]) || empty($parsed["path"])) {
            return false;
        }
        $host = (string) $parsed["host"];
        $uri = (string) $parsed["path"];
        if (!empty($parsed["query"])) {
            $uri .= "?" . $parsed["query"];
        }
        $cache_file = $this->get_cache_path($host, $uri, $is_mobile);
        if ($cache_file === "" || strpos($cache_file, $this->cache_dir) !== 0) {
            return false;
        }
        $settings = $this->get_server_caching_settings();
        $lifetime = isset($settings["lifetime"])
            ? absint($settings["lifetime"])
            : 86400;
        $stat = @stat($cache_file);
        if ($stat !== false) {
            $file_time = (int) $stat["mtime"];
            if (time() - $file_time < $lifetime) {
                return true;
            }
        }
        $user_agent = $is_mobile
            ? "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1"
            : "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
        $ssl_verify = (bool) apply_filters("https_ssl_verify", true);
        $args = [
            "timeout" => 30,
            "sslverify" => $ssl_verify,
            "user-agent" => $user_agent,
            "cookies" => [
                new WP_Http_Cookie([
                    "name" => "optistate_session_validated",
                    "value" => "1",
                ]),
            ],
        ];
        $response = wp_safe_remote_get($url, $args);
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }
        $content_type = wp_remote_retrieve_header($response, "content-type");
        if (is_array($content_type)) {
            $content_type = reset($content_type);
        }
        $content_type = (string) $content_type;
        if (
            $content_type === "" ||
            stripos($content_type, "text/html") === false
        ) {
            return false;
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === "" || strlen($body) < 256) {
            return false;
        }
        $cached_headers = [];
        foreach (self::ALLOWED_CACHED_HEADERS as $header_name) {
            $header_value = wp_remote_retrieve_header($response, $header_name);
            if (is_array($header_value)) {
                $header_value = reset($header_value);
            }
            $header_value = (string) $header_value;
            if ($header_value !== "") {
                $cached_headers[] = $header_name . ": " . $header_value;
            }
        }
        $header_block = implode("\n", $cached_headers);
        $header_block = rtrim($header_block) . "\n\n";
        $minify = !empty($settings["minify_html"]);
        if ($minify) {
            $body = $this->minify_html($body);
        }
        $full_content =
            $header_block .
            $body .
            "\n<!-- Cached by WP Optimal State Plugin -->";
        $result = $this->write_cache_file_atomic($cache_file, $full_content);
        if (!$result) {
            OPTISTATE_Utils::log_critical_error(
                sprintf(
                    "Preload write failed. URL: %s, Cache File: %s",
                    $url,
                    $cache_file
                )
            );
        }
        return $result;
    }
    private function filter_preload_urls(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }
        $settings = $this->get_server_caching_settings();
        $filtered = [];
        $patterns = $this->get_compiled_exclude_patterns();
        foreach ($urls as $url) {
            if (!is_string($url) || $url === "") {
                continue;
            }
            $parsed = wp_parse_url($url);
            if (!$parsed || empty($parsed["path"])) {
                continue;
            }
            $path = (string) $parsed["path"];
            $query = isset($parsed["query"]) ? (string) $parsed["query"] : "";
            if (!empty($patterns)) {
                $excluded = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $path)) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) {
                    continue;
                }
            }
            $query_mode =
                $settings["query_string_mode"] ?? self::DEFAULT_QUERY_MODE;
            if ($query !== "") {
                if ($query_mode === "ignore_all") {
                    $url = strtok($url, "?");
                } elseif ($query_mode === "include_safe") {
                    $safe_query = $this->get_safe_query_string($query);
                    $url =
                        strtok($url, "?") .
                        ($safe_query !== "" ? "?" . $safe_query : "");
                }
            }
            if (is_string($url) && $url !== "") {
                $filtered[] = $url;
            }
        }
        return array_values(array_unique($filtered));
    }
    private function try_acquire_preload_lock(): bool
    {
        $lock_name = "optistate_preload_process_lock";
        $unique = wp_generate_password(20, false, false);
        $added = add_option($lock_name, $unique . "|" . time(), "", "no");
        if ($added) {
            set_transient($lock_name, $unique, 60);
            return true;
        }
        $existing = get_option($lock_name, "");
        if (is_string($existing) && $existing !== "") {
            $parts = explode("|", $existing, 2);
            $stamp = isset($parts[1]) ? (int) $parts[1] : 0;
            if ($stamp > 0 && time() - $stamp > 60) {
                update_option($lock_name, $unique . "|" . time(), "no");
                set_transient($lock_name, $unique, 60);
                return true;
            }
        }
        return false;
    }
    private function release_preload_lock(): void
    {
        delete_transient("optistate_preload_process_lock");
        delete_option("optistate_preload_process_lock");
    }
    private function renew_preload_lock(): void
    {
        $lock_name = "optistate_preload_process_lock";
        $existing = get_option($lock_name, "");
        if (!is_string($existing) || $existing === "") {
            return;
        }
        $parts = explode("|", $existing, 2);
        $token = $parts[0] ?? "";
        if ($token === "") {
            return;
        }
        update_option($lock_name, $token . "|" . time(), "no");
        set_transient($lock_name, $token, 60);
    }
    public function ajax_start_preload(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        try {
            if (get_transient("optistate_preload_running")) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __("Preload already in progress", "optistate"),
                ]);
                return;
            }
            $lock_acquired = false;
            for ($i = 0; $i < 3; $i++) {
                if ($this->try_acquire_preload_lock()) {
                    $lock_acquired = true;
                    break;
                }
                usleep(100000);
            }
            if (!$lock_acquired) {
                OPTISTATE_Utils::send_json_error([
                    "message" => __(
                        "Preload is starting in another tab",
                        "optistate"
                    ),
                ]);
                return;
            }
            $urls = $this->get_sitemap_urls();
            if (empty($urls)) {
                $this->release_preload_lock();
                $error_msg = __("No URLs found in sitemap", "optistate");
                $this->main_plugin->log_entry(
                    "❌ " .
                        __("Cache Preload Failed: ", "optistate") .
                        $error_msg,
                    "scheduled"
                );
                OPTISTATE_Utils::send_json_error(["message" => $error_msg]);
                return;
            }
            $urls = $this->filter_preload_urls($urls);
            if (empty($urls)) {
                $this->release_preload_lock();
                $error_msg = __(
                    "No valid URLs to preload after filtering",
                    "optistate"
                );
                $this->main_plugin->log_entry(
                    "❌ " .
                        __("Cache Preload Failed: ", "optistate") .
                        $error_msg,
                    "scheduled"
                );
                OPTISTATE_Utils::send_json_error(["message" => $error_msg]);
                return;
            }
            $url_count = count($urls);
            $this->main_plugin->log_entry(
                "▶️ " .
                    sprintf(
                        __(
                            "Cache Preload Started by the system (%d pages)",
                            "optistate"
                        ),
                        $url_count
                    ),
                "scheduled"
            );
            set_transient(
                "optistate_preload_urls_remaining",
                $urls,
                HOUR_IN_SECONDS
            );
            set_transient(
                "optistate_preload_total",
                $url_count,
                HOUR_IN_SECONDS
            );
            set_transient("optistate_preload_processed", 0, HOUR_IN_SECONDS);
            set_transient("optistate_preload_batch_size", 10, HOUR_IN_SECONDS);
            set_transient("optistate_preload_running", true, HOUR_IN_SECONDS);
            if (!wp_next_scheduled("optistate_background_preload_batch")) {
                wp_schedule_single_event(
                    time() + 1,
                    "optistate_background_preload_batch"
                );
            }
            $this->release_preload_lock();
            OPTISTATE_Utils::send_json_success([
                "status" => "starting",
                "total" => $url_count,
                "message" => sprintf(
                    __("Starting preload of %s pages...", "optistate"),
                    number_format_i18n($url_count)
                ),
            ]);
        } catch (\Throwable $e) {
            $this->release_preload_lock();
            $error_msg = $e->getMessage();
            $this->main_plugin->log_entry(
                "❌ " . __("Cache Preload Failed: ", "optistate") . $error_msg,
                "scheduled"
            );
            OPTISTATE_Utils::send_json_error([
                "message" =>
                    __("Preload initialization failed: ", "optistate") .
                    $error_msg,
            ]);
        }
    }
    public function process_background_preload_batch(): void
    {
        if (!get_transient("optistate_preload_running")) {
            return;
        }
        if (!$this->try_acquire_preload_lock()) {
            if (!wp_next_scheduled("optistate_background_preload_batch")) {
                wp_schedule_single_event(
                    time() + 5,
                    "optistate_background_preload_batch"
                );
            }
            return;
        }
        try {
            $urls_remaining = get_transient("optistate_preload_urls_remaining");
            $processed =
                (int) (get_transient("optistate_preload_processed") ?: 0);
            $total = (int) (get_transient("optistate_preload_total") ?: 0);
            $batch_size =
                (int) (get_transient("optistate_preload_batch_size") ?: 10);
            if (empty($urls_remaining) || $processed >= $total) {
                $this->complete_preload_process($total);
                return;
            }
            $start_time = microtime(true);
            $target_duration = 6.0;
            $batch = array_slice((array) $urls_remaining, 0, $batch_size);
            $new_urls_remaining = array_slice(
                (array) $urls_remaining,
                count($batch)
            );
            $settings = $this->get_server_caching_settings();
            $mobile_cache = !empty($settings["mobile_cache"]);
            $processed_in_batch = 0;
            $last_lock_renew = microtime(true);
            foreach ($batch as $url) {
                $this->preload_url((string) $url, false);
                if ($mobile_cache) {
                    $this->preload_url((string) $url, true);
                }
                $processed_in_batch++;
                if (microtime(true) - $last_lock_renew > 10) {
                    $this->renew_preload_lock();
                    $last_lock_renew = microtime(true);
                }
                if (microtime(true) - $start_time > $target_duration * 1.5) {
                    $remaining_in_batch = array_slice(
                        $batch,
                        $processed_in_batch
                    );
                    $new_urls_remaining = array_merge(
                        $remaining_in_batch,
                        $new_urls_remaining
                    );
                    break;
                }
            }
            $processed += $processed_in_batch;
            $duration = max(0.5, microtime(true) - $start_time);
            $new_batch_size = max(
                10,
                min(60, (int) ($batch_size * ($target_duration / $duration)))
            );
            set_transient(
                "optistate_preload_processed",
                $processed,
                HOUR_IN_SECONDS
            );
            set_transient(
                "optistate_preload_urls_remaining",
                $new_urls_remaining,
                HOUR_IN_SECONDS
            );
            set_transient(
                "optistate_preload_batch_size",
                $new_batch_size,
                HOUR_IN_SECONDS
            );
            if (!empty($new_urls_remaining) && $processed < $total) {
                if (!wp_next_scheduled("optistate_background_preload_batch")) {
                    wp_schedule_single_event(
                        time() + 1,
                        "optistate_background_preload_batch"
                    );
                }
            } else {
                $this->complete_preload_process($total);
            }
        } finally {
            $this->release_preload_lock();
        }
    }
    private function complete_preload_process(int $total): void
    {
        delete_transient("optistate_preload_urls_remaining");
        delete_transient("optistate_preload_total");
        delete_transient("optistate_preload_processed");
        delete_transient("optistate_preload_running");
        delete_transient("optistate_preload_batch_size");
        delete_transient("optistate_preload_hash");
        wp_clear_scheduled_hook("optistate_background_preload_batch");
        $this->release_preload_lock();
        $this->main_plugin->log_entry(
            sprintf(
                "🏁 " .
                    __(
                        "Cache Preload Completed (Cached %s pages)",
                        "optistate"
                    ),
                number_format_i18n($total)
            ),
            "scheduled"
        );
    }
    public function check_preload_health(): void
    {
        if (get_transient("optistate_preload_health_ran")) {
            return;
        }
        set_transient("optistate_preload_health_ran", 1, 60);
        $running = get_transient("optistate_preload_running");
        if (!$running) {
            return;
        }
        $next_scheduled = wp_next_scheduled(
            "optistate_background_preload_batch"
        );
        if (!$next_scheduled) {
            $urls_remaining = get_transient("optistate_preload_urls_remaining");
            $processed =
                (int) (get_transient("optistate_preload_processed") ?: 0);
            $total = (int) (get_transient("optistate_preload_total") ?: 0);
            if (!empty($urls_remaining) && $processed < $total) {
                wp_schedule_single_event(
                    time(),
                    "optistate_background_preload_batch"
                );
            }
        } elseif ($next_scheduled < time() - 60) {
            wp_clear_scheduled_hook("optistate_background_preload_batch");
            wp_schedule_single_event(
                time(),
                "optistate_background_preload_batch"
            );
        }
    }
    public function ajax_stop_preload(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $processed = (int) (get_transient("optistate_preload_processed") ?: 0);
        $total = (int) (get_transient("optistate_preload_total") ?: 0);
        $this->reset_preload_state();
        $this->main_plugin->log_entry(
            sprintf(
                "🛑 " .
                    __(
                        'Cache Preload Stopped Manually (Cached %1$d of %2$d pages)',
                        "optistate"
                    ),
                $processed,
                $total
            )
        );
        OPTISTATE_Utils::send_json_success([
            "message" => __("Preload stopped successfully", "optistate"),
            "processed" => $processed,
            "total" => $total,
        ]);
    }
    public function ajax_get_preload_status(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $running = get_transient("optistate_preload_running");
        $processed = (int) (get_transient("optistate_preload_processed") ?: 0);
        $total = (int) (get_transient("optistate_preload_total") ?: 0);
        OPTISTATE_Utils::send_json_success([
            "running" => (bool) $running,
            "processed" => $processed,
            "total" => $total,
            "percentage" =>
                $total > 0 ? (int) round(($processed / $total) * 100) : 0,
            "batch_size" =>
                (int) (get_transient("optistate_preload_batch_size") ?: 10),
        ]);
    }
    private function minify_html(string $html): string
    {
        if ($html === "" || strlen($html) > 3 * 1024 * 1024) {
            return $html;
        }
        $preserve_tags = ["pre", "script", "textarea", "style"];
        $placeholders = [];
        $counter = 0;
        $original = $html;
        try {
            $marker_token = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $marker_token = uniqid("mkr_", true);
        }
        foreach ($preserve_tags as $tag) {
            $pattern = "/<" . $tag . "\b[^>]*>.*?<\/" . $tag . ">/is";
            $html = preg_replace_callback(
                $pattern,
                static function ($matches) use (
                    &$counter,
                    &$placeholders,
                    $marker_token
                ) {
                    $key = "___OPTSM_" . $marker_token . "_" . $counter . "___";
                    $placeholders[$key] = $matches[0];
                    $counter++;
                    return $key;
                },
                $html
            );
            if ($html === null) {
                return $original;
            }
        }
        if (strpos($original, "___OPTSM_" . $marker_token . "_") !== false) {
            return $original;
        }
        $html = preg_replace("/<!--(?!\s*\[if\s).*?-->/s", "", $html);
        if ($html === null) {
            return $original;
        }
        $html = preg_replace("/\s+/", " ", $html);
        if ($html === null) {
            return $original;
        }
        $html = str_replace("> <", "><", $html);
        if (!empty($placeholders)) {
            $html = strtr($html, $placeholders);
        }
        return trim($html);
    }
}