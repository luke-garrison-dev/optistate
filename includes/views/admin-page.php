<?php if (!defined("ABSPATH")) { exit(); } ?>
<div class="wrap optistate-wrap">
    <div id="optistate-js-notices" class="optistate-js-notices"></div>
    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(OPTISTATE::PLUGIN_NAME); ?> Logo" class="optistate-logo">
    <div class="db-backup-wrap os-mt-neg-12">
        <div class="optistate-container">
            <div class="optistate-notice">
                <strong>ℹ️ <?php esc_html_e("Need Help?", "optistate"); ?></strong>
                <?php esc_html_e("Check out the full plugin manual for detailed instructions and best practices.", "optistate"); ?>
                <a href="<?php echo esc_url($manual_base); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e("READ THE MANUAL", "optistate"); ?> 📕
                </a>
            </div>

            <?php if ($server_type === "nginx"): ?>
                <div class="notice nginx-notice">
                    <h3 class="os-mt-0"><?php esc_html_e("🖳 Nginx Server Detected", "optistate"); ?></h3>
                    <p class="os-mb-5">
                        <?php esc_html_e("⚠️ Your server is running Nginx. Security rules must be configured manually (Important). Browser Caching also requires manual activation (Optional).", "optistate"); ?>
                    </p>
                    <p class="os-mb-0">
                        <?php esc_html_e("Please follow our", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-7-3-1"); ?>" target="_blank" rel="noopener noreferrer">
                            <strong><?php esc_html_e("Nginx Configuration Guide ⚙️", "optistate"); ?></strong>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper optistate-nav-tabs">
                <a href="#tab-dashboard" class="nav-tab"><span class="dashicons dashicons-dashboard"></span> <?php esc_html_e("Dashboard", "optistate"); ?></a>
                <a href="#tab-stats" class="nav-tab"><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e("Statistics", "optistate"); ?></a>
                <a href="#tab-backups" class="nav-tab"><span class="dashicons dashicons-database-export"></span> <?php esc_html_e("Backups", "optistate"); ?></a>
                <a href="#tab-cleanup" class="nav-tab"><span class="dashicons dashicons-trash"></span> <?php esc_html_e("Cleanup", "optistate"); ?></a>
                <a href="#tab-advanced" class="nav-tab"><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e("Advanced", "optistate"); ?></a>
                <a href="#tab-caching" class="nav-tab"><span class="dashicons dashicons-database"></span> <?php esc_html_e("Caching", "optistate"); ?></a>
                <a href="#tab-tweaks" class="nav-tab"><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e("Tweaks", "optistate"); ?></a>
                <a href="#tab-automation" class="nav-tab"><span class="dashicons dashicons-clock"></span> <?php esc_html_e("Schedule", "optistate"); ?></a>
                <a href="#tab-security" class="nav-tab"><span class="dashicons dashicons-shield"></span> <?php esc_html_e("Security", "optistate"); ?></a>
                <a href="#tab-settings" class="nav-tab"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e("Settings", "optistate"); ?></a>
            </h2>
            <div id="tab-dashboard" class="optistate-tab-content">
                <div class="optistate-grid-2">
                    <div class="optistate-card optistate-card-highlight">
                        <h2>
                            <span>💥 <?php esc_html_e("One-Click Optimization", "optistate"); ?></span>
                            <a href="<?php echo esc_url($manual_base . "#ch-5-3"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                        </h2>
                        <p>
                            <?php esc_html_e("Perform all safe optimizations with one click (db cleanup + table optimization).", "optistate"); ?><br>
                            <?php esc_html_e('Click "Optimize Now" to see what items will be deleted.', "optistate"); ?>
                        </p>
                        <button class="button button-primary button-hero optistate-one-click" id="optistate-one-click">🚀 <?php esc_html_e("Optimize Now", "optistate"); ?></button>
                        <div id="optistate-one-click-results" class="optistate-results"></div>
                        <button class="button" id="optistate-refresh-one-click" style="margin-top: 15px;">♻ <?php esc_html_e("Refresh Items", "optistate"); ?></button>
                    </div>
                    <div class="optistate-card optistate-health-dashboard">
                        <h2>
                            <span>📊 <?php esc_html_e("Database Health Score", "optistate"); ?></span>
                            <a href="<?php echo esc_url($manual_base . "#ch-5-1"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                        </h2>
                        <div id="optistate-health-score-loading" class="optistate-loading">🔎 <?php esc_html_e("Analyzing database health...", "optistate"); ?></div>
                        <div id="optistate-health-score-wrapper" class="os-display-none">
                            <div class="health-score-main">
                                <div class="health-score-circle">
                                    <div class="health-score-value" id="health-score-value">0</div>
                                    <div class="health-score-label"><?php esc_html_e("Overall Score", "optistate"); ?></div>
                                </div>
                                <div class="health-score-details">
                                    <div class="health-score-category"><span class="category-label">⚡️ <?php esc_html_e("Performance", "optistate"); ?></span><span class="category-score" id="health-score-performance">0</span></div>
                                    <div class="health-score-category"><span class="category-label">🧹 <?php esc_html_e("Cleanliness", "optistate"); ?></span><span class="category-score" id="health-score-cleanliness">0</span></div>
                                    <div class="health-score-category"><span class="category-label">🔋 <?php esc_html_e("Efficiency", "optistate"); ?></span><span class="category-score" id="health-score-efficiency">0</span></div>
                                </div>
                            </div>
                            <div class="health-score-recommendations">
                                <h4>⭐ <?php esc_html_e("Details & Recommendations", "optistate"); ?></h4>
                                <div id="health-score-recommendations-list"></div>
                            </div>
                            <button class="button" id="optistate-refresh-health-score">♻ <?php esc_html_e("Refresh Analysis", "optistate"); ?></button>
                        </div>
                    </div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2 class="optistate-targeted-header-wrapper">
                        <span>
                            🖥️ <?php esc_html_e("System Info", "optistate"); ?>
                            <a href="<?php echo esc_url($manual_base . "#ch-5-1-1"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>">
                                <span class="dashicons dashicons-info"></span>
                            </a>
                        </span>
                        <button type="button" class="button optistate-refresh-system-stats-btn button-small" id="optistate-refresh-system-stats-btn">
                            ♻ <?php esc_html_e("Refresh System Stats", "optistate"); ?>
                        </button>
                    </h2>
                    <div class="notice notice-warning inline optistate-system-stats-warning" style="margin: 0 0 15px 0; padding: 8px 12px;">
                        <p style="margin: 0; font-size: 13px;">
                            ⓘ <?php esc_html_e("Note: On shared hosting, disk space and RAM values may reflect the entire server, not your account quota. For accurate numbers, consult your hosting control panel.", "optistate"); ?>
                        </p>
                    </div>
                    <div id="optistate-system-stats-container" class="optistate-stats-full">
                        <div id="optistate-system-stats" class="optistate-stats">
                            <div class="optistate-loading"><?php esc_html_e("Loading system information...", "optistate"); ?></div>
                        </div>
                    </div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>
                        <span><span class="dashicons dashicons-dashboard"></span> <?php esc_html_e("Performance Metrics (PageSpeed)", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-7-5"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        🎚️ <?php esc_html_e("Analyze your site performance using Google PageSpeed Insights. Test different pages to identify bottlenecks.", "optistate"); ?><br>
                        🔑 <?php esc_html_e("If you need an API key, you can get it here:", "optistate"); ?>
                        <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" rel="noopener noreferrer">PageSpeed Insights API</a>.
                    </p>
                    <div class="optistate-grid-2 os-psi-grid-layout">
                        <div class="optistate-psi-controls">
                            <div>
                                <label for="optistate_pagespeed_key" class="os-label-block-bold-mb5"><?php esc_html_e("Google API Key (Optional but Recommended)", "optistate"); ?></label>
                                <div class="os-flex-gap-10">
                                    <div class="os-flex-1-relative">
                                        <input type="password" id="optistate_pagespeed_key" value="<?php echo esc_attr($plugin->settings_manager->get_pagespeed_api_key()); ?>" class="os-input-password-padded" placeholder="<?php esc_attr_e("Enter API Key", "optistate"); ?>">
                                        <span id="toggle-api-key-visibility" class="dashicons dashicons-visibility os-toggle-password-icon" title="<?php esc_attr_e("Show/Hide API Key", "optistate"); ?>"></span>
                                    </div>
                                    <button type="button" class="button" id="save-pagespeed-key-btn"><?php esc_html_e("Save Key", "optistate"); ?></button>
                                </div>
                                <p class="description os-mt-5"><?php esc_html_e("Without a key, tests may fail due to public rate limits.", "optistate"); ?></p>
                            </div>
                            <div class="os-mt-20">
                                <label for="optistate-test-url" class="os-label-block-bold-mb5"><?php esc_html_e("Page to Test", "optistate"); ?></label>
                                <select id="optistate-test-url" class="os-w100-mb10">
                                    <option value="">🏠 <?php esc_html_e("Homepage (Default)", "optistate"); ?></option>
                                    <optgroup label="<?php esc_attr_e("Recent Posts", "optistate"); ?>">
                                        <?php foreach ($recent_posts as $post) : ?>
                                            <option value="<?php echo esc_attr(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="<?php esc_attr_e("Pages", "optistate"); ?>">
                                        <?php foreach ($cached_pages as $page) : ?>
                                            <option value="<?php echo esc_attr(get_permalink($page->ID)); ?>"><?php echo esc_html($page->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <input type="text" id="optistate-custom-url" maxlength="1000" placeholder="<?php esc_attr_e("Or enter custom URL...", "optistate"); ?>" class="os-w100-mb10">
                            </div>
                            <div class="os-flex-center-gap-10">
                                <select id="optistate-strategy" class="os-h-36">
                                    <option value="mobile">📱 <?php esc_html_e("Mobile Device", "optistate"); ?></option>
                                    <option value="desktop">💻 <?php esc_html_e("Desktop", "optistate"); ?></option>
                                </select>
                                <?php
                                $last_state = get_option("optistate_pagespeed_last_state");
                                $last_strategy = isset($last_state["strategy"]) ? $last_state["strategy"] : "mobile";
                                ?>
                                <script> var optistate_last_strategy = <?php echo wp_json_encode($last_strategy); ?>; </script>
                                <button type="button" class="button button-primary button-large" id="run-pagespeed-btn">
                                    <span class="dashicons dashicons-performance optst-adt-icn"></span> <?php esc_html_e("Run Audit", "optistate"); ?>
                                </button>
                            </div>
                            <p class="os-last-checked">
                                <?php esc_html_e("Last checked:", "optistate"); ?> <span id="psi-timestamp"><?php esc_html_e("Never", "optistate"); ?></span><br>
                                <span id="psi-tested-url" class="os-color-link-blue"></span>
                            </p>
                        </div>
                        <div class="optistate-psi-score-wrapper">
                            <div class="optistate-score-circle" id="psi-score-circle">
                                <span id="psi-score">--</span>
                            </div>
                            <span class="optistate-psi-text">🚦 <?php esc_html_e("Performance Score", "optistate"); ?></span>
                        </div>
                    </div>
                    <div id="optistate-psi-metrics" class="optistate-grid-targeted os-psi-metrics-disabled">
                        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
                            <div class="targeted-header"><h4>FCP (First Contentful Paint)</h4></div>
                            <div class="targeted-stat os-font-11em-bold" id="psi-fcp">--</div>
                        </div>
                        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
                            <div class="targeted-header"><h4>LCP (Largest Contentful Paint)</h4></div>
                            <div class="targeted-stat os-font-11em-bold" id="psi-lcp">--</div>
                        </div>
                        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
                            <div class="targeted-header"><h4>CLS (Cumulative Layout Shift)</h4></div>
                            <div class="targeted-stat os-font-11em-bold" id="psi-cls">--</div>
                        </div>
                        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
                            <div class="targeted-header"><h4>TTFB (Time to First Byte)</h4></div>
                            <div class="targeted-stat os-font-11em-bold" id="psi-ttfb">--</div>
                        </div>
                        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
                            <div class="targeted-header"><h4>TBT (Total Blocking Time)</h4></div>
                            <div class="targeted-stat os-font-11em-bold" id="psi-tbt">--</div>
                        </div>
                        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
                            <div class="targeted-header"><h4>SI (Speed Index)</h4></div>
                            <div class="targeted-stat os-font-11em-bold" id="psi-si">--</div>
                        </div>
                        <div class="optistate-card optistate-targeted-card os-card-min-auto-p12">
                            <div class="targeted-header"><h4>TTI (Time to Interactive)</h4></div>
                            <div class="targeted-stat os-font-11em-bold" id="psi-tti">--</div>
                        </div>
                        <div class="os-psi-legend">
                            <span class="os-color-muted">🚦 COLOR KEY</span><br>
                            <span class="os-color-success">🟢 GOOD (90-100)</span><br>
                            <span class="os-color-average">🟠 AVERAGE (60-89)</span><br>
                            <span class="os-color-poor">🔴 POOR (0-59)</span>
                        </div>
                    </div>
                    <div id="optistate-psi-recommendations" class="os-mt-32 os-display-none">
                        <h3 class="os-psi-rec-header"><span class="dashicons dashicons-lightbulb os-color-link-blue"></span> <?php esc_html_e("Recommended Actions", "optistate"); ?></h3>
                        <div id="optistate-psi-recommendations-list"></div>
                    </div>
                </div>
            </div>
            <div id="tab-cleanup" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span>🧹 <?php esc_html_e("Detailed Database Cleanup", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-6"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        ⓘ <?php esc_html_e("Items marked with this symbol ⚠️ are not included in the one-click optimization and should be reviewed carefully before deletion.", "optistate"); ?><br>
                    </p>
                    <div class="optistate-cleanup-grid" id="optistate-cleanup-items"></div>
                    <div class="optistate-cleanup-actions">
                        <button type="button" class="button optistate-refresh-cleanup-btn">♻ <?php esc_html_e("Refresh Cleanup Data", "optistate"); ?></button>
                    </div>
                </div>
                <div class="optistate-card os-mt-20">
                    <h2>
                        <span><span class="dashicons dashicons-plugins-checked"></span> <?php esc_html_e("Legacy Plugin Data Scanner", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-4"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-mb-15-lh-relaxed">
                        🕵️ <?php esc_html_e("Detects data left behind by plugins/themes you have uninstalled.", "optistate"); ?><br>
                        📉 <?php esc_html_e('Removing "Ghost Data" can significantly reduce database size and slightly improve your site\'s speed.', "optistate"); ?><br>
                        🧩 <?php esc_html_e("Also run the Database Structure Analysis tool (Advanced tab) to identify and remove any additional unused tables.", "optistate"); ?>
                    </p>
                    <button type="button" class="button button-secondary os-mb-5" id="optistate-scan-legacy-btn">🔎 <?php esc_html_e("Scan for Ghost Data", "optistate"); ?></button>
                    <div id="optistate-legacy-results" class="os-display-none"></div>
                    <br><br><hr>
                    <div class="optistate-trash-section">
                        <h3>
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e("Trash", "optistate"); ?>
                            <span id="optistate-trash-count"></span>
                            - <?php esc_html_e("Recover Deleted Items", "optistate"); ?>
                        </h3>
                        <p class="os-mb-15">
                            🕓 <?php esc_html_e("Deleted folders/tables/options/metas are moved here and kept for 14 days. You can restore them if needed.", "optistate"); ?>
                        </p>
                        <div id="optistate-trash-list" class="os-mt-15"></div>
                    </div>
                </div>
            </div>
            <div id="tab-backups" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span><span class="dashicons dashicons-database-export"></span> <?php esc_html_e("Create a Database Backup", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-4-1"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        ✔ <?php esc_html_e("Always backup your database before performing cleanup operations.", "optistate"); ?><br>
                        🔄 <?php esc_html_e("You will be able to restore it if something goes wrong during cleanup.", "optistate"); ?><br>
                        💾 <?php printf(esc_html__("Backups are securely stored in your %s folder.", "optistate"), '<span class="os-code-highlight"><code>' . esc_html("/wp-content/uploads/" . OPTISTATE::BACKUP_DIR_NAME) . "</code></span>"); ?>
                    </p>
                    <div class="os-mb-15">
                        <label for="max_backups_setting" class="os-label-block-bold-mb5"><?php esc_html_e("Maximum Backups to Keep:", "optistate"); ?></label>
                        <select class="os-select-bold-w100" name="max_backups_setting" id="max_backups_setting">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo esc_attr($i); ?>" <?php selected($i, $max_backups_setting); ?>><?php echo esc_html($i); ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="button" class="button os-ml-10" id="save-max-backups-btn">✓ <?php esc_html_e("Save", "optistate"); ?></button>
                        <p class="os-mt-5-lh-relaxed">
                            ⚠️ <?php esc_html_e("Older backups will be automatically deleted when this limit is reached.", "optistate"); ?><br>
                            ℹ <?php esc_html_e("Backups consume space: If you have limited storage capacity, keep only one or two backups.", "optistate"); ?>
                        </p>
                    </div>
                    <button type="button" class="button button-large button-primary os-font-weight-500" id="create-backup-btn">↪ <?php esc_html_e("Create Backup Now", "optistate"); ?></button>
                </div>
                <div class="optistate-card os-mt-20">
                    <h2>
                        <span><span class="dashicons dashicons-database-view"></span> <?php esc_html_e("Manage Existing Backups", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-4-3"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e("Backup Name", "optistate"); ?></th>
                                <th><?php esc_html_e("Date Created", "optistate"); ?></th>
                                <th><?php esc_html_e("Size", "optistate"); ?></th>
                                <th><?php esc_html_e("Actions", "optistate"); ?></th>
                            </tr>
                        </thead>
                        <tbody id="backups-list">
                            <?php if (empty($backups)): ?>
                                <tr>
                                    <td colspan="4" class="db-backup-empty"><?php esc_html_e("No backups found. Create your first backup!", "optistate"); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($backups as $backup): ?>
                                    <tr data-file="<?php echo esc_attr($backup["filename"]); ?>" data-bytes="<?php echo esc_attr($backup["size_bytes"]); ?>" data-uncompressed-bytes="<?php echo esc_attr($backup["uncompressed_size"]); ?>">
                                        <td>
                                            <strong><?php echo esc_html($backup["filename"]); ?></strong>
                                            <div class="os-backup-meta-row">
                                                <?php if (!empty($backup["verified"])): ?>
                                                    <span class="db-backup-verified optistate-integrity-info os-cursor-pointer" data-status="verified">✓ <?php esc_html_e("Integrity", "optistate"); ?></span>
                                                <?php else: ?>
                                                    <span class="db-backup-unverified optistate-integrity-info os-cursor-pointer" data-status="unverified">⚠ <?php esc_html_e("Integrity", "optistate"); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($backup["tables_list"]) && is_array($backup["tables_list"])): ?>
                                                    <?php
                                                    $tables_json = wp_json_encode($backup["tables_list"]);
                                                    $table_count = count($backup["tables_list"]);
                                                    ?>
                                                    <span class="db-backup-tables os-cursor-pointer" data-tables="<?php echo esc_attr($tables_json); ?>" data-filename="<?php echo esc_attr($backup["filename"]); ?>" title="<?php esc_attr_e("Click to view tables", "optistate"); ?>">
                                                        𓊂 <?php echo number_format_i18n($table_count); ?> <?php esc_html_e("TABLES", "optistate"); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php
                                                $b_type = isset($backup["type"]) ? $backup["type"] : "MANUAL";
                                                $b_class = $b_type === "SCHEDULED" ? "optistate-type-scheduled" : "optistate-type-manual";
                                                $b_icon = $b_type === "SCHEDULED" ? "⏰" : "👤";
                                                ?>
                                                <span class="optistate-backup-type <?php echo esc_attr($b_class); ?>" title="<?php echo esc_attr($b_type === "MANUAL" ? __("Created manually by user", "optistate") : __("Created automatically by the system", "optistate")); ?>">
                                                    <?php echo $b_icon . " " . esc_html($b_type); ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html($backup["date"]); ?></td>
                                        <td><?php echo esc_html($backup["size"]); ?></td>
                                        <td>
                                            <button class="button download-backup" data-file="<?php echo esc_attr($backup["filename"]); ?>">
                                                <span class="dashicons dashicons-download"></span> <?php esc_html_e("Download", "optistate"); ?>
                                            </button>
                                            <button class="button restore-backup" data-file="<?php echo esc_attr($backup["filename"]); ?>">
                                                <span class="dashicons dashicons-backup"></span> <?php esc_html_e("Restore", "optistate"); ?>
                                            </button>
                                            <button class="button delete-backup" data-file="<?php echo esc_attr($backup["filename"]); ?>">
                                                <span class="dashicons dashicons-trash"></span> <?php esc_html_e("Delete", "optistate"); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="optistate-restore-recovery-notice" class="optistate-notice os-display-none os-mt-20">
                        <span class="os-color-danger-bold">ⓘ <?php esc_html_e("Restore process stuck?", "optistate"); ?></span>
                        <ol class="os-m-10-0-0-0">
                            <li><a href="<?php echo esc_url(admin_url("plugins.php")); ?>"><?php esc_html_e("Go to Plugins → Installed Plugins", "optistate"); ?></a></li>
                            <li><?php esc_html_e("Deactivate Optimal State", "optistate"); ?></li>
                            <li><?php esc_html_e("Reactivate the plugin", "optistate"); ?></li>
                        </ol>
                        <?php esc_html_e("This will safely abort the restore and remove maintenance mode.", "optistate"); ?>
                    </div>
                    <div class="optistate-restore-file-section">
                        <h3><span class="dashicons dashicons-upload os-font-20"></span> <?php esc_html_e("Restore Database from File", "optistate"); ?></h3>
                        <p class="os-line-height-relaxed">
                            📤 <?php esc_html_e("Restore your database by uploading a backup created by this plugin or phpMyAdmin (max. 5GB, .sql, .sql.gz).", "optistate"); ?><br>
                            <strong>ℹ️ <?php esc_html_e("Please Note: ", "optistate"); ?></strong><?php esc_html_e("This is not a website migration tool. It cannot replace your files, plugins, etc.", "optistate"); ?><br>
                            <strong>⚠️ <?php esc_html_e("Extreme Caution: ", "optistate"); ?></strong><?php esc_html_e("Restoring an incorrect or damaged database could ruin your website.", "optistate"); ?>
                        </p>
                        <div class="optistate-file-upload-area">
                            <input type="file" id="optistate-file-input" class="optistate-file-input" accept=".sql,.sql.gz">
                            <label for="optistate-file-input" class="optistate-file-label">
                                <span class="dashicons dashicons-upload"></span> <?php esc_html_e("Choose Backup File", "optistate"); ?>
                            </label>
                        </div>
                        <div id="optistate-file-info" class="optistate-file-info os-display-none">
                            <strong><?php esc_html_e("Selected:", "optistate"); ?></strong>
                            <span id="optistate-file-name"></span> (<span id="optistate-file-size"></span>)
                        </div>
                        <div id="optistate-upload-progress" class="optistate-upload-progress">
                            <div class="optistate-progress-bar">
                                <div class="optistate-progress-fill">0%</div>
                            </div>
                        </div>
                        <div id="restore-button-wrapper">
                            <button type="button" class="button button-large button-primary optistate-restore-file-btn" id="optistate-restore-file-btn">
                                <span class="dashicons dashicons-upload"></span> <?php esc_html_e("Restore from File", "optistate"); ?>
                            </button>
                        </div>
                    </div>
                    <div class="os-mt-20">
                        <label class="optistate-danger-zone">
                            <input type="checkbox" id="disable_restore_security" name="disable_restore_security" value="1" <?php checked($disable_security, true); ?> class="os-mt-2">
                            <div>
                                <strong class="os-danger-text-lg">🔓 <?php esc_html_e("Disable Restore Security Checks", "optistate"); ?></strong>
                                <div class="description os-m-5-0-0-0">
                                    <?php esc_html_e("Check this if your restore fails due to false positives (e.g., suspicious code or disallowed SQL queries).", "optistate"); ?><br>
                                    <div class="os-warning-text-danger">
                                        ⚠️ <?php esc_html_e("Warning: This disables the SQL Firewall and Malicious Code Scanner. Only use with trusted backup files.", "optistate"); ?>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div id="tab-stats" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span>📈 <?php esc_html_e("Database Statistics", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-2"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <div id="optistate-stats-loading" class="optistate-loading"><?php esc_html_e("Loading statistics...", "optistate"); ?></div>
                    <p class="os-line-height-relaxed">
                        💡 <?php esc_html_e("Review your database statistics before and after cleanup and optimization operations.", "optistate"); ?><br>
                    </p>
                    <div id="optistate-stats-container" class="optistate-stats-full">
                        <div id="optistate-stats" class="optistate-stats"></div>
                    </div>
                    <div id="optistate-db-size" class="optistate-db-size os-mt-20">
                        <strong><?php esc_html_e("Total Database Size:", "optistate"); ?></strong>
                        <span id="optistate-db-size-value"><?php esc_html_e("Calculating...", "optistate"); ?></span>
                    </div>
                    <button class="button optistate-refresh-health-score" id="optistate-refresh-stats">♻ <?php esc_html_e("Refresh Stats", "optistate"); ?></button>
                </div>
            </div>
            <div id="tab-caching" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span><span class="dashicons dashicons-database"></span> <?php esc_html_e("Caching Features", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-7-2"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <div id="optistate-caching-content-wrapper">
                        <p class="os-line-height-relaxed">
                            🗄️ <?php esc_html_e("Configure caching mechanisms to dramatically improve page load times and reduce server load.", "optistate"); ?><br>
                            ⚠️ <?php esc_html_e("Do not activate server‑side page caching if you already use a caching plugin (e.g., WP Rocket, LiteSpeed, WP Super Cache).", "optistate"); ?>
                        </p>
                        <div id="optistate-caching-features-loading" class="os-loading-padded-center">
                            <span class="spinner is-active"></span><span><?php esc_html_e("Loading caching features...", "optistate"); ?></span>
                        </div>
                        <div id="optistate-caching-features-container" class="os-display-none"></div>
                        <div class="optistate-features-actions">
                            <button type="button" class="button button-primary button-large optistate-save-perf-btn" id="save-caching-features-btn">✓ <?php esc_html_e("Save Caching Settings", "optistate"); ?></button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="tab-tweaks" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e("Tweak Features", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-7-4"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <div id="optistate-tweaks-content-wrapper">
                        <p class="os-line-height-relaxed">
                            🛠️ <?php esc_html_e("Fine‑tune various WordPress features to improve performance, reduce overhead, and enhance security.", "optistate"); ?><br>
                            <strong>⚠️ <?php esc_html_e("Important:", "optistate"); ?></strong> <?php esc_html_e("Some features may affect functionality. Features marked with ⚠️ should be tested carefully.", "optistate"); ?>
                        </p>
                        <div id="optistate-tweaks-features-loading" class="os-loading-padded-center">
                            <span class="spinner is-active"></span><span><?php esc_html_e("Loading tweak features...", "optistate"); ?></span>
                        </div>
                        <div id="optistate-tweaks-features-container" class="os-display-none"></div>
                        <div class="optistate-features-actions">
                            <button type="button" class="button button-primary button-large optistate-save-perf-btn" id="save-tweaks-features-btn">✓ <?php esc_html_e("Save Tweaks Settings", "optistate"); ?></button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="tab-advanced" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span>🗄️ <?php esc_html_e("Advanced Database Optimization", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-7"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        🔹 <?php esc_html_e("Optimize and repair database tables to improve performance.", "optistate"); ?><br>
                        <strong>‼️ <?php esc_html_e("Caution", "optistate"); ?>:</strong> <?php esc_html_e("These operations may make your website unresponsive for a few minutes, especially if your database is large and has never been optimized!", "optistate"); ?>
                    </p>
                    <div class="optistate-adv-optimize">
                        <button class="button optistate-refresh-stats optistate-advanced-op-btn" id="optistate-optimize-tables">⚡ <?php esc_html_e("Optimize All Tables", "optistate"); ?></button>
                        <button class="button optistate-refresh-stats optistate-advanced-op-btn" id="optistate-analyze-repair-tables">🛠️ <?php esc_html_e("Analyze & Repair Tables", "optistate"); ?></button>
                        <button class="button optistate-refresh-stats optistate-advanced-op-btn" id="optistate-optimize-autoload">⚙️ <?php esc_html_e("Optimize Autoloaded Options", "optistate"); ?></button>
                    </div>
                    <div class="optistate-adv-optimize os-mt-10" id="optistate-autoload-restore-container" style="<?php echo esc_attr($display_style); ?>">
                        <button class="button" id="optistate-restore-autoload-btn">↩️ <?php esc_html_e("Restore Autoload Backup", "optistate"); ?></button>
                        <span class="description" style="display:inline-block; vertical-align:middle;">
                            <?php esc_html_e("Issues after optimizing autoloaded options?", "optistate"); ?><br>
                            <?php printf(esc_html__("Restore %s previously disabled autoloaded options.", "optistate"), '<span id="optistate-autoload-restore-count">' . number_format_i18n($backup_count) . '</span>'); ?>
                        </span>
                    </div>
                    <div id="optistate-table-results" class="optistate-results"></div>
                    <div class="os-mt-15">
                        <p class="os-line-height-relaxed">
                            <strong>⚡ <?php esc_html_e("Optimize Tables", "optistate"); ?>:</strong> <?php esc_html_e("Runs OPTIMIZE TABLE on all database tables to reclaim space and improve query speed.", "optistate"); ?><br>
                            <strong>🛠️ <?php esc_html_e("Analyze & Repair", "optistate"); ?>:</strong> <?php esc_html_e("Checks tables for errors/corruption (CHECK TABLE), then runs REPAIR TABLE to fix issues.", "optistate"); ?><br>
                            <strong>⚙️ <?php esc_html_e("Autoloaded Options", "optistate"); ?>:</strong> <?php esc_html_e("Identifies large autoloaded options and sets them to non-autoload to boost site speed.", "optistate"); ?>
                        </p>
                    </div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>🧩 <?php esc_html_e("Database Structure Analysis", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-8"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-mb-15-lh-relaxed">
                        💡 <?php esc_html_e("Understand the architecture of your WordPress database better.", "optistate"); ?><br>
                        ✓ <?php esc_html_e("Core WordPress tables are explained in detail.", "optistate"); ?><br>
                        ⚠️ <?php esc_html_e("Third-party tables (from plugins/themes) are highlighted.", "optistate"); ?>
                    </p>
                    <button type="button" class="button button-secondary os-mb-15" id="optistate-analyze-tables-btn">🔎 <?php esc_html_e("Analyze Database Structure", "optistate"); ?></button>
                    <div id="optistate-table-analysis-loading" class="os-loading-block-centered">
                        <span class="spinner is-active"></span><span>⏳ <?php esc_html_e("Analyzing database structure...", "optistate"); ?></span>
                    </div>
                    <div id="optistate-table-analysis-results" class="os-display-none"></div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>🔢 <?php esc_html_e("MySQL Index Manager", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-9"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-mb-15-lh-relaxed">
                        ⊹ <?php esc_html_e("Scans your database for missing high-impact indexes that can drastically improve query performance.", "optistate"); ?><br>
                        ⏲ <?php esc_html_e("Adding missing indexes to columns like `autoload` can reduce query time by up to 90%.", "optistate"); ?>
                    </p>
                    <button type="button" class="button button-secondary os-mb-15" id="optistate-analyze-indexes-btn">🔎 <?php esc_html_e("Scan for Missing Indexes", "optistate"); ?></button>
                    <div id="optistate-index-analysis-loading" class="os-loading-padded">
                        <span class="spinner is-active os-spinner-reset"></span> <?php esc_html_e("Analyzing database schema...", "optistate"); ?>
                    </div>
                    <div id="optistate-index-results" class="os-display-none"></div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>🛡️ <?php esc_html_e("Referential Integrity Scanner", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-10"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-mb-15-lh-relaxed">
                        👻 <?php esc_html_e('Finds "Zombie Data": Rows in your database that point to content that no longer exists.', "optistate"); ?><br>
                        🔗 <?php esc_html_e("Example: Post Meta pointing to a Post ID that was deleted years ago. Standard cleanup often misses these.", "optistate"); ?>
                    </p>
                    <div class="os-flex-gap-10-mb15">
                        <button type="button" class="button button-secondary" id="optistate-run-integrity-scan">🔎 <?php esc_html_e("Scan Database Integrity", "optistate"); ?></button>
                        <span id="optistate-integrity-loading" class="os-display-none">
                            <span class="spinner is-active os-spinner-reset"></span> <?php esc_html_e("Scanning relationships...", "optistate"); ?>
                        </span>
                    </div>
                    <div id="optistate-integrity-results" class="os-display-none"></div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>↳↰ <?php esc_html_e("Database Search & Replace", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-5-11"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <div class="notice notice-warning inline os-warning-inline">
                        <p><strong>⚠️ <?php esc_html_e("Advanced Feature - Use with Caution", "optistate"); ?></strong></p>
                        <p>
                            <?php esc_html_e("This tool searches your database for specific text and replaces it. It handles WordPress serialized data correctly.", "optistate"); ?><br>
                            <?php esc_html_e("1. Always perform a Dry Run first to see what will be changed.", "optistate"); ?><br>
                            <?php esc_html_e("2. Create a fresh database backup before replacing.", "optistate"); ?>
                        </p>
                    </div>
                    <div class="optistate-sr-inputs-wrapper">
                        <div class="optistate-sr-input-group">
                            <label for="optistate-sr-search" class="sr-search"><?php esc_html_e("Search For:", "optistate"); ?></label>
                            <input type="text" id="optistate-sr-search" class="sr-search-2" placeholder="e.g. http://old-domain.com" maxlength="600">
                        </div>
                        <div class="optistate-sr-input-group">
                            <label for="optistate-sr-replace" class="sr-search"><?php esc_html_e("Replace With:", "optistate"); ?></label>
                            <input type="text" id="optistate-sr-replace" class="sr-search-2" placeholder="e.g. https://new-domain.com" maxlength="4096">
                        </div>
                    </div>
                    <div class="os-mt-15">
                        <label class="os-cursor-pointer"><input type="checkbox" id="optistate-sr-case-sensitive" class="os-mr-4"><span class="os-font-weight-600">🔎 <?php esc_html_e("Case Sensitive", "optistate"); ?></span></label>
                        <p class="description os-sr-desc-indent"><?php esc_html_e('If checked, "Apple" will not match "apple". Recommended for specific code replacements.', "optistate"); ?></p>
                    </div>
                    <div class="os-mt-10">
                        <label class="os-cursor-pointer"><input type="checkbox" id="optistate-sr-partial-match" class="os-mr-4"><span class="os-font-weight-600">🧩 <?php esc_html_e("Partial Match", "optistate"); ?></span></label>
                        <p class="description os-sr-desc-indent">
                            <?php esc_html_e('If checked, searches for partial text anywhere in strings (e.g., "http://" in URLs). If unchecked, only matches complete words with boundaries.', "optistate"); ?><br>
                            <?php esc_html_e('ⓘ Usage example: "http://" ⮕ "https://"', "optistate"); ?>
                        </p>
                    </div>
                    <div class="os-mt-15">
                        <label for="optistate-sr-tables" class="sr-tables"><?php esc_html_e("Select Tables (Optional):", "optistate"); ?></label>
                        <select id="optistate-sr-tables" multiple class="sr-tables-list">
                            <option value="all" selected><?php esc_html_e("-- All Tables --", "optistate"); ?></option>
                            <?php
                            $sr_tables = $plugin->search_replace_engine->get_selectable_tables();
                            foreach ($sr_tables as $sr_table) {
                                echo '<option value="' . esc_attr($sr_table) . '">' . esc_html($sr_table) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple specific tables. Leave as "All Tables" for a full site update.', "optistate"); ?></p>
                    </div>
                    <div class="optistate-sr-actions">
                        <button type="button" class="button button-secondary button-large" id="optistate-sr-dry-run">🔎️️ <?php esc_html_e("Perform Dry Run (Preview)", "optistate"); ?></button>
                        <button type="button" class="button button-primary button-large" id="optistate-sr-execute" disabled>↳↰ <?php esc_html_e("Execute Replacement", "optistate"); ?></button>
                        <span id="optistate-sr-loading" class="os-sr-loading-span">
                            <span class="spinner is-active os-spinner-reset"></span><span class="sr-status-text"><?php esc_html_e("Processing...", "optistate"); ?></span>
                        </span>
                    </div>
                    <div id="optistate-sr-results" class="os-mt-20-hidden"></div>
                </div>
            </div>
            <div id="tab-automation" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span><span class="dashicons dashicons-clock"></span> <?php esc_html_e("Automatic Backup and Cleanup", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-6"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <div id="optistate-auto-settings-form">
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e("Run Tasks Automatically Every", "optistate"); ?></th>
                                <td>
                                    <input type="number" class="os-input-days" id="auto_optimize_days" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[auto_optimize_days]" value="<?php echo esc_attr($auto_optimize_days); ?>" min="0" max="365">
                                    <strong><?php esc_html_e("DAYS", "optistate"); ?></strong>
                                    <?php esc_html_e("(0 to disable)", "optistate"); ?>
                                    <span class="os-ml-15">
                                        <?php esc_html_e("at", "optistate"); ?>
                                        <select class="os-select-time" id="auto_optimize_time" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[auto_optimize_time]">
                                            <?php for ($hour = 0; $hour < 24; $hour++):
                                                $time_value = sprintf("%02d:00", $hour);
                                                $time_display = wp_date("g:i A", OPTISTATE_Utils::local_time_to_timestamp($time_value));
                                            ?>
                                                <option value="<?php echo esc_attr($time_value); ?>" <?php selected($time_value, $auto_optimize_time); ?>><?php echo esc_html($time_display); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </span>
                                    <p class="os-line-height-relaxed">
                                        <span id="auto-status-enabled" style="<?php echo $auto_optimize_days > 0 ? "" : "display:none;"; ?>">
                                            <?php
                                            $task_description = $auto_backup_only ? __("Automated *backup only*", "optistate") : __("Automated *backup & cleanup*", "optistate");
                                            printf(
                                                esc_html__('%1$s is enabled and will run every %2$d days at %3$s.', "optistate"),
                                                esc_html($task_description),
                                                absint($auto_optimize_days),
                                                esc_html(wp_date("g:i A", OPTISTATE_Utils::local_time_to_timestamp($auto_optimize_time)))
                                            );
                                            ?>
                                        </span>
                                        <span id="auto-status-disabled" style="<?php echo $auto_optimize_days > 0 ? "display:none;" : ""; ?>">
                                            🔴 <?php esc_html_e("Automated optimization is currently disabled.", "optistate"); ?>
                                        </span><br>
                                        <span id="auto-task-desc-full" style="<?php echo $auto_backup_only ? "display:none;" : ""; ?>">
                                            ℹ️ <?php esc_html_e("When enabled, the following tasks will be performed regularly:", "optistate"); ?> ➜ <?php esc_html_e("Database Backup", "optistate"); ?> ➜ <?php esc_html_e("One-Click Optimization.", "optistate"); ?><br>
                                            💡<?php esc_html_e("Tip: Choose a time when website traffic is usually lower.", "optistate"); ?>
                                        </span>
                                        <span id="auto-task-desc-backup-only" style="<?php echo $auto_backup_only ? "" : "display:none;"; ?>">
                                            ℹ️ <?php esc_html_e("When enabled, the following tasks will be performed regularly:", "optistate"); ?> ➜ <?php esc_html_e("Database Backup.", "optistate"); ?><br>
                                            💡<?php esc_html_e("Tip: Choose a time when website traffic is usually lower.", "optistate"); ?>
                                        </span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e("Backup Only", "optistate"); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="auto_backup_only" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[auto_backup_only]" value="1" <?php checked($auto_backup_only, true); ?>>
                                        <strong><?php esc_html_e("Perform database backup ONLY (skip cleanup)", "optistate"); ?></strong>
                                    </label>
                                    <p class="os-line-height-relaxed">
                                        <span id="auto-backup-only-status">
                                            <?php if ($auto_backup_only): ?>
                                                ✅ <?php esc_html_e("Backup Only mode is enabled.", "optistate"); ?>
                                            <?php else: ?>
                                                ℹ️ <?php esc_html_e("Backup & Cleanup mode is enabled.", "optistate"); ?>
                                            <?php endif; ?>
                                        </span><br>
                                        <?php esc_html_e("If checked, the scheduled task will only create a database backup. The automatic cleanup will be skipped.", "optistate"); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e("Email Notifications", "optistate"); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="email_notifications" name="<?php echo esc_attr(OPTISTATE::OPTION_NAME); ?>[email_notifications]" value="1" <?php checked($email_notifications, true); ?>>
                                        <?php esc_html_e("Send completion email with backup and cleanup details", "optistate"); ?>
                                    </label>
                                    <p class="os-line-height-relaxed">
                                        <?php if ($email_notifications): ?>
                                            <span id="email-status-enabled"> ✅ <?php esc_html_e("Email notifications are enabled.", "optistate"); ?></span>
                                            <span id="email-status-disabled" class="os-display-none"> 🔴 <?php esc_html_e("Email notifications are disabled.", "optistate"); ?></span>
                                        <?php else: ?>
                                            <span id="email-status-enabled" class="os-display-none"> ✅ <?php esc_html_e("Email notifications are enabled.", "optistate"); ?></span>
                                            <span id="email-status-disabled"> 🔴 <?php esc_html_e("Email notifications are disabled.", "optistate"); ?></span>
                                        <?php endif; ?>
                                        <br>
                                        📧 <?php printf(esc_html__("Notifications will be sent to: %s", "optistate"), "<strong>" . esc_html(get_option("admin_email")) . "</strong>"); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" class="button button-primary" id="save-auto-optimize-btn">✓ <?php esc_html_e("Save Settings", "optistate"); ?></button>
                    </div>
                </div>
                <div class="optistate-card os-mt-20">
                    <div class="optistate-targeted-header-wrapper" style="display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="margin: 0;"><span class="dashicons dashicons-backup"></span> <?php esc_html_e("Activity Log", "optistate"); ?></h2>
                        <div>
                            <button type="button" class="button button-secondary button-small" id="optistate-refresh-log-btn" title="<?php esc_attr_e("Refresh Activity Log", "optistate"); ?>">♻ <?php esc_html_e("Refresh Logs", "optistate"); ?></button>
                            <button type="button" class="button button-secondary button-small" id="optistate-download-log-btn" title="<?php esc_attr_e("Download Activity Log (JSON format)", "optistate"); ?>">⬇ <?php esc_html_e("Download Log", "optistate"); ?></button>
                        </div>
                    </div>
                    <div id="optistate-settings-log"></div>
                </div>
            </div>
            <div id="tab-security" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2>
                        <span class="dashicons dashicons-shield"></span> <?php esc_html_e("Login Page Protection", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-9-4"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        <?php esc_html_e("Protect your login page from brute-force attacks by blocking users and bots after failed login attempts.", "optistate"); ?><br>
                        🕗 <?php esc_html_e("During the lockout period, users/bots will not be able to access the login page.", "optistate"); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e("Enable Protection", "optistate"); ?></th>
                            <td><label><input type="checkbox" id="login_protect_enabled" <?php checked($login_enabled); ?>> 🔒 <?php esc_html_e("Activate login limiting", "optistate"); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e("Max Failed Attempts", "optistate"); ?></th>
                            <td>
                                <select id="login_protect_max_attempts" class="os-w100-mb10" style="max-width: 200px;">
                                    <?php
                                    $attempt_options = [
                                        1 => "1",
                                        2 => "2",
                                        3 => __("3 (Recommended)", "optistate"),
                                        4 => "4",
                                        5 => "5",
                                        6 => "6",
                                    ];
                                    foreach ($attempt_options as $num => $label): ?>
                                        <option value="<?php echo esc_attr($num); ?>" <?php selected($max_attempts, $num); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select><br>
                                <span class="description"><?php esc_html_e("Number of failed attempts allowed before blocking.", "optistate"); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e("Block Duration", "optistate"); ?></th>
                            <td>
                                <select id="login_protect_block_duration" class="os-w100-mb10" style="max-width: 200px;">
                                    <?php foreach ([1, 3, 6, 12, 24, 48] as $hours): ?>
                                        <option value="<?php echo esc_attr($hours); ?>" <?php selected($block_duration, $hours); ?>>
                                            <?php echo esc_html(sprintf(_n("%d Hour", "%d Hours", $hours, "optistate"), $hours)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select><br>
                                <span class="description"><?php esc_html_e('How long the user/bot won\'t be able to access the login page.', "optistate"); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e("Cloudflare Compatibility", "optistate"); ?></th>
                            <td><label><input type="checkbox" class="optistate-checkbox-label" id="login_cloudflare_enabled" <?php checked(!empty($settings["cloudflare_enabled"])); ?>> 🌐 <?php esc_html_e("Cloudflare DNS", "optistate"); ?></label>
                                <div style="margin-top: 6px;"><span class="description"><?php esc_html_e("Enable if your site is behind Cloudflare to accurately detect visitor IP addresses.", "optistate"); ?></span></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e("Enable Login Captcha", "optistate"); ?></th>
                            <td><label><input type="checkbox" id="login_captcha_enabled" <?php checked($settings["login_captcha_enabled"] ?? false); ?>> 🧮 <?php esc_html_e("Require math challenge", "optistate"); ?></label>
                                <p><span class="description"><?php esc_html_e("Add a simple arithmetic question to the login form. Bots will fail to answer correctly, blocking automated attacks.", "optistate"); ?><br> <?php esc_html_e("⤷ This feature will work when", "optistate"); ?> <strong><?php esc_html_e("🔒 Activate login limiting", "optistate"); ?></strong> <?php esc_html_e("is left unchecked. You can enable both or just this one.", "optistate"); ?></span></p>
                            </td>
                        </tr>
                    </table>
                    <button type="button" class="button button-primary" id="optistate-save-login-btn">✓ <?php esc_html_e("Save Login Settings", "optistate"); ?></button>
                    <?php
                    global $wpdb;
                    if ($table_exists) {
                        $now = time();
                        $login_active = !empty($settings["login_protect_enabled"]);
                        $ip_blocker_active = !empty($settings["ip_blocker_enabled"]);
                        if (!$login_active && !$ip_blocker_active) {
                            echo '<br><br><hr style="width:40%;"><h3 class="os-mt-33">📊 ' . esc_html__("Protection Statistics", "optistate") . "</h3>";
                            echo "<em>" . esc_html__("Both Login Page Protection and IP Number Blocker are currently turned off. No active blocks are being enforced.", "optistate") . "</em>";
                        } else {
                            $stats = [];
                            $blocked_users = [];
                            $where_conditions = [];
                            $query_params = [];
                            if ($login_active && $ip_blocker_active) {
                                $where_conditions[] = "blocked_until > %d";
                                $query_params[] = $now;
                            } elseif ($login_active) {
                                $where_conditions[] = "attempts_count >= 0 AND blocked_until > %d";
                                $query_params[] = $now;
                            } elseif ($ip_blocker_active) {
                                $where_conditions[] = "attempts_count = -1 AND blocked_until > %d";
                                $query_params[] = $now;
                            }
                            if (!empty($where_conditions)) {
                                $where_sql = implode(" AND ", $where_conditions);
                                $sql = $wpdb->prepare(
                                    "SELECT ip_address, user_agent, blocked_until, updated_at, attempts_count FROM $table_name WHERE $where_sql ORDER BY updated_at DESC LIMIT 20",
                                    ...$query_params
                                );
                                $blocked_users = $wpdb->get_results($sql);
                            }
                            if ($login_active) {
                                $total_blocked = (int) get_option("optistate_total_blocked_count", 0);
                                $baseline = (int) get_option("optistate_blocked_24h_baseline", $total_blocked);
                                $baseline_ts = (int) get_option("optistate_blocked_24h_baseline_ts", 0);
                                $last_24h = ($baseline_ts > 0 && $now - $baseline_ts < DAY_IN_SECONDS) ? max(0, $total_blocked - $baseline) : 0;
                                $stats["login"] = [
                                    "total_blocked" => $total_blocked,
                                    "last_24h" => $last_24h,
                                ];
                            }
                            if ($ip_blocker_active) {
                                $site_blocked_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE attempts_count = -1");
                                $last_blocked_ip = $wpdb->get_var("SELECT ip_address FROM $table_name WHERE attempts_count = -1 ORDER BY updated_at DESC LIMIT 1");
                                $whitelist_count = count($whitelist);
                                $last_whitelisted_ip = !empty($whitelist) ? end($whitelist) : "";
                                $stats["ip_blocker"] = [
                                    "total_blocked" => $site_blocked_total,
                                    "last_blocked_ip" => $last_blocked_ip,
                                    "whitelist_count" => $whitelist_count,
                                    "last_whitelisted_ip" => $last_whitelisted_ip,
                                ];
                            }
                            if (!empty($stats)) {
                                echo '<br><br><hr style="width:40%;"><h3 class="os-mt-33">📊 ' . esc_html__("Protection Statistics", "optistate") . "</h3>";
                                echo '<div class="optistate-stats">';
                                if (isset($stats["login"])) {
                                    echo '<div class="optistate-stat-item">';
                                    echo '<div class="optistate-stat-label">' . esc_html__("Login Page » Blocked Users", "optistate") . "</div>";
                                    echo '<div class="optistate-stat-value">' . number_format_i18n($stats["login"]["total_blocked"]) . "</div>";
                                    echo "</div>";
                                    echo '<div class="optistate-stat-item">';
                                    echo '<div class="optistate-stat-label">' . esc_html__("Login Page » Last 24 Hours", "optistate") . "</div>";
                                    echo '<div class="optistate-stat-value">' . number_format_i18n($stats["login"]["last_24h"]) . "</div>";
                                    echo "</div>";
                                }
                                if (isset($stats["ip_blocker"])) {
                                    echo '<div class="optistate-stat-item">';
                                    echo '<div class="optistate-stat-label">' . esc_html__("Entire Website » Blocked IPs", "optistate") . "</div>";
                                    echo '<div class="optistate-stat-value">' . number_format_i18n($stats["ip_blocker"]["total_blocked"]) . "</div>";
                                    echo "</div>";
                                    echo '<div class="optistate-stat-item">';
                                    echo '<div class="optistate-stat-label">' . esc_html__("Entire Website » Last Blocked IP", "optistate") . "</div>";
                                    echo '<div class="optistate-stat-value">' . esc_html($stats["ip_blocker"]["last_blocked_ip"] ?: __("None", "optistate")) . "</div>";
                                    echo "</div>";
                                    echo '<div class="optistate-stat-item">';
                                    echo '<div class="optistate-stat-label">' . esc_html__("Entire Website » Whitelisted IPs", "optistate") . "</div>";
                                    echo '<div class="optistate-stat-value">' . number_format_i18n($stats["ip_blocker"]["whitelist_count"]) . "</div>";
                                    echo "</div>";
                                    echo '<div class="optistate-stat-item">';
                                    echo '<div class="optistate-stat-label">' . esc_html__("Entire Website » Last Whitelisted IP", "optistate") . "</div>";
                                    echo '<div class="optistate-stat-value">' . esc_html($stats["ip_blocker"]["last_whitelisted_ip"] ?: __("None", "optistate")) . "</div>";
                                    echo "</div>";
                                }
                                echo "</div>";
                            }
                            echo '<h4 class="os-mt-33">🚫 ' . esc_html__("Active Blocks (Last 20)", "optistate") . "</h4>";
                            echo '<div id="optistate-blocked-users-list">';
                            if (empty($blocked_users)) {
                                echo '<p class="description os-p-11"><em>' . esc_html__("No active blocks found for the currently enabled protection features.", "optistate") . "</em></p>";
                            } else {
                                echo '<table class="wp-list-table widefat fixed striped">';
                                echo "<thead><tr>";
                                echo "<th>" . esc_html__("IP Address", "optistate") . "</th>";
                                echo "<th>" . esc_html__("User Agent", "optistate") . "</th>";
                                echo "<th>" . esc_html__("Blocked At", "optistate") . "</th>";
                                echo "<th>" . esc_html__("Expires In", "optistate") . "</th>";
                                echo "<th>" . esc_html__("Action", "optistate") . "</th>";
                                echo "</tr></thead><tbody>";
                                foreach ($blocked_users as $user) {
                                    $is_permanent = isset($user->attempts_count) && (int) $user->attempts_count === -1;
                                    if ($is_permanent) {
                                        $expiry_text = __("Never", "optistate");
                                        $block_type_label = __("🛑 Entire Website", "optistate");
                                    } else {
                                        $expires_in = max(0, $user->blocked_until - $now);
                                        $hours = floor($expires_in / 3600);
                                        $minutes = floor(($expires_in % 3600) / 60);
                                        $expiry_text = sprintf("%dh %dm", $hours, $minutes);
                                        $block_type_label = __("🔐 Login Page", "optistate");
                                    }
                                    echo "<tr>";
                                    echo "<td><code>" . esc_html($user->ip_address) . "</code>";
                                    if (!empty($whitelist)) {
                                        $is_whitelisted = in_array($user->ip_address, $whitelist, true);
                                        if (!$is_whitelisted) {
                                            foreach ($whitelist as $range) {
                                                if (strpos($range, "/") !== false && OPTISTATE_Utils::ip_in_range($user->ip_address, $range)) {
                                                    $is_whitelisted = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if ($is_whitelisted) {
                                            echo '<br><small style="color:green;">' . esc_html__("Currently Whitelisted", "optistate") . "</small>";
                                        }
                                    }
                                    echo "</td>";
                                    echo "<td>" . esc_html(mb_substr($user->user_agent, 0, 100)) . (mb_strlen($user->user_agent) > 100 ? "..." : "") . "</td>";
                                    echo "<td>" . (isset($user->updated_at) ? esc_html(OPTISTATE_Utils::format_timestamp($user->updated_at)) : "—") . '<br><span class="os-block-type-label" style="font-size: 0.9em; opacity: 0.8;">' . esc_html($block_type_label) . "</span></td>";
                                    echo '<td><span class="os-color-danger-bold">' . esc_html($expiry_text) . "</span></td>";
                                    echo '<td><button type="button" class="button button-small optistate-unblock-user" data-ip="' . esc_attr($user->ip_address) . '">' . esc_html__("Unblock", "optistate") . "</button></td>";
                                    echo "</tr>";
                                }
                                echo "</tbody></table>";
                            }
                            echo "</div>";
                        }
                    }
                    ?>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>🛑 <?php esc_html_e("IP Number Blocker", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-9-5"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        <?php esc_html_e("Block specific IP addresses from accessing your site entirely.", "optistate"); ?><br>
                        ℹ️️ <?php esc_html_e("You can activate this feature alongside Login Page Protection to shield the whole site, not just the login page.", "optistate"); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e("Enable IP Blocker", "optistate"); ?></th>
                            <td><label><input type="checkbox" id="ip_blocker_enabled" <?php checked($ip_blocker_enabled); ?>> 🚧 <?php esc_html_e("Activate site-wide IP blocking", "optistate"); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e("Blocked IP Addresses", "optistate"); ?></th>
                            <td>
                                <div class="os-p-8-12-warning os-mb-10">
                                    <strong><?php esc_html_e("Your Current IP:", "optistate"); ?></strong>
                                    <code style="background:rgba(0,0,0,0.05); padding:1px 4px; word-break:break-all;"><?php echo esc_html($current_ip); ?></code><br>
                                    <span style="color:#d63638;">⚠ <?php esc_html_e("To avoid locking yourself out of the entire site, ensure this IP is NOT in the list below.", "optistate"); ?></span><br>
                                    <span style="font-size: small">🛈 <?php esc_html_e("You can manually add it to the whitelist for added security.", "optistate"); ?></span>
                                </div>
                                <textarea id="optistate_ip_block_list" class="os-textarea-full" rows="5" placeholder="192.168.1.1&#10;192.168.2.0/24&#10;2001:db8::/32"><?php echo esc_textarea($ip_list_string); ?></textarea>
                                <p class="description"><?php esc_html_e("Enter one IP address or CIDR subnet per line. Supports exact IPv4/IPv6 addresses and network ranges (e.g., 192.168.1.0/24).", "optistate"); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e("Whitelisted IP Addresses", "optistate"); ?></th>
                            <td>
                                <textarea id="optistate_ip_whitelist" class="os-textarea-full" rows="4" placeholder="192.168.1.1&#10;192.168.2.0/24"><?php echo esc_textarea(implode("\n", $settings["ip_whitelist"] ?? [])); ?></textarea>
                                <p class="description"><?php esc_html_e("Enter one IP address or CIDR subnet per line. These IPs will never be blocked by either the Login Page Protection and the IP Number Blocker tools.", "optistate"); ?></p>
                            </td>
                        </tr>
                    </table>
                    <button type="button" class="button button-primary" id="optistate-save-ip-blocker-btn">✓ <?php esc_html_e("Save IP Blocker Settings", "optistate"); ?></button>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e("Two-Factor Authentication (2FA)", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-9-6"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        <?php esc_html_e("Add an extra layer of security to your login by requiring a time-based one-time password (TOTP) from an authenticator app (e.g., Google Authenticator, Authy).", "optistate"); ?><br>
                        <?php
                        printf(
                            esc_html__("When enabled, users can activate 2FA from their %s.", "optistate"),
                            '<a href="' . esc_url($profile_url) . '">' . esc_html__("profile page", "optistate") . "</a>"
                        );
                        ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e("Enable 2FA", "optistate"); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="optistate_enable_two_factor" <?php checked($settings["enable_two_factor"] ?? false); ?>> 🔑 <?php esc_html_e("Allow users to enable two-factor authentication", "optistate"); ?>
                                </label>
                                <p class="description"><?php esc_html_e("This globally enables the 2FA feature. Each user must then activate it individually in their profile.", "optistate"); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="description"><?php esc_html_e("ℹ️ If a user loses access to their authenticator app, an administrator can reset 2FA for that account from the user's own profile page.", "optistate"); ?></p>
                    <br>
                    <button type="button" class="button button-primary" id="optistate-save-two-factor-btn">✓ <?php esc_html_e("Save 2FA Settings", "optistate"); ?></button>
                </div>
            </div>
            <div id="tab-settings" class="optistate-tab-content">
                <div class="optistate-card">
                    <h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e("User Access Control", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-9-3"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed">
                        <?php esc_html_e("Enable only selected administrators to use the plugin. If no users are selected, all admins can use the plugin.", "optistate"); ?><br>
                        <strong>⚠️ <?php esc_html_e("Warning:", "optistate"); ?></strong> <?php esc_html_e("Do not lock yourself out! Always include your own account in the list.", "optistate"); ?>
                    </p>
                    <div class="optistate-admin-access">
                        <?php if (empty($admin_users)): ?>
                            <p><?php esc_html_e("No administrator accounts found.", "optistate"); ?></p>
                        <?php else: ?>
                            <?php foreach ($admin_users as $user): ?>
                                <label class="optistate-admin-list <?php echo $user->ID === $current_user_id ? "os-current-user-border" : ""; ?>">
                                    <input type="checkbox" class="optistate-allowed-user os-mr-8" value="<?php echo esc_attr($user->ID); ?>" <?php checked(in_array((int) $user->ID, array_map("intval", $allowed_users), true)); ?>>
                                    <strong><?php echo esc_html($user->display_name); ?></strong>
                                    <span class="os-color-muted"> (<?php echo esc_html($user->user_login); ?>) <?php if ($user->ID === $current_user_id): ?> <span class="os-current-user-tag">← <?php esc_html_e("You", "optistate"); ?></span> <?php endif; ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="os-tip-text">💡 <?php esc_html_e("Tip: Leave all checkboxes unchecked to allow all administrators.", "optistate"); ?></p>
                    <button type="button" class="button button-primary" id="optistate-save-user-access-btn">✓ <?php esc_html_e("Save User Access Settings", "optistate"); ?></button>
                    <div id="optistate-user-access-status" class="os-mt-10-mb-neg-10"></div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2><span class="dashicons dashicons-insert"></span> <?php esc_html_e("One-Click Optimization Configuration", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-9-7"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed"> 💥 <?php esc_html_e("Select additional cleanup items to include in the One-Click Optimization process. Default items ✓ are always included and cannot be removed.", "optistate"); ?> </p>
                    <div id="optistate-one-click-extra-items-container">
                        <div class="optistate-loading"><?php esc_html_e("Loading...", "optistate"); ?></div>
                    </div>
                    <button type="button" class="button button-primary os-mt-5" id="optistate-save-one-click-extra-btn">✓ <?php esc_html_e("Save One-Click Config", "optistate"); ?></button>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>
                        <span class="dashicons dashicons-layout"></span> <?php esc_html_e("Settings Presets", "optistate"); ?>
                        <a href="<?php echo esc_url($manual_base . "#ch-9-8"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <p class="os-line-height-relaxed"> 🎛️ <?php esc_html_e("Apply a predefined combination of settings with one click. Choose a preset that matches your needs.", "optistate"); ?> </p>
                    <div class="os-flex-start-gap-10">
                        <select id="optistate-preset-select" class="os-w-auto" style="min-width: 216px;">
                            <option value="">-- <?php esc_html_e("Select a Preset", "optistate"); ?> --</option>
                            <?php
                            $presets = OPTISTATE_Presets::get_presets();
                            foreach ($presets as $key => $preset) { ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $last_preset); ?>><?php echo esc_html($preset["label"]); ?></option>
                            <?php } ?>
                        </select>
                        <button type="button" class="button button-primary" id="optistate-apply-preset-btn">✓ <?php esc_html_e("Apply Preset", "optistate"); ?></button>
                    </div>
                    <div id="optistate-preset-description" class="os-mt-15" style="color: #444; background: #f9f9f9; padding: 12px; border-radius: 4px; border-left: 3px solid #2271b1; <?php echo $last_preset && isset($presets[$last_preset]) ? "" : "display: none;"; ?>">
                        <?php if ($last_preset && isset($presets[$last_preset])): ?>
                            <em><?php echo wp_kses_post($presets[$last_preset]["description"]); ?></em>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="optistate-card os-mt-20">
                    <h2>
                        <span><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e("Settings Import/Export", "optistate"); ?></span>
                        <a href="<?php echo esc_url($manual_base . "#ch-9"); ?>" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e("Read the Manual", "optistate"); ?>"><span class="dashicons dashicons-info"></span></a>
                    </h2>
                    <div id="optistate-export-content-wrapper">
                        <p class="os-line-height-relaxed">
                            ⛯ <?php esc_html_e("Backup your plugin settings in order to restore them later, or export them to another WordPress site.", "optistate"); ?><br>
                            <strong>⚠️ <?php esc_html_e("Important:", "optistate"); ?></strong> <?php esc_html_e("This only exports plugin settings (backup limits, automation schedule, performance features, etc). It does NOT export your database backups or cached pages.", "optistate"); ?>
                        </p>
                        <div class="optistate-grid-2">
                            <div class="optistate-file-export">
                                <h3 class="os-mt-0">📤 <?php esc_html_e("Export Settings", "optistate"); ?></h3>
                                <p class="os-line-height-relaxed">
                                    <?php esc_html_e("Download a JSON file containing all your plugin settings.", "optistate"); ?><br>
                                    ✓ <?php esc_html_e("Includes: Backup limits, automation schedule, caching/tweak features, user access restrictions, and more.", "optistate"); ?>
                                </p>
                                <button type="button" class="button-2" id="optistate-export-settings-btn"><span class="dashicons dashicons-download"></span> <?php esc_html_e("Export Settings", "optistate"); ?></button>
                                <div id="optistate-export-status" class="os-mt-10"></div>
                            </div>
                            <div class="optistate-file-import">
                                <h3 class="os-mt-0">📥 <?php esc_html_e("Import Settings", "optistate"); ?></h3>
                                <p class="os-line-height-relaxed">
                                    <?php esc_html_e("Upload a settings file to replace current configuration.", "optistate"); ?><br>
                                    <strong>⚠️ <?php esc_html_e("Warning:", "optistate"); ?></strong> <?php esc_html_e("This will overwrite your current settings!", "optistate"); ?>
                                </p>
                                <div class="optistate-file-upload-area os-mb-10">
                                    <input type="file" id="optistate-settings-file-input" class="optistate-file-input" accept=".json">
                                    <label for="optistate-settings-file-input" class="optistate-file-label"><span class="dashicons dashicons-upload"></span> <?php esc_html_e("Choose JSON File", "optistate"); ?></label>
                                </div>
                                <div id="optistate-settings-file-info" class="optistate-file-info"><strong><?php esc_html_e("Selected:", "optistate"); ?></strong> <span id="optistate-settings-file-name"></span></div>
                                <button type="button" class="button-2" id="optistate-import-settings-btn" disabled><span class="dashicons dashicons-upload"></span> <?php esc_html_e("Import Settings", "optistate"); ?></button>
                                <div id="optistate-import-status" class="os-mt-10"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>