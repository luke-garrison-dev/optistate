<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Presets
{
    private static $presets = null;
    public static function get_presets(): array
    {
        if (self::$presets !== null) {
            return self::$presets;
        }
        self::$presets = [
            "default" => [
                "label" => __("Default (Reset Settings)", "optistate"),
                "description" => __(
                    "🔄 Restore all plugin settings to their factory defaults. This is useful if you want to start fresh or undo all customizations.<div style='height:8px;'></div><strong>✔ What is preserved:</strong> Google API Key, User Access Control, Blocked/Whitelisted IP numbers.",
                    "optistate"
                ),
                "config" => [],
            ],
            "balanced" => [
                "label" => __("Balanced Speed & Security", "optistate"),
                "description" => sprintf(
                    __(
                        '<strong>✔ What you get:</strong><br>• <strong>Performance:</strong> Server & browser caching, lazy loading, font optimization.<br>• <strong>Security:</strong> Login protection, Security Headers (basic), XML-RPC block, Application Passwords lock.<div style="height:8px;"></div><strong>✘ Not included:</strong><br>Query caching, IP/Bot blocking, Two-Factor Authentication, File Editor lock, and optional Security Headers.<br><br>💡 Recommended for most websites – a safe, effective mix.',
                        "optistate"
                    )
                ),
                "config" => self::get_balanced_config(),
            ],
            "max_speed_security" => [
                "label" => __("Maximum Speed & Security", "optistate"),
                "description" => sprintf(
                    __(
                        '<strong>✔ What you get:</strong><br>• <strong>Everything.</strong> All caching layers (server, query, browser), all frontend optimizations, Security Headers (basic + optional), login protection, bot/IP blocking, Two-Factor Authentication, and locks on the File Editor & Application Passwords.<div style="height:8px;"></div><strong>✘ Not included:</strong> <br><strong>Nothing.</strong> Every single performance and security feature is turned ON.<div style="height:8px;"></div><strong>️🛈 Manual setup required:</strong> IP Number Blocker, Two-Factor Authentication (2FA).<br><br>💡 Best for high‑traffic, security‑conscious sites.',
                        "optistate"
                    )
                ),
                "config" => self::get_max_speed_security_config(),
            ],
            "max_speed_only" => [
                "label" => __("Maximum Speed Only", "optistate"),
                "description" => sprintf(
                    __(
                        '<strong>✔ What you get:</strong><br>• <strong>Performance only.</strong> All caching layers (server, query, browser), font optimization, lazy loading, emoji removal, XML-RPC, and header cleanup.<div style="height:8px;"></div><strong>✘ Not included:</strong><br>Bot/IP blocking, Login protection, Two-Factor Authentication, File Editor lock, Application Passwords lock, and Security Headers.<br><br>💡 Ideal for sites where speed is #1 and security is handled separately.',
                        "optistate"
                    )
                ),
                "config" => self::get_max_speed_only_config(),
            ],
            "max_security_only" => [
                "label" => __("Maximum Security Only", "optistate"),
                "description" => sprintf(
                    __(
                        '<strong>✔ What you get:</strong><br> • <strong>Security hardening.</strong> Security Headers (basic + optional), bot/IP blocking, login protection, Two-Factor Authentication, File Editor lock, Application Passwords lock, and XML-RPC protection.<div style="height:8px;"></div> <strong>✘ Not included:</strong><br> Browser caching, Server/Query caching, lazy loading, font optimization, and emoji removal.<div style="height:8px;"></div> <strong>️🛈 Manual setup required:</strong> IP Number Blocker, Two-Factor Authentication (2FA).<br><br> 💡 Perfect when you already use a separate caching plugin or when caching is not desired.',
                        "optistate"
                    )
                ),
                "config" => self::get_max_security_only_config(),
            ],
        ];
        return self::$presets;
    }
    public static function get_preset_config(string $key): array
    {
        $presets = self::get_presets();
        if (!isset($presets[$key])) {
            return [];
        }
        return $presets[$key]["config"];
    }
    private static function get_balanced_config(): array
    {
        return [
            "max_backups" => 3,
            "auto_optimize_days" => 7,
            "auto_optimize_time" => "02:00",
            "email_notifications" => true,
            "auto_backup_only" => false,
            "disable_restore_security" => false,
            "login_protect_enabled" => true,
            "login_protect_max_attempts" => 3,
            "login_protect_block_duration" => 6,
            "cloudflare_enabled" => false,
            "ip_blocker_enabled" => false,
            "ip_block_list" => [],
            "enable_two_factor" => false,
            "one_click_extra_items" => [],
            "one_click_backup" => false,
            "performance_features" => [
                "server_caching" => [
                    "enabled" => true,
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
                "browser_caching" => true,
                "db_query_caching" => [
                    "enabled" => false,
                    "ttl_main" => 43200,
                    "ttl_secondary" => 86400,
                    "exclude_post_types" => "shop_order,ticket,product",
                    "exclude_ids" => "",
                    "flush_on_comments" => true,
                    "flush_on_save" => true,
                ],
                "font_optimization" => [
                    "enabled" => true,
                    "async_google_fonts" => true,
                    "display_swap" => true,
                    "preconnect" => true,
                    "remove_google_fonts" => false,
                ],
                "lazy_load" => true,
                "emoji_script" => true,
                "bad_bot_blocker" => [
                    "enabled" => false,
                    "user_agents" => OPTISTATE::DEFAULT_BOT_LIST,
                ],
                "xmlrpc" => true,
                "self_pingbacks" => true,
                "rest_api_link" => false,
                "shortlink" => false,
                "rsd_link" => false,
                "wlwmanifest" => true,
                "wp_generator" => false,
                "feed_links" => false,
                "post_relational_links" => false,
                "post_revisions" => "limit_5",
                "trash_auto_empty" => "days_14",
                "heartbeat_api" => "slow",
                "file_editor" => false,
                "application_passwords" => true,
                "security_headers" => [
                    "enabled" => true,
                    "optional_headers_enabled" => false,
                ],
            ],
        ];
    }
    private static function get_max_speed_security_config(): array
    {
        return [
            "max_backups" => 5,
            "auto_optimize_days" => 3,
            "auto_optimize_time" => "03:00",
            "email_notifications" => true,
            "auto_backup_only" => false,
            "disable_restore_security" => false,
            "login_protect_enabled" => true,
            "login_protect_max_attempts" => 3,
            "login_protect_block_duration" => 12,
            "cloudflare_enabled" => false,
            "login_captcha_enabled" => true,
            "ip_blocker_enabled" => true,
            "ip_block_list" => [],
            "enable_two_factor" => true,
            "one_click_extra_items" => [],
            "one_click_backup" => true,
            "performance_features" => [
                "server_caching" => [
                    "enabled" => true,
                    "lifetime" => 259200,
                    "query_string_mode" => "include_safe",
                    "exclude_urls" =>
                        "/cart*\n/my-account*\n/checkout*\n/wp-login.php*\n/wp-admin*",
                    "mobile_cache" => true,
                    "disable_cookie_check" => true,
                    "custom_consent_cookie" => "",
                    "auto_preload" => true,
                    "minify_html" => true,
                ],
                "browser_caching" => true,
                "db_query_caching" => [
                    "enabled" => true,
                    "ttl_main" => 86400,
                    "ttl_secondary" => 172800,
                    "exclude_post_types" => "shop_order,ticket,product",
                    "exclude_ids" => "",
                    "flush_on_comments" => true,
                    "flush_on_save" => true,
                ],
                "font_optimization" => [
                    "enabled" => true,
                    "async_google_fonts" => true,
                    "display_swap" => true,
                    "preconnect" => true,
                    "remove_google_fonts" => false,
                ],
                "lazy_load" => true,
                "emoji_script" => true,
                "bad_bot_blocker" => [
                    "enabled" => true,
                    "user_agents" => OPTISTATE::DEFAULT_BOT_LIST,
                ],
                "xmlrpc" => true,
                "self_pingbacks" => true,
                "rest_api_link" => true,
                "shortlink" => true,
                "rsd_link" => true,
                "wlwmanifest" => true,
                "wp_generator" => true,
                "feed_links" => true,
                "post_relational_links" => true,
                "post_revisions" => "limit_3",
                "trash_auto_empty" => "days_7",
                "heartbeat_api" => "disable_frontend",
                "file_editor" => true,
                "application_passwords" => true,
                "security_headers" => [
                    "enabled" => true,
                    "optional_headers_enabled" => true,
                ],
            ],
        ];
    }
    private static function get_max_speed_only_config(): array
    {
        return [
            "max_backups" => 3,
            "auto_optimize_days" => 7,
            "auto_optimize_time" => "02:00",
            "email_notifications" => true,
            "auto_backup_only" => false,
            "disable_restore_security" => false,
            "login_protect_enabled" => false,
            "login_protect_max_attempts" => 3,
            "login_protect_block_duration" => 6,
            "cloudflare_enabled" => false,
            "ip_blocker_enabled" => false,
            "ip_block_list" => [],
            "enable_two_factor" => false,
            "one_click_extra_items" => [],
            "one_click_backup" => false,
            "performance_features" => [
                "server_caching" => [
                    "enabled" => true,
                    "lifetime" => 604800,
                    "query_string_mode" => "include_safe",
                    "exclude_urls" =>
                        "/cart*\n/my-account*\n/checkout*\n/wp-login.php*\n/wp-admin*",
                    "mobile_cache" => true,
                    "disable_cookie_check" => true,
                    "custom_consent_cookie" => "",
                    "auto_preload" => true,
                    "minify_html" => true,
                ],
                "browser_caching" => true,
                "db_query_caching" => [
                    "enabled" => true,
                    "ttl_main" => 86400,
                    "ttl_secondary" => 172800,
                    "exclude_post_types" => "shop_order,ticket,product",
                    "exclude_ids" => "",
                    "flush_on_comments" => true,
                    "flush_on_save" => true,
                ],
                "font_optimization" => [
                    "enabled" => true,
                    "async_google_fonts" => true,
                    "display_swap" => true,
                    "preconnect" => true,
                    "remove_google_fonts" => false,
                ],
                "lazy_load" => true,
                "emoji_script" => true,
                "bad_bot_blocker" => [
                    "enabled" => false,
                    "user_agents" => OPTISTATE::DEFAULT_BOT_LIST,
                ],
                "xmlrpc" => true,
                "self_pingbacks" => true,
                "rest_api_link" => true,
                "shortlink" => true,
                "rsd_link" => true,
                "wlwmanifest" => true,
                "wp_generator" => true,
                "feed_links" => true,
                "post_relational_links" => true,
                "post_revisions" => "limit_3",
                "trash_auto_empty" => "days_7",
                "heartbeat_api" => "disable_frontend",
                "file_editor" => false,
                "application_passwords" => false,
            ],
        ];
    }
    private static function get_max_security_only_config(): array
    {
        return [
            "max_backups" => 5,
            "auto_optimize_days" => 7,
            "auto_optimize_time" => "02:00",
            "email_notifications" => true,
            "auto_backup_only" => false,
            "disable_restore_security" => false,
            "login_protect_enabled" => true,
            "login_protect_max_attempts" => 3,
            "login_protect_block_duration" => 24,
            "cloudflare_enabled" => false,
            "login_captcha_enabled" => true,
            "ip_blocker_enabled" => true,
            "ip_block_list" => [],
            "enable_two_factor" => true,
            "one_click_extra_items" => [],
            "one_click_backup" => true,
            "performance_features" => [
                "server_caching" => [
                    "enabled" => false,
                    "lifetime" => 86400,
                    "query_string_mode" => "include_safe",
                    "exclude_urls" => "",
                    "mobile_cache" => false,
                    "disable_cookie_check" => false,
                    "custom_consent_cookie" => "",
                    "auto_preload" => false,
                    "minify_html" => false,
                ],
                "browser_caching" => false,
                "db_query_caching" => [
                    "enabled" => false,
                    "ttl_main" => 43200,
                    "ttl_secondary" => 86400,
                    "exclude_post_types" => "shop_order,ticket,product",
                    "exclude_ids" => "",
                    "flush_on_comments" => true,
                    "flush_on_save" => true,
                ],
                "font_optimization" => [
                    "enabled" => false,
                    "async_google_fonts" => true,
                    "display_swap" => true,
                    "preconnect" => true,
                    "remove_google_fonts" => false,
                ],
                "lazy_load" => false,
                "emoji_script" => false,
                "bad_bot_blocker" => [
                    "enabled" => true,
                    "user_agents" => OPTISTATE::DEFAULT_BOT_LIST,
                ],
                "xmlrpc" => true,
                "self_pingbacks" => true,
                "rest_api_link" => true,
                "shortlink" => true,
                "rsd_link" => true,
                "wlwmanifest" => true,
                "wp_generator" => true,
                "feed_links" => true,
                "post_relational_links" => true,
                "post_revisions" => "default",
                "trash_auto_empty" => "default",
                "heartbeat_api" => "default",
                "file_editor" => true,
                "application_passwords" => true,
                "security_headers" => [
                    "enabled" => true,
                    "optional_headers_enabled" => true,
                ],
            ],
        ];
    }
}