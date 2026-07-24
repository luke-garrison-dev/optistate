<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Legacy_Scanner
{
    private OPTISTATE $main_plugin;

    private static ?array $plugin_map_cache = null;
    private static ?array $prefix_lookup_cache = null;
    private static ?array $slug_lookup_cache = null;
    private static ?array $prefix_to_info_map = null;
    private static int $prefix_max_length = 0;
    private static ?array $active_status_cache = null;
    private static ?array $installed_status_cache = null;

    private array $active_check_cache = [];
    private array $installed_check_cache = [];

    private float $scan_start_time = 0.0;
    private float $scan_time_budget = 0.0;
    private ?int $scan_version = null;
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
    private const CORE_KEY_PREFIXES = [
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
    private const PROTECTED_OPTIONS = [
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
    private const PROTECTED_TABLES = [
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
    private const PROTECTED_DIRECTORIES = [
        "plugins",
        "themes",
        "mu-plugins",
        "uploads",
        "languages",
        "upgrade",
        "upgrade-temp-backup",
        "cache",
        "database",
        "sqlite",
        "sites",
        "fonts",
        "wp-personal-data-exports",
    ];
    private const SYSTEM_DIRECTORIES = [
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
        "sites",
        "fonts",
        "wp-personal-data-exports",
    ];
    private const META_TYPES = [
        "postmeta",
        "commentmeta",
        "usermeta",
        "termmeta",
    ];

    private const STATE_VERSION = 4;
    private const STATE_KEY_PREFIX = "optistate_ls_state_v4";
    private const STATE_TTL = 30 * MINUTE_IN_SECONDS;
    private const DIR_INDEX_KEY_PREFIX = "optistate_ls_dirs_v4";
    private const DIR_INDEX_TTL = HOUR_IN_SECONDS;
    private const SCAN_VERSION_OPTION = "optistate_legacy_scan_version";

    private const DB_BATCH_SIZE = 1000;
    private const MAX_KEY_LENGTH = 191;
    private const MAX_OPTION_NAME_LENGTH = 191;
    private const MAX_META_KEY_LENGTH = 255;
    private const MAX_TABLE_NAME_LENGTH = 64;
    private const MAX_PATH_LENGTH = 4096;
    private const MIN_TIME_BUDGET = 5;
    private const MAX_TIME_BUDGET = 25;
    private const MIN_FOLDER_SLUG_LENGTH = 4;
    private const MAX_FOLDER_TOKENS = 24;
    private const DEFAULT_MAX_RESULTS = 500;
    private const DEFAULT_MAX_FOLDERS = 200;
    private const DEFAULT_MAX_FOLDERS_SCANNED = 3000;
    private const LARGE_OPTION_BYTES = 50000;
    private const LARGE_UPLOAD_FOLDER_BYTES = 100000000;
    private const FOLDER_STAT_MAX_FILES = 50000;
    private const FOLDER_STAT_MAX_DEPTH = 5;

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

        add_action("activated_plugin", [$this, "invalidate_folder_cache"]);
        add_action("deactivated_plugin", [$this, "invalidate_folder_cache"]);
        add_action("switch_theme", [$this, "invalidate_folder_cache"]);
        add_action(
            "upgrader_process_complete",
            [$this, "handle_upgrader_process_complete"],
            10,
            2
        );
    }
    public function handle_upgrader_process_complete(
        $upgrader = null,
        $options = null
    ): void {
        if (!is_array($options)) {
            return;
        }

        $action = isset($options["action"]) ? (string) $options["action"] : "";
        $type = isset($options["type"]) ? (string) $options["type"] : "";

        if (
            in_array($action, ["install", "update"], true) &&
            in_array($type, ["plugin", "theme"], true)
        ) {
            $this->invalidate_folder_cache();
        }
    }
    private function start_scan_clock(): void
    {
        $this->scan_start_time = microtime(true);

        $max_execution = (int) ini_get("max_execution_time");

        if ($max_execution > 0) {
            $budget = (int) floor($max_execution * 0.6);
            $budget = max(
                self::MIN_TIME_BUDGET,
                min(self::MAX_TIME_BUDGET, $budget)
            );
        } else {
            $budget = self::MAX_TIME_BUDGET;
        }

        $budget = (int) apply_filters(
            "optistate_legacy_scan_time_budget",
            $budget,
            $max_execution
        );

        $this->scan_time_budget = (float) max(1, $budget);
    }
    public function should_stop_scan(): bool
    {
        if ($this->scan_start_time <= 0.0) {
            return false;
        }

        return microtime(true) - $this->scan_start_time >=
            $this->scan_time_budget;
    }

    private function safe_is_dir(string $path): bool
    {
        if ($this->should_stop_scan()) {
            return false;
        }

        return @is_dir($path);
    }
    private function get_scan_version(): int
    {
        if ($this->scan_version === null) {
            $version = (int) get_option(self::SCAN_VERSION_OPTION, 1);
            $this->scan_version = $version > 0 ? $version : 1;
        }

        return $this->scan_version;
    }

    private function bump_scan_version(): void
    {
        $version = $this->get_scan_version() + 1;

        if ($version >= PHP_INT_MAX) {
            $version = 1;
        }

        update_option(self::SCAN_VERSION_OPTION, $version, false);

        $this->scan_version = $version;
    }
    private function reset_lookup_caches(): void
    {
        self::$plugin_map_cache = null;
        self::$prefix_lookup_cache = null;
        self::$slug_lookup_cache = null;
        self::$prefix_to_info_map = null;
        self::$prefix_max_length = 0;
    }

    private function clear_status_caches(): void
    {
        $this->active_check_cache = [];
        $this->installed_check_cache = [];

        self::$active_status_cache = null;
        self::$installed_status_cache = null;
    }
    public function invalidate_folder_cache(): void
    {
        $this->clear_scan_state();
        $this->bump_scan_version();
        $this->reset_lookup_caches();
        $this->clear_status_caches();
    }
    private function reset_scan_progress(): void
    {
        $this->clear_scan_state();
        $this->reset_lookup_caches();
        $this->clear_status_caches();
    }
    private function get_state_key(): string
    {
        return self::STATE_KEY_PREFIX .
            "_" .
            $this->get_scan_version() .
            "_" .
            get_current_user_id();
    }
    private function read_scan_state(): ?array
    {
        $state = get_transient($this->get_state_key());

        if (
            !is_array($state) ||
            ($state["version"] ?? 0) !== self::STATE_VERSION ||
            !isset($state["results"], $state["db"], $state["folders"]) ||
            !is_array($state["results"]) ||
            !is_array($state["db"]) ||
            !is_array($state["folders"]) ||
            !isset(
                $state["max_results"],
                $state["max_folders"],
                $state["folder_count"]
            )
        ) {
            return null;
        }

        $state["max_results"] = (int) $state["max_results"];
        $state["max_folders"] = (int) $state["max_folders"];
        $state["folder_count"] = (int) $state["folder_count"];

        if (
            $state["max_results"] < 1 ||
            $state["max_folders"] < 0 ||
            $state["folder_count"] < 0
        ) {
            return null;
        }

        $state["db"]["options_cursor"] = (string) ($state["db"][
            "options_cursor"
        ] ?? "");
        $state["db"]["options_done"] = !empty($state["db"]["options_done"]);
        $state["db"]["tables_cursor"] = (string) ($state["db"][
            "tables_cursor"
        ] ?? "");
        $state["db"]["tables_done"] = !empty($state["db"]["tables_done"]);

        if (
            !isset($state["db"]["meta"]) ||
            !is_array($state["db"]["meta"])
        ) {
            $state["db"]["meta"] = [];
        }

        $state["folders"]["key"] = (string) ($state["folders"]["key"] ?? "");
        $state["folders"]["count"] = max(
            0,
            (int) ($state["folders"]["count"] ?? 0)
        );
        $state["folders"]["index"] = max(
            0,
            (int) ($state["folders"]["index"] ?? 0)
        );
        $state["folders"]["done"] = !empty($state["folders"]["done"]);

        $state["seen"] = [];

        foreach ($state["results"] as $result) {
            if (isset($result["type"], $result["name"])) {
                $state["seen"][$result["type"] . ":" . $result["name"]] = true;
            }
        }

        return $state;
    }

    private function write_scan_state(array $state): void
    {
        unset($state["seen"]);

        set_transient($this->get_state_key(), $state, self::STATE_TTL);
    }

    private function clear_scan_state(): void
    {
        delete_transient($this->get_state_key());
    }

    private function create_scan_state(): array
    {
        $meta_state = [];

        foreach (self::META_TYPES as $meta_type) {
            $meta_state[$meta_type] = ["cursor" => "", "done" => false];
        }

        $max_results = (int) apply_filters(
            "optistate_max_scan_results",
            self::DEFAULT_MAX_RESULTS
        );
        $max_folders = (int) apply_filters(
            "optistate_max_folders_display",
            self::DEFAULT_MAX_FOLDERS
        );

        return [
            "version" => self::STATE_VERSION,
            "results" => [],
            "seen" => [],
            "folder_count" => 0,
            "max_results" => max(1, $max_results),
            "max_folders" => max(1, $max_folders),
            "db" => [
                "options_cursor" => "",
                "options_done" => false,
                "meta" => $meta_state,
                "tables_cursor" => "",
                "tables_done" => false,
            ],
            "folders" => [
                "key" => "",
                "count" => 0,
                "index" => 0,
                "done" => false,
            ],
        ];
    }
    private function scan_is_complete(array $state): bool
    {
        if (!$state["db"]["options_done"] || !$state["db"]["tables_done"]) {
            return false;
        }

        foreach (self::META_TYPES as $meta_type) {
            if (empty($state["db"]["meta"][$meta_type]["done"])) {
                return false;
            }
        }

        return !empty($state["folders"]["done"]);
    }
    private function results_saturated(array $state): bool
    {
        return count($state["results"]) >= (int) $state["max_results"];
    }
    private function folders_saturated(array $state): bool
    {
        return $this->results_saturated($state) ||
            (int) $state["folder_count"] >= (int) $state["max_folders"];
    }
    private function mark_scan_complete(array &$state): void
    {
        $state["db"]["options_done"] = true;
        $state["db"]["tables_done"] = true;

        foreach (self::META_TYPES as $meta_type) {
            $state["db"]["meta"][$meta_type]["done"] = true;
        }

        $state["folders"]["done"] = true;
    }
    private function add_result(array &$state, array $item): bool
    {
        if (!isset($item["type"], $item["name"])) {
            return false;
        }

        $key = $item["type"] . ":" . $item["name"];

        if (isset($state["seen"][$key])) {
            return false;
        }

        if (count($state["results"]) >= $state["max_results"]) {
            return false;
        }
        if ($item["type"] === "folder") {
            if ($state["folder_count"] >= $state["max_folders"]) {
                return false;
            }

            $state["folder_count"]++;
        }

        $state["seen"][$key] = true;
        $state["results"][] = $item;

        return true;
    }
    public function get_legacy_plugin_map(bool $force = false): array
    {
        static $plugin_map = null;

        if ($plugin_map === null || $force) {
            $file_path = plugin_dir_path(__FILE__) . "data/legacy-map.php";
            $raw_map = file_exists($file_path) ? require $file_path : [];

            if (!is_array($raw_map)) {
                $raw_map = [];
            }

            $raw_map = apply_filters("optistate_legacy_plugin_map", $raw_map);

            if (!is_array($raw_map)) {
                $raw_map = [];
            }

            $plugin_map = self::normalize_plugin_map($raw_map);
            uksort($plugin_map, static function ($a, $b): int {
                $diff = strlen((string) $b) <=> strlen((string) $a);

                return $diff !== 0 ? $diff : strcmp((string) $a, (string) $b);
            });
        }

        return $plugin_map;
    }
    private static function normalize_plugin_map(array $raw_map): array
    {
        $normalized = [];

        foreach ($raw_map as $prefix => $data) {
            if (!is_string($prefix) || $prefix === "" || !is_array($data)) {
                continue;
            }

            $slugs = [];

            if (isset($data["slugs"])) {
                foreach ((array) $data["slugs"] as $slug) {
                    if (!is_scalar($slug)) {
                        continue;
                    }

                    $slug = trim((string) $slug);

                    if ($slug !== "") {
                        $slugs[] = $slug;
                    }
                }
            }

            $data["slugs"] = array_values(array_unique($slugs));
            $data["name"] = isset($data["name"]) && is_scalar($data["name"])
                ? (string) $data["name"]
                : __("Unknown", "optistate");
            $data["risk"] = isset($data["risk"]) && is_scalar($data["risk"])
                ? (string) $data["risk"]
                : "medium";
            $data["type"] = isset($data["type"]) && is_scalar($data["type"])
                ? (string) $data["type"]
                : "plugin";

            $normalized[$prefix] = $data;
        }

        return $normalized;
    }
    private function build_plugin_lookup_tables(): void
    {
        if (self::$plugin_map_cache !== null) {
            return;
        }

        $plugin_map = $this->get_legacy_plugin_map();

        self::$prefix_lookup_cache = [];
        self::$slug_lookup_cache = [];
        self::$prefix_to_info_map = [];
        self::$prefix_max_length = 0;

        foreach ($plugin_map as $prefix => $data) {
            $prefix_lower = strtolower($prefix);
            $clean_prefix = trim($prefix, "_");

            if ($clean_prefix !== "" && strlen($clean_prefix) >= 2) {
                $entry = ["original_prefix" => $prefix, "data" => $data];

                self::$prefix_lookup_cache[$prefix_lower] = $entry;
                self::$prefix_to_info_map[$prefix_lower] = $entry;
                self::$prefix_max_length = max(
                    self::$prefix_max_length,
                    strlen($prefix_lower)
                );

                $clean_prefix_lower = strtolower($clean_prefix);

                if ($clean_prefix_lower !== $prefix_lower) {
                    $clean_entry = [
                        "original_prefix" => $clean_prefix,
                        "data" => $data,
                    ];

                    self::$prefix_lookup_cache[
                        $clean_prefix_lower
                    ] = $clean_entry;
                    self::$prefix_to_info_map[
                        $clean_prefix_lower
                    ] = $clean_entry;
                    self::$prefix_max_length = max(
                        self::$prefix_max_length,
                        strlen($clean_prefix_lower)
                    );
                }
            }

            foreach ($data["slugs"] as $slug) {
                $is_wildcard = strpos($slug, "*") !== false;
                $variants = [
                    strtolower($slug),
                    strtolower(sanitize_key($slug)),
                ];

                foreach (array_unique($variants) as $variant) {
                    if ($variant === "") {
                        continue;
                    }

                    if (!isset(self::$slug_lookup_cache[$variant])) {
                        self::$slug_lookup_cache[$variant] = [];
                    }

                    self::$slug_lookup_cache[$variant][] = [
                        "prefix" => $prefix,
                        "data" => $data,
                        "is_wildcard" => $is_wildcard,
                    ];
                }
            }
        }

        self::$plugin_map_cache = $plugin_map;
    }
    private function match_prefix(string $item_lower): ?array
    {
        if (self::$prefix_max_length < 2 || empty(self::$prefix_to_info_map)) {
            return null;
        }

        $item_length = strlen($item_lower);
        $length = min($item_length, self::$prefix_max_length);

        for (; $length >= 2; $length--) {
            $candidate = substr($item_lower, 0, $length);

            if (!isset(self::$prefix_to_info_map[$candidate])) {
                continue;
            }

            if (
                !$this->prefix_ends_on_boundary(
                    $item_lower,
                    $candidate,
                    $item_length,
                    $length
                )
            ) {
                continue;
            }

            return [
                "prefix" => $candidate,
                "info" => self::$prefix_to_info_map[$candidate],
            ];
        }

        return null;
    }
    private function prefix_ends_on_boundary(
        string $item_lower,
        string $prefix,
        int $item_length,
        int $prefix_length
    ): bool {
        $last = $prefix[$prefix_length - 1];

        if ($last === "_" || $last === "-") {
            return true;
        }

        if ($prefix_length === $item_length) {
            return true;
        }

        $next = $item_lower[$prefix_length];

        return $next === "_" || $next === "-";
    }
    private function get_active_status_cache(): array
    {
        if (self::$active_status_cache !== null) {
            return self::$active_status_cache;
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
            if (!is_string($plugin_path) || $plugin_path === "") {
                continue;
            }

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

        foreach ($this->get_mu_plugin_slugs() as $mu_slug) {
            $active["plugins"][] = $mu_slug;
            $active["all"][] = $mu_slug;
        }

        foreach ($this->get_mu_plugin_directories() as $mu_dir) {
            $active["plugins"][] = $mu_dir;
            $active["all"][] = $mu_dir;

            $mu_dir_slug = strtolower(sanitize_key($mu_dir));

            if ($mu_dir_slug !== "") {
                $active["plugins"][] = $mu_dir_slug;
                $active["all"][] = $mu_dir_slug;
            }
        }

        $theme = wp_get_theme();
        $themes = [
            strtolower(sanitize_key($theme->get_stylesheet())),
            strtolower(sanitize_key($theme->get_template())),
            strtolower(sanitize_key((string) $theme->get("Name"))),
        ];

        $parent = $theme->parent();

        if ($parent) {
            $themes[] = strtolower(sanitize_key($parent->get_stylesheet()));
            $themes[] = strtolower(sanitize_key($parent->get_template()));
            $themes[] = strtolower(sanitize_key((string) $parent->get("Name")));
        }

        foreach ($themes as $theme_slug) {
            if ($theme_slug === "") {
                continue;
            }

            $active["themes"][] = $theme_slug;
            $active["all"][] = $theme_slug;
        }

        $active["plugins"] = array_values(array_unique($active["plugins"]));
        $active["themes"] = array_values(array_unique($active["themes"]));
        $active["all"] = array_values(array_unique($active["all"]));
        $active["all_map"] = array_fill_keys($active["all"], true);

        self::$active_status_cache = $active;

        return self::$active_status_cache;
    }
    private function get_installed_status_cache(): array
    {
        if (self::$installed_status_cache !== null) {
            return self::$installed_status_cache;
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

        foreach (get_plugins() as $plugin_path => $plugin_data) {
            $dir = dirname($plugin_path);

            if ($dir !== ".") {
                $dir_normalized = strtolower($dir);
                $installed["plugins"][] = $dir_normalized;
                $installed["all_dirs"][] = $dir_normalized;
                $installed["all_slugs"][] = strtolower(sanitize_key($dir));
            }

            $installed["all_slugs"][] = strtolower(
                basename($plugin_path, ".php")
            );
        }

        foreach (wp_get_themes() as $theme_slug => $theme_obj) {
            $theme_slug_normalized = strtolower((string) $theme_slug);
            $installed["themes"][] = $theme_slug_normalized;
            $installed["all_dirs"][] = $theme_slug_normalized;
            $installed["all_slugs"][] = $theme_slug_normalized;
            $installed["all_slugs"][] = strtolower(
                sanitize_key((string) $theme_obj->get("Name"))
            );
        }

        foreach ($this->get_mu_plugin_slugs() as $mu_slug) {
            $installed["plugins"][] = $mu_slug;
            $installed["all_slugs"][] = $mu_slug;
        }

        foreach ($this->get_mu_plugin_directories() as $mu_dir) {
            $installed["plugins"][] = $mu_dir;
            $installed["all_dirs"][] = $mu_dir;
            $installed["all_slugs"][] = $mu_dir;

            $mu_dir_slug = strtolower(sanitize_key($mu_dir));

            if ($mu_dir_slug !== "") {
                $installed["all_slugs"][] = $mu_dir_slug;
            }
        }

        $installed["plugins"] = array_values(
            array_unique($installed["plugins"])
        );
        $installed["themes"] = array_values(array_unique($installed["themes"]));
        $installed["all_slugs"] = array_values(
            array_filter(array_unique($installed["all_slugs"]))
        );
        $installed["all_dirs"] = array_values(
            array_filter(array_unique($installed["all_dirs"]))
        );
        $installed["all_slugs_map"] = array_fill_keys(
            $installed["all_slugs"],
            true
        );
        $installed["all_dirs_map"] = array_fill_keys(
            $installed["all_dirs"],
            true
        );

        $canonical = [];

        foreach ($installed["all_slugs"] as $slug) {
            $canonical[] = self::canonical_separators($slug);
        }

        foreach ($installed["all_dirs"] as $dir) {
            $canonical[] = self::canonical_separators($dir);
        }

        $installed["all_canonical"] = array_values(
            array_filter(array_unique($canonical))
        );

        self::$installed_status_cache = $installed;

        return self::$installed_status_cache;
    }

    private function get_mu_plugin_slugs(): array
    {
        static $slugs = null;

        if ($slugs !== null) {
            return $slugs;
        }

        $slugs = [];

        if (!defined("WPMU_PLUGIN_DIR") || !@is_dir(WPMU_PLUGIN_DIR)) {
            return $slugs;
        }

        $files = glob(WPMU_PLUGIN_DIR . "/*.php");

        if (!is_array($files)) {
            return $slugs;
        }

        foreach ($files as $path) {
            $slug = strtolower(sanitize_key(basename($path, ".php")));

            if ($slug !== "") {
                $slugs[] = $slug;
            }
        }

        $slugs = array_values(array_unique($slugs));

        return $slugs;
    }
    private static function canonical_separators(string $value): string
    {
        return trim(str_replace("_", "-", strtolower($value)), "-");
    }
    private function get_mu_plugin_directories(): array
    {
        static $directories = null;

        if ($directories !== null) {
            return $directories;
        }

        $directories = [];

        if (!defined("WPMU_PLUGIN_DIR") || !@is_dir(WPMU_PLUGIN_DIR)) {
            return $directories;
        }

        $handle = @opendir(WPMU_PLUGIN_DIR);

        if (!$handle) {
            return $directories;
        }

        $base = trailingslashit(WPMU_PLUGIN_DIR);

        while (($item = readdir($handle)) !== false) {
            if ($item === "." || $item === "..") {
                continue;
            }

            if (!@is_dir($base . $item)) {
                continue;
            }

            $directories[] = strtolower($item);
        }

        closedir($handle);

        $directories = array_values(array_unique($directories));

        return $directories;
    }
    private function build_slug_cache_key(?string $slug, array $slugs): string
    {
        $parts = [];

        foreach ($slugs as $value) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return ($slug ?? "") . "\x00" . implode("\x00", $parts);
    }

    private function is_plugin_active(
        ?string $slug,
        array $plugin_slugs,
        array $active_slugs_cache
    ): bool {
        $cache_key = $this->build_slug_cache_key($slug, $plugin_slugs);

        if (isset($this->active_check_cache[$cache_key])) {
            return $this->active_check_cache[$cache_key];
        }

        $result = $this->slug_set_matches(
            $plugin_slugs,
            $active_slugs_cache["all_map"],
            $active_slugs_cache["all"]
        );

        $this->active_check_cache[$cache_key] = $result;

        return $result;
    }

    private function is_plugin_installed(
        ?string $slug,
        array $plugin_slugs,
        array $installed_slugs_cache,
        ?array $installed_slugs_map = null
    ): bool {
        $cache_key = $this->build_slug_cache_key($slug, $plugin_slugs);

        if (isset($this->installed_check_cache[$cache_key])) {
            return $this->installed_check_cache[$cache_key];
        }

        if ($installed_slugs_map === null) {
            $installed_slugs_map = array_fill_keys($installed_slugs_cache, true);
        }

        $result = $this->slug_set_matches(
            $plugin_slugs,
            $installed_slugs_map,
            $installed_slugs_cache
        );

        $this->installed_check_cache[$cache_key] = $result;

        return $result;
    }
    private function slug_set_matches(
        array $plugin_slugs,
        array $haystack_map,
        array $haystack_list
    ): bool {
        foreach ($plugin_slugs as $check_slug) {
            if (!is_scalar($check_slug)) {
                continue;
            }

            $check_slug = (string) $check_slug;
            $check_slug_sanitized = strtolower(sanitize_key($check_slug));

            if (
                $check_slug_sanitized !== "" &&
                isset($haystack_map[$check_slug_sanitized])
            ) {
                return true;
            }

            if (strpos($check_slug, "*") === false) {
                continue;
            }

            $pattern = $this->build_wildcard_pattern($check_slug);

            if ($pattern === "") {
                continue;
            }

            foreach ($haystack_list as $candidate) {
                if (preg_match($pattern, (string) $candidate) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
    private function build_wildcard_pattern(string $slug): string
    {
        static $cache = [];

        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        $normalized = preg_replace(
            '/[^a-z0-9_\-*]/',
            "",
            strtolower($slug)
        );

        if (!is_string($normalized) || strpos($normalized, "*") === false) {
            $cache[$slug] = "";

            return "";
        }

        $literal = str_replace("*", "", $normalized);

        if (strlen($literal) < 3) {
            $cache[$slug] = "";

            return "";
        }

        $cache[$slug] =
            "/^" .
            str_replace("\\*", ".*", preg_quote($normalized, "/")) .
            '$/';

        return $cache[$slug];
    }
    public function is_item_active_or_installed(array $item): bool
    {
        $slugs = [];

        foreach ((array) ($item["slugs"] ?? []) as $slug) {
            if (is_scalar($slug) && (string) $slug !== "") {
                $slugs[] = (string) $slug;
            }
        }

        if (empty($slugs)) {
            return false;
        }

        $primary_slug = (string) reset($slugs);

        if (
            $this->is_plugin_active(
                $primary_slug,
                $slugs,
                $this->get_active_status_cache()
            )
        ) {
            return true;
        }

        $installed_cache = $this->get_installed_status_cache();

        return $this->is_plugin_installed(
            $primary_slug,
            $slugs,
            $installed_cache["all_slugs"],
            $installed_cache["all_slugs_map"]
        );
    }
    private function belongs_to_any_installed_item(string $item_name): bool
    {
        $installed_cache = $this->get_installed_status_cache();

        $item_lower = strtolower($item_name);
        $item_slug = strtolower(sanitize_key($item_name));

        if (
            isset($installed_cache["all_dirs_map"][$item_lower]) ||
            ($item_slug !== "" &&
                isset($installed_cache["all_slugs_map"][$item_slug]))
        ) {
            return true;
        }

        $length = strlen($item_lower);

        for ($index = 3; $index < $length; $index++) {
            $character = $item_lower[$index];

            if ($character !== "_" && $character !== "-") {
                continue;
            }

            if (
                isset(
                    $installed_cache["all_slugs_map"][
                        substr($item_lower, 0, $index)
                    ]
                )
            ) {
                return true;
            }
        }

        return false;
    }
    private function is_core_key(string $key): bool
    {
        static $core_cache = [];

        if (isset($core_cache[$key])) {
            return $core_cache[$key];
        }

        $is_core = false;

        foreach (self::CORE_KEY_PREFIXES as $prefix) {
            if ($key === $prefix || strpos($key, $prefix) === 0) {
                $is_core = true;
                break;
            }
        }

        if (
            !$is_core &&
            (strpos($key, "_transient_") === 0 ||
                strpos($key, "_site_transient_") === 0)
        ) {
            $is_core = true;
        }

        $core_cache[$key] = $is_core;

        return $is_core;
    }

    private function get_formatted_label(array $data): string
    {
        $name = $data["name"] ?? __("Unknown", "optistate");
        $type_label =
            ($data["type"] ?? "plugin") === "theme"
                ? __("Theme", "optistate")
                : __("Plugin", "optistate");

        return sprintf(
            __("Legacy: %1\$s (%2\$s)", "optistate"),
            $name,
            $type_label
        );
    }

    private function build_risk_note(array $data, string $context): string
    {
        return sprintf(
            __("%1\$s from uninstalled %2\$s: %3\$s", "optistate"),
            $context,
            $data["type"] ?? "plugin",
            $data["name"] ?? __("Unknown", "optistate")
        );
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
        $installed_slugs = $installed_cache["all_slugs"];
        $installed_map = $installed_cache["all_slugs_map"];

        $is_folder = $item_type === "folder";

        if ($item_slug !== "" && isset(self::$slug_lookup_cache[$item_slug])) {
            foreach (self::$slug_lookup_cache[$item_slug] as $match) {
                $plugin_slugs = $match["data"]["slugs"];

                $is_installed = $this->is_plugin_installed(
                    $item_slug,
                    $plugin_slugs,
                    $installed_slugs,
                    $installed_map
                );

                if ($is_folder) {
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
                    $is_installed ||
                    $this->is_plugin_active(
                        $item_slug,
                        $plugin_slugs,
                        $active_cache
                    )
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

        if ($is_folder) {
            if (strlen($item_lower) < self::MIN_FOLDER_SLUG_LENGTH) {
                return null;
            }

            $best = null;

            foreach ($this->folder_slug_candidates($item_lower) as $candidate) {
                if (
                    strlen($candidate) < self::MIN_FOLDER_SLUG_LENGTH ||
                    !isset(self::$slug_lookup_cache[$candidate])
                ) {
                    continue;
                }

                foreach (self::$slug_lookup_cache[$candidate] as $match) {
                    if (
                        $this->is_plugin_installed(
                            $item_slug,
                            $match["data"]["slugs"],
                            $installed_slugs,
                            $installed_map
                        )
                    ) {
                        return null;
                    }

                    if ($best === null) {
                        $best = [
                            "prefix" => $match["prefix"],
                            "data" => $match["data"],
                            "match_type" =>
                                $candidate === $item_lower
                                    ? "folder_exact_slug"
                                    : "folder_contains_slug",
                        ];
                    }
                }
            }

            return $best;
        }

        $prefix_match = $this->match_prefix($item_lower);

        if ($prefix_match === null) {
            return null;
        }

        $matched_prefix = $prefix_match["prefix"];
        $info = $prefix_match["info"];
        $plugin_slugs = $info["data"]["slugs"];

        if (
            $this->is_plugin_active($item_slug, $plugin_slugs, $active_cache) ||
            $this->is_plugin_installed(
                $item_slug,
                $plugin_slugs,
                $installed_slugs,
                $installed_map
            )
        ) {
            return null;
        }

        $prefix_canonical = self::canonical_separators($matched_prefix);

        if ($prefix_canonical !== "") {
            $prefix_boundary = $prefix_canonical . "-";

            foreach ($installed_cache["all_canonical"] ?? [] as $installed_slug) {
                if (
                    $installed_slug === $prefix_canonical ||
                    strpos($installed_slug, $prefix_boundary) === 0
                ) {
                    return null;
                }
            }
        }

        return [
            "prefix" => $matched_prefix,
            "data" => $info["data"],
            "match_type" => "prefix",
        ];
    }
    private function folder_slug_candidates(string $item_lower): array
    {
        $parts = preg_split(
            '/([-_.])/',
            $item_lower,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if (!is_array($parts) || empty($parts)) {
            return [];
        }

        $count = min(count($parts), self::MAX_FOLDER_TOKENS * 2 - 1);
        $candidates = [];

        for ($start = 0; $start < $count; $start += 2) {
            $buffer = $parts[$start];

            if ($buffer !== "") {
                $candidates[$buffer] = true;
            }

            for ($end = $start + 2; $end < $count; $end += 2) {
                $buffer .= $parts[$end - 1] . $parts[$end];
                $candidates[$buffer] = true;
            }
        }

        $list = array_keys($candidates);

        usort($list, static function ($a, $b): int {
            $diff = strlen((string) $b) <=> strlen((string) $a);

            return $diff !== 0 ? $diff : strcmp((string) $a, (string) $b);
        });

        return $list;
    }
    public function ajax_scan_legacy_data(): void
    {
        $this->start_scan_clock();

        if (
            !isset($_SERVER["REQUEST_METHOD"]) ||
            $_SERVER["REQUEST_METHOD"] !== "POST"
        ) {
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
            $force_new =
                isset($_POST["new_scan"]) &&
                is_scalar($_POST["new_scan"]) &&
                (string) wp_unslash($_POST["new_scan"]) === "1";

            if ($force_new) {
                $this->invalidate_folder_cache();

                $state = null;
            } else {
                $state = $this->read_scan_state();
            }

            if ($state === null) {
                $this->reset_scan_progress();

                $state = $this->create_scan_state();
            }

            $trash_manager = $this->get_trash_manager();

            if ($trash_manager !== null) {
                $trash_manager->ensure_table_exists();
            }

            if ($this->results_saturated($state)) {
                $this->mark_scan_complete($state);
            } else {
                $this->build_plugin_lookup_tables();

                $conditions = $this->build_pattern_conditions();

                if (!empty($conditions["sql"])) {
                    $this->scan_options($state, $conditions);
                    $this->scan_meta_tables($state, $conditions);
                } else {
                    $state["db"]["options_done"] = true;

                    foreach (self::META_TYPES as $meta_type) {
                        $state["db"]["meta"][$meta_type]["done"] = true;
                    }
                }

                $this->scan_tables($state);
                $this->scan_folders(
                    $state,
                    $this->get_installed_status_cache()
                );
            }

            if ($this->scan_is_complete($state)) {
                $this->clear_scan_state();
            } else {
                $this->write_scan_state($state);
            }

            OPTISTATE_Utils::send_json_success($state["results"]);
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

        $prefixes = [];
        $exact_slugs = [];
        $wildcard_bases = [];

        foreach ($map as $prefix => $data) {
            $trimmed = trim($prefix, "_");

            if ($trimmed !== "" && strlen($trimmed) >= 2) {
                $prefixes[$trimmed] = true;
            }

            foreach ($data["slugs"] as $slug) {
                if (strpos($slug, "*") !== false) {
                    $base = str_replace("*", "", $slug);

                    if ($base !== "") {
                        $wildcard_bases[$base] = true;
                    }

                    continue;
                }

                $exact_slugs[$slug] = true;
            }
        }

        $prefixes = $this->prune_subsumed_prefixes(array_keys($prefixes));

        $sql_parts = [];
        $values = [];

        foreach ($prefixes as $prefix) {
            $sql_parts[] = "{{COL}} LIKE %s";
            $values[] = $wpdb->esc_like($prefix . "_") . "%";
        }

        foreach (array_keys($wildcard_bases) as $base) {
            $sql_parts[] = "{{COL}} LIKE %s";
            $values[] = $wpdb->esc_like($base) . "%";
        }

        $exact_slugs = array_keys($exact_slugs);

        if (!empty($exact_slugs)) {
            $sql_parts[] =
                "{{COL}} IN (" .
                implode(",", array_fill(0, count($exact_slugs), "%s")) .
                ")";

            foreach ($exact_slugs as $slug) {
                $values[] = $slug;
            }
        }

        if (empty($sql_parts)) {
            return ["sql" => "", "values" => []];
        }

        return [
            "sql" => "(" . implode(" OR ", $sql_parts) . ")",
            "values" => $values,
        ];
    }
    private function prune_subsumed_prefixes(array $prefixes): array
    {
        usort($prefixes, static function ($a, $b): int {
            $diff = strlen($a) <=> strlen($b);

            return $diff !== 0 ? $diff : strcmp($a, $b);
        });

        $kept = [];
        $kept_map = [];

        foreach ($prefixes as $prefix) {
            $subsumed = false;
            $length = strlen($prefix);

            for ($index = 2; $index < $length; $index++) {
                if ($prefix[$index] !== "_") {
                    continue;
                }

                if (isset($kept_map[substr($prefix, 0, $index)])) {
                    $subsumed = true;
                    break;
                }
            }

            if (!$subsumed) {
                $kept[] = $prefix;
                $kept_map[$prefix] = true;
            }
        }

        return $kept;
    }
    private function render_conditions(
        array $conditions,
        string $column
    ): string {
        return str_replace("{{COL}}", $column, $conditions["sql"]);
    }

    private function scan_options(array &$state, array $conditions): void
    {
        if (!empty($state["db"]["options_done"])) {
            return;
        }

        if ($this->results_saturated($state)) {
            $state["db"]["options_done"] = true;

            return;
        }

        global $wpdb;

        $where = $this->render_conditions($conditions, "option_name");
        $cursor = (string) $state["db"]["options_cursor"];
        $transient_patterns = [
            $wpdb->esc_like("_transient_") . "%",
            $wpdb->esc_like("_site_transient_") . "%",
        ];

        do {
            if ($this->should_stop_scan()) {
                return;
            }

            $args = $conditions["values"];
            $args[] = $cursor;
            $args[] = $transient_patterns[0];
            $args[] = $transient_patterns[1];
            $args[] = self::DB_BATCH_SIZE;

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, autoload, LENGTH(option_value) AS size
                     FROM {$wpdb->options}
                     WHERE {$where}
                       AND option_name > %s
                       AND option_name NOT LIKE %s
                       AND option_name NOT LIKE %s
                     ORDER BY option_name ASC
                     LIMIT %d",
                    $args
                )
            );

            if ($wpdb->last_error) {
                OPTISTATE_Utils::log_critical_error(
                    "Legacy scanner: options query failed",
                    ["error" => $wpdb->last_error]
                );

                return;
            }

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = (string) $row->option_name;
                $state["db"]["options_cursor"] = $cursor;

                $name = (string) $row->option_name;

                if (
                    $name === "" ||
                    strlen($name) > self::MAX_KEY_LENGTH ||
                    in_array($name, self::PROTECTED_OPTIONS, true) ||
                    $this->is_core_key($name) ||
                    $this->belongs_to_any_installed_item($name)
                ) {
                    continue;
                }

                $source = $this->identify_legacy_source($name, "option");

                if ($source === null) {
                    continue;
                }

                $size = (int) $row->size;

                $this->add_result($state, [
                    "type" => "option",
                    "name" => $name,
                    "count" => size_format($size, 2),
                    "display_type" => "option",
                    "label" => $this->get_formatted_label($source["data"]),
                    "risk" =>
                        $size > self::LARGE_OPTION_BYTES
                            ? "high"
                            : $source["data"]["risk"],
                    "risk_note" => $this->build_risk_note(
                        $source["data"],
                        __("Data", "optistate")
                    ),
                    "autoload" => in_array(
                        (string) $row->autoload,
                        ["yes", "on", "auto-on", "auto"],
                        true
                    ),
                ]);
            }

            $row_count = count($rows);

            unset($rows);
            $wpdb->flush();

            if ($this->results_saturated($state)) {
                break;
            }
        } while ($row_count === self::DB_BATCH_SIZE);

        $state["db"]["options_done"] = true;
    }

    private function scan_meta_tables(array &$state, array $conditions): void
    {
        global $wpdb;

        $where = $this->render_conditions($conditions, "meta_key");

        foreach (self::META_TYPES as $meta_type) {
            if (!isset($state["db"]["meta"][$meta_type])) {
                $state["db"]["meta"][$meta_type] = [
                    "cursor" => "",
                    "done" => false,
                ];
            }

            if (!empty($state["db"]["meta"][$meta_type]["done"])) {
                continue;
            }

            if ($this->results_saturated($state)) {
                $state["db"]["meta"][$meta_type]["done"] = true;

                continue;
            }

            if ($this->should_stop_scan()) {
                return;
            }

            $table = $wpdb->{$meta_type};

            if (empty($table)) {
                $state["db"]["meta"][$meta_type]["done"] = true;

                continue;
            }

            $cursor = (string) $state["db"]["meta"][$meta_type]["cursor"];
            $completed = true;

            do {
                if ($this->should_stop_scan()) {
                    return;
                }

                $args = $conditions["values"];
                $args[] = $cursor;
                $args[] = self::DB_BATCH_SIZE;

                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT DISTINCT meta_key
                         FROM {$table}
                         WHERE {$where}
                           AND meta_key > %s
                         ORDER BY meta_key ASC
                         LIMIT %d",
                        $args
                    )
                );

                if ($wpdb->last_error) {
                    OPTISTATE_Utils::log_critical_error(
                        "Legacy scanner: {$meta_type} query failed",
                        ["error" => $wpdb->last_error]
                    );

                    $completed = false;

                    break;
                }

                if (empty($rows)) {
                    break;
                }

                $batch_matches = [];

                foreach ($rows as $row) {
                    $key = (string) $row->meta_key;
                    $cursor = $key;
                    $state["db"]["meta"][$meta_type]["cursor"] = $cursor;

                    if (
                        $key === "" ||
                        strlen($key) > self::MAX_KEY_LENGTH ||
                        $this->is_core_key($key) ||
                        $this->belongs_to_any_installed_item($key)
                    ) {
                        continue;
                    }

                    if (isset($state["seen"][$meta_type . ":" . $key])) {
                        continue;
                    }

                    $source = $this->identify_legacy_source($key, "meta");

                    if ($source !== null) {
                        $batch_matches[$key] = $source;
                    }
                }

                $row_count = count($rows);

                unset($rows);

                if (!empty($batch_matches)) {
                    $this->record_meta_matches(
                        $state,
                        $meta_type,
                        $table,
                        $batch_matches
                    );
                }

                $wpdb->flush();

                if ($this->results_saturated($state)) {
                    break;
                }
            } while ($row_count === self::DB_BATCH_SIZE);

            if ($completed) {
                $state["db"]["meta"][$meta_type]["done"] = true;
            }
        }
    }
    private function record_meta_matches(
        array &$state,
        string $meta_type,
        string $table,
        array $batch_matches
    ): void {
        global $wpdb;

        $keys = array_keys($batch_matches);

        $counts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, COUNT(*) AS cnt
                 FROM {$table}
                 WHERE meta_key IN (" .
                    implode(",", array_fill(0, count($keys), "%s")) .
                    ")
                 GROUP BY meta_key",
                $keys
            ),
            OBJECT_K
        );

        if ($wpdb->last_error) {
            OPTISTATE_Utils::log_critical_error(
                "Legacy scanner: {$meta_type} count query failed",
                ["error" => $wpdb->last_error]
            );

            $counts = [];
        }

        foreach ($batch_matches as $key => $source) {
            $count = isset($counts[$key]) ? (int) $counts[$key]->cnt : 0;

            $this->add_result($state, [
                "type" => $meta_type,
                "name" => $key,
                "count" => sprintf(
                    __("%s rows", "optistate"),
                    number_format_i18n($count)
                ),
                "display_type" => $meta_type,
                "label" => $this->get_formatted_label($source["data"]),
                "risk" => $source["data"]["risk"],
                "risk_note" => $this->build_risk_note(
                    $source["data"],
                    __("Data", "optistate")
                ),
            ]);
        }
    }

    private function scan_tables(array &$state): void
    {
        if (!empty($state["db"]["tables_done"])) {
            return;
        }

        if ($this->results_saturated($state)) {
            $state["db"]["tables_done"] = true;

            return;
        }

        global $wpdb;

        $all_tables = OPTISTATE_Utils::get_all_tables();

        if (empty($all_tables)) {
            $state["db"]["tables_done"] = true;

            return;
        }
        sort($all_tables, SORT_STRING);

        $cursor = (string) $state["db"]["tables_cursor"];
        $db_prefix = $wpdb->prefix;

        foreach ($all_tables as $table) {
            if ($cursor !== "" && strcmp($table, $cursor) <= 0) {
                continue;
            }

            if ($this->should_stop_scan()) {
                return;
            }

            $state["db"]["tables_cursor"] = $table;
            $cursor = $table;

            if (
                strpos($table, $db_prefix) !== 0 ||
                !preg_match('/^[A-Za-z0-9_]+$/', $table)
            ) {
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

            $source = $this->identify_legacy_source($clean_table, "table");

            if ($source === null) {
                continue;
            }

            $row_count = 0;
            $update_time = "";
            $table_info = $this->get_table_metadata();

            if (isset($table_info[$table])) {
                $update_time = (string) ($table_info[$table]->UPDATE_TIME ?? "");
                $row_count = (int) ($table_info[$table]->TABLE_ROWS ?? 0);
            } else {
                $row_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM " .
                        OPTISTATE_Utils::escape_identifier($table)
                );

                if ($wpdb->last_error) {
                    OPTISTATE_Utils::log_critical_error(
                        "Legacy scanner: row count failed for table",
                        ["table" => $table, "error" => $wpdb->last_error]
                    );

                    $row_count = 0;
                }
            }

            $timestamp = $update_time !== "" ? strtotime($update_time) : false;

            $this->add_result($state, [
                "type" => "table",
                "name" => $table,
                "count" => sprintf(
                    __("%s rows", "optistate"),
                    number_format_i18n($row_count)
                ),
                "display_type" => "table",
                "label" => $this->get_formatted_label($source["data"]),
                "risk" => "high",
                "risk_note" => $this->build_risk_note(
                    $source["data"],
                    __("Table", "optistate")
                ),
                "last_accessed_date" => $timestamp
                    ? date_i18n("j M Y", $timestamp)
                    : "",
            ]);

            if ($this->results_saturated($state)) {
                break;
            }
        }

        $state["db"]["tables_done"] = true;
    }
    private function get_table_metadata(): array
    {
        static $metadata = null;

        if ($metadata !== null) {
            return $metadata;
        }

        global $wpdb;

        $rows = OPTISTATE_Utils::with_stats_expiry_disabled(static function () use (
            $wpdb
        ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME, UPDATE_TIME, TABLE_ROWS
                     FROM information_schema.tables
                     WHERE table_schema = %s",
                    DB_NAME
                ),
                OBJECT_K
            );
        });

        if ($wpdb->last_error) {
            OPTISTATE_Utils::log_critical_error(
                "Legacy scanner: information_schema query failed",
                ["error" => $wpdb->last_error]
            );

            $rows = [];
        }

        $metadata = is_array($rows) ? $rows : [];

        return $metadata;
    }
    private function collect_candidate_directories(array $installed_cache): array
    {
        $upload_dir = wp_get_upload_dir();

        $base_upload_path = $upload_dir["basedir"] ?? "";
        $wp_plugin_dir = defined("WP_PLUGIN_DIR") ? WP_PLUGIN_DIR : "";
        $wp_theme_dir = get_theme_root();
        $wp_mu_plugin_dir = defined("WPMU_PLUGIN_DIR") ? WPMU_PLUGIN_DIR : "";
        $wp_content_dir = defined("WP_CONTENT_DIR") ? WP_CONTENT_DIR : "";

        $candidates = [
            "uploads" => $base_upload_path,
            "plugins" => $wp_plugin_dir,
            "themes" => $wp_theme_dir,
            "mu-plugins" => $wp_mu_plugin_dir,
            "wp-content" => $wp_content_dir,
        ];

        $scan_locations = [];

        foreach ($candidates as $name => $path) {
            if (is_string($path) && $path !== "" && $this->safe_is_dir($path)) {
                $scan_locations[$name] = $path;
            }
        }

        $roots = [];

        foreach (
            [
                $wp_plugin_dir,
                $wp_theme_dir,
                $wp_mu_plugin_dir,
                $base_upload_path,
                $wp_content_dir,
            ]
            as $root
        ) {
            if (is_string($root) && $root !== "") {
                $roots[untrailingslashit(wp_normalize_path($root))] = true;
            }
        }

        $max_folders = (int) apply_filters(
            "optistate_max_folders_scan",
            self::DEFAULT_MAX_FOLDERS_SCANNED
        );
        $max_folders = max(1, $max_folders);

        $found = [];

        foreach ($scan_locations as $location_name => $location_path) {
            $handle = @opendir($location_path);

            if (!$handle) {
                continue;
            }

            $inspected = 0;

            while (($item = readdir($handle)) !== false) {
                if ($item === "." || $item === "..") {
                    continue;
                }

                if ($inspected++ >= $max_folders) {
                    break;
                }

                $item_lower = strtolower($item);

                if (
                    ctype_digit($item) ||
                    in_array($item_lower, self::SKIP_FOLDERS, true) ||
                    in_array($item_lower, self::SYSTEM_DIRECTORIES, true) ||
                    isset($installed_cache["all_dirs_map"][$item_lower])
                ) {
                    continue;
                }

                $full_path = trailingslashit($location_path) . $item;

                if (!$this->safe_is_dir($full_path)) {
                    continue;
                }

                if (isset($roots[untrailingslashit(wp_normalize_path($full_path))])) {
                    continue;
                }

                $found[] = [
                    "name" => $item,
                    "path" => $full_path,
                    "location" => $location_name,
                ];
            }

            closedir($handle);
        }

        return $found;
    }
    private function get_candidate_directories(
        array &$state,
        array $installed_cache
    ): array {
        $signature = md5(
            (string) $this->get_scan_version() .
                "|" .
                implode(",", $installed_cache["all_dirs"])
        );

        $cache_key =
            self::DIR_INDEX_KEY_PREFIX .
            "_" .
            $this->get_scan_version() .
            "_" .
            $signature;

        $directories = OPTISTATE_Utils::get_or_set_transient(
            $cache_key,
            function () use ($installed_cache): array {
                return $this->collect_candidate_directories($installed_cache);
            },
            self::DIR_INDEX_TTL
        );
        if (!is_array($directories)) {
            $directories = [];
        }

        $stored_key = (string) $state["folders"]["key"];
        $stored_count = (int) $state["folders"]["count"];

        if (
            $stored_key !== $cache_key ||
            $stored_count !== count($directories)
        ) {
            $state["folders"]["key"] = $cache_key;
            $state["folders"]["count"] = count($directories);
            $state["folders"]["index"] = 0;
        }

        return $directories;
    }

    private function scan_folders(array &$state, array $installed_cache): void
    {
        if (!empty($state["folders"]["done"])) {
            return;
        }

        if ($this->folders_saturated($state)) {
            $state["folders"]["done"] = true;

            return;
        }

        $directories = $this->get_candidate_directories(
            $state,
            $installed_cache
        );

        $total = count($directories);

        if ($total === 0) {
            $state["folders"]["done"] = true;

            return;
        }

        $index = (int) $state["folders"]["index"];

        for (; $index < $total; $index++) {
            if ($this->should_stop_scan()) {
                break;
            }

            $this->inspect_directory(
                $state,
                $directories[$index],
                $installed_cache
            );

            if ($this->folders_saturated($state)) {
                $index = $total;

                break;
            }
        }

        $state["folders"]["index"] = min($index, $total);

        if ($index >= $total) {
            $state["folders"]["done"] = true;
        }
    }

    private function inspect_directory(
        array &$state,
        array $dir_info,
        array $installed_cache
    ): void {
        $folder_path = (string) $dir_info["path"];

        if (!@is_dir($folder_path)) {
            return;
        }

        $folder_name = (string) $dir_info["name"];
        $folder_lower = strtolower($folder_name);
        $location_name = (string) $dir_info["location"];
        $is_upload = $location_name === "uploads";
        $is_theme_dir = $location_name === "themes";
        $is_mu_plugin_dir = $location_name === "mu-plugins";
        $is_plugin_dir = $location_name === "plugins" || $is_mu_plugin_dir;

        if (isset($installed_cache["all_dirs_map"][$folder_lower])) {
            if ($is_plugin_dir || $is_theme_dir || !$is_upload) {
                return;
            }
        }

        $source = $this->identify_legacy_source($folder_name, "folder");

        if ($source !== null) {
            if (
                $this->is_plugin_installed(
                    $folder_name,
                    $source["data"]["slugs"],
                    $installed_cache["all_slugs"],
                    $installed_cache["all_slugs_map"]
                )
            ) {
                return;
            }

            $stats = $this->folder_stats($dir_info["path"]);

            $size = (int) ($stats["size"] ?? 0);
            $risk = (string) $source["data"]["risk"];
            $note = $this->build_risk_note(
                $source["data"],
                __("Orphaned folder", "optistate")
            );

            if ($is_plugin_dir) {
                $risk = "high";
                $note .= " " .
                    __(
                        "(leftover in plugins directory - security risk)",
                        "optistate"
                    );
            } elseif ($is_theme_dir) {
                $risk = "high";
                $note .= " " . __("(leftover in themes directory)", "optistate");
            } elseif ($is_upload && $size > self::LARGE_UPLOAD_FOLDER_BYTES) {
                $risk = "high";
                $note .= " " .
                    sprintf(
                        __("(large upload folder: %s)", "optistate"),
                        size_format($size, 1)
                    );
            }

            if (!empty($stats["sensitive"])) {
                $risk = "critical";
                $note .= " " . __("- contains sensitive files", "optistate");
            }

            $this->add_folder_result($state, $dir_info, $size, [
                "label" => $this->get_formatted_label($source["data"]),
                "risk" => $risk,
                "risk_note" => $note,
            ]);

            return;
        }

        if ($location_name === "plugins" || $is_theme_dir) {
            $stats = $this->folder_stats($dir_info["path"]);
            $risk = "high";
            $note = sprintf(
                __("Orphaned folder in %s directory", "optistate"),
                $location_name
            );

            if (!empty($stats["sensitive"])) {
                $risk = "critical";
                $note .= " " . __("- contains sensitive files", "optistate");
            }

            $this->add_folder_result(
                $state,
                $dir_info,
                (int) ($stats["size"] ?? 0),
                [
                    "label" => __(
                        "Legacy: Unknown Plugin/Theme",
                        "optistate"
                    ),
                    "risk" => $risk,
                    "risk_note" => $note,
                ]
            );

            return;
        }

        if (!$is_upload) {
            return;
        }

        $known_upload_folders = [
            "avatars" => [
                "buddypress",
                "simple-local-avatars",
                "wp-user-avatar",
                "one-user-avatar",
                "basic-user-avatars",
            ],
            "wpforms" => ["wpforms", "wpforms-lite"],
            "elementor" => ["elementor", "elementor-pro"],
            "woocommerce" => ["woocommerce"],
            "woocommerce_uploads" => ["woocommerce"],
            "wpcf7_uploads" => ["contact-form-7"],
        ];

        if (!isset($known_upload_folders[$folder_lower])) {
            return;
        }

        $plugin_slugs = $known_upload_folders[$folder_lower];

        if (
            $this->is_plugin_installed(
                (string) reset($plugin_slugs),
                $plugin_slugs,
                $installed_cache["all_slugs"],
                $installed_cache["all_slugs_map"]
            )
        ) {
            return;
        }

        $stats = $this->folder_stats($dir_info["path"]);
        $risk = "medium";
        $note = __(
            "Orphaned upload folder from uninstalled plugin",
            "optistate"
        );

        if (!empty($stats["sensitive"])) {
            $risk = "critical";
            $note .= " " . __("- contains sensitive files", "optistate");
        }

        $this->add_folder_result(
            $state,
            $dir_info,
            (int) ($stats["size"] ?? 0),
            [
                "label" => sprintf(
                    __("Legacy: %s", "optistate"),
                    ucfirst(str_replace("_", " ", $folder_lower))
                ),
                "risk" => $risk,
                "risk_note" => $note,
            ]
        );
    }
    private function folder_stats(string $path): array
    {
        $stats = OPTISTATE_Utils::get_folder_size(
            $path,
            self::FOLDER_STAT_MAX_FILES,
            self::FOLDER_STAT_MAX_DEPTH,
            true,
            [$this, "should_stop_scan"]
        );

        return is_array($stats)
            ? $stats
            : ["size" => 0, "sensitive" => false, "file_count" => 0];
    }

    private function add_folder_result(
        array &$state,
        array $dir_info,
        int $size,
        array $descriptor
    ): void {
        $last_modified = @filemtime($dir_info["path"]);
        $identifier = $this->make_path_identifier((string) $dir_info["path"]);

        $this->add_result($state, [
            "type" => "folder",
            "name" => $identifier,
            "path" => $dir_info["path"],
            "relative_path" => $identifier,
            "location" => $dir_info["location"],
            "count" => $size > 0 ? size_format($size, 1) : "0 B",
            "display_type" => "folder",
            "label" => $descriptor["label"],
            "risk" => $descriptor["risk"],
            "risk_note" => $descriptor["risk_note"],
            "days_old" => $last_modified
                ? (int) floor((time() - $last_modified) / DAY_IN_SECONDS)
                : 0,
            "last_accessed_date" => $last_modified
                ? date_i18n("j M Y", $last_modified)
                : "",
        ]);
    }
    private function make_path_identifier(string $absolute_path): string
    {
        $absolute = wp_normalize_path($absolute_path);
        $root = trailingslashit(wp_normalize_path(ABSPATH));

        if (strpos($absolute, $root) === 0) {
            return substr($absolute, strlen($root));
        }

        return $absolute;
    }

    private function is_absolute_path(string $path): bool
    {
        if ($path === "") {
            return false;
        }

        return $path[0] === "/" || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    private function resolve_path_identifier(string $identifier): string
    {
        $identifier = wp_normalize_path($identifier);

        if ($this->is_absolute_path($identifier)) {
            return $identifier;
        }

        return wp_normalize_path(
            trailingslashit(ABSPATH) . ltrim($identifier, "/")
        );
    }
    private function path_has_traversal(string $path): bool
    {
        foreach (explode("/", wp_normalize_path($path)) as $segment) {
            if ($segment === "..") {
                return true;
            }
        }

        return false;
    }
    private function get_trash_manager(): ?OPTISTATE_Trash_Manager
    {
        $manager = $this->main_plugin->trash_manager;

        return $manager instanceof OPTISTATE_Trash_Manager ? $manager : null;
    }

    private function trash_unavailable_error(): void
    {
        OPTISTATE_Utils::send_json_error(
            __(
                "The trash service is unavailable. Please check the plugin log for details.",
                "optistate"
            ),
            500
        );
    }

    private function read_post_identifier(
        string $field,
        int $max_length
    ): string {
        if (!isset($_POST[$field]) || !is_scalar($_POST[$field])) {
            return "";
        }

        $value = wp_check_invalid_utf8((string) wp_unslash($_POST[$field]));

        if ($value === "") {
            return "";
        }

        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', "", $value);

        if ($value === "" || strlen($value) > $max_length) {
            return "";
        }

        return $value;
    }
    private function forget_result(string $type, string $name): void
    {
        $state = $this->read_scan_state();

        if ($state === null) {
            return;
        }

        $changed = false;

        foreach ($state["results"] as $index => $result) {
            if (
                ($result["type"] ?? "") !== $type ||
                ($result["name"] ?? "") !== $name
            ) {
                continue;
            }

            if ($type === "folder" && (int) $state["folder_count"] > 0) {
                $state["folder_count"]--;
            }

            unset($state["results"][$index]);

            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $state["results"] = array_values($state["results"]);

        $this->write_scan_state($state);
    }

    public function ajax_delete_legacy_data(): void
    {
        if (
            !isset($_SERVER["REQUEST_METHOD"]) ||
            $_SERVER["REQUEST_METHOD"] !== "POST"
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid request method.", "optistate"),
                405
            );

            return;
        }

        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("legacy_delete", 1)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );

            return;
        }

        $trash_manager = $this->get_trash_manager();

        if ($trash_manager === null) {
            $this->trash_unavailable_error();

            return;
        }

        $type = isset($_POST["type"]) && is_scalar($_POST["type"])
            ? sanitize_key((string) $_POST["type"])
            : "";

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

        if ($trash_type === "folder") {
            $max_length = self::MAX_PATH_LENGTH;
        } elseif ($trash_type === "table") {
            $max_length = self::MAX_TABLE_NAME_LENGTH;
        } elseif ($trash_type === "option") {
            $max_length = self::MAX_OPTION_NAME_LENGTH;
        } else {
            $max_length = self::MAX_META_KEY_LENGTH;
        }

        $name = $this->read_post_identifier("name", $max_length);

        if ($name === "") {
            OPTISTATE_Utils::send_json_error(
                __("Invalid data identifier.", "optistate")
            );

            return;
        }

        if ($trash_type === "folder") {
            $this->delete_legacy_folder($trash_manager, $name);

            return;
        }

        if (
            $trash_type === "option" &&
            in_array($name, self::PROTECTED_OPTIONS, true)
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Cannot delete core WordPress options.", "optistate")
            );

            return;
        }
        if (
            in_array($trash_type, self::META_TYPES, true) &&
            $this->is_core_key($name)
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Cannot delete core WordPress metadata.", "optistate")
            );

            return;
        }

        if ($this->belongs_to_any_installed_item($name)) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Cannot delete data from an installed plugin or theme.",
                    "optistate"
                ),
                403
            );

            return;
        }

        if ($trash_type === "table" && !$this->validate_table_target($name)) {
            return;
        }

        $trash_key = $trash_manager->move_to_trash($trash_type, $name);

        if ($trash_key === false) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Failed to move item to trash. Please try again.",
                    "optistate"
                )
            );

            return;
        }

        $this->forget_result($type, $name);

        if ($trash_type !== $type) {
            $this->forget_result($trash_type, $name);
        }

        OPTISTATE_Utils::send_json_success([
            "count" => 1,
            "message" => sprintf(
                __(
                    "%s moved to trash. You can restore it within 14 days.",
                    "optistate"
                ),
                ucfirst(str_replace("_", " ", $trash_type))
            ),
            "verified" => true,
        ]);
    }
    private function validate_table_target(string $name): bool
    {
        global $wpdb;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            OPTISTATE_Utils::send_json_error(
                __("Invalid table name.", "optistate")
            );

            return false;
        }

        $stripped =
            strpos($name, $wpdb->prefix) === 0
                ? substr($name, strlen($wpdb->prefix))
                : $name;

        if (
            in_array($stripped, self::PROTECTED_TABLES, true) ||
            OPTISTATE_Utils::is_core_table($name)
        ) {
            OPTISTATE_Utils::send_json_error(
                __("Cannot delete core WordPress tables.", "optistate")
            );

            return false;
        }

        if (!OPTISTATE_Utils::table_exists($name)) {
            OPTISTATE_Utils::send_json_error(
                __("Table does not exist.", "optistate")
            );

            return false;
        }

        return true;
    }
    private function delete_legacy_folder(
        OPTISTATE_Trash_Manager $trash_manager,
        string $name
    ): void {
        if ($this->path_has_traversal($name)) {
            OPTISTATE_Utils::send_json_error(
                __("Security Error: Directory traversal detected.", "optistate"),
                403
            );

            return;
        }

        $upload_dir = wp_get_upload_dir();

        $valid_base_paths = array_filter([
            $this->real_normalized_path($upload_dir["basedir"] ?? ""),
            $this->real_normalized_path(
                defined("WP_CONTENT_DIR") ? WP_CONTENT_DIR : ""
            ),
            $this->real_normalized_path(
                defined("WP_PLUGIN_DIR") ? WP_PLUGIN_DIR : ""
            ),
            $this->real_normalized_path(get_theme_root()),
            $this->real_normalized_path(
                defined("WPMU_PLUGIN_DIR") ? WPMU_PLUGIN_DIR : ""
            ),
        ]);

        if (empty($valid_base_paths)) {
            OPTISTATE_Utils::send_json_error(
                __("Could not determine valid base directories.", "optistate"),
                500
            );

            return;
        }

        $real_target = $this->real_normalized_path(
            $this->resolve_path_identifier($name)
        );

        if ($real_target === "" || !@is_dir($real_target)) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Directory does not exist or is not accessible.",
                    "optistate"
                ),
                404
            );

            return;
        }

        $target_slashed = trailingslashit($real_target);
        $is_valid = false;

        foreach ($valid_base_paths as $base_path) {
            $base_slashed = trailingslashit($base_path);
            if (
                $target_slashed !== $base_slashed &&
                strpos($target_slashed, $base_slashed) === 0
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

        if (in_array($basename, self::PROTECTED_DIRECTORIES, true)) {
            OPTISTATE_Utils::send_json_error(
                __("Cannot delete WordPress core directories.", "optistate"),
                403
            );

            return;
        }

        $installed_cache = $this->get_installed_status_cache();

        if (isset($installed_cache["all_dirs_map"][strtolower($basename)])) {
            OPTISTATE_Utils::send_json_error(
                __(
                    "Cannot delete an installed plugin or theme folder.",
                    "optistate"
                ),
                403
            );

            return;
        }

        $trash_key = $trash_manager->move_to_trash("folder", $real_target);

        if ($trash_key === false) {
            OPTISTATE_Utils::send_json_error(
                __("Failed to move folder to trash.", "optistate")
            );

            return;
        }

        $this->forget_result("folder", $name);

        $identifier = $this->make_path_identifier($real_target);

        if ($identifier !== $name) {
            $this->forget_result("folder", $identifier);
        }

        OPTISTATE_Utils::send_json_success([
            "count" => 1,
            "message" => __(
                "Folder moved to trash. You can restore it within 14 days.",
                "optistate"
            ),
            "verified" => true,
        ]);
    }

    private function real_normalized_path(string $path): string
    {
        if ($path === "") {
            return "";
        }

        $real = @realpath($path);

        return $real === false ? "" : wp_normalize_path($real);
    }
}