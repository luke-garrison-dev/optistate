<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Legacy_Scanner
{
    private OPTISTATE $main_plugin;
    private static ?array $plugin_map_cache = null;
    private static ?array $prefix_lookup_cache = null;
    private static ?array $slug_lookup_cache = null;
    private static ?string $prefix_lookup_regex = null;
    private static ?array $prefix_to_info_map = null;
    private array $active_check_cache = [];
    private array $installed_check_cache = [];
    private int $scan_start_time = 0;
    private int $max_execution_time = 25;
    private const SKIP_FOLDERS = [
        ".git",
        ".svn",
        ".hg",
        ".idea",
        ".vscode",
        "node_modules",
        "bower_components",
        "vendor",
        "cache",
        "tmp",
        "temp",
        "logs",
        "backup",
    ];
    private const FOLDER_SCAN_STATE_KEY = "optistate_ls_folder_v2";
    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        add_action("wp_ajax_optistate_scan_legacy_data", [
            $this,
            "ajax_scan_legacy_data",
        ]);
        add_action("wp_ajax_optistate_delete_legacy_data", [
            $this,
            "ajax_delete_legacy_data",
        ]);
        add_action("wp_ajax_optistate_list_trash_items", [
            $this,
            "ajax_list_trash_items",
        ]);
        add_action("wp_ajax_optistate_restore_trash_item", [
            $this,
            "ajax_restore_trash_item",
        ]);
        add_action("wp_ajax_optistate_permanently_delete_trash_item", [
            $this,
            "ajax_permanently_delete_trash_item",
        ]);
        add_action("activated_plugin", [$this, "invalidate_folder_cache"]);
        add_action("deactivated_plugin", [$this, "invalidate_folder_cache"]);
        add_action("switch_theme", [$this, "invalidate_folder_cache"]);
        add_action(
            "upgrader_process_complete",
            function ($upgrader, $options) {
                if (
                    in_array($options["action"], ["install", "update"], true) &&
                    in_array($options["type"], ["plugin", "theme"], true)
                ) {
                    $this->invalidate_folder_cache();
                }
            },
            10,
            2
        );
    }
    public function should_stop_scan(): bool
    {
        if ($this->scan_start_time === 0) {
            return false;
        }
        return time() - $this->scan_start_time >= $this->max_execution_time;
    }
    private function safe_is_dir(string $path): bool
    {
        if ($this->should_stop_scan()) {
            return false;
        }
        return @is_dir($path);
    }
    private function build_plugin_lookup_tables(): void
    {
        if (self::$plugin_map_cache !== null) {
            return;
        }
        $plugin_map = $this->get_legacy_plugin_map();
        self::$prefix_lookup_cache = [];
        self::$slug_lookup_cache = [];
        foreach ($plugin_map as $prefix => $data) {
            $prefix_lower = strtolower($prefix);
            $clean_prefix = trim($prefix, "_");
            if (!empty($clean_prefix) && strlen($clean_prefix) >= 2) {
                self::$prefix_lookup_cache[$prefix_lower] = [
                    "original_prefix" => $prefix,
                    "data" => $data,
                ];
                $clean_prefix_lower = strtolower($clean_prefix);
                if ($clean_prefix_lower !== $prefix_lower) {
                    self::$prefix_lookup_cache[$clean_prefix_lower] = [
                        "original_prefix" => $clean_prefix,
                        "data" => $data,
                    ];
                }
            }
            if (!empty($data["slugs"])) {
                foreach ((array) $data["slugs"] as $slug) {
                    $slug_lower = strtolower($slug);
                    $slug_sanitized = strtolower(sanitize_key($slug));
                    if (!isset(self::$slug_lookup_cache[$slug_lower])) {
                        self::$slug_lookup_cache[$slug_lower] = [];
                    }
                    self::$slug_lookup_cache[$slug_lower][] = [
                        "prefix" => $prefix,
                        "data" => $data,
                        "is_wildcard" => strpos($slug, "*") !== false,
                    ];
                    if ($slug_sanitized !== $slug_lower) {
                        if (!isset(self::$slug_lookup_cache[$slug_sanitized])) {
                            self::$slug_lookup_cache[$slug_sanitized] = [];
                        }
                        self::$slug_lookup_cache[$slug_sanitized][] = [
                            "prefix" => $prefix,
                            "data" => $data,
                            "is_wildcard" => strpos($slug, "*") !== false,
                        ];
                    }
                }
            }
        }
        self::$plugin_map_cache = $plugin_map;
    }
    private function get_prefix_lookup_regex(): ?string
    {
        if (self::$prefix_lookup_regex !== null) {
            return self::$prefix_lookup_regex;
        }
        if (empty(self::$prefix_lookup_cache)) {
            return null;
        }
        $map = [];
        foreach (self::$prefix_lookup_cache as $prefix => $info) {
            $map[$prefix] = $info;
            $trimmed = trim($prefix, "_");
            if ($trimmed !== $prefix) {
                $map[$trimmed] = $info;
            }
        }
        self::$prefix_to_info_map = $map;
        $keys = array_keys($map);
        usort($keys, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        $escaped = array_map(static function ($k) {
            return preg_quote($k, "/");
        }, $keys);
        self::$prefix_lookup_regex = "/^(" . implode("|", $escaped) . ")/i";
        return self::$prefix_lookup_regex;
    }
    private function is_plugin_active(
        ?string $slug,
        array $plugin_slugs,
        array $active_slugs_cache
    ): bool {
        $cache_key =
            ($slug ?? "null") . "_" . md5(wp_json_encode($plugin_slugs));
        if (isset($this->active_check_cache[$cache_key])) {
            return $this->active_check_cache[$cache_key];
        }
        foreach ((array) $plugin_slugs as $check_slug) {
            $check_slug_sanitized = strtolower(sanitize_key($check_slug));
            if (
                in_array(
                    $check_slug_sanitized,
                    $active_slugs_cache["all"],
                    true
                )
            ) {
                $this->active_check_cache[$cache_key] = true;
                return true;
            }
            if (strpos($check_slug, "*") !== false) {
                $escaped_slug = preg_quote($check_slug_sanitized, "/");
                $pattern =
                    "/^" . str_replace("\*", ".*", $escaped_slug) . '$/i';
                foreach ($active_slugs_cache["all"] as $active_slug) {
                    if (preg_match($pattern, $active_slug)) {
                        $this->active_check_cache[$cache_key] = true;
                        return true;
                    }
                }
            }
        }
        $this->active_check_cache[$cache_key] = false;
        return false;
    }
    public function is_item_active_or_installed(array $item): bool
    {
        $slugs = (array) ($item["slugs"] ?? []);
        if (empty($slugs)) {
            return false;
        }
        $primary_slug = (string) reset($slugs);
        $active_cache = $this->get_active_status_cache();
        if ($this->is_plugin_active($primary_slug, $slugs, $active_cache)) {
            return true;
        }
        $installed_cache = $this->get_installed_status_cache();
        return $this->is_plugin_installed(
            $primary_slug,
            $slugs,
            $installed_cache["all_slugs"]
        );
    }
    private function is_plugin_installed(
        ?string $slug,
        array $plugin_slugs,
        array $installed_slugs_cache
    ): bool {
        $cache_key =
            ($slug ?? "null") . "_" . md5(wp_json_encode($plugin_slugs));
        if (isset($this->installed_check_cache[$cache_key])) {
            return $this->installed_check_cache[$cache_key];
        }
        foreach ((array) $plugin_slugs as $check_slug) {
            $check_slug_sanitized = strtolower(sanitize_key($check_slug));
            if (in_array($check_slug_sanitized, $installed_slugs_cache, true)) {
                $this->installed_check_cache[$cache_key] = true;
                return true;
            }
            if (strpos($check_slug, "*") !== false) {
                $escaped_slug = preg_quote($check_slug_sanitized, "/");
                $pattern =
                    "/^" . str_replace("\*", ".*", $escaped_slug) . '$/i';
                foreach ($installed_slugs_cache as $installed_slug) {
                    if (preg_match($pattern, $installed_slug)) {
                        $this->installed_check_cache[$cache_key] = true;
                        return true;
                    }
                }
            }
        }
        $this->installed_check_cache[$cache_key] = false;
        return false;
    }
    private function get_active_status_cache(bool $force = false): array
    {
        static $cached = null;
        if ($force) {
            $cached = null;
        }
        if ($cached !== null) {
            return $cached;
        }
        $active = ["plugins" => [], "themes" => [], "all" => []];
        $active_plugins = (array) get_option("active_plugins", []);
        if (is_multisite()) {
            $active_plugins = array_merge(
                $active_plugins,
                array_keys(
                    (array) get_site_option("active_sitewide_plugins", [])
                )
            );
        }
        foreach ($active_plugins as $plugin_path) {
            $slug = dirname($plugin_path);
            if ($slug === ".") {
                $slug = basename($plugin_path, ".php");
            }
            $slug_normalized = strtolower(sanitize_key($slug));
            $active["plugins"][] = $slug_normalized;
            $active["all"][] = $slug_normalized;
            $main_file = basename($plugin_path, ".php");
            if ($main_file !== $slug) {
                $main_normalized = strtolower(sanitize_key($main_file));
                $active["plugins"][] = $main_normalized;
                $active["all"][] = $main_normalized;
            }
        }
        $mu_plugins = glob(WPMU_PLUGIN_DIR . "/*.php") ?: [];
        foreach ($mu_plugins as $mu_path) {
            $mu_normalized = strtolower(
                sanitize_key(basename($mu_path, ".php"))
            );
            $active["plugins"][] = $mu_normalized;
            $active["all"][] = $mu_normalized;
        }
        $theme = wp_get_theme();
        $stylesheet = strtolower(sanitize_key($theme->get_stylesheet()));
        $template = strtolower(sanitize_key($theme->get_template()));
        $theme_name = strtolower(sanitize_key($theme->get("Name")));
        $active["themes"][] = $stylesheet;
        $active["themes"][] = $template;
        $active["themes"][] = $theme_name;
        $active["all"][] = $stylesheet;
        $active["all"][] = $template;
        $active["all"][] = $theme_name;
        if ($theme->parent()) {
            $parent = $theme->parent();
            $parent_stylesheet = strtolower(
                sanitize_key($parent->get_stylesheet())
            );
            $parent_template = strtolower(
                sanitize_key($parent->get_template())
            );
            $parent_name = strtolower(sanitize_key($parent->get("Name")));
            $active["themes"][] = $parent_stylesheet;
            $active["themes"][] = $parent_template;
            $active["themes"][] = $parent_name;
            $active["all"][] = $parent_stylesheet;
            $active["all"][] = $parent_template;
            $active["all"][] = $parent_name;
        }
        $active["plugins"] = array_unique($active["plugins"]);
        $active["themes"] = array_unique($active["themes"]);
        $active["all"] = array_unique($active["all"]);
        $cached = $active;
        return $cached;
    }
    private function get_installed_status_cache(bool $force = false): array
    {
        static $cached = null;
        if ($force) {
            $cached = null;
        }
        if ($cached !== null) {
            return $cached;
        }
        if (!function_exists("get_plugins")) {
            require_once ABSPATH . "wp-admin/includes/plugin.php";
        }
        $installed = [
            "plugins" => [],
            "themes" => [],
            "all_slugs" => [],
            "all_dirs" => [],
        ];
        $all_plugins = get_plugins();
        foreach ($all_plugins as $plugin_path => $plugin_data) {
            $dir = dirname($plugin_path);
            if ($dir !== ".") {
                $dir_normalized = strtolower($dir);
                $installed["plugins"][] = $dir_normalized;
                $installed["all_dirs"][] = $dir_normalized;
                $slug_normalized = strtolower(sanitize_key($dir));
                $installed["all_slugs"][] = $slug_normalized;
            }
            $basename = strtolower(basename($plugin_path, ".php"));
            $installed["all_slugs"][] = $basename;
        }
        $all_themes = wp_get_themes();
        foreach ($all_themes as $theme_slug => $theme_obj) {
            $theme_slug_normalized = strtolower($theme_slug);
            $installed["themes"][] = $theme_slug_normalized;
            $installed["all_dirs"][] = $theme_slug_normalized;
            $installed["all_slugs"][] = $theme_slug_normalized;
            $theme_name = strtolower(sanitize_key($theme_obj->get("Name")));
            $installed["all_slugs"][] = $theme_name;
        }
        $mu_plugins = glob(WPMU_PLUGIN_DIR . "/*.php") ?: [];
        foreach ($mu_plugins as $mu_path) {
            $mu_normalized = strtolower(
                sanitize_key(basename($mu_path, ".php"))
            );
            $installed["plugins"][] = $mu_normalized;
            $installed["all_slugs"][] = $mu_normalized;
        }
        $installed["plugins"] = array_unique($installed["plugins"]);
        $installed["themes"] = array_unique($installed["themes"]);
        $installed["all_slugs"] = array_unique($installed["all_slugs"]);
        $installed["all_dirs"] = array_unique($installed["all_dirs"]);
        $cached = $installed;
        return $cached;
    }
    private function identify_legacy_source(
        string $item_name,
        string $item_type = "unknown"
    ): ?array {
        $this->build_plugin_lookup_tables();
        $item_lower = strtolower($item_name);
        $item_slug = strtolower(sanitize_key($item_name));
        $active_cache = $this->get_active_status_cache();
        $installed_cache = $this->get_installed_status_cache();
        if (isset(self::$slug_lookup_cache[$item_slug])) {
            foreach (self::$slug_lookup_cache[$item_slug] as $match) {
                $plugin_slugs = (array) $match["data"]["slugs"];
                $is_installed = $this->is_plugin_installed(
                    $item_slug,
                    $plugin_slugs,
                    $installed_cache["all_slugs"]
                );
                if ($item_type === "folder" || $item_type === "upload_folder") {
                    if ($is_installed) {
                        return null;
                    }
                    return [
                        "prefix" => $match["prefix"],
                        "data" => $match["data"],
                        "match_type" => "folder_exact_slug",
                    ];
                }
                if (
                    $this->is_plugin_active(
                        $item_slug,
                        $plugin_slugs,
                        $active_cache
                    ) ||
                    $is_installed
                ) {
                    return null;
                }
                return [
                    "prefix" => $match["prefix"],
                    "data" => $match["data"],
                    "match_type" => "exact_slug",
                ];
            }
        }
        if ($item_type === "folder" || $item_type === "upload_folder") {
            foreach (self::$slug_lookup_cache as $slug => $matches) {
                if (strlen($slug) < 3 || strlen($item_lower) < 3) {
                    continue;
                }
                $match_found = false;
                $match_type = "";
                if (strpos($item_lower, $slug) !== false) {
                    $match_found = true;
                    $match_type = "folder_contains_slug";
                } elseif (strpos($slug, $item_lower) !== false) {
                    $match_found = true;
                    $match_type = "slug_contains_folder";
                } else {
                    $patterns = [
                        $slug . "_uploads",
                        $slug . "-uploads",
                        $slug . "_files",
                        $slug . "-files",
                        $slug . "_assets",
                        $slug . "-assets",
                    ];
                    foreach ($patterns as $pattern) {
                        if (
                            $item_lower === $pattern ||
                            strpos($item_lower, $pattern) !== false
                        ) {
                            $match_found = true;
                            $match_type = "folder_pattern";
                            break;
                        }
                    }
                }
                if ($match_found) {
                    foreach ($matches as $match) {
                        $plugin_slugs = (array) $match["data"]["slugs"];
                        if (
                            !$this->is_plugin_installed(
                                $item_slug,
                                $plugin_slugs,
                                $installed_cache["all_slugs"]
                            )
                        ) {
                            return [
                                "prefix" => $match["prefix"],
                                "data" => $match["data"],
                                "match_type" => $match_type,
                            ];
                        }
                    }
                }
            }
            return null;
        }
        $prefix_regex = $this->get_prefix_lookup_regex();
        if (
            $prefix_regex !== null &&
            preg_match($prefix_regex, $item_lower, $matches)
        ) {
            $matched_prefix = $matches[1];
            if (isset(self::$prefix_to_info_map[$matched_prefix])) {
                $info = self::$prefix_to_info_map[$matched_prefix];
                $plugin_slugs = (array) $info["data"]["slugs"];
                $is_active = $this->is_plugin_active(
                    $item_slug,
                    $plugin_slugs,
                    $active_cache
                );
                $is_installed = $this->is_plugin_installed(
                    $item_slug,
                    $plugin_slugs,
                    $installed_cache["all_slugs"]
                );
                if ($is_active || $is_installed) {
                    return null;
                }
                foreach ($installed_cache["all_slugs"] as $installed_slug) {
                    if (strpos($installed_slug, $matched_prefix) === 0) {
                        return null;
                    }
                }
                return [
                    "prefix" => $matched_prefix,
                    "data" => $info["data"],
                    "match_type" => "prefix",
                ];
            }
        }
        return null;
    }
    private function belongs_to_any_installed_item(string $item_name): bool
    {
        $installed_cache = $this->get_installed_status_cache();
        $item_lower = strtolower($item_name);
        $item_slug = strtolower(sanitize_key($item_name));
        if (
            in_array($item_lower, $installed_cache["all_dirs"], true) ||
            in_array($item_slug, $installed_cache["all_slugs"], true)
        ) {
            return true;
        }
        foreach ($installed_cache["all_slugs"] as $slug) {
            if (strlen($slug) < 3) {
                continue;
            }
            if (
                strpos($item_lower, $slug . "_") === 0 ||
                strpos($item_lower, $slug . "-") === 0
            ) {
                return true;
            }
        }
        return false;
    }
    private function is_core_key(string $key, array $patterns): bool
    {
        static $core_cache = [];
        if (isset($core_cache[$key])) {
            return $core_cache[$key];
        }
        $core_keys = [
            "_edit_",
            "_wp_",
            "wp_",
            "rss_",
            "widget_",
            "nav_menu_",
            "cron",
            "siteurl",
            "home",
            "user_roles",
            "theme_mods_",
            "custom_css_",
            "stylesheet",
            "template",
            "current_theme",
            "db_version",
            "rewrite_rules",
        ];
        if (in_array($key, $core_keys, true)) {
            $core_cache[$key] = true;
            return true;
        }
        foreach ($patterns as $p) {
            if (strpos($key, $p) === 0) {
                $core_cache[$key] = true;
                return true;
            }
        }
        if (
            strpos($key, "_transient_") === 0 ||
            strpos($key, "_site_transient_") === 0 ||
            strpos($key, "_transient_timeout_") === 0 ||
            strpos($key, "_site_transient_timeout_") === 0
        ) {
            $core_cache[$key] = true;
            return true;
        }
        $core_cache[$key] = false;
        return false;
    }
    private function get_formatted_label(array $data): string
    {
        $name = $data["name"] ?? "Unknown";
        $type_label =
            isset($data["type"]) && $data["type"] === "theme"
                ? "Theme"
                : "Plugin";
        return "Legacy: " . $name . " (" . $type_label . ")";
    }
    public function ajax_scan_legacy_data(): void
    {
        $this->scan_start_time = time();
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request method.", "optistate"),
                405
            );
            return;
        }
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("legacy_scan", 5)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        wp_raise_memory_limit("admin");
        try {
            $user_id = get_current_user_id();
            $state_key = self::FOLDER_SCAN_STATE_KEY . "_" . $user_id;
            $has_scan_state = get_transient($state_key) !== false;
            $is_new_scan =
                !$has_scan_state ||
                (!empty($_POST["new_scan"]) && $_POST["new_scan"] === "1");
            if ($is_new_scan) {
                $this->invalidate_folder_cache();
            } else {
                $this->get_legacy_plugin_map(true);
                self::$plugin_map_cache = null;
                self::$prefix_lookup_cache = null;
                self::$slug_lookup_cache = null;
                self::$prefix_lookup_regex = null;
                self::$prefix_to_info_map = null;
                $this->clear_status_caches();
            }
            if ($this->main_plugin->trash_manager) {
                delete_transient("optistate_trash_table_exists");
                $this->main_plugin->trash_manager->ensure_table_exists();
            }
            global $wpdb;
            $this->build_plugin_lookup_tables();
            $installed_cache = $this->get_installed_status_cache();
            $conditions = $this->build_pattern_conditions();
            $results = [];
            if (!empty($conditions)) {
                $results = $this->scan_database_tables($conditions);
            }
            $this->scan_folders_resumable($results, $installed_cache);
            OPTISTATE_Utils::send_json_success($results);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Legacy scanner: unhandled exception",
                [
                    "message" => $e->getMessage(),
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                ]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred during scanning. Please try again.",
                    "optistate"
                )
            );
        }
    }
    private function build_pattern_conditions(): array
    {
        global $wpdb;
        $map = $this->get_legacy_plugin_map();
        $conditions = [];
        $seen = [];
        foreach ($map as $prefix => $data) {
            $trimmed = trim($prefix, "_");
            if (!empty($trimmed) && strlen($trimmed) >= 2) {
                $key = "prefix_" . $trimmed;
                if (!in_array($key, $seen, true)) {
                    $seen[] = $key;
                    $escaped = $wpdb->esc_like($trimmed);
                    $conditions[] = $wpdb->prepare(
                        "key_name LIKE %s",
                        $escaped . $wpdb->esc_like("_") . "%"
                    );
                }
            }
            if (!empty($data["slugs"])) {
                foreach ((array) $data["slugs"] as $slug) {
                    if (empty($slug)) {
                        continue;
                    }
                    $slug = trim($slug);
                    if (strpos($slug, "*") !== false) {
                        $base = str_replace("*", "", $slug);
                        if (!empty($base)) {
                            $key = "wildcard_" . $base;
                            if (!in_array($key, $seen, true)) {
                                $seen[] = $key;
                                $escaped = $wpdb->esc_like($base);
                                $conditions[] = $wpdb->prepare(
                                    "key_name LIKE %s",
                                    $escaped . "%"
                                );
                            }
                        }
                    } else {
                        $key = "exact_" . $slug;
                        if (!in_array($key, $seen, true)) {
                            $seen[] = $key;
                            $conditions[] = $wpdb->prepare(
                                "key_name = %s",
                                $slug
                            );
                        }
                    }
                }
            }
        }
        return $conditions;
    }
    private function scan_database_tables(array $conditions): array
    {
        global $wpdb;
        $db_prefix = $wpdb->prefix;
        $core_patterns = [
            "_edit_",
            "_wp_",
            "wp_",
            "rss_",
            "widget_",
            "nav_menu_",
            "cron",
            "siteurl",
            "home",
            "user_roles",
            "theme_mods_",
            "custom_css_",
            "stylesheet",
            "template",
            "current_theme",
            "db_version",
            "rewrite_rules",
        ];
        $transient_patterns = [
            $wpdb->esc_like("_transient_") . "%",
            $wpdb->esc_like("_site_transient_") . "%",
            $wpdb->esc_like("_transient_timeout_") . "%",
            $wpdb->esc_like("_site_transient_timeout_") . "%",
        ];
        $results = [];
        $seen_items = [];
        $build_where = static function (string $col) use ($conditions): string {
            $col_conditions = array_map(
                static fn(string $cond): string => str_replace(
                    "key_name",
                    $col,
                    $cond
                ),
                $conditions
            );
            return "WHERE (" . implode(" OR ", $col_conditions) . ")";
        };
        $options_where = $build_where("option_name");
        $meta_where_sql = $build_where("meta_key");
        $batch_size = 1000;
        $last_option = isset($_POST["resume_option"])
            ? sanitize_text_field(wp_unslash($_POST["resume_option"]))
            : (get_transient("optistate_scan_last_option") ?:
            "");
        do {
            if ($this->should_stop_scan()) {
                break;
            }
            $sql = $wpdb->prepare(
                "SELECT option_name, autoload, LENGTH(option_value) as size FROM {$wpdb->options} {$options_where} AND option_name > %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d",
                $last_option,
                $transient_patterns[0],
                $transient_patterns[1],
                $transient_patterns[2],
                $transient_patterns[3],
                $batch_size
            );
            $opt_rows = $wpdb->get_results($sql);
            if ($wpdb->last_error) {
                OPTISTATE_Utils::log_critical_error(
                    "Legacy scanner: options query failed",
                    ["error" => $wpdb->last_error]
                );
                break;
            }
            if (empty($opt_rows)) {
                delete_transient("optistate_scan_last_option");
                break;
            }
            foreach ($opt_rows as $row) {
                $last_option = $row->option_name;
                $name = $row->option_name;
                if (empty($name) || strlen($name) > 191) {
                    continue;
                }
                if ($this->is_core_key($name, $core_patterns)) {
                    continue;
                }
                if (
                    in_array(
                        $name,
                        [
                            "siteurl",
                            "home",
                            "blogname",
                            "admin_email",
                            "active_plugins",
                            "template",
                            "stylesheet",
                            "cron",
                            "db_version",
                            "user_roles",
                            "wp_user_roles",
                            "rewrite_rules",
                            "users_can_register",
                        ],
                        true
                    )
                ) {
                    continue;
                }
                if ($this->belongs_to_any_installed_item($name)) {
                    continue;
                }
                $source = $this->identify_legacy_source($name, "option");
                if ($source) {
                    $item_key = "option:" . $name;
                    if (!isset($seen_items[$item_key])) {
                        $seen_items[$item_key] = true;
                        $risk =
                            $row->size > 50000
                                ? "high"
                                : $source["data"]["risk"];
                        $results[] = [
                            "type" => "option",
                            "name" => $name,
                            "count" => size_format($row->size, 2),
                            "display_type" => "option",
                            "label" => $this->get_formatted_label(
                                $source["data"]
                            ),
                            "risk" => $risk,
                            "risk_note" =>
                                "Data from uninstalled " .
                                ($source["data"]["type"] ?? "plugin") .
                                ": " .
                                $source["data"]["name"],
                            "autoload" => in_array(
                                $row->autoload,
                                ["yes", "on", "auto-on"],
                                true
                            ),
                        ];
                    }
                }
            }
            set_transient(
                "optistate_scan_last_option",
                $last_option,
                HOUR_IN_SECONDS
            );
        } while (count($opt_rows) === $batch_size);
        $wpdb->flush();
        $meta_tables = [
            "postmeta" => $wpdb->postmeta,
            "commentmeta" => $wpdb->commentmeta,
            "usermeta" => $wpdb->usermeta,
            "termmeta" => $wpdb->termmeta,
        ];
        foreach ($meta_tables as $meta_type => $table) {
            if ($this->should_stop_scan()) {
                break;
            }
            $transient_key = "optistate_scan_last_meta_" . $meta_type;
            $last_meta = isset($_POST["resume_meta_" . $meta_type])
                ? sanitize_text_field(
                    wp_unslash($_POST["resume_meta_" . $meta_type])
                )
                : (get_transient($transient_key) ?:
                "");
            do {
                if ($this->should_stop_scan()) {
                    break;
                }
                $sql = $wpdb->prepare(
                    "SELECT DISTINCT meta_key FROM {$table} {$meta_where_sql} AND meta_key > %s ORDER BY meta_key LIMIT %d",
                    $last_meta,
                    $batch_size
                );
                $meta_rows = $wpdb->get_results($sql);
                if ($wpdb->last_error) {
                    OPTISTATE_Utils::log_critical_error(
                        "Legacy scanner: {$meta_type} query failed",
                        ["error" => $wpdb->last_error]
                    );
                    break 2;
                }
                if (empty($meta_rows)) {
                    delete_transient($transient_key);
                    break;
                }
                $last_meta = end($meta_rows)->meta_key;
                $batch_matches = [];
                foreach ($meta_rows as $row) {
                    $key = $row->meta_key;
                    if (empty($key) || strlen($key) > 191) {
                        continue;
                    }
                    if ($this->is_core_key($key, $core_patterns)) {
                        continue;
                    }
                    if ($this->belongs_to_any_installed_item($key)) {
                        continue;
                    }
                    $source = $this->identify_legacy_source($key, "meta");
                    $item_key = $meta_type . ":" . $key;
                    if ($source && !isset($seen_items[$item_key])) {
                        $batch_matches[$key] = $source;
                    }
                }
                if (!empty($batch_matches)) {
                    $keys_list = array_keys($batch_matches);
                    $placeholders = implode(
                        ",",
                        array_fill(0, count($keys_list), "%s")
                    );
                    $count_rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT meta_key, COUNT(*) AS cnt FROM {$table} WHERE meta_key IN ({$placeholders}) GROUP BY meta_key",
                            ...$keys_list
                        ),
                        OBJECT_K
                    );
                    foreach ($batch_matches as $key => $source) {
                        $item_key = $meta_type . ":" . $key;
                        $seen_items[$item_key] = true;
                        $count = isset($count_rows[$key])
                            ? (int) $count_rows[$key]->cnt
                            : 0;
                        $results[] = [
                            "type" => $meta_type,
                            "name" => $key,
                            "count" => number_format_i18n($count) . " rows",
                            "display_type" => $meta_type,
                            "label" => $this->get_formatted_label(
                                $source["data"]
                            ),
                            "risk" => $source["data"]["risk"],
                            "risk_note" =>
                                "Data from uninstalled " .
                                ($source["data"]["type"] ?? "plugin") .
                                ": " .
                                $source["data"]["name"],
                        ];
                    }
                }
                set_transient($transient_key, $last_meta, HOUR_IN_SECONDS);
            } while (count($meta_rows) === $batch_size);
            $wpdb->flush();
        }
        $all_tables = OPTISTATE_Utils::get_all_tables();
        $db_tables_info = OPTISTATE_Utils::with_stats_expiry_disabled(
            function () use ($wpdb) {
                return $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT TABLE_NAME, UPDATE_TIME, TABLE_ROWS FROM information_schema.tables WHERE table_schema = %s",
                        DB_NAME
                    ),
                    OBJECT_K
                );
            }
        );
        if ($wpdb->last_error) {
            OPTISTATE_Utils::log_critical_error(
                "Legacy scanner: information_schema query failed",
                ["error" => $wpdb->last_error]
            );
            $db_tables_info = [];
        }
        if (!is_array($db_tables_info)) {
            $db_tables_info = [];
        }
        foreach ($all_tables as $table) {
            if ($this->should_stop_scan()) {
                break;
            }
            if (strpos($table, $db_prefix) !== 0) {
                continue;
            }
            $clean_table = substr($table, strlen($db_prefix));
            if (
                OPTISTATE_Utils::is_core_table($table) ||
                $this->belongs_to_any_installed_item($clean_table) ||
                $this->belongs_to_any_installed_item($table)
            ) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                continue;
            }
            $source = $this->identify_legacy_source($clean_table, "table");
            if (!$source) {
                continue;
            }
            $row_count = 0;
            $table_update_time = "";
            $table_info = $db_tables_info[$table] ?? null;
            if ($table_info) {
                $table_update_time = $table_info->UPDATE_TIME ?? "";
                if (isset($table_info->TABLE_ROWS)) {
                    $row_count = (int) $table_info->TABLE_ROWS;
                }
            } else {
                $row_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM `{$table}`"
                );
                if ($wpdb->last_error) {
                    OPTISTATE_Utils::log_critical_error(
                        "Legacy scanner: COUNT(*) failed for table",
                        ["table" => $table, "error" => $wpdb->last_error]
                    );
                    $row_count = 0;
                }
            }
            $item_key = "table:" . $table;
            if (!isset($seen_items[$item_key])) {
                $seen_items[$item_key] = true;
                $results[] = [
                    "type" => "table",
                    "name" => $table,
                    "count" => number_format_i18n($row_count) . " rows",
                    "display_type" => "table",
                    "label" => $this->get_formatted_label($source["data"]),
                    "risk" => "high",
                    "risk_note" =>
                        "Table from uninstalled " .
                        ($source["data"]["type"] ?? "plugin") .
                        ": " .
                        $source["data"]["name"],
                    "last_accessed_date" => $table_update_time
                        ? date_i18n("j M Y", strtotime($table_update_time))
                        : "",
                ];
            }
        }
        return $results;
    }
    private function scan_folders_resumable(
        array &$results,
        array $installed_cache
    ): void {
        $user_id = get_current_user_id();
        $state_key = self::FOLDER_SCAN_STATE_KEY . "_" . $user_id;
        $state = get_transient($state_key);
        if (
            $state &&
            isset($state["index"], $state["seen_items"], $state["dirs"])
        ) {
            $directories = $state["dirs"];
            $seen_items = $state["seen_items"];
            $start_index = (int) $state["index"];
        } else {
            $upload_dir = wp_get_upload_dir();
            $scan_locations = [];
            $base_upload_path = $upload_dir["basedir"];
            $wp_content_dir = WP_CONTENT_DIR;
            $wp_plugin_dir = WP_PLUGIN_DIR;
            $wp_theme_dir = get_theme_root();
            $wp_mu_plugin_dir = WPMU_PLUGIN_DIR;
            $wordpress_system_dirs = [
                "upgrade",
                "upgrade-temp-backup",
                "backup-db",
                "cache",
                "languages",
                "plugins",
                "themes",
                "mu-plugins",
                "uploads",
                "database",
                "sqlite",
            ];
            if (is_dir($base_upload_path)) {
                $scan_locations["uploads"] = $base_upload_path;
            }
            if (is_dir($wp_plugin_dir)) {
                $scan_locations["plugins"] = $wp_plugin_dir;
            }
            if (is_dir($wp_theme_dir)) {
                $scan_locations["themes"] = $wp_theme_dir;
            }
            if (is_dir($wp_mu_plugin_dir)) {
                $scan_locations["mu-plugins"] = $wp_mu_plugin_dir;
            }
            if (is_dir($wp_content_dir)) {
                $scan_locations["wp-content"] = $wp_content_dir;
            }
            $scan_locations = array_filter($scan_locations, [
                $this,
                "safe_is_dir",
            ]);
            $transient_key =
                "optistate_legacy_folder_index_v4_" .
                md5(
                    serialize($scan_locations) .
                        serialize($installed_cache["all_dirs"])
                );
            $directories = OPTISTATE_Utils::get_or_set_transient(
                $transient_key,
                function () use (
                    $scan_locations,
                    $installed_cache,
                    $wordpress_system_dirs,
                    $wp_plugin_dir,
                    $wp_theme_dir,
                    $wp_mu_plugin_dir,
                    $base_upload_path
                ) {
                    $found = [];
                    foreach (
                        $scan_locations
                        as $location_name => $location_path
                    ) {
                        $handle = @opendir($location_path);
                        if (!$handle) {
                            continue;
                        }
                        $folder_count = 0;
                        $max_folders = apply_filters(
                            "optistate_max_folders_scan",
                            3000
                        );
                        while (($item = readdir($handle)) !== false) {
                            $folder_count++;
                            if ($folder_count > $max_folders) {
                                break;
                            }
                            if ($item === "." || $item === "..") {
                                continue;
                            }
                            if (in_array($item, self::SKIP_FOLDERS, true)) {
                                continue;
                            }
                            $full_path =
                                trailingslashit($location_path) . $item;
                            if ($this->safe_is_dir($full_path)) {
                                if (
                                    in_array(
                                        $item,
                                        $wordpress_system_dirs,
                                        true
                                    )
                                ) {
                                    continue;
                                }
                                if (
                                    $full_path === $wp_plugin_dir ||
                                    $full_path === $wp_theme_dir ||
                                    $full_path === $wp_mu_plugin_dir ||
                                    $full_path === $base_upload_path
                                ) {
                                    continue;
                                }
                                $item_lower = strtolower($item);
                                if (
                                    in_array(
                                        $item_lower,
                                        $installed_cache["all_dirs"],
                                        true
                                    )
                                ) {
                                    continue;
                                }
                                $found[] = [
                                    "name" => $item,
                                    "path" => $full_path,
                                    "location" => $location_name,
                                    "relative_path" => str_replace(
                                        ABSPATH,
                                        "",
                                        $full_path
                                    ),
                                    "is_plugin_dir" =>
                                        $location_name === "plugins" ||
                                        $location_name === "mu-plugins",
                                    "is_theme_dir" =>
                                        $location_name === "themes",
                                ];
                            }
                        }
                        closedir($handle);
                    }
                    return $found;
                },
                HOUR_IN_SECONDS
            );
            $seen_items = [];
            $start_index = 0;
        }
        $total_dirs = count($directories);
        $batch_size = 50;
        $processed = 0;
        $max_folders_to_show = apply_filters(
            "optistate_max_folders_display",
            200
        );
        for ($i = $start_index; $i < $total_dirs; $i++) {
            if ($this->should_stop_scan()) {
                break;
            }
            if ($processed >= $batch_size) {
                break;
            }
            $dir_info = $directories[$i];
            $folder_name = $dir_info["name"];
            $folder_lower = strtolower($folder_name);
            $location_name = $dir_info["location"];
            if (
                ($dir_info["is_plugin_dir"] || $dir_info["is_theme_dir"]) &&
                in_array($folder_lower, $installed_cache["all_dirs"], true)
            ) {
                continue;
            }
            if (
                $location_name !== "uploads" &&
                in_array($folder_lower, $installed_cache["all_dirs"], true)
            ) {
                continue;
            }
            $source = $this->identify_legacy_source($folder_name, "folder");
            if ($source) {
                $plugin_slugs = (array) $source["data"]["slugs"];
                if (
                    $this->is_plugin_installed(
                        $folder_name,
                        $plugin_slugs,
                        $installed_cache["all_slugs"]
                    )
                ) {
                    continue;
                }
                $folder_stats = OPTISTATE_Utils::get_folder_size(
                    $dir_info["path"],
                    50000,
                    5,
                    true,
                    [$this, "should_stop_scan"]
                );
                $folder_size = $folder_stats["size"];
                $has_sensitive_files = $folder_stats["sensitive"];
                $last_modified = filemtime($dir_info["path"]);
                $days_old = $last_modified
                    ? (int) floor((time() - $last_modified) / DAY_IN_SECONDS)
                    : 0;
                $risk_level = $source["data"]["risk"];
                $risk_note =
                    "Orphaned folder from uninstalled plugin: " .
                    $source["data"]["name"];
                if ($dir_info["is_plugin_dir"]) {
                    $risk_level = "high";
                    $risk_note .=
                        " (leftover in plugins directory - security risk)";
                } elseif ($dir_info["is_theme_dir"]) {
                    $risk_level = "high";
                    $risk_note .= " (leftover in themes directory)";
                } elseif (
                    $location_name === "uploads" &&
                    $folder_size > 100000000
                ) {
                    $risk_level = "high";
                    $risk_note .=
                        " (large upload folder: " .
                        size_format($folder_size, 1) .
                        ")";
                }
                if ($has_sensitive_files) {
                    $risk_level = "critical";
                    $risk_note .= " - contains sensitive files";
                }
                $item_key = "folder:" . $dir_info["path"];
                if (
                    !isset($seen_items[$item_key]) &&
                    count($results) < $max_folders_to_show
                ) {
                    $seen_items[$item_key] = true;
                    $results[] = [
                        "type" => "folder",
                        "name" => $dir_info["relative_path"],
                        "path" => $dir_info["path"],
                        "relative_path" => $dir_info["relative_path"],
                        "location" => $dir_info["location"],
                        "count" => $folder_size
                            ? size_format($folder_size, 1)
                            : "0 B",
                        "display_type" => "folder",
                        "label" => $this->get_formatted_label($source["data"]),
                        "risk" => $risk_level,
                        "risk_note" => $risk_note,
                        "days_old" => $days_old,
                        "last_accessed_date" => $last_modified
                            ? date_i18n("j M Y", $last_modified)
                            : "",
                    ];
                }
            } else {
                if ($dir_info["is_plugin_dir"] || $dir_info["is_theme_dir"]) {
                    if (
                        !in_array(
                            $folder_lower,
                            $installed_cache["all_dirs"],
                            true
                        )
                    ) {
                        $folder_stats = OPTISTATE_Utils::get_folder_size(
                            $dir_info["path"]
                        );
                        $folder_size = $folder_stats["size"];
                        $last_modified = filemtime($dir_info["path"]);
                        $days_old = $last_modified
                            ? (int) floor(
                                (time() - $last_modified) / DAY_IN_SECONDS
                            )
                            : 0;
                        $item_key = "folder:" . $dir_info["path"];
                        if (
                            !isset($seen_items[$item_key]) &&
                            count($results) < $max_folders_to_show
                        ) {
                            $seen_items[$item_key] = true;
                            $results[] = [
                                "type" => "folder",
                                "name" => $dir_info["relative_path"],
                                "path" => $dir_info["path"],
                                "relative_path" => $dir_info["relative_path"],
                                "location" => $dir_info["location"],
                                "count" => $folder_size
                                    ? size_format($folder_size, 1)
                                    : "0 B",
                                "display_type" => "folder",
                                "label" => "Legacy: Unknown Plugin/Theme",
                                "risk" => "high",
                                "risk_note" =>
                                    "Orphaned folder in " .
                                    $dir_info["location"] .
                                    " directory",
                                "days_old" => $days_old,
                                "last_accessed_date" => $last_modified
                                    ? date_i18n("j M Y", $last_modified)
                                    : "",
                            ];
                        }
                    }
                }
                if ($location_name === "uploads") {
                    $known_upload_folders = [
                        "avatars" => [],
                        "wpforms" => ["wpforms", "wpforms-lite"],
                        "elementor" => ["elementor"],
                        "woocommerce" => ["woocommerce"],
                        "wpcf7_uploads" => ["contact-form-7"],
                    ];
                    if (array_key_exists($folder_name, $known_upload_folders)) {
                        $plugin_slugs = $known_upload_folders[$folder_name];
                        $plugin_installed = false;
                        if (!empty($plugin_slugs)) {
                            $primary = reset($plugin_slugs);
                            $plugin_installed = $this->is_plugin_installed(
                                $primary,
                                $plugin_slugs,
                                $installed_cache["all_slugs"]
                            );
                        }
                        if (!$plugin_installed) {
                            $folder_stats = OPTISTATE_Utils::get_folder_size(
                                $dir_info["path"]
                            );
                            $folder_size = $folder_stats["size"];
                            $last_modified = filemtime($dir_info["path"]);
                            $days_old = $last_modified
                                ? (int) floor(
                                    (time() - $last_modified) / DAY_IN_SECONDS
                                )
                                : 0;
                            $item_key = "folder:" . $dir_info["path"];
                            if (
                                !isset($seen_items[$item_key]) &&
                                count($results) < $max_folders_to_show
                            ) {
                                $seen_items[$item_key] = true;
                                $results[] = [
                                    "type" => "folder",
                                    "name" => $dir_info["relative_path"],
                                    "path" => $dir_info["path"],
                                    "relative_path" =>
                                        $dir_info["relative_path"],
                                    "location" => $dir_info["location"],
                                    "count" => $folder_size
                                        ? size_format($folder_size, 1)
                                        : "0 B",
                                    "display_type" => "folder",
                                    "label" =>
                                        "Legacy: " .
                                        ucfirst(
                                            str_replace("_", " ", $folder_name)
                                        ),
                                    "risk" => "medium",
                                    "risk_note" =>
                                        "Orphaned upload folder from uninstalled plugin",
                                    "days_old" => $days_old,
                                    "last_accessed_date" => $last_modified
                                        ? date_i18n("j M Y", $last_modified)
                                        : "",
                                ];
                            }
                        }
                    }
                }
            }
            $processed++;
        }
        if ($i < $total_dirs) {
            set_transient(
                $state_key,
                [
                    "index" => $i,
                    "seen_items" => $seen_items,
                    "dirs" => $directories,
                ],
                30 * MINUTE_IN_SECONDS
            );
        } else {
            delete_transient($state_key);
        }
    }
    public function invalidate_folder_cache(): void
    {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_optistate_legacy_folder_index%' OR option_name LIKE '_transient_timeout_optistate_legacy_folder_index%'"
        );
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            delete_transient(self::FOLDER_SCAN_STATE_KEY . "_" . $user_id);
        }
        wp_cache_delete("alloptions", "options");
        delete_transient("optistate_scan_last_option");
        delete_transient("optistate_scan_last_meta_postmeta");
        delete_transient("optistate_scan_last_meta_commentmeta");
        delete_transient("optistate_scan_last_meta_usermeta");
        delete_transient("optistate_scan_last_meta_termmeta");
        $this->get_legacy_plugin_map(true);
        self::$plugin_map_cache = null;
        self::$prefix_lookup_cache = null;
        self::$slug_lookup_cache = null;
        self::$prefix_lookup_regex = null;
        self::$prefix_to_info_map = null;
        $this->clear_status_caches();
    }
    private function clear_status_caches(): void
    {
        $this->active_check_cache = [];
        $this->installed_check_cache = [];
        $this->get_active_status_cache(true);
        $this->get_installed_status_cache(true);
    }
    public function get_legacy_plugin_map(bool $force = false): array
    {
        static $plugin_map = null;
        if ($plugin_map === null || $force) {
            $file_path = plugin_dir_path(__FILE__) . "data/legacy-map.php";
            $plugin_map = file_exists($file_path) ? require $file_path : [];
            $plugin_map = apply_filters(
                "optistate_legacy_plugin_map",
                $plugin_map
            );
            uksort($plugin_map, static function ($a, $b) {
                return strlen($b) - strlen($a);
            });
        }
        return $plugin_map;
    }
    public function ajax_list_trash_items(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $items = $this->main_plugin->trash_manager->list_trash_items();
        OPTISTATE_Utils::send_json_success($items);
    }
    public function ajax_restore_trash_item(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $key = isset($_POST["key"])
            ? sanitize_text_field(wp_unslash($_POST["key"]))
            : "";
        if (empty($key)) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid trash item key.", "optistate")
            );
            return;
        }
        try {
            if ($this->main_plugin->trash_manager->restore_from_trash($key)) {
                OPTISTATE_Utils::send_json_success([
                    "message" => __("Item restored successfully.", "optistate"),
                ]);
            } else {
                OPTISTATE_Utils::send_json_error(
                    __("Failed to restore item.", "optistate")
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error("Restore from trash failed", [
                "key" => $key,
                "error" => $e->getMessage(),
            ]);
            OPTISTATE_Utils::send_json_error($e->getMessage());
        }
    }
    public function ajax_permanently_delete_trash_item(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $key = isset($_POST["key"])
            ? sanitize_text_field(wp_unslash($_POST["key"]))
            : "";
        if (empty($key)) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid trash item key.", "optistate")
            );
            return;
        }
        try {
            if ($this->main_plugin->trash_manager->permanently_delete($key)) {
                OPTISTATE_Utils::send_json_success([
                    "message" => __("Item permanently deleted.", "optistate"),
                ]);
            } else {
                OPTISTATE_Utils::send_json_error(
                    __("Failed to delete item permanently.", "optistate")
                );
            }
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "Permanent delete from trash failed",
                ["key" => $key, "error" => $e->getMessage()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred while deleting the folder.",
                    "optistate"
                )
            );
        }
    }
    public function ajax_delete_legacy_data(): void
    {
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request method.", "optistate"),
                405
            );
            return;
        }
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $type = sanitize_key($_POST["type"]);
        $type_map = [
            "post_meta" => "postmeta",
            "comment_meta" => "commentmeta",
            "user_meta" => "usermeta",
            "term_meta" => "termmeta",
            "postmeta" => "postmeta",
            "commentmeta" => "commentmeta",
            "usermeta" => "usermeta",
            "termmeta" => "termmeta",
            "option" => "option",
            "table" => "table",
            "folder" => "folder",
        ];
        if (!isset($type_map[$type])) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid data type.", "optistate")
            );
            return;
        }
        $trash_type = $type_map[$type];
        $name = sanitize_text_field(wp_unslash($_POST["name"]));
        if (empty($name)) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid data identifier.", "optistate")
            );
            return;
        }
        $protected_keys = [
            "siteurl",
            "home",
            "blogname",
            "admin_email",
            "active_plugins",
            "template",
            "stylesheet",
            "rewrite_rules",
            "cron",
            "users_can_register",
            "db_version",
            "user_roles",
            "wp_user_roles",
            "theme_mods",
            "widgets",
        ];
        if (
            $trash_type === "option" &&
            in_array($name, $protected_keys, true)
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Cannot delete core WordPress options.", "optistate")
            );
            return;
        }
        if (
            $trash_type !== "folder" &&
            $this->belongs_to_any_installed_item($name)
        ) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Cannot delete data from an installed plugin or theme.",
                    "optistate"
                ),
                403
            );
            return;
        }
        if ($trash_type === "table") {
            global $wpdb;
            $core_tables = [
                "posts",
                "postmeta",
                "users",
                "usermeta",
                "options",
                "comments",
                "commentmeta",
                "links",
                "terms",
                "termmeta",
                "term_taxonomy",
                "term_relationships",
            ];
            $stripped =
                strpos($name, $wpdb->prefix) === 0
                    ? substr($name, strlen($wpdb->prefix))
                    : $name;
            if (in_array($stripped, $core_tables, true)) {
                OPTISTATE_Utils::send_json_error(
                    __("Cannot delete core WordPress tables.", "optistate")
                );
                return;
            }
            if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $name))) {
                OPTISTATE_Utils::send_json_error(
                    __("Table does not exist.", "optistate")
                );
                return;
            }
        }
        if ($trash_type === "folder") {
            if (preg_match('/(?:^|[\/\\\\])\.\.(?:[\/\\\\]|$)/', $name)) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Security Error: Directory traversal detected.",
                        "optistate"
                    ),
                    403
                );
                return;
            }
            $upload_dir = wp_get_upload_dir();
            $valid_base_paths = array_filter([
                wp_normalize_path(realpath($upload_dir["basedir"])),
                wp_normalize_path(realpath(WP_CONTENT_DIR)),
                wp_normalize_path(realpath(WP_PLUGIN_DIR)),
                wp_normalize_path(realpath(get_theme_root())),
                wp_normalize_path(realpath(WPMU_PLUGIN_DIR)),
            ]);
            if (empty($valid_base_paths)) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Could not determine valid base directories.",
                        "optistate"
                    ),
                    500
                );
                return;
            }
            $full_path = wp_normalize_path(ABSPATH . ltrim($name, "/"));
            $real_target = wp_normalize_path(realpath($full_path));
            if (empty($real_target) || !is_dir($real_target)) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Directory does not exist or is not accessible.",
                        "optistate"
                    ),
                    404
                );
                return;
            }
            $is_valid = false;
            $real_target_slashed = trailingslashit($real_target);
            foreach ($valid_base_paths as $base_path) {
                if (
                    strpos(
                        $real_target_slashed,
                        trailingslashit($base_path)
                    ) === 0
                ) {
                    $is_valid = true;
                    break;
                }
            }
            if (!$is_valid) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Security Error: Invalid or unauthorized directory path.",
                        "optistate"
                    ),
                    403
                );
                return;
            }
            $basename = basename($real_target);
            $core_dirs = [
                "plugins",
                "themes",
                "mu-plugins",
                "uploads",
                "languages",
                "upgrade",
                "cache",
                "database",
                "sqlite",
            ];
            if (in_array($basename, $core_dirs, true)) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Cannot delete WordPress core directories.",
                        "optistate"
                    ),
                    403
                );
                return;
            }
            $installed_cache = $this->get_installed_status_cache();
            if (
                in_array(
                    strtolower($basename),
                    $installed_cache["all_dirs"],
                    true
                )
            ) {
                OPTISTATE_Utils::send_json_error(
                    __(
                        "Cannot delete an installed plugin or theme folder.",
                        "optistate"
                    ),
                    403
                );
                return;
            }
            $trash_key = $this->main_plugin->trash_manager->move_to_trash(
                "folder",
                $real_target
            );
            if ($trash_key) {
                OPTISTATE_Utils::send_json_success([
                    "count" => 1,
                    "message" => __(
                        "Folder moved to trash. You can restore it within 14 days.",
                        "optistate"
                    ),
                ]);
            } else {
                OPTISTATE_Utils::send_json_error(
                    __("Failed to move folder to trash.", "optistate")
                );
            }
            return;
        }
        $trash_key = $this->main_plugin->trash_manager->move_to_trash(
            $trash_type,
            $name
        );
        if ($trash_key) {
            $type_label = str_replace("_", " ", $trash_type);
            OPTISTATE_Utils::send_json_success([
                "count" => 1,
                "message" => sprintf(
                    __(
                        "%s moved to trash. You can restore it within 14 days.",
                        "optistate"
                    ),
                    ucfirst($type_label)
                ),
                "verified" => true,
            ]);
        } else {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Failed to move item to trash. Please try again.",
                    "optistate"
                )
            );
        }
    }
}