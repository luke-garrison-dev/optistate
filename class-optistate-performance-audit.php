<?php if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

class OPTISTATE_Performance_Audit {

	private OPTISTATE $main_plugin;

	public function __construct( OPTISTATE $main_plugin ) {
		$this->main_plugin = $main_plugin;
		add_action( 'wp_ajax_optistate_run_pagespeed_audit', [ $this, 'ajax_run_pagespeed_audit' ] );
		add_action( 'wp_ajax_optistate_save_pagespeed_settings', [ $this, 'ajax_save_pagespeed_settings' ] );
		add_action( 'wp_ajax_optistate_check_pagespeed_status', [ $this, 'ajax_check_pagespeed_status' ] );
		add_action( 'wp_ajax_optistate_run_pagespeed_worker_async', [ $this, 'ajax_run_pagespeed_worker_async' ] );
	}

	public function run_pagespeed_worker( string $task_id ): void {
		wp_raise_memory_limit( 'admin' );
		OPTISTATE_Utils::safe_set_time_limit( 120 );

		$process_store = $this->main_plugin->process_store;
		$task          = $process_store->get( $task_id );

		if ( ! $task || $task['status'] !== 'pending' ) {
			return;
		}
		if ( isset( $task['started'] ) && ( time() - (int) $task['started'] ) > 300 ) {
			$task['status']  = 'error';
			$task['message'] = __(
				'Audit task expired before it could be processed. Please try again.',
				'optistate'
			);
			$process_store->set( $task_id, $task, 60 );
			return;
		}

		$task['status'] = 'processing';
		$process_store->set( $task_id, $task, 600 );

		try {
			$test_url = $task['url'];
			$strategy = $task['strategy'];

			$settings = $this->main_plugin->settings_manager->get_persistent_settings();
			$api_key  = isset( $settings['pagespeed_api_key'] )
				? trim( $settings['pagespeed_api_key'] )
				: '';

			$endpoint   = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
			$query_args = [
				'url'      => $test_url,
				'strategy' => $strategy,
				'category' => [ 'performance' ],
			];
			$endpoint = add_query_arg( $query_args, $endpoint );

			$request_headers = [ 'Accept' => 'application/json' ];
			if ( ! empty( $api_key ) ) {
				$request_headers['X-Goog-Api-Key'] = $api_key;
			}
			$response = wp_remote_get( $endpoint, [
				'timeout' => 45,
				'headers' => $request_headers,
			] );

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException(
					__( 'API Connection Failed: ', 'optistate' ) .
					$response->get_error_message()
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				throw new RuntimeException(
					__( 'Invalid API response (non-JSON body).', 'optistate' )
				);
			}

			if ( $code !== 200 ) {
				$error_msg = isset( $data['error']['message'] )
					? $data['error']['message']
					: __( 'Unknown API Error', 'optistate' );
				throw new RuntimeException(
					__( 'PageSpeed Error: ', 'optistate' ) . $error_msg
				);
			}

			$lighthouse = $data['lighthouseResult'] ?? [];
			$audits     = $lighthouse['audits'] ?? [];

			$perf_settings   = $this->main_plugin->performance_manager->get_performance_settings();
			$features_status = [
				'server_caching'   => ! empty( $perf_settings['server_caching']['enabled'] ),
				'browser_caching'  => ! empty( $perf_settings['browser_caching'] ),
				'lazy_load'        => ! empty( $perf_settings['lazy_load'] ),
				'db_query_caching' => ! empty( $perf_settings['db_query_caching']['enabled'] ),
				'font_optimization' => ! empty( $perf_settings['font_optimization']['enabled'] ),
				'font_display_swap' =>
					! empty( $perf_settings['font_optimization']['enabled'] ) &&
					! empty( $perf_settings['font_optimization']['display_swap'] ),
				'font_preconnect'  =>
					! empty( $perf_settings['font_optimization']['enabled'] ) &&
					! empty( $perf_settings['font_optimization']['preconnect'] ),
				'font_async'       =>
					! empty( $perf_settings['font_optimization']['enabled'] ) &&
					! empty( $perf_settings['font_optimization']['async_google_fonts'] ),
				'heartbeat_api'    =>
					isset( $perf_settings['heartbeat_api'] ) &&
					$perf_settings['heartbeat_api'] !== 'default',
				'bad_bot_blocker'  => ! empty( $perf_settings['bad_bot_blocker']['enabled'] ),
				'xmlrpc'           => ! empty( $perf_settings['xmlrpc'] ),
			];

			$recommendations = [];

			if ( isset( $audits['server-response-time'] ) ) {
				$srt_audit = $audits['server-response-time'];
				if ( isset( $srt_audit['numericValue'] ) && $srt_audit['numericValue'] > 600 ) {
					if ( ! $features_status['server_caching'] ) {
						$recommendations[] = [
							'priority'    => 'high',
							'icon'        => 'dashicons-superhero',
							'title'       => __( 'Enable Server-Side Page Caching', 'optistate' ),
							'description' => sprintf(
								__(
									'Server response time (TTFB) is %s. Enable Server-Side Page Caching to serve pre-rendered HTML instantly, bypassing PHP processing and database queries on each request.',
									'optistate'
								),
								isset( $srt_audit['displayValue'] )
									? str_replace( 'Root document took ', '', $srt_audit['displayValue'] )
									: 'slow'
							),
							'tab'     => '#tab-performance',
							'feature' => 'server_caching',
						];
					} elseif ( ! $features_status['db_query_caching'] ) {
						$recommendations[] = [
							'priority'    => 'medium',
							'icon'        => 'dashicons-database',
							'title'       => __( 'Enable Database Query Caching', 'optistate' ),
							'description' => sprintf(
								__(
									'TTFB is %s. You have page caching enabled, but database queries may still be slowing down cache generation. Enable DB Query Caching to reduce MySQL overhead.',
									'optistate'
								),
								isset( $srt_audit['displayValue'] )
									? str_replace( 'Root document took ', '', $srt_audit['displayValue'] )
									: 'slow'
							),
							'tab'     => '#tab-performance',
							'feature' => 'db_query_caching',
						];
					}
				}
			}

			if ( isset( $audits['render-blocking-resources'] ) ) {
				$rbl_audit      = $audits['render-blocking-resources'];
				if ( isset( $rbl_audit['score'] ) && $rbl_audit['score'] < 0.9 ) {
					$blocking_items = $rbl_audit['details']['items'] ?? [];
					$blocking_count = count( $blocking_items );
					$rbl_files      = $this->get_audit_file_list( $blocking_items );
					if ( ! $features_status['browser_caching'] ) {
						$recommendations[] = [
							'priority'    => 'high',
							'icon'        => 'dashicons-performance',
							'title'       => __( 'Enable Browser Caching', 'optistate' ),
							'description' =>
								sprintf(
									__(
										'Your site has %s render-blocking resources. Enable Browser Caching to leverage browser cache for CSS, JavaScript, and static assets, reducing repeat load times.',
										'optistate'
									),
									$blocking_count > 0
										? number_format_i18n( $blocking_count )
										: 'multiple'
								) . $this->format_file_list( $rbl_files ),
							'tab'     => '#tab-performance',
							'feature' => 'browser_caching',
						];
					}
				}
			}

			if ( isset( $audits['offscreen-images'] ) || isset( $audits['modern-image-formats'] ) ) {
				$offscreen      = $audits['offscreen-images'] ?? [];
				$modern_formats = $audits['modern-image-formats'] ?? [];

				if ( isset( $offscreen['score'] ) && $offscreen['score'] < 0.9 ) {
					if ( ! $features_status['lazy_load'] ) {
						$offscreen_count    = isset( $offscreen['details']['items'] )
							? count( $offscreen['details']['items'] )
							: 0;
						$recommendations[] = [
							'priority'    => 'high',
							'icon'        => 'dashicons-images-alt2',
							'title'       => __( 'Enable Lazy Loading for Images', 'optistate' ),
							'description' => sprintf(
								__(
									'Detected %s off-screen images loading immediately. Enable Lazy Loading to defer images below the fold, reducing initial page weight and improving FCP/LCP.',
									'optistate'
								),
								$offscreen_count > 0
									? number_format_i18n( $offscreen_count )
									: 'multiple'
							),
							'tab'     => '#tab-performance',
							'feature' => 'lazy_load',
						];
					}
				}

				if ( isset( $modern_formats['score'] ) && $modern_formats['score'] < 0.9 ) {
					$potential_savings = isset( $modern_formats['details']['overallSavingsBytes'] )
						? round( $modern_formats['details']['overallSavingsBytes'] / 1024 )
						: 0;
					if ( $potential_savings > 50 ) {
						$recommendations[] = [
							'priority'    => 'medium',
							'icon'        => 'dashicons-format-image',
							'title'       => __( 'Use Modern Image Formats', 'optistate' ),
							'description' => sprintf(
								__(
									'Serving images in WebP or AVIF format could save ~%s KB. Consider using an image optimization plugin or CDN that automatically converts images to modern formats.',
									'optistate'
								),
								number_format_i18n( $potential_savings )
							),
							'tab'     => null,
							'feature' => null,
						];
					}
				}
			}

			if ( isset( $audits['unused-javascript'] ) ) {
				$uj_audit = $audits['unused-javascript'];
				if ( isset( $uj_audit['score'] ) && $uj_audit['score'] < 0.9 ) {
					$wasted_bytes = isset( $uj_audit['details']['overallSavingsBytes'] )
						? round( $uj_audit['details']['overallSavingsBytes'] / 1024 )
						: 0;
					$uj_files     = isset( $uj_audit['details']['items'] )
						? $this->get_audit_file_list( $uj_audit['details']['items'] )
						: [];
					$recommendations[] = [
						'priority'    => 'medium',
						'icon'        => 'dashicons-editor-code',
						'title'       => __( 'Reduce Unused JavaScript', 'optistate' ),
						'description' =>
							sprintf(
								__(
									'~%s KB of unused JavaScript detected. Remove unnecessary scripts and enable browser caching to reduce repeat load times. Consider code splitting or deferring non-critical scripts.',
									'optistate'
								),
								$wasted_bytes > 0
									? number_format_i18n( $wasted_bytes )
									: 'significant amount'
							) . $this->format_file_list( $uj_files ),
						'tab'     => '#tab-performance',
						'feature' => 'browser_caching',
					];
				}
			}

			$tbt_value = $audits['total-blocking-time']['numericValue'] ?? 0;
			$tti_value = $audits['interactive']['numericValue'] ?? 0;
			if ( $tbt_value > 600 || $tti_value > 7300 ) {
				$recommendations[] = [
					'priority'    => 'high',
					'icon'        => 'dashicons-database',
					'title'       => __( 'Reduce JavaScript Execution Time', 'optistate' ),
					'description' => sprintf(
						__(
							'Total Blocking Time is %s ms (target: &lt;200ms). This makes your site feel unresponsive. Optimize database queries via "Optimize All Tables" and "Optimize Autoloaded Options" in Advanced tab. Consider disabling heavy plugins during page load.',
							'optistate'
						),
						number_format_i18n( round( $tbt_value ) )
					),
					'tab'     => '#tab-advanced',
					'feature' => 'database',
				];
				if ( ! $features_status['heartbeat_api'] ) {
					$recommendations[] = [
						'priority'    => 'medium',
						'icon'        => 'dashicons-controls-repeat',
						'title'       => __( 'Slow Down the Heartbeat API', 'optistate' ),
						'description' => sprintf(
							__(
								'Total Blocking Time is %s ms. The WordPress Heartbeat API fires AJAX requests every 15–60 seconds, keeping the main thread busy. Enable Heartbeat API Control in the Performance tab and set it to "Slow Down" or "Disable on Frontend" to reduce this overhead immediately.',
								'optistate'
							),
							number_format_i18n( round( $tbt_value ) )
						),
						'tab'     => '#tab-performance',
						'feature' => 'heartbeat_api',
					];
				}
			}

			if ( isset( $audits['server-response-time'] ) ) {
				$ttfb_audit = $audits['server-response-time'];
				$ttfb_value = $ttfb_audit['numericValue'] ?? 0;

				if ( $ttfb_value > 600 && $features_status['server_caching'] ) {
					$recommendations[] = [
						'priority'    => 'medium',
						'icon'        => 'dashicons-list-view',
						'title'       => __( 'Optimize Database Indexes', 'optistate' ),
						'description' => sprintf(
							__(
								'Server response time is %s despite caching being enabled. Missing database indexes on frequently-queried columns can slow down page generation. Run "MySQL Index Manager" in the Advanced tab to analyze and add recommended indexes.',
								'optistate'
							),
							isset( $ttfb_audit['displayValue'] )
								? str_replace( 'Root document took ', '', $ttfb_audit['displayValue'] )
								: 'slow'
						),
						'tab'     => '#tab-advanced',
						'feature' => 'indexes',
					];
				}

				if ( $ttfb_value > 600 && ! $features_status['bad_bot_blocker'] ) {
					$recommendations[] = [
						'priority'    => 'medium',
						'icon'        => 'dashicons-shield',
						'title'       => __( 'Block Resource-Intensive Bots', 'optistate' ),
						'description' => sprintf(
							__(
								'Server response time is %s. Aggressive SEO crawlers (Semrush, Ahrefs, MJ12bot, etc.) can continuously hit your server, consuming PHP workers and inflating TTFB for real users. Enable the Bad Bot Blocker in the Performance tab to block these crawlers at the server level.',
								'optistate'
							),
							isset( $ttfb_audit['displayValue'] )
								? str_replace( 'Root document took ', '', $ttfb_audit['displayValue'] )
								: 'slow'
						),
						'tab'     => '#tab-performance',
						'feature' => 'bad_bot_blocker',
					];
				}

				if ( $ttfb_value > 600 && ! $features_status['xmlrpc'] ) {
					$recommendations[] = [
						'priority'    => 'low',
						'icon'        => 'dashicons-networking',
						'title'       => __( 'Disable XML-RPC', 'optistate' ),
						'description' => __(
							'XML-RPC is a legacy API that is frequently targeted by brute-force and DDoS attacks, generating thousands of PHP requests that consume server capacity. Unless you use a desktop blogging client, disabling it in the Performance tab will reduce unnecessary server load.',
							'optistate'
						),
						'tab'     => '#tab-performance',
						'feature' => 'xmlrpc',
					];
				}
			}

			if ( isset( $audits['unused-css-rules'] ) ) {
				$uc_audit = $audits['unused-css-rules'];
				if ( isset( $uc_audit['score'] ) && $uc_audit['score'] < 0.9 ) {
					$wasted_css = isset( $uc_audit['details']['overallSavingsBytes'] )
						? round( $uc_audit['details']['overallSavingsBytes'] / 1024 )
						: 0;
					$uc_files   = isset( $uc_audit['details']['items'] )
						? $this->get_audit_file_list( $uc_audit['details']['items'] )
						: [];
					$recommendations[] = [
						'priority'    => 'low',
						'icon'        => 'dashicons-admin-appearance',
						'title'       => __( 'Reduce Unused CSS', 'optistate' ),
						'description' =>
							sprintf(
								__(
									'~%s KB of unused CSS detected. Consider generating Critical CSS or using a plugin to inline above-the-fold styles and defer the rest. Enable browser caching to minimize repeat load times.',
									'optistate'
								),
								$wasted_css > 0
									? number_format_i18n( $wasted_css )
									: 'significant amount'
							) . $this->format_file_list( $uc_files ),
						'tab'     => '#tab-performance',
						'feature' => 'browser_caching',
					];
				}
			}

			if ( isset( $audits['uses-rel-preload'] ) ) {
				$preload_audit = $audits['uses-rel-preload'];
				if ( isset( $preload_audit['score'] ) && $preload_audit['score'] < 0.9 ) {
					$potential_savings = isset( $preload_audit['details']['overallSavingsMs'] )
						? round( $preload_audit['details']['overallSavingsMs'] )
						: 0;
					$recommendations[] = [
						'priority'    => 'medium',
						'icon'        => 'dashicons-external',
						'title'       => __( 'Preload Critical Assets', 'optistate' ),
						'description' => sprintf(
							__(
								'Key resources (fonts, hero images) are discovered late, delaying render by ~%s ms. Use &lt;link rel=&quot;preload&quot;&gt; to fetch critical assets immediately, improving LCP and preventing layout shifts.',
								'optistate'
							),
							$potential_savings > 0
								? number_format_i18n( $potential_savings )
								: 'several hundred'
						),
						'tab'     => null,
						'feature' => null,
					];
				}
			}

			if ( isset( $audits['unminified-javascript'] ) ) {
				$minify_audit = $audits['unminified-javascript'];
				if ( isset( $minify_audit['score'] ) && $minify_audit['score'] < 0.9 ) {
					$savings      = isset( $minify_audit['details']['overallSavingsBytes'] )
						? round( $minify_audit['details']['overallSavingsBytes'] / 1024 )
						: 0;
					$minify_files = isset( $minify_audit['details']['items'] )
						? $this->get_audit_file_list( $minify_audit['details']['items'] )
						: [];
					$recommendations[] = [
						'priority'    => 'medium',
						'icon'        => 'dashicons-media-code',
						'title'       => __( 'Minify JavaScript', 'optistate' ),
						'description' =>
							sprintf(
								__(
									'Unminified JavaScript could be reduced by ~%s KB. Minification removes whitespace and comments, reducing file size and parse time. Use a minification plugin or CDN.',
									'optistate'
								),
								$savings > 0
									? number_format_i18n( $savings )
									: 'significant amount'
							) . $this->format_file_list( $minify_files ),
						'tab'     => null,
						'feature' => null,
					];
				}
			}

			if ( isset( $audits['largest-contentful-paint-element'] ) ) {
				$lcp_audit = $audits['largest-contentful-paint-element'];
				$lcp_value = $audits['largest-contentful-paint']['numericValue'] ?? 0;
				if ( $lcp_value > 2500 ) {
					$lcp_element       = isset( $lcp_audit['details']['items'][0]['node']['nodeLabel'] )
						? $lcp_audit['details']['items'][0]['node']['nodeLabel']
						: 'main content element';
					$recommendations[] = [
						'priority'    => 'high',
						'icon'        => 'dashicons-images-alt',
						'title'       => __( 'Optimize Largest Contentful Paint', 'optistate' ),
						'description' => sprintf(
							__(
								'LCP is %.1f s (target: &lt;2.5s). The slowest element is: "%s". Optimize this element by using WebP format, adding proper sizing, preloading if it\'s an image, or enabling server-side caching.',
								'optistate'
							),
							$lcp_value / 1000,
							substr( $lcp_element, 0, 50 )
						),
						'tab'     => '#tab-performance',
						'feature' => 'server_caching',
					];
				}
			}

			if ( isset( $audits['third-party-summary'] ) ) {
				$tp_audit = $audits['third-party-summary'];
				if ( isset( $tp_audit['score'] ) && $tp_audit['score'] < 0.9 ) {
					$blocking_time     = isset( $tp_audit['details']['summary']['blockingTime'] )
						? round( $tp_audit['details']['summary']['blockingTime'] )
						: 0;
					$recommendations[] = [
						'priority'    => 'low',
						'icon'        => 'dashicons-cloud',
						'title'       => __( 'Reduce Third-Party Impact', 'optistate' ),
						'description' => sprintf(
							__(
								'Third-party scripts blocked the main thread for %s ms. Audit analytics, ads, and social widgets. Consider self-hosting critical scripts or using facades (click-to-load) for non-essential embeds.',
								'optistate'
							),
							$blocking_time > 0
								? number_format_i18n( $blocking_time )
								: 'significant time'
						),
						'tab'     => null,
						'feature' => null,
					];
				}
			}

			if ( isset( $audits['font-display'] ) ) {
				$font_audit = $audits['font-display'];
				if ( isset( $font_audit['score'] ) && $font_audit['score'] < 1 ) {
					if ( ! $features_status['font_optimization'] ) {
						$recommendations[] = [
							'priority'    => 'medium',
							'icon'        => 'dashicons-editor-textcolor',
							'title'       => __( 'Enable Font Loading Optimization', 'optistate' ),
							'description' => __(
								'Google Fonts are blocking text rendering, causing invisible text (FOIT) on load. Enable Font Loading Optimization in the Performance tab — it will add font-display: swap, async-load stylesheets, and add a preconnect hint to fonts.googleapis.com, fixing this issue automatically.',
								'optistate'
							),
							'tab'     => '#tab-performance',
							'feature' => 'font_optimization',
						];
					} elseif ( ! $features_status['font_display_swap'] ) {
						$recommendations[] = [
							'priority'    => 'medium',
							'icon'        => 'dashicons-editor-textcolor',
							'title'       => __( 'Enable font-display: swap in Font Optimization', 'optistate' ),
							'description' => __(
								'Font Loading Optimization is active, but the "font-display: swap" sub-option is disabled. Enable it to instruct browsers to show text immediately in a fallback font while the custom font loads, eliminating invisible text (FOIT).',
								'optistate'
							),
							'tab'     => '#tab-performance',
							'feature' => 'font_optimization',
						];
					}
				}
			}

			if ( isset( $audits['dom-size'] ) ) {
				$dom_audit    = $audits['dom-size'];
				if ( isset( $dom_audit['score'] ) && $dom_audit['score'] < 0.9 ) {
					$dom_elements = isset( $dom_audit['numericValue'] )
						? round( $dom_audit['numericValue'] )
						: 0;
					if ( $dom_elements > 1500 ) {
						$recommendations[] = [
							'priority'    => 'low',
							'icon'        => 'dashicons-networking',
							'title'       => __( 'Reduce DOM Size', 'optistate' ),
							'description' => sprintf(
								__(
									'Page contains %s DOM elements (recommended: &lt;1,500). Large DOMs increase memory usage, slow down style calculations, and hurt layout performance. Simplify page structure, remove unused elements, or implement pagination.',
									'optistate'
								),
								number_format_i18n( $dom_elements )
							),
							'tab'     => null,
							'feature' => null,
						];
					}
				}
			}

			usort( $recommendations, static function ( $a, $b ) {
				$priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
				return $priority_order[ $a['priority'] ] <=> $priority_order[ $b['priority'] ];
			} );

			$ttfb_display = $audits['server-response-time']['displayValue'] ?? 'N/A';
			if ( $ttfb_display !== 'N/A' ) {
				$ttfb_display = str_replace( 'Root document took ', '', $ttfb_display );
			}

			$results = [
				'score'           => isset( $lighthouse['categories']['performance']['score'] )
					? round( $lighthouse['categories']['performance']['score'] * 100 )
					: 0,
				'fcp'             => [
					'display' => $audits['first-contentful-paint']['displayValue'] ?? 'N/A',
					'value'   => $audits['first-contentful-paint']['numericValue'] ?? 0,
				],
				'lcp'             => [
					'display' => $audits['largest-contentful-paint']['displayValue'] ?? 'N/A',
					'value'   => $audits['largest-contentful-paint']['numericValue'] ?? 0,
				],
				'cls'             => [
					'display' => $audits['cumulative-layout-shift']['displayValue'] ?? 'N/A',
					'value'   => $audits['cumulative-layout-shift']['numericValue'] ?? 0,
				],
				'tbt'             => [
					'display' => $audits['total-blocking-time']['displayValue'] ?? 'N/A',
					'value'   => $audits['total-blocking-time']['numericValue'] ?? 0,
				],
				'si'              => [
					'display' => $audits['speed-index']['displayValue'] ?? 'N/A',
					'value'   => $audits['speed-index']['numericValue'] ?? 0,
				],
				'tti'             => [
					'display' => $audits['interactive']['displayValue'] ?? 'N/A',
					'value'   => $audits['interactive']['numericValue'] ?? 0,
				],
				'ttfb'            => [
					'display' => $ttfb_display,
					'value'   => $audits['server-response-time']['numericValue'] ?? 0,
				],
				'timestamp'       => current_time(
					OPTISTATE_Utils::get_cached_option( 'date_format' ) .
					' ' .
					OPTISTATE_Utils::get_cached_option( 'time_format' )
				),
				'strategy'        => ucfirst( $strategy ),
				'tested_url'      => $test_url,
				'recommendations' => array_slice( $recommendations, 0, 5 ),
			];

			update_option(
				'optistate_pagespeed_last_state',
				[
					'url'       => $test_url,
					'strategy'  => $strategy,
					'timestamp' => time(),
				],
				false
			);

			$cache_key = 'optistate_pagespeed_' . md5( $test_url . $strategy );
			set_transient( $cache_key, $results, 30 * DAY_IN_SECONDS );

			$task['status']  = 'done';
			$task['results'] = $results;
			$process_store->set( $task_id, $task, 600 );

			$path         = wp_parse_url( $test_url, PHP_URL_PATH );
			$display_path = ( $path === '/' || empty( $path ) ) ? '🏠︎/' : $path;
			$log_message  = sprintf(
				'🚦 ' . __( 'Performance Audit (%s): %d%% - %s', 'optistate' ),
				$results['strategy'],
				$results['score'],
				$display_path
			);
			$this->main_plugin->log_entry( $log_message );

		} catch ( RuntimeException $e ) {
			$task['status']  = 'error';
			$task['message'] = $e->getMessage();
			$process_store->set( $task_id, $task, 300 );

		} catch ( Throwable $e ) {
			OPTISTATE_Utils::log_critical_error(
				'run_pagespeed_worker failed unexpectedly: ' . $e->getMessage(),
				[
					'task_id' => $task_id,
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				]
			);
			$task['status']  = 'error';
			$task['message'] = __(
				'An unexpected server error occurred during the audit. Please try again.',
				'optistate'
			);
			$process_store->set( $task_id, $task, 300 );
		}
	}
	private function get_audit_file_list( array $audit_items, int $max = 5 ): array {
		$files = [];
		foreach ( $audit_items as $item ) {
			$url = $item['url'] ?? ( $item['source']['url'] ?? '' );
			if ( empty( $url ) || ! is_string( $url ) ) {
				continue;
			}
			$parsed_path = wp_parse_url( $url, PHP_URL_PATH );
			if ( ! is_string( $parsed_path ) ) {
				continue;
			}
			$name = basename( $parsed_path );
			if ( ! empty( $name ) && pathinfo( $name, PATHINFO_EXTENSION ) !== '' ) {
				$files[] = $name;
			}
			if ( count( $files ) >= $max ) {
				break;
			}
		}
		return $files;
	}
	private function format_file_list( array $files ): string {
		if ( empty( $files ) ) {
			return '';
		}
		$escaped = array_map( 'esc_html', $files );
		return '<br>' . esc_html__( '• Affected files:', 'optistate' ) . ' ' . implode( ', ', $escaped ) . '.';
	}

