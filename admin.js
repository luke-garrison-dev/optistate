jQuery(document).ready(function($) {
    'use strict';
    window.requestAnimationFrame = window.requestAnimationFrame || window.webkitRequestAnimationFrame || function(cb) {
        setTimeout(cb, 16);
    };
    var optistate_batch_update = (function() {
        var pending = false;
        var callbacks = [];

        function runCallbacks() {
            pending = false;
            var cbs = callbacks.slice();
            callbacks = [];
            for (var i = 0; i < cbs.length; i++) {
                cbs[i]();
            }
        }
        return function(callback) {
            if (typeof callback !== 'function') return;
            callbacks.push(callback);
            if (!pending) {
                pending = true;
                requestAnimationFrame(runCallbacks);
            }
        };
    })();
    const POLL_INTERVAL_NORMAL = 3000;
    const POLL_INTERVAL_SLOW = 4000;
    const RESTORE_LOCK_TIMEOUT = 3600000;
    const MAX_FILE_SIZE = 5000 * 1024 * 1024;
    const STATS_CACHE_DURATION = 15000;
    const SMALL_FILE_THRESHOLD = 12 * 1024 * 1024;
    const SMALL_CHUNK_SIZE = 1 * 1024 * 1024;
    const LARGE_CHUNK_SIZE = 3 * 1024 * 1024;
    const RATE_LIMIT_MS = 5000;
    const SELECTORS = {
        body: 'body',
        restoreFileBtn: '#optistate-restore-file-btn',
        createBackupBtn: '#create-backup-btn',
        globalButtons: '.restore-backup, #optistate-restore-file-btn, .delete-backup',
        backupsList: '#backups-list',
        backupSpinner: '#backup-spinner',
        statsContainer: '#optistate-stats',
        dbSizeValue: '#optistate-db-size-value',
        cleanupItemsContainer: '#optistate-cleanup-items',
        perfFeaturesContainer: '#optistate-performance-features-container',
        settingsLogContainer: '#optistate-settings-log',
        healthScoreWrapper: '#optistate-health-score-wrapper',
        healthScoreLoading: '#optistate-health-score-loading',
        uploadProgress: '#optistate-upload-progress',
        fileInfo: '#optistate-file-info',
        restoreWrapper: '#restore-button-wrapper',
        psiTestUrl: '#optistate-test-url',
        psiCustomUrl: '#optistate-custom-url',
        psiStrategy: '#optistate-strategy',
        restoreRecoveryNotice: '#optistate-restore-recovery-notice',
        progressFill: '.optistate-progress-fill',
        fileSize: '#optistate-file-size'
    };

    function createPoller(config) {
        const {
            action,
            data: baseData = {},
            interval = 2500,
            maxAttempts = 0,
            maxNetworkRetries = 999,
            visibilityAware = true,
            onResponse,
            onComplete,
            onError,
            onTimeout,
            onNetworkError,
            beforePoll,
            shouldStop
        } = config;
        let currentInterval = interval;
        let attempts = 0;
        let networkRetries = 0;
        let timeoutId = null;
        let cancelled = false;
        let running = false;
        let completed = false;
        const isBackupAction = /backup|restore|decompression/.test(action);
        const targetUrl = config.url || (isBackupAction && optistate_BackupMgr?.ajax_url ? optistate_BackupMgr.ajax_url : (optistate_Ajax?.ajaxurl || ajaxurl));
        const targetNonce = baseData.nonce || (isBackupAction ? optistate_BackupMgr?.nonce : optistate_Ajax?.nonce);
        const visibilityHandler = () => {
            if (document.hidden) {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                    timeoutId = null;
                }
                return;
            }
            if (completed) return;
            if (!running || cancelled) {
                cancelled = false;
                running = true;
                timeoutId = setTimeout(doPoll, 100);
            } else if (!timeoutId) {
                timeoutId = setTimeout(doPoll, 100);
            }
        };
        if (visibilityAware) {
            $(document).on('visibilitychange', visibilityHandler);
        }

        function stopPolling(permanent = true) {
            cancelled = permanent;
            running = false;
            if (timeoutId) {
                clearTimeout(timeoutId);
                timeoutId = null;
            }
            if (permanent) {
                $(document).off('visibilitychange', visibilityHandler);
            }
        }

        function doPoll() {
            if (cancelled || completed) return;
            if (typeof shouldStop === 'function' && shouldStop()) {
                stopPolling(true);
                return;
            }
            attempts++;
            if (maxAttempts > 0 && attempts > maxAttempts) {
                if (typeof onTimeout === 'function') onTimeout();
                else if (typeof onError === 'function') onError('Polling timed out.');
                stopPolling(true);
                return;
            }
            const pollData = {
                ...baseData,
                action,
                nonce: targetNonce
            };
            if (typeof beforePoll === 'function') {
                const extra = beforePoll();
                if (extra) Object.assign(pollData, extra);
            }
            $.ajax({
                url: targetUrl,
                type: 'POST',
                data: pollData,
                timeout: 90000,
                success: function(response) {
                    if (cancelled || completed) return;
                    networkRetries = 0;
                    if (!response || !response.success) {
                        const errMsg = response?.data?.message || 'Unknown error.';
                        if (response && response.data && response.data.status === 'error') {
                            if (typeof onError === 'function') onError(errMsg);
                            stopPolling(true);
                            return;
                        }
                        scheduleRetry(errMsg, true);
                        return;
                    }
                    const result = onResponse(response.data, function(finalData) {
                        completed = true;
                        if (typeof onComplete === 'function') onComplete(finalData);
                        stopPolling(true);
                    }, function(err) {
                        if (typeof onError === 'function') onError(err);
                        stopPolling(true);
                    });
                    if (typeof result === 'number') {
                        currentInterval = result;
                        if (!cancelled && !completed) timeoutId = setTimeout(doPoll, currentInterval);
                    } else if (result === true) {
                        if (!cancelled && !completed) timeoutId = setTimeout(doPoll, currentInterval);
                    } else if (result && typeof result.then === 'function') {
                        result.then(function(data) {
                            completed = true;
                            if (typeof onComplete === 'function') onComplete(data);
                            stopPolling(true);
                        }).catch(function(err) {
                            if (typeof onError === 'function') onError(err);
                            stopPolling(true);
                        });
                    } else if (result !== false) {
                        if (typeof onError === 'function') onError('Invalid poll response.');
                        stopPolling(true);
                    }
                },
                error: function(xhr, status, error) {
                    if (cancelled || completed) return;
                    const isTransient = status === 'timeout' || [502, 503, 504].includes(xhr.status) || status === 'abort';
                    if (isTransient && networkRetries < maxNetworkRetries) {
                        networkRetries++;
                        const backoff = Math.min(30000, currentInterval * Math.pow(1.5, networkRetries)) + (Math.random() * 500);
                        scheduleRetry(null, false, backoff);
                        return;
                    }
                    let errorMsg = 'Connection error. Please refresh and try again.';
                    if (xhr.status === 403) {
                        errorMsg = 'Access denied. Please refresh the page.';
                    } else if (xhr.status === 404) {
                        errorMsg = 'The requested endpoint was not found.';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error. Please check the PHP error logs.';
                    } else if (status === 'timeout') {
                        errorMsg = 'The request timed out. The process may still be running.';
                    }
                    if (typeof onNetworkError === 'function') {
                        onNetworkError(errorMsg);
                    } else if (typeof onError === 'function') {
                        onError(errorMsg);
                    }
                    stopPolling(true);
                }
            });
        }

        function scheduleRetry(message, isNetworkError = false, delay = null) {
            if (cancelled || completed) return;
            const backoff = delay || Math.min(30000, currentInterval * Math.pow(1.5, networkRetries + 1)) + (Math.random() * 500);
            if (timeoutId) clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                doPoll();
            }, backoff);
        }

        function start() {
            if (running) return;
            if (completed) return;
            cancelled = false;
            running = true;
            attempts = 0;
            networkRetries = 0;
            currentInterval = interval;
            if (timeoutId) clearTimeout(timeoutId);
            timeoutId = setTimeout(doPoll, 100);
        }
        return {
            start,
            stop: function() {
                stopPolling(true);
            },
            get isRunning() {
                return running;
            },
            get isCompleted() {
                return completed;
            },
            refresh: function() {
                if (completed) return;
                if (!running) {
                    start();
                } else {
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                        timeoutId = null;
                    }
                    doPoll();
                }
            }
        };
    }

    function pollPageSpeedStatus(taskId) {
        const poller = createPoller({
            action: 'optistate_check_pagespeed_status',
            data: {
                task_id: taskId
            },
            interval: POLL_INTERVAL_NORMAL,
            maxAttempts: 60,
            visibilityAware: true,
            maxNetworkRetries: 3,
            onResponse: function(responseData, resolve, reject) {
                try {
                    if (responseData.status === 'done') {
                        resetButton();
                        updatePageSpeedUI(responseData.data);
                        showToast(__('Performance audit completed successfully!', 'optistate'), 'success');
                        debouncedLoadOptimizationLog();
                        resolve(responseData);
                        return false;
                    } else if (responseData.status === 'error') {
                        reject(responseData.message || 'Audit failed.');
                        return false;
                    } else {
                        return true;
                    }
                } catch (e) {
                    resetButton();
                    reject(e);
                    return false;
                }
            },
            onError: function(err) {
                showToast(err || 'Audit failed during processing.', 'error');
                resetButton();
            },
            onTimeout: function() {
                showToast('Audit timed out. Please try again.', 'error');
                resetButton();
            }
        });
        poller.start();
    }

    function pollIndexStatus(taskId, $btn, action_type) {
        let attempts = 0;
        let runningCount = 0;
        const isDrop = action_type === 'drop';
        const poller = createPoller({
            action: 'optistate_check_index_status',
            data: {
                task_id: taskId
            },
            interval: 3000,
            maxAttempts: 80,
            visibilityAware: true,
            maxNetworkRetries: 3,
            beforePoll: function() {
                attempts++;
                return null;
            },
            onResponse: function(responseData, resolve, reject) {
                const status = responseData.status;
                if (status === 'done') {
                    showToast(isDrop ? 'Index removed!' : 'Index added!', 'success');
                    debouncedLoadOptimizationLog();
                    $btn.fadeOut(200, function() {
                        $(this).replaceWith('<span class="os-index-action-success">✅ ' + (isDrop ? 'Removed' : 'Added') + '</span>');
                    });
                    resolve(responseData);
                    return false;
                } else if (status === 'error') {
                    showToast(responseData.message || 'Database operation failed.', 'error');
                    $btn.prop('disabled', false).html('Retry');
                    reject(responseData.message);
                    return false;
                } else {
                    if (status === 'running') {
                        runningCount++;
                        if (runningCount >= 5) {
                            runningCount = 0;
                            $.post(optistate_Ajax.ajaxurl, {
                                action: 'optistate_force_run_index_worker',
                                task_id: taskId,
                                nonce: optistate_Ajax.nonce
                            });
                        }
                    }
                    const label = (attempts % 2 === 0) ? 'Applying...' : 'Verifying...';
                    $btn.css({
                        'display': 'inline-flex',
                        'align-items': 'center',
                        'vertical-align': 'middle',
                        'justify-content': 'center',
                        'gap': '8px'
                    }).html('<span class="spinner is-active os-spinner-inline-block"></span><span>' + label + '</span>');
                    return true;
                }
            },
            onTimeout: function() {
                $btn.css('display', '').prop('disabled', false).html('Check Status');
                showToast('Timeout reached.', 'warning');
            },
            onError: function(err) {
                showToast(err || 'Network error.', 'error');
                $btn.prop('disabled', false).html('Retry');
            }
        });
        poller.start();
    }

    function pollPreloadStatus() {
        if (isPreloadCancelled) return;
        clearTimeout(preloadInterval);
        const poller = createPoller({
            action: 'optistate_get_preload_status',
            data: {},
            interval: 3000,
            maxAttempts: 0,
            visibilityAware: true,
            maxNetworkRetries: 3,
            beforePoll: function() {
                if (isPreloadCancelled) {
                    poller.stop();
                    return null;
                }
                return null;
            },
            onResponse: function(responseData, resolve, reject) {
                if (isPreloadCancelled) {
                    reject('Cancelled');
                    return false;
                }
                const d = responseData;
                if (!d.running) {
                    optistate_batch_update(function() {
                        $('#preload-progress-bar').css('width', '100%').text('100%');
                        $('#preload-status-text').html('<strong>✅ Preload completed!</strong>');
                    });
                    showToast('🏁 Cache preload finished!', 'success');
                    setTimeout(function() {
                        $('#preload-progress-wrapper').slideUp(300);
                        loadCacheStats();
                        debouncedLoadOptimizationLog();
                    }, 3000);
                    resolve(d);
                    return false;
                } else {
                    optistate_batch_update(function() {
                        $('#preload-progress-bar').css('width', d.percentage + '%').text(d.percentage + '%');
                        $('#preload-status-text').text(sprintf('Cached %s of %s pages... Batch size: %s', (d.processed || 0).toLocaleString(), (d.total || 0).toLocaleString(), (d.batch_size || 0).toLocaleString()));
                    });
                    return true;
                }
            },
            onError: function(err) {
                if (!isPreloadCancelled) {
                    window.preloadErrorCount = (window.preloadErrorCount || 0) + 1;
                    if (window.preloadErrorCount < 3) {
                        preloadInterval = setTimeout(pollPreloadStatus, 5000);
                    } else {
                        showToast('Preload monitoring stopped due to connection errors.', 'warning');
                        $('#preload-progress-wrapper').slideUp(300);
                        window.preloadErrorCount = 0;
                    }
                }
            }
        });
        poller.start();
        window._preloadPoller = poller;
    }
    const {
        __,
        sprintf
    } = wp.i18n;
    const ESCAPE_MAP = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    const SIZE_PARSE_REGEX = /^([\d.]+)\s*([A-Za-z]+)/i;
    const SIZE_UNITS = {
        'b': 1,
        'bytes': 1,
        'kb': 1024,
        'mb': 1024 * 1024,
        'gb': 1024 * 1024 * 1024,
        'tb': 1024 * 1024 * 1024 * 1024
    };
    let isRestoreInProgress = false;
    let restoreLockTimestamp = 0;
    let isProcessing = false;
    let uploadedFilePath = null;
    let currentUpload = null;
    let statsCache = null;
    let statsCacheTime = 0;
    let $cacheStatsElements = null;
    let preloadInterval = null;
    let isPreloadCancelled = false;
    let preloadResumeDebounceTimer = null;
    let debounceLogTimer = null;
    let isDeletingAll = false;
    let lastCronRefreshTime = 0;
    const $body = $(SELECTORS.body);
    const $backupsList = $(SELECTORS.backupsList);
    const $createBackupBtn = $(SELECTORS.createBackupBtn);
    const $backupSpinner = $(SELECTORS.backupSpinner);
    const $statsContainer = $(SELECTORS.statsContainer);
    const $dbSizeValue = $(SELECTORS.dbSizeValue);
    const $cleanupItemsContainer = $(SELECTORS.cleanupItemsContainer);
    const $perfFeaturesContainer = $(SELECTORS.perfFeaturesContainer);
    const $settingsLogContainer = $(SELECTORS.settingsLogContainer);
    const $healthScoreWrapper = $(SELECTORS.healthScoreWrapper);
    const $healthScoreLoading = $(SELECTORS.healthScoreLoading);
    const $refreshStatsBtn = $('#optistate-refresh-stats');
    const $autoOptimizeDays = $('#auto_optimize_days');
    const $autoOptimizeTime = $('#auto_optimize_time');
    const $autoBackupOnly = $('#auto_backup_only');
    const $emailNotifications = $('#email_notifications');
    const $maxBackupsSetting = $('#max_backups_setting');
    const $saveAutoOptimizeBtn = $('#save-auto-optimize-btn');
    const $restoreRecoveryNotice = $(SELECTORS.restoreRecoveryNotice);
    const $progressFill = $(SELECTORS.progressFill);
    const $fileSize = $(SELECTORS.fileSize);
    const $restoreFileBtn = $(SELECTORS.restoreFileBtn);
    const labels = {
        'post_revisions': __('Post Revisions', 'optistate'),
        'post_revisions_size': __('Revisions Data Size', 'optistate'),
        'expired_transients': __('Expired Transients', 'optistate'),
        'expired_transients_size': __('Expired Transients Data Size', 'optistate'),
        'table_overhead': __('Database Overhead', 'optistate'),
        'total_indexes_size': __('Total Indexes Size', 'optistate'),
        'total_tables_count': __('Number of Tables', 'optistate'),
        'db_creation_date': __('Database Created On', 'optistate'),
        'autoload_options': __('Autoloaded Options', 'optistate'),
        'autoload_size': __('Autoload Data Size', 'optistate'),
        'auto_drafts': __('Auto Drafts', 'optistate'),
        'trashed_posts': __('Trashed Posts', 'optistate'),
        'spam_comments': __('Spam Comments', 'optistate'),
        'trashed_comments': __('Trashed Comments', 'optistate'),
        'orphaned_postmeta': __('Orphaned Post Meta', 'optistate'),
        'orphaned_termmeta': __('Orphaned Term Meta', 'optistate'),
        'orphaned_usermeta': __('Orphaned User Meta', 'optistate'),
        'orphaned_commentmeta': __('Orphaned Comment Meta', 'optistate'),
        'orphaned_relationships': __('Orphaned Term Relationships', 'optistate'),
        'duplicate_postmeta': __('Duplicate Post Meta', 'optistate'),
        'duplicate_commentmeta': __('Duplicate Comment Meta', 'optistate'),
        'duplicate_usermeta': __('Duplicate User Meta', 'optistate'),
        'duplicate_termmeta': __('Duplicate Term Meta', 'optistate'),
        'unapproved_comments': __('Unapproved Comments', 'optistate'),
        'pingbacks': __('Pingbacks', 'optistate'),
        'trackbacks': __('Trackbacks', 'optistate'),
        'all_transients': __('All Transients (Non-expired)', 'optistate'),
        'action_scheduler': __('Action Logs', 'optistate'),
        'oembed_cache': __('oEmbed Cache', 'optistate'),
        'woo_bloat': __('WooCommerce Sessions/Logs', 'optistate'),
        'empty_taxonomies': __('Empty Taxonomies', 'optistate'),
        'engine_distribution': __('Table Engine Distribution', 'optistate'),
        'index_to_data_ratio': __('Index-to-Data Ratio', 'optistate'),
    };
    const systemLabels = {
        'wp_version': __('WordPress Version', 'optistate'),
        'active_plugins_count': __('WordPress Plugins (Active)', 'optistate'),
        'active_theme': __('WordPress Theme (Active)', 'optistate'),
        'wp_memory_limit': __('WordPress Memory Limit', 'optistate'),
        'persistent_cache_status': __('Persistent Object Cache', 'optistate'),
        'upload_folder_size': __('Uploads Folder Size', 'optistate'),
        'error_logging': __('Error Logging Status', 'optistate'),
        'htaccess_info': __('.htaccess File', 'optistate'),
        'server_type': __('Server Type', 'optistate'),
        'os': __('Operating System', 'optistate'),
        'mysql_version': __('MySQL Version', 'optistate'),
        'php_version': __('PHP Version', 'optistate'),
        'total_ram': __('Total System Memory (RAM)', 'optistate'),
        'php_memory_limit': __('PHP Memory Limit', 'optistate'),
        'disk_total': __('Total Disk Space', 'optistate'),
        'disk_free': __('Free Disk Space', 'optistate'),
    };
    const STATS_TOOLTIPS = {
        'table_overhead': __('Space that can be reclaimed by optimizing tables', 'optistate'),
        'engine_distribution': __('Database storage engines used by your tables (InnoDB is recommended)', 'optistate'),
        'index_to_data_ratio': __('Low ratio may indicate missing indexes, high ratio may indicate over‑indexing. Ideal: 20-50%', 'optistate'),
        'autoload_options': __('Options that load on every page request. Large autoload can slow down your site', 'optistate'),
    };

    function escapeHTML(text) {
        if (text === undefined || text === null) return '';
        return String(text).replace(/[&<>"']/g, m => ESCAPE_MAP[m]);
    }
    const esc_attr = escapeHTML;
    const esc_html = escapeHTML;

    function safeModalHTML(html) {
        if (!html) return '';
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        doc.querySelectorAll('script').forEach(s => s.remove());
        doc.querySelectorAll('*').forEach(el => {
            Array.from(el.attributes).forEach(attr => {
                if (attr.name.toLowerCase().startsWith('on')) el.removeAttribute(attr.name);
            });
        });
        const allowedTags = ['br', 'strong', 'em', 'p', 'ul', 'li', 'code', 'pre', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'a', 'button'];
        const walk = (node) => {
            if (node.nodeType === 1 && !allowedTags.includes(node.tagName.toLowerCase())) {
                const text = document.createTextNode(node.textContent);
                node.parentNode.replaceChild(text, node);
                return;
            }
            Array.from(node.children).forEach(walk);
        };
        Array.from(doc.body.children).forEach(walk);
        return doc.body.innerHTML;
    }

    function pollProcessStatus(action, data, handlers, interval, maxAttempts) {
        const poller = createPoller({
            action,
            data,
            interval: interval || POLL_INTERVAL_NORMAL,
            maxAttempts: maxAttempts || 0,
            visibilityAware: true,
            maxNetworkRetries: 3,
            onResponse: function(responseData, resolve, reject) {
                if (handlers.onStatus) {
                    const keepPolling = handlers.onStatus(responseData);
                    if (keepPolling === false) {
                        if (handlers.onComplete) handlers.onComplete(responseData);
                        resolve(responseData);
                        return false;
                    }
                    return true;
                }
                if (handlers.onComplete) handlers.onComplete(responseData);
                resolve(responseData);
                return false;
            },
            onError: function(err) {
                if (handlers.onError) handlers.onError(err);
            },
            onTimeout: function() {
                if (handlers.onTimeout) handlers.onTimeout();
                else if (handlers.onError) handlers.onError('Polling timed out.');
            },
            onNetworkError: function(err) {
                if (handlers.onError) handlers.onError(err);
            }
        });
        poller.start();
        return function stopPolling() {
            poller.stop();
        };
    }

    function showOverlay($container, message) {
        if (!$container.length) return;
        $container.find('.os-loading-overlay').remove();
        $container.css('position', 'relative');
        const $overlay = $('<div class="os-loading-overlay"><span class="spinner is-active"></span> ' + message + '</div>');
        $container.append($overlay);
    }

    function hideOverlay($container) {
        if (!$container.length) return;
        $container.find('.os-loading-overlay').remove();
        if ($container.css('position') === 'relative') {
            $container.css('position', '');
        }
    }

    function formatBytes(bytes, decimals = 2) {
        const numBytes = parseInt(bytes, 10);
        if (isNaN(numBytes) || numBytes <= 0) return __('0 Bytes', 'optistate');
        const k = 1024;
        const dm = Math.max(0, Math.min(decimals, 4));
        const sizes = [__('Bytes', 'optistate'), __('KB', 'optistate'), __('MB', 'optistate'), __('GB', 'optistate'), __('TB', 'optistate')];
        const i = Math.min(Math.floor(Math.log(numBytes) / Math.log(k)), sizes.length - 1);
        return parseFloat((numBytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function formatSystemBytes(bytes, decimals = 2) {
        if (!bytes || bytes === 0) return 'N/A';
        return formatBytes(bytes, decimals);
    }

    function parseSizeToBytes(sizeText) {
        if (!sizeText || typeof sizeText !== 'string') return 0;
        const cleanText = sizeText.replace(/,/g, '').replace(/[^\d. A-Za-z]/g, '').trim();
        const match = cleanText.match(SIZE_PARSE_REGEX);
        if (!match) return 0;
        const value = parseFloat(match[1]);
        const bytes = SIZE_UNITS[match[2].toLowerCase()];
        return (isNaN(value) || bytes === undefined) ? 0 : Math.round(value * bytes);
    }

    function getAlternatingLoadingText(primaryText) {
        const now = Date.now();
        return (Math.floor(now / 3000) % 2 !== 0) ? __('PLEASE WAIT ....', 'optistate') : primaryText;
    }

    function acquireRestoreLock() {
        const now = Date.now();
        if (isRestoreInProgress && (now - restoreLockTimestamp) > RESTORE_LOCK_TIMEOUT) {
            releaseRestoreLock();
        }
        if (isRestoreInProgress) return false;
        localStorage.removeItem('optistate_restore_completed_viewed');
        isRestoreInProgress = true;
        restoreLockTimestamp = now;
        $(SELECTORS.globalButtons).prop('disabled', true);
        $createBackupBtn.prop('disabled', true);
        return true;
    }

    function releaseRestoreLock() {
        isRestoreInProgress = false;
        restoreLockTimestamp = 0;
    }

    function resetRestoreUI(errorMessage = null) {
        releaseRestoreLock();
        $(SELECTORS.globalButtons).prop('disabled', false);
        $createBackupBtn.prop('disabled', false);
        $restoreFileBtn.html(`<span class="dashicons dashicons-upload"></span> ${__('Restore from File', 'optistate')}`);
        $restoreFileBtn.data('retry-count', 0);
        $backupsList.find('.restore-backup').each(function() {
            $(this).html(`<span class="dashicons dashicons-backup"></span> ${__('Restore', 'optistate')}`);
            $(this).data('retry-count', 0);
        });
        $(SELECTORS.restoreWrapper).hide();
        $(SELECTORS.backupSpinner).hide();
        if (uploadedFilePath && typeof resetUploadUI === 'function') resetUploadUI();
        if (errorMessage) showToast(errorMessage, 'error');
        $restoreRecoveryNotice.hide();
    }

    function pollBackupStatus(transient_key, $button, options) {
        var opts = options || {};
        var disableGlobalButtons = opts.disableGlobalButtons !== false;
        var showCompressingTransition = opts.showCompressingTransition !== false;
        var onComplete = opts.onComplete || function(data) {
            showToast(data.message || __('Backup complete!', 'optistate'), 'success');
            if ($backupSpinner.length) $backupSpinner.hide();
            $button.prop('disabled', true).html('<span class="dashicons dashicons-yes-alt"></span> <strong>' + __('BACKUP COMPLETE!', 'optistate') + '</strong>');
            $(SELECTORS.globalButtons).prop('disabled', false);
            setTimeout(function() {
                $button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> <strong>' + __('Create Backup Now', 'optistate') + '</strong>');
                $button.data('retry-count', 0);
            }, 5000);
            if (data.backups) updateBackupsList(data.backups);
            debouncedLoadOptimizationLog();
        };
        var onError = opts.onError || function(errorMsg) {
            showToast(errorMsg, 'error');
            $button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> <strong>' + __('Create Backup Now', 'optistate') + '</strong>');
            $button.data('retry-count', 0);
            if ($backupSpinner.length) $backupSpinner.hide();
            $(SELECTORS.globalButtons).prop('disabled', false);
        };
        pollProcessStatus('optistate_check_backup_status', {
            transient_key: transient_key
        }, {
            onStatus: function(data) {
                if (data.status === 'running' || data.status === 'compressing') {
                    var msg = data.status === 'running' ? __('BACKING UP ....', 'optistate') : __('COMPRESSING ....', 'optistate');
                    optistate_batch_update(function() {
                        $button.html('<span class="spinner is-active os-spinner-inline"></span> <strong>' + msg + '</strong>');
                    });
                    if (disableGlobalButtons) $(SELECTORS.globalButtons).prop('disabled', true);
                    $('#create-backup-btn').prop('disabled', true);
                    return true;
                } else if (data.status === 'done') {
                    if (showCompressingTransition) {
                        optistate_batch_update(function() {
                            $button.html('<span class="spinner is-active os-spinner-inline"></span> <strong>' + __('COMPRESSING ....', 'optistate') + '</strong>');
                        });
                        setTimeout(function() {
                            onComplete(data);
                        }, 1800);
                    } else {
                        onComplete(data);
                    }
                    return false;
                } else {
                    onError(data.message || __('Backup failed during processing.', 'optistate'));
                    return false;
                }
            },
            onComplete: function(data) {},
            onError: onError
        }, POLL_INTERVAL_NORMAL);
    }

    function pollRestoreStatus(master_restore_key, $button) {
        pollProcessStatus('optistate_get_restore_status', {
            master_restore_key: master_restore_key
        }, {
            onStatus: function(data) {
                if (data.status === 'completed_recently') {
                    debouncedLoadOptimizationLog();
                    resetRestoreUI();
                    $(SELECTORS.globalButtons).prop('disabled', false);
                    $createBackupBtn.prop('disabled', false);
                    $restoreRecoveryNotice.hide();
                    showToast(data.message || __('Restore completed successfully.', 'optistate'), 'success');
                    if (typeof reloadBackupList === 'function') reloadBackupList();
                    return false;
                } else if (data.status === 'done' || data.final_success_flag === true) {
                    debouncedLoadOptimizationLog();
                    if ($button && $button.length) {
                        optistate_batch_update(function() {
                            $button.html('<span class="dashicons dashicons-yes-alt"></span> ' + __('RESTORE COMPLETE!', 'optistate'));
                        });
                    }
                    releaseRestoreLock();
                    showToast((data.message || __('Restore complete!', 'optistate')) + ' ' + __('⏳ Page will reload shortly...', 'optistate'), 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 10000);
                    return false;
                } else if (['safety_backup_starting', 'safety_backup_running', 'restore_starting', 'restore_running', 'rollback_starting'].includes(data.status)) {
                    var baseMessage = data.message || __('PROCESSING ....', 'optistate');
                    if ($button && $button.length) {
                        optistate_batch_update(function() {
                            $button.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline"></span> ' + getAlternatingLoadingText(baseMessage));
                        });
                    }
                    $createBackupBtn.prop('disabled', true);
                    return true;
                } else if (['rollback_done', 'error', 'not_running', 'stalled', 'rollback_failed'].includes(data.status)) {
                    debouncedLoadOptimizationLog();
                    var type = data.status === 'rollback_done' ? 'warning' : (data.status === 'not_running' ? 'info' : 'error');
                    resetRestoreUI();
                    $(SELECTORS.globalButtons).prop('disabled', false);
                    $createBackupBtn.prop('disabled', false);
                    $restoreRecoveryNotice.hide();
                    showToast(data.message || __('Restore finished.', 'optistate'), type);
                    return false;
                } else {
                    debouncedLoadOptimizationLog();
                    resetRestoreUI(__('Restore encountered an unknown status.', 'optistate'));
                    return false;
                }
            },
            onError: function(errorMsg) {
                debouncedLoadOptimizationLog();
                resetRestoreUI(errorMsg || __('Connection error during restore check.', 'optistate'));
            }
        }, POLL_INTERVAL_SLOW);
    }

    function pollDecompressionStatus(decompression_key, $button) {
        if (!$button.length) {
            releaseRestoreLock();
            return;
        }
        pollProcessStatus('optistate_check_decompression_status', {
            decompression_key: decompression_key
        }, {
            onStatus: function(data) {
                if (data.status === 'error') {
                    releaseRestoreLock();
                    showToast(data.message || __('Decompression failed.', 'optistate'), 'error');
                    resetRestoreUI();
                    return false;
                }
                if (data.status === 'decompressing') {
                    var displayText = getAlternatingLoadingText(__('DECOMPRESSING BACKUP ....', 'optistate'));
                    optistate_batch_update(function() {
                        $button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + displayText);
                        if ($button.is($restoreFileBtn)) {
                            $progressFill.text(getAlternatingLoadingText(__('DECOMPRESSING ....', 'optistate')));
                        }
                    });
                    $createBackupBtn.prop('disabled', true);
                    return true;
                } else if (data.status === 'restore_starting') {
                    pollRestoreStatus(data.master_restore_key, $button);
                    return false;
                } else {
                    releaseRestoreLock();
                    showToast(data.message || __('Decompression failed.', 'optistate'), 'error');
                    resetRestoreUI();
                    return false;
                }
            },
            onError: function(errorMsg) {
                releaseRestoreLock();
                showToast(errorMsg || __('Decompression failed.', 'optistate'), 'error');
                resetRestoreUI();
            }
        }, POLL_INTERVAL_SLOW);
    }

    function updateBackupsList(backups) {
        if (!Array.isArray(backups)) return;
        $backupsList.empty();
        if (backups.length === 0) {
            $backupsList.html(`<td><td colspan="4" class="db-backup-empty">${esc_attr(__('No backups found. Create your first backup!', 'optistate'))}</td></tr>`);
            return;
        }
        const fragment = document.createDocumentFragment();
        backups.forEach(backup => {
            if (!backup.filename || !backup.date || !backup.size) return;
            const verifiedText = backup.verified ? __('✓ Verified', 'optistate') : __('⚠ Unverified', 'optistate');
            const verificationStatus = `<span class="db-backup-${backup.verified ? 'verified' : 'unverified'} optistate-integrity-info os-cursor-pointer" data-status="${backup.verified ? 'verified' : 'unverified'}">${esc_html(verifiedText)}</span>`;
            let tablesBadge = '';
            if (backup.table_count && backup.table_count > 0 && Array.isArray(backup.tables_list) && backup.tables_list.length > 0) {
                const tablesJson = JSON.stringify(backup.tables_list);
                tablesBadge = `<span class="db-backup-tables os-cursor-pointer" data-tables='${esc_attr(tablesJson)}' data-filename="${esc_attr(backup.filename)}" title="${esc_attr(__('Click to view tables', 'optistate'))}">𓊂 ${backup.table_count} ${__('TABLES', 'optistate')}</span>`;
            }
            const backupType = backup.type || 'MANUAL';
            const typeBadgeClass = backupType === 'SCHEDULED' ? 'optistate-type-scheduled' : 'optistate-type-manual';
            const typeIcon = backupType === 'SCHEDULED' ? '⏰' : '👤';
            const typeTooltip = backupType === 'MANUAL' ? __('Created manually by user', 'optistate') : __('Created automatically by the system', 'optistate');
            const typeBadge = `<span class="optistate-backup-type ${typeBadgeClass}" title="${esc_attr(typeTooltip)}">${typeIcon} ${backupType}</span>`;
            const row = document.createElement('tr');
            row.setAttribute('data-file', esc_attr(backup.filename));
            if (backup.size_bytes) row.setAttribute('data-bytes', esc_attr(backup.size_bytes));
            if (backup.uncompressed_size) row.setAttribute('data-uncompressed-bytes', esc_attr(backup.uncompressed_size));
            row.innerHTML = ` <td> <strong>${esc_html(backup.filename)}</strong> <div class="os-backup-meta-row"> ${verificationStatus} ${tablesBadge} ${typeBadge} </div> </td> <td>${esc_html(backup.date)}</td> <td>${esc_html(backup.size)}</td> <td> <button class="button download-backup" data-file="${esc_attr(backup.filename)}" data-download-url="${esc_attr(backup.download_url)}"> <span class="dashicons dashicons-download"></span> ${__('Download', 'optistate')} </button> <button class="button restore-backup" data-file="${esc_attr(backup.filename)}" ${!backup.verified ? 'disabled' : ''} title="${!backup.verified ? esc_attr__('Cannot restore: File integrity failed.', 'optistate') : ''}"> <span class="dashicons dashicons-backup"></span> ${__('Restore', 'optistate')} </button> <button class="button delete-backup" data-file="${esc_attr(backup.filename)}"> <span class="dashicons dashicons-trash"></span> ${__('Delete', 'optistate')} </button> </td> `;
            fragment.appendChild(row);
        });
        $backupsList[0].appendChild(fragment);
    }

    function apiRequest(opts) {
        const {
            action,
            data = {},
            $btn = null,
            loadingText = null,
            errorMsg = __('An error occurred.', 'optistate'),
            isSaveAction = false,
            onSuccess,
            onError,
            ajaxOptions = {}
        } = opts;
        const originalText = $btn ? $btn.html() : '';
        if ($btn) {
            $btn.prop('disabled', true);
            if (loadingText) $btn.html(loadingText);
        }
        const request = $.ajax({
            url: optistate_Ajax.ajaxurl,
            type: 'POST',
            data: {
                action,
                nonce: optistate_Ajax.nonce,
                ...data
            },
            timeout: 30000,
            ...ajaxOptions
        }).done(function(response) {
            if (onSuccess) onSuccess(response);
        }).fail(function(xhr) {
            if (onError) {
                onError(xhr);
            } else {
                handleAjaxError(xhr, errorMsg, isSaveAction);
            }
        }).always(function() {
            if ($btn) {
                $btn.prop('disabled', false);
                if (originalText) $btn.html(originalText);
            }
        });
        return request;
    }

    function initBackupEvents() {
        $createBackupBtn.on('click', function() {
            const $btn = $(this);
            if ($btn.prop('disabled')) return;
            showOPTISTATEModal(__('💾 Create Backup', 'optistate'), __('Create a new database backup?<br><br>The process will run in the background.<br>The backup file will be compressed with GZIP.', 'optistate'), function() {
                $btn.prop('disabled', true);
                $backupSpinner.show();
                $btn.html(`<span class="spinner is-active os-spinner-inline"></span> <strong>${__('INITIATING ....', 'optistate')}</strong>`);
                $(SELECTORS.globalButtons).prop('disabled', true);
                setTimeout(() => {
                    $.ajax({
                        url: optistate_BackupMgr.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'optistate_create_backup',
                            nonce: optistate_BackupMgr.nonce
                        },
                        timeout: 30000,
                        success: function(response) {
                            if (response?.success && response.data.status === 'starting') {
                                let currentDbSizeBytes = 0;
                                if (statsCache && statsCache.total_db_size_bytes) {
                                    currentDbSizeBytes = parseInt(statsCache.total_db_size_bytes, 10);
                                } else {
                                    currentDbSizeBytes = parseSizeToBytes($dbSizeValue.text());
                                }
                                showToast(getBackupTimeEstimate(currentDbSizeBytes), 'info');
                                $btn.html(`<span class="spinner is-active os-spinner-inline"></span> <strong>${__('BACKING UP ....', 'optistate')}</strong>`);
                                $backupSpinner.hide();
                                pollBackupStatus(response.data.transient_key, $btn);
                            } else {
                                showToast(response?.data?.message || __('Backup failed to start.', 'optistate'), 'error');
                                $btn.prop('disabled', false).html(`<span class="dashicons dashicons-plus-alt"></span> <strong>${__('Create Backup Now', 'optistate')}</strong>`);
                                $backupSpinner.hide();
                                $(SELECTORS.globalButtons).prop('disabled', false);
                            }
                        },
                        error: function(xhr) {
                            showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : __('An error occurred while creating the backup.', 'optistate'), xhr.status === 429 ? 'warning' : 'error');
                            $btn.prop('disabled', false).html(`<span class="dashicons dashicons-plus-alt"></span> <strong>${__('Create Backup Now', 'optistate')}</strong>`);
                            $backupSpinner.hide();
                            $(SELECTORS.globalButtons).prop('disabled', false);
                        }
                    });
                }, 800);
            });
        });
        $backupsList.on('click', '.delete-backup', function() {
            const $btn = $(this);
            const filename = $btn.data('file');
            const $row = $btn.closest('tr');
            if (!filename) return;
            const message = sprintf(__('Are you sure you want to delete this backup?', 'optistate') + '<br><br>' + __('%s', 'optistate') + '<br><br>' + __('This action cannot be undone.', 'optistate'), esc_html(filename));
            showOPTISTATEModal(__('🗑️ Confirm Deletion', 'optistate'), message, function() {
                $btn.prop('disabled', true);
                $.post(optistate_BackupMgr.ajax_url, {
                    action: 'optistate_delete_backup',
                    nonce: optistate_BackupMgr.nonce,
                    filename: filename
                }).done(function(response) {
                    if (response?.success) {
                        showToast(response.data.message, 'success');
                        debouncedLoadOptimizationLog();
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            if ($backupsList.find('tr').length === 0) {
                                $backupsList.html(`<tr><td colspan="4" class="db-backup-empty">${__('No backups found. Create your first backup!', 'optistate')}</td></tr>`);
                            }
                        });
                    } else {
                        showToast(response?.data?.message || __('Failed to delete backup.', 'optistate'), 'error');
                        $btn.prop('disabled', false);
                    }
                }).fail(function(xhr) {
                    let msg;
                    if (xhr.status === 429) {
                        msg = xhr.responseJSON?.data?.message || getRateLimitMessage(false);
                        showToast(msg, 'warning');
                    } else {
                        msg = __('An error occurred while deleting the backup.', 'optistate');
                        showToast(msg, 'error');
                    }
                    $btn.prop('disabled', false);
                });
            });
        });
        $backupsList.on('click', '.download-backup', function() {
            const $btn = $(this);
            const filename = $btn.data('file');
            if (!filename || $btn.prop('disabled')) return;
            const now = Date.now();
            const lastDownload = localStorage.getItem('optistate_last_download_click');
            if (lastDownload && (now - parseInt(lastDownload)) < RATE_LIMIT_MS) {
                showToast(__('🕔 Please wait a few seconds before downloading again.', 'optistate'), 'warning');
                return;
            }
            localStorage.setItem('optistate_last_download_click', now);
            const baseUrl = optistate_BackupMgr.ajax_url.replace('admin-ajax.php', '');
            window.location.href = add_query_arg({
                action: 'optistate_backup_download',
                file: filename,
                _wpnonce: optistate_BackupMgr.nonce
            }, baseUrl);
            showToast(__('Download starting...', 'optistate'), 'success');
            debouncedLoadOptimizationLog();
        });
        $backupsList.on('click', '.restore-backup', function() {
            if (isRestoreInProgress) {
                showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
                return;
            }
            const $btn = $(this);
            const filename = $btn.data('file');
            if (!filename) return;
            const $row = $btn.closest('tr');
            if ($row.find('.db-backup-unverified').length > 0) {
                showToast(__('Restore Blocked: File integrity is compromised or unverified.', 'optistate'), 'error');
                $btn.prop('disabled', true);
                return;
            }
            let sizeInBytes = $row.data('bytes');
            if (!sizeInBytes) {
                sizeInBytes = parseSizeToBytes($row.find('td').eq(2).text().trim());
            }
            const message = sprintf(__('This will restore your database from:', 'optistate') + '<br><br>' + __('%s', 'optistate') + '<br><br>' + __('Your site will enter maintenance mode briefly and then reload.', 'optistate') + '<br><br>' + __('ALL CURRENT DATA WILL BE REPLACED!', 'optistate') + '<br><br>' + __('Are you absolutely sure you want to continue?', 'optistate'), esc_html(filename));
            showOPTISTATEModal(__('⚠️ WARNING: Restore Database', 'optistate'), message, function() {
                if (!acquireRestoreLock()) {
                    showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
                    return;
                }
                $(SELECTORS.globalButtons).prop('disabled', true);
                $createBackupBtn.prop('disabled', true);
                $btn.html(`<span class="spinner is-active os-spinner-inline"></span> <strong>${__('INITIATING ....', 'optistate')}</strong>`);
                $btn.prop('disabled', true);
                $.ajax({
                    url: optistate_BackupMgr.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'optistate_restore_backup',
                        nonce: optistate_BackupMgr.nonce,
                        filename: filename
                    },
                    timeout: 300000,
                    success: function(response) {
                        if (response?.success) {
                            showToast(getRestoreTimeEstimate(sizeInBytes) || __('Restore initiated!', 'optistate'), 'info');
                            $restoreRecoveryNotice.hide().removeClass('os-display-none').fadeIn(300);
                            if (response.data.status === 'decompressing') {
                                $btn.html(`<span class="spinner is-active"></span> ${__('DECOMPRESSING...', 'optistate')}`);
                                pollDecompressionStatus(response.data.decompression_key, $btn);
                            } else if (response.data.status === 'starting') {
                                $btn.html(`<span class="spinner is-active"></span> <strong>${__('CREATING SAFETY BACKUP...', 'optistate')}</strong>`);
                                debouncedLoadOptimizationLog();
                                pollRestoreStatus(response.data.master_restore_key, $btn);
                            }
                        } else {
                            showToast(response?.data?.message || __('Failed to initiate safety backup.', 'optistate'), 'error');
                            $btn.html(`<span class="dashicons dashicons-backup"></span> ${__('Restore', 'optistate')}`);
                            resetRestoreUI();
                        }
                    },
                    error: function(xhr) {
                        showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : (xhr.responseJSON?.data?.message || __('Failed to initiate safety backup (network error).', 'optistate')), xhr.status === 429 ? 'warning' : 'error');
                        resetRestoreUI();
                    }
                });
            });
        });
    }

    function add_query_arg(args, url) {
        const params = new URLSearchParams();
        for (const key in args) params.append(key, args[key]);
        return url + (url.includes('?') ? '&' : '?') + params.toString();
    }

    function startChunkedUpload(file) {
        const CHUNK_SIZE_BYTES = file.size < SMALL_FILE_THRESHOLD ? SMALL_CHUNK_SIZE : LARGE_CHUNK_SIZE;
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE_BYTES);
        const uploadId = generateUploadId();
        currentUpload = {
            file,
            uploadId,
            totalChunks,
            currentChunk: 0,
            cancelled: false,
            chunkSize: CHUNK_SIZE_BYTES,
            retryCount: 0
        };
        $(SELECTORS.uploadProgress).show();
        $progressFill.css('width', '0%').text('0%');
        uploadNextChunk();
    }

    function uploadNextChunk() {
        if (!currentUpload || currentUpload.cancelled) {
            resetUploadUI();
            return;
        }

        function scheduleChunkRetry() {
            currentUpload.retryCount = (currentUpload.retryCount || 0) + 1;
            const delay = 2000 * currentUpload.retryCount;
            setTimeout(uploadNextChunk, delay);
        }
        const {
            chunkSize,
            file,
            currentChunk,
            totalChunks,
            uploadId
        } = currentUpload;
        const start = currentChunk * chunkSize;
        const end = Math.min(start + chunkSize, file.size);
        const chunk = file.slice(start, end);
        const formData = new FormData();
        formData.append('action', 'optistate_upload_restore_file');
        formData.append('nonce', optistate_BackupMgr.nonce);
        formData.append('chunk', chunk);
        formData.append('chunk_index', currentChunk);
        formData.append('total_chunks', totalChunks);
        formData.append('file_name', file.name);
        formData.append('file_size', file.size);
        formData.append('upload_id', uploadId);
        $.ajax({
            url: optistate_BackupMgr.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 300000,
            success: function(response) {
                currentUpload.retryCount = 0;
                if (!currentUpload || currentUpload.cancelled) {
                    resetUploadUI();
                    return;
                }
                if (response?.success) {
                    const data = response.data;
                    if (data.status === 'decompressing') {
                        uploadedFilePath = 'DECOMPRESSING';
                        $progressFill.css('width', '0%').text(__('Starting decompression...', 'optistate'));
                        $(SELECTORS.restoreWrapper).fadeIn(300);
                        showToast(__('File uploaded! Decompression starting...', 'optistate'), 'success');
                        currentUpload = null;
                        if (data.decompression_key) {
                            pollDecompressionStatus(data.decompression_key, $restoreFileBtn);
                        }
                        return;
                    }
                    if (data.complete) {
                        uploadedFilePath = data.temp_path;
                        if (data.file_size) $fileSize.text(data.file_size);
                        $progressFill.css('width', '100%').text(__('100% - Validation complete. Ready to restore.', 'optistate'));
                        $(SELECTORS.restoreWrapper).fadeIn(300);
                        showToast(__('File uploaded and validated! Click "Restore from File" to proceed.', 'optistate'), 'success');
                        currentUpload = null;
                    } else {
                        const percentComplete = data.progress || Math.round(((currentChunk + 1) / totalChunks) * 100);
                        $progressFill.css('width', percentComplete + '%').text(percentComplete + '%');
                        currentUpload.currentChunk++;
                        uploadNextChunk();
                    }
                } else {
                    const code = response?.data?.code || '';
                    const msg = response?.data?.message || '';
                    if (code === 'duplicate_chunk') {
                        currentUpload.retryCount = 0;
                        currentUpload.currentChunk++;
                        uploadNextChunk();
                        return;
                    }
                    if (code === 'lock_contention' && (currentUpload.retryCount || 0) < 3) {
                        scheduleChunkRetry();
                        return;
                    }
                    showToast(msg || __('Upload failed!', 'optistate'), 'error');
                    currentUpload = null;
                    resetUploadUI();
                }
            },
            error: function(xhr, status, error) {
                if (!currentUpload || currentUpload.cancelled) {
                    resetUploadUI();
                    return;
                }
                const errCode = xhr.responseJSON?.data?.code || '';
                const errorMsg = xhr.responseJSON?.data?.message || '';
                if (errCode === 'duplicate_chunk') {
                    currentUpload.retryCount = 0;
                    currentUpload.currentChunk++;
                    uploadNextChunk();
                    return;
                }
                const isTransient = errCode === 'lock_contention' || status === 'timeout' || xhr.status === 0 || (xhr.status >= 500 && xhr.status < 600);
                if (isTransient && (currentUpload.retryCount || 0) < 3) {
                    scheduleChunkRetry();
                    return;
                }
                const msg = errorMsg || __('Upload failed (Connection Error)', 'optistate');
                showToast(msg, 'error');
                currentUpload = null;
                resetUploadUI();
            }
        });
    }

    function resetUploadUI() {
        $(SELECTORS.uploadProgress).hide();
        $(SELECTORS.fileInfo).hide();
        $('#optistate-file-input').val('');
        $(SELECTORS.restoreWrapper).hide();
        uploadedFilePath = null;
        currentUpload = null;
    }

    function generateUploadId() {
        return Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    }

    function loadStats(forceRefresh = false, showSuccessToast = false, refreshHealthScore = true) {
        if (!forceRefresh && statsCache && (Date.now() - statsCacheTime) < STATS_CACHE_DURATION) {
            displayStats(statsCache);
            displayCleanupItems(statsCache);
            displaySystemStats(statsCache.system_stats || {});
            if (statsCache.formatted_total_size) {
                $dbSizeValue.text(statsCache.formatted_total_size);
            }
            hideSystemStatsLoading();
            return $.Deferred().resolve().promise();
        }
        if (forceRefresh) {
            showSystemStatsLoading();
        }
        const $statsContainer = $('#optistate-stats');
        showOverlay($statsContainer, __('Generating database statistics...', 'optistate'));
        $('#optistate-stats-loading').hide();
        const data = {
            action: 'optistate_get_stats',
            nonce: optistate_Ajax.nonce
        };
        if (forceRefresh) data.force_refresh = true;
        const request = $.post(optistate_Ajax.ajaxurl, data).done(function(response) {
            if (response && response.success && response.data) {
                const stats = response.data;
                statsCache = stats;
                statsCacheTime = Date.now();
                displayStats(stats);
                displayCleanupItems(stats);
                displaySystemStats(stats.system_stats || {});
                hideSystemStatsLoading();
                if (stats.formatted_total_size) {
                    $dbSizeValue.text(stats.formatted_total_size);
                }
                if (forceRefresh && refreshHealthScore) {
                    loadHealthScore(false);
                }
                if (showSuccessToast) {
                    showToast(__('Database statistics refreshed', 'optistate'), 'info');
                }
            } else {
                $dbSizeValue.text(__('Error', 'optistate'));
                $('#optistate-system-stats').html('<div class="optistate-error">' + __('Failed to load system information.', 'optistate') + '</div>');
            }
        }).fail(function(xhr) {
            if (xhr.status === 429 && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.stats) {
                const stats = xhr.responseJSON.data.stats;
                displayStats(stats);
                displayCleanupItems(stats);
                displaySystemStats(stats.system_stats || {});
                if (stats.formatted_total_size) {
                    $dbSizeValue.text(stats.formatted_total_size);
                }
            } else {
                $dbSizeValue.text(__('Error', 'optistate'));
                $('#optistate-system-stats').html('<div class="optistate-error">' + __('Failed to load system information.', 'optistate') + '</div>');
            }
            handleAjaxError(xhr);
            hideSystemStatsLoading();
        }).always(function() {
            hideOverlay($statsContainer);
        });
        return request;
    }

    function updateAutoStatusDisplay() {
        const days = parseInt($autoOptimizeDays.val(), 10) || 0;
        const time = $autoOptimizeTime.val();
        const isBackupOnly = $autoBackupOnly.is(':checked');
        const $enabledSpan = $('#auto-status-enabled');
        const $disabledSpan = $('#auto-status-disabled');
        const $backupOnlyStatus = $('#auto-backup-only-status');
        const $taskDescFull = $('#auto-task-desc-full');
        const $taskDescBackupOnly = $('#auto-task-desc-backup-only');
        const timeDisplay = formatTimeForDisplay(time);
        if (days > 0) {
            let statusText;
            if (isBackupOnly) {
                statusText = '✅ ' + sprintf(__('Automated *backup only* is enabled and will run every %s days at %s.', 'optistate'), days, esc_html(timeDisplay));
                $taskDescFull.hide();
                $taskDescBackupOnly.show();
            } else {
                statusText = '✅ ' + sprintf(__('Automated *backup & cleanup* is enabled and will run every %s days at %s.', 'optistate'), days, esc_html(timeDisplay));
                $taskDescFull.show();
                $taskDescBackupOnly.hide();
            }
            $enabledSpan.html(statusText);
            $enabledSpan.show();
            $disabledSpan.hide();
        } else {
            $disabledSpan.show();
            $enabledSpan.hide();
            $taskDescFull.show();
            $taskDescBackupOnly.hide();
        }
        if (isBackupOnly) {
            $backupOnlyStatus.html('✅ ' + __('Backup Only mode is enabled.', 'optistate'));
        } else {
            $backupOnlyStatus.html('ℹ️ ' + __('Backup & Cleanup mode is enabled.', 'optistate'));
        }
    }

    function resetButton() {
        isProcessing = false;
        const $btn = $('#run-pagespeed-btn');
        $btn.prop('disabled', false).html('<span class="dashicons dashicons-performance optst-adt-icn"></span> ' + __('Run Audit', 'optistate'));
    }
    $refreshStatsBtn.on('click', function() {
        if (isProcessing) return;
        loadStats(true, true);
    });
    $('#optistate-refresh-one-click').on('click', function() {
        const $btn = $(this);
        if ($btn.prop('disabled') || isProcessing) return;
        const originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline-margin"></span> ' + __('Refreshing...', 'optistate'));
        $('#optistate-one-click-count').css('opacity', '0.5');
        $.when(loadStats(true, true)).always(function() {
            $btn.prop('disabled', false).html(originalText);
            $('#optistate-one-click-count').css('opacity', '1');
        });
    });
    $body.on('click', '.os-switch-to-stats', function(e) {
        e.preventDefault();
        $('.nav-tab-wrapper a[href="#tab-stats"]').trigger('click');
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
    $body.on('click', '.optistate-refresh-cleanup-btn', function() {
        const $btn = $(this);
        if ($btn.prop('disabled') || isProcessing) return;
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline-margin"></span> ' + __('Refreshing...', 'optistate'));
        const $cleanupContainer = $('#optistate-cleanup-items');
        showOverlay($cleanupContainer, __('Refreshing cleanup data...', 'optistate'));
        $.when(loadStats(true, true)).always(function() {
            $btn.prop('disabled', false).html(originalHtml);
            hideOverlay($cleanupContainer);
        });
    });
    $(document).on('click', '.optistate-refresh-system-stats-btn', function() {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);
        loadStats(true, false, false).always(function() {
            $btn.prop('disabled', false);
        });
    });

    function initFileUploadEvents() {
        $('#optistate-file-input').on('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const fileName = file.name.toLowerCase();
            if (!fileName.endsWith('.sql') && !fileName.endsWith('.sql.gz')) {
                showToast(__('Only .sql and .sql.gz files are allowed!', 'optistate'), 'error');
                this.value = '';
                return;
            }
            if (file.size > MAX_FILE_SIZE) {
                showToast(__('File is too large! Maximum size is 5GB.', 'optistate'), 'error');
                this.value = '';
                return;
            }
            $('#optistate-file-name').text(esc_html(file.name));
            $fileSize.text(formatBytes(file.size)).attr('data-bytes', file.size);
            $(SELECTORS.fileInfo).show();
            startChunkedUpload(file);
        });
        $body.on('click', '#optistate-restore-file-btn', function() {
            if (isRestoreInProgress) {
                showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
                return;
            }
            if (uploadedFilePath === 'DECOMPRESSING') {
                showToast(__('⏳ File is still being decompressed. Please wait...', 'optistate'), 'info');
                return;
            }
            if (!uploadedFilePath) {
                showToast(__('Select a SQL file from your device first!', 'optistate'), 'error');
                return;
            }
            const $button = $(this);
            const fileName = $('#optistate-file-name').text();
            let sizeInBytes = $fileSize.attr('data-bytes');
            if (!sizeInBytes) sizeInBytes = parseSizeToBytes($fileSize.text());
            const message = `⚠️ ${__('WARNING: Restore Database from File', 'optistate')}<br><br>${sprintf(__('File: %s', 'optistate'), esc_html(fileName))}<br><br>${__('This will:', 'optistate')}<br>${__('• Create a safety backup first', 'optistate')}<br>${__('• Validate the database structure', 'optistate')}<br>${__('• Replace the current database', 'optistate')}<br><br>${__('ALL CURRENT DATA WILL BE REPLACED!', 'optistate')}<br><br>${__('Are you absolutely sure?', 'optistate')}`;
            showOPTISTATEModal(__('🔐 Restore from File', 'optistate'), message, function() {
                if (!acquireRestoreLock()) {
                    showToast(__('⛔ A restore is already in progress. Please wait for it to complete.', 'optistate'), 'error');
                    return;
                }
                $(SELECTORS.globalButtons).prop('disabled', true);
                $createBackupBtn.prop('disabled', true);
                const $wrapper = $(SELECTORS.restoreWrapper);
                $button.html(`<span class="spinner is-active os-spinner-inline"></span> ${__('INITIATING ....', 'optistate')}`);
                $button.prop('disabled', true);
                $wrapper.fadeIn(300);
                $.ajax({
                    url: optistate_BackupMgr.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'optistate_restore_from_file',
                        nonce: optistate_BackupMgr.nonce,
                        temp_path: uploadedFilePath
                    },
                    timeout: 1800000,
                    success: function(response) {
                        if (response?.success) {
                            showToast(getRestoreTimeEstimate(sizeInBytes) || __('Restore initiated!', 'optistate'), 'info');
                            $restoreRecoveryNotice.hide().removeClass('os-display-none').fadeIn(300);
                            if (response.data.status === 'decompressing') {
                                $button.html(`<span class="spinner is-active os-spinner-inline"></span> ${__('DECOMPRESSING BACKUP ....', 'optistate')}`);
                                pollDecompressionStatus(response.data.decompression_key, $button);
                            } else if (response.data.status === 'starting') {
                                $button.html(`<span class="spinner is-active os-spinner-inline"></span> ${__('CREATING SAFETY BACKUP ....', 'optistate')}`);
                                debouncedLoadOptimizationLog();
                                pollRestoreStatus(response.data.master_restore_key, $button);
                            }
                        } else {
                            showToast(response?.data?.message || __('Restore failed to start.', 'optistate'), 'error');
                            resetRestoreUI();
                        }
                    },
                    error: function(xhr) {
                        showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : (xhr.responseJSON?.data?.message || __('Restore failed to start. Please try again.', 'optistate')), xhr.status === 429 ? 'warning' : 'error');
                        resetRestoreUI();
                    }
                });
            });
        });
    }

    function initSettingsEvents() {
        $('#save-max-backups-btn').on('click', function() {
            if (isProcessing) return;
            const $btn = $(this);
            const maxBackups = parseInt($maxBackupsSetting.val(), 10);
            if (isNaN(maxBackups) || maxBackups < 1 || maxBackups > 10) {
                showToast(__('Please select a valid number of backups (1-10).', 'optistate'), 'error');
                return;
            }
            isProcessing = true;
            apiRequest({
                action: 'optistate_save_max_backups',
                data: {
                    max_backups: maxBackups
                },
                $btn: $btn,
                loadingText: `✓ ${__('Saving...', 'optistate')}`,
                errorMsg: __('Failed to save setting.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response?.success) {
                        showToast(__('Maximum backups setting saved successfully!', 'optistate'), 'success');
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response?.data?.message || __('Failed to save setting.', 'optistate'), 'error');
                    }
                }
            }).always(function() {
                isProcessing = false;
            });
        });
        $saveAutoOptimizeBtn.on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const autoOptimizeDays = parseInt($autoOptimizeDays.val(), 10);
            const autoOptimizeTime = $autoOptimizeTime.val();
            const maxBackups = parseInt($maxBackupsSetting.val(), 10);
            if (isNaN(maxBackups) || maxBackups < 1 || maxBackups > 10) return showToast(__('Please select a valid number of backups (1-10) in section 1.', 'optistate'), 'error');
            if (isNaN(autoOptimizeDays) || autoOptimizeDays < 0 || autoOptimizeDays > 365) return showToast(__('Please enter a valid number between 0 and 365 for days.', 'optistate'), 'error');
            if (!isValidTime(autoOptimizeTime)) return showToast(__('Please select a valid time from the dropdown.', 'optistate'), 'error');
            isProcessing = true;
            apiRequest({
                action: 'optistate_save_auto_settings',
                data: {
                    auto_optimize_days: autoOptimizeDays,
                    auto_optimize_time: autoOptimizeTime,
                    email_notifications: $emailNotifications.is(':checked') ? 1 : 0,
                    auto_backup_only: $autoBackupOnly.is(':checked') ? 1 : 0,
                    max_backups: maxBackups
                },
                $btn: $btn,
                loadingText: `✓ ${__('Saving...', 'optistate')}`,
                errorMsg: __('Failed to save settings.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response?.success) {
                        showToast(response.data.message, 'success');
                        if (response.data) updateUIAfterSave(response.data);
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response?.data?.message || __('Failed to save settings.', 'optistate'), 'error');
                    }
                }
            }).always(function() {
                isProcessing = false;
            });
        });
        const validateDaysInput = function() {
            let value = $autoOptimizeDays.val().replace(/\D/g, '');
            if (value.length > 3) value = value.substring(0, 3);
            if (parseInt(value, 10) > 365) value = '365';
            if ($autoOptimizeDays.val() !== value) $autoOptimizeDays.val(value);
        };
        $autoOptimizeDays.on('input blur change', validateDaysInput);
    }

    function isValidTime(time) {
        if (typeof time !== 'string' || time.length !== 5) return false;
        if (!/^\d{2}:\d{2}$/.test(time)) return false;
        const [hour, minute] = time.split(':').map(Number);
        return hour >= 0 && hour <= 23 && minute === 0;
    }

    function formatTimeForDisplay(time) {
        if (!isValidTime(time)) return __('Invalid Time', 'optistate');
        const hour = parseInt(time.split(':')[0], 10);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        return `${hour % 12 || 12}:00 ${ampm}`;
    }

    function updateUIAfterSave(data) {
        if (!data) return;
        const days = parseInt(data.days, 10) || 0;
        const timeDisplay = formatTimeForDisplay(data.time || '02:00');
        const autoBackupOnly = Boolean(data.auto_backup_only);
        const $enabledSpan = $('#auto-status-enabled');
        const $disabledSpan = $('#auto-status-disabled');
        const $emailEnabledSpan = $('#email-status-enabled');
        const $emailDisabledSpan = $('#email-status-disabled');
        const $backupOnlyStatus = $('#auto-backup-only-status');
        const $taskDescFull = $('#auto-task-desc-full');
        const $taskDescBackupOnly = $('#auto-task-desc-backup-only');
        if (days > 0) {
            const statusText = `✅ ${sprintf(autoBackupOnly ? __('Automated *backup only* is enabled and will run every %s days at %s.', 'optistate') : __('Automated *backup & cleanup* is enabled and will run every %s days at %s.', 'optistate'), days, esc_html(timeDisplay))}`;
            $enabledSpan.html(statusText).show();
            $disabledSpan.hide();
            if (autoBackupOnly) {
                $taskDescFull.hide();
                $taskDescBackupOnly.show();
            } else {
                $taskDescFull.show();
                $taskDescBackupOnly.hide();
            }
        } else {
            $disabledSpan.show();
            $enabledSpan.hide();
            $taskDescFull.show();
            $taskDescBackupOnly.hide();
        }
        if (data.email_notifications) {
            $emailEnabledSpan.show();
            $emailDisabledSpan.hide();
        } else {
            $emailEnabledSpan.hide();
            $emailDisabledSpan.show();
        }
        $backupOnlyStatus.html(autoBackupOnly ? `✅ ${__('Backup Only mode is enabled.', 'optistate')}` : `ℹ️ ${__('Backup & Cleanup mode is enabled.', 'optistate')}`);
        $autoOptimizeTime.val(data.time || '02:00');
    }
    $('#auto_optimize_days, #auto_optimize_time, #auto_backup_only').on('change input', updateAutoStatusDisplay);
    updateAutoStatusDisplay();

    function showOPTISTATEModal(title, message, onConfirm, isDanger) {
        const dangerClass = isDanger ? ' optistate-modal-danger' : '';
        const safeTitle = esc_html(title);
        const safeMessage = safeModalHTML(message);
        const $overlay = $('<div class="optistate-modal-overlay"></div>');
        const $modal = $(` <div class="optistate-modal${dangerClass}"> <div class="optistate-modal-header"> <h3>${safeTitle}</h3> <button class="optistate-modal-close" aria-label="${esc_attr(__('Close', 'optistate'))}">&times;</button> </div> <div class="optistate-modal-body">${safeMessage}</div> <div class="optistate-modal-footer"> <button class="button optistate-modal-cancel">${__('Cancel', 'optistate')}</button> <button class="button button-primary optistate-modal-confirm">${__('Confirm', 'optistate')}</button> </div> </div> `);
        $body.append($overlay, $modal);
        requestAnimationFrame(() => {
            $overlay.addClass('show');
            $modal.addClass('show');
        });
        const closeModal = () => {
            $overlay.removeClass('show');
            $modal.removeClass('show');
            setTimeout(() => {
                $overlay.remove();
                $modal.remove();
            }, 300);
        };
        $modal.find('.optistate-modal-close, .optistate-modal-cancel').on('click', closeModal);
        $overlay.on('click', e => {
            if (e.target === $overlay[0]) closeModal();
        });
        $modal.find('.optistate-modal-confirm').on('click', () => {
            closeModal();
            if (onConfirm) onConfirm();
        });
        $(document).one('keyup.OPTISTATE', e => {
            if (e.key === 'Escape') closeModal();
        });
    }

    function getBackupTimeEstimate(sizeInBytes) {
        const baseMsg = `${__('Database backup started!', 'optistate')}<br>${__('You can close this page - process will continue in the background.', 'optistate')}`;
        if (isNaN(sizeInBytes) || sizeInBytes <= 0) return baseMsg;
        const sizeMB = sizeInBytes / (1024 * 1024);
        let estimate;
        if (sizeMB < 130) estimate = __('Less than 1 minute.', 'optistate');
        else if (sizeMB < 280) estimate = __('Less than 2 minutes', 'optistate');
        else if (sizeMB < 760) estimate = __('Less than 5 minutes.', 'optistate');
        else if (sizeMB < 1600) estimate = __('Less than 10 minutes.', 'optistate');
        else if (sizeMB < 3300) estimate = __('Less than 20 minutes.', 'optistate');
        else if (sizeMB < 5000) estimate = __('Less than 30 minutes.', 'optistate');
        else estimate = __('30+ minutes.', 'optistate');
        return `${baseMsg}<br>${sprintf(__('⏱️ Estimated time: %s', 'optistate'), estimate)}`;
    }

    function getRestoreTimeEstimate(sizeInBytes) {
        const base = __('Database restore started!<br>', 'optistate');
        const end = __('<br>You can leave this page - process will continue in the background.', 'optistate');
        if (isNaN(sizeInBytes) || sizeInBytes <= 0) return base + end;
        const sizeMB = sizeInBytes / (1024 * 1024);
        let time;
        if (sizeMB < 28) time = __('Less than 1 minute.', 'optistate');
        else if (sizeMB < 85) time = __('Less than 3 minutes.', 'optistate');
        else if (sizeMB < 130) time = __('Less than 5 minutes.', 'optistate');
        else if (sizeMB < 280) time = __('Less than 10 minutes.', 'optistate');
        else if (sizeMB < 600) time = __('Less than 20 minutes.', 'optistate');
        else if (sizeMB < 940) time = __('Less than 30 minutes.', 'optistate');
        else time = __('30+ minutes.', 'optistate');
        return `${base}⏱️ ${sprintf(__('Estimated time: %s', 'optistate'), time)}${end}`;
    }

    function showToast(message, type = 'success') {
        const safeType = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
        const $toast = $(` <div class="optistate-toast optistate-toast-${safeType}"> <span class="optistate-toast-icon"></span> <span class="optistate-toast-message"></span> <button class="optistate-toast-close" aria-label="${__('Close notification', 'optistate')}"> <span aria-hidden="true">&times;</span> </button> </div> `);
        const $messageEl = $toast.find('.optistate-toast-message');
        const formattedMessage = String(message).replace(/\n/g, '<br>');
        const safeMessage = safeModalHTML(formattedMessage);
        $messageEl.html(safeMessage);
        let $container = $('#optistate-toast-container');
        if (!$container.length) {
            $container = $('<div id="optistate-toast-container"></div>').appendTo('body');
        }
        $container.prepend($toast);
        $toast[0].offsetHeight;
        $toast.addClass('show');
        $toast.find('.optistate-toast-close').on('click', () => {
            $toast.removeClass('show').addClass('removing');
            setTimeout(() => $toast.remove(), 400);
        });
        setTimeout(() => {
            if ($toast.parent().length) {
                $toast.removeClass('show').addClass('removing');
                setTimeout(() => $toast.remove(), 400);
            }
        }, 18000);
    }

    function showSystemStatsLoading() {
        const $container = $('#optistate-system-stats-container');
        if ($container.length) {
            showOverlay($container, __('Refreshing system information...', 'optistate'));
        }
    }

    function hideSystemStatsLoading() {
        const $container = $('#optistate-system-stats-container');
        if ($container.length) {
            hideOverlay($container);
        }
    }

    function getRateLimitMessage(isSaveAction) {
        return isSaveAction ? optistate_Ajax.rate_limit_save_message : optistate_Ajax.rate_limit_message;
    }

    function handleAjaxError(xhr, customMsg, isSaveAction) {
        let errorMsg;
        if (xhr.status === 429) errorMsg = xhr.responseJSON?.data?.message || getRateLimitMessage(isSaveAction);
        else if (xhr.status === 403) errorMsg = __('Security session expired. Please refresh the page.', 'optistate');
        else errorMsg = xhr.responseJSON?.data?.message || customMsg || sprintf(__('An error occurred (Status: %d). Please try again.', 'optistate'), xhr.status);
        showToast(errorMsg, xhr.status === 429 ? 'warning' : 'error');
        isProcessing = false;
        $('.optistate-action-btn').prop('disabled', false);
    }

    function displaySystemStats(stats) {
        if (!stats || typeof stats !== 'object') {
            $('#optistate-system-stats').html('<div class="optistate-error">' + __('System information temporarily unavailable.', 'optistate') + '</div>');
            return;
        }
        const $container = $('#optistate-system-stats');
        if (!$container.length) return;
        const fragment = document.createDocumentFragment();
        Object.keys(systemLabels).forEach(key => {
            let value = stats[key];
            if (value === undefined || value === null) value = 'N/A';
            if ((key === 'disk_total' || key === 'disk_used' || key === 'disk_free' || key === 'php_memory_limit' || key === 'total_ram') && (value === 0 || value === '0')) {
                value = 'N/A';
            } else if (key === 'disk_total' || key === 'disk_used' || key === 'disk_free' || key === 'php_memory_limit' || key === 'total_ram') {
                value = formatSystemBytes(value);
            } else if (key === 'active_plugins_count') {
                value = value.toLocaleString();
            }
            const div = document.createElement('div');
            div.className = 'optistate-stat-item';
            if (key === 'error_logging') {
                const raw = String(value);
                let valueHtml = escapeHTML(raw);
                if (raw.indexOf('ON') !== -1 && raw.indexOf('B') !== -1) {
                    const sizePart = raw.replace(/^ON\s*[\/—]\s*/, '').trim();
                    valueHtml = `ON — <a href="#" class="optistate-download-error-log os-no-decoration" style="text-decoration:none;cursor:pointer;" title="${__('Click to download debug.log', 'optistate')}">${escapeHTML(sizePart)} ⬇</a>`;
                }
                div.innerHTML = `<div class="optistate-stat-label">${escapeHTML(systemLabels[key])}</div><div class="optistate-stat-value">${valueHtml}</div>`;
                fragment.appendChild(div);
                return;
            }
            if (key === 'htaccess_info') {
                const info = value;
                let valueHtml = '';
                if (!info.exists) {
                    valueHtml = `<span style="color:#d63638;">${__('Not found', 'optistate')}</span>`;
                } else {
                    const sizeText = info.size_formatted || __('N/A', 'optistate');
                    const mtimeText = info.mtime_formatted || __('N/A', 'optistate');
                    valueHtml = `<a href="#" class="optistate-download-htaccess os-no-decoration" title="${esc_attr(__('Click to download htaccess.txt', 'optistate'))}">${escapeHTML(sizeText)} ⬇</a>` + ` — ${__('Updated:', 'optistate')} ${escapeHTML(mtimeText)}`;
                }
                div.innerHTML = `<div class="optistate-stat-label">${escapeHTML(systemLabels[key])}</div><div class="optistate-stat-value">${valueHtml}</div>`;
                fragment.appendChild(div);
                return;
            }
            let valueHtml = escapeHTML(String(value));
            if (key === 'disk_free' && stats.disk_total && stats.disk_total > 0) {
                const freeBytes = stats.disk_free;
                const totalBytes = stats.disk_total;
                if (freeBytes < totalBytes * 0.1) {
                    const warningMsg = __('Disk space usage is over 90%. To avoid errors, please remove unnecessary files to free up space.', 'optistate');
                    valueHtml += ` <span class="optistate-warning-icon" style="cursor: help; color: #f0ad4e;" title="${escapeHTML(warningMsg)}">⚠️</span>`;
                }
            }
            if (key === 'active_plugins_count') {
                const count = parseInt(stats[key], 10);
                if (!isNaN(count) && count > 20) {
                    const warningMsg = __('Having many active plugins can slow down your website. Please review your plugins and deactivate unnecessary ones.', 'optistate');
                    valueHtml += ` <span class="optistate-warning-icon" style="cursor: help; color: #f0ad4e;" title="${escapeHTML(warningMsg)}">⚠️</span>`;
                }
            }
            div.innerHTML = `<div class="optistate-stat-label">${escapeHTML(systemLabels[key])}</div><div class="optistate-stat-value">${valueHtml}</div>`;
            fragment.appendChild(div);
        });
        $container.empty().append(fragment);
    }

    function displayStats(stats) {
        if (!stats || typeof stats !== 'object') return;
        const currentHeight = $statsContainer.outerHeight();
        if (currentHeight > 0) $statsContainer.css({
            minHeight: currentHeight
        });
        const fragment = document.createDocumentFragment();
        Object.keys(stats).forEach(key => {
            if (!labels[key]) return;
            let value = (stats[key] === false || stats[key] === null) ? '0 B' : stats[key];
            let isHtml = false;
            if (key === 'engine_distribution') {
                if (typeof value === 'object' && value !== null) {
                    const parts = [];
                    for (const engine in value) {
                        if (value.hasOwnProperty(engine)) {
                            const data = value[engine];
                            parts.push(engine + ': ' + data.count + ' tables (' + data.size + ')');
                        }
                    }
                    value = parts.length ? parts.join('<br>') : 'N/A';
                    isHtml = true;
                } else {
                    value = 'N/A';
                }
            }
            if (key === 'db_creation_date') {
                value = `<span class="os-nowrap">${esc_html(value)}</span>`;
                isHtml = true;
            } else if (!isHtml) {
                const numValue = typeof value === 'number' ? value : parseInt(value, 10);
                value = (!isNaN(numValue) && String(numValue) === String(value)) ? esc_html(numValue.toLocaleString()) : esc_html(String(value));
            }
            const label = labels[key];
            let labelHtml = esc_html(label);
            if (STATS_TOOLTIPS[key]) {
                labelHtml += ` <span class="dashicons dashicons-info" title="${esc_attr(STATS_TOOLTIPS[key])}" style="cursor:help; font-size:16px; vertical-align:middle;"></span>`;
            }
            const div = document.createElement('div');
            div.className = 'optistate-stat-item';
            div.innerHTML = `<div class="optistate-stat-label">${labelHtml}</div><div class="optistate-stat-value">${value}</div>`;
            fragment.appendChild(div);
        });
        $statsContainer.empty().append(fragment).css({
            minHeight: ''
        });
        debouncedLoadOptimizationLog();
        const config = window.optistate_OneClickConfig || {};
        let defaultKeys = config.default_keys || [];
        let extraKeys = config.extra_items || [];
        const allKeys = defaultKeys.concat(extraKeys);
        if (allKeys.indexOf('all_transients') !== -1) {
            defaultKeys = defaultKeys.filter(k => k !== 'expired_transients');
            extraKeys = extraKeys.filter(k => k !== 'expired_transients');
        }
        const defaultCount = defaultKeys.reduce((acc, key) => acc + (parseInt(stats[key], 10) || 0), 0);
        const optionalCount = extraKeys.reduce((acc, key) => acc + (parseInt(stats[key], 10) || 0), 0);
        const existingDiv = document.getElementById('optistate-one-click-count');
        if (existingDiv) existingDiv.remove();
        const container = document.createElement('div');
        container.id = 'optistate-one-click-count';
        let messageLines = [];
        if (defaultCount > 0) {
            messageLines.push(sprintf(__('🛈 %s safe items available to clean.', 'optistate'), defaultCount.toLocaleString()));
        } else if (optionalCount === 0) {}
        if (optionalCount > 0) {
            messageLines.push(sprintf(__('🛈 %s optional items available to clean.', 'optistate'), optionalCount.toLocaleString()));
        }
        if (messageLines.length === 0) {
            messageLines.push(__('✅ No items available to clean.', 'optistate'));
        }
        messageLines.forEach((line, index) => {
            const textNode = document.createTextNode(line);
            container.appendChild(textNode);
            if (index < messageLines.length - 1) {
                container.appendChild(document.createElement('br'));
            }
        });
        container.appendChild(document.createElement('br'));
        const link = document.createElement('a');
        link.href = '#tab-stats';
        link.className = 'os-switch-to-stats os-no-decoration';
        link.textContent = __('Check the Statistics', 'optistate');
        container.appendChild(document.createTextNode(' '));
        container.appendChild(link);
        container.appendChild(document.createTextNode(' ' + __('for more details.', 'optistate')));
        container.className = (defaultCount > 0 || optionalCount > 0) ? 'os-mt-18-weight' : 'os-mt-18-italic';
        document.getElementById('optistate-one-click').insertAdjacentElement('afterend', container);
    }

    function debouncedLoadOptimizationLog() {
        clearTimeout(debounceLogTimer);
        debounceLogTimer = setTimeout(loadOptimizationLog, 500);
    }

    function loadOptimizationLog() {
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_get_optimization_log',
            nonce: optistate_Ajax.nonce
        }).done(response => {
            if (response?.success && response.data) displayOptimizationLog(response.data);
        });
    }

    function displayOptimizationLog(log) {
        if (!Array.isArray(log)) return;
        let html = '<div class="optistate-log"><div class="optistate-log-list">';
        if (log.length === 0) {
            html += `<div class="optistate-log-empty">${__('No significant events have been recorded yet.', 'optistate')}</div>`;
        } else {
            log.forEach(entry => {
                if (!entry.type || !entry.date || !entry.operation) return;
                const typeLabel = entry.type === 'manual' ? __('Manual', 'optistate') : __('Scheduled', 'optistate');
                html += ` <div class="optistate-log-item"> <span class="optistate-log-date">${esc_html(entry.date)}</span> <span class="optistate-log-operation">${esc_html(entry.operation || '🚀 ' + __('One-Click Optimization', 'optistate'))}</span> <span class="optistate-log-type ${entry.type === 'manual' ? 'manual' : 'scheduled'}">${esc_html(typeLabel)}</span> </div>`;
            });
        }
        html += `</div><div class="os-mt-18">ℹ️ ${__('This log displays 250 most recent events.', 'optistate')}</div></div>`;
        $('#optistate-settings-log').html(html).hide().fadeIn(300);
    }
    const CLEANUP_ITEMS = [{
        key: 'post_revisions',
        title: __('📝 Post Revisions', 'optistate'),
        desc: __('Saved copies of posts and pages that accumulate each time you update', 'optistate'),
        safe: true
    }, {
        key: 'auto_drafts',
        title: __('🗒️ Auto Drafts', 'optistate'),
        desc: __('Automatically saved drafts that never became published posts or pages', 'optistate'),
        safe: true
    }, {
        key: 'trashed_posts',
        title: __('🗑️ Trashed Posts', 'optistate'),
        desc: __('Posts and pages that have been moved to the trash', 'optistate'),
        safe: false
    }, {
        key: 'spam_comments',
        title: __('🚫 Spam Comments', 'optistate'),
        desc: __('Comments that have been marked as spam by anti‑spam tools', 'optistate'),
        safe: false
    }, {
        key: 'unapproved_comments',
        title: __('⏳ Unapproved Comments', 'optistate'),
        desc: __('Comments that are awaiting moderation', 'optistate'),
        safe: false
    }, {
        key: 'trashed_comments',
        title: __('🚮 Trashed Comments', 'optistate'),
        desc: __('Comments that have been moved to the trash', 'optistate'),
        safe: true
    }, {
        key: 'orphaned_postmeta',
        title: __('👻 Orphaned Post Meta', 'optistate'),
        desc: __('Metadata entries that belong to posts which no longer exist', 'optistate'),
        safe: true
    }, {
        key: 'orphaned_commentmeta',
        title: __('🕸️ Orphaned Comment Meta', 'optistate'),
        desc: __('Metadata entries that belong to comments which no longer exist', 'optistate'),
        safe: true
    }, {
        key: 'orphaned_relationships',
        title: __('⛓️‍💥 Orphaned Term Relationships', 'optistate'),
        desc: __('Term assignments where either the term or the linked object (post, user, or link) is missing', 'optistate'),
        safe: true
    }, {
        key: 'orphaned_usermeta',
        title: __('🪪 Orphaned User Meta', 'optistate'),
        desc: __('Metadata entries that belong to users who no longer exist', 'optistate'),
        safe: true
    }, {
        key: 'orphaned_termmeta',
        title: __('🏷️ Orphaned Term Meta', 'optistate'),
        desc: __('Metadata entries that belong to taxonomy terms which no longer exist', 'optistate'),
        safe: true
    }, {
        key: 'expired_transients',
        title: __('⏱️ Expired Transients', 'optistate'),
        desc: __('Temporary cached data that has passed its expiration time', 'optistate'),
        safe: true
    }, {
        key: 'all_transients',
        title: __('💾 All Transients', 'optistate'),
        desc: __('All cached temporary data, including both expired and active items', 'optistate'),
        safe: false
    }, {
        key: 'duplicate_postmeta',
        title: __('📑 Duplicate Post Meta', 'optistate'),
        desc: __('Duplicate metadata entries for the same post', 'optistate'),
        safe: true
    }, {
        key: 'duplicate_commentmeta',
        title: __('📋 Duplicate Comment Meta', 'optistate'),
        desc: __('Duplicate metadata entries for the same comment', 'optistate'),
        safe: true
    }, {
        key: 'duplicate_usermeta',
        title: __('👤 Duplicate User Meta', 'optistate'),
        desc: __('Duplicate metadata entries for the same user', 'optistate'),
        safe: true
    }, {
        key: 'duplicate_termmeta',
        title: __('🏷️ Duplicate Term Meta', 'optistate'),
        desc: __('Duplicate metadata entries for the same taxonomy term', 'optistate'),
        safe: true
    }, {
        key: 'pingbacks',
        title: __('🔔 Pingbacks', 'optistate'),
        desc: __('Automated notifications from other blogs linking to your content', 'optistate'),
        safe: true
    }, {
        key: 'trackbacks',
        title: __('📡 Trackbacks', 'optistate'),
        desc: __('Automated notifications similar to pingbacks', 'optistate'),
        safe: true
    }, {
        key: 'action_scheduler',
        title: __('⚙️ Action Logs', 'optistate'),
        desc: __('Completed, failed, and canceled action scheduler entries', 'optistate'),
        safe: true
    }, {
        key: 'oembed_cache',
        title: __('🎬 oEmbed Cache', 'optistate'),
        desc: __('Cached embed data from external services (YouTube, Twitter, etc)', 'optistate'),
        safe: true
    }, {
        key: 'woo_bloat',
        title: __('🛒 WooCommerce Sessions/Logs', 'optistate'),
        desc: __('Expired WooCommerce sessions, transients, and cache data', 'optistate'),
        safe: true
    }, {
        key: 'empty_taxonomies',
        title: __('📂 Empty Taxonomies', 'optistate'),
        desc: __('Taxonomy terms (categories, tags, etc) with no posts assigned, plus unregistered taxonomy entries', 'optistate'),
        safe: false
    }];

    function displayCleanupItems(stats) {
        if (!stats || typeof stats !== 'object') return;
        const items = CLEANUP_ITEMS;
        const isFirstLoad = $cleanupItemsContainer.children().length === 0;
        const fragment = document.createDocumentFragment();
        items.forEach(item => {
            const count = parseInt(stats[item.key], 10) || 0;
            const warningIcon = !item.safe ? `<span class="optistate-warning-icon" title="${esc_attr(__('Review before cleaning', 'optistate'))}">⚠️</span>` : '';
            const div = document.createElement('div');
            div.className = `optistate-cleanup-item ${count > 0 ? 'has-items' : ''}`;
            div.innerHTML = ` <div class="optistate-cleanup-header"> <span class="optistate-cleanup-title">${item.title} ${warningIcon}</span> <span class="optistate-cleanup-count">${count.toLocaleString()}</span> </div> <div class="optistate-cleanup-desc">${item.desc}</div> <button class="optistate-clean-btn" data-type="${esc_attr(item.key)}" data-safe="${item.safe}"${count === 0 ? ' disabled' : ''}>${__('Clean Now', 'optistate')}</button> `;
            fragment.appendChild(div);
        });
        if (isFirstLoad) {
            $cleanupItemsContainer.empty().append(fragment).hide().fadeIn(300);
        } else {
            $cleanupItemsContainer.stop(true, true).fadeTo(150, 0.3, function() {
                $(this).empty().append(fragment).fadeTo(200, 1);
            });
        }
    }

    function initCleanupEvents() {
        $body.on('click', '.optistate-clean-btn:not(:disabled)', function() {
            if (isProcessing) return;
            var $btn = $(this);
            var itemType = $btn.data('type');
            var isSafe = $btn.data('safe');
            if (!itemType) return;
            var itemName = labels[itemType] || itemType;
            var itemCount = statsCache?.[itemType] ? parseInt(statsCache[itemType], 10) : 0;
            var displayItemName = itemCount > 0 ? esc_html(itemName) + ' <strong>(' + itemCount.toLocaleString() + ')</strong>' : esc_html(itemName);
            var title = isSafe ? '🧹 ' + __('Confirm Cleanup', 'optistate') : '⚠️ ' + __('Warning: Permanent Deletion', 'optistate');
            var confirmMsg = '➜ ' + displayItemName + '<br><br>' + (isSafe ? __('Clean this item? This action cannot be undone.', 'optistate') : __('Make sure you no longer need these items.<br>Are you sure you want to continue?', 'optistate'));
            showOPTISTATEModal(title, confirmMsg, function() {
                isProcessing = true;
                $btn.prop('disabled', true).addClass('loading').text(__('🧹 Cleaning...', 'optistate'));
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_clean_item',
                    nonce: optistate_Ajax.nonce,
                    item_type: itemType
                }).done(function(response) {
                    isProcessing = false;
                    if (response && response.success) {
                        $btn.removeClass('loading').addClass('success').text('✓ ' + __('Cleaned', 'optistate'));
                        showToast(__('Successfully cleaned!', 'optistate'), 'success');
                        setTimeout(function() {
                            loadStats(true);
                        }, 2500);
                    } else {
                        $btn.removeClass('loading').prop('disabled', false).text(__('Error - Try Again', 'optistate'));
                        showToast(response && response.data && response.data.message ? response.data.message : __('Cleanup failed', 'optistate'), 'error');
                    }
                }).fail(function(xhr) {
                    handleAjaxError(xhr);
                    $btn.removeClass('loading').prop('disabled', false).text(__('Error - Try Again', 'optistate'));
                });
            }, !isSafe);
        });
        $('#optistate-optimize-tables').on('click', function() {
            if (isProcessing) return;
            var $btn = $(this);
            var message = __('This process performs a maintenance defragmentation on your database tables.', 'optistate') + '<br><br>' + __('• <strong>Reclaims unused space</strong> (data overhead)', 'optistate') + '<br>' + __('• <strong>Defragments data files</strong> for better I/O performance', 'optistate') + '<br><br>' + __('<strong>⚠️ Note:</strong> For very large databases, this operation might temporarily lock tables while they are being rebuilt.', 'optistate');
            showOPTISTATEModal('⚡ ' + __('Optimize Database Tables', 'optistate'), message, function() {
                isProcessing = true;
                $('.optistate-advanced-op-btn').prop('disabled', true);
                $btn.addClass('loading').text('⚡ ' + __('Starting...', 'optistate'));
                (function runOptimizationStep() {
                    $.post(optistate_Ajax.ajaxurl, {
                        action: 'optistate_optimize_tables',
                        nonce: optistate_Ajax.nonce
                    }).done(function(response) {
                        if (response && response.success && response.data) {
                            var data = response.data;
                            if (data.status === 'running') {
                                $btn.text(data.message || '⚡ ' + (data.percentage || 0) + '%');
                                runOptimizationStep();
                                return;
                            }
                            var messages = [sprintf(__('Successfully optimized %s tables!', 'optistate'), (parseInt(data.optimized, 10) || 0).toLocaleString())];
                            if (data.reclaimed > 0) messages.push(sprintf(__('Reclaimed %s of space.', 'optistate'), formatBytes(data.reclaimed)));
                            if (data.skipped > 0) messages.push(sprintf(__('%s tables skipped (no optimization needed).', 'optistate'), parseInt(data.skipped, 10).toLocaleString()));
                            if (data.failed > 0) messages.push(sprintf(__('%s tables failed to optimize.', 'optistate'), parseInt(data.failed, 10).toLocaleString()));
                            var detailsHtml = '<div class="optistate-success">' + messages.join('<br>') + '</div>';
                            if (data.details && data.details.length) {
                                detailsHtml += '<div class="optistate-details os-details-container"><strong>' + __('Detailed Results:', 'optistate') + '</strong><ul class="os-m-5-0">';
                                data.details.forEach(function(d) {
                                    if (!d.table || !d.status) return;
                                    var icon = d.status === 'optimized' ? '✅' : (d.status === 'failed' ? '❌' : '⏩');
                                    detailsHtml += '<li>' + icon + ' ' + esc_html(d.table) + ': ' + esc_html(d.status) + (d.reclaimed ? ' (' + sprintf(__('reclaimed %s', 'optistate'), esc_html(d.reclaimed)) + ')' : '') + (d.error ? ' - ' + esc_html(d.error) : '') + '</li>';
                                });
                                detailsHtml += '</ul></div>';
                            }
                            $('#optistate-table-results').addClass('show').html(detailsHtml).hide().fadeIn(300);
                            showToast(messages.join('<br>'), data.failed > 0 ? 'warning' : 'success');
                            debouncedLoadOptimizationLog();
                            isProcessing = false;
                        } else {
                            handleAjaxError({
                                responseJSON: response
                            });
                        }
                    }).fail(handleAjaxError).always(function() {
                        if (!isProcessing) {
                            $('.optistate-advanced-op-btn').prop('disabled', false);
                            $btn.removeClass('loading').text('⚡ ' + __('Optimize All Tables', 'optistate'));
                        }
                    });
                })();
            });
        });
        $('#optistate-analyze-repair-tables').on('click', function() {
            if (isProcessing) return;
            var $btn = $(this);
            var message = __('This operation checks the structural integrity of all database tables.', 'optistate') + '<br><br>' + __('• <strong>Checks</strong> for table corruption and errors', 'optistate') + '<br>' + __('• <strong>Automatically attempts repair</strong> if issues are found', 'optistate') + '<br>' + __('• <strong>Updates index statistics</strong> for query optimizer', 'optistate') + '<br><br>' + __('<strong>ℹ️ Note:</strong> This process can be resource-intensive.', 'optistate');
            showOPTISTATEModal('🛠️ ' + __('Analyze & Repair Database', 'optistate'), message, function() {
                isProcessing = true;
                $('.optistate-advanced-op-btn').prop('disabled', true);
                $btn.addClass('loading').text('▶️ ' + __('Initiating...', 'optistate'));
                $('#optistate-table-results').removeClass('show').html('').hide();
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_initiate_analyze_repair',
                    nonce: optistate_Ajax.nonce
                }).done(function(response) {
                    if (response && response.success && response.data.status === 'starting') {
                        processAnalyzeRepairChunk(response.data.transient_key, $btn);
                    } else {
                        showToast(response && response.data ? response.data.message : __('Failed to initiate process.', 'optistate'), 'error');
                        isProcessing = false;
                        $('.optistate-advanced-op-btn').prop('disabled', false);
                        $btn.removeClass('loading').text('🛠️ ' + __('Analyze & Repair Tables', 'optistate'));
                    }
                }).fail(function(xhr) {
                    handleAjaxError(xhr);
                    isProcessing = false;
                    $('.optistate-advanced-op-btn').prop('disabled', false);
                    $btn.removeClass('loading').text('🛠️ ' + __('Analyze & Repair Tables', 'optistate'));
                });
            });
        });
        $('#optistate-optimize-autoload').on('click', function() {
            if (isProcessing) return;
            var $btn = $(this);
            $btn.prop('disabled', true).addClass('loading').html('<span class="spinner is-active os-spinner-inline"></span> ' + __('Fetching options...', 'optistate'));
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_preview_autoload_options',
                nonce: optistate_Ajax.nonce
            }).done(function(response) {
                $btn.prop('disabled', false).removeClass('loading').html('⚙️ ' + __('Optimize Autoloaded Options', 'optistate'));
                if (!response.success) {
                    showToast(response.data.message || __('Failed to fetch preview.', 'optistate'), 'error');
                    return;
                }
                var data = response.data;
                if (data.count === 0) {
                    showOPTISTATEModal('⚙️ ' + __('Optimize Autoloaded Options', 'optistate'), __('No autoloaded options need optimization. Your site is already optimized!', 'optistate'), null, false);
                    return;
                }
                var listHtml = '<div class="optistate-tb-mod">';
                listHtml += '<div class="optistate-tb-lst">' + sprintf(__('Autoloaded Options (%s)', 'optistate'), data.count.toLocaleString()) + '</div>';
                listHtml += '<ul class="optistate-tblist-ul">';
                var displayed = 0;
                var candidates = data.candidates || [];
                candidates.forEach(function(item) {
                    if (displayed >= 200) return;
                    listHtml += '<li>';
                    listHtml += '<code style="display:flex; justify-content:space-between; align-items:center; padding:2px 10px;">';
                    listHtml += '<span>' + esc_html(item.name) + '</span>';
                    listHtml += '<span style="margin-left:15px;">' + esc_html(item.size_formatted) + '</span>';
                    listHtml += '</code>';
                    listHtml += '</li>';
                    displayed++;
                });
                listHtml += '</ul>';
                if (data.count > 200) {
                    listHtml += '<p style="margin:8px 0 4px;font-style:italic;color:#666;">' + sprintf(__('... and %s more options.', 'optistate'), (data.count - 200).toLocaleString()) + '</p>';
                }
                listHtml += '</div>';
                var rawSize = parseInt(data.total_size, 10) || 0;
                var totalSize = formatBytes(rawSize);
                var message = sprintf(__('This will optimize %s items, reducing autoload size by approximately %s.', 'optistate'), data.count.toLocaleString(), totalSize) + '<br><br>' + listHtml + '<br>' + __('Are you sure you want to proceed?', 'optistate');
                showOPTISTATEModal('⚙️ ' + __('Optimize Autoloaded Options', 'optistate'), message, function() {
                    isProcessing = true;
                    $btn.addClass('loading').html('<span class="spinner is-active os-spinner-inline"></span> ' + __('Optimizing....', 'optistate'));
                    $('.optistate-advanced-op-btn').prop('disabled', true);
                    $.post(optistate_Ajax.ajaxurl, {
                        action: 'optistate_optimize_autoload',
                        nonce: optistate_Ajax.nonce
                    }).done(function(optResponse) {
                        if (optResponse && optResponse.success && optResponse.data) {
                            var optData = optResponse.data;
                            var msg = (optData.optimized > 0) ? sprintf(__('Optimized %s autoloaded options', 'optistate'), parseInt(optData.optimized, 10).toLocaleString()) : __('No autoloaded options needed optimization.', 'optistate') + '<br>' + __('Your autoloaded options are already optimized!', 'optistate');
                            if (optData.optimized > 0) {
                                if (optData.total_size_reduced > 0) {
                                    msg += ', ' + sprintf(__('reduced autoload size by %s MB', 'optistate'), (parseFloat(optData.total_size_reduced) / 1024 / 1024).toFixed(2));
                                }
                                if (optData.skipped > 0) {
                                    msg += '. ' + sprintf(__('<br>%s essential options preserved.', 'optistate'), parseInt(optData.skipped, 10).toLocaleString());
                                }
                                var $container = $('#optistate-autoload-restore-container');
                                if ($container.length) {
                                    var count = optData.optimized || 0;
                                    $('#optistate-autoload-restore-count').text(count.toLocaleString());
                                    $container.show();
                                }
                            }
                            var detailsHtml = '<div class="optistate-success">' + msg + '</div>';
                            if (optData.details && optData.details.length > 0) {
                                var optimized = [],
                                    preserved = [];
                                optData.details.forEach(function(d) {
                                    if (d.status === 'optimized') optimized.push(d);
                                    else if (d.status === 'skipped') preserved.push(d);
                                });
                                if (optimized.length > 0) {
                                    detailsHtml += '<div class="optistate-details os-details-container">';
                                    detailsHtml += '<strong>' + sprintf(__('Optimized Options (%s total):', 'optistate'), parseInt(optimized.length, 10).toLocaleString()) + '</strong>';
                                    detailsHtml += '<ul class="os-m-5-0">';
                                    optimized.forEach(function(d) {
                                        detailsHtml += '<li>✅ ' + esc_html(d.option) + ' (' + esc_html(d.size) + ')</li>';
                                    });
                                    detailsHtml += '</ul>';
                                }
                                if (preserved.length > 0) {
                                    detailsHtml += '<hr style="margin:15px 15px 10px;"><strong>' + sprintf(__('Preserved Options (%s total):', 'optistate'), parseInt(preserved.length, 10).toLocaleString()) + '</strong>';
                                    detailsHtml += '<ul class="os-m-5-0">';
                                    preserved.forEach(function(d) {
                                        var reason = d.reason ? ' (' + esc_html(d.reason) + ')' : '';
                                        detailsHtml += '<li>🔒 ' + esc_html(d.option) + ' (' + esc_html(d.size) + ')' + reason + '</li>';
                                    });
                                    detailsHtml += '</ul></div>';
                                }
                            }
                            $('#optistate-table-results').addClass('show').html(detailsHtml).hide().fadeIn(300);
                            showToast(msg, 'success');
                            setTimeout(function() {
                                loadStats(true);
                            }, 1500);
                        } else {
                            showToast(optResponse?.data?.message || __('Optimization failed.', 'optistate'), 'error');
                        }
                    }).fail(function(xhr) {
                        var errMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (xhr.status === 429 ? getRateLimitMessage(false) : __('Network error.', 'optistate'));
                        showToast(errMsg, xhr.status === 429 ? 'warning' : 'error');
                    }).always(function() {
                        isProcessing = false;
                        $('.optistate-advanced-op-btn').prop('disabled', false);
                        $btn.removeClass('loading').html('⚙️ ' + __('Optimize Autoloaded Options', 'optistate'));
                    });
                }, false);
            }).fail(function(xhr) {
                $btn.prop('disabled', false).removeClass('loading').html('⚙️ ' + __('Optimize Autoloaded Options', 'optistate'));
                var errMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (xhr.status === 429 ? getRateLimitMessage(false) : __('Network error.', 'optistate'));
                showToast(errMsg, xhr.status === 429 ? 'warning' : 'error');
            });
        });
        $('#optistate-restore-autoload-btn').on('click', function() {
            if (isProcessing) return;
            var $btn = $(this);
            var originalText = $btn.html();
            showOPTISTATEModal('↩️ ' + __('Restore Autoload Backup', 'optistate'), __('This will restore the previous autoload status and values for all options that were disabled during the last optimization.<br><br>Are you sure you want to proceed?', 'optistate'), function() {
                isProcessing = true;
                $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline"></span> ' + __('Restoring...', 'optistate'));
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_restore_autoload_backup',
                    nonce: optistate_Ajax.nonce
                }).done(function(response) {
                    if (response.success) {
                        showToast(response.data.message, 'success');
                        $('#optistate-autoload-restore-container').hide();
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response.data.message || __('Restore failed.', 'optistate'), 'error');
                    }
                }).fail(function(xhr) {
                    handleAjaxError(xhr);
                }).always(function() {
                    isProcessing = false;
                    $btn.prop('disabled', false).html(originalText);
                });
            }, false);
        });

        function resetOneClickButton($btn) {
            isProcessing = false;
            $btn.removeClass('loading').prop('disabled', false).text(__('🚀 Optimize Now', 'optistate'));
            $('#optistate-one-click-results').removeClass('show').html('');
        }

        function startBackupAndOptimize($btn) {
            $btn.html('<span class="spinner is-active os-spinner-inline"></span> ' + __('Creating backup...', 'optistate'));
            $btn.prop('disabled', true);
            $.ajax({
                url: optistate_BackupMgr.ajax_url,
                type: 'POST',
                data: {
                    action: 'optistate_create_backup',
                    nonce: optistate_BackupMgr.nonce,
                    one_click: 1
                },
                timeout: 30000,
                success: function(response) {
                    if (response && response.success && response.data.status === 'starting') {
                        pollBackupStatus(response.data.transient_key, $btn, {
                            onComplete: function(data) {
                                if (data.backups) updateBackupsList(data.backups);
                                showToast(data.message || __('Backup created successfully!', 'optistate'), 'success');
                                $(SELECTORS.globalButtons).prop('disabled', false);
                                $('#create-backup-btn').prop('disabled', false);
                                debouncedLoadOptimizationLog();
                                runCleanupAndOptimize($btn);
                            },
                            onError: function(errorMsg) {
                                $(SELECTORS.globalButtons).prop('disabled', false);
                                $('#create-backup-btn').prop('disabled', false);
                                showToast(errorMsg || __('Backup failed. One-Click Optimization aborted.', 'optistate'), 'error');
                                resetOneClickButton($btn);
                            }
                        });
                    } else {
                        showToast(response?.data?.message || __('Failed to start backup.', 'optistate'), 'error');
                        resetOneClickButton($btn);
                    }
                },
                error: function(xhr) {
                    showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : __('An error occurred while creating the backup.', 'optistate'), xhr.status === 429 ? 'warning' : 'error');
                    resetOneClickButton($btn);
                }
            });
        }

        function runCleanupAndOptimize($btn) {
            $btn.text(__('🧹 Cleaning...', 'optistate'));
            var $results = $('#optistate-one-click-results');
            $results.html('<div class="optistate-info"><strong>' + __('Cleaning database...', 'optistate') + '</strong><br><span class="spinner is-active"></span></div>').show();
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_one_click_optimize',
                nonce: optistate_Ajax.nonce
            }).done(function(cleanup_response) {
                var html = '<div class="optistate-success"><strong>✅ ' + __('Cleanup Complete!', 'optistate') + '</strong></div>';
                if (cleanup_response && cleanup_response.success && cleanup_response.data) {
                    Object.keys(cleanup_response.data).forEach(function(key) {
                        if (key === 'health_score') return;
                        var count = parseInt(cleanup_response.data[key], 10) || 0;
                        var label = key.replace(/_/g, ' ');
                        if (key === 'all_transients') {
                            label = __('transients (all)', 'optistate');
                        }
                        html += '<div class="optistate-result-item">' + sprintf(__('Cleaned %s %s', 'optistate'), count.toLocaleString(), esc_html(label)) + '</div>';
                    });
                }
                $('#optistate-one-click-results').addClass('show').html(html).hide().fadeIn(300);
                $btn.text(__('⚡ Optimizing Tables...', 'optistate'));
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_optimize_tables',
                    nonce: optistate_Ajax.nonce
                }).done(function(optimize_response) {
                    if (optimize_response && optimize_response.success && optimize_response.data) {
                        var data = optimize_response.data;
                        var messages = [sprintf(__('Successfully optimized %s tables!', 'optistate'), (parseInt(data.optimized, 10) || 0).toLocaleString())];
                        if (data.reclaimed > 0) messages.push(sprintf(__('Reclaimed %s of space.', 'optistate'), formatBytes(data.reclaimed)));
                        $('#optistate-one-click-results').append('<div class="optistate-success os-success-mt">' + messages.join('<br>') + '</div>');
                    }
                    showToast(__('One-click optimization completed successfully!', 'optistate'), 'success');
                    $(document).trigger('optistate_optimization_complete');
                    $btn.removeClass('loading').prop('disabled', false).text(__('🚀 Optimize Now', 'optistate'));
                    isProcessing = false;
                }).fail(function(xhr) {
                    handleAjaxError(xhr);
                    showToast(__('Cleanup succeeded, but table optimization failed.', 'optistate'), 'error');
                    $btn.removeClass('loading').prop('disabled', false).text(__('🚀 Optimize Now', 'optistate'));
                    isProcessing = false;
                });
            }).fail(function(xhr) {
                handleAjaxError(xhr);
                $('#optistate-one-click-results').empty().hide();
                $btn.removeClass('loading').prop('disabled', false).text(__('🚀 Optimize Now', 'optistate'));
                isProcessing = false;
            });
        }
        $('#optistate-one-click').on('click', function() {
            if (isProcessing) return;
            var $btn = $(this);
            $('#optistate-one-click-results').empty().hide();
            var config = window.optistate_OneClickConfig || {};
            var defaultKeys = config.default_keys || [];
            var extraKeys = config.extra_items || [];
            var oneClickBackup = config.one_click_backup || false;
            var allKeys = defaultKeys.concat(extraKeys);
            if (allKeys.indexOf('all_transients') !== -1) {
                allKeys = allKeys.filter(function(k) {
                    return k !== 'expired_transients';
                });
            }
            var filteredDefault = defaultKeys.filter(function(k) {
                return k !== 'expired_transients' || allKeys.indexOf('all_transients') === -1;
            });
            var filteredExtra = extraKeys.filter(function(k) {
                return k !== 'expired_transients' || allKeys.indexOf('all_transients') === -1;
            });
            var defaultItems = [];
            filteredDefault.forEach(function(key) {
                var label = '';
                if (config.all_items && config.all_items[key]) {
                    label = config.all_items[key].label;
                } else {
                    label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                        return l.toUpperCase();
                    });
                }
                var count = (statsCache && typeof statsCache[key] !== 'undefined') ? parseInt(statsCache[key], 10) || 0 : 0;
                defaultItems.push({
                    key: key,
                    label: label,
                    count: count
                });
            });
            var optionalItems = [];
            filteredExtra.forEach(function(key) {
                var label = '';
                if (config.all_items && config.all_items[key]) {
                    label = config.all_items[key].label;
                } else {
                    label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                        return l.toUpperCase();
                    });
                }
                var count = (statsCache && typeof statsCache[key] !== 'undefined') ? parseInt(statsCache[key], 10) || 0 : 0;
                optionalItems.push({
                    key: key,
                    label: label,
                    count: count
                });
            });
            var hasOptional = optionalItems.length > 0;
            var message = __('This will perform a full database cleanup including:', 'optistate') + '<br><br>';
            if (defaultItems.length > 0) {
                message += '<div class="optistate-mod-itms">';
                message += '<strong>' + __('Default items:', 'optistate') + '</strong><br>';
                defaultItems.forEach(function(item) {
                    message += '• ' + item.label + ' <strong>(' + item.count.toLocaleString() + ')</strong><br>';
                });
            }
            if (hasOptional) {
                message += '<br><strong>' + __('Optional items:', 'optistate') + '</strong><br>';
                optionalItems.forEach(function(item) {
                    message += '• ' + item.label + ' <strong>(' + item.count.toLocaleString() + ')</strong><br>';
                });
            }
            message += '<p><strong>Plus:</strong><br>' + __('• Optimize database tables', 'optistate') + '<br>';
            if (oneClickBackup) {
                message += __('• Full database backup', 'optistate') + '</p>';
            }
            message += '</div>';
            if (hasOptional) {
                message += '<span class="os-color-danger-bold">⚠️ ' + __('Optional items are included', 'optistate') + '</span><br>';
                message += __('Please review them before proceeding.', 'optistate');
            } else {
                message += __('This operation is safe but cannot be undone.', 'optistate');
            }
            showOPTISTATEModal(__('🚀 Full Optimization', 'optistate'), message, function() {
                isProcessing = true;
                $btn.prop('disabled', true).addClass('loading').text(__('🧹 Initializing...', 'optistate'));
                if (oneClickBackup) {
                    startBackupAndOptimize($btn);
                } else {
                    runCleanupAndOptimize($btn);
                }
            }, false);
        });
    }

    function processAnalyzeRepairChunk(transient_key, $btn) {
        if (!isProcessing) {
            $('.optistate-advanced-op-btn').prop('disabled', false);
            $btn.removeClass('loading').text(`🛠️ ${__('Analyze & Repair Tables', 'optistate')}`);
            return;
        }
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_run_analyze_repair_chunk',
            nonce: optistate_Ajax.nonce,
            transient_key: transient_key
        }).done(function(response) {
            if (response?.success && response.data) {
                const data = response.data;
                if (data.status === 'running') {
                    optistate_batch_update(function() {
                        $btn.text(`${data.step || __('Processing...', 'optistate')} (${data.percentage || 0}%)`);
                    });
                    setTimeout(() => processAnalyzeRepairChunk(transient_key, $btn), 200);
                } else if (data.status === 'done') {
                    const results = data.results;
                    let message = '';
                    if (results.analyzed > 0) {
                        message = sprintf(__('Analyzed %s tables. ', 'optistate'), parseInt(results.analyzed, 10).toLocaleString());
                        if (results.corrupted > 0) message += sprintf(__('Found %s corrupted. ', 'optistate'), parseInt(results.corrupted, 10).toLocaleString());
                        if (results.repaired > 0) message += sprintf(__('Repaired %s. ', 'optistate'), parseInt(results.repaired, 10).toLocaleString());
                        if (results.optimized > 0) message += sprintf(__('Optimized %s. ', 'optistate'), parseInt(results.optimized, 10).toLocaleString());
                        if (results.failed > 0) message += sprintf(__('%s operations failed.', 'optistate'), parseInt(results.failed, 10).toLocaleString());
                        if (results.corrupted === 0) message += '<br>' + __('All tables are in optimal condition!', 'optistate');
                    } else {
                        message = __('No tables were found to analyze.', 'optistate');
                    }
                    let detailsHtml = `<div class="optistate-success">${message}</div>`;
                    if (results.details?.length > 0) {
                        detailsHtml += `<div class="optistate-details os-details-container"><strong>${__('Table Analysis Details:', 'optistate')}</strong><ul class="os-m-5-0">`;
                        results.details.forEach(d => {
                            if (!d.table) return;
                            let statusIcon = '✅';
                            let statusParts = [];
                            if (d.error && !d.repaired) {
                                statusIcon = '❌';
                                statusParts.push(__('Failed', 'optistate'));
                            } else {
                                if (d.corrupted) {
                                    statusIcon = '🔴';
                                    statusParts.push(__('Corrupted', 'optistate'));
                                }
                                if (d.repaired) {
                                    statusIcon = '🛠️';
                                    statusParts = statusParts.filter(s => s !== __('Corrupted', 'optistate'));
                                    statusParts.push(__('Repaired', 'optistate'));
                                }
                                if (d.optimized) {
                                    if (statusIcon === '✅') statusIcon = '⚡';
                                    statusParts.push(__('Optimized', 'optistate'));
                                }
                            }
                            const statusText = statusParts.join(' & ') || __('Healthy', 'optistate');
                            detailsHtml += `<li>${statusIcon} ${esc_html(d.table)}: ${esc_html(statusText)}${d.error ? ` <span class="optistate-error" title="${esc_attr(d.error)}">(${__('details', 'optistate')})</span>` : ''}</li>`;
                        });
                        detailsHtml += `</ul></div>`;
                    }
                    $('#optistate-table-results').addClass('show').html(detailsHtml).hide().fadeIn(300);
                    showToast(message, results.failed > 0 ? 'warning' : 'success');
                    setTimeout(() => loadStats(true), 1500);
                    isProcessing = false;
                    $('.optistate-advanced-op-btn').prop('disabled', false);
                    $btn.removeClass('loading').text(`🛠️ ${__('Analyze & Repair Tables', 'optistate')}`);
                }
            } else {
                showToast(response?.data?.message || __('An unknown error occurred.', 'optistate'), 'error');
                isProcessing = false;
                $('.optistate-advanced-op-btn').prop('disabled', false);
                $btn.removeClass('loading').text(`🛠️ ${__('Analyze & Repair Tables', 'optistate')}`);
            }
        }).fail(function(xhr) {
            handleAjaxError(xhr);
            isProcessing = false;
            $('.optistate-advanced-op-btn').prop('disabled', false);
            $btn.removeClass('loading').text(`🛠️ ${__('Analyze & Repair Tables', 'optistate')}`);
        });
    }
    $(document).on('click', '#optistate-refresh-log-btn', function() {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        const $icon = $btn.find('.dashicons');
        $btn.prop('disabled', true);
        $icon.addClass('is-active');
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_get_optimization_log',
            nonce: optistate_Ajax.nonce,
            manual_refresh: 1
        }).done(function(response) {
            if (response?.success && response.data) {
                displayOptimizationLog(response.data);
                showToast(__('Activity Log refreshed', 'optistate'), 'info');
            } else {
                showToast(__('Failed to refresh log', 'optistate'), 'error');
            }
        }).fail(function(xhr) {
            let errorMsg = __('Failed to refresh log', 'optistate');
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg = xhr.responseJSON.data.message;
            } else if (xhr.status === 429) {
                errorMsg = getRateLimitMessage(false);
            }
            showToast(errorMsg, xhr.status === 429 ? 'warning' : 'error');
        }).always(function() {
            const $newBtn = $('#optistate-refresh-log-btn');
            if ($newBtn.length) {
                $newBtn.prop('disabled', false).find('.dashicons').removeClass('is-active');
            }
        });
    });

    function initializeHealthScore() {
        loadHealthScore();
        $('#optistate-refresh-health-score').off('click').on('click', function() {
            loadHealthScore(true);
        });
    }

    function loadHealthScore(forceRefresh = false, showToastOnRefresh = true) {
        const $wrapper = $(SELECTORS.healthScoreWrapper);
        const $loading = $(SELECTORS.healthScoreLoading);
        $wrapper.css('position', 'relative');
        $loading.addClass('os-loading-overlay os-health-loading').fadeIn(150);
        $wrapper.css('opacity', '0.5').show();
        $.ajax({
            url: optistate_Ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'optistate_get_health_score',
                nonce: optistate_Ajax.nonce,
                force_refresh: forceRefresh
            },
            timeout: 60000,
            success: function(response) {
                if (response?.success && response.data) {
                    displayHealthScore(response.data);
                    if (forceRefresh) {
                        if (showToastOnRefresh) {
                            showToast(__('Health score refreshed', 'optistate'), 'info');
                        }
                        loadStats(false);
                    }
                } else {
                    showHealthScoreError(response?.data?.message || __('Failed to load health score', 'optistate'));
                }
            },
            error: function(xhr) {
                if (xhr.status === 429) {
                    showToast(xhr.responseJSON?.data?.message || getRateLimitMessage(false), 'warning');
                    return;
                }
                let errorMsg = __('Network error loading health score', 'optistate');
                if (xhr.responseJSON?.data?.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                showHealthScoreError(errorMsg);
            },
            complete: function() {
                $loading.fadeOut(150);
                $wrapper.css('opacity', '1').show();
            }
        });
    }

    function displayHealthScore(scoreData) {
        if (!scoreData || typeof scoreData.overall_score === 'undefined') return;
        const overallScore = parseInt(scoreData.overall_score, 10);
        const $scoreValue = $('#health-score-value');
        $scoreValue.text(overallScore.toLocaleString()).removeClass('score-excellent score-good score-fair score-poor score-critical');
        let color = '#D33434';
        if (overallScore >= 90) {
            $scoreValue.addClass('score-excellent');
            color = '#259B2D';
        } else if (overallScore >= 75) {
            $scoreValue.addClass('score-good');
            color = '#16AF7F';
        } else if (overallScore >= 60) {
            $scoreValue.addClass('score-fair');
            color = '#EA9A07';
        } else if (overallScore >= 40) {
            $scoreValue.addClass('score-poor');
            color = '#F76420';
        } else {
            $scoreValue.addClass('score-critical');
        }
        $('.health-score-circle').css('border-color', color);
        if (scoreData.category_scores) {
            $('#health-score-performance').text(Math.round(scoreData.category_scores.performance || 0).toLocaleString());
            $('#health-score-cleanliness').text(Math.round(scoreData.category_scores.cleanliness || 0).toLocaleString());
            $('#health-score-efficiency').text(Math.round(scoreData.category_scores.efficiency || 0).toLocaleString());
        }
        const $recommendations = $('#health-score-recommendations-list');
        $recommendations.empty();
        if (scoreData.recommendations?.length > 0) {
            scoreData.recommendations.forEach(rec => {
                if (!rec.message || !rec.urgency) return;
                let bullet = '🔹';
                if (rec.urgency === 'high') bullet = '🔴 ';
                else if (rec.urgency === 'medium') bullet = '🔸';
                else if (rec.urgency === 'low' || rec.urgency === 'info') bullet = '🔹';
                else if (rec.urgency === 'success') bullet = '✅ ';
                $recommendations.append(`<div class="recommendation-item">${bullet}${esc_html(rec.message)}</div>`);
            });
        } else {
            $recommendations.append(`<div class="recommendation-item">${__('No recommendations at this time', 'optistate')}</div>`);
        }
    }

    function showHealthScoreError(message) {
        showToast(message, 'error');
        const $val = $('#health-score-value');
        if ($val.text() === '...' || $val.text() === '') {
            $val.text('-');
            $('.health-score-circle').css('border-color', '#ccc');
            $('#health-score-recommendations-list').html(`<div class="recommendation-item recommendation-high">${__('Unable to load data. Please refresh.', 'optistate')}</div>`);
        }
    }
    $(document).on('optistate_optimization_complete', () => setTimeout(() => loadHealthScore(true, false), 1000));

    function initTableAnalysisEvents() {
        let lastTableAnalysisFetch = 0;
        $('#optistate-analyze-tables-btn').on('click', function() {
            const $btn = $(this);
            const $loading = $('#optistate-table-analysis-loading');
            const $results = $('#optistate-table-analysis-results');
            if ($results.is(':visible')) {
                $results.slideUp(300);
                $btn.find('.dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-search');
                return;
            }
            const now = Date.now();
            if (now - lastTableAnalysisFetch < 5000) {
                showToast(__('🕔 Please wait 5 seconds before analyzing again.', 'optistate'), 'warning');
                return;
            }
            lastTableAnalysisFetch = now;
            $btn.prop('disabled', true);
            $loading.fadeIn(200);
            $results.hide();
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_get_table_analysis',
                nonce: optistate_Ajax.nonce
            }).done(function(response) {
                if (response?.success && response.data) {
                    displayTableAnalysis(response.data);
                    $results.slideDown(300);
                    $btn.find('.dashicons').removeClass('dashicons-search').addClass('dashicons-arrow-up-alt2');
                } else {
                    showToast(response?.data || __('Failed to analyze tables', 'optistate'), 'error');
                }
            }).fail(function(xhr) {
                showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : __('Network error while analyzing tables', 'optistate'), xhr.status === 429 ? 'warning' : 'error');
            }).always(function() {
                $loading.fadeOut(200);
                $btn.prop('disabled', false);
            });
        });
        $('#optistate-table-analysis-results').on('click', '.optistate-table-toggle', function() {
            const $toggle = $(this);
            const $details = $toggle.siblings('.optistate-table-details');
            $details.toggleClass('show');
            $toggle.text($details.hasClass('show') ? `▲ ${__('Hide Details', 'optistate')}` : `▼ ${__('Show Details', 'optistate')}`);
        });
        $('#optistate-table-analysis-results').on('click', '.os-delete-table-btn', function() {
            const $btn = $(this);
            const tableName = $btn.data('table');
            if (!tableName) return;
            const message = ` <div class="os-text-left"> <span class="os-color-danger-big">⚠️ ${__('CRITICAL WARNING: Permanent Data Loss', 'optistate')}</span><br><br> ${sprintf(__('You are about to delete the table: <code>%s</code>', 'optistate'), esc_html(tableName))}<br> ${__('This action cannot be undone. If an active plugin is still using this table, features may break.', 'optistate')} <div class="unused-db-table"><strong>${__('Required Verification Steps:', 'optistate')}</strong><ul class="os-list-disc"><li>${__('Confirm the associated plugin/theme is fully uninstalled.', 'optistate')}</li><li>${__('Ensure you have a recent database backup.', 'optistate')}</li></ul></div> <strong>${__('Are you absolutely sure you want to proceed?', 'optistate')}</strong> </div>`;
            showOPTISTATEModal(`🗑️ ${__('Confirm Table Deletion', 'optistate')}`, message, function() {
                $btn.prop('disabled', true).text(__('Deleting...', 'optistate'));
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_delete_table',
                    nonce: optistate_Ajax.nonce,
                    table_name: tableName
                }).done(function(response) {
                    if (response?.success) {
                        showToast(response.data.message, 'success');
                        $btn.closest('.optistate-table-item').css('background-color', '#ffcccc').fadeOut(600, function() {
                            $(this).remove();
                        });
                        debouncedLoadOptimizationLog();
                        loadTrashItems();
                    } else {
                        showToast(response?.data?.message || __('Failed to delete table.', 'optistate'), 'error');
                        $btn.prop('disabled', false).html(`<span class="dashicons dashicons-trash"></span> ${__('Delete Table', 'optistate')}`);
                    }
                }).fail(function(xhr) {
                    handleAjaxError(xhr);
                    $btn.prop('disabled', false).html(`<span class="dashicons dashicons-trash"></span> ${__('Delete Table', 'optistate')}`);
                });
            }, true);
        });
    }

    function displayTableAnalysis(data) {
        if (!data || !data.totals) return;
        const $results = $('#optistate-table-analysis-results');
        const fragment = document.createDocumentFragment();
        let summaryHtml = ` <div class="optistate-analysis-summary"> <h4 class="os-flex-between"><span><span class="dashicons dashicons-chart-bar"></span> ${__(' Database Summary', 'optistate')}</span>${data.db_name ? `<code class="os-color-db-name">${__('Database Name:', 'optistate')} ${esc_html(data.db_name)}</code>` : ''}</h4> <div class="optistate-summary-grid"> <div class="optistate-summary-item"><div class="optistate-summary-value">${data.totals.total_tables.toLocaleString()}</div><div class="optistate-summary-label">${__('Total Tables', 'optistate')}</div></div> <div class="optistate-summary-item"><div class="optistate-summary-value os-color-success">${data.totals.core_count.toLocaleString()}</div><div class="optistate-summary-label">${__('WordPress Core', 'optistate')}</div></div> <div class="optistate-summary-item"><div class="optistate-summary-value os-color-danger">${data.totals.plugin_count.toLocaleString()}</div><div class="optistate-summary-label">${__('Plugin/Theme', 'optistate')}</div></div> <div class="optistate-summary-item"><div class="optistate-summary-value">${formatBytes(data.totals.total_size)}</div><div class="optistate-summary-label">${__('Total Size', 'optistate')}</div></div> </div> </div>`;
        if (data.totals.plugin_count > 0) {
            summaryHtml += ` <div class="optistate-table-warning"> <strong>⚠️ ${sprintf(__('Third-party tables detected', 'optistate'), data.totals.plugin_count)}</strong><br> ${__('🔹 These tables belong to plugins or themes. They will be included in backups.<br>', 'optistate')} ${__('🔸 Tables marked with this icon 🕸️ may no longer be used.', 'optistate')} </div> <div class="optistate-table-info"> <strong>ℹ️️ ${sprintf(__('Technical note', 'optistate'), data.totals.plugin_count)}</strong><br> ${__('The "Last Updated" timestamps displayed in table details are retrieved from the INFORMATION_SCHEMA. However, please be aware that for InnoDB storage engines—the WordPress standard—this value is frequently missing or statically inaccurate. Performing operations such as OPTIMIZE TABLE will reset this timestamp to the current time.', 'optistate')} </div>`;
        }
        const summaryNode = document.createElement('div');
        summaryNode.innerHTML = summaryHtml;
        fragment.appendChild(summaryNode);
        const grid = document.createElement('div');
        grid.className = 'optistate-tables-grid';
        const renderChunked = (tables, container, isCore) => {
            let index = 0;
            const batchSize = 50;

            function nextBatch() {
                const batchFragment = document.createDocumentFragment();
                const end = Math.min(index + batchSize, tables.length);
                for (let i = index; i < end; i++) {
                    batchFragment.appendChild(renderTableItem(tables[i], isCore));
                }
                container.appendChild(batchFragment);
                index = end;
                if (index < tables.length) requestAnimationFrame(nextBatch);
            }
            nextBatch();
        };
        if (data.core_tables?.length > 0) {
            const coreCategory = document.createElement('div');
            coreCategory.className = 'optistate-table-category core-tables';
            coreCategory.innerHTML = `<h4><span class="dashicons dashicons-wordpress"></span>${__('WordPress Core Tables', 'optistate')} (${data.core_tables.length.toLocaleString()}) - ${formatBytes(data.totals.core_size)}</h4>`;
            grid.appendChild(coreCategory);
            renderChunked(data.core_tables, coreCategory, true);
        }
        if (data.plugin_tables?.length > 0) {
            const pluginCategory = document.createElement('div');
            pluginCategory.className = 'optistate-table-category plugin-tables';
            pluginCategory.innerHTML = `<h4><span class="dashicons dashicons-admin-plugins"></span>${__('Plugin & Theme Tables', 'optistate')} (${data.plugin_tables.length.toLocaleString()}) - ${formatBytes(data.totals.plugin_size)}</h4>`;
            grid.appendChild(pluginCategory);
            renderChunked(data.plugin_tables, pluginCategory, false);
        }
        fragment.appendChild(grid);
        $results.empty().append(fragment);
    }

    function renderTableItem(table, isCore) {
        const tableClass = isCore ? 'core-table' : 'plugin-table';
        const overheadWarning = table.overhead > 1024 * 1024 ? ' ⚠️' : '';
        const abandonedTitle = table.is_abandoned ? (table.can_delete ? table.abandoned_text : __('This table has not been accessed in 30+ days but belongs to an installed plugin/theme — protected from deletion.', 'optistate')) : '';
        const abandonedHtml = table.is_abandoned ? ` <span class="optistate-abandoned-icon os-cursor-help" title="${esc_attr(abandonedTitle)}">🕸️</span>` : '';
        let statsHtml = ` <div class="optistate-table-stat"><strong>${__('Rows:', 'optistate')}</strong> ${table.rows.toLocaleString()}</div> <div class="optistate-table-stat"><strong>${__('Size:', 'optistate')}</strong> ${formatBytes(table.total_size)}</div> ${table.overhead > 0 ? `<div class="optistate-table-stat"><strong>${__('Overhead:', 'optistate')}</strong> ${formatBytes(table.overhead)}${overheadWarning}</div>` : ''} <div class="optistate-table-stat"><strong>${__('Engine:', 'optistate')}</strong> ${esc_html(table.engine)}</div> `;
        let detailsHtml = ` <div><strong>${__('Data Size:', 'optistate')}</strong> ${formatBytes(table.data_size)}</div> <div><strong>${__('Index Size:', 'optistate')}</strong> ${formatBytes(table.index_size)}</div> <div><strong>${__('Collation:', 'optistate')}</strong> ${esc_html(table.collation)}</div> ${table.created ? `<div><strong>${__('Created:', 'optistate')}</strong> ${table.created} (UTC)</div>` : ''} ${table.updated ? `<div><strong>${__('Last Updated:', 'optistate')}</strong> ${table.updated} (UTC)</div>` : ''} ${table.can_delete ? ` <div class="delete-db-table"> <p class="os-delete-table-warning">${__('This table appears unused. Verify before deleting.', 'optistate')}</p> <button class="button os-delete-table-btn" data-table="${esc_attr(table.name)}">🗑 ${__('Delete Table', 'optistate')}</button> </div>` : ''} `;
        const item = document.createElement('div');
        item.className = `optistate-table-item ${tableClass}`;
        const pluginTestsLink = isCore ? '' : ` <a href="https://plugintests.com/search-ids?query=${encodeURIComponent(table.name)}&collection=tables&matchType=prefix" target="_blank" rel="noopener noreferrer" class="os-plugintests-link" title="${esc_attr(__('Look up which plugin owns this table on plugintests.com', 'optistate'))}" style="text-decoration:none; font-size:0.9em;">🔎</a>`;
        item.innerHTML = ` <div class="optistate-table-name">${esc_html(table.name)}${abandonedHtml}${pluginTestsLink}</div> <div class="optistate-table-description">${esc_html(table.description)}</div> <div class="optistate-table-stats">${statsHtml}</div> <button class="optistate-table-toggle">▼ ${__('Show Details', 'optistate')}</button> <div class="optistate-table-details">${detailsHtml}</div> `;
        return item;
    }

    function initIndexAnalysisEvents() {
        $('#optistate-analyze-indexes-btn').on('click', function() {
            const $btn = $(this);
            const $loading = $('#optistate-index-analysis-loading');
            const $results = $('#optistate-index-results');
            $btn.prop('disabled', true);
            $loading.fadeIn(200);
            $results.hide().empty();
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_analyze_indexes',
                nonce: optistate_Ajax.nonce
            }).done(function(response) {
                if (response?.success) {
                    const recs = response.data.recommendations;
                    if (recs.length === 0) {
                        $results.html(`<div class="notice notice-success inline os-p-10"><p>✅ <strong>${__('Great news!', 'optistate')}</strong></p><p>${__('No missing or redundant indexes were found in your database.', 'optistate')}</p></div>`);
                    } else {
                        let html = `<table class="widefat striped os-border-table"><thead><tr><th>${__('Table', 'optistate')}</th><th>${__('Index / Columns', 'optistate')}</th><th>${__('Status & Analysis', 'optistate')}</th><th>${__('Action', 'optistate')}</th></thead><tbody>`;
                        recs.forEach(rec => {
                            const isRedundant = (rec.type === 'redundant');
                            html += `<tr> <td><code>${rec.table}</code></td> <td><strong>${rec.index_name}</strong><br><small><code>${rec.column}</code></small></td> <td style="color: ${isRedundant ? '#d63638' : '#CC8400'};">${rec.reason}</td> <td><button class="button ${isRedundant ? 'button-link-delete' : 'button-primary'} optistate-manage-index-btn" data-table="${rec.table}" data-type="${isRedundant ? 'drop' : 'add'}" data-column="${rec.raw_columns || ''}" data-index="${rec.index_name}">${isRedundant ? __('Remove Bloat', 'optistate') : __('Fix Index', 'optistate')}</button></td> </tr>`;
                        });
                        $results.html(html + '</tbody></table>');
                    }
                    $results.slideDown(300);
                } else {
                    showToast(response?.data?.message || 'Analysis failed', 'error');
                }
            }).fail(function(xhr) {
                showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : __('Network error analyzing indexes.', 'optistate'), xhr.status === 429 ? 'warning' : 'error');
            }).always(() => {
                $loading.fadeOut(200);
                $btn.prop('disabled', false);
            });
        });
        $(document).on('click', '.optistate-manage-index-btn', function() {
            const $btn = $(this);
            const {
                table,
                column,
                index: index_name,
                type: action_type
            } = $btn.data();
            const isDrop = action_type === 'drop';
            const title = isDrop ? `⚠️ ${__('Confirm Index Removal', 'optistate')}` : `✔ ${__('Confirm Database Optimization', 'optistate')}`;
            const message = `<p>${isDrop ? sprintf(__('Are you sure you want to remove the redundant index <code>%s</code> from table <code>%s</code>?', 'optistate'), index_name, table) : sprintf(__('Are you sure you want to add an index to <code>%s</code> on table <code>%s</code>?', 'optistate'), column, table)}</p><p>${__('This operation will run in the background to prevent timeouts.', 'optistate')}</p>`;
            showOPTISTATEModal(title, message, function() {
                $btn.prop('disabled', true).html(`<span class="spinner is-active os-spinner-index"></span> ${__('Starting...', 'optistate')}`);
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_manage_index',
                    nonce: optistate_Ajax.nonce,
                    table,
                    action_type,
                    column,
                    index_name
                }).done(function(response) {
                    if (response.success && response.data.task_id) {
                        $btn.html(`<span class="spinner is-active os-spinner-index"></span> ${__('Processing...', 'optistate')}`);
                        pollIndexStatus(response.data.task_id, $btn, action_type);
                    } else {
                        showToast(response.data.message || __('Operation failed', 'optistate'), 'error');
                        $btn.prop('disabled', false).text(isDrop ? __('Remove Bloat', 'optistate') : __('Fix Index', 'optistate'));
                    }
                }).fail(() => {
                    showToast(__('Server connection lost.', 'optistate'), 'error');
                    $btn.prop('disabled', false).text(__('Retry', 'optistate'));
                });
            }, false);
        });
    }

    function runLegacyScan() {
        const $btn = $('#optistate-scan-legacy-btn');
        const $results = $('#optistate-legacy-results');
        if ($btn.prop('disabled') || isProcessing) return;
        $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline-margin"></span> ' + __('Scanning Database...', 'optistate'));
        $results.slideUp(200).empty();
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_scan_legacy_data',
            nonce: optistate_Ajax.nonce
        }).done(function(response) {
            if (response.success) {
                renderLegacyResults(response.data);
            } else {
                showToast(response.data.message || __('Scan failed to retrieve data.', 'optistate'), 'error');
                $results.html(`<div class="notice notice-error inline"><p>${response.data.message || __('Error occurred.', 'optistate')}</p></div>`).slideDown();
            }
        }).fail(function(xhr) {
            handleAjaxError(xhr);
        }).always(function() {
            $btn.prop('disabled', false).html('🔎 ' + __('Scan for Ghost Data', 'optistate'));
        });
    }

    function initLegacyScannerEvents() {
        $('#optistate-scan-legacy-btn').on('click', function() {
            const $btn = $(this);
            const $results = $('#optistate-legacy-results');
            if ($btn.prop('disabled') || isProcessing) return;
            if ($results.is(':visible') && $results.children().length > 0) {
                $results.slideUp(200);
                return;
            }
            runLegacyScan();
        });
        $(document).on('click', '.optistate-continue-scan-btn', function(e) {
            e.preventDefault();
            $(this).prop('disabled', true).html('<span class="spinner is-active os-spinner-inline"></span> ' + __('Scanning...', 'optistate'));
            runLegacyScan();
        });
        $(document).on('click', '#optistate-delete-all-trash-btn', function(e) {
            e.preventDefault();
            if (isDeletingAll) return;
            const $btn = $(this);
            const count = parseInt($btn.data('count'), 10) || 0;
            if (count === 0) return;
            showOPTISTATEModal(__('⚠️ Permanently Delete All Trash Items', 'optistate'), sprintf(__('You are about to permanently delete %s items from trash.<br><br>This action cannot be undone. Are you sure?', 'optistate'), count.toLocaleString()), function() {
                isDeletingAll = true;
                $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline"></span> ' + __('Deleting...', 'optistate'));
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_delete_all_trash',
                    nonce: optistate_Ajax.nonce
                }).done(function(response) {
                    if (response.success) {
                        const data = response.data;
                        const isCompleted = data.completed === true;
                        const remaining = data.remaining || 0;
                        showToast(data.message, 'success');
                        debouncedLoadOptimizationLog();
                        loadTrashItems().done(function() {
                            const $newBtn = $('#optistate-delete-all-trash-btn');
                            if ($newBtn.length) {
                                $newBtn.prop('disabled', false);
                                if (!isCompleted && remaining > 0) {
                                    $newBtn.html(sprintf(__('🗑 Continue deleting (%s remain)', 'optistate'), remaining.toLocaleString()));
                                    $newBtn.data('count', remaining);
                                }
                            }
                        }).always(function() {
                            isDeletingAll = false;
                        });
                    } else {
                        showToast(response.data.message || __('An error occurred.', 'optistate'), 'error');
                        $btn.prop('disabled', false).html(__('🗑 Delete All', 'optistate'));
                        isDeletingAll = false;
                    }
                }).fail(function(xhr) {
                    const msg = xhr.responseJSON?.data?.message || __('Network error.', 'optistate');
                    showToast(msg, 'error');
                    $btn.prop('disabled', false).html(__('🗑 Delete All', 'optistate'));
                    isDeletingAll = false;
                });
            }, true);
        });
        $(document).on('click', '.optistate-delete-legacy-btn', function() {
            const $btn = $(this);
            const data = $btn.data();
            const $row = $btn.closest('tr');
            const typeMap = {
                'post_meta': __('Post Meta', 'optistate'),
                'option': __('Option', 'optistate'),
                'table': __('Database Table', 'optistate'),
                'folder': __('Folder', 'optistate')
            };
            const displayType = typeMap[data.type] || (data.type.charAt(0).toUpperCase() + data.type.slice(1).replace(/_/g, ' '));
            const message = `<div class="os-mb-15"><span class="os-color-danger-big">⚠️ ${__('Confirm Data Deletion', 'optistate')}</span></div>` + `<p>${sprintf(__('You are about to delete: <strong>%s</strong>', 'optistate'), esc_html(data.label))}</p>` + `<code class="os-code-block">${esc_html(data.name)}</code>` + `<p class="os-mt-10" style="margin-top: 10px;"><strong>${__('Item type:', 'optistate')}</strong> ${esc_html(displayType)}</p>` + `<div class="os-mt-15"><strong>${__('Verification Required:', 'optistate')}</strong>` + `<ul class="os-list-disc">` + `<li>${__('Ensure the associated plugin is permanently uninstalled.', 'optistate')}</li>` + `<li>${__('Do not delete if you plan to reinstall the plugin.', 'optistate')}</li>` + `<li>${__('Create a fresh database backup before proceeding.', 'optistate')}</li>` + `</ul></div>`;
            showOPTISTATEModal(__('🗑️ Remove Legacy Data', 'optistate'), message, function() {
                $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-no-margin"></span>');
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_delete_legacy_data',
                    nonce: optistate_Ajax.nonce,
                    type: data.type,
                    name: data.name
                }).done(function(response) {
                    if (response.success) {
                        const count = response.data.count || 0;
                        showToast(sprintf(__('Item successfully moved to trash.', 'optistate'), count), 'success');
                        loadTrashItems();
                        $row.css('background-color', '#ffcccc').fadeOut(600, function() {
                            $(this).remove();
                            if ($('#optistate-legacy-table tbody tr').length === 0) {
                                $('#optistate-legacy-results').html(`<div class="notice notice-success inline os-p-10"><p><strong>✅ ${__('All Clean!', 'optistate')}</strong> ${__('No detected ghost data remaining.', 'optistate')}</p></div>`);
                            }
                        });
                        if (typeof loadStats === 'function') loadStats(false);
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response.data.message || __('Deletion failed.', 'optistate'), 'error');
                        $btn.prop('disabled', false).html(__('Delete', 'optistate'));
                    }
                }).fail(function(xhr) {
                    handleAjaxError(xhr);
                    $btn.prop('disabled', false).html(__('Delete', 'optistate'));
                });
            }, true);
        });
    }

    function buildLegacyScanNotices(data, isComplete, isTruncated) {
        let notices = '';
        if (!isComplete) {
            const folders = data.folders || {};
            const scanned = parseInt(folders.scanned, 10) || 0;
            const total = parseInt(folders.total, 10) || 0;
            const progress = (total > 0 && scanned < total) ? ' ' + sprintf(__('Folders checked so far: %s of %s.', 'optistate'), scanned.toLocaleString(), total.toLocaleString()) : '';
            notices += ` <div class="notice notice-warning inline os-mb-15"> <p><strong>⏳ ${__('Scan paused before it finished', 'optistate')}</strong></p> <p>${__('The scan stopped early to stay inside the server time limit, so this list is not complete yet.', 'optistate')}${progress}</p> <p><button type="button" class="button button-primary optistate-continue-scan-btn">${__('▶ Continue scan', 'optistate')}</button></p> </div> `;
        }
        if (isTruncated) {
            notices += ` <div class="notice notice-info inline os-mb-15"> <p>${__('The result limit was reached, so only the first batch of matches is shown. Deal with these, then scan again to see the rest.', 'optistate')}</p> </div> `;
        }
        return notices;
    }

    function renderLegacyResults(payload) {
        const $container = $('#optistate-legacy-results');
        const data = (payload && !Array.isArray(payload) && typeof payload === 'object') ? payload : {};
        const items = Array.isArray(payload) ? payload : (Array.isArray(data.items) ? data.items : []);
        const isComplete = data.complete !== false;
        const isTruncated = data.truncated === true;
        const notices = buildLegacyScanNotices(data, isComplete, isTruncated);
        if (items.length === 0) {
            if (isComplete) {
                $container.html(` <div class="notice notice-success inline os-p-10-border-success"> <p><strong>✅ ${__('No Ghost Data Detected', 'optistate')}</strong></p> <p>${__('Your database appears free of common leftover data from old plugins.', 'optistate')}</p> </div> `).slideDown(300);
            } else {
                $container.html(notices).slideDown(300);
            }
            return;
        }
        let html = notices + ` <div class="notice notice-warning inline os-mb-15"> <p><strong>☰ ${sprintf(__('Found %s items requiring review', 'optistate'), items.length)}</strong></p> <p>${__('🗑 These items match patterns of uninstalled plugins and themes.<br>⚠︎ Verify they actually belong to removed plugins/themes before deleting them!', 'optistate')}</p> </div> <table class="widefat striped os-border-table-full" id="optistate-legacy-table"> <thead> <tr> <th>${__('Data Source', 'optistate')}</th> <th>${__('Type', 'optistate')}</th> <th>${__('Size / Count', 'optistate')}</th> <th>${__('Risk Level', 'optistate')}</th> <th class="os-text-right-bold">${__('Action', 'optistate')}</th> </thead> <tbody>`;
        items.forEach(item => {
            const riskClass = item.risk === 'high' ? 'impact-high' : (item.risk === 'medium' ? 'impact-medium' : 'impact-low');
            const riskLabel = item.risk === 'high' ? __('High', 'optistate') : (item.risk === 'medium' ? __('Medium', 'optistate') : __('Low', 'optistate'));
            let displayType = '';
            let displayIcon = '';
            let extraBadge = '';
            switch (item.type) {
                case 'option':
                    displayType = 'Option';
                    displayIcon = 'dashicons-admin-generic';
                    if (item.autoload) {
                        extraBadge = '<br><span style="font-size:0.85em;">• Autoloaded</span>';
                    }
                    break;
                case 'post_meta':
                    displayType = 'Meta';
                    displayIcon = 'dashicons-list-view';
                    break;
                case 'table':
                    displayType = 'Table';
                    displayIcon = 'dashicons-database';
                    if (item.last_accessed_date) {
                        extraBadge = '<br><span style="font-size:0.85em;">' + __('• Updated:', 'optistate') + ' ' + esc_html(item.last_accessed_date) + '</span>';
                    }
                    break;
                case 'folder':
                    displayType = 'Folder';
                    displayIcon = 'dashicons-portfolio';
                    if (item.last_accessed_date) {
                        extraBadge = '<br><span style="font-size:0.85em;">' + __('• Updated:', 'optistate') + ' ' + esc_html(item.last_accessed_date) + '</span>';
                    }
                    break;
                default:
                    displayType = 'Meta';
                    displayIcon = 'dashicons-list-view';
            }
            const pluginTestsUrl = item.type === 'table' ? 'https://plugintests.com/search-ids?query=' + encodeURIComponent(item.name) + '&collection=tables&matchType=prefix' : item.type === 'option' ? 'https://plugintests.com/search-ids?query=' + encodeURIComponent(item.name) + '&collection=options&matchType=prefix' : '';
            const pluginTestsTitle = item.type === 'table' ? __('Look up which plugin owns this table on plugintests.com', 'optistate') : item.type === 'option' ? __('Look up which plugin owns this option on plugintests.com', 'optistate') : '';
            const pluginTestsLink = pluginTestsUrl ? ' <a href="' + pluginTestsUrl + '" target="_blank" rel="noopener noreferrer" class="os-plugintests-link" title="' + pluginTestsTitle + '" style="text-decoration:none; font-size:0.9em;">🔎</a>' : '';
            html += ` <tr> <td> <strong>${esc_html(item.label)}</strong>${pluginTestsLink}<br> <code class="os-code-light">${esc_html(item.name)}</code> </td> <td><span class="dashicons ${displayIcon}"></span> ${displayType}${extraBadge}</td> <td><strong>${esc_html(item.count)}</strong></td> <td><span class="optistate-feature-impact ${riskClass}">${riskLabel}</span></td> <td class="os-text-right"> <button class="button optistate-delete-legacy-btn" data-type="${esc_attr(item.type)}" data-name="${esc_attr(item.name)}" data-label="${esc_attr(item.label)}"> ${__('Delete', 'optistate')} </button> </td> </tr>`;
        });
        html += `</tbody></table>`;
        $container.html(html).slideDown(300);
    }

    function initIntegrityScanEvents() {
        $('#optistate-run-integrity-scan').on('click', function() {
            const $btn = $(this);
            const $loading = $('#optistate-integrity-loading');
            const $results = $('#optistate-integrity-results');
            $btn.prop('disabled', true);
            $loading.fadeIn(200);
            $results.slideUp(200).empty();
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_scan_integrity',
                nonce: optistate_Ajax.nonce
            }).done(response => {
                if (response.success) renderIntegrityResults(response.data);
                else showToast(response.data.message || 'Scan failed', 'error');
            }).fail(xhr => showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : __('Network error.', 'optistate'), xhr.status === 429 ? 'warning' : 'error')).always(() => {
                $loading.fadeOut(200);
                $btn.prop('disabled', false);
            });
        });
        $(document).on('click', '.optistate-fix-integrity-btn', function() {
            const $btn = $(this);
            const type = $btn.data('type');
            const initialCount = parseInt($btn.data('count'), 10);
            const $row = $btn.closest('tr');
            const $countCell = $row.find('td').eq(1);
            const message = `${sprintf(__('Are you sure you want to delete %s orphaned rows?', 'optistate'), initialCount.toLocaleString())}<br><br>${__('These rows have no parent data associated with them. This action is generally safe.', 'optistate')}`;
            showOPTISTATEModal(`🛡️ ${__('Confirm Integrity Fix', 'optistate')}`, message, function() {
                (function processIntegrityBatch() {
                    $btn.prop('disabled', true).html(`<span class="spinner is-active os-spinner-no-margin"></span> ${__('Processing...', 'optistate')}`);
                    $.post(optistate_Ajax.ajaxurl, {
                        action: 'optistate_fix_integrity',
                        type: type,
                        nonce: optistate_Ajax.nonce
                    }).done(function(response) {
                        if (response.success) {
                            const remaining = response.data.remaining;
                            if (remaining > 0) {
                                $countCell.text(remaining.toLocaleString()).css('color', '#ffb900');
                                $btn.html(`<span class="spinner is-active os-spinner-no-margin"></span> ${sprintf(__('Remaining: %s', 'optistate'), remaining.toLocaleString())}`);
                                setTimeout(processIntegrityBatch, 500);
                            } else {
                                showToast(__('Integrity fix complete.', 'optistate'), 'success');
                                $row.css('background-color', '#d4edda').fadeOut(600, function() {
                                    $(this).remove();
                                    if ($('#optistate-integrity-results tbody tr').length === 0) {
                                        $('#optistate-integrity-results').html(`<div class="notice notice-success inline os-p-10-border-success"><p><strong>✅ ${__('Perfect Integrity!', 'optistate')}</strong></p><p>${__('All integrity issues have been resolved.', 'optistate')}</p></div>`);
                                    }
                                });
                                if (typeof loadStats === 'function') loadStats(false);
                                debouncedLoadOptimizationLog();
                            }
                        } else {
                            showToast(response.data.message || 'Fix failed', 'error');
                            $btn.prop('disabled', false).text(__('Retry', 'optistate'));
                        }
                    }).fail(() => {
                        showToast(__('Network error.', 'optistate'), 'error');
                        $btn.prop('disabled', false).text(__('Retry', 'optistate'));
                    });
                })();
            });
        });
    }

    function renderIntegrityResults(data) {
        const $results = $('#optistate-integrity-results');
        if (data.total === 0) {
            $results.html(`<div class="notice notice-success inline os-p-10"><p><strong>✅ ${__('Perfect Integrity!', 'optistate')}</strong></p><p>${__('No orphaned rows or broken relationships were found in your database.', 'optistate')}</p></div>`);
        } else {
            let html = ` <div class="notice notice-warning inline os-p-10-mb-15"><p><strong>⚠️ ${sprintf(__('%s Integrity Issues Found', 'optistate'), data.total.toLocaleString())}</strong></p><p>${__('These rows are "orphaned" - they point to parent data that no longer exists. They serve no purpose and can be safely removed.', 'optistate')}</p></div> <table class="widefat striped os-border-table-full"><thead><tr><th class="os-font-bold">${__('Issue Type', 'optistate')}</th><th class="os-font-bold">${__('Orphans Found', 'optistate')}</th><th class="os-font-bold">${__('Examples (ID: Context)', 'optistate')}</th><th class="os-text-right-bold">${__('Action', 'optistate')}</th></thead><tbody>`;
            data.issues.forEach(issue => {
                let examples = issue.samples.map(s => `<code class="os-orphan-code">#${s.orphan_id}: ${esc_html(s.context || 'N/A')}</code>`).join(', ');
                if (issue.count > 5) examples += `, ...`;
                html += ` <tr id="integrity-row-${issue.type}"> <td><strong>${esc_html(issue.label)}</strong><br><span class="os-font-11-gray">${issue.child_table} <span class="dashicons dashicons-arrow-right-alt os-font-12-arrow"></span> ${issue.parent_table}</span></td> <td class="os-color-danger-bold">${issue.count.toLocaleString()}</td> <td class="os-font-12">${examples}</td> <td class="os-text-right"><button class="button optistate-fix-integrity-btn" data-type="${issue.type}" data-count="${issue.count}">${__('Fix Orphans', 'optistate')}</button></td> </tr>`;
            });
            html += `</tbody></table>`;
            $results.html(html);
        }
        $results.slideDown(300);
    }

    function initCacheStatsElements() {
        if ($cacheStatsElements === null) {
            $cacheStatsElements = {
                fileCount: $('#cache-file-count'),
                totalSize: $('#cache-total-size'),
                mobileCount: $('#cache-mobile-file-count'),
                avgSize: $('#cache-average-size'),
                lastWrite: $('#cache-last-write'),
                oldestPage: $('#cache-oldest-page')
            };
        }
        return $cacheStatsElements;
    }

    function loadCacheStats(callback) {
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_get_cache_stats',
            nonce: optistate_Ajax.nonce
        }).done(function(response) {
            const $elements = initCacheStatsElements();
            const update = () => {
                const data = response?.success ? response.data : null;
                const errorText = __('Error', 'optistate');
                $elements.fileCount.text(data ? (typeof data.file_count === 'number' ? data.file_count.toLocaleString() : data.file_count) : errorText);
                $elements.totalSize.text(data ? data.total_size : errorText);
                $elements.mobileCount.text(data ? (typeof data.mobile_file_count === 'number' ? data.mobile_file_count.toLocaleString() : data.mobile_file_count) : errorText);
                $elements.avgSize.text(data ? data.average_size : errorText);
                $elements.lastWrite.text(data ? data.last_write : errorText);
                $elements.oldestPage.text(data ? data.oldest_page : errorText);
            };
            requestAnimationFrame(() => {
                update();
                if (typeof callback === 'function') {
                    callback(response);
                }
            });
        }).fail(function() {
            const $elements = initCacheStatsElements();
            requestAnimationFrame(() => Object.values($elements).forEach(el => el.text(__('Error', 'optistate'))));
        });
    }

    function initPerformanceFeatures() {
        loadPerformanceFeatures();
        $perfFeaturesContainer.on('change', '.optistate-performance-feature .optistate-feature-toggle input', function() {
            const $toggle = $(this);
            const $wrapper = $toggle.closest('.optistate-performance-feature');
            const $panel = $wrapper.find('.server-cache-settings-panel');
            const $label = $toggle.closest('.optistate-feature-control').find('.optistate-toggle-label');
            const isChecked = $toggle.is(':checked');
            const feature = $wrapper.data('feature');
            $label.text(isChecked ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate'));
            if (['server_caching', 'db_query_caching', 'bad_bot_blocker', 'font_optimization', 'security_headers'].includes(feature)) {
                isChecked ? $panel.slideDown(300) : $panel.slideUp(300);
            }
            if (feature === 'font_optimization' && isChecked) {
                $panel.find('.font-remove-toggle').trigger('change');
            }
        });
        $perfFeaturesContainer.on('change', '.font-remove-toggle', function() {
            const isRemoveChecked = $(this).is(':checked');
            const $panel = $(this).closest('.server-cache-settings-panel');
            const $otherOptions = $panel.find('input[type="checkbox"]').not(this);
            $otherOptions.prop('disabled', isRemoveChecked).prop('checked', !isRemoveChecked && $otherOptions.prop('checked'));
            $otherOptions.closest('.optistate-db-setting-row').toggleClass('os-option-disabled', isRemoveChecked);
        });
        $perfFeaturesContainer.on('input propertychange', '.bad-bot-list', function() {
            const val = $(this).val();
            const lines = val.split('\n');
            if (lines.some(l => l.length > 150)) {
                const start = this.selectionStart;
                $(this).val(lines.map(l => l.substring(0, 150)).join('\n'));
                this.setSelectionRange(start, start);
            }
        });
        $perfFeaturesContainer.on('click', '.reset-bots-btn', function() {
            showOPTISTATEModal(__('⟲ Confirm Reset', 'optistate'), __('Are you sure you want to reset the blocked list to defaults?', 'optistate'), function() {
                $(this).closest('.os-feature-info-box').find('textarea').val(decodeURIComponent($(this).data('default')));
            }.bind(this));
        });
        $('#save-performance-features-btn').on('click', function() {
            if (isProcessing) return;
            const $btn = $(this);
            const features = {};
            $('.optistate-performance-feature').each(function() {
                const $feature = $(this);
                const featureKey = $feature.data('feature');
                if (featureKey === 'server_caching') {
                    features.server_caching = {
                        enabled: $feature.find('.optistate-feature-toggle input').is(':checked'),
                        lifetime: $feature.find('#server-caching-lifetime').val(),
                        query_string_mode: $feature.find('#server-caching-query-mode').val(),
                        exclude_urls: $feature.find('#server-caching-exclude-urls').val(),
                        mobile_cache: $feature.find('#server-caching-mobile-toggle').is(':checked'),
                        disable_cookie_check: $feature.find('#server-caching-disable-cookie-check').is(':checked'),
                        custom_consent_cookie: $feature.find('#server-caching-custom-cookie').val(),
                        auto_preload: $feature.find('#server-caching-auto-preload').is(':checked'),
                        minify_html: $feature.find('#server-caching-minify-html').is(':checked')
                    };
                } else if (featureKey === 'db_query_caching') {
                    features.db_query_caching = {
                        enabled: $feature.find('.optistate-feature-toggle input').is(':checked'),
                        ttl_main: $feature.find('.db-ttl-main').val(),
                        ttl_secondary: $feature.find('.db-ttl-secondary').val(),
                        exclude_post_types: $feature.find('.db-exclude-types').val(),
                        exclude_ids: $feature.find('.db-exclude-ids').val(),
                        flush_on_comments: $feature.find('.db-flush-comment').is(':checked'),
                        flush_on_save: $feature.find('.db-flush-save').is(':checked')
                    };
                } else if (featureKey === 'font_optimization') {
                    features.font_optimization = {
                        enabled: $feature.find('.optistate-feature-toggle input').is(':checked'),
                        async_google_fonts: $feature.find('.font-async-toggle').is(':checked'),
                        display_swap: $feature.find('.font-swap-toggle').is(':checked'),
                        preconnect: $feature.find('.font-preconnect-toggle').is(':checked'),
                        remove_google_fonts: $feature.find('.font-remove-toggle').is(':checked')
                    };
                } else if (featureKey === 'security_headers') {
                    features.security_headers = {
                        enabled: $feature.find('.optistate-feature-toggle input').is(':checked'),
                        optional_headers_enabled: $feature.find('#security-headers-optional').is(':checked')
                    };
                } else if (featureKey === 'bad_bot_blocker') {
                    features.bad_bot_blocker = {
                        enabled: $feature.find('.optistate-feature-toggle input').is(':checked'),
                        user_agents: $feature.find('.bad-bot-list').val()
                    };
                } else if (featureKey === 'cookie_banner_detection') {
                    features.cookie_banner_detection = $feature.find('.optistate-feature-toggle input').is(':checked');
                } else {
                    const $select = $feature.find('.optistate-feature-select');
                    features[featureKey] = $select.length ? $select.val() : $feature.find('.optistate-feature-toggle input').is(':checked');
                }
            });
            isProcessing = true;
            apiRequest({
                action: 'optistate_save_performance_features',
                data: {
                    features: features
                },
                $btn: $btn,
                loadingText: `✓ ${__('Saving...', 'optistate')}`,
                errorMsg: __('Failed to save settings.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response?.success) {
                        showToast(__('Performance settings have been saved successfully.', 'optistate'), 'success');
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response?.data?.message || __('Failed to save settings.', 'optistate'), 'error');
                    }
                }
            }).always(() => {
                isProcessing = false;
            });
        });
    }

    function loadPerformanceFeatures() {
        const $loading = $('#optistate-performance-features-loading');
        const $actions = $('#optistate-performance-features-actions');
        $loading.show();
        $perfFeaturesContainer.hide();
        $actions.hide();
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_get_performance_features',
            nonce: optistate_Ajax.nonce
        }).done(function(response) {
            if (response?.success && response.data) {
                displayPerformanceFeatures(response.data.definitions, response.data.features, response.data.revisions_defined, response.data.trash_days_defined, response.data.current_user_agent, response.data.cron_jobs);
                $loading.hide();
                $perfFeaturesContainer.fadeIn(300);
                $actions.fadeIn(300);
                if ($('#server-caching-auto-preload').length) {
                    checkPreloadStatus();
                }
            } else {
                showToast(__('Failed to load performance features', 'optistate'), 'error');
            }
        }).fail(() => {
            showToast(__('Network error loading performance features', 'optistate'), 'error');
            $loading.hide();
        });
    }

    function renderCronJobsTable(jobs) {
        var $wrapper = $('#cron-jobs-table-wrapper');
        if (!jobs || jobs.length === 0) {
            $wrapper.html('<div class="notice notice-info inline"><p>' + __('No cron jobs found.', 'optistate') + '</p></div>');
            return;
        }
        var html = '<table class="widefat striped os-border-table-full">';
        html += '<thead>';
        html += '<tr>';
        html += '<th>' + __('Hook', 'optistate') + '</th>';
        html += '<th>' + __('Schedule', 'optistate') + '</th>';
        html += '<th>' + __('Next Run', 'optistate') + '</th>';
        html += '<th>' + __('State', 'optistate') + '</th>';
        html += '<th>' + __('Actions', 'optistate') + '</th>';
        html += '</tr>';
        html += '</thead>';
        html += '<tbody>';
        jobs.forEach(function(job) {
            var state = job.state || 'normal';
            var stateLabel = state === 'normal' ? __('Normal', 'optistate') : (state === 'paused' ? '⏸️ ' + __('Paused', 'optistate') : '🐢 ' + __('Slowed', 'optistate'));
            var nextRun = job.next_run ? new Date(job.next_run * 1000).toLocaleString() : __('N/A', 'optistate');
            var scheduleDisplay = job.schedule ? job.schedule : __('One-time', 'optistate');
            if (job.interval) {
                scheduleDisplay += ' (' + job.interval + 's)';
            }
            var canSlow = job.can_slow_down && state === 'normal';
            var isPaused = state === 'paused';
            var isSlowed = state === 'slowed';
            var isProtected = job.protected === true;
            html += '<tr data-event-id="' + esc_attr(job.id) + '">';
            html += '<td><code>' + esc_html(job.hook) + '</code></td>';
            html += '<td>' + esc_html(scheduleDisplay) + '</td>';
            html += '<td>' + esc_html(nextRun) + '</td>';
            html += '<td>' + stateLabel + '</td>';
            html += '<td>';
            if (isProtected) {
                html += '<span class="optistate-protected-badge" title="' + esc_attr(__('System cron job – cannot be modified', 'optistate')) + '">🔒 ' + __('Protected', 'optistate') + '</span>';
            } else {
                if (isPaused) {
                    html += '<button class="button button-small cron-resume-btn" data-action="resume" data-event-id="' + esc_attr(job.id) + '">' + '▶ ' + __('Resume', 'optistate') + '</button> ';
                } else if (isSlowed) {
                    html += '<button class="button button-small cron-restore-btn" data-action="restore" data-event-id="' + esc_attr(job.id) + '">' + '↩ ' + __('Restore', 'optistate') + '</button> ';
                } else {
                    html += '<button class="button button-small cron-pause-btn" data-action="pause" data-event-id="' + esc_attr(job.id) + '">' + '⏸ ' + __('Pause', 'optistate') + '</button> ';
                    if (canSlow) {
                        html += '<button class="button button-small cron-slowdown-btn" data-action="slowdown" data-event-id="' + esc_attr(job.id) + '">' + '🐢 ' + __('Slow Down', 'optistate') + '</button> ';
                    }
                }
                html += '<button class="button button-small cron-run-now-btn" data-action="run_now" data-event-id="' + esc_attr(job.id) + '">' + '▶ ' + __('Run Now', 'optistate') + '</button>';
            }
            html += '</td>';
            html += '</tr>';
        });
        html += '</tbody>';
        html += '</table>';
        $wrapper.html(html);
        var $count = $('.os-cron-count');
        if ($count.length) {
            $count.text(sprintf(__('%s cron jobs', 'optistate'), jobs.length));
        }
        $wrapper.find('.cron-pause-btn, .cron-resume-btn, .cron-slowdown-btn, .cron-restore-btn, .cron-run-now-btn').off('click').on('click', function() {
            var $btn = $(this);
            var action = $btn.data('action');
            var eventId = $btn.data('event-id');
            if (!eventId || !action) return;
            if ($btn.prop('disabled')) return;
            handleCronAction(action, eventId, $btn);
        });
    }

    function handleCronAction(action, eventId, $btn) {
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline"></span>');
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_cron_manager_action',
            nonce: optistate_Ajax.nonce,
            cron_action: action,
            event_id: eventId
        }).done(function(response) {
            if (response.success) {
                showToast(response.data.message || __('Action completed.', 'optistate'), 'success');
                refreshCronJobs();
                debouncedLoadOptimizationLog();
            } else {
                showToast(response.data.message || __('Action failed.', 'optistate'), 'error');
                $btn.prop('disabled', false).html(originalText);
            }
        }).fail(function(xhr) {
            const msg = xhr.responseJSON?.data?.message || __('Network error.', 'optistate');
            const type = xhr.status === 429 ? 'warning' : 'error';
            showToast(msg, type);
            $btn.prop('disabled', false).html(originalText);
        });
    }

    function refreshCronJobs(callback) {
        var $wrapper = $('#cron-jobs-table-wrapper');
        $wrapper.html('<div class="optistate-loading">' + __('Refreshing cron jobs...', 'optistate') + '</div>');
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_get_performance_features',
            nonce: optistate_Ajax.nonce,
            refresh: 1
        }).done(function(response) {
            if (response.success && response.data && response.data.cron_jobs) {
                renderCronJobsTable(response.data.cron_jobs);
            } else {
                $wrapper.html('<div class="notice notice-error inline"><p>' + __('Failed to refresh cron jobs.', 'optistate') + '</p></div>');
            }
        }).fail(function() {
            $wrapper.html('<div class="notice notice-error inline"><p>' + __('Network error while refreshing.', 'optistate') + '</p></div>');
        }).always(function() {
            if (typeof callback === 'function') callback();
        });
    }
    $(document).on('click', '#refresh-cron-jobs-btn', function() {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        const now = Date.now();
        if (now - lastCronRefreshTime < 3000) {
            showToast(__('Rate limit exceeded. Try again in a moment.', 'optistate'), 'warning');
            return;
        }
        lastCronRefreshTime = now;
        $btn.prop('disabled', true);
        refreshCronJobs(function() {
            $btn.prop('disabled', false);
        });
    });

    function displayPerformanceFeatures(definitions, currentSettings, revisions_defined, trash_days_defined, current_user_agent, cron_jobs) {
        if (!definitions || !currentSettings) return showToast(__('Failed to load performance features: Invalid data structure', 'optistate'), 'error');
        const categoryMap = {
            'caching': {
                label: 'Caching',
                icon: 'dashicons-database'
            },
            'frontend': {
                label: 'Frontend Optimization',
                icon: 'dashicons-format-image'
            },
            'backend': {
                label: 'Database & Backend',
                icon: 'dashicons-admin-tools'
            },
            'security': {
                label: 'Security & Bot Control',
                icon: 'dashicons-shield'
            },
            'header': {
                label: 'Header & Meta Cleanup',
                icon: 'dashicons-editor-removeformatting'
            }
        };
        const featureCategory = {
            'server_caching': 'caching',
            'browser_caching': 'caching',
            'db_query_caching': 'caching',
            'lazy_load': 'frontend',
            'font_optimization': 'frontend',
            'emoji_script': 'frontend',
            'post_revisions': 'backend',
            'trash_auto_empty': 'backend',
            'heartbeat_api': 'backend',
            'self_pingbacks': 'backend',
            'bad_bot_blocker': 'security',
            'security_headers': 'security',
            'xmlrpc': 'security',
            'file_editor': 'security',
            'application_passwords': 'security',
            'rest_api_link': 'header',
            'shortlink': 'header',
            'rsd_link': 'header',
            'wlwmanifest': 'header',
            'wp_generator': 'security',
            'feed_links': 'header',
            'post_relational_links': 'header'
        };
        const orderedCategories = ['caching', 'frontend', 'backend', 'security', 'header'];
        const grouped = {};
        orderedCategories.forEach(cat => {
            grouped[cat] = [];
        });
        Object.keys(definitions).forEach(key => {
            const cat = featureCategory[key] || 'backend';
            if (grouped[cat]) grouped[cat].push(key);
        });
        $perfFeaturesContainer.empty();
        const fragment = document.createDocumentFragment();
        orderedCategories.forEach(cat => {
            const featuresInGroup = grouped[cat] || [];
            if (featuresInGroup.length === 0) return;
            const categoryInfo = categoryMap[cat];
            const groupWrapper = document.createElement('div');
            groupWrapper.className = 'optistate-perf-group os-mt-20';
            groupWrapper.innerHTML = ` <div class="optistate-prf-hd"> <span class="dashicons ${categoryInfo.icon}" style="font-size:24px; width:24px; height:24px;"></span> <h3 style="margin:0;">${categoryInfo.label}</h3> </div> <div class="optistate-perf-group-body" style="margin-bottom:45px;"></div> `;
            const body = groupWrapper.querySelector('.optistate-perf-group-body');
            featuresInGroup.forEach(key => {
                const feature = definitions[key];
                const currentValue = currentSettings.hasOwnProperty(key) ? currentSettings[key] : feature.default;
                const impactClass = 'impact-' + (feature.impact || 'low');
                const impactLabel = feature.impact === 'high' ? __('High Impact', 'optistate') : (feature.impact === 'medium' ? __('Medium Impact', 'optistate') : __('Low Impact', 'optistate'));
                const warningBadge = !feature.safe ? `<span class="optistate-warning-badge">⚠️ ${__('TEST CAREFULLY', 'optistate')}</span>` : '';
                let controlHTML = '';
                if (feature.type === 'custom_caching' && key === 'server_caching') {
                    const s = currentSettings.server_caching || feature.default;
                    const lifetimeOpts = {
                        3600: '1 Hour',
                        7200: '2 Hours',
                        21600: '6 Hours',
                        43200: '12 Hours',
                        86400: '1 Day',
                        259200: '3 Days',
                        604800: '1 Week',
                        1209600: '2 Weeks',
                        2592000: '1 Month',
                        7776000: '3 Months',
                        15552000: '6 Months'
                    };
                    const lifetimeHTML = Object.entries(lifetimeOpts).map(([val, label]) => `<option value="${val}" ${String(s.lifetime) === val ? 'selected' : ''}>${__(label, 'optistate')}</option>`).join('');
                    const queryOpts = {
                        'ignore_all': __('1. Ignore All Query Strings', 'optistate'),
                        'include_safe': __('2. Include Safe Query Strings (Recommended)', 'optistate'),
                        'unique_cache': __('3. Unique Cache for All Query Strings (Advanced)', 'optistate')
                    };
                    const queryHTML = Object.entries(queryOpts).map(([val, label]) => `<option value="${val}" ${s.query_string_mode === val ? 'selected' : ''}>${label}</option>`).join('');
                    controlHTML = ` <div class="optistate-feature-control main-toggle"> <label class="optistate-feature-toggle"><input type="checkbox" ${s.enabled ? 'checked' : ''}><span class="optistate-toggle-slider"></span></label> <span class="optistate-toggle-label">${s.enabled ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate')}</span> </div> <div class="server-cache-settings-panel ${!s.enabled ? 'os-panel-hidden' : ''}"> <div class="server-cache-panel-grid"> <div class="cache-stats"> <h4 class="optistate-cache-refresh"> <span><span class="dashicons dashicons-chart-bar"></span> ${__('Cache Status', 'optistate')}</span> <button class="button button-small optistate-cache-updt" title="${__('Refresh Cache Stats', 'optistate')}"> <span class="dashicons dashicons-update"></span> </button> </h4> <div class="stat-item"><strong>${__('Cached Pages:', 'optistate')}</strong> <span id="cache-file-count">...</span></div> <div class="stat-item"><strong>${__('Mobile Pages:', 'optistate')}</strong> <span id="cache-mobile-file-count">...</span></div> <div class="stat-item"><strong>${__('Total Size:', 'optistate')}</strong> <span id="cache-total-size">...</span></div> <div class="stat-item"><strong>${__('Avg. Page Size:', 'optistate')}</strong> <span id="cache-average-size">...</span></div> <div class="stat-item"><strong>${__('Last Write:', 'optistate')}</strong> <span id="cache-last-write">...</span></div> <div class="stat-item"><strong>${__('Oldest Page:', 'optistate')}</strong> <span id="cache-oldest-page">...</span></div> <button type="button" class="button button-secondary" id="purge-page-cache-btn">${__('🗑️ Purge All Cache', 'optistate')}</button> <div class="optistate-minify-html-section"> <div class="optistate-auto-preload-section"> <label for="server-caching-minify-html"> <input type="checkbox" id="server-caching-minify-html" ${s.minify_html ? 'checked' : ''}> <strong>${__('🗜 Minify HTML before caching', 'optistate')}</strong> </label> <p class="description">${__('Removes extra whitespace and line breaks from cached HTML files.<br><br>Reduces file size by 10-30%.<br>Recommended for high-traffic sites.', 'optistate')}</p> </div> <div class="optistate-auto-preload-section"> <label for="server-caching-auto-preload"> <input type="checkbox" id="server-caching-auto-preload" ${s.auto_preload ? 'checked' : ''}> <strong>${__('🔋 Automatic Preload', 'optistate')}</strong> </label> <p class="optistate-auto-preload-description"> ${__('Automatically cache all pages from your sitemap after purging the cache.', 'optistate')}<br><br> ${__('⚠️ Disable any cookie consent plugin before launching preload, then reactivate it upon completion.', 'optistate')}<br><br> ${__('ℹ️ This process will take a while and consume storage space.', 'optistate')}<br><br> </p> <div id="preload-progress-wrapper" class="optistate-preload-progress-wrapper"> <div class="optistate-preload-header"> <strong>${__('⌛ Preloading in progress...', 'optistate')}</strong> <button type="button" class="button button-small" id="stop-preload-btn">${__('🟥 Stop', 'optistate')}</button> </div> <div class="optistate-preload-bar-container"><div id="preload-progress-bar" class="optistate-preload-bar">0%</div></div> <div id="preload-status-text" class="optistate-preload-status">${__('Initializing...', 'optistate')}</div> <br>${__('🛈 ︎You can leave this page, processing will continue.', 'optistate')} </div> </div> </div> </div> <div class="cache-settings"> <h4><span class="dashicons dashicons-admin-settings"></span> ${__('Configuration', 'optistate')}</h4> <div class="setting-item"> <label for="server-caching-lifetime">${__('🕒 Cache Lifetime', 'optistate')}</label> <select id="server-caching-lifetime">${lifetimeHTML}</select> ${__('How long a cached page is considered fresh. After this time, a new version will be generated.', 'optistate')} </div> <div class="setting-item"> <label for="server-caching-query-mode">${__('❓ Query String Handling', 'optistate')}</label> <select id="server-caching-query-mode">${queryHTML}</select> <div id="query-mode-descriptions" class="optistate-query-mode-descriptions"> <div class="query-mode-desc" data-mode="ignore_all"> ${__('Serves the same cached page for all query strings.', 'optistate')} <br><strong>${__('Example:', 'optistate')}</strong> <code>/page?utm=123</code> ${__('serves the cache for', 'optistate')} <code>/page</code>. <br><strong>${__('Best for:', 'optistate')}</strong> ${__('Simple websites that do not use pagination (e.g., ?page=2) and search functionality.', 'optistate')} <br><strong>${__('🗑️ ', 'optistate')}</strong> ${__('Changes will trigger a full cache purge.', 'optistate')} </div> <div class="query-mode-desc" data-mode="include_safe"> ${__('Creates unique cache files for "safe" parameters like pagination.', 'optistate')} <br><strong>${__('Example:', 'optistate')}</strong> <code>/blog?page=2</code> ${__('is cached separately from', 'optistate')} <code>/blog</code>. <br><strong>${__('Best for:', 'optistate')}</strong> ${__('Most websites, especially those with pagination, custom archives, search functionality.', 'optistate')} <br><strong>${__('🗑️ ', 'optistate')}</strong> ${__('Changes will trigger a full cache purge.', 'optistate')} </div> <div class="query-mode-desc" data-mode="unique_cache"> ${__('Creates a separate cache file for every unique query string.', 'optistate')} <br><strong>${__('Example:', 'optistate')}</strong> <code>/page?a=1</code> ${__('and', 'optistate')} <code>/page?a=2</code> ${__('are cached as two different files.', 'optistate')} <br><strong class="optistate-critical-warning">${__('⚠️ Warning:', 'optistate')}</strong> ${__('This can use a very large amount of disk space.', 'optistate')} <br><strong>${__('🗑 ', 'optistate')}</strong> ${__('Changes will trigger a full cache purge.', 'optistate')} </div> </div> </div> <div class="setting-item"> <label for="server-caching-exclude-urls">${__('⛔️ Exclude Pages from Cache', 'optistate')}</label> <textarea id="server-caching-exclude-urls" rows="6" placeholder="/cart/*&#10;/forum/*&#10;/my-custom-page/&#10;&#10;">${esc_html(s.exclude_urls || '')}</textarea> <div class="optistate-smart-exclusions-info"> <strong>${__('💡 Smart Exclusions Already Active:', 'optistate')}</strong> <p> ${__('🔸 Logged-in users (never cached)', 'optistate')}<br> ${__('🔸 Cart & checkout pages (auto-detected)', 'optistate')}<br> ${__('🔸 URLs with tracking parameters (utm_*, fbclid, gclid)', 'optistate')}<br> ${__('🔸 Search results & 404 pages', 'optistate')}<br> ${__('🔸 Cookie banners (see "Smart Cookie Detection" below)', 'optistate')} </p> </div> <div class="optistate-exclude-help"> ${__('Enter parts of URLs to exclude, one per line. Use * as a wildcard.', 'optistate')} </div> <div class="cache-examples"> <strong>${__('🎯 Examples:', 'optistate')}</strong>${__('🔹 To exclude the homepage:', 'optistate')} <code>/</code><br> ${__('🔹 To exclude a specific page:', 'optistate')} <code>/contact-us/</code><br> ${__('🔹 To exclude all blog posts:', 'optistate')} <code>/blog/*</code><br> ${__('🔹 To exclude member area:', 'optistate')} <code>/members/*</code><br> ${__('✖ Wrong:', 'optistate')} https://www.yourwebsite.com<code>/contact-us/</code> </div> </div> <div class="setting-item"> <label for="server-caching-mobile-toggle">${__('📲 Mobile-Specific Cache', 'optistate')}</label> <label class="optistate-checkbox-label"> <input type="checkbox" id="server-caching-mobile-toggle" ${s.mobile_cache ? 'checked' : ''}> ${__('Create separate cache files for mobile devices', 'optistate')} </label> ${__('Enable this ONLY if your site uses a different theme or layout for mobile visitors.', 'optistate')} </div> <div class="setting-item"> <label for="server-caching-disable-cookie-check" class="optistate-checkbox-label"> <input type="checkbox" id="server-caching-disable-cookie-check" ${s.disable_cookie_check ? 'checked' : ''}> <strong>${__('🛡️ Disable Cookie Checks (Maximum Performance)', 'optistate')}</strong> </label> ${__('Check this option to serve cached pages to all visitors immediately for maximum performance.', 'optistate')} <div class="optistate-warning-text"> <span class="optistate-warning-label">${__('⚠️ Warning:', 'optistate')}</span> ${__('Only check this option if your site does not have any cookie banner/consent management plugin.', 'optistate')} </div> </div> <div class="optistate-custom-cookie-section"> <label for="server-caching-custom-cookie" class="optistate-custom-cookie-label"> <strong>${__('⤷ Add Custom Consent Cookie', 'optistate')}</strong> </label> <input type="text" id="server-caching-custom-cookie" class="optistate-custom-cookie-input" value="${esc_attr(s.custom_consent_cookie || '')}" placeholder="${esc_attr(__('e.g., my_custom_consent_cookie', 'optistate'))}"> <p class="optistate-custom-cookie-help"> ${__('If your site uses a custom or unsupported cookie banner, add the cookie name here.', 'optistate')}<br> ${__('This will ensure non-cached pages are served until this cookie is present.', 'optistate')} </p> </div> </div> </div> <div class="optistate-feature-info-box"> <h4><span class="dashicons dashicons-shield-alt"></span> ${__('Smart Cookie Detection', 'optistate')}</h4> <p>${__('This caching feature automatically detects consent from major WordPress cookie plugins:', 'optistate')}</p> <ul> <li>${__('✔ Users WITH consent cookies ➝ see cached pages (fast).', 'optistate')}</li> <li>${__('✖ Users WITHOUT consent cookies ➝ see fresh pages (privacy-safe).', 'optistate')}</li> </ul> <p><strong>${__('Supported Plugins:', 'optistate')}</strong> CookieYes, Complianz, Cookie Notice, Borlabs Cookie, Real Cookie Banner, Cookiebot, OneTrust, Termly, Iubenda, GDPR Cookie Consent, and 10+ more.</p> <p class="optistate-tip-box os-mb-15"> <strong>${__('💡 Tip:', 'optistate')}</strong> ${__('If you don\'t use any consent plugin, select "Disable Cookie Checks" above for best performance.', 'optistate')} </p> </div> <div class="optistate-feature-info-box"> <h4><span class="dashicons dashicons-controls-play"></span> ${__('Automatic Cache Purging', 'optistate')}</h4> <p>${__('This feature is smart! You don\'t need to manually purge the cache every time you make a change. The cache for relevant pages is automatically cleared when you:', 'optistate')}</p> <ul> <li>${__('Publish or update a post or page.', 'optistate')}</li> <li>${__('Change a post\'s URL (slug).', 'optistate')}</li> <li>${__('Approve, unapprove, or delete a comment.', 'optistate')}</li> <li>${__('Update a category or tag.', 'optistate')}</li> <li>${__('Update your website menu.', 'optistate')}</li> </ul> </div> </div>`;
                } else if (feature.type === 'custom_db_caching' && key === 'db_query_caching') {
                    const s = {
                        enabled: false,
                        ttl_main: 43200,
                        ttl_secondary: 86400,
                        exclude_post_types: 'shop_order,ticket,product',
                        flush_on_comments: true,
                        exclude_ids: '',
                        flush_on_save: true,
                        ...((typeof currentValue === 'object') ? currentValue : {
                            enabled: currentValue
                        })
                    };
                    const ttlOpts = {
                        300: __('5 Minutes', 'optistate'),
                        3600: __('1 Hour', 'optistate'),
                        14400: __('4 Hours', 'optistate'),
                        21600: __('6 Hours', 'optistate'),
                        43200: __('12 Hours', 'optistate'),
                        86400: __('24 Hours', 'optistate'),
                        604800: __('1 Week', 'optistate')
                    };
                    const renderTTL = (val) => Object.entries(ttlOpts).map(([v, l]) => `<option value="${v}" ${String(val) === v ? 'selected' : ''}>${l}</option>`).join('');
                    controlHTML = ` <div class="optistate-feature-control main-toggle" ${feature.disabled ? 'class="os-opacity-50"' : ''}> <label class="optistate-feature-toggle"><input type="checkbox" ${s.enabled ? 'checked' : ''} ${feature.disabled ? 'disabled' : ''}><span class="optistate-toggle-slider"></span></label> <span class="optistate-toggle-label">${s.enabled ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate')}</span> </div> <div class="server-cache-settings-panel ${!s.enabled ? 'os-panel-hidden' : ''}"> <div class="server-cache-panel-grid"> <div class="cache-settings"> <h4><span class="dashicons dashicons-clock"></span> ${__('Cache Lifetimes (TTL)', 'optistate')}</h4> <div class="setting-item"> <label> ${__('Main Query TTL', 'optistate')} <span class="optistate-tooltip" title="${esc_attr(__('Main queries are your homepage, single posts/pages, and archive pages. Longer TTL = better performance, but content updates won\'t appear until cache expires.', 'optistate'))}">ⓘ</span> </label> <select class="db-ttl-main os-mb-10">${renderTTL(s.ttl_main)}</select> <span class="optistate-tip">${__('Tip: Set to 12-24 hours for mostly static sites, 1-4 hours for frequently updated sites.', 'optistate')}</span> </div> <div class="setting-item"> <label> ${__('Secondary Query TTL', 'optistate')} <span class="optistate-tooltip" title="${esc_attr(__('Secondary queries include widgets, recent posts, related posts, and custom queries. These can usually be cached longer as they\'re less critical.', 'optistate'))}">ⓘ</span> </label> <select class="db-ttl-secondary os-mb-10">${renderTTL(s.ttl_secondary)}</select> <span class="optistate-tip">${__('Tip: Secondary queries can typically be cached 2-3x longer than main queries.', 'optistate')}</span> </div> </div> <div class="cache-settings"> <h4><span class="dashicons dashicons-filter"></span> ${__('Exclusions & Triggers', 'optistate')}</h4> <div class="setting-item"> <label>${__('⛔️ Exclude Post Types', 'optistate')}</label> <input type="text" class="db-exclude-types os-input-full-mb" value="${esc_attr(s.exclude_post_types)}" placeholder="e.g., shop_order,ticket,product"><br> <span class="optistate-tip">${__('Post types that should never be cached (comma-separated). Exclude dynamic content like orders, tickets, or forms.<br>Default exclusions: shop_order, ticket, product.', 'optistate')}</span> </div> <div class="setting-item"> <label>${__('⛔️ Exclude Post IDs', 'optistate')}</label> <input type="text" class="db-exclude-ids os-input-full-mb" value="${esc_attr(s.exclude_ids)}" placeholder="e.g., 42,105,207"><br> <span class="optistate-tip">${__('Specific post/page IDs whose queries should never be cached (comma-separated). Useful for highly dynamic single posts, e-commerce product pages, or members-only content.', 'optistate')}</span> </div> <div class="setting-item"> <label class="optistate-checkbox-label"> <input type="checkbox" class="db-flush-save" ${s.flush_on_save ? 'checked' : ''}> ${__('💾 Flush on Post Save/Update', 'optistate')} </label> <span class="optistate-tip os-mt-10">${__('When enabled, the entire query cache clears whenever a post or page is published, updated, or deleted. Ensures visitors always see fresh content. Recommended for most sites. Disable only on very high-traffic sites where post updates are very frequent.', 'optistate')}</span> </div> <div class="setting-item"> <label class="optistate-checkbox-label"> <input type="checkbox" class="db-flush-comment" ${s.flush_on_comments ? 'checked' : ''}> ${__('🗨️ Flush on New Comment', 'optistate')} </label> <span class="optistate-tip os-mt-10">${__('When enabled, the entire query cache clears whenever a comment is posted or moderated. Ensures fresh comment counts and lists.<br>Recommended for blogs and community sites. Disable if you have heavy comment traffic and use AJAX for comments.', 'optistate')}</span> </div> </div> </div> <div class="os-p-15-10-9"> <strong>💡 ${__('How Query Caching Works:', 'optistate')}</strong> <ul class="os-m-8-0-0-15"> <li>${__('Caches database query results in Redis/Memcached, reducing MySQL load.', 'optistate')}</li> <li>${__('Main queries = Primary page content; Secondary queries = Sidebars, widgets, loops.', 'optistate')}</li> <li>${__('Cache automatically clears when posts/pages are updated.', 'optistate')}</li> <li>${__('Requires persistent object cache (Redis/Memcached) to function.', 'optistate')}</li> <li>${__('No caching occurs for logged-in users, admins, or during POST requests', 'optistate')}</li> </ul> </div> </div>`;
                } else if (feature.type === 'custom_font_optimization' && key === 'font_optimization') {
                    const s = {
                        enabled: false,
                        async_google_fonts: true,
                        display_swap: true,
                        preconnect: true,
                        remove_google_fonts: false,
                        ...((typeof currentValue === 'object') ? currentValue : {
                            enabled: currentValue
                        })
                    };
                    if (s.remove_google_fonts) {
                        s.async_google_fonts = false;
                        s.display_swap = false;
                        s.preconnect = false;
                    }
                    const otherDisabled = s.remove_google_fonts ? 'disabled' : '';
                    const otherStyle = s.remove_google_fonts ? 'os-option-disabled' : '';
                    controlHTML = ` <div class="optistate-feature-control main-toggle"> <label class="optistate-feature-toggle"><input type="checkbox" ${s.enabled ? 'checked' : ''}><span class="optistate-toggle-slider"></span></label> <span class="optistate-toggle-label">${s.enabled ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate')}</span> </div> <div class="server-cache-settings-panel ${!s.enabled ? 'os-panel-hidden' : ''}"> <div class="server-cache-panel-grid"> <div class="cache-settings os-full-width"> <h4><span class="dashicons dashicons-editor-textcolor"></span> ${__('Optimization Strategy', 'optistate')}</h4> <div class="setting-item optistate-db-setting-row ${otherStyle}"> <label class="optistate-checkbox-label"> <input type="checkbox" class="font-async-toggle" ${s.async_google_fonts ? 'checked' : ''} ${otherDisabled}> <strong>${__('⚡ Async Google Fonts (Eliminate Render Blocking)', 'optistate')}</strong> </label> <span class="optistate-tip os-mt-10">${__('Loads fonts in the background so they don\'t stop the page from rendering. This significantly improves "First Contentful Paint" (FCP) and PageSpeed scores.', 'optistate')}</span> </div> <div class="setting-item optistate-db-setting-row ${otherStyle}"> <label class="optistate-checkbox-label"> <input type="checkbox" class="font-swap-toggle" ${s.display_swap ? 'checked' : ''} ${otherDisabled}> <strong>${__('👁️ Force "display=swap"', 'optistate')}</strong> </label> <span class="optistate-tip os-mt-10">${__('Ensures text is visible immediately using a system font, then swaps to the custom font once loaded. Prevents the "Flash of Invisible Text" (FOIT).', 'optistate')}</span> </div> <div class="setting-item optistate-db-setting-row ${otherStyle}"> <label class="optistate-checkbox-label"> <input type="checkbox" class="font-preconnect-toggle" ${s.preconnect ? 'checked' : ''} ${otherDisabled}> <strong>${__('🔌 Preconnect to Font CDN', 'optistate')}</strong> </label> <span class="optistate-tip os-mt-10">${__('Establishes an early connection to fonts.gstatic.com, reducing the latency when the browser starts downloading fonts.', 'optistate')}</span> </div> <div class="setting-item os-p-15-10-9" style="background: #fff5f5; border-left-color: #d63638;"> <label class="optistate-checkbox-label"> <input type="checkbox" class="font-remove-toggle" ${s.remove_google_fonts ? 'checked' : ''}> <strong class="os-color-danger-bold">${__('🚫 Remove Google Fonts', 'optistate')}</strong> </label> <span class="optistate-tip os-mt-10">${__('Completely stops Google Fonts from loading. Best for privacy and raw performance, but will change your website\'s design to use system fonts.', 'optistate')}</span> </div> </div> </div> </div>`;
                } else if (feature.type === 'custom_security_headers' && key === 'security_headers') {
                    const s = {
                        enabled: false,
                        optional_headers_enabled: false,
                        ...((typeof currentValue === 'object') ? currentValue : {
                            enabled: currentValue
                        })
                    };
                    controlHTML = ` <div class="optistate-feature-control main-toggle"> <div class="os-htaccess-status"><span class="os-htaccess-status-icon"></span><span class="os-htaccess-status-text"></span></div> <label class="optistate-feature-toggle"><input type="checkbox" ${s.enabled ? 'checked' : ''}><span class="optistate-toggle-slider"></span></label> <span class="optistate-toggle-label">${s.enabled ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate')}</span> </div> <div class="server-cache-settings-panel ${!s.enabled ? 'os-panel-hidden' : ''}"> <div class="server-cache-panel-grid"> <div class="cache-settings os-full-width"> <h4>✴ ${__('Optional Security Headers', 'optistate')}</h4> <div class="setting-item optistate-db-setting-row"> <label class="optistate-checkbox-label"> <input type="checkbox" id="security-headers-optional" ${s.optional_headers_enabled ? 'checked' : ''}> <strong>${__('⛊︎ Enable Optional Headers (May Break Sites)', 'optistate')}</strong> </label> <span class="optistate-tip os-mt-10">${__('Adds extra security headers that can cause compatibility issues with some plugins, CDNs, or third-party resources.<br>Test your site and all its features thoroughly after enabling.', 'optistate')} <br><br><strong>${__('Headers added:', 'optistate')}</strong><br> • Cross-Origin-Opener-Policy: same-origin<br> • Cross-Origin-Embedder-Policy: require-corp<br> • X-DNS-Prefetch-Control: off<br> • Strict-Transport-Security with includeSubDomains and preload </span> </div> </div> </div> </div> `;
                } else if (feature.type === 'custom_bot_blocker' && key === 'bad_bot_blocker') {
                    const s = {
                        enabled: false,
                        user_agents: feature.default.user_agents || '',
                        ...((typeof currentValue === 'object') ? currentValue : (currentValue === true ? {
                            enabled: true,
                            user_agents: feature.default.user_agents
                        } : {}))
                    };
                    controlHTML = ` <div class="optistate-feature-control main-toggle"> <label class="optistate-feature-toggle"><input type="checkbox" ${s.enabled ? 'checked' : ''}><span class="optistate-toggle-slider"></span></label> <span class="optistate-toggle-label">${s.enabled ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate')}</span> </div> <div class="server-cache-settings-panel ${!s.enabled ? 'os-panel-hidden' : ''}"> <div class="os-feature-info-box"> <label class="os-font-bold-block"> ${__('<strong>🚫 Blocked User Agents</strong> (One per line, 150 characters max).<br>⚠️ Caution: Do not block user agents commonly found in web browsers.', 'optistate')} </label> <div class="os-p-8-12-warning os-mb-10 os-bot-margin"> <strong>${__('Your User Agent:', 'optistate')}</strong> <code style="background:rgba(0,0,0,0.05); padding:1px 4px; word-break:break-all;">${esc_html(current_user_agent)}</code><br> <span style="color:#d63638;">${__('⚠ To avoid blocking yourself, ensure this string, or any part of it, is NOT in the list below.', 'optistate')}</span> </div> <textarea class="bad-bot-list os-textarea-full" rows="8" placeholder="MJ12bot&#10;AhrefsBot&#10;...">${esc_html(s.user_agents)}</textarea> <div class="os-flex-between-mt"> <span class="description os-text-muted"> ${__('Partial matches allowed. E.g., "Google" blocks "Googlebot". Case-insensitive.<br>You can view a complete list of bots on ', 'optistate')} <a href="https://radar.cloudflare.com/bots/directory?kind=all" target="_blank">${__('this page', 'optistate')}</a>${__('.', 'optistate')} </span> <button type="button" class="button reset-bots-btn" data-default="${encodeURIComponent(feature.default.user_agents || '')}">${__('⟲ Reset to Defaults', 'optistate')}</button> </div> </div> </div>`;
                } else if (feature.type === 'toggle') {
                    if (key === 'browser_caching') {
                        controlHTML = ` <div class="optistate-feature-control"> <div class="os-htaccess-status"><span class="os-htaccess-status-icon"></span><span class="os-htaccess-status-text"></span></div> <label class="optistate-feature-toggle"><input type="checkbox" ${currentValue ? 'checked' : ''} id="caching-toggle"><span class="optistate-toggle-slider"></span></label> <span class="optistate-toggle-label">${currentValue ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate')}</span> </div>`;
                    } else {
                        controlHTML = ` <div class="optistate-feature-control" ${feature.disabled ? 'class="os-opacity-50-no-cursor"' : ''}> <label class="optistate-feature-toggle"><input type="checkbox" ${currentValue ? 'checked' : ''} ${feature.disabled ? 'disabled' : ''}><span class="optistate-toggle-slider"></span></label> <span class="optistate-toggle-label">${currentValue ? __('✔ Active', 'optistate') : __('✗ Inactive', 'optistate')}</span> </div>`;
                    }
                } else if (feature.type === 'custom_cron_manager' && key === 'cron_manager') {
                    controlHTML = ` <div class="optistate-feature-control main-toggle"> <label class="optistate-feature-toggle"> <input type="checkbox" class="cron-manager-toggle"> <span class="optistate-toggle-slider"></span> </label> <span class="optistate-toggle-label cron-toggle-label">${__('Show', 'optistate')}</span> </div> <div id="cron-manager-container" class="cron-manager-panel" style="display:none;"> <div class="os-flex-between-mb"> <span class="os-cron-count">${__('Loading cron jobs...', 'optistate')}</span> <button type="button" class="button button-small" id="refresh-cron-jobs-btn">${__('♻ Refresh', 'optistate')} </button> </div> <div id="cron-jobs-table-wrapper"> <div class="optistate-loading">${__('Fetching cron jobs...', 'optistate')}</div> </div> </div> `;
                } else if (feature.options) {
                    const optionsHTML = Object.entries(feature.options).map(([optKey, label]) => `<option value="${esc_attr(optKey)}" ${currentValue === optKey ? 'selected' : ''}>${esc_html(label)}</option>`).join('');
                    const isDisabled = (key === 'post_revisions' && revisions_defined) || (key === 'trash_auto_empty' && trash_days_defined);
                    controlHTML = `<div class="optistate-feature-control"><select class="optistate-feature-select" ${isDisabled ? 'disabled' : ''}>${optionsHTML}</select></div>`;
                    if (isDisabled) {
                        controlHTML += `<div class="optistate-feature-warning os-p-8-12-warning"><span class="dashicons dashicons-info-outline os-icon-inline"></span> ${sprintf(__('This setting is already defined in your wp-config.php as %s.', 'optistate'), key === 'post_revisions' ? 'WP_POST_REVISIONS' : 'EMPTY_TRASH_DAYS')}</div>`;
                    }
                }
                const div = document.createElement('div');
                div.className = `optistate-performance-feature ${!feature.safe ? 'feature-unsafe' : ''}`;
                div.setAttribute('data-feature', esc_attr(key));
                div.innerHTML = ` <div class="optistate-feature-header"> <div class="optistate-feature-title">${esc_html(feature.title || __('Unnamed', 'optistate'))} ${warningBadge}${feature.manual_url ? `<a href="${feature.manual_url}" class="optistate-info-link os-no-decoration" target="_blank" rel="noopener noreferrer" title="${esc_attr(__('Read the Manual', 'optistate'))}"><span class="dashicons dashicons-info"></span></a>` : ''}</div> <span class="optistate-feature-impact ${impactClass}">${impactLabel}</span> </div> <div class="optistate-feature-description">${(feature.description || '').replace(/<(?!strong>|\/strong>|br\s*\/?>|a[\s>]|\/a>)[^>]*>/gi, '').replace(/<br\s*\/?>/gi, '<br>')}</div> ${controlHTML} `;
                body.appendChild(div);
            });
            fragment.appendChild(groupWrapper);
        });
        $perfFeaturesContainer.append(fragment);
        if (cron_jobs && Array.isArray(cron_jobs)) {
            var $cronContainer = $('#cron-manager-container');
            if ($cronContainer.length) {
                renderCronJobsTable(cron_jobs);
            }
        }
        $perfFeaturesContainer.on('change', '.cron-manager-toggle', function() {
            const $panel = $(this).closest('.optistate-performance-feature').find('.cron-manager-panel');
            const $label = $(this).closest('.optistate-feature-control').find('.cron-toggle-label');
            if ($(this).is(':checked')) {
                $panel.slideDown(300);
                $label.text(__('Hide', 'optistate'));
            } else {
                $panel.slideUp(300);
                $label.text(__('Show', 'optistate'));
            }
        });
        if (definitions.browser_caching || definitions.security_headers) {
            checkHtaccessStatus().then(status => {
                $perfFeaturesContainer.find('.os-htaccess-status').each(function() {
                    updateHtaccessFeatureDisplay(status, $(this));
                });
            });
        }
        if ($('#cache-file-count').length) loadCacheStats();
        $perfFeaturesContainer.on('change', '#server-caching-query-mode', function() {
            const mode = $(this).val();
            $('#query-mode-descriptions .query-mode-desc').hide().filter(`[data-mode="${mode}"]`).show();
        }).find('#server-caching-query-mode').trigger('change');
        if ($('#server-caching-auto-preload').length) checkPreloadStatus();
    }

    function checkHtaccessStatus() {
        return $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_check_htaccess_status',
            nonce: optistate_Ajax.nonce
        }).then(res => ({
            writable: true,
            message: res.data.message
        }), jqXHR => {
            const data = jqXHR?.responseJSON?.data;
            return {
                writable: false,
                message: data?.message || __('Network error', 'optistate'),
                exists: data?.exists
            };
        });
    }

    function updateHtaccessFeatureDisplay(status, $msg) {
        if (!$msg.length) return;
        const $icon = $msg.find('.os-htaccess-status-icon');
        const $text = $msg.find('.os-htaccess-status-text');
        const $toggle = $msg.closest('.optistate-feature-control').find('.optistate-feature-toggle input');
        if (status.writable) {
            $msg.css({
                backgroundColor: '#d4edda',
                border: '1px solid #c3e6cb',
                color: '#155724'
            }).show();
            $icon.html('✅ ');
            $text.html(`<strong>${__('Status:', 'optistate')}</strong> ${status.message}`);
            $toggle.prop('disabled', false);
        } else {
            $msg.css({
                backgroundColor: '#f8d7da',
                border: '1px solid #f5c6cb',
                color: '#721c24'
            }).show();
            $icon.html('⚠️ ');
            $text.html(`<strong>${__('Cannot Enable:', 'optistate')}</strong> ${status.message}`);
            $toggle.prop('disabled', true).prop('checked', false).closest('.optistate-feature-control').find('.optistate-toggle-label').text(__('✗ Inactive', 'optistate'));
        }
    }

    function startPreload() {
        isPreloadCancelled = false;
        $('#stop-preload-btn').html(`🛑 ${__('Stop', 'optistate')}`).prop('disabled', false);
        $('#preload-progress-wrapper').slideDown(300);
        $('#preload-progress-bar').css('width', '0%').text('0%');
        $('#preload-status-text').text(__('Starting preload...', 'optistate'));
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_start_preload',
            nonce: optistate_Ajax.nonce
        }).done(res => {
            if (res?.success) {
                showToast(res.data.message, 'info');
                pollPreloadStatus();
            } else {
                showToast(res?.data?.message || __('Failed to start preload', 'optistate'), 'error');
                $('#preload-progress-wrapper').slideUp(300);
            }
        }).fail(xhr => {
            showToast(xhr.status === 'timeout' ? __('Request timeout', 'optistate') : __('Network error', 'optistate'), 'error');
            $('#preload-progress-wrapper').slideUp(300);
        });
    }

    function checkPreloadStatus() {
        clearTimeout(preloadResumeDebounceTimer);
        preloadResumeDebounceTimer = setTimeout(function() {
            preloadResumeDebounceTimer = null;
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_get_preload_status',
                nonce: optistate_Ajax.nonce
            }).done(res => {
                if (res?.success && res.data.running) {
                    const d = res.data;
                    $('#preload-progress-wrapper').slideDown(300);
                    $('#preload-progress-bar').css('width', `${d.percentage || 0}%`).text(`${d.percentage || 0}%`);
                    $('#preload-status-text').text(sprintf(__('Cached %s of %s pages... Batch size: %s', 'optistate'), (d.processed || 0).toLocaleString(), (d.total || 0).toLocaleString(), (d.batch_size || 0).toLocaleString()));
                    $('#stop-preload-btn').prop('disabled', false).html(`🛑 ${__('Stop', 'optistate')}`);
                    showToast(sprintf(__('🔋 Cache preload resumed (%s of %s pages completed)', 'optistate'), (d.processed || 0).toLocaleString(), (d.total || 0).toLocaleString()), 'info');
                    pollPreloadStatus();
                }
            });
        }, 300);
    }
    $perfFeaturesContainer.on('click', '#stop-preload-btn', function(e) {
        e.preventDefault();
        isPreloadCancelled = true;
        clearTimeout(preloadInterval);
        if (window._preloadPoller) {
            window._preloadPoller.stop();
            window._preloadPoller = null;
        }
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true).html(`<span></span> ${__('Stopping...', 'optistate')}`);
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_stop_preload',
            nonce: optistate_Ajax.nonce
        }).done(res => {
            if (res?.success) {
                showToast(res.data.message, 'info');
                $('#preload-status-text').html(`<strong>${__('Preload stopped by user.', 'optistate')}</strong>`);
                setTimeout(() => $('#preload-progress-wrapper').slideUp(300), 1500);
                loadCacheStats();
                debouncedLoadOptimizationLog();
            } else {
                showToast(__('Failed to stop preload', 'optistate'), 'error');
                $btn.prop('disabled', false).html(`🟥 ${__('Stop', 'optistate')}`);
                isPreloadCancelled = false;
            }
        }).fail(xhr => {
            showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : (xhr.responseJSON?.data?.message || __('Network error', 'optistate')), xhr.status === 429 ? 'warning' : 'error');
            $btn.prop('disabled', false).html(`🟥 ${__('Stop', 'optistate')}`);
            isPreloadCancelled = false;
        });
    });
    $perfFeaturesContainer.on('click', '#purge-page-cache-btn', function(e) {
        e.preventDefault();
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        const msg = `${sprintf(__('You are about to delete all %s cached pages (total size: %s).', 'optistate'), esc_html($('#cache-file-count').text()), esc_html($('#cache-total-size').text()))}<br><br>${__('This is generally not required as the cache clears automatically based on the set cache lifetime. Proceed only if certain.', 'optistate')}`;
        showOPTISTATEModal(__('🗑️ Confirm Cache Purge', 'optistate'), msg, function() {
            $btn.prop('disabled', true).html(`<span class="spinner is-active os-spinner-inline"></span> ${__('PURGING ....', 'optistate')}`);
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_purge_page_cache',
                nonce: optistate_Ajax.nonce
            }).done(res => {
                if (res?.success) {
                    showToast(res.data.message || __('Cache successfully purged!', 'optistate'), 'success');
                    if (res.data.trigger_preload) setTimeout(() => startPreload(), 1000);
                } else showToast(res?.data?.message || __('An error occurred.', 'optistate'), 'error');
            }).fail(xhr => showToast(xhr.status === 429 ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false)) : __('Network error.', 'optistate'), xhr.status === 429 ? 'warning' : 'error')).always(() => {
                $btn.prop('disabled', false).html(`🗑️ ${__('Purge All Cache', 'optistate')}`);
                loadCacheStats();
            });
        });
    });

    function initSettingsImportExportEvents() {
        $('#optistate-export-settings-btn').on('click', function() {
            const $btn = $(this);
            const $status = $('#optistate-export-status');
            $btn.prop('disabled', true);
            $status.html(`<span class="spinner is-active os-spinner-no-margin"></span> <span class="os-text-muted">${__('Preparing export...', 'optistate')}</span>`);
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_export_settings',
                nonce: optistate_Ajax.nonce
            }).done(res => {
                if (res.success) {
                    window.location.href = res.data.download_url;
                    $status.html(`<p class="optistate-success">✓ ${res.data.message}</p>`);
                    debouncedLoadOptimizationLog();
                    setTimeout(() => $status.fadeOut(300, function() {
                        $(this).html('').fadeIn(300);
                    }), 4000);
                } else $status.html(`<p class="optistate-error">✗ ${res.data.message || 'Export failed'}</p>`);
            }).fail(xhr => $status.html(`<p class="optistate-error">✗ ${xhr.responseJSON?.data?.message || (xhr.status === 429 ? getRateLimitMessage(false) : __('Network error', 'optistate'))}</p>`)).always(() => $btn.prop('disabled', false));
        });
        $('#optistate-settings-file-input').on('change', function() {
            const file = this.files[0];
            const $info = $('#optistate-settings-file-info');
            if (file) {
                if (!file.name.toLowerCase().endsWith('.json') || file.size > 1048576) {
                    showToast(file.size > 1048576 ? __('File too large (Max 1MB).', 'optistate') : __('Select a JSON file.', 'optistate'), 'error');
                    this.value = '';
                    $info.hide();
                    $('#optistate-import-settings-btn').prop('disabled', true);
                    return;
                }
                $('#optistate-settings-file-name').text(file.name);
                $info.fadeIn(300);
                $('#optistate-import-settings-btn').prop('disabled', false);
            } else {
                $info.hide();
                $('#optistate-import-settings-btn').prop('disabled', true);
            }
        });
        $('#optistate-import-settings-btn').on('click', function() {
            showOPTISTATEModal(__('📤 Confirm Import', 'optistate'), __('This will replace all your current settings.\nAre you sure you want to continue?', 'optistate'), function() {
                const $btn = $(this);
                const $status = $('#optistate-import-status');
                const $fileInput = $('#optistate-settings-file-input');
                const file = $fileInput[0].files[0];
                if (!file) {
                    $status.html(`<p class="optistate-error">✗ ${__('Please select a file first', 'optistate')}</p>`);
                    return;
                }
                $btn.prop('disabled', true);
                $status.html(`<span class="spinner is-active os-spinner-no-margin"></span> <span class="os-text-muted">${__('Importing settings...', 'optistate')}</span>`);
                const fd = new FormData();
                fd.append('action', 'optistate_import_settings');
                fd.append('nonce', optistate_Ajax.nonce);
                fd.append('settings_file', file);
                $.ajax({
                    url: optistate_Ajax.ajaxurl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: res => {
                        if (res.success) {
                            let msg = `<p class="optistate-success">✓ ${res.data.message}</p>`;
                            if (res.data.summary) {
                                msg += `<div class="os-import-details">`;
                                msg += `<strong>${__('Import Summary:', 'optistate')}</strong><br>`;
                                msg += `• ${__('Max Backups:', 'optistate')} ${res.data.summary.max_backups}<br>`;
                                msg += `• ${__('Auto Optimize:', 'optistate')} ${sprintf(__('Every %s days', 'optistate'), res.data.summary.auto_optimize_days)}<br>`;
                                msg += `• ${__('Email Notifications:', 'optistate')} ${res.data.summary.email_notifications ? __('Enabled', 'optistate') : __('Disabled', 'optistate')}<br>`;
                                const featCount = typeof res.data.summary.performance_features_count === 'number' ? res.data.summary.performance_features_count.toLocaleString() : res.data.summary.performance_features_count;
                                msg += `• ${__('Performance Features:', 'optistate')} ${featCount} ${__('configured', 'optistate')}<br>`;
                                if (res.data.summary.preset_label) {
                                    msg += `• ${__('Settings Preset:', 'optistate')} ${res.data.summary.preset_label}<br>`;
                                }
                                if (res.data.summary.imported_from_site) {
                                    msg += `• ${__('Exported From:', 'optistate')} ${res.data.summary.imported_from_site}<br>`;
                                }
                                if (res.data.summary.exported_at) {
                                    msg += `• ${__('Exported On:', 'optistate')} ${res.data.summary.exported_at}`;
                                }
                                msg += `</div>`;
                            }
                            $status.html(msg);
                            $fileInput.val('');
                            $('#optistate-settings-file-info').hide();
                            debouncedLoadOptimizationLog();
                            setTimeout(() => {
                                showOPTISTATEModal(__('✓ Import Successful', 'optistate'), __('Settings imported successfully!\nReload the page to see all changes?', 'optistate'), function() {
                                    window.location.reload();
                                });
                            }, 2000);
                        } else {
                            $status.html(`<p class="optistate-error">✗ ${res.data.message || __('Import failed', 'optistate')}</p>`);
                            $btn.prop('disabled', false);
                        }
                    },
                    error: xhr => {
                        const errorMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (xhr.status === 429 ? getRateLimitMessage(false) : __('Network error', 'optistate'));
                        $status.html(`<p class="optistate-error">✗ ${errorMsg}</p>`);
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
    }

    function initUserAccessEvents() {
        $('#optistate-save-user-access-btn').on('click', function() {
            const $btn = $(this);
            const allowedUsers = [];
            $('.optistate-allowed-user:checked').each(function() {
                allowedUsers.push($(this).val());
            });
            apiRequest({
                action: 'optistate_save_user_access',
                data: {
                    allowed_users: allowedUsers
                },
                $btn: $btn,
                loadingText: __('Saving...', 'optistate'),
                errorMsg: __('Error saving settings.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response.success) {
                        showToast(response.data.message, 'success');
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response.data.message || __('Error saving settings.', 'optistate'), 'error');
                    }
                }
            });
        });
        $('#optistate-save-login-btn').on('click', function() {
            const $btn = $(this);
            apiRequest({
                action: 'optistate_save_login_protection',
                data: {
                    enabled: $('#login_protect_enabled').is(':checked'),
                    max_attempts: $('#login_protect_max_attempts').val(),
                    duration: $('#login_protect_block_duration').val(),
                    cloudflare: $('#login_cloudflare_enabled').is(':checked'),
                    captcha_enabled: $('#login_captcha_enabled').is(':checked')
                },
                $btn: $btn,
                loadingText: __('Saving...', 'optistate'),
                errorMsg: __('Error saving settings.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response.success) {
                        var msg = response.data.message;
                        if (response.data.reload_needed) {
                            msg = msg + ' ' + __('<br>Please reload the page to activate protection statistics.', 'optistate');
                        }
                        showToast(msg, 'success');
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response.data.message || __('Error saving settings.', 'optistate'), 'error');
                    }
                }
            });
        });
    }

    function initSecurityEvents() {
        const updateSecurityUI = (isChecked) => {
            const $container = $('#optistate-js-notices');
            $container.empty();
            if (isChecked) {
                $container.html(` <div class="notice notice-error optistate-security-warning-banner os-border-6"> <p class="os-font-14-p5"> <strong>⚠️ SECURITY WARNING:</strong> ${__('Database Restore Security Checks are currently <strong>DISABLED</strong>.', 'optistate')} <button class="button os-ml-10" type="button" id="optistate-quick-enable-security"> ${__('Re-enable Security', 'optistate')} </button> </p> </div> `);
            }
        };
        $('#optistate-save-ip-blocker-btn').on('click', function() {
            const $btn = $(this);
            apiRequest({
                action: 'optistate_save_ip_blocker',
                data: {
                    enabled: $('#ip_blocker_enabled').is(':checked'),
                    ip_list: $('#optistate_ip_block_list').val(),
                    ip_whitelist: $('#optistate_ip_whitelist').val()
                },
                $btn: $btn,
                loadingText: __('Saving...', 'optistate'),
                errorMsg: __('Error saving settings.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response.success) {
                        var msg = response.data.message;
                        if (response.data.reload_needed) {
                            msg = msg + ' ' + __('<br>Please reload the page to activate protection statistics.', 'optistate');
                        }
                        showToast(msg, 'success');
                        debouncedLoadOptimizationLog();
                    } else {
                        showToast(response.data.message || __('Error saving settings.', 'optistate'), 'error');
                    }
                }
            });
        });
        $('#disable_restore_security').on('change', function() {
            const $checkbox = $(this);
            const isChecked = $checkbox.is(':checked');
            $checkbox.prop('disabled', true);
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_save_auto_settings',
                nonce: optistate_Ajax.nonce,
                auto_optimize_days: parseInt($autoOptimizeDays.val(), 10) || 0,
                auto_optimize_time: $autoOptimizeTime.val(),
                email_notifications: $emailNotifications.is(':checked') ? 1 : 0,
                auto_backup_only: $autoBackupOnly.is(':checked') ? 1 : 0,
                max_backups: parseInt($maxBackupsSetting.val(), 10) || 3,
                disable_restore_security: isChecked ? 1 : 0
            }).done(res => {
                $checkbox.prop('disabled', false);
                if (res.success) {
                    updateSecurityUI(isChecked);
                    showToast(isChecked ? __('⚠️ Security checks disabled. Please restore carefully.', 'optistate') : __('✅ Security checks re-enabled.', 'optistate'), isChecked ? 'warning' : 'success');
                    debouncedLoadOptimizationLog();
                } else {
                    showToast(__('Failed to save security setting.', 'optistate'), 'error');
                    $checkbox.prop('checked', !isChecked);
                }
            }).fail((xhr) => {
                $checkbox.prop('disabled', false).prop('checked', !isChecked);
                showToast(xhr.status === 429 ? getRateLimitMessage(true) : (xhr.responseJSON?.data?.message || __('Network error.', 'optistate')), xhr.status === 429 ? 'warning' : 'error');
            });
        });
        $body.on('click', '#optistate-quick-enable-security', () => $('#disable_restore_security').prop('checked', false).trigger('change'));
        if ($('#disable_restore_security').is(':checked')) updateSecurityUI(true);
        $body.on('click', '.optistate-integrity-info', function() {
            const status = $(this).data('status');
            const isVerified = status === 'verified';
            const title = isVerified ? `🛡️ ${__('File Integrity Verified', 'optistate')}` : `⚠️ ${__('File Integrity Check Failed', 'optistate')}`;
            const message = isVerified ? `<span class="os-mb-15"><strong>${__('Great news!', 'optistate')}</strong> ${__('This backup file is healthy and safe to use.', 'optistate')}</span><p>${__('A unique digital fingerprint confirms that the backup has not been altered since it was created.', 'optistate')}</p><div class="optistate-success os-mt-15">✅ ${__('No corruption detected. Safe to restore.', 'optistate')}</div>` : `<span class="os-mb-15"><strong>${__('Critical Warning:', 'optistate')}</strong> ${__('This backup file appears to be damaged or corrupted.', 'optistate')}</span><p>${__('The digital fingerprint does not match.', 'optistate')}</p><p><strong>${__('Common Causes:', 'optistate')}</strong></p><ul class="os-list-disc-mb"><li>${__('Interrupted Backup Process', 'optistate')}</li><li>${__('Disk Write Error', 'optistate')}</li><li>${__('Manual File Modification', 'optistate')}</li></ul><div class="notice notice-error inline os-integrity-error"><p class="os-m-0">❌ <strong>DO NOT RESTORE:</strong> ${__('Using this file will likely crash your site.', 'optistate')}</p></div>`;
            showOPTISTATEModal(title, message, null, !isVerified);
            setTimeout(() => $('.optistate-modal-confirm').text(__('OK, Understood', 'optistate')), 50);
        });
    }

function initSearchReplaceEvents() {
    const SR_POLL_INTERVAL = 750;

    const renderSRWarnings = (data) => {
        const warnings = data.warnings || [];
        if (warnings.length === 0) {
            return '';
        }
        const total = typeof data.total_errors === 'number' ? data.total_errors : warnings.length;
        let html = '<div class="notice notice-warning inline os-mt-15"><p><strong>⚠️ ' +
            __('Some rows or tables were not processed:', 'optistate') +
            '</strong></p><ul style="margin-top:0; list-style:disc; padding-left:20px;">';
        warnings.forEach(warning => {
            html += '<li>' + esc_html(String(warning)) + '</li>';
        });
        html += '</ul>';
        if (total > warnings.length) {
            html += '<p><em>' + sprintf(
                __('%s further issue(s) were recorded; see the error log for the full list.', 'optistate'),
                (total - warnings.length).toLocaleString()
            ) + '</em></p>';
        }
        html += '</div>';
        return html;
    };

    const processSRChunk = (action, params, $btn, $loading, $results, $statusText) => {
        $.post(optistate_Ajax.ajaxurl, {
            ...params,
            action: action,
            nonce: optistate_Ajax.nonce
        }).done(res => {
            if (res.success) {
                if (res.data.status === 'running') {
                    if ($statusText.length && res.data.message) $statusText.text(res.data.message);
                    params.reset = false;
                    if (res.data.lock_token) params.lock_token = res.data.lock_token;
                    setTimeout(() => processSRChunk(action, params, $btn, $loading, $results, $statusText), SR_POLL_INTERVAL);
                } else if (action === 'optistate_search_replace_dry_run') {
                    const data = res.data.data || res.data;
                    renderDryRunResults(data, $results);
                    $loading.hide();
                    $btn.prop('disabled', false);
                    const hasMatches = data.total_matches > 0;
                    const hasReplace = $('#optistate-sr-replace').val().trim().length > 0;
                    $('#optistate-sr-execute').prop('disabled', !(hasMatches && hasReplace));
                } else {
                    showToast(res.data.message, 'success');
                    $results.html(
                        `<div class="optistate-success">✅ ${esc_html(res.data.message)}</div>` +
                        renderSRWarnings(res.data)
                    ).slideDown(300);
                    if (typeof loadCacheStats === 'function') loadCacheStats();
                    debouncedLoadOptimizationLog();
                    $loading.hide();
                    $btn.prop('disabled', false);
                    $('#optistate-sr-dry-run').prop('disabled', false);
                }
            } else {
                showToast(res.data.message || __('Operation failed.', 'optistate'), 'error');
                $loading.hide();
                $btn.prop('disabled', false);
                if (action.includes('execute')) $('#optistate-sr-dry-run').prop('disabled', false);
            }
        }).fail(xhr => {
            showToast(
                xhr.status === 429
                    ? (xhr.responseJSON?.data?.message || getRateLimitMessage(false))
                    : (xhr.responseJSON?.data?.message || __('Network error.', 'optistate')),
                xhr.status === 429 ? 'warning' : 'error'
            );
            $loading.hide();
            $btn.prop('disabled', false);
            $('#optistate-sr-dry-run').prop('disabled', false);
        });
    };

    const renderDryRunResults = (data, $results) => {
        let mainHtml = '';
        if (data.total_matches === 0) {
            mainHtml = `<div class="notice notice-info inline"><p>${__('✘ No matches found for this search term.', 'optistate')}</p></div>`;
        } else {
            let summary = '';
            if (data.unique_rows !== undefined && data.unique_rows > 0) {
                summary = sprintf(__('✔ Found <strong>%s</strong> occurrences inside <strong>%s</strong> unique rows across <strong>%s</strong> tables.', 'optistate'), data.total_matches.toLocaleString(), data.unique_rows.toLocaleString(), data.tables_affected.toLocaleString());
                if (data.total_matches > data.unique_rows) {
                    summary += ' ' + __('Multiple occurrences found in the same row.', 'optistate');
                }
                if (data.has_serialized_data) {
                    summary += ' <br><small>⚠️ ' + __('Serialized data detected. The final replacement count may be slightly lower to prevent data corruption.', 'optistate') + '</small>';
                }
            } else {
                summary = sprintf(__('Found %s matches across %s tables.', 'optistate'), data.total_matches.toLocaleString(), data.tables_affected.toLocaleString());
            }
            mainHtml = `<div class="optistate-success os-mb-15"><strong>${summary}</strong></div>`;
        }

        let cappedHtml = '';
        if (data.counts_capped && data.counts_capped_note) {
            cappedHtml = '<div class="notice notice-warning inline"><p>⚠️ ' + esc_html(data.counts_capped_note) + '</p></div>';
        }

        let skippedHtml = '';
        const skippedNonTrans = data.skipped_non_transactional || [];
        const skippedComp = data.skipped_composite || [];
        if (skippedNonTrans.length > 0 || skippedComp.length > 0) {
            skippedHtml += '<div class="notice notice-info inline"><p><strong>𝒊 ' + __('Some tables were not scanned:', 'optistate') + '</strong></p><ul style="margin-top:0; list-style:disc; padding-left:20px;">';
            if (skippedNonTrans.length > 0) {
                skippedHtml += '<li>' + sprintf(__('%s table(s) with non‑transactional storage engines: %s', 'optistate'), skippedNonTrans.length, skippedNonTrans.map(t => '<code>' + esc_html(t) + '</code>').join(', ')) + '</li>';
            }
            if (skippedComp.length > 0) {
                skippedHtml += '<li>' + sprintf(__('%s table(s) with composite primary keys: %s', 'optistate'), skippedComp.length, skippedComp.map(t => '<code>' + esc_html(t) + '</code>').join(', ')) + '</li>';
            }
            skippedHtml += '</ul><p><em>' + __('These tables will be excluded from the search & replace operation to ensure data integrity.', 'optistate') + '</em></p></div>';
        }

        let previewHtml = '';
        if (data.total_matches > 0 && data.preview?.length > 0) {
            previewHtml += `<div class="os-search-container"><table class="widefat striped os-border-none"><thead><tr><th>${__('Table', 'optistate')}</th><th>${__('Column', 'optistate')}</th><th>${__('ID', 'optistate')}</th><th>${__('Content Preview', 'optistate')}</th></tr></thead><tbody>`;
            data.preview.forEach(item => {
                previewHtml += `<tr><td>${esc_html(item.table)}</td><td>${esc_html(item.column)}</td><td>${esc_html(item.id)}</td><td class="os-font-12-mono">${item.content}</td></tr>`;
            });
            const occurrencesShown = data.preview_occurrences !== undefined ? data.preview_occurrences : (data.preview?.length || 0);
            if (data.total_matches > occurrencesShown) {
                previewHtml += `<tr><td colspan="4" class="os-text-center"><em>${sprintf(__('%s more matches...', 'optistate'), (data.total_matches - occurrencesShown).toLocaleString())}</em></td></tr>`;
            }
            previewHtml += '</tbody></table></div>';
        } else if (data.total_matches > 0) {
            previewHtml = `<p class="os-text-center">${__('Match count exceeds preview limit. No preview available.', 'optistate')}</p>`;
        }

        const fullHtml = mainHtml + cappedHtml + skippedHtml + previewHtml;
        $results.html(fullHtml).slideDown(300);
    };

    $('#optistate-sr-search').on('input', function() {
        const searchVal = $(this).val().trim();
        const $dryRunBtn = $('#optistate-sr-dry-run');
        const $executeBtn = $('#optistate-sr-execute');
        $dryRunBtn.prop('disabled', searchVal === '');
        if (searchVal === '') {
            $executeBtn.prop('disabled', true);
        }
    });
    $('#optistate-sr-search').trigger('input');

    $('#optistate-sr-dry-run').on('click', function() {
        const search = $('#optistate-sr-search').val();
        if (!search) return showToast(__('Please enter a search term.', 'optistate'), 'error');
        const $btn = $(this);
        const $loading = $('#optistate-sr-loading');
        const $results = $('#optistate-sr-results');
        $btn.prop('disabled', true);
        $('#optistate-sr-execute').prop('disabled', true);
        $loading.show().find('.sr-status-text').text(__('Initializing scan...', 'optistate'));
        $loading.find('.spinner').addClass('os-spinner-inline-margin');
        $results.slideUp(200).empty();
        processSRChunk('optistate_search_replace_dry_run', {
            search,
            tables: $('#optistate-sr-tables').val(),
            case_sensitive: $('#optistate-sr-case-sensitive').is(':checked') ? 1 : 0,
            partial_match: $('#optistate-sr-partial-match').is(':checked') ? 1 : 0,
            reset: true
        }, $btn, $loading, $results, $loading.find('.sr-status-text'));
    });

    $('#optistate-sr-execute').on('click', function() {
        const search = $('#optistate-sr-search').val();
        const replace = $('#optistate-sr-replace').val();
        if (!search) return;
        const $btn = $(this);
        showOPTISTATEModal(`↳↰ ${__('Confirm Search & Replace', 'optistate')}`, `⚠️ <strong>${__('CRITICAL WARNING: Database Modification', 'optistate')}</strong><br><br>${sprintf(__('You are about to replace <code>%s</code> with <code>%s</code>.', 'optistate'), esc_html(search), esc_html(replace))}<br><br>${__('• Please create a backup first.', 'optistate')}<br>${__('• This is irreversible.', 'optistate')}<br><br>${__('Are you absolutely sure?', 'optistate')}`, function() {
            $btn.prop('disabled', true);
            $('#optistate-sr-dry-run').prop('disabled', true);
            const $loading = $('#optistate-sr-loading').show();
            const $statusText = $loading.find('.sr-status-text');
            $statusText.text(__('Initializing replacement...', 'optistate'));
            $loading.find('.spinner').addClass('os-spinner-inline-margin');
            processSRChunk('optistate_search_replace_execute', {
                search,
                replace,
                tables: $('#optistate-sr-tables').val(),
                case_sensitive: $('#optistate-sr-case-sensitive').is(':checked') ? 1 : 0,
                partial_match: $('#optistate-sr-partial-match').is(':checked') ? 1 : 0,
                reset: true
            }, $btn, $loading, $('#optistate-sr-results'), $statusText);
        }, true);
    });
}

    function initTabs() {
        const activeTab = localStorage.getItem('optistate_active_tab') || '#tab-backups';
        $('.optistate-tab-content').hide();
        $(activeTab).show();
        $('.nav-tab-wrapper a').removeClass('nav-tab-active').filter(`[href="${activeTab}"]`).addClass('nav-tab-active');
        $('.nav-tab-wrapper a').on('click', function(e) {
            e.preventDefault();
            const target = $(this).attr('href');
            $('.nav-tab-wrapper a').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.optistate-tab-content').hide();
            $(target).show();
            localStorage.setItem('optistate_active_tab', target);
        });
    }

    function initPageSpeedEvents() {
        $('#toggle-api-key-visibility').on('click', function() {
            const $input = $('#optistate_pagespeed_key');
            const $icon = $(this);
            const isPass = $input.attr('type') === 'password';
            $input.attr('type', isPass ? 'text' : 'password');
            $icon.toggleClass('dashicons-visibility dashicons-hidden');
        });
        const loadPageSpeed = (forceRefresh) => {
            const $btn = $('#run-pagespeed-btn');
            if (forceRefresh) {
                isProcessing = true;
                $btn.prop('disabled', true).html(`<span class="spinner is-active os-spinner-inline"></span> ${__('Initializing...', 'optistate')}`);
                $('#optistate-psi-metrics').css('opacity', '0.5');
            }
            const resetBtn = () => {
                isProcessing = false;
                $btn.prop('disabled', false).html(`<span class="dashicons dashicons-performance optst-adt-icn"></span> ${__('Run Audit', 'optistate')}`);
            };
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_run_pagespeed_audit',
                nonce: optistate_Ajax.nonce,
                strategy: $(SELECTORS.psiStrategy).val(),
                test_url: $(SELECTORS.psiCustomUrl).val().trim() || $(SELECTORS.psiTestUrl).val() || '',
                force_refresh: forceRefresh
            }).done(res => {
                if (res.success) {
                    if (res.data.score !== undefined) {
                        updatePageSpeedUI(res.data);
                        if (forceRefresh) showToast(__('Performance audit loaded from cache!', 'optistate'), 'success');
                        resetBtn();
                    } else if (res.data.status === 'processing') {
                        $btn.html(`<span class="spinner is-active os-spinner-inline"></span> ${__('Auditing...', 'optistate')}`);
                        pollPageSpeedStatus(res.data.task_id);
                    } else {
                        showToast(res.data.message || __('Audit failed to start.', 'optistate'), 'error');
                        resetBtn();
                    }
                } else {
                    showToast(res.data.message || __('Audit failed to start.', 'optistate'), 'error');
                    resetBtn();
                }
            }).fail(function(xhr) {
                let errorMsg = __('Connection error.', 'optistate');
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                } else if (xhr.status === 429) {
                    errorMsg = getRateLimitMessage(false);
                }
                showToast(errorMsg, xhr.status === 429 ? 'warning' : 'error');
                resetBtn();
            });
        };
        const loadCachedPageSpeed = () => {
            if ($('#psi-score').length === 0) return;
            $.post(optistate_Ajax.ajaxurl, {
                action: 'optistate_run_pagespeed_audit',
                nonce: optistate_Ajax.nonce,
                cached_only: 'true',
                force_refresh: 'false',
                strategy: $(SELECTORS.psiStrategy).val()
            }).done(function(response) {
                if (response.success && response.data && typeof response.data === 'object' && 'score' in response.data) {
                    updatePageSpeedUI(response.data);
                }
            }).fail(function() {});
        };
        $('#save-pagespeed-key-btn').on('click', function() {
            const key = $('#optistate_pagespeed_key').val().trim();
            const $btn = $(this);
            if (!key) {
                showToast(__('Please enter an API Key before saving.', 'optistate'), 'error');
                return;
            }
            apiRequest({
                action: 'optistate_save_pagespeed_settings',
                data: {
                    api_key: key
                },
                $btn: $btn,
                loadingText: __('Saving...', 'optistate'),
                errorMsg: __('Failed to save settings.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response.success) {
                        showToast(response.data.message, 'success');
                    } else {
                        showToast(response.data.message, 'error');
                    }
                }
            });
        });
        $('#run-pagespeed-btn').on('click', () => loadPageSpeed(true));
        $(SELECTORS.psiCustomUrl).on('input', () => $(SELECTORS.psiCustomUrl).val().trim() && $(SELECTORS.psiTestUrl).val(''));
        $(SELECTORS.psiTestUrl).on('change', () => $(SELECTORS.psiTestUrl).val() && $(SELECTORS.psiCustomUrl).val(''));
        $(`${SELECTORS.psiStrategy}, ${SELECTORS.psiTestUrl}, ${SELECTORS.psiCustomUrl}`).on('change input', () => $('#psi-timestamp').text(__('Changed - click Run Audit', 'optistate')));
        loadCachedPageSpeed();
    }
    const updatePageSpeedUI = (data) => {
        if (!data) return;
        const $testUrl = $('#optistate-test-url');
        const $customUrl = $('#optistate-custom-url');
        if (data.tested_url) {
            if ($testUrl.find(`option[value="${data.tested_url}"]`).length > 0) {
                $testUrl.val(data.tested_url);
                $customUrl.val('');
            } else {
                $testUrl.val('');
                $customUrl.val(data.tested_url);
            }
        }
        const score = parseInt(data.score, 10);
        $('#psi-score').text(score);
        let color = score >= 90 ? '#28a745' : (score >= 60 ? '#ffa400' : '#dc3545');
        let bg = score >= 90 ? '#e8f5e9' : (score >= 60 ? '#fff8e1' : '#fce8e8');
        $('#psi-score-circle').css({
            borderColor: color,
            backgroundColor: bg,
            color: '#333'
        });
        const updateMetric = (id, metric, thresholds) => {
            const val = metric?.value || 0;
            $(`${id}`).text(`→ ${metric?.display || 'N/A'}`).closest('.optistate-targeted-card').css({
                borderLeft: `4px solid ${val <= thresholds.good ? '#28a745' : (val <= thresholds.needsImprovement ? '#ffa400' : '#dc3545')}`,
                paddingLeft: '11px'
            });
        };
        updateMetric('#psi-fcp', data.fcp, {
            good: 1800,
            needsImprovement: 3000
        });
        updateMetric('#psi-lcp', data.lcp, {
            good: 2500,
            needsImprovement: 4000
        });
        updateMetric('#psi-cls', data.cls, {
            good: 0.1,
            needsImprovement: 0.25
        });
        updateMetric('#psi-tbt', data.tbt, {
            good: 200,
            needsImprovement: 600
        });
        updateMetric('#psi-si', data.si, {
            good: 3400,
            needsImprovement: 5800
        });
        updateMetric('#psi-tti', data.tti, {
            good: 3800,
            needsImprovement: 7300
        });
        updateMetric('#psi-ttfb', data.ttfb, {
            good: 600,
            needsImprovement: 1800
        });
        $('#psi-timestamp').text(`${data.timestamp} (${data.strategy})`);
        if (data.tested_url) {
            let urlPath = data.tested_url.replace(/^https?:\/\/[^\/]+/, '');
            if (!urlPath || urlPath === '/') {
                urlPath = '🏠︎/';
            }
            $('#psi-tested-url').text(__('Tested: ', 'optistate') + urlPath);
        }
        $('#optistate-psi-metrics').css({
            opacity: '1',
            pointerEvents: 'auto'
        });
        const $recsList = $('#optistate-psi-recommendations-list');
        if (data.recommendations?.length > 0) {
            const colors = {
                high: '#C13048',
                medium: '#EF8F00',
                low: '#666'
            };
            let html = '';
            data.recommendations.forEach(rec => {
                const urgency = rec.urgency || 'low';
                html += `<div class="optistate-card os-p-15-mb-12" style="border-left: 4px solid ${colors[rec.priority]};"><div class="os-flex-start-gap"><span class="dashicons ${rec.icon} os-psi-icon"></span><div class="os-flex-1"><div class="os-flex-center-gap"><strong class="os-font-14-bold">${rec.title}</strong><span class="os-speed-prior" style="background: ${colors[rec.priority]};">${rec.priority.toUpperCase()}</span></div><p class="os-psi-desc">${rec.description}</p>${rec.tab ? `<a class="nav-tab-link button button-small os-no-decoration" href="${rec.tab}">${__('Performance Settings →', 'optistate')}</a>` : ''}</div></div></div>`;
            });
            $recsList.html(html).parent().show();
            $recsList.find('.nav-tab-link').on('click', function(e) {
                e.preventDefault();
                $(`.nav-tab-wrapper a[href="${$(this).attr('href')}"]`).click();
                window.scrollTo(0, 0);
            });
        } else if (score >= 90) {
            $recsList.html(`<div class="optistate-card os-psi-excellent-card"><span class="dashicons dashicons-yes-alt"></span><h3 class="os-psi-excellent-title">${__('Excellent Performance!', 'optistate')}</h3><p class="os-psi-excellent-desc">${__('Your site is performing well.', 'optistate')}</p></div>`).parent().show();
        } else $recsList.parent().hide();
    };

    function initUserBlockingEvents() {
        $(document).on('click', '.optistate-unblock-user', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const ip = $btn.data('ip');
            showOPTISTATEModal(__('🔓 Confirm Unblock', 'optistate'), sprintf(__('Are you sure you want to unblock %s?', 'optistate'), ip), function() {
                $btn.prop('disabled', true).text(__('Unblocking...', 'optistate'));
                $.post(optistate_Ajax.ajaxurl, {
                    action: 'optistate_unblock_user',
                    nonce: optistate_Ajax.nonce,
                    ip_address: ip
                }).done(res => {
                    if (res.success) {
                        showToast(res.data.message, 'success');
                        debouncedLoadOptimizationLog();
                        $btn.closest('tr').fadeOut(400, function() {
                            $(this).remove();
                            if ($('#optistate-blocked-users-list tbody tr').length === 0) {
                                $('#optistate-blocked-users-list').html('<p class="description os-p-11"><em>' + __('No active blocks found.', 'optistate') + '</em></p>');
                            }
                        });
                    } else {
                        showToast(res.data.message || __('Failed to unblock user.', 'optistate'), 'error');
                        $btn.prop('disabled', false).text(__('Unblock', 'optistate'));
                    }
                }).fail(function(xhr) {
                    let errorMsg = __('An error occurred while unblocking the user.', 'optistate');
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    } else if (xhr.status === 403) {
                        errorMsg = __('Access denied. Please refresh the page and try again.', 'optistate');
                    }
                    showToast(errorMsg, 'error');
                    $btn.prop('disabled', false).text(__('Unblock', 'optistate'));
                });
            });
        });
    }

    function checkRestoreStatusOnLoad() {
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_check_restore_status',
            nonce: optistate_Ajax.nonce
        }).done(function(response) {
            if (response?.success && response.data) {
                const data = response.data;
                if (data.status === 'running') {
                    acquireRestoreLock();
                    let $btn = $(data.button_selector);
                    if (!$btn.length) $btn = $('.restore-backup').first();
                    $(SELECTORS.globalButtons).add($createBackupBtn).prop('disabled', true);
                    if ($btn.length) {
                        optistate_batch_update(function() {
                            $btn.html(`<span class="spinner is-active os-spinner-inline"></span> <strong>${__('RESUMING ....', 'optistate')}</strong>`);
                        });
                        if (data.button_selector === SELECTORS.restoreFileBtn) {
                            $(SELECTORS.fileInfo).show();
                            $(SELECTORS.uploadProgress).show();
                            $(SELECTORS.restoreWrapper).fadeIn(300);
                            $progressFill.css('width', '0%').text(__('RESUMING ....', 'optistate'));
                        }
                    }
                    $restoreRecoveryNotice.hide().removeClass('os-display-none').fadeIn(300);
                    showToast(__('Previous database restore detected. Resuming monitoring...', 'optistate'), 'info');
                    pollRestoreStatus(data.master_restore_key, $btn);
                } else if (data.status === 'completed_recently') {
                    resetRestoreUI();
                    if (!localStorage.getItem('optistate_restore_completed_viewed')) {
                        showToast(data.message || __('Restore completed successfully.', 'optistate'), 'success');
                        localStorage.setItem('optistate_restore_completed_viewed', '1');
                    }
                    if (typeof reloadBackupList === 'function') reloadBackupList();
                } else if (data.status === 'stalled') {
                    showToast(data.message || __('Previous restore stalled.', 'optistate'), 'error');
                    resetRestoreUI();
                } else {
                    $restoreRecoveryNotice.hide();
                    resetRestoreUI();
                }
            }
        });
    }

    function checkBackupStatusOnLoad() {
        if (isProcessing) return;
        $.post(optistate_BackupMgr.ajax_url, {
            action: 'optistate_check_manual_backup_on_load',
            nonce: optistate_BackupMgr.nonce
        }).done(function(response) {
            if (response?.success && response.data) {
                if (response.data.status === 'running' && response.data.transient_key) {
                    $(SELECTORS.globalButtons).prop('disabled', true);
                    if ($createBackupBtn.length) {
                        $createBackupBtn.prop('disabled', true);
                        $backupSpinner.hide();
                        showToast(__('Resuming manual backup monitoring...', 'optistate'), 'info');
                        pollBackupStatus(response.data.transient_key, $createBackupBtn);
                    }
                } else if (response.data.status === 'stalled') {
                    showToast(__('A previous manual backup stalled.', 'optistate'), 'warning');
                }
            }
        });
    }

    function initializeAll() {
        initBackupEvents();
        initFileUploadEvents();
        initSettingsEvents();
        initCleanupEvents();
        initTableAnalysisEvents();
        initIndexAnalysisEvents();
        initIntegrityScanEvents();
        initSettingsImportExportEvents();
        initUserAccessEvents();
        initSecurityEvents();
        initSearchReplaceEvents();
        initTabs();
        initPageSpeedEvents();
        initUserBlockingEvents();
        initLegacyScannerEvents();
        initOneClickExtraItems();
        if (typeof checkRestoreStatusOnLoad === 'function') checkRestoreStatusOnLoad();
        if (typeof checkBackupStatusOnLoad === 'function') checkBackupStatusOnLoad();
        if ($statsContainer.length) loadStats();
        if ($perfFeaturesContainer.length) initPerformanceFeatures();
        if ($healthScoreWrapper.length) initializeHealthScore();
        loadTrashItems();
    }
    let lastCacheRefresh = 0;
    $(document).on('click', '.optistate-cache-updt', function(e) {
        e.preventDefault();
        const now = Date.now();
        if (now - lastCacheRefresh < 3000) {
            showToast(__('Rate limit exceeded. Try again in a moment.', 'optistate'), 'warning');
            return;
        }
        lastCacheRefresh = now;
        const $btn = $(this);
        $btn.prop('disabled', true);
        loadCacheStats(function(response) {
            $btn.prop('disabled', false);
            if (response?.success) {
                showToast(__('Cache statistics refreshed successfully.', 'optistate'), 'success');
            } else {
                showToast(__('Failed to refresh cache statistics.', 'optistate'), 'error');
            }
        });
    });

    function loadTrashItems() {
        const $list = $('#optistate-trash-list');
        $list.html('<div class="optistate-loading">' + __('Loading trash items...', 'optistate') + '</div>');
        return $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_list_trash_items',
            nonce: optistate_Ajax.nonce
        }).done(function(response) {
            if (response.success) {
                renderTrashItems(response.data);
            } else {
                $list.html('<div class="optistate-error">' + response.data.message + '</div>');
            }
        }).fail(function() {
            $list.html('<div class="optistate-error">' + __('Network error loading trash.', 'optistate') + '</div>');
        });
    }

    function renderTrashItems(payload) {
        const data = (payload && !Array.isArray(payload) && typeof payload === 'object') ? payload : {};
        const items = Array.isArray(payload) ? payload : (Array.isArray(data.items) ? data.items : []);
        const total = Number.isFinite(data.total) ? data.total : items.length;
        const $list = $('#optistate-trash-list');
        let $actions = $('#optistate-trash-actions');
        if (!$actions.length) {
            $actions = $('<div id="optistate-trash-actions" style="margin-top:15px;"></div>');
            $list.after($actions);
            $actions.html('<button id="optistate-delete-all-trash-btn" class="button button-small">' + __('🗑 Delete All', 'optistate') + '</button>');
        }
        if (items.length === 0) {
            $list.html('<p class="description">' + __('Trash is empty.', 'optistate') + '</p>');
            $actions.hide();
            return;
        }
        const typeLabels = {
            'folder': __('Folder', 'optistate'),
            'table': __('Database Table', 'optistate'),
            'option': __('Option', 'optistate'),
            'postmeta': __('Post Meta', 'optistate'),
            'commentmeta': __('Comment Meta', 'optistate'),
            'usermeta': __('User Meta', 'optistate'),
            'termmeta': __('Term Meta', 'optistate')
        };
        let html = '<table class="widefat striped"><thead><tr>' + '<th>' + __('Type', 'optistate') + '</th>' + '<th>' + __('Original Path / Name', 'optistate') + '</th>' + '<th>' + __('Size', 'optistate') + '</th>' + '<th>' + __('Deleted', 'optistate') + '</th>' + '<th>' + __('Actions', 'optistate') + '</th>' + '</tr></thead><tbody>';
        items.forEach(function(item) {
            const dateObj = new Date(item.deleted_at * 1000);
            const dateStr = dateObj.toLocaleDateString() + ' ' + dateObj.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });
            const displayPath = item.display_path || item.original_path || item.original_name;
            const typeLabel = typeLabels[item.type] || item.type;
            html += '<tr data-key="' + esc_attr(item.trash_key) + '">' + '<td>' + esc_html(typeLabel) + '</td>' + '<td><code>' + esc_html(displayPath) + '</code></td>' + '<td>' + (item.size ? formatBytes(item.size) : '0 B') + '</td>' + '<td>' + esc_html(dateStr) + '</td>' + '<td>' + '<button class="button button-small optistate-restore-trash-btn" style="margin-right: 5px" data-key="' + esc_attr(item.trash_key) + '" data-type="' + esc_attr(item.type) + '">' + __('Restore', 'optistate') + '</button> ' + '<button class="button button-small optistate-permanent-delete-trash-btn" data-key="' + esc_attr(item.trash_key) + '" data-type="' + esc_attr(item.type) + '">' + __('Delete Permanently', 'optistate') + '</button>' + '</td>' + '</tr>';
        });
        html += '</tbody></table>';
        if (total > items.length) {
            html += '<p class="description">' + sprintf(__('Showing the %s most recent of %s items.', 'optistate'), items.length.toLocaleString(), total.toLocaleString()) + '</p>';
        }
        $list.html(html);
        $actions.show();
        const $btn = $actions.find('#optistate-delete-all-trash-btn');
        $btn.text(sprintf(__('🗑 Delete All (%s items)', 'optistate'), total.toLocaleString()));
        $btn.data('count', total);
    }

    function restoreTrashItem(key) {
        const $btn = $('.optistate-restore-trash-btn[data-key="' + key + '"]');
        if (!$btn.length) return;
        $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline"></span>');
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_restore_trash_item',
            nonce: optistate_Ajax.nonce,
            key: key
        }).done(function(response) {
            if (response.success) {
                showToast(response.data.message, 'success');
                loadTrashItems();
                debouncedLoadOptimizationLog();
            } else {
                showToast(response.data.message, 'error');
                $btn.prop('disabled', false).text(__('Restore', 'optistate'));
            }
        }).fail(function(xhr) {
            let errorMsg = __('Network error.', 'optistate');
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg = xhr.responseJSON.data.message;
            } else if (xhr.status === 403) {
                errorMsg = __('Access denied. Please refresh the page and try again.', 'optistate');
            } else if (xhr.status === 429) {
                errorMsg = getRateLimitMessage(false);
            }
            showToast(errorMsg, xhr.status === 429 ? 'warning' : 'error');
            $btn.prop('disabled', false).text(__('Restore', 'optistate'));
        });
    }

    function permanentlyDeleteTrashItem(key) {
        const $btn = $('.optistate-permanent-delete-trash-btn[data-key="' + key + '"]');
        if (!$btn.length) return;
        $btn.prop('disabled', true).html('<span class="spinner is-active os-spinner-inline"></span>');
        $.post(optistate_Ajax.ajaxurl, {
            action: 'optistate_permanently_delete_trash_item',
            nonce: optistate_Ajax.nonce,
            key: key
        }).done(function(response) {
            if (response.success) {
                showToast(response.data.message, 'success');
                loadTrashItems();
                debouncedLoadOptimizationLog();
            } else {
                showToast(response.data.message, 'error');
                $btn.prop('disabled', false).text(__('Delete Permanently', 'optistate'));
            }
        }).fail(function(xhr) {
            let errorMsg = __('Network error.', 'optistate');
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg = xhr.responseJSON.data.message;
            } else if (xhr.status === 403) {
                errorMsg = __('Access denied. Please refresh the page and try again.', 'optistate');
            } else if (xhr.status === 429) {
                errorMsg = getRateLimitMessage(false);
            }
            showToast(errorMsg, xhr.status === 429 ? 'warning' : 'error');
            $btn.prop('disabled', false).text(__('Delete Permanently', 'optistate'));
        });
    }

    function registerFileDownload(config) {
        config.isLink = config.isLink !== undefined ? config.isLink : true;
        config.logActivity = config.logActivity !== undefined ? config.logActivity : true;
        config.loadingText = config.loadingText || __('⏳ Downloading...', 'optistate');
        $(document).on('click', config.selector, function(e) {
            e.preventDefault();
            const $el = $(this);
            if (config.isLink) {
                if ($el.hasClass('downloading')) return;
                $el.addClass('downloading');
            } else {
                if ($el.prop('disabled')) return;
                $el.prop('disabled', true);
            }
            const originalContent = config.isLink ? $el.text() : $el.html();
            if (config.isLink) {
                $el.text(config.loadingText);
            } else {
                $el.html(config.loadingText);
            }
            fetch(optistate_Ajax.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: config.action,
                    nonce: optistate_Ajax.nonce
                })
            }).then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        let errorMsg = text;
                        try {
                            const json = JSON.parse(text);
                            if (json && json.data && json.data.message) {
                                errorMsg = json.data.message;
                            }
                        } catch (e) {}
                        if (!errorMsg) {
                            errorMsg = response.status === 429 ? getRateLimitMessage(false) : __('Server error', 'optistate');
                        }
                        const err = new Error(errorMsg);
                        err.status = response.status;
                        throw err;
                    });
                }
                return response.blob().then(blob => ({
                    blob,
                    contentDisposition: response.headers.get('Content-Disposition')
                }));
            }).then(({
                blob,
                contentDisposition
            }) => {
                let filename = config.defaultFilename;
                if (contentDisposition) {
                    const match = contentDisposition.match(/filename="(.+)"/);
                    if (match) filename = match[1];
                }
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                showToast(config.successMessage, 'success');
                if (config.logActivity) {
                    debouncedLoadOptimizationLog();
                }
            }).catch(error => {
                showToast(error.message || __('Failed to download file.', 'optistate'), error.status === 429 ? 'warning' : 'error');
            }).finally(() => {
                if (config.isLink) {
                    $el.removeClass('downloading').text(originalContent);
                } else {
                    $el.prop('disabled', false).html(originalContent);
                }
            });
        });
    }
    $(document).on('click', '.optistate-restore-trash-btn', function() {
        const key = $(this).data('key');
        const type = $(this).data('type');
        let title = __('📂 Restore Folder', 'optistate');
        let message = __('Restore this folder to its original location?', 'optistate');
        if (type === 'table') {
            title = __('🗄️️ Restore Table', 'optistate');
            message = __('Restore this table to the database?', 'optistate');
        } else if (type === 'option') {
            title = __('⚙️ Restore Option', 'optistate');
            message = __('Restore this option to the database?', 'optistate');
        } else if (['postmeta', 'commentmeta', 'usermeta', 'termmeta'].includes(type)) {
            title = __('📋 Restore Meta Data', 'optistate');
            message = __('Restore this meta data to the database?', 'optistate');
        }
        if (key) {
            showOPTISTATEModal(title, message, function() {
                restoreTrashItem(key);
            });
        }
    });
    $(document).on('click', '.optistate-permanent-delete-trash-btn', function() {
        const key = $(this).data('key');
        const type = $(this).data('type');
        let title = __('📂️ Permanently Delete Folder', 'optistate');
        if (type === 'table') {
            title = __('🗄️ Permanently Delete Table', 'optistate');
        } else if (type === 'option') {
            title = __('⚙️️ Permanently Delete Option', 'optistate');
        } else if (['postmeta', 'commentmeta', 'usermeta', 'termmeta'].includes(type)) {
            title = __('📋️ Permanently Delete Meta Data', 'optistate');
        }
        if (key) {
            showOPTISTATEModal(title, __('This action cannot be undone. Are you sure?', 'optistate'), function() {
                permanentlyDeleteTrashItem(key);
            });
        }
    });
    $(document).on('click', '#optistate-save-two-factor-btn', function() {
        var $btn = $(this);
        apiRequest({
            action: 'optistate_save_two_factor_setting',
            data: {
                enabled: $('#optistate_enable_two_factor').is(':checked') ? 1 : 0
            },
            $btn: $btn,
            loadingText: optistate_Ajax.saving_text || __('Saving...', 'optistate'),
            errorMsg: __('Error saving settings.', 'optistate'),
            isSaveAction: true,
            onSuccess: function(response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    debouncedLoadOptimizationLog();
                } else {
                    showToast(response.data.message || __('Error saving settings.', 'optistate'), 'error');
                }
            }
        });
    });
    $(document).on('click', '.db-backup-tables', function(e) {
        e.preventDefault();
        var $badge = $(this);
        var tables = $badge.data('tables');
        var filename = $badge.data('filename') || '';
        var $row = $badge.closest('tr');
        var compressedBytes = $row.data('bytes') || 0;
        var uncompressedBytes = $row.data('uncompressed-bytes') || 0;
        var compressedSize = compressedBytes ? formatBytes(compressedBytes) : __('N/A', 'optistate');
        var uncompressedSize = uncompressedBytes ? formatBytes(uncompressedBytes) : __('N/A', 'optistate');
        var countText = $badge.text().match(/\d+/);
        var count = countText ? parseInt(countText[0], 10) : 0;
        if (tables && Array.isArray(tables) && tables.length > 0) {
            var listHtml = '<div class="optistate-tb-mod">';
            if (filename) {
                listHtml += '<div class="optistate-tb-lst">' + escapeHTML(filename) + '</div>';
            }
            listHtml += '<div style="margin: 0 0 8px; font-size: 0.95em;">' + __('Compressed size: ', 'optistate') + compressedSize + ' &nbsp;|&nbsp; ' + __('Uncompressed size: ', 'optistate') + uncompressedSize + '</div>';
            listHtml += '<ul class="optistate-tblist-ul">';
            tables.forEach(function(table) {
                listHtml += '<li><code class="optistate-cd-frmt">' + escapeHTML(table) + '</code></li>';
            });
            listHtml += '</ul></div>';
            showOPTISTATEModal(sprintf(__('𓊂 Table List (%s)', 'optistate'), count.toLocaleString()), listHtml, null, false);
        } else {
            showOPTISTATEModal(__('𓊂 Table List (N/A)', 'optistate'), __('No table information available for this backup.', 'optistate'), null, false);
        }
    });

    function initOneClickExtraItems() {
        var $container = $('#optistate-one-click-extra-items-container');
        var $status = $('#optistate-one-click-extra-status');
        var config = window.optistate_OneClickConfig || {};
        var allItems = config.all_items || {};
        var defaultKeys = config.default_keys || [];
        var extraKeys = config.extra_items || [];
        var oneClickBackup = config.one_click_backup || false;
        if (Object.keys(allItems).length === 0) {
            $container.html('<p>' + __('No cleanup items available.', 'optistate') + '</p>');
            return;
        }
        var defaultSet = new Set(defaultKeys);
        var orderedKeys = [];
        defaultKeys.forEach(function(key) {
            if (allItems.hasOwnProperty(key)) {
                orderedKeys.push(key);
            }
        });
        Object.keys(allItems).forEach(function(key) {
            if (!defaultSet.has(key)) {
                orderedKeys.push(key);
            }
        });
        var html = '<div class="optistate-tb-mod">';
        html += '<div class="optistate-chkbx-grd">';
        orderedKeys.forEach(function(key) {
            var item = allItems[key];
            var isDefault = defaultSet.has(key);
            var isChecked = isDefault || extraKeys.indexOf(key) !== -1;
            var disabledAttr = isDefault ? 'disabled="disabled"' : '';
            var label = item.label || key;
            html += '<label class="optistate-chkbx-lbl">';
            html += '<input type="checkbox" name="oneclick_extra[]" value="' + key + '" ' + (isChecked ? 'checked="checked"' : '') + ' ' + disabledAttr + '> ';
            html += label;
            if (isDefault) {
                html += ' <span class="os-color-muted" style="font-size:0.85em;">✓</span>';
            }
            html += '</label>';
        });
        html += '</div>';
        html += '<div style="margin-top: 22px;">';
        html += __('💾 Create an automated database backup before running One-Click Optimization manually in the Dashboard tab. This will appear in your Backups tab.', 'optistate');
        html += '</div>';
        var backupChecked = oneClickBackup ? 'checked="checked"' : '';
        html += '<div class="optistate-chkbx-lbl" style="margin:12px 0 20px;">';
        html += '<label><strong>';
        html += '<input type="checkbox" id="one_click_backup" name="one_click_backup" value="1" ' + backupChecked + '> ';
        html += __('↩ Activate preventive backup', 'optistate');
        html += '</label></strong>';
        html += '</div>';
        html += '</div>';
        $container.html(html);
        $('#optistate-save-one-click-extra-btn').on('click', function() {
            var $btn = $(this);
            var selected = [];
            $container.find('input[type="checkbox"]:checked:not(:disabled)').each(function() {
                if ($(this).attr('name') === 'oneclick_extra[]') {
                    selected.push($(this).val());
                }
            });
            var backupEnabled = $('#one_click_backup').is(':checked') ? 1 : 0;
            apiRequest({
                action: 'optistate_save_one_click_extra_items',
                data: {
                    items: selected,
                    one_click_backup: backupEnabled
                },
                $btn: $btn,
                loadingText: __('Saving...', 'optistate'),
                errorMsg: __('Network error.', 'optistate'),
                isSaveAction: true,
                onSuccess: function(response) {
                    if (response.success) {
                        showToast(response.data.message, 'success');
                        debouncedLoadOptimizationLog();
                        config.extra_items = selected;
                        config.one_click_backup = backupEnabled === 1;
                    } else {
                        showToast(response.data.message, 'error');
                    }
                }
            });
        });
    }
    $('#optistate-preset-select').on('change', function() {
        var selected = $(this).val();
        var $desc = $('#optistate-preset-description');
        if (selected && window.optistate_PresetData && window.optistate_PresetData.presets) {
            var presets = window.optistate_PresetData.presets;
            if (presets[selected]) {
                $desc.html('<em>' + presets[selected].description + '</em>').show();
            } else {
                $desc.html('').hide();
            }
        } else {
            $desc.html('').hide();
        }
        $('#optistate-apply-preset-btn').prop('disabled', !selected);
    });
    $('#optistate-apply-preset-btn').prop('disabled', !$('#optistate-preset-select').val());
    $('#optistate-apply-preset-btn').on('click', function() {
        var $btn = $(this);
        var preset = $('#optistate-preset-select').val();
        if (!preset) {
            return;
        }
        var presetLabel = $('#optistate-preset-select option:selected').text();
        var message = sprintf(__('Are you sure you want to apply the preset "%s"?', 'optistate'), presetLabel) + '<br><br>' + __('This will overwrite your current settings. You can always change them later.', 'optistate');
        showOPTISTATEModal(__('🎛️ Apply Preset', 'optistate'), message, function() {
            apiRequest({
                action: 'optistate_apply_preset',
                data: {
                    preset: preset
                },
                $btn: $btn,
                loadingText: __('Applying...', 'optistate'),
                errorMsg: __('Failed to apply preset.', 'optistate'),
                onSuccess: function(response) {
                    if (response.success) {
                        showToast(response.data.message, 'success');
                        if (response.data.reload) {
                            setTimeout(function() {
                                window.location.reload();
                            }, 2000);
                        }
                    } else {
                        showToast(response.data.message || __('Failed to apply preset.', 'optistate'), 'error');
                    }
                }
            });
        });
    });
    $('#optistate-preset-select').trigger('change');
    $(window).on('beforeunload', function() {
        clearTimeout(preloadInterval);
        clearTimeout(preloadResumeDebounceTimer);
    });
    registerFileDownload({
        selector: '.optistate-download-htaccess',
        action: 'optistate_download_htaccess',
        successMessage: __('.htaccess file downloaded successfully.', 'optistate'),
        defaultFilename: '.htaccess'
    });
    registerFileDownload({
        selector: '.optistate-download-error-log',
        action: 'optistate_download_error_log',
        successMessage: __('Error log downloaded successfully.', 'optistate'),
        defaultFilename: 'debug.log'
    });
    registerFileDownload({
        selector: '#optistate-download-log-btn',
        action: 'optistate_download_activity_log',
        successMessage: __('Activity log downloaded successfully.', 'optistate'),
        defaultFilename: 'optistate-activity-log.json'
    });
    initializeAll();
});