<?php declare(strict_types=1);
if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Performance_Manager
{
    private OPTISTATE $main_plugin;
    private ?array $performance_settings_cache = null;
    private ?array $cron_manager_state_cache = null;
    private bool $is_revisions_defined = false;
    private bool $is_trash_days_defined = false;
    private bool $runtime_optimizations_applied = false;
    private static function _str_starts_with(
        string $haystack,
        string $needle
    ): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
    private static function _str_contains(
        string $haystack,
        string $needle
    ): bool {
        return $needle === "" || strpos($haystack, $needle) !== false;
    }
    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        add_filter("cron_schedules", [$this, "register_custom_cron_schedules"]);
        if (is_admin() || wp_doing_ajax() || (defined("WP_CLI") && WP_CLI)) {
            $config_constants = get_transient("optistate_config_constants");
            if (
                !is_array($config_constants) ||
                !array_key_exists("WP_POST_REVISIONS", $config_constants) ||
                !array_key_exists("EMPTY_TRASH_DAYS", $config_constants)
            ) {
                $config_constants = [
                    "WP_POST_REVISIONS" => $this->is_constant_in_wp_config(
                        "WP_POST_REVISIONS"
                    ),
                    "EMPTY_TRASH_DAYS" => $this->is_constant_in_wp_config(
                        "EMPTY_TRASH_DAYS"
                    ),
                ];
                set_transient(
                    "optistate_config_constants",
                    $config_constants,
                    12 * HOUR_IN_SECONDS
                );
            }
            $this->is_revisions_defined =
                (bool) $config_constants["WP_POST_REVISIONS"];
            $this->is_trash_days_defined =
                (bool) $config_constants["EMPTY_TRASH_DAYS"];
        } else {
            $this->is_revisions_defined = false;
            $this->is_trash_days_defined = false;
        }
    }
    public function get_feature_definitions(): array
    {
        static $definitions = null;
        if ($definitions === null) {
            $has_persistent_cache = wp_using_ext_object_cache();
            $manual_base =
                plugin_dir_url(dirname(__FILE__)) . "manual/v" . OPTISTATE::VERSION . ".html";
            $site_url_encoded = rawurlencode(trailingslashit(get_site_url()));
            $manual_link =
                '<a href="' .
                esc_url($manual_base . "#ch-7-3-1") .
                '" target="_blank" rel="noopener noreferrer">' .
                __("section 7.3.1", "optistate") .
                "</a>";
            $definitions = [
                "server_caching" => [
                    "title" => __("🌐 Server-Side Page Caching", "optistate"),
                    "manual_url" => $manual_base . "#ch-7-2",
                    "description" => __(
                        "Drastically improves site speed by storing fully rendered pages as static HTML files. When a visitor requests a page, the lightweight cached file is served directly, bypassing slow PHP and database queries. Combine this with browser caching for ultimate performance. DO NOT ACTIVATE if you already use a caching plugin such as WP Rocket, LiteSpeed, WP Super Cache, etc.",
                        "optistate"
                    ),
                    "impact" => "high",
                    "type" => "custom_caching",
                    "default" => [
                        "enabled" => false,
                        "lifetime" => 86400,
                        "query_string_mode" => "include_safe",
                        "exclude_urls" =>
                            "/cart*\n/my-account*\n/checkout*\n/wp-login.php*\n/wp-admin*",
                        "mobile_cache" => false,
                        "disable_cookie_check" => false,
                        "custom_consent_cookie" => "",
                        "auto_preload" => false,
                        "minify_html" => false,
                    ],
                    "safe" => true,
                    "category" => "caching",
                ],
                "browser_caching" => [
                    "title" => __(
                        "💻 Browser Caching (.htaccess)",
                        "optistate"
                    ),
                    "manual_url" => $manual_base . "#ch-7-3",
                    "description" => sprintf(
                        __(
                            "Enables browser caching by adding optimized caching rules to your .htaccess file. This improves page load times for returning visitors by storing static assets in their browser.<br>DO NOT ACTIVATE if you already use a caching plugin such as WP Rocket, LiteSpeed, WP Super Cache, etc. Combine this with server-side caching for ultimate performance.<br>Requires Apache server with writable .htaccess file.<br>For Nginx servers, manual configuration is required (user manual %s).",
                            "optistate"
                        ),
                        $manual_link,
                        $site_url_encoded
                    ),
                    "impact" => "medium",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "caching",
                ],
                "db_query_caching" => [
                    "title" => __("🗄️ Database Query Caching", "optistate"),
                    "manual_url" => $manual_base . "#ch-7-4-query",
                    "description" => $has_persistent_cache
                        ? __(
                            "Advanced object caching for database queries. Reduces database load by caching complex query results in Redis/Memcached. Do not activate if you use another plugin for this purpose.",
                            "optistate"
                        )
                        : __(
                            "Advanced object caching for database queries. Reduces database load by caching complex query results in Redis/Memcached.<br>⚠️ Requirement Missing: A persistent object cache (Redis or Memcached) is not detected. This feature cannot be activated.",
                            "optistate"
                        ),
                    "impact" => "high",
                    "type" => "custom_db_caching",
                    "default" => [
                        "enabled" => false,
                        "ttl_main" => 43200,
                        "ttl_secondary" => 86400,
                        "exclude_post_types" => "shop_order,ticket,product",
                        "exclude_ids" => "",
                        "flush_on_comments" => true,
                        "flush_on_save" => true,
                    ],
                    "safe" => true,
                    "disabled" => !$has_persistent_cache,
                    "category" => "caching",
                ],
                "font_optimization" => [
                    "title" => __("𝐆 Font Loading Optimization", "optistate"),
                    "description" => __(
                        "Optimizes delivery of external fonts (Google Fonts) to eliminate render-blocking resources, improves First Contentful Paint (FCP), and reduces Cumulative Layout Shift (CLS).",
                        "optistate"
                    ),
                    "impact" => "medium",
                    "type" => "custom_font_optimization",
                    "default" => [
                        "enabled" => false,
                        "async_google_fonts" => true,
                        "display_swap" => true,
                        "preconnect" => true,
                        "remove_google_fonts" => false,
                    ],
                    "safe" => true,
                    "category" => "frontend",
                ],
                "lazy_load" => [
                    "title" => __("⏲ Lazy Load Images & Iframes", "optistate"),
                    "description" => __(
                        'Enforces native browser lazy loading by injecting loading="lazy" and decoding="async" attributes into images and iframes.<br>This improves Core Web Vitals by deferring off-screen media until needed.',
                        "optistate"
                    ),
                    "impact" => "medium",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "frontend",
                ],
                "emoji_script" => [
                    "title" => __("😊 Emoji Scripts", "optistate"),
                    "description" => __(
                        "Removes the emoji detection JavaScript (wp-emoji-release.min.js) loaded on every page. Modern browsers display emojis natively, making this script redundant.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "frontend",
                ],
                "bad_bot_blocker" => [
                    "title" => __("🤖 Bad Bot Blocker", "optistate"),
                    "manual_url" => $manual_base . "#ch-7-6",
                    "description" => __(
                        "Blocks resource-intensive SEO crawlers that provide competitive intelligence to other businesses. Does NOT block legitimate search engines like Google, Bing, or regional search engines.<br>You can customize the list to match your needs.",
                        "optistate"
                    ),
                    "impact" => "high",
                    "type" => "custom_bot_blocker",
                    "default" => [
                        "enabled" => false,
                        "user_agents" => OPTISTATE::DEFAULT_BOT_LIST,
                    ],
                    "safe" => true,
                    "category" => "security",
                ],
                "security_headers" => [
                    "title" => __(
                        "🛡️ Security Headers (.htaccess)",
                        "optistate"
                    ),
                    "manual_url" => $manual_base . "#ch-7-3-2",
                    "description" => sprintf(
                        __(
                            'Hardens your site by adding recommended HTTP security headers (X-Frame-Options, Content-Security-Policy, Referrer-Policy, Permissions-Policy, HSTS, and more) to your .htaccess file.<br>Requires Apache server with writable .htaccess file.<br>For Nginx servers, manual configuration is required (user manual %s).<br><a href="https://securityheaders.com/?q=%s&followRedirects=on" target="_blank" rel="noopener noreferrer">🔎 Check your security headers</a> before and after enabling this feature.',
                            "optistate"
                        ),
                        $manual_link,
                        $site_url_encoded
                    ),
                    "impact" => "medium",
                    "type" => "custom_security_headers",
                    "default" => [
                        "enabled" => false,
                        "optional_headers_enabled" => false,
                    ],
                    "safe" => false,
                    "category" => "security",
                ],
                "xmlrpc" => [
                    "title" => __("ᯤ XML-RPC Interface", "optistate"),
                    "description" => __(
                        "Disables the XML-RPC API used by legacy mobile apps. It has been replaced by the REST API and is a frequent target for brute-force attacks.",
                        "optistate"
                    ),
                    "impact" => "medium",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => false,
                    "category" => "security",
                ],
                "file_editor" => [
                    "title" => __("📝 File Editor", "optistate"),
                    "description" => __(
                        "Disables the built-in WordPress file editor under Appearance > Theme File Editor and Plugins > Plugin File Editor. This prevents unauthorized users or attackers from modifying theme and plugin files directly from the WordPress admin dashboard. Recommended for all production sites.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "security",
                ],
                "application_passwords" => [
                    "title" => __("🔑 Application Passwords", "optistate"),
                    "description" => __(
                        "Disables the Application Passwords feature introduced in WordPress 5.6. This feature allows users to generate passwords for REST API authentication. If not used by any service (e.g., for headless setups or mobile apps), it presents an unnecessary attack surface. Recommended to disable for most sites.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "security",
                ],
                "post_revisions" => [
                    "title" => __("📝 Post Revisions Limit", "optistate"),
                    "description" => __(
                        'WordPress saves a new copy every time you click "Save Draft". Limiting revisions prevents database bloat while keeping recent versions for safety.',
                        "optistate"
                    ),
                    "impact" => "medium",
                    "options" => [
                        "default" => __(
                            "WordPress Default (Unlimited)",
                            "optistate"
                        ),
                        "limit_3" => __("Limit to 3 Revisions", "optistate"),
                        "limit_5" => __("Limit to 5 Revisions", "optistate"),
                        "limit_10" => __("Limit to 10 Revisions", "optistate"),
                        "disable" => __(
                            "Disable Revisions (not recommended)",
                            "optistate"
                        ),
                    ],
                    "default" => "default",
                    "safe" => false,
                    "category" => "backend",
                ],
                "trash_auto_empty" => [
                    "title" => __("🗑️ Automatic Trash Emptying", "optistate"),
                    "description" => __(
                        "By default, WordPress automatically purges trashed posts and pages older than 30 days. However, you can customize this period or completely disable automatic emptying.<br>⚠ Warning: Once emptied, deleted content cannot be recovered.",
                        "optistate"
                    ),
                    "impact" => "medium",
                    "options" => [
                        "default" => __(
                            "WordPress Default (30 days)",
                            "optistate"
                        ),
                        "disable" => __(
                            "Disable Auto-Empty (Keep Forever)",
                            "optistate"
                        ),
                        "days_7" => __("7 Days", "optistate"),
                        "days_14" => __("14 Days", "optistate"),
                        "days_30" => __("30 Days", "optistate"),
                        "days_60" => __("60 Days", "optistate"),
                        "days_90" => __("90 Days", "optistate"),
                    ],
                    "default" => "default",
                    "safe" => false,
                    "category" => "backend",
                ],
                "heartbeat_api" => [
                    "title" => __("၊၊||၊ Heartbeat API Control", "optistate"),
                    "description" => __(
                        "Reduces or disables the WordPress Heartbeat API that creates frequent AJAX calls (every 15-60 seconds). This saves server resources but may disable real-time features like post editing locks.",
                        "optistate"
                    ),
                    "impact" => "high",
                    "options" => [
                        "default" => __(
                            "WordPress Default (Every 15-60 seconds)",
                            "optistate"
                        ),
                        "slow" => __(
                            "Slow Down (Every 2 minutes)",
                            "optistate"
                        ),
                        "disable_admin" => __(
                            "Disable in Admin Area",
                            "optistate"
                        ),
                        "disable_frontend" => __(
                            "Disable on Frontend Only",
                            "optistate"
                        ),
                        "disable_all" => __("Disable Everywhere", "optistate"),
                    ],
                    "default" => "default",
                    "safe" => true,
                    "category" => "backend",
                ],
                "cron_manager" => [
                    "title" => __("⏰ Cron Manager", "optistate"),
                    "manual_url" => $manual_base . "#ch-7-7",
                    "description" => __(
                        "View and control WordPress cron jobs. Pause or slow down individual jobs to reduce server load.<br>You can pause or delay the execution of individual cron jobs.",
                        "optistate"
                    ),
                    "impact" => "medium",
                    "type" => "custom_cron_manager",
                    "default" => [],
                    "safe" => true,
                    "category" => "backend",
                ],
                "self_pingbacks" => [
                    "title" => __("↩️ Self Pingbacks", "optistate"),
                    "description" => __(
                        "Prevents WordPress from creating pingback notifications when you link to your own posts, reducing unnecessary database operations.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "backend",
                ],
                "rest_api_link" => [
                    "title" => __("🔗 REST API Link Tag", "optistate"),
                    "description" => __(
                        "Removes the REST API discovery link from page headers. The REST API will still work, but external discovery is disabled.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "header",
                ],
                "shortlink" => [
                    "title" => __("🔗 Shortlink Tag", "optistate"),
                    "description" => __(
                        "Removes the shortlink meta tag from page headers. Shortlinks are rarely used and removing them saves minimal bandwidth.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "header",
                ],
                "rsd_link" => [
                    "title" => __(
                        "🔗 RSD (Really Simple Discovery) Link",
                        "optistate"
                    ),
                    "description" => __(
                        "Removes the RSD link used by external blog clients. Unless you use desktop blogging software, this can be safely removed.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "header",
                ],
                "wlwmanifest" => [
                    "title" => __(
                        "🪟 Windows Live Writer Manifest",
                        "optistate"
                    ),
                    "description" => __(
                        "Removes the Windows Live Writer manifest link. This software is discontinued and the link is no longer needed.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "header",
                ],
                "wp_generator" => [
                    "title" => __("♯ WordPress Version Meta Tag", "optistate"),
                    "description" => sprintf(
                        __(
                            'Removes <code>&lt;meta name="generator" content="WordPress %s" /&gt;</code> from page headers.<br>This meta tag is automatically added by WordPress and reveals your exact version number, which can be used by attackers to target known vulnerabilities in that specific version.',
                            "optistate"
                        ),
                        get_bloginfo("version")
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "header",
                ],
                "feed_links" => [
                    "title" => __("📡 Feed Links (RSS & Atom)", "optistate"),
                    "description" => __(
                        "Removes RSS and Atom feed discovery links from page headers (posts, comments, categories, and tags). The feeds themselves remain accessible via their direct URLs — only the auto-discovery &lt;link&gt; tags are removed. Recommended if you do not use RSS feeds or a feed-based newsletter service.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "header",
                ],
                "post_relational_links" => [
                    "title" => __("🔗 Post Relational Links", "optistate"),
                    "description" => __(
                        "Removes relational &lt;link&gt; tags from page headers (index, start, parent, prev, next). These links are legacy HTML4 navigation hints not used by modern browsers or search engines.",
                        "optistate"
                    ),
                    "impact" => "low",
                    "type" => "toggle",
                    "default" => false,
                    "safe" => true,
                    "category" => "header",
                ],
            ];
            if (
                isset($definitions["browser_caching"]) ||
                isset($definitions["security_headers"])
            ) {
                $htaccess_info = $this->get_htaccess_info();
                if (isset($definitions["browser_caching"])) {
                    $definitions["browser_caching"][
                        "disabled"
                    ] = !$htaccess_info["writable"];
                }
                if (isset($definitions["security_headers"])) {
                    $definitions["security_headers"][
                        "disabled"
                    ] = !$htaccess_info["writable"];
                }
            }
        }
        return $definitions;
    }
    public function get_performance_settings(): array
    {
        if ($this->performance_settings_cache !== null) {
            return $this->performance_settings_cache;
        }
        $settings = $this->main_plugin->settings_manager->get_persistent_settings();
        if (
            !isset($settings["performance_features"]) ||
            !is_array($settings["performance_features"])
        ) {
            $settings["performance_features"] = [];
        }
        $definitions = $this->get_feature_definitions();
        foreach (
            [
                "server_caching",
                "font_optimization",
                "bad_bot_blocker",
                "security_headers",
                "db_query_caching",
            ]
            as $complex_key
        ) {
            if (
                !isset($definitions[$complex_key]) ||
                !is_array($definitions[$complex_key]["default"] ?? null)
            ) {
                continue;
            }
            $default = $definitions[$complex_key]["default"];
            if (
                !isset($settings["performance_features"][$complex_key]) ||
                !is_array($settings["performance_features"][$complex_key])
            ) {
                $settings["performance_features"][$complex_key] = $default;
            } else {
                $settings["performance_features"][$complex_key] = wp_parse_args(
                    $settings["performance_features"][$complex_key],
                    $default
                );
            }
        }
        $this->performance_settings_cache = $settings["performance_features"];
        return $this->performance_settings_cache;
    }
    private function _performance_save_settings(array $features): bool
    {
        $validated_features = $this->validate_performance_features($features);
        $this->performance_settings_cache = null;
        return $this->main_plugin->settings_manager->save_persistent_settings([
            "performance_features" => $validated_features,
        ]);
    }
    public function validate_performance_features(array $features): array
    {
        if (!is_array($features)) {
            return [];
        }
        $validated = [];
        $definitions = $this->get_feature_definitions();
        foreach ($features as $key => $value) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $feature_def = $definitions[$key];
            if (
                isset($feature_def["type"]) &&
                $feature_def["type"] === "custom_caching" &&
                $key === "server_caching"
            ) {
                if (is_array($value)) {
                    $allowed_query_modes = [
                        "ignore_all",
                        "include_safe",
                        "unique_cache",
                    ];
                    $query_mode = $value["query_string_mode"] ?? "include_safe";
                    if (!in_array($query_mode, $allowed_query_modes, true)) {
                        $query_mode = "include_safe";
                    }
                    $validated[$key] = [
                        "enabled" => filter_var(
                            $value["enabled"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "lifetime" => min(
                            max(
                                absint($value["lifetime"] ?? 86400),
                                HOUR_IN_SECONDS
                            ),
                            6 * MONTH_IN_SECONDS
                        ),
                        "query_string_mode" => $query_mode,
                        "exclude_urls" => sanitize_textarea_field(
                            $value["exclude_urls"] ?? ""
                        ),
                        "mobile_cache" => filter_var(
                            $value["mobile_cache"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "disable_cookie_check" => filter_var(
                            $value["disable_cookie_check"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "custom_consent_cookie" => sanitize_text_field(
                            $value["custom_consent_cookie"] ?? ""
                        ),
                        "auto_preload" => filter_var(
                            $value["auto_preload"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "minify_html" => filter_var(
                            $value["minify_html"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                    ];
                } else {
                    $validated[$key] = $feature_def["default"];
                }
            } elseif (
                isset($feature_def["type"]) &&
                $feature_def["type"] === "custom_db_caching"
            ) {
                if (is_array($value)) {
                    if (!empty($feature_def["disabled"])) {
                        $value["enabled"] = false;
                    }
                    $validated[$key] = [
                        "enabled" => filter_var(
                            $value["enabled"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "ttl_main" => min(
                            max(absint($value["ttl_main"] ?? 43200), 60),
                            7 * DAY_IN_SECONDS
                        ),
                        "ttl_secondary" => min(
                            max(absint($value["ttl_secondary"] ?? 86400), 60),
                            7 * DAY_IN_SECONDS
                        ),
                        "exclude_post_types" => sanitize_text_field(
                            $value["exclude_post_types"] ?? ""
                        ),
                        "exclude_ids" => sanitize_text_field(
                            $value["exclude_ids"] ?? ""
                        ),
                        "flush_on_comments" => filter_var(
                            $value["flush_on_comments"] ?? true,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "flush_on_save" => filter_var(
                            $value["flush_on_save"] ?? true,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                    ];
                } else {
                    $validated[$key] = $feature_def["default"];
                }
            } elseif (
                isset($feature_def["type"]) &&
                $feature_def["type"] === "custom_bot_blocker"
            ) {
                if (is_array($value)) {
                    $raw_bots = isset($value["user_agents"])
                        ? (string) $value["user_agents"]
                        : "";
                    $bots = array_filter(
                        array_map("trim", explode("\n", $raw_bots))
                    );
                    $bots_array = array_slice(
                        array_filter(
                            array_map(
                                fn(string $bot): string => substr($bot, 0, 150),
                                $bots
                            )
                        ),
                        0,
                        50
                    );
                    $validated[$key] = [
                        "enabled" => filter_var(
                            $value["enabled"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "user_agents" => implode("\n", $bots_array),
                    ];
                } else {
                    $validated[$key] = $feature_def["default"];
                }
            } elseif (
                isset($feature_def["type"]) &&
                $feature_def["type"] === "custom_font_optimization"
            ) {
                if (is_array($value)) {
                    $is_removed = filter_var(
                        $value["remove_google_fonts"] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    );
                    $validated[$key] = [
                        "enabled" => filter_var(
                            $value["enabled"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "async_google_fonts" => $is_removed
                            ? false
                            : filter_var(
                                $value["async_google_fonts"] ?? true,
                                FILTER_VALIDATE_BOOLEAN
                            ),
                        "display_swap" => $is_removed
                            ? false
                            : filter_var(
                                $value["display_swap"] ?? true,
                                FILTER_VALIDATE_BOOLEAN
                            ),
                        "preconnect" => $is_removed
                            ? false
                            : filter_var(
                                $value["preconnect"] ?? true,
                                FILTER_VALIDATE_BOOLEAN
                            ),
                        "remove_google_fonts" => $is_removed,
                    ];
                } else {
                    $validated[$key] = $feature_def["default"];
                }
            } elseif (
                isset($feature_def["type"]) &&
                $feature_def["type"] === "custom_security_headers"
            ) {
                if (is_array($value)) {
                    $validated[$key] = [
                        "enabled" => filter_var(
                            $value["enabled"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        "optional_headers_enabled" => filter_var(
                            $value["optional_headers_enabled"] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        ),
                    ];
                } else {
                    $validated[$key] = $feature_def["default"];
                }
            } elseif (
                isset($feature_def["type"]) &&
                $feature_def["type"] === "toggle"
            ) {
                $validated[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (
                isset($feature_def["options"]) &&
                is_array($feature_def["options"])
            ) {
                if (is_string($value) && array_key_exists($value, $feature_def["options"])) {
                    $validated[$key] = sanitize_key($value);
                } else {
                    $validated[$key] = $feature_def["default"];
                }
            }
        }
        return $validated;
    }
    private function _get_slowdown_multiplier(int $interval): int
    {
        $interval = max(60, $interval);
        if ($interval < 600) {
            return 36;
        }
        if ($interval < 1800) {
            return 24;
        }
        if ($interval < 3600) {
            return 12;
        }
        if ($interval < 7200) {
            return 8;
        }
        if ($interval < 21600) {
            return 4;
        }
        if ($interval < 43200) {
            return 3;
        }
        if ($interval < 172800) {
            return 2;
        }
        return 1;
    }
    private function _cron_manager_get_event_id(
        string $hook,
        array $args
    ): string {
        return md5($hook . serialize($args));
    }
    private function _cron_manager_get_state(bool $force = false): array
    {
        if (!$force && $this->cron_manager_state_cache !== null) {
            return $this->cron_manager_state_cache;
        }
        $state = $this->main_plugin->get_store_data("cron_manager_state");
        if (!is_array($state)) {
            $state = [];
        }
        $this->cron_manager_state_cache = $state;
        return $state;
    }
    private function _cron_manager_set_state(array $state): bool
    {
        $this->cron_manager_state_cache = $state;
        delete_transient("optistate_cron_slowed_schedules");
        return $this->main_plugin->set_store_data("cron_manager_state", $state);
    }
    public function cleanup_orphaned_cron_state(): void
    {
        $state = $this->_cron_manager_get_state(true);
        if (empty($state)) {
            return;
        }
        $active_events = [];
        $cron = get_option("cron", []);
        $stale_cutoff = time() - 7 * DAY_IN_SECONDS;
        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks) || $timestamp < $stale_cutoff) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                foreach ($events as $args_key => $event) {
                    if (!is_array($event) || !isset($event["args"])) {
                        continue;
                    }
                    $args = isset($event["args"]) ? (array) $event["args"] : [];
                    $id = $this->_cron_manager_get_event_id($hook, $args);
                    $active_events[$id] = true;
                }
            }
        }
        $changed = false;
        foreach ($state as $id => $entry) {
            if (isset($entry["paused"]) && $entry["paused"] === true) {
                continue;
            }
            if (isset($active_events[$id])) {
                continue;
            }
            if (isset($entry["hook"])) {
                $entry_args = isset($entry["args"])
                    ? (array) $entry["args"]
                    : [];
                if (wp_next_scheduled($entry["hook"], $entry_args) !== false) {
                    continue;
                }
            }
            unset($state[$id]);
            $changed = true;
        }
        if ($changed) {
            $this->_cron_manager_set_state($state);
            $this->main_plugin->log_entry(
                "🧹 Orphaned cron manager state cleaned up"
            );
            delete_transient("optistate_cron_jobs_cache");
        }
    }
    public function register_custom_cron_schedules(array $schedules): array
    {
       $slowed = get_transient("optistate_cron_slowed_schedules");
        if (!is_array($slowed)) {
            $slowed = [];
            $state = $this->_cron_manager_get_state();
            foreach ($state as $entry) {
                if (
                    isset($entry["slowed_schedule"], $entry["slowed_interval"]) &&
                    (int) $entry["slowed_interval"] > 0
                ) {
                    $slowed[(string) $entry["slowed_schedule"]] =
                        (int) $entry["slowed_interval"];
                }
            }
            set_transient(
                "optistate_cron_slowed_schedules",
                $slowed,
                15 * MINUTE_IN_SECONDS
            );
        }
        foreach ($slowed as $schedule_name => $interval) {
            if (!isset($schedules[$schedule_name])) {
                $schedules[$schedule_name] = [
                    "interval" => $interval,
                    "display" => sprintf(
                        __("Slowed (%s seconds)", "optistate"),
                        number_format_i18n($interval)
                    ),
                ];
            }
        }
        return $schedules;
    }
    private function is_protected_hook(string $hook): bool
    {
        return self::_str_starts_with($hook, "optistate_");
    }
    public function get_cron_jobs(bool $force = false): array
    {
        $cache_key = "optistate_cron_jobs_cache";
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $cron = get_option("cron", []);
        $state = $this->_cron_manager_get_state($force);
        $jobs = [];
        $job_id_map = [];
        $now = time();
        $visibility_cutoff = $now - 7 * DAY_IN_SECONDS;
        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks) || $timestamp < $visibility_cutoff) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                foreach ($events as $args_key => $event) {
                    if (!is_array($event) || !isset($event["schedule"])) {
                        continue;
                    }
                    $args = isset($event["args"]) ? (array) $event["args"] : [];
                    $id = $this->_cron_manager_get_event_id($hook, $args);
                    $schedule = $event["schedule"] ?: false;
                    $interval = isset($event["interval"])
                        ? (int) $event["interval"]
                        : 0;
                    $next_run = (int) $timestamp;
                    $job = [
                        "id" => $id,
                        "hook" => $hook,
                        "args" => $args,
                        "schedule" => $schedule,
                        "interval" => $interval,
                        "next_run" => $next_run,
                        "state" => "normal",
                    ];
                    if (isset($state[$id])) {
                        $st = $state[$id];
                        if (isset($st["paused"]) && $st["paused"]) {
                            $job["state"] = "paused";
                        } elseif (isset($st["slowed"]) && $st["slowed"]) {
                            $job["state"] = "slowed";
                        }
                        if (isset($st["original_schedule"])) {
                            $job["original_schedule"] =
                                $st["original_schedule"];
                        }
                        if (isset($st["original_interval"])) {
                            $job["original_interval"] =
                                $st["original_interval"];
                        }
                    }
                    if ($job["state"] === "normal" && $next_run < $now) {
                        $job["state"] = "missed";
                    }
                    $multiplier = $this->_get_slowdown_multiplier($interval);
                    $job["can_slow_down"] =
                        $schedule !== false &&
                        $multiplier > 1 &&
                        !isset($state[$id]["slowed"]);
                    if ($this->is_protected_hook($job["hook"])) {
                        $job["protected"] = true;
                        $job["can_slow_down"] = false;
                    }
                    if ($job["state"] === "missed") {
                        $job["can_slow_down"] = false;
                    }
                    $jobs[] = $job;
                    $job_id_map[$id] = true;
                }
            }
        }
        foreach ($state as $id => $st) {
            if (
                isset($st["paused"]) &&
                $st["paused"] === true &&
                !isset($job_id_map[$id])
            ) {
                if (!isset($st["hook"]) || !isset($st["args"])) {
                    continue;
                }
                $hook = $st["hook"];
                $args = $st["args"];
                $schedule = $st["original_schedule"] ?? false;
                $interval = isset($st["original_interval"])
                    ? (int) $st["original_interval"]
                    : 0;
                $next_run = isset($st["original_next_run"])
                    ? (int) $st["original_next_run"]
                    : 0;
                if ($next_run < $now) {
                    $next_run = $now + 60;
                }
                $job = [
                    "id" => $id,
                    "hook" => $hook,
                    "args" => $args,
                    "schedule" => $schedule,
                    "interval" => $interval,
                    "next_run" => $next_run,
                    "state" => "paused",
                    "can_slow_down" => false,
                ];
                if ($this->is_protected_hook($hook)) {
                    $job["protected"] = true;
                    $job["can_slow_down"] = false;
                }
                $jobs[] = $job;
            }
        }
        usort($jobs, function (array $a, array $b): int {
            $diff = $a["next_run"] <=> $b["next_run"];
            if ($diff !== 0) {
                return $diff;
            }
            return strcmp((string) $a["id"], (string) $b["id"]);
        });
        set_transient($cache_key, $jobs, 24 * HOUR_IN_SECONDS);
        return $jobs;
    }
    public function ajax_cron_manager_action(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("cron_manager_action", 3)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        $action = isset($_POST["cron_action"])
            ? sanitize_key($_POST["cron_action"])
            : "";
        $event_id = isset($_POST["event_id"])
            ? sanitize_text_field($_POST["event_id"])
            : "";
        if (
            !in_array(
                $action,
                ["pause", "resume", "slowdown", "restore", "run_now"],
                true
            ) ||
            empty($event_id)
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request.", "optistate")
            );
            return;
        }
        global $wpdb;
        $lock_name = "optistate_cron_manager_lock";
        $lock_acquired = $wpdb->get_var(
            $wpdb->prepare("SELECT GET_LOCK(%s, 3)", $lock_name)
        );
        if (!$lock_acquired) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Another operation is in progress. Please try again.",
                    "optistate"
                ),
                409
            );
            return;
        }
        try {
            $jobs = $this->get_cron_jobs(true);
            $state = $this->_cron_manager_get_state();
            $target_job = null;
            foreach ($jobs as $job) {
                if ($job["id"] === $event_id) {
                    $target_job = $job;
                    break;
                }
            }
            $hook = null;
            $args = [];
            $next_run = 0;
            $schedule = false;
            $interval = 0;
            if ($target_job) {
                $hook = $target_job["hook"];
                $args = $target_job["args"];
                $next_run = $target_job["next_run"];
                $schedule = $target_job["schedule"];
                $interval = $target_job["interval"];
            } elseif (isset($state[$event_id])) {
                $st = $state[$event_id];
                if (isset($st["hook"])) {
                    $hook = $st["hook"];
                    $args = $st["args"] ?? [];
                    $schedule = $st["original_schedule"] ?? false;
                    $interval = $st["original_interval"] ?? 0;
                    $next_run = $st["original_next_run"] ?? 0;
                }
            }
            if (!$hook) {
                throw new Exception(
                    __("Could not identify the cron job.", "optistate")
                );
            }
            if ($this->is_protected_hook($hook)) {
                throw new Exception(
                    __(
                        "This is a system cron job and cannot be modified.",
                        "optistate"
                    )
                );
            }
            switch ($action) {
                case "pause":
                    if (!$target_job) {
                        throw new Exception(__("Job not found.", "optistate"));
                    }
                    if ($target_job["state"] === "paused") {
                        throw new Exception(
                            __("This job is already paused.", "optistate")
                        );
                    }
                    if ($target_job["state"] === "slowed") {
                        throw new Exception(
                            __(
                                "Cannot pause a slowed job. Please restore it first.",
                                "optistate"
                            )
                        );
                    }
                    $unscheduled = wp_unschedule_event($next_run, $hook, $args);
                    if ($unscheduled === false) {
                        throw new Exception(
                            __(
                                "Failed to unschedule the cron event. Please try again.",
                                "optistate"
                            )
                        );
                    }
                    $state[$event_id] = [
                        "paused" => true,
                        "hook" => $hook,
                        "args" => $args,
                        "original_schedule" => $schedule,
                        "original_interval" => $interval,
                        "original_next_run" => $next_run,
                    ];
                    $this->_cron_manager_set_state($state);
                    $this->main_plugin->log_entry(
                        sprintf('⏸️ Cron job "%s" paused by {username}', $hook)
                    );
                    delete_transient("optistate_cron_jobs_cache");
                    OPTISTATE_Utils::send_json_success([
                        "message" => __("Cron job paused.", "optistate"),
                    ]);
                    return;
                case "slowdown":
                    if (!$target_job) {
                        throw new Exception(__("Job not found.", "optistate"));
                    }
                    if ($target_job["state"] === "paused") {
                        throw new Exception(
                            __(
                                "This job is currently paused. Resume it before slowing it down.",
                                "optistate"
                            )
                        );
                    }
                    if ($target_job["state"] === "slowed") {
                        throw new Exception(
                            __("This job is already slowed down.", "optistate")
                        );
                    }
                    if ($schedule === false) {
                        throw new Exception(
                            __(
                                "One-time jobs cannot be slowed down.",
                                "optistate"
                            )
                        );
                    }
                    $multiplier = $this->_get_slowdown_multiplier($interval);
                    if ($multiplier <= 1) {
                        throw new Exception(
                            __(
                                "This job interval is already long enough; no slowdown needed.",
                                "optistate"
                            )
                        );
                    }
                    $new_interval = $interval * $multiplier;
                    $new_schedule =
                        "optistate_slowed_" .
                        substr(md5($event_id . $new_interval), 0, 12);
                    add_filter("cron_schedules", function (
                        array $schedules
                    ) use ($new_schedule, $new_interval): array {
                        if (!isset($schedules[$new_schedule])) {
                            $schedules[$new_schedule] = [
                                "interval" => $new_interval,
                                "display" => sprintf(
                                    __("Slowed (%s seconds)", "optistate"),
                                    number_format_i18n($new_interval)
                                ),
                            ];
                        }
                        return $schedules;
                    });
                    $unscheduled = wp_unschedule_event($next_run, $hook, $args);
                    if ($unscheduled === false) {
                        throw new Exception(
                            __(
                                "Failed to unschedule the original cron event.",
                                "optistate"
                            )
                        );
                    }
                    $scheduled = wp_schedule_event(
                        time() + $new_interval,
                        $new_schedule,
                        $hook,
                        $args
                    );
                    if (!$scheduled) {
                        $restored = wp_schedule_event(
                            time() + $interval,
                            $schedule,
                            $hook,
                            $args
                        );
                        if ($restored === false) {
                            OPTISTATE_Utils::log_critical_error(
                                "Slowdown failed AND original cron schedule could not be restored",
                                [
                                    "hook" => $hook,
                                    "event_id" => $event_id,
                                    "schedule" => $schedule,
                                    "interval" => $interval,
                                ]
                            );
                            $this->main_plugin->log_entry(
                                sprintf(
                                    '⚠️ Failed to restore original schedule for "%s" after failed slowdown attempt',
                                    $hook
                                ),
                                "error"
                            );
                            throw new Exception(
                                __(
                                    "Failed to schedule the slower cron job AND could not restore the original schedule. Please review the cron manager.",
                                    "optistate"
                                )
                            );
                        }
                        throw new Exception(
                            __(
                                "Failed to schedule the slower cron job. Original schedule restored.",
                                "optistate"
                            )
                        );
                    }
                    $state[$event_id] = [
                        "slowed" => true,
                        "hook" => $hook,
                        "args" => $args,
                        "original_schedule" => $schedule,
                        "original_interval" => $interval,
                        "slowed_schedule" => $new_schedule,
                        "slowed_interval" => $new_interval,
                    ];
                    $this->_cron_manager_set_state($state);
                    $this->main_plugin->log_entry(
                        sprintf(
                            '🐢 Cron job "%s" slowed down (interval %d → %d seconds) by {username}',
                            $hook,
                            $interval,
                            $new_interval
                        )
                    );
                    delete_transient("optistate_cron_jobs_cache");
                    OPTISTATE_Utils::send_json_success([
                        "message" => __("Cron job slowed down.", "optistate"),
                    ]);
                    return;
                case "resume":
                case "restore":
                    if (!isset($state[$event_id])) {
                        throw new Exception(
                            __("No stored state for this job.", "optistate")
                        );
                    }
                    $stored = $state[$event_id];
                    $orig_schedule = $stored["original_schedule"] ?? false;
                    $orig_interval = $stored["original_interval"] ?? 0;
                    $orig_next_run = $stored["original_next_run"] ?? 0;
                    wp_clear_scheduled_hook($hook, $args);
                    if ($orig_schedule !== false && $orig_interval > 0) {
                        $scheduled = wp_schedule_event(
                            time() + $orig_interval,
                            $orig_schedule,
                            $hook,
                            $args
                        );
                    } elseif ($orig_next_run > time()) {
                        $scheduled = wp_schedule_single_event(
                            $orig_next_run,
                            $hook,
                            $args
                        );
                    } else {
                        $scheduled = wp_schedule_single_event(
                            time() + 60,
                            $hook,
                            $args
                        );
                    }
                    if (!$scheduled) {
                        throw new Exception(
                            __(
                                "Failed to reschedule the cron job. State preserved.",
                                "optistate"
                            )
                        );
                    }
                    unset($state[$event_id]);
                    $this->_cron_manager_set_state($state);
                    $this->main_plugin->log_entry(
                        sprintf(
                            '▶️ Cron job "%s" resumed/restored by {username}',
                            $hook
                        )
                    );
                    delete_transient("optistate_cron_jobs_cache");
                    OPTISTATE_Utils::send_json_success([
                        "message" => __(
                            "Cron job restored to original schedule.",
                            "optistate"
                        ),
                    ]);
                    return;
                case "run_now":
                    $scheduled = wp_schedule_single_event(
                        time() + 1,
                        $hook,
                        $args
                    );
                    if (!$scheduled) {
                        throw new Exception(
                            __(
                                "Failed to schedule the job for immediate execution.",
                                "optistate"
                            )
                        );
                    }
                    if (!$target_job && isset($state[$event_id])) {
                        unset($state[$event_id]);
                        $this->_cron_manager_set_state($state);
                    }
                    if ($target_job && $schedule === false) {
                        $unscheduled = wp_unschedule_event(
                            $next_run,
                            $hook,
                            $args
                        );
                        if ($unscheduled === false) {
                            $this->main_plugin->log_entry(
                                sprintf(
                                    '⚠️ Failed to unschedule original one-time cron job "%s" during run_now',
                                    $hook
                                ),
                                "error"
                            );
                        }
                    }
                    $this->main_plugin->log_entry(
                        sprintf(
                            '▶️ Cron job "%s" executed now by {username}',
                            $hook
                        )
                    );
                    delete_transient("optistate_cron_jobs_cache");
                    OPTISTATE_Utils::send_json_success([
                        "message" => __(
                            "Job scheduled to run now.",
                            "optistate"
                        ),
                    ]);
                    return;
            }
        } catch (\Throwable $e) {
            OPTISTATE_Utils::log_critical_error("Cron manager action failed", [
                "action" => $action,
                "event_id" => $event_id,
                "error" => $e->getMessage(),
            ]);
            OPTISTATE_Utils::send_json_error($e->getMessage());
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }
    public function apply_performance_optimizations(): void
    {
        if ($this->runtime_optimizations_applied) {
            return;
        }
        $this->runtime_optimizations_applied = true;
        $settings = $this->get_performance_settings();
        if (
            isset($settings["heartbeat_api"]) &&
            $settings["heartbeat_api"] !== "default"
        ) {
            OPTISTATE_Utils::apply_heartbeat_optimization(
                $settings["heartbeat_api"]
            );
        }
        if (
            isset($settings["post_revisions"]) &&
            $settings["post_revisions"] !== "default"
        ) {
            OPTISTATE_Utils::apply_revision_limit($settings["post_revisions"]);
        }
        if (
            isset($settings["trash_auto_empty"]) &&
            $settings["trash_auto_empty"] !== "default"
        ) {
            OPTISTATE_Utils::apply_trash_days($settings["trash_auto_empty"]);
        }
        OPTISTATE_Utils::apply_header_cleanups($settings);
        if (!empty($settings["emoji_script"])) {
            OPTISTATE_Utils::disable_emoji_scripts();
        }
        if (!empty($settings["xmlrpc"])) {
            OPTISTATE_Utils::disable_xmlrpc();
        }
        if (!empty($settings["file_editor"])) {
            add_filter(
                "file_mod_allowed",
                function (bool $allowed, string $context): bool {
                    if ($context === "file_edit") {
                        return false;
                    }
                    return $allowed;
                },
                10,
                2
            );
            if (!defined("DISALLOW_FILE_EDIT")) {
                define("DISALLOW_FILE_EDIT", true);
            }
        }
        if (!empty($settings["application_passwords"])) {
            add_filter(
                "wp_is_application_passwords_available",
                "__return_false"
            );
        }
        if (!empty($settings["self_pingbacks"])) {
            OPTISTATE_Utils::disable_self_pingbacks();
        }
        if (!empty($settings["db_query_caching"]["enabled"])) {
            $this->_performance_enable_db_query_caching();
        }
        if (!empty($settings["lazy_load"])) {
            $this->_performance_enable_lazy_load();
        }
        if (!empty($settings["font_optimization"]["enabled"])) {
            $this->_performance_enable_font_optimization(
                $settings["font_optimization"]
            );
        }
        if (!empty($settings["bad_bot_blocker"]["enabled"])) {
            if (
                !is_admin() &&
                !(defined("DOING_AJAX") && DOING_AJAX) &&
                !(defined("WP_CLI") && WP_CLI)
            ) {
              $this->_performance_block_bad_bots_php();
            }
        }
    }
    public function get_htaccess_info(
        bool $force = false,
        bool $include_content = false,
        bool $attempt_create = false
    ): array {
        static $cache = null;
        if (!$force && $cache !== null) {
            if ($include_content && !array_key_exists("content", $cache)) {
                $fs = $this->main_plugin->get_filesystem();
                $cache["content"] = $fs->exists($cache["path"])
                    ? $fs->get_contents($cache["path"])
                    : "";
            }
            return $cache;
        }
        $fs = $this->main_plugin->get_filesystem();
        $path = get_home_path() . ".htaccess";
        $info = [
            "path" => $path,
            "exists" => false,
            "writable" => false,
            "size" => 0,
            "mtime" => 0,
            "message" => "",
        ];
        if (!$fs->exists($path)) {
            if ($attempt_create) {
                $created = $fs->put_contents(
                    $path,
                    "# WordPress htaccess\n",
                    FS_CHMOD_FILE
                );
                if ($created === false) {
                    $info["message"] = __(
                        ".htaccess file does not exist and cannot be created. Check file permissions in your WordPress root directory.",
                        "optistate"
                    );
                    $cache = $info;
                    return $info;
                }
            } else {
                $info["message"] = __(
                    ".htaccess file does not exist.",
                    "optistate"
                );
                $cache = $info;
                return $info;
            }
        }
        $info["exists"] = true;
        $info["size"] = $fs->size($path);
        $info["mtime"] = $fs->mtime($path);
        $info["writable"] = $fs->is_writable($path);
        if (!$info["writable"]) {
            $info["message"] = __(
                ".htaccess file exists but is not writable. Please set permissions to 644 or contact your hosting provider.",
                "optistate"
            );
        } else {
            $info["message"] = __(".htaccess file is writable.", "optistate");
        }
        if ($include_content) {
            $info["content"] = $fs->get_contents($path);
        }
        $cache = $info;
        return $info;
    }
    private function _generate_whitelist_htaccess_rules(
        array $whitelist
    ): string {
        if (empty($whitelist)) {
            return "";
        }
        $plain_ips = [];
        $cidr_ranges = [];
        foreach ($whitelist as $entry) {
            $entry = trim((string) $entry);
            if ($entry === "") {
                continue;
            }
            if (strpos($entry, "/") !== false) {
                $cidr_ranges[] = $entry;
            } elseif (filter_var($entry, FILTER_VALIDATE_IP)) {
                $plain_ips[] = $entry;
            }
        }
        if (!empty($cidr_ranges)) {
            $this->main_plugin->log_entry(
                sprintf(
                    __(
                        '⚠️ IP Whitelist: %d CIDR range(s) (%s) cannot be expressed as SetEnvIf rules and were skipped. Apache\'s SetEnvIf directive does not support CIDR notation. Use individual plain IP addresses for automatic .htaccess whitelisting.',
                        "optistate"
                    ),
                    count($cidr_ranges),
                    implode(", ", $cidr_ranges)
                ),
                "error"
            );
        }
        if (empty($plain_ips)) {
            return "";
        }
        $lines = [];
        $lines[] = "# BEGIN WP Optimal State IP Whitelist";
        $lines[] = "<IfModule mod_setenvif.c>";
        foreach ($plain_ips as $ip) {
            $escaped = preg_quote($ip, "/");
            $lines[] = " SetEnvIf Remote_Addr \"^{$escaped}$\" OptiWhitelisted";
            $lines[] = " SetEnvIf X-Forwarded-For \"^{$escaped}$\" OptiWhitelisted";
            $lines[] = " SetEnvIf X-Real-IP \"^{$escaped}$\" OptiWhitelisted";
            $lines[] = " SetEnvIf CF-Connecting-IP \"^{$escaped}$\" OptiWhitelisted";
        }
        $lines[] = "</IfModule>";
        $lines[] = "# END WP Optimal State IP Whitelist";
        return implode(PHP_EOL, $lines);
    }
    private function strip_optistate_htaccess_blocks(string $content): string
    {
        $blocks_to_remove = [
            "WP Optimal State IP Whitelist",
            "WP Optimal State IP Blocking",
            "WP Optimal State Bot Blocking",
            "WP Optimal State Caching",
            "WP Optimal State Security Headers",
        ];
        foreach ($blocks_to_remove as $block_name) {
            $pattern =
                "/# BEGIN " .
                preg_quote($block_name, "/") .
                ".*?# END " .
                preg_quote($block_name, "/") .
                "/is";
            $content = preg_replace($pattern, "", $content);
        }
        $separator =
            "# ============================================================";
        $content = preg_replace(
            "/^" . preg_quote($separator, "/") . '\s*$\r?\n?/m',
            "",
            $content
        );
        return preg_replace("/\n{3,}/", "\n\n", trim($content));
    }
    public function rebuild_htaccess(): bool
    {
        $info = $this->get_htaccess_info(true, true, true);
        if (!$info["writable"]) {
            OPTISTATE_Utils::log_critical_error(
                ".htaccess rebuild aborted: file not writable",
                ["path" => $info["path"]]
            );
            return false;
        }
        $htaccess_path = $info["path"];
        $current_content = $info["content"];
        if ($current_content === false) {
            OPTISTATE_Utils::log_critical_error(
                "Unable to read .htaccess contents for rebuild",
                ["path" => $htaccess_path]
            );
            return false;
        }
        $clean_content = $this->strip_optistate_htaccess_blocks(
            $current_content
        );
        $perf_settings = $this->get_performance_settings();
        $global_settings = $this->main_plugin->settings_manager->get_persistent_settings();
        $ip_blocker_enabled = filter_var(
            $global_settings["ip_blocker_enabled"] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $whitelist_rules = "";
        if ($ip_blocker_enabled) {
            $whitelist_ips = $global_settings["ip_whitelist"] ?? [];
            $whitelist_rules = $this->_generate_whitelist_htaccess_rules(
                $whitelist_ips
            );
        }
        $ip_rules = "";
        if ($ip_blocker_enabled) {
            $all_ips_to_block = $global_settings["ip_block_list"] ?? [];
            if (!is_array($all_ips_to_block)) {
                $all_ips_to_block = [];
            }
            $all_ips_to_block = array_values(
                array_unique(array_filter($all_ips_to_block))
            );
            if (!empty($all_ips_to_block)) {
                $raw_ips = implode("\n", $all_ips_to_block);
                $ip_rules = OPTISTATE_Utils::get_ip_block_rules($raw_ips, true);
            }
        }
        $bot_rules = "";
        if (
            !empty($perf_settings["bad_bot_blocker"]["enabled"]) &&
            !empty($perf_settings["bad_bot_blocker"]["user_agents"])
        ) {
            $bot_rules = OPTISTATE_Utils::get_bot_rules(
                $perf_settings["bad_bot_blocker"]["user_agents"],
                true
            );
        }
        $caching_rules = "";
        if (!empty($perf_settings["browser_caching"])) {
            $caching_rules = OPTISTATE_Utils::get_caching_rules();
        }
        $security_headers_rules = "";
        $security_headers_settings = $perf_settings["security_headers"] ?? [];
        if (!empty($security_headers_settings["enabled"])) {
            $optional = !empty(
                $security_headers_settings["optional_headers_enabled"]
            );
            $security_headers_rules = OPTISTATE_Utils::get_security_headers_rules(
                $optional
            );
        }
        $top_block = "";
        if (!empty($whitelist_rules)) {
            $top_block .= $whitelist_rules . PHP_EOL . PHP_EOL;
        }
        if (!empty($ip_rules)) {
            $top_block .= $ip_rules . PHP_EOL . PHP_EOL;
        }
        if (!empty($bot_rules)) {
            $top_block .= $bot_rules . PHP_EOL . PHP_EOL;
        }
        if (!empty($caching_rules)) {
            $top_block .= $caching_rules . PHP_EOL . PHP_EOL;
        }
        if (!empty($security_headers_rules)) {
            $top_block .= $security_headers_rules . PHP_EOL . PHP_EOL;
        }
        $final_content = $top_block . $clean_content;
        $final_content =
            preg_replace("/\n{3,}/", "\n\n", trim($final_content)) . PHP_EOL;
        if (trim($current_content) === trim($final_content)) {
            return true;
        }
        $result = $this->main_plugin->settings_manager->secure_file_write_atomic(
            $htaccess_path,
            $final_content,
            false
        );
        if (!$result) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to write rebuilt .htaccess",
                ["path" => $htaccess_path]
            );
        }
        return $result;
    }
    public function remove_all_htaccess_rules(): bool
    {
        $info = $this->get_htaccess_info(true, true);
        if (!$info["exists"]) {
            return true;
        }
        if (!$info["writable"]) {
            OPTISTATE_Utils::log_critical_error(
                "[Deactivation] .htaccess not writable.",
                ["path" => $info["path"]]
            );
            return false;
        }
        $htaccess_path = $info["path"];
        $current_content = $info["content"];
        $clean_content = $this->strip_optistate_htaccess_blocks(
            $current_content
        );
        $final_content = $clean_content . PHP_EOL;
        if (trim($current_content) === trim($final_content)) {
            return true;
        }
        $result = $this->main_plugin->settings_manager->secure_file_write_atomic(
            $htaccess_path,
            $final_content,
            false
        );
        if ($result) {
            $this->main_plugin->log_entry(
                "🗑️ All .htaccess rules removed during deactivation"
            );
        } else {
            OPTISTATE_Utils::log_critical_error(
                "[Deactivation] Failed to write cleaned .htaccess."
            );
        }
        return $result;
    }
    private function _performance_enable_db_query_caching(): void
    {
        if (!wp_using_ext_object_cache()) {
            OPTISTATE_Utils::log_critical_error(
                "DB Query Caching skipped: persistent object cache not detected"
            );
            $this->main_plugin->log_entry(
                "⚠️ " .
                    __(
                        "DB Query Caching could not be enabled: Persistent object cache (Redis/Memcached) not detected",
                        "optistate"
                    ),
                "error"
            );
            return;
        }
        $settings_map = $this->get_performance_settings();
        $db_settings =
            $settings_map["db_query_caching"] ??
            $this->get_feature_definitions()["db_query_caching"]["default"];
        if (empty($db_settings["enabled"])) {
            return;
        }
        if (
            is_admin() ||
            wp_doing_ajax() ||
            wp_doing_cron() ||
            (defined("REST_REQUEST") && REST_REQUEST)
        ) {
            return;
        }
        if (
            isset($_SERVER["REQUEST_METHOD"]) &&
            strtoupper($_SERVER["REQUEST_METHOD"]) !== "GET"
        ) {
            return;
        }
        OPTISTATE_Utils::init_query_cache_config([
            "excluded_post_types" => array_flip(
                array_filter(
                    array_map(
                        "trim",
                        explode(",", $db_settings["exclude_post_types"])
                    )
                )
            ),
            "excluded_ids" => array_flip(
                array_filter(
                    array_map(
                        "absint",
                        explode(",", $db_settings["exclude_ids"])
                    )
                )
            ),
            "ttl_main" => (int) $db_settings["ttl_main"],
            "ttl_secondary" => (int) $db_settings["ttl_secondary"],
            "max_cache_size" => 500,
            "flush_on_comments" => !empty($db_settings["flush_on_comments"]),
            "flush_on_save" => !empty($db_settings["flush_on_save"]),
        ]);
        add_filter(
            "posts_pre_query",
            ["OPTISTATE_Utils", "intercept_query"],
            10,
            2
        );
        add_filter(
            "posts_results",
            ["OPTISTATE_Utils", "cache_query_results"],
            10,
            2
        );
        if (!empty($db_settings["flush_on_save"])) {
            $flush_main = ["OPTISTATE_Utils", "flush_cache_group_main"];
            add_action("save_post", $flush_main);
            add_action("deleted_post", $flush_main);
            add_action("wp_trash_post", $flush_main);
            add_action("switch_theme", $flush_main);
            add_action("edited_term", $flush_main);
            add_action("delete_term", $flush_main);
            add_action("create_term", $flush_main);
        }
        if (!empty($db_settings["flush_on_comments"])) {
            $flush_secondary = [
                "OPTISTATE_Utils",
                "flush_cache_group_secondary",
            ];
            add_action("comment_post", $flush_secondary);
            add_action("wp_set_comment_status", $flush_secondary);
        }
    }
    private function _performance_enable_font_optimization(
        array $settings
    ): void {
        if (empty($settings["enabled"])) {
            return;
        }
        if (!empty($settings["remove_google_fonts"])) {
            add_action(
                "wp_enqueue_scripts",
                ["OPTISTATE_Utils", "font_opt_remove_google_fonts"],
                999
            );
            add_action(
                "wp_print_styles",
                ["OPTISTATE_Utils", "font_opt_remove_google_fonts"],
                999
            );
            add_action(
                "admin_enqueue_scripts",
                ["OPTISTATE_Utils", "font_opt_remove_google_fonts"],
                999
            );
            add_action(
                "admin_print_styles",
                ["OPTISTATE_Utils", "font_opt_remove_google_fonts"],
                999
            );
            return;
        }
        if (!empty($settings["preconnect"])) {
            add_filter(
                "wp_resource_hints",
                ["OPTISTATE_Utils", "font_opt_resource_hints"],
                10,
                2
            );
        }
        if (
            !empty($settings["async_google_fonts"]) ||
            !empty($settings["display_swap"])
        ) {
            add_filter(
                "style_loader_tag",
                [$this, "_performance_font_opt_style_loader_tag"],
                10,
                4
            );
        }
    }
    public function _performance_font_opt_style_loader_tag(
        string $html,
        string $handle,
        string $href,
        string $media
    ): string {
        static $font_settings = null;
        if ($font_settings === null) {
            $settings = $this->get_performance_settings();
            $font_settings = $settings["font_optimization"] ?? [];
        }
        if (!self::_str_contains($href, "fonts.googleapis.com")) {
            return $html;
        }
        $clean_href = $href;
        if (!empty($font_settings["display_swap"])) {
            $clean_href = remove_query_arg("display", $clean_href);
            $clean_href = add_query_arg(["display" => "swap"], $clean_href);
        }
        if (!empty($font_settings["async_google_fonts"])) {
            $escaped_url = esc_url($clean_href);
            $full_html =
                '<link rel="preload" as="style" href="' . $escaped_url . '" />';
            $full_html .=
                '<link rel="stylesheet" href="' .
                $escaped_url .
                '" media="print" onload="this.media=\'all\'" />';
            $full_html .=
                '<noscript><link rel="stylesheet" href="' .
                $escaped_url .
                '" /></noscript>';
            return $full_html;
        }
        if (!empty($font_settings["display_swap"])) {
            $html = preg_replace(
                '/href=[\'"]([^\'"]+)[\'"]/',
                'href="' . esc_url($clean_href) . '"',
                $html,
                1
            );
        }
        return $html;
    }
    private function _performance_enable_lazy_load(): void
    {
        add_filter("wp_lazy_loading_enabled", "__return_true");
        if (!is_admin()) {
            add_filter(
                "wp_content_img_tag",
                ["OPTISTATE_Utils", "add_async_decoding"],
                10,
                3
            );
        }
    }
    public function _performance_block_bad_bots_php(): void
    {
        if (wp_doing_cron() || (defined("WP_CLI") && WP_CLI)) {
            return;
        }
        $global_settings = $this->main_plugin->settings_manager->get_persistent_settings();
        $ip_blocker_enabled = !empty($global_settings["ip_blocker_enabled"]);
        $whitelist = $global_settings["ip_whitelist"] ?? [];
        if ($ip_blocker_enabled && !empty($whitelist)) {
                $client_ip = OPTISTATE_Utils::get_client_ip(
                !empty($global_settings["cloudflare_enabled"]),
                $global_settings["custom_trusted_proxies"] ?? []
            );
            foreach ($whitelist as $entry) {
                if (OPTISTATE_Utils::ip_in_range($client_ip, $entry)) {
                    return;
                }
            }
        }
        $settings = $this->get_performance_settings();
        if (empty($settings["bad_bot_blocker"]["enabled"])) {
            return;
        }
        if (is_admin() || (defined("DOING_AJAX") && DOING_AJAX)) {
            return;
        }
        $request_uri = $_SERVER["REQUEST_URI"] ?? "";
        if (self::_str_contains($request_uri, "wp-login.php")) {
            return;
        }
        if (empty($_SERVER["HTTP_USER_AGENT"])) {
            return;
        }
        $user_agent = $_SERVER["HTTP_USER_AGENT"];
        static $bot_list = null;
        if ($bot_list === null) {
            $raw_bots = $settings["bad_bot_blocker"]["user_agents"] ?? "";
            $bot_list = array_values(
                array_filter(
                    array_map("trim", explode("\n", (string) $raw_bots)),
                    static fn(string $p): bool => $p !== ""
                )
            );
        }
        if (!empty($bot_list)) {
            foreach ($bot_list as $bot) {
                if (stripos($user_agent, $bot) !== false) {
                    OPTISTATE_Utils::deny_bot_access($user_agent);
                    return;
                }
            }
        }
    }
    public function ajax_check_htaccess_status(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $info = $this->get_htaccess_info(true);
        if ($info["writable"]) {
            OPTISTATE_Utils::send_json_success([
                "writable" => true,
                "message" => $info["message"],
            ]);
        } else {
            OPTISTATE_Utils::send_json_error($info["message"], 400, [
                "writable" => false,
                "exists" => $info["exists"],
            ]);
        }
    }
    public function ajax_get_performance_features(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $force_refresh = isset($_POST["refresh"]) && $_POST["refresh"] === "1";
        if (
            $force_refresh &&
            !OPTISTATE_Utils::check_rate_limit("refresh_crons", 2)
        ) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        $current_settings = $this->get_performance_settings();
        $definitions = $this->get_feature_definitions();
        $response = [];
        foreach ($definitions as $key => $feature) {
            $saved_value = $current_settings[$key] ?? [];
            $default_value = $feature["default"] ?? null;
            if (is_array($default_value)) {
                $response[$key] = wp_parse_args($saved_value, $default_value);
            } else {
                $response[$key] = isset($current_settings[$key])
                    ? $current_settings[$key]
                    : $default_value;
            }
        }
        $current_ua = isset($_SERVER["HTTP_USER_AGENT"])
            ? sanitize_text_field(wp_unslash($_SERVER["HTTP_USER_AGENT"]))
            : __("Unknown", "optistate");
        $cron_jobs = $this->get_cron_jobs($force_refresh);
        OPTISTATE_Utils::send_json_success([
            "features" => $response,
            "definitions" => $definitions,
            "revisions_defined" => $this->is_revisions_defined,
            "trash_days_defined" => $this->is_trash_days_defined,
            "current_user_agent" => $current_ua,
            "cron_jobs" => $cron_jobs,
        ]);
    }
    public function ajax_save_performance_features(): void
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
        $features = isset($_POST["features"])
            ? wp_unslash($_POST["features"])
            : [];
        if (!is_array($features)) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid data format", "optistate")
            );
            return;
        }
        $current_settings = $this->get_performance_settings();
        $definitions = $this->get_feature_definitions();
        $changes_to_log = [];
        foreach ($definitions as $key => $def) {
            if (!isset($features[$key])) {
                continue;
            }
            $old_value_raw = $current_settings[$key] ?? $def["default"];
            $new_value_raw = $features[$key];
            $old_value_normalized = $old_value_raw;
            $new_value_normalized = null;
            if (
                isset($def["type"]) &&
                $def["type"] === "custom_caching" &&
                $key === "server_caching"
            ) {
                $new_value_normalized = filter_var(
                    $new_value_raw["enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $old_value_normalized =
                    (bool) ($old_value_raw["enabled"] ?? false);
            } elseif (
                isset($def["type"]) &&
                $def["type"] === "custom_db_caching"
            ) {
                $new_value_normalized = filter_var(
                    $new_value_raw["enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $old_value_normalized =
                    (bool) ($old_value_raw["enabled"] ?? false);
            } elseif (
                isset($def["type"]) &&
                $def["type"] === "custom_bot_blocker"
            ) {
                $new_value_normalized = filter_var(
                    $new_value_raw["enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $old_value_normalized =
                    (bool) ($old_value_raw["enabled"] ?? false);
                if (
                    $key === "bad_bot_blocker" &&
                    isset(
                        $new_value_raw["user_agents"],
                        $old_value_raw["user_agents"]
                    )
                ) {
                    if (
                        trim($old_value_raw["user_agents"]) !==
                        trim($new_value_raw["user_agents"])
                    ) {
                        $changes_to_log[$key . "_list"] = [
                            "title" => $def["title"],
                            "type" => "list_update",
                        ];
                    }
                }
            } elseif (
                isset($def["type"]) &&
                $def["type"] === "custom_font_optimization"
            ) {
                $new_value_normalized = filter_var(
                    $new_value_raw["enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $old_value_normalized =
                    (bool) ($old_value_raw["enabled"] ?? false);
            } elseif (
                isset($def["type"]) &&
                $def["type"] === "custom_security_headers"
            ) {
                $new_enabled = filter_var(
                    $new_value_raw["enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $old_enabled = filter_var(
                    $old_value_raw["enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $new_optional = filter_var(
                    $new_value_raw["optional_headers_enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $old_optional = filter_var(
                    $old_value_raw["optional_headers_enabled"] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );
                $new_value_normalized = $new_enabled;
                $old_value_normalized = $old_enabled;
                if ($old_optional !== $new_optional) {
                    $changes_to_log[$key . "_optional"] = [
                        "title" => $def["title"],
                        "old" => $old_optional,
                        "new" => $new_optional,
                        "type" => "optional_update",
                    ];
                }
            } elseif (isset($def["type"]) && $def["type"] === "toggle") {
                $new_value_normalized =
                    $new_value_raw === "true" ||
                    $new_value_raw === true ||
                    $new_value_raw === 1 ||
                    $new_value_raw === "1";
                $old_value_normalized = (bool) $old_value_raw;
            } elseif (isset($def["options"])) {
                $new_value_normalized = (string) $new_value_raw;
                $old_value_normalized = (string) $old_value_raw;
            }
            if ($new_value_normalized === null) {
                continue;
            }
            if ($old_value_normalized !== $new_value_normalized) {
                $changes_to_log[$key] = [
                    "title" => $def["title"],
                    "old" => $old_value_normalized,
                    "new" => $new_value_normalized,
                    "type" =>
                        $def["type"] ??
                        (isset($def["options"]) ? "options" : "unknown"),
                    "options" => $def["options"] ?? null,
                ];
            }
        }
        $old_query_mode =
            $current_settings["server_caching"]["query_string_mode"] ??
            "include_safe";
        $new_query_mode =
            $features["server_caching"]["query_string_mode"] ?? "include_safe";
        $query_mode_changed = $old_query_mode !== $new_query_mode;
        $htaccess_relevant_keys = [
            "browser_caching",
            "bad_bot_blocker",
            "bad_bot_blocker_list",
            "security_headers",
            "security_headers_optional",
        ];
        $htaccess_rebuild_needed = !empty(
            array_intersect_key(
                $changes_to_log,
                array_flip($htaccess_relevant_keys)
            )
        );
        $success = $this->_performance_save_settings($features);
        if ($success) {
            if ($htaccess_rebuild_needed) {
                $this->rebuild_htaccess();
            }
            foreach ($changes_to_log as $key => $change) {
                $operation = "";
                $title = wp_strip_all_tags($change["title"]);
                if ($change["type"] === "list_update") {
                    $operation = sprintf(
                        __("%s Updated by {username}", "optistate"),
                        $title
                    );
                } elseif ($change["type"] === "optional_update") {
                    $operation = sprintf(
                        __(
                            "%s *Optional Headers* Updated by {username}",
                            "optistate"
                        ),
                        $title
                    );
                } elseif (
                    strpos($key, "post_revisions") !== false ||
                    strpos($key, "trash_auto_empty") !== false ||
                    strpos($key, "heartbeat_api") !== false
                ) {
                    $operation = sprintf(
                        __("%s Updated by {username}", "optistate"),
                        $title
                    );
                } elseif (
                    in_array(
                        $change["type"],
                        [
                            "custom_caching",
                            "custom_db_caching",
                            "custom_bot_blocker",
                            "custom_font_optimization",
                            "custom_security_headers",
                            "toggle",
                        ],
                        true
                    )
                ) {
                    if ($change["new"]) {
                        $operation = sprintf(
                            __("%s Activated by {username}", "optistate"),
                            $title
                        );
                    } else {
                        $operation = sprintf(
                            __("%s Deactivated by {username}", "optistate"),
                            $title
                        );
                    }
                }
                if (!empty($operation)) {
                    $this->main_plugin->log_entry($operation);
                }
            }
            if ($query_mode_changed) {
                $this->main_plugin->log_entry(
                    sprintf(
                        __(
                            '❓Cache Query Mode changed to "%s" by {username}',
                            "optistate"
                        ),
                        str_replace("_", " ", $new_query_mode)
                    )
                );
            }
            OPTISTATE_Utils::send_json_success([
                "message" => __(
                    "Performance settings saved successfully!",
                    "optistate"
                ),
            ]);
        } else {
            OPTISTATE_Utils::log_critical_error(
                "Failed to save performance settings",
                ["features_keys" => array_keys($features)]
            );
            $this->main_plugin->log_entry(
                "❌ " .
                    __(
                        "Performance settings could not be saved due to a database error",
                        "optistate"
                    ),
                "error"
            );
            OPTISTATE_Utils::send_json_error(
                __("Failed to save settings. Please try again.", "optistate")
            );
        }
    }
    private function is_constant_in_wp_config(string $constant_name): bool
    {
        if (!defined($constant_name)) {
            return false;
        }
        $fs = $this->main_plugin->get_filesystem();
        $config_file = ABSPATH . "wp-config.php";
        if (!$fs->exists($config_file)) {
            $config_file = dirname(ABSPATH) . "/wp-config.php";
            if (!$fs->exists($config_file)) {
                return false;
            }
        }
        $config_content = $fs->get_contents($config_file);
        if ($config_content === false || empty($config_content)) {
            OPTISTATE_Utils::log_critical_error(
                "Failed to read wp-config.php for constant check",
                ["constant" => $constant_name, "path" => $config_file]
            );
            return false;
        }
        $quoted = preg_quote($constant_name, "/");
        $define_pattern = '/define\s*\(\s*[\'"]' . $quoted . '[\'"]\s*,/';
        $const_pattern = "/(?:^|[\s;{}])const\s+" . $quoted . "\s*=/m";
        return preg_match($define_pattern, $config_content) === 1 ||
            preg_match($const_pattern, $config_content) === 1;
    }
}