	public function ajax_run_pagespeed_audit(): void {
		check_ajax_referer( OPTISTATE::NONCE_ACTION, 'nonce' );
		$this->main_plugin->settings_manager->check_user_access();

		$force_refresh = isset( $_POST['force_refresh'] ) && $_POST['force_refresh'] === 'true';
		$cached_only   = isset( $_POST['cached_only'] ) && $_POST['cached_only'] === 'true';
		$strategy      = isset( $_POST['strategy'] ) && $_POST['strategy'] === 'desktop'
			? 'desktop'
			: 'mobile';
		$raw_url       = isset( $_POST['test_url'] ) && is_string( $_POST['test_url'] )
			? esc_url_raw( wp_unslash( $_POST['test_url'] ) )
			: '';
		$test_url      = trim( $raw_url );
		if ( empty( $test_url ) && ! $force_refresh ) {
			$last_state = get_option( 'optistate_pagespeed_last_state' );
			if ( is_array( $last_state ) && ! empty( $last_state['url'] ) ) {
				$test_url = $last_state['url'];
				$strategy = isset( $last_state['strategy'] ) ? $last_state['strategy'] : $strategy;
			}
		}

		if ( empty( $test_url ) ) {
			$test_url = home_url();
		} elseif ( wp_parse_url( $test_url, PHP_URL_SCHEME ) === null ) {
			$test_url = 'https://' . ltrim( $test_url, '/' );
		}

		$cache_key_static = 'optistate_pagespeed_' . md5( $test_url . $strategy );
		$cached_data      = get_transient( $cache_key_static );
		if ( $cached_only ) {
			if ( $cached_data !== false ) {
				OPTISTATE_Utils::send_json_success( $cached_data );
			} else {
				OPTISTATE_Utils::send_json_success( [ 'status' => 'no_cache' ] );
			}
			return;
		}
		if ( $cached_data !== false && ! $force_refresh ) {
			OPTISTATE_Utils::send_json_success( $cached_data );
			return;
		}
		if ( ! OPTISTATE_Utils::check_rate_limit( 'pagespeed_audit', 20 ) ) {
			OPTISTATE_Utils::send_json_error(
				OPTISTATE_Utils::get_rate_limit_message( false ),
				429
			);
			return;
		}
		if ( $force_refresh ) {
			delete_transient( $cache_key_static );
		}
		$test_url_parsed = wp_parse_url( $test_url );
		$home_url_parsed = wp_parse_url( home_url() );

		if ( ! isset( $test_url_parsed['host'] ) || ! isset( $home_url_parsed['host'] ) ) {
			OPTISTATE_Utils::send_json_error( __( 'Invalid URL format.', 'optistate' ) );
			return;
		}

		$test_host      = $test_url_parsed['host'];
		$home_host      = $home_url_parsed['host'];
		$is_valid_domain =
			$test_host === $home_host ||
			substr( $test_host, -strlen( '.' . $home_host ) ) === '.' . $home_host;

		if ( ! $is_valid_domain ) {
			OPTISTATE_Utils::send_json_error(
				__( 'Security Restriction: You can only test URLs belonging to this domain or its subdomains.', 'optistate' )
			);
			return;
		}
		try {
			$task_id  = 'psi_' . bin2hex( random_bytes( 8 ) );
			$task_data = [
				'status'   => 'pending',
				'url'      => $test_url,
				'strategy' => $strategy,
				'started'  => time(),
				'user_id'  => get_current_user_id(),
			];

			$this->main_plugin->process_store->set( $task_id, $task_data, 600 );
			$scheduled = false;
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				if ( wp_schedule_single_event( time() + 1, 'optistate_run_pagespeed_worker', [ $task_id ] ) ) {
					$scheduled = true;
					break;
				}
				usleep( 100000 );
			}
			if ( $scheduled ) {
				spawn_cron();
			} else {
				OPTISTATE_Utils::log_critical_error(
					'Could not schedule pagespeed worker cron, relying on async request.',
					[ 'task_id' => $task_id ]
				);
			}
			$auth_cookie_names = array_filter( [
				defined( 'AUTH_COOKIE' )        ? AUTH_COOKIE        : null,
				defined( 'SECURE_AUTH_COOKIE' ) ? SECURE_AUTH_COOKIE : null,
				defined( 'LOGGED_IN_COOKIE' )   ? LOGGED_IN_COOKIE   : null,
			] );
			$cookies = [];
			foreach ( $_COOKIE as $name => $value ) {
				if ( in_array( $name, $auth_cookie_names, true ) ) {
					$cookies[] = new WP_Http_Cookie( [ 'name' => $name, 'value' => $value ] );
				}
			}

			$nonce = wp_create_nonce( OPTISTATE::NONCE_ACTION );

			wp_remote_post( admin_url( 'admin-ajax.php' ), [
				'blocking' => false,
				'timeout'  => 0.5,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'  => $cookies,
				'body'     => [
					'action'  => 'optistate_run_pagespeed_worker_async',
					'task_id' => $task_id,
					'nonce'   => $nonce,
				],
			] );

			OPTISTATE_Utils::send_json_success( [
				'status'  => 'processing',
				'task_id' => $task_id,
				'message' => __( 'Audit started in background...', 'optistate' ),
			] );

		} catch ( Throwable $e ) {
			OPTISTATE_Utils::log_critical_error(
				'ajax_run_pagespeed_audit: failed to launch background task: ' . $e->getMessage(),
				[ 'file' => $e->getFile(), 'line' => $e->getLine() ]
			);
			OPTISTATE_Utils::send_json_error(
				__( 'Failed to start background task.', 'optistate' ),
				500
			);
		}
	}

	public function ajax_save_pagespeed_settings(): void {
		check_ajax_referer( OPTISTATE::NONCE_ACTION, 'nonce' );
		$this->main_plugin->settings_manager->check_user_access();

		if ( ! OPTISTATE_Utils::check_rate_limit( 'save_settings', 3 ) ) {
			OPTISTATE_Utils::send_json_error(
				OPTISTATE_Utils::get_rate_limit_message( true ),
				429
			);
			return;
		}

		$api_key = isset( $_POST['api_key'] )
			? sanitize_text_field( wp_unslash( $_POST['api_key'] ) )
			: '';
		$api_key = trim( $api_key );
		$length  = strlen( $api_key );

		if ( ! empty( $api_key ) && ( $length < 30 || $length > 80 ) ) {
			OPTISTATE_Utils::send_json_error(
				__( 'API Key must be between 30 and 80 characters.', 'optistate' )
			);
			return;
		}

		$this->main_plugin->settings_manager->save_persistent_settings( [
			'pagespeed_api_key' => $api_key,
		] );

		OPTISTATE_Utils::send_json_success( [
			'message' => __( 'API Key saved successfully.', 'optistate' ),
		] );
	}

	public function ajax_check_pagespeed_status(): void {
		check_ajax_referer( OPTISTATE::NONCE_ACTION, 'nonce' );
		$this->main_plugin->settings_manager->check_user_access();

		$task_id = isset( $_POST['task_id'] )
			? sanitize_text_field( wp_unslash( $_POST['task_id'] ) )
			: '';

		if ( empty( $task_id ) ) {
			OPTISTATE_Utils::send_json_error( __( 'Invalid Task ID.', 'optistate' ) );
			return;
		}

		$task = $this->main_plugin->process_store->get( $task_id );

		if ( ! $task ) {
			OPTISTATE_Utils::send_json_error(
				__( 'Audit session expired. Please try again.', 'optistate' )
			);
			return;
		}
		if ( (int) $task['user_id'] !== get_current_user_id() ) {
			OPTISTATE_Utils::send_json_error(
				__( 'Task not found.', 'optistate' ),
				404
			);
			return;
		}
		if (
			$task['status'] !== 'done' &&
			$task['status'] !== 'error' &&
			! empty( $task['url'] ) &&
			! empty( $task['strategy'] )
		) {
			$cache_key   = 'optistate_pagespeed_' . md5( $task['url'] . $task['strategy'] );
			$cached_data = get_transient( $cache_key );
			if ( $cached_data !== false ) {
				$task['status']  = 'done';
				$task['results'] = $cached_data;
				$this->main_plugin->process_store->set( $task_id, $task, 600 );
			}
		}
		if (
			in_array( $task['status'], [ 'pending', 'processing' ], true ) &&
			isset( $task['started'] ) &&
			( time() - (int) $task['started'] ) > 180
		) {
			$task['status']  = 'error';
			$task['message'] = __(
				'The audit worker did not complete in time. This is usually caused by a slow API response or a restricted PHP execution environment. Please try again.',
				'optistate'
			);
			$this->main_plugin->process_store->set( $task_id, $task, 60 );
		}

		if ( $task['status'] === 'done' ) {
			OPTISTATE_Utils::send_json_success( [
				'status' => 'done',
				'data'   => $task['results'],
			] );
		} elseif ( $task['status'] === 'error' ) {
			OPTISTATE_Utils::send_json_error( $task['message'] );
		} else {
			OPTISTATE_Utils::send_json_success( [ 'status' => 'processing' ] );
		}
	}

	public function ajax_run_pagespeed_worker_async(): void {
		check_ajax_referer( OPTISTATE::NONCE_ACTION, 'nonce' );
		$this->main_plugin->settings_manager->check_user_access();

		$task_id = isset( $_POST['task_id'] )
			? sanitize_text_field( wp_unslash( $_POST['task_id'] ) )
			: '';

		if ( empty( $task_id ) ) {
			wp_die( 'Invalid task ID.' );
		}

		$this->run_pagespeed_worker( $task_id );
		wp_die();
	}
}