<?php if (!defined("ABSPATH")) {
    exit();
}
class OPTISTATE_Cleanup_Functions
{
    private OPTISTATE $main_plugin;
    private array $last_affected_ids = [
        "post" => [],
        "comment" => [],
        "term" => [],
        "user" => [],
    ];
    public function __construct(OPTISTATE $main_plugin)
    {
        $this->main_plugin = $main_plugin;
        add_action("wp_ajax_optistate_clean_item", [$this, "ajax_clean_item"]);
        add_action("wp_ajax_optistate_one_click_optimize", [
            $this,
            "ajax_one_click_optimize",
        ]);
    }
    private function is_time_exceeded(int $start_time, int $time_limit): bool
    {
        return time() - $start_time >= $time_limit;
    }
    private function get_time_limit(): int
    {
        $max_exec = (int) ini_get("max_execution_time");
        if ($max_exec <= 0) {
            $max_exec =
                (defined("WP_CLI") && WP_CLI) || wp_doing_cron() ? 300 : 30;
        }
        return max(5, $max_exec - 10);
    }
    private function set_last_affected_ids(string $type, array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $this->last_affected_ids[$type] = array_merge(
            $this->last_affected_ids[$type],
            $ids
        );
    }
    private function get_last_affected_ids(string $type): array
    {
        $ids = $this->last_affected_ids[$type];
        $this->last_affected_ids[$type] = [];
        return $ids;
    }
    private function set_last_affected_post_ids(array $ids): void
    {
        $this->set_last_affected_ids("post", $ids);
    }
    private function get_last_affected_post_ids(): array
    {
        return $this->get_last_affected_ids("post");
    }
    private function set_last_affected_comment_ids(array $ids): void
    {
        $this->set_last_affected_ids("comment", $ids);
    }
    private function get_last_affected_comment_ids(): array
    {
        return $this->get_last_affected_ids("comment");
    }
    private function set_last_affected_term_ids(array $ids): void
    {
        $this->set_last_affected_ids("term", $ids);
    }
    private function get_last_affected_term_ids(): array
    {
        return $this->get_last_affected_ids("term");
    }
    private function set_last_affected_user_ids(array $ids): void
    {
        $this->set_last_affected_ids("user", $ids);
    }
    private function get_last_affected_user_ids(): array
    {
        return $this->get_last_affected_ids("user");
    }
    public static function get_all_cleanup_items(): array
    {
        static $items = null;
        if ($items !== null) {
            return $items;
        }
        $default_keys = [
            "post_revisions",
            "auto_drafts",
            "trashed_comments",
            "orphaned_postmeta",
            "orphaned_commentmeta",
            "orphaned_relationships",
            "expired_transients",
            "duplicate_postmeta",
            "duplicate_commentmeta",
            "orphaned_usermeta",
            "orphaned_termmeta",
            "pingbacks",
            "trackbacks",
        ];
        $default_flip = array_flip($default_keys);
        $all = [
            "post_revisions" => __("Post Revisions", "optistate"),
            "auto_drafts" => __("Auto Drafts", "optistate"),
            "trashed_posts" => __("Trashed Posts", "optistate"),
            "spam_comments" => __("Spam Comments", "optistate"),
            "trashed_comments" => __("Trashed Comments", "optistate"),
            "orphaned_postmeta" => __("Orphaned Post Meta", "optistate"),
            "orphaned_commentmeta" => __("Orphaned Comment Meta", "optistate"),
            "orphaned_relationships" => __(
                "Orphaned Term Relationships",
                "optistate"
            ),
            "expired_transients" => __("Expired Transients", "optistate"),
            "all_transients" => __("All Transients", "optistate"),
            "duplicate_postmeta" => __("Duplicate Post Meta", "optistate"),
            "duplicate_commentmeta" => __(
                "Duplicate Comment Meta",
                "optistate"
            ),
            "duplicate_usermeta" => __("Duplicate User Meta", "optistate"),
            "duplicate_termmeta" => __("Duplicate Term Meta", "optistate"),
            "orphaned_usermeta" => __("Orphaned User Meta", "optistate"),
            "orphaned_termmeta" => __("Orphaned Term Meta", "optistate"),
            "unapproved_comments" => __("Unapproved Comments", "optistate"),
            "pingbacks" => __("Pingbacks", "optistate"),
            "trackbacks" => __("Trackbacks", "optistate"),
            "action_scheduler" => __("Action Logs", "optistate"),
            "oembed_cache" => __("oEmbed Cache", "optistate"),
            "woo_bloat" => __("WooCommerce Sessions/Logs", "optistate"),
            "empty_taxonomies" => __("Empty Taxonomies", "optistate"),
        ];
        $items = [];
        foreach ($all as $key => $label) {
            $items[$key] = [
                "label" => $label,
                "default" => isset($default_flip[$key]),
            ];
        }
        return $items;
    }
    public static function get_default_one_click_operations(): array
    {
        return [
            "post_revisions",
            "auto_drafts",
            "trashed_comments",
            "pingbacks",
            "trackbacks",
            "orphaned_postmeta",
            "orphaned_commentmeta",
            "orphaned_usermeta",
            "orphaned_termmeta",
            "orphaned_relationships",
            "expired_transients",
            "duplicate_postmeta",
            "duplicate_commentmeta",
            "duplicate_usermeta",
            "duplicate_termmeta",
        ];
    }
    public function ajax_clean_item(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        $item_type = isset($_POST["item_type"])
            ? sanitize_key(wp_unslash($_POST["item_type"]))
            : "";
        $cleaned = 0;
        $error_details = [];
        try {
            $cleaned = OPTISTATE_Utils::without_foreign_key_checks(
                function () use ($item_type, &$error_details) {
                    switch ($item_type) {
                        case "post_revisions":
                            return $this->clean_post_revisions();
                        case "auto_drafts":
                            return $this->clean_auto_drafts();
                        case "trashed_posts":
                            return $this->clean_trashed_posts();
                        case "spam_comments":
                            return $this->clean_spam_comments();
                        case "trashed_comments":
                            return $this->clean_trashed_comments();
                        case "unapproved_comments":
                            return $this->clean_unapproved_comments();
                        case "pingbacks":
                            return $this->clean_pingbacks();
                        case "trackbacks":
                            return $this->clean_trackbacks();
                        case "expired_transients":
                            return $this->clean_expired_transients();
                        case "all_transients":
                            return $this->clean_all_transients();
                        case "orphaned_postmeta":
                            return $this->clean_orphaned_postmeta();
                        case "orphaned_commentmeta":
                            return $this->clean_orphaned_commentmeta();
                        case "orphaned_termmeta":
                            return $this->clean_orphaned_termmeta();
                        case "orphaned_relationships":
                            return $this->clean_orphaned_relationships();
                        case "orphaned_usermeta":
                            return $this->clean_orphaned_usermeta();
                        case "duplicate_postmeta":
                            return $this->clean_duplicate_postmeta();
                        case "duplicate_commentmeta":
                            return $this->clean_duplicate_commentmeta();
                        case "duplicate_usermeta":
                            return $this->clean_duplicate_usermeta();
                        case "duplicate_termmeta":
                            return $this->clean_duplicate_termmeta();
                        case "action_scheduler":
                            return $this->clean_action_scheduler();
                        case "oembed_cache":
                            return $this->clean_oembed_cache();
                        case "woo_bloat":
                            return $this->clean_woocommerce_bloat();
                        case "empty_taxonomies":
                            return $this->clean_empty_taxonomies();
                        default:
                            OPTISTATE_Utils::send_json_error(
                                __("Invalid cleanup type", "optistate")
                            );
                            return 0;
                    }
                }
            );
        } catch (Throwable $e) {
            $error_details = [
                "operation" => $item_type,
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "memory_usage" => size_format(memory_get_usage(true), 2),
            ];
            $this->main_plugin->log_entry(
                "❌ " .
                    sprintf(
                        __("Cleanup operation '%s' failed: %s", "optistate"),
                        $item_type,
                        $e->getMessage()
                    ),
                "error",
                "",
                $error_details
            );
            OPTISTATE_Utils::log_critical_error(
                "Cleanup operation failed: " . $e->getMessage(),
                $error_details
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "Cleanup failed due to a database error. Check logs for details.",
                    "optistate"
                )
            );
            return;
        }
        if ($cleaned > 0) {
            $this->main_plugin->log_entry(
                "🧹 " .
                    sprintf(
                        __("Cleaned %s (%s) by {username}", "optistate"),
                        str_replace("_", " ", $item_type),
                        number_format_i18n($cleaned)
                    )
            );
            $this->flush_collected_caches();
        }
        $this->main_plugin->clear_stats_cache();
        OPTISTATE_Utils::invalidate_table_cache();
        OPTISTATE_Utils::send_json_success(["cleaned" => $cleaned]);
    }
    public function ajax_one_click_optimize(): void
    {
        check_ajax_referer(OPTISTATE::NONCE_ACTION, "nonce");
        $this->main_plugin->settings_manager->check_user_access();
        if (!OPTISTATE_Utils::check_rate_limit("one_click", 30)) {
            OPTISTATE_Utils::send_json_error(
                OPTISTATE_Utils::get_rate_limit_message(false),
                429
            );
            return;
        }
        try {
            $cleaned = $this->perform_optimizations(true);
            $total_cleaned = 0;
            if (is_array($cleaned)) {
                array_walk_recursive($cleaned, function ($value) use (
                    &$total_cleaned
                ) {
                    if (is_numeric($value)) {
                        $total_cleaned += $value;
                    }
                });
            }
            $this->main_plugin->log_entry(
                "🧹 " .
                    sprintf(
                        __(
                            "One-Click Optimization Completed (%s items cleaned) by {username}",
                            "optistate"
                        ),
                        number_format_i18n($total_cleaned)
                    )
            );
            $this->main_plugin->clear_stats_cache();
            OPTISTATE_Utils::invalidate_table_cache();
            $new_stats = $this->main_plugin->get_combined_database_statistics(
                true
            );
            $health_score = $this->main_plugin->health_score->calculate_health_score(
                $new_stats
            );
            $cleaned["health_score"] = $health_score;
            OPTISTATE_Utils::send_json_success($cleaned);
        } catch (Throwable $e) {
            OPTISTATE_Utils::log_critical_error(
                "One-click optimization failed: " . $e->getMessage(),
                ["file" => $e->getFile(), "line" => $e->getLine()]
            );
            $this->main_plugin->log_entry(
                "❌ " . __("One-Click Optimization Failed", "optistate"),
                "error",
                "",
                ["details" => $e->getMessage()]
            );
            OPTISTATE_Utils::send_json_error(
                __(
                    "An unexpected error occurred during the optimization. Please try again.",
                    "optistate"
                )
            );
        }
    }
    public function perform_optimizations(bool $return_data = false)
    {
        wp_raise_memory_limit("admin");
        $cleaned = [];
        return OPTISTATE_Utils::with_session_vars(
            ["FOREIGN_KEY_CHECKS" => 0, "UNIQUE_CHECKS" => 0],
            function () use ($return_data, &$cleaned) {
                $is_automated =
                    wp_doing_cron() &&
                    (doing_action("optistate_scheduled_cleanup") ||
                        doing_action("optistate_async_backup_complete"));
                $is_cli = defined("WP_CLI") && WP_CLI;
                $is_admin_request = current_user_can("manage_options");
                if (!$is_automated && !$is_cli && !$is_admin_request) {
                    return $return_data ? [] : null;
                }
                $default_ops = self::get_default_one_click_operations();
                $settings = $this->main_plugin->settings_manager->get_persistent_settings();
                $extra_keys = $settings["one_click_extra_items"] ?? [];
                $all_items = self::get_all_cleanup_items();
                $extra_keys = array_filter($extra_keys, function ($key) use (
                    $all_items
                ) {
                    return isset($all_items[$key]);
                });
                $operation_keys = array_unique(
                    array_merge($default_ops, $extra_keys)
                );
                if (in_array("all_transients", $operation_keys, true)) {
                    $operation_keys = array_diff($operation_keys, [
                        "expired_transients",
                    ]);
                }
                $method_map = ["woo_bloat" => "clean_woocommerce_bloat"];
                $operations = [];
                foreach ($operation_keys as $key) {
                    $method_name = $method_map[$key] ?? "clean_" . $key;
                    if (method_exists($this, $method_name)) {
                        $operations[$key] = [$this, $method_name];
                    }
                }
                foreach ($operations as $op_name => $callback) {
                    try {
                        $cleaned[$op_name] = $callback();
                    } catch (Throwable $e) {
                        $error_details = [
                            "operation" => $op_name,
                            "message" => $e->getMessage(),
                            "file" => $e->getFile(),
                            "line" => $e->getLine(),
                            "memory_usage" => size_format(
                                memory_get_usage(true),
                                2
                            ),
                            "peak_memory" => size_format(
                                memory_get_peak_usage(true),
                                2
                            ),
                        ];
                        $this->main_plugin->log_entry(
                            "❌ " .
                                sprintf(
                                    __(
                                        "Optimization operation '%s' failed: %s",
                                        "optistate"
                                    ),
                                    $op_name,
                                    $e->getMessage()
                                ),
                            "error",
                            "",
                            $error_details
                        );
                        OPTISTATE_Utils::log_critical_error(
                            "Optimization operation failed: " .
                                $e->getMessage(),
                            $error_details
                        );
                        $cleaned[$op_name] = 0;
                    }
                }
                $this->flush_collected_caches();
                return $return_data ? $cleaned : null;
            }
        );
    }
    private function flush_collected_caches(): void
    {
        $affected_post_ids = $this->get_last_affected_post_ids();
        $affected_comment_ids = $this->get_last_affected_comment_ids();
        $affected_term_ids = $this->get_last_affected_term_ids();
        $affected_user_ids = $this->get_last_affected_user_ids();
        if (!empty($affected_post_ids)) {
            foreach (
                array_unique(array_map("absint", $affected_post_ids))
                as $post_id
            ) {
                clean_post_cache($post_id);
                wp_cache_delete($post_id, "posts");
                wp_cache_delete($post_id, "post_meta");
            }
        }
        wp_cache_delete("last_changed", "posts");
        if (!empty($affected_comment_ids)) {
            foreach (
                array_unique(array_map("absint", $affected_comment_ids))
                as $comment_id
            ) {
                clean_comment_cache($comment_id);
                wp_cache_delete($comment_id, "comment_meta");
            }
        }
        wp_cache_delete("last_changed", "comments");
        if (!empty($affected_term_ids)) {
            clean_term_cache(
                array_unique(array_map("absint", $affected_term_ids))
            );
        }
        wp_cache_delete("last_changed", "terms");
        if (!empty($affected_user_ids)) {
            foreach (
                array_unique(array_map("absint", $affected_user_ids))
                as $user_id
            ) {
                clean_user_cache($user_id);
                wp_cache_delete($user_id, "user_meta");
            }
        }
        wp_cache_delete("last_changed", "users");
        wp_cache_delete("alloptions", "options");
        wp_cache_delete("notoptions", "options");
        wp_cache_delete("last_changed", "post_meta");
        wp_cache_delete("last_changed", "comment_meta");
        wp_cache_delete("last_changed", "term_relationships");
        wp_cache_delete("last_changed", "user_meta");
        wp_cache_delete("last_changed", "term_meta");
    }
    private function run_batch_delete(
        string $query_template,
        int $batch_size = 2000,
        array $parameters = []
    ): int {
        global $wpdb;
        $total_cleaned = 0;
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $memory_limit = wp_convert_hr_to_bytes(ini_get("memory_limit"));
        $adaptive_batch = $batch_size;
        if ($memory_limit > 0) {
            $available_memory = $memory_limit - memory_get_usage(true);
            if ($available_memory < 50 * 1024 * 1024) {
                $adaptive_batch = min($batch_size, 500);
            } elseif ($available_memory < 100 * 1024 * 1024) {
                $adaptive_batch = min($batch_size, 1000);
            }
        }
        $has_limit = strpos($query_template, "LIMIT %d") !== false;
        do {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $current_batch = $adaptive_batch;
            if (isset($last_elapsed) && $last_elapsed > 2) {
                $current_batch = max(100, (int) ($adaptive_batch * 0.7));
            }
            if ($has_limit) {
                $current_params = array_merge($parameters, [$current_batch]);
                $query = $wpdb->prepare($query_template, ...$current_params);
            } else {
                $query = $query_template;
            }
            try {
                $cleaned = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $query
                ) {
                    $result = $wpdb->query($query);
                    if ($result === false) {
                        throw new \Exception(
                            "Batch delete failed: " . $wpdb->last_error
                        );
                    }
                    return $result;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "Batch delete interrupted after partial progress",
                    [
                        "query_preview" => substr($query, 0, 200),
                        "error" => $e->getMessage(),
                        "processed" => $total_cleaned,
                    ]
                );
                break;
            }
            $total_cleaned += $cleaned;
            $last_elapsed = microtime(true) - $start_time;
            if (!$has_limit || $cleaned < $current_batch) {
                break;
            }
            usleep(20000);
        } while (true);
        return $total_cleaned;
    }
    public function get_taxonomy_buckets(): array
    {
        global $wpdb;
        $registered = array_keys(get_taxonomies());
        $all_db_slugs = $wpdb->get_col(
            "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}"
        );
        $unregistered = array_diff($all_db_slugs, $registered);
        if (empty($unregistered)) {
            return ["registered" => $registered, "skip" => [], "orphan" => []];
        }
        $placeholders = implode(
            ", ",
            array_fill(0, count($unregistered), "%s")
        );
        $slugs_with_refs = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tt.taxonomy FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN ($placeholders)",
                ...$unregistered
            )
        );
        $slugs_with_refs_index = array_flip($slugs_with_refs);
        $skip = [];
        $orphan = [];
        foreach ($unregistered as $slug) {
            if (isset($slugs_with_refs_index[$slug])) {
                $skip[] = $slug;
            } else {
                $orphan[] = $slug;
            }
        }
        return [
            "registered" => $registered,
            "skip" => $skip,
            "orphan" => $orphan,
        ];
    }
    private function clean_action_scheduler(): int
    {
        global $wpdb;
        $table_actions = $wpdb->prefix . "actionscheduler_actions";
        $table_logs = $wpdb->prefix . "actionscheduler_logs";
        $table_claims = $wpdb->prefix . "actionscheduler_claims";
        $table_groups = $wpdb->prefix . "actionscheduler_groups";
        if (!OPTISTATE_Utils::table_exists($table_actions)) {
            return 0;
        }
        $cleaned = 0;
        $batch_size = 2000;
        $cleaned += $this->run_batch_delete(
            "DELETE FROM {$table_actions} WHERE status IN ('complete', 'failed', 'canceled') ORDER BY action_id ASC LIMIT %d",
            $batch_size
        );
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $clean_orphans = function (
            $child_table,
            $child_id_col,
            $parent_id_col
        ) use ($wpdb, $table_actions, $batch_size, $start_time, $time_limit) {
            $total = 0;
            if (OPTISTATE_Utils::table_exists($child_table)) {
                while (true) {
                    if ($this->is_time_exceeded($start_time, $time_limit)) {
                        break;
                    }
                    $ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT c.{$child_id_col} FROM {$child_table} c LEFT JOIN {$table_actions} a ON a.{$parent_id_col} = c.{$parent_id_col} WHERE a.{$parent_id_col} IS NULL ORDER BY c.{$child_id_col} ASC LIMIT %d",
                            $batch_size
                        )
                    );
                    if (empty($ids)) {
                        break;
                    }
                    $safe_ids = implode(",", array_map("absint", $ids));
                    try {
                        $deleted = OPTISTATE_Utils::transaction(
                            function () use (
                                $wpdb,
                                $child_table,
                                $child_id_col,
                                $safe_ids
                            ) {
                                $deleted = $wpdb->query(
                                    "DELETE FROM {$child_table} WHERE {$child_id_col} IN ($safe_ids)"
                                );
                                if ($deleted === false) {
                                    throw new \Exception(
                                        "orphan cleanup failed for " .
                                            $child_table
                                    );
                                }
                                return $deleted;
                            }
                        );
                    } catch (\Throwable $e) {
                        OPTISTATE_Utils::log_critical_error(
                            "Orphan cleanup interrupted",
                            [
                                "table" => $child_table,
                                "error" => $e->getMessage(),
                            ]
                        );
                        break;
                    }
                    $total += $deleted;
                    if (count($ids) < $batch_size) {
                        break;
                    }
                    usleep(20000);
                }
            }
            return $total;
        };
        $cleaned += $clean_orphans($table_logs, "log_id", "action_id");
        $cleaned += $clean_orphans($table_claims, "claim_id", "claim_id");
        $cleaned += $clean_orphans($table_groups, "group_id", "group_id");
        return $cleaned;
    }
    private function clean_oembed_cache(): int
    {
        global $wpdb;
        $batch_size = 2000;
        $total = 0;
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $like_pm = $wpdb->esc_like("_oembed_") . "%";
        do {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s ORDER BY meta_id ASC LIMIT %d",
                    $like_pm,
                    $batch_size
                )
            );
            if (empty($ids)) {
                break;
            }
            $safe_ids = implode(",", array_map("absint", $ids));
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $safe_ids
                ) {
                    $deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($safe_ids)"
                    );
                    if ($deleted === false) {
                        throw new \Exception("postmeta oembed delete failed");
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "oembed cleanup interrupted",
                    ["error" => $e->getMessage()]
                );
                break;
            }
            $total += $deleted;
            usleep(20000);
        } while (true);
        $like_oembed = $wpdb->esc_like("_oembed_") . "%";
        $like_trans_oembed = $wpdb->esc_like("_transient_oembed_") . "%";
        $like_trans_timeout =
            $wpdb->esc_like("_transient_timeout_oembed_") . "%";
        do {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $opt_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_id FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s) ORDER BY option_id ASC LIMIT %d",
                    $like_oembed,
                    $like_trans_oembed,
                    $like_trans_timeout,
                    $batch_size
                )
            );
            if (empty($opt_ids)) {
                break;
            }
            $safe_opt_ids = implode(",", array_map("absint", $opt_ids));
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $safe_opt_ids
                ) {
                    $deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->options} WHERE option_id IN ($safe_opt_ids)"
                    );
                    if ($deleted === false) {
                        throw new \Exception("options oembed delete failed");
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "oembed options cleanup interrupted",
                    ["error" => $e->getMessage()]
                );
                break;
            }
            $total += $deleted;
            usleep(20000);
        } while (true);
        return $total;
    }
    private function clean_woocommerce_bloat(): int
    {
        global $wpdb;
        $total_cleaned = 0;
        $now = time();
        $batch_size = 500;
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $pairs = [
            [
                "timeout" => "_transient_timeout_wc_",
                "value" => "_transient_wc_",
            ],
            [
                "timeout" => "_transient_timeout_wc_var_",
                "value" => "_transient_wc_var_",
            ],
            ["timeout" => "_wc_session_expires_", "value" => "_wc_session_"],
        ];
        foreach ($pairs as $p) {
            do {
                if ($this->is_time_exceeded($start_time, $time_limit)) {
                    break 2;
                }
                $timeouts = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d ORDER BY option_id ASC LIMIT %d",
                        $wpdb->esc_like($p["timeout"]) . "%",
                        $now,
                        $batch_size
                    )
                );
                if (empty($timeouts)) {
                    break;
                }
                $value_names = array_map(
                    fn($n) => str_replace($p["timeout"], $p["value"], $n),
                    $timeouts
                );
                $all_names = array_merge($timeouts, $value_names);
                $placeholders = implode(
                    ",",
                    array_fill(0, count($all_names), "%s")
                );
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                        ...$all_names
                    )
                );
                if ($deleted !== false) {
                    $total_cleaned += $deleted;
                    foreach ($all_names as $name) {
                        wp_cache_delete($name, "options");
                    }
                }
                usleep(20000);
            } while (count($timeouts) === $batch_size);
        }
        $orphan_patterns = [
            [
                "value" => "_transient_wc_",
                "timeout" => "_transient_timeout_wc_",
            ],
            [
                "value" => "_transient_wc_var_",
                "timeout" => "_transient_timeout_wc_var_",
            ],
            ["value" => "_wc_session_", "timeout" => "_wc_session_expires_"],
        ];
        foreach ($orphan_patterns as $p) {
            do {
                if ($this->is_time_exceeded($start_time, $time_limit)) {
                    break 2;
                }
                $candidates = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE '_transient_timeout_%' AND option_name NOT LIKE '_wc_session_expires_%' ORDER BY option_id ASC LIMIT %d",
                        $wpdb->esc_like($p["value"]) . "%",
                        $batch_size
                    )
                );
                if (empty($candidates)) {
                    break;
                }
                $timeout_candidates = array_map(function ($name) use ($p) {
                    return str_replace($p["value"], $p["timeout"], $name);
                }, $candidates);
                $placeholders = implode(
                    ",",
                    array_fill(0, count($timeout_candidates), "%s")
                );
                $valid_timeouts = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders) AND option_value >= %d",
                        ...array_merge($timeout_candidates, [$now])
                    )
                );
                $valid_timeout_set = array_flip($valid_timeouts);
                $orphan_names = [];
                foreach ($candidates as $idx => $value_name) {
                    $timeout_name = $timeout_candidates[$idx];
                    if (!isset($valid_timeout_set[$timeout_name])) {
                        $orphan_names[] = $value_name;
                    }
                }
                if (empty($orphan_names)) {
                    if (count($candidates) < $batch_size) {
                        break;
                    }
                    continue;
                }
                $orphan_placeholders = implode(
                    ",",
                    array_fill(0, count($orphan_names), "%s")
                );
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name IN ($orphan_placeholders)",
                        ...$orphan_names
                    )
                );
                if ($deleted !== false) {
                    $total_cleaned += $deleted;
                    foreach ($orphan_names as $name) {
                        wp_cache_delete($name, "options");
                    }
                }
                usleep(20000);
            } while (count($candidates) === $batch_size);
        }
        $wc_session_table = $wpdb->prefix . "woocommerce_sessions";
        if (OPTISTATE_Utils::table_exists($wc_session_table)) {
            do {
                if ($this->is_time_exceeded($start_time, $time_limit)) {
                    break;
                }
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM $wc_session_table WHERE session_expiry < %d ORDER BY session_id ASC LIMIT %d",
                        $now,
                        $batch_size
                    )
                );
                if ($deleted !== false) {
                    $total_cleaned += $deleted;
                }
            } while ($deleted >= $batch_size);
        }
        wp_cache_delete("alloptions", "options");
        wp_cache_delete("notoptions", "options");
        $transient_patterns = ["_transient_wc_", "_wc_session_"];
        foreach ($transient_patterns as $pattern) {
            $keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
                    $pattern . "%"
                )
            );
            foreach ($keys as $key) {
                wp_cache_delete($key, "options");
            }
        }
        return $total_cleaned;
    }
    private function clean_empty_taxonomies(): int
    {
        global $wpdb;
        $buckets = $this->get_taxonomy_buckets();
        $registered = $buckets["registered"];
        $orphan = $buckets["orphan"];
        $actionable = array_merge($registered, $orphan);
        if (empty($actionable)) {
            return 0;
        }
        $batch_limit = 500;
        $count = 0;
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $last_tt_id = 0;
        $orphan_index = array_flip($orphan);
        $placeholders = implode(", ", array_fill(0, count($actionable), "%s"));
        $affected_term_ids = [];
        $affected_taxonomies_for_recount = [];
        do {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.term_id, tt.term_taxonomy_id, tt.taxonomy FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id WHERE tt.count = 0 AND t.slug != 'uncategorized' AND tt.taxonomy NOT IN ('nav_menu', 'link_category', 'post_format') AND tt.taxonomy IN ($placeholders) AND tt.term_taxonomy_id > %d ORDER BY tt.term_taxonomy_id ASC LIMIT %d",
                    ...array_merge($actionable, [$last_tt_id, $batch_limit])
                )
            );
            if (empty($rows)) {
                break;
            }
            $time_exceeded = false;
            $batch_orphan_tt_ids = [];
            $batch_orphan_term_ids = [];
            foreach ($rows as $row) {
                if ($this->is_time_exceeded($start_time, $time_limit)) {
                    $time_exceeded = true;
                    break;
                }
                $last_tt_id = (int) $row->term_taxonomy_id;
                $affected_term_ids[] = (int) $row->term_id;
                $affected_taxonomies_for_recount[$row->taxonomy] = true;
                if (!isset($orphan_index[$row->taxonomy])) {
                    $result = wp_delete_term(
                        (int) $row->term_id,
                        $row->taxonomy
                    );
                    if (!is_wp_error($result) && $result !== false) {
                        $count++;
                    } else {
                        OPTISTATE_Utils::log_critical_error(
                            "Failed to delete empty term",
                            [
                                "term_id" => $row->term_id,
                                "taxonomy" => $row->taxonomy,
                            ]
                        );
                    }
                } else {
                    $batch_orphan_tt_ids[] = (int) $row->term_taxonomy_id;
                    $batch_orphan_term_ids[(int) $row->term_id] = true;
                }
            }
            if (!empty($batch_orphan_tt_ids)) {
                $tt_placeholders = implode(
                    ", ",
                    array_fill(0, count($batch_orphan_tt_ids), "%d")
                );
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($tt_placeholders)",
                        ...$batch_orphan_tt_ids
                    )
                );
                $tt_deleted_count = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($tt_placeholders)",
                        ...$batch_orphan_tt_ids
                    )
                );
                if ($tt_deleted_count !== false) {
                    $count += (int) $tt_deleted_count;
                }
                $candidate_term_ids = array_keys($batch_orphan_term_ids);
                $term_placeholders = implode(
                    ", ",
                    array_fill(0, count($candidate_term_ids), "%d")
                );
                $still_used = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT term_id FROM {$wpdb->term_taxonomy} WHERE term_id IN ($term_placeholders)",
                        ...$candidate_term_ids
                    )
                );
                $still_used_index = array_flip(
                    array_map("intval", $still_used)
                );
                $term_ids_to_delete = array_filter(
                    $candidate_term_ids,
                    function ($term_id) use ($still_used_index) {
                        return !isset($still_used_index[$term_id]);
                    }
                );
                if (!empty($term_ids_to_delete)) {
                    $delete_placeholders = implode(
                        ", ",
                        array_fill(0, count($term_ids_to_delete), "%d")
                    );
                    $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$wpdb->terms} WHERE term_id IN ($delete_placeholders)",
                            ...$term_ids_to_delete
                        )
                    );
                    $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ($delete_placeholders)",
                            ...$term_ids_to_delete
                        )
                    );
                }
            }
            if ($time_exceeded) {
                break;
            }
        } while (count($rows) === $batch_limit);
        if ($count > 0) {
            $taxonomies = array_keys($affected_taxonomies_for_recount);
            foreach ($taxonomies as $taxonomy) {
                if (taxonomy_exists($taxonomy)) {
                    $term_taxonomy_ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                            $taxonomy
                        )
                    );
                    if (!empty($term_taxonomy_ids)) {
                        wp_update_term_count_now($term_taxonomy_ids, $taxonomy);
                    }
                }
            }
            if (
                function_exists("clean_term_cache") &&
                !empty($affected_term_ids)
            ) {
                clean_term_cache(array_unique($affected_term_ids));
            }
            if (function_exists("clean_taxonomy_cache")) {
                foreach ($taxonomies as $taxonomy_name) {
                    if (taxonomy_exists($taxonomy_name)) {
                        clean_taxonomy_cache($taxonomy_name);
                    }
                }
                wp_cache_delete("last_changed", "taxonomies");
            }
            wp_cache_delete("last_changed", "terms");
        }
        return $count;
    }
    private function clean_post_revisions(): int
    {
        global $wpdb;
        $total = 0;
        $last_id = 0;
        $batch_size = 2000;
        $affected_parent_ids = [];
        $start_time = time();
        $time_limit = $this->get_time_limit();
        do {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $revisions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent != 0 AND ID > %d ORDER BY ID ASC LIMIT %d",
                    $last_id,
                    $batch_size
                )
            );
            if (empty($revisions)) {
                break;
            }
            foreach ($revisions as $revision) {
                $affected_parent_ids[] = (int) $revision->post_parent;
            }
            $ids = array_column($revisions, "ID");
            $last_id = (int) end($ids);
            $safe_ids = implode(",", array_map("absint", $ids));
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $safe_ids
                ) {
                    $meta_deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($safe_ids)"
                    );
                    if ($meta_deleted === false) {
                        throw new \Exception("postmeta delete failed");
                    }
                    $rel_deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($safe_ids)"
                    );
                    if ($rel_deleted === false) {
                        throw new \Exception(
                            "term_relationships delete failed"
                        );
                    }
                    $deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->posts} WHERE ID IN ($safe_ids)"
                    );
                    if ($deleted === false) {
                        throw new \Exception("posts delete failed");
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "clean_post_revisions interrupted",
                    [
                        "error" => $e->getMessage(),
                        "processed" => $total,
                        "batch_ids" => count($ids),
                    ]
                );
                break;
            }
            $total += $deleted;
            usleep(20000);
        } while (count($ids) === $batch_size);
        if (!empty($affected_parent_ids)) {
            $this->set_last_affected_post_ids(
                array_unique($affected_parent_ids)
            );
        }
        return $total;
    }
    private function clean_auto_drafts(): int
    {
        global $wpdb;
        $total = 0;
        $last_id = 0;
        $batch_size = 2000;
        $affected_parent_ids = [];
        $start_time = time();
        $time_limit = $this->get_time_limit();
        do {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND ID > %d ORDER BY ID ASC LIMIT %d",
                    $last_id,
                    $batch_size
                )
            );
            if (empty($ids)) {
                break;
            }
            foreach ($ids as $id) {
                $affected_parent_ids[] = (int) $id;
            }
            $last_id = (int) end($ids);
            $safe_ids = implode(",", array_map("absint", $ids));
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $safe_ids,
                    &$affected_parent_ids
                ) {
                    $meta_deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($safe_ids)"
                    );
                    if ($meta_deleted === false) {
                        throw new \Exception("postmeta delete failed");
                    }
                    $rel_deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($safe_ids)"
                    );
                    if ($rel_deleted === false) {
                        throw new \Exception(
                            "term_relationships delete failed"
                        );
                    }
                    $child_ids = $wpdb->get_col(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($safe_ids)"
                    );
                    if (!empty($child_ids)) {
                        $safe_child_ids = implode(
                            ",",
                            array_map("absint", $child_ids)
                        );
                        $wpdb->query(
                            "UPDATE {$wpdb->posts} SET post_parent = 0 WHERE ID IN ($safe_child_ids)"
                        );
                        foreach ($child_ids as $cid) {
                            $affected_parent_ids[] = (int) $cid;
                        }
                    }
                    $deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->posts} WHERE ID IN ($safe_ids)"
                    );
                    if ($deleted === false) {
                        throw new \Exception("posts delete failed");
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "clean_auto_drafts interrupted",
                    [
                        "error" => $e->getMessage(),
                        "processed" => $total,
                        "batch_ids" => count($ids),
                    ]
                );
                break;
            }
            $total += $deleted;
            usleep(20000);
        } while (count($ids) === $batch_size);
        if (!empty($affected_parent_ids)) {
            $this->set_last_affected_post_ids(
                array_unique($affected_parent_ids)
            );
        }
        return $total;
    }
    private function clean_trashed_posts(): int
    {
        global $wpdb;
        $total = 0;
        $last_id = 0;
        $batch_size = 500;
        $affected_post_ids = [];
        $affected_comment_ids = [];
        $affected_taxonomies = [];
        $start_time = time();
        $time_limit = $this->get_time_limit();
        do {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' AND ID > %d ORDER BY ID ASC LIMIT %d",
                    $last_id,
                    $batch_size
                )
            );
            if (empty($ids)) {
                break;
            }
            foreach ($ids as $id) {
                $affected_post_ids[] = (int) $id;
            }
            $last_id = (int) end($ids);
            $safe_ids = implode(",", array_map("absint", $ids));
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $safe_ids,
                    &$affected_comment_ids,
                    &$affected_post_ids,
                    &$affected_taxonomies
                ) {
                    $meta_deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($safe_ids)"
                    );
                    if ($meta_deleted === false) {
                        throw new \Exception("postmeta delete failed");
                    }
                    $touched_taxonomies = $wpdb->get_col(
                        "SELECT DISTINCT tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id IN ($safe_ids)"
                    );
                    if (!empty($touched_taxonomies)) {
                        foreach ($touched_taxonomies as $taxonomy) {
                            $affected_taxonomies[$taxonomy] = true;
                        }
                    }
                    $rel_deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($safe_ids)"
                    );
                    if ($rel_deleted === false) {
                        throw new \Exception(
                            "term_relationships delete failed"
                        );
                    }
                    $child_ids = $wpdb->get_col(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($safe_ids)"
                    );
                    if (!empty($child_ids)) {
                        $safe_child_ids = implode(
                            ",",
                            array_map("absint", $child_ids)
                        );
                        $wpdb->query(
                            "UPDATE {$wpdb->posts} SET post_parent = 0 WHERE ID IN ($safe_child_ids)"
                        );
                        foreach ($child_ids as $cid) {
                            $affected_post_ids[] = (int) $cid;
                        }
                    }
                    $comment_ids = $wpdb->get_col(
                        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($safe_ids)"
                    );
                    if (!empty($comment_ids)) {
                        $all_cids = array_map("absint", $comment_ids);
                        $frontier = $all_cids;
                        while (!empty($frontier)) {
                            $next_level = $wpdb->get_col(
                                "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_parent IN (" .
                                    implode(",", $frontier) .
                                    ")"
                            );
                            if (empty($next_level)) {
                                break;
                            }
                            $next_level = array_map("absint", $next_level);
                            $next_level = array_values(
                                array_diff($next_level, $all_cids)
                            );
                            if (empty($next_level)) {
                                break;
                            }
                            $all_cids = array_merge($all_cids, $next_level);
                            $frontier = $next_level;
                        }
                        foreach ($all_cids as $cid) {
                            $affected_comment_ids[] = (int) $cid;
                        }
                        $safe_cids = implode(",", $all_cids);
                        $cm_deleted = $wpdb->query(
                            "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($safe_cids)"
                        );
                        if ($cm_deleted === false) {
                            throw new \Exception("commentmeta delete failed");
                        }
                        $comments_deleted = $wpdb->query(
                            "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($safe_cids)"
                        );
                        if ($comments_deleted === false) {
                            throw new \Exception("comments delete failed");
                        }
                    }
                    $deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->posts} WHERE ID IN ($safe_ids)"
                    );
                    if ($deleted === false) {
                        throw new \Exception("posts delete failed");
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "clean_trashed_posts interrupted",
                    [
                        "error" => $e->getMessage(),
                        "processed" => $total,
                        "batch_ids" => count($ids),
                    ]
                );
                break;
            }
            $total += $deleted;
            usleep(30000);
        } while (count($ids) === $batch_size);
        if (!empty($affected_taxonomies)) {
            foreach (array_keys($affected_taxonomies) as $taxonomy) {
                if (taxonomy_exists($taxonomy)) {
                    $term_taxonomy_ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                            $taxonomy
                        )
                    );
                    if (!empty($term_taxonomy_ids)) {
                        wp_update_term_count_now($term_taxonomy_ids, $taxonomy);
                    }
                }
            }
            wp_cache_delete("last_changed", "terms");
        }
        if (!empty($affected_post_ids)) {
            $this->set_last_affected_post_ids(array_unique($affected_post_ids));
        }
        if (!empty($affected_comment_ids)) {
            $this->set_last_affected_comment_ids(
                array_unique($affected_comment_ids)
            );
        }
        return $total;
    }
    private function clean_spam_comments(): int
    {
        global $wpdb;
        return $this->run_batch_delete(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
            2000,
            [0]
        );
    }
    private function clean_trashed_comments(): int
    {
        global $wpdb;
        return $this->run_batch_delete(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
            2000,
            [0]
        );
    }
    private function clean_unapproved_comments(): int
    {
        global $wpdb;
        return $this->run_batch_delete(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = '0' AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
            2000,
            [0]
        );
    }
    private function clean_pingbacks(): int
    {
        global $wpdb;
        return $this->run_batch_delete(
            "DELETE FROM {$wpdb->comments} WHERE comment_type = 'pingback' AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
            2000,
            [0]
        );
    }
    private function clean_trackbacks(): int
    {
        global $wpdb;
        return $this->run_batch_delete(
            "DELETE FROM {$wpdb->comments} WHERE comment_type = 'trackback' AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
            2000,
            [0]
        );
    }
    private function clean_orphaned_meta(
        string $meta_table,
        string $meta_fk_col,
        string $meta_id_col,
        string $parent_table,
        string $parent_id_col
    ): int {
        global $wpdb;
        $allowed = [
            $wpdb->postmeta => [
                "fk" => "post_id",
                "id" => "meta_id",
                "parent" => $wpdb->posts,
                "pid" => "ID",
            ],
            $wpdb->commentmeta => [
                "fk" => "comment_id",
                "id" => "meta_id",
                "parent" => $wpdb->comments,
                "pid" => "comment_ID",
            ],
            $wpdb->usermeta => [
                "fk" => "user_id",
                "id" => "umeta_id",
                "parent" => $wpdb->users,
                "pid" => "ID",
            ],
            $wpdb->termmeta => [
                "fk" => "term_id",
                "id" => "meta_id",
                "parent" => $wpdb->terms,
                "pid" => "term_id",
            ],
        ];
        if (
            !isset($allowed[$meta_table]) ||
            $allowed[$meta_table]["fk"] !== $meta_fk_col ||
            $allowed[$meta_table]["id"] !== $meta_id_col ||
            $allowed[$meta_table]["parent"] !== $parent_table ||
            $allowed[$meta_table]["pid"] !== $parent_id_col
        ) {
            OPTISTATE_Utils::log_critical_error(
                "clean_orphaned_meta rejected unauthorized parameters",
                ["meta_table" => $meta_table]
            );
            return 0;
        }
        $total = 0;
        $batch_size = 2000;
        $affected_parent_ids = [];
        $start_time = time();
        $time_limit = $this->get_time_limit();
        while (true) {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.{$meta_id_col}, m.{$meta_fk_col} FROM {$meta_table} m LEFT JOIN {$parent_table} p ON p.{$parent_id_col} = m.{$meta_fk_col} WHERE p.{$parent_id_col} IS NULL ORDER BY m.{$meta_id_col} ASC LIMIT %d",
                    $batch_size
                ),
                ARRAY_A
            );
            if (empty($rows)) {
                break;
            }
            $orphaned_ids = array_column($rows, $meta_id_col);
            $affected_parent_ids = array_merge(
                $affected_parent_ids,
                array_column($rows, $meta_fk_col)
            );
            $placeholders = implode(
                ",",
                array_fill(0, count($orphaned_ids), "%d")
            );
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $meta_table,
                    $meta_id_col,
                    $orphaned_ids,
                    $placeholders
                ) {
                    $deleted = $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$meta_table} WHERE {$meta_id_col} IN ($placeholders)",
                            ...$orphaned_ids
                        )
                    );
                    if ($deleted === false) {
                        throw new \Exception("orphaned meta delete failed");
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "clean_orphaned_meta interrupted",
                    [
                        "meta_table" => $meta_table,
                        "error" => $e->getMessage(),
                        "processed" => $total,
                    ]
                );
                break;
            }
            $total += $deleted;
            usleep(20000);
        }
        if ($meta_table === $wpdb->postmeta && !empty($affected_parent_ids)) {
            $this->set_last_affected_post_ids(
                array_unique($affected_parent_ids)
            );
        } elseif (
            $meta_table === $wpdb->commentmeta &&
            !empty($affected_parent_ids)
        ) {
            $this->set_last_affected_comment_ids(
                array_unique($affected_parent_ids)
            );
        } elseif (
            $meta_table === $wpdb->usermeta &&
            !empty($affected_parent_ids)
        ) {
            $this->set_last_affected_user_ids(
                array_unique($affected_parent_ids)
            );
        } elseif (
            $meta_table === $wpdb->termmeta &&
            !empty($affected_parent_ids)
        ) {
            $this->set_last_affected_term_ids(
                array_unique($affected_parent_ids)
            );
        }
        return $total;
    }
    private function clean_orphaned_postmeta(): int
    {
        global $wpdb;
        return $this->clean_orphaned_meta(
            $wpdb->postmeta,
            "post_id",
            "meta_id",
            $wpdb->posts,
            "ID"
        );
    }
    private function clean_orphaned_commentmeta(): int
    {
        global $wpdb;
        return $this->clean_orphaned_meta(
            $wpdb->commentmeta,
            "comment_id",
            "meta_id",
            $wpdb->comments,
            "comment_ID"
        );
    }
    private function clean_orphaned_usermeta(): int
    {
        global $wpdb;
        return $this->clean_orphaned_meta(
            $wpdb->usermeta,
            "user_id",
            "umeta_id",
            $wpdb->users,
            "ID"
        );
    }
    private function clean_orphaned_termmeta(): int
    {
        global $wpdb;
        return $this->clean_orphaned_meta(
            $wpdb->termmeta,
            "term_id",
            "meta_id",
            $wpdb->terms,
            "term_id"
        );
    }
    private function clean_orphaned_relationships(): int
    {
        global $wpdb;
        $total = 0;
        $batch_size = 2000;
        $affected_term_ids = [];
        $affected_taxonomies = [];
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $links_check = isset($wpdb->links)
            ? "AND NOT EXISTS (SELECT 1 FROM {$wpdb->links} l WHERE l.link_id = tr.object_id)"
            : "";
        while (true) {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $tuples = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tr.object_id, tr.term_taxonomy_id, tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.term_taxonomy_id IS NULL OR (NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = tr.object_id) AND NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = tr.object_id) {$links_check}) ORDER BY tr.object_id ASC, tr.term_taxonomy_id ASC LIMIT %d",
                    $batch_size
                )
            );
            if (empty($tuples)) {
                break;
            }
            $values = [];
            foreach ($tuples as $t) {
                $values[] = sprintf(
                    "(%d, %d)",
                    (int) $t->object_id,
                    (int) $t->term_taxonomy_id
                );
                $term_id = (int) $t->term_id;
                if ($term_id) {
                    $affected_term_ids[] = $term_id;
                }
                if (!empty($t->taxonomy)) {
                    $affected_taxonomies[$t->taxonomy] = true;
                }
            }
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $values
                ) {
                    $deleted = $wpdb->query(
                        "DELETE FROM {$wpdb->term_relationships} WHERE (object_id, term_taxonomy_id) IN (" .
                            implode(",", $values) .
                            ")"
                    );
                    if ($deleted === false) {
                        throw new \Exception(
                            "orphaned relationships delete failed"
                        );
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "clean_orphaned_relationships interrupted",
                    ["error" => $e->getMessage(), "processed" => $total]
                );
                break;
            }
            $total += $deleted;
            usleep(20000);
        }
        if ($total > 0) {
            if (!empty($affected_taxonomies)) {
                foreach (array_keys($affected_taxonomies) as $taxonomy) {
                    if (taxonomy_exists($taxonomy)) {
                        $term_taxonomy_ids = $wpdb->get_col(
                            $wpdb->prepare(
                                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                                $taxonomy
                            )
                        );
                        if (!empty($term_taxonomy_ids)) {
                            wp_update_term_count_now(
                                $term_taxonomy_ids,
                                $taxonomy
                            );
                        }
                    }
                }
            }
            if (!empty($affected_term_ids)) {
                clean_term_cache(array_unique($affected_term_ids));
            }
            if (
                function_exists("clean_taxonomy_cache") &&
                !empty($affected_taxonomies)
            ) {
                foreach (array_keys($affected_taxonomies) as $tax) {
                    if (!empty($tax) && taxonomy_exists($tax)) {
                        clean_taxonomy_cache($tax);
                    }
                }
            }
            wp_cache_delete("last_changed", "terms");
            if (!empty($affected_term_ids)) {
                $this->set_last_affected_term_ids(
                    array_unique($affected_term_ids)
                );
            }
        }
        return $total;
    }
    private function clean_expired_transients(): int
    {
        if (wp_using_ext_object_cache()) {
            return 0;
        }
        global $wpdb;
        $now = time();
        $batch_size = 2000;
        $total = 0;
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $prefix_timeout = $wpdb->esc_like("_transient_timeout_") . "%";
        $prefix_site_time = $wpdb->esc_like("_site_transient_timeout_") . "%";
        $exclude_wc = $wpdb->esc_like("_transient_timeout_wc_") . "%";
        $exclude_oembed = $wpdb->esc_like("_transient_timeout_oembed_") . "%";
        $conditions = [
            $wpdb->prepare(
                "(option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value < %d)",
                $prefix_timeout,
                $exclude_wc,
                $exclude_oembed,
                $now
            ),
            $wpdb->prepare(
                "(option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value < %d)",
                $prefix_site_time,
                $exclude_wc,
                $exclude_oembed,
                $now
            ),
        ];
        $where = implode(" OR ", $conditions);
        $last_id = 0;
        while (true) {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_id, option_name FROM {$wpdb->options} WHERE ($where) AND option_id > %d ORDER BY option_id ASC LIMIT %d",
                    $last_id,
                    $batch_size
                )
            );
            if (empty($rows)) {
                break;
            }
            $last_id = (int) end($rows)->option_id;
            $expired = array_column($rows, "option_name");
            $expired_count = count($expired);
            $value_names = [];
            foreach ($expired as $name) {
                if (strpos($name, "_transient_timeout_") === 0) {
                    $value_names[] = str_replace(
                        "_transient_timeout_",
                        "_transient_",
                        $name
                    );
                } elseif (strpos($name, "_site_transient_timeout_") === 0) {
                    $value_names[] = str_replace(
                        "_site_transient_timeout_",
                        "_site_transient_",
                        $name
                    );
                }
            }
            $all_names = array_merge($expired, $value_names);
            $placeholders = implode(
                ",",
                array_fill(0, count($all_names), "%s")
            );
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                    ...$all_names
                )
            );
            if ($deleted === false) {
                OPTISTATE_Utils::log_critical_error(
                    "expired_transients cleanup failed",
                    ["where" => $where]
                );
                break;
            }
            $total += $expired_count;
            if ($expired_count < $batch_size) {
                break;
            }
            usleep(20000);
        }
        return $total;
    }
    private function clean_all_transients(): int
    {
        if (
            function_exists("wp_using_ext_object_cache") &&
            wp_using_ext_object_cache()
        ) {
            if (function_exists("wp_cache_flush_group")) {
                $flushed_transient = (bool) wp_cache_flush_group("transient");
                $flushed_site_transient = (bool) wp_cache_flush_group(
                    "site-transient"
                );
                return $flushed_transient && $flushed_site_transient ? 1 : 0;
            } elseif (function_exists("wp_cache_flush")) {
                return wp_cache_flush() ? 1 : 0;
            } else {
                OPTISTATE_Utils::log_critical_error(
                    "Cache flush not available (object cache active but no flush method)"
                );
                return 0;
            }
        }
        global $wpdb;
        $like_transient = "_transient_%";
        $like_site_trans = "_site_transient_%";
        $total_value_count = 0;
        $last_id = 0;
        $batch_size = 2000;
        $start_time = time();
        $time_limit = $this->get_time_limit();
        while (true) {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                break;
            }
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_id, option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_name NOT LIKE '\_transient\_wc\_%%' AND option_name NOT LIKE '\_transient\_oembed\_%%' AND option_id > %d ORDER BY option_id ASC LIMIT %d",
                    $like_transient,
                    $like_site_trans,
                    $last_id,
                    $batch_size
                ),
                ARRAY_A
            );
            if (empty($rows)) {
                break;
            }
            $ids = array_column($rows, "option_id");
            $last_id = (int) end($ids);
            foreach ($rows as $row) {
                $name = $row["option_name"];
                if (
                    strpos($name, "_transient_timeout_") !== 0 &&
                    strpos($name, "_site_transient_timeout_") !== 0
                ) {
                    $total_value_count++;
                }
            }
            $placeholders = implode(",", array_fill(0, count($ids), "%d"));
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_id IN ($placeholders)",
                    ...$ids
                )
            );
            if ($deleted === false) {
                OPTISTATE_Utils::log_critical_error(
                    "all_transients cleanup failed",
                    ["batch_size" => count($ids)]
                );
                break;
            }
            if (count($rows) < $batch_size) {
                break;
            }
            usleep(50000);
        }
        wp_cache_delete("alloptions", "options");
        wp_cache_delete("notoptions", "options");
        return $total_value_count;
    }
    private function clean_duplicate_meta(
        string $table,
        string $group_col,
        string $meta_id_col,
        string $log_label
    ): int {
        global $wpdb;
        $allowed_params = [
            $wpdb->postmeta => [
                "group_cols" => ["post_id"],
                "id_cols" => ["meta_id"],
            ],
            $wpdb->commentmeta => [
                "group_cols" => ["comment_id"],
                "id_cols" => ["meta_id"],
            ],
            $wpdb->usermeta => [
                "group_cols" => ["user_id"],
                "id_cols" => ["umeta_id"],
            ],
            $wpdb->termmeta => [
                "group_cols" => ["term_id"],
                "id_cols" => ["meta_id"],
            ],
        ];
        if (
            !isset($allowed_params[$table]) ||
            !in_array(
                $group_col,
                $allowed_params[$table]["group_cols"],
                true
            ) ||
            !in_array($meta_id_col, $allowed_params[$table]["id_cols"], true)
        ) {
            OPTISTATE_Utils::log_critical_error(
                "clean_duplicate_meta rejected unauthorized parameters",
                [
                    "table" => $table,
                    "group_col" => $group_col,
                    "meta_id_col" => $meta_id_col,
                ]
            );
            return 0;
        }
        $total_cleaned = 0;
        $start_time = time();
        $time_limit = $this->get_time_limit();
        $batch_size = 500;
        $affected_parent_ids = [];
        while (true) {
            if ($this->is_time_exceeded($start_time, $time_limit)) {
                OPTISTATE_Utils::log_critical_error(
                    "Duplicate cleanup reached time limit",
                    ["label" => $log_label, "processed" => $total_cleaned]
                );
                break;
            }
            $duplicate_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT /*+ MAX_EXECUTION_TIME(60000) */ m1.{$meta_id_col} FROM {$table} m1 INNER JOIN (SELECT MIN({$meta_id_col}) as min_id, {$group_col}, meta_key, MD5(meta_value) as val_hash FROM {$table} WHERE meta_key != '' GROUP BY {$group_col}, meta_key, val_hash HAVING COUNT(*) > 1) m2 ON m1.{$group_col} = m2.{$group_col} AND m1.meta_key = m2.meta_key AND MD5(m1.meta_value) = m2.val_hash AND m1.{$meta_id_col} > m2.min_id ORDER BY m1.{$meta_id_col} ASC LIMIT %d",
                    $batch_size
                )
            );
            if (empty($duplicate_ids)) {
                break;
            }
            $id_placeholders = implode(
                ",",
                array_fill(0, count($duplicate_ids), "%d")
            );
            $parent_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT {$group_col} FROM {$table} WHERE {$meta_id_col} IN ($id_placeholders)",
                    ...$duplicate_ids
                )
            );
            $affected_parent_ids = array_merge(
                $affected_parent_ids,
                $parent_ids
            );
            $cleaned_batch = $this->execute_meta_delete_batch(
                $table,
                $meta_id_col,
                $duplicate_ids
            );
            $total_cleaned += $cleaned_batch;
            if ($cleaned_batch === 0) {
                break;
            }
            usleep(5000);
        }
        if ($total_cleaned > 0 && !empty($affected_parent_ids)) {
            $affected_parent_ids = array_unique($affected_parent_ids);
            if ($table === $wpdb->postmeta) {
                foreach ($affected_parent_ids as $post_id) {
                    wp_cache_delete($post_id, "post_meta");
                    clean_post_cache($post_id);
                }
                $this->set_last_affected_post_ids($affected_parent_ids);
            } elseif ($table === $wpdb->commentmeta) {
                foreach ($affected_parent_ids as $comment_id) {
                    wp_cache_delete($comment_id, "comment_meta");
                    clean_comment_cache($comment_id);
                }
                $this->set_last_affected_comment_ids($affected_parent_ids);
            } elseif ($table === $wpdb->usermeta) {
                foreach ($affected_parent_ids as $user_id) {
                    wp_cache_delete($user_id, "user_meta");
                    clean_user_cache($user_id);
                }
                $this->set_last_affected_user_ids($affected_parent_ids);
            } elseif ($table === $wpdb->termmeta) {
                foreach ($affected_parent_ids as $term_id) {
                    wp_cache_delete($term_id, "term_meta");
                    clean_term_cache($term_id);
                }
                $this->set_last_affected_term_ids($affected_parent_ids);
            }
        }
        return $total_cleaned;
    }
    private function execute_meta_delete_batch(
        string $table_name,
        string $id_column,
        array $ids
    ): int {
        global $wpdb;
        $allowed = [
            $wpdb->postmeta => "meta_id",
            $wpdb->commentmeta => "meta_id",
            $wpdb->usermeta => "umeta_id",
            $wpdb->termmeta => "meta_id",
        ];
        if (
            !isset($allowed[$table_name]) ||
            $allowed[$table_name] !== $id_column
        ) {
            OPTISTATE_Utils::log_critical_error(
                "execute_meta_delete_batch rejected unauthorized table/column",
                ["table" => $table_name, "id_column" => $id_column]
            );
            return 0;
        }
        if (empty($ids)) {
            return 0;
        }
        $safe_ids = array_filter(array_map("absint", $ids), fn($id) => $id > 0);
        if (empty($safe_ids)) {
            return 0;
        }
        $chunk_size = 500;
        $total_deleted = 0;
        foreach (array_chunk($safe_ids, $chunk_size) as $id_chunk) {
            $placeholders = implode(",", array_fill(0, count($id_chunk), "%d"));
            try {
                $deleted = OPTISTATE_Utils::transaction(function () use (
                    $wpdb,
                    $table_name,
                    $id_column,
                    $id_chunk,
                    $placeholders
                ) {
                    $query = $wpdb->prepare(
                        "DELETE FROM {$table_name} WHERE {$id_column} IN ($placeholders)",
                        ...$id_chunk
                    );
                    $result = $wpdb->query($query);
                    if ($result === false) {
                        throw new \Exception(
                            "batch delete failed: " . $wpdb->last_error
                        );
                    }
                    return $result;
                });
            } catch (\Throwable $e) {
                OPTISTATE_Utils::log_critical_error(
                    "execute_meta_delete_batch interrupted",
                    [
                        "table" => $table_name,
                        "error" => $e->getMessage(),
                        "batch_size" => count($id_chunk),
                    ]
                );
                break;
            }
            $total_deleted += $deleted;
            usleep(10000);
        }
        return $total_deleted;
    }
    private function clean_duplicate_postmeta(): int
    {
        global $wpdb;
        return $this->clean_duplicate_meta(
            $wpdb->postmeta,
            "post_id",
            "meta_id",
            "Post meta"
        );
    }
    private function clean_duplicate_commentmeta(): int
    {
        global $wpdb;
        return $this->clean_duplicate_meta(
            $wpdb->commentmeta,
            "comment_id",
            "meta_id",
            "Comment meta"
        );
    }
    private function clean_duplicate_usermeta(): int
    {
        global $wpdb;
        return $this->clean_duplicate_meta(
            $wpdb->usermeta,
            "user_id",
            "umeta_id",
            "User meta"
        );
    }
    private function clean_duplicate_termmeta(): int
    {
        global $wpdb;
        return $this->clean_duplicate_meta(
            $wpdb->termmeta,
            "term_id",
            "meta_id",
            "Term meta"
        );
    }
}