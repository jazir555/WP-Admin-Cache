<?php
/*
  Plugin Name: WP Admin Cache (Enhanced + Exact/Manual Tab)
  Plugin URI: https://www.wpadmincache.com
  Description: WP Admin Cache plugin with a separate "Manual Pages" tab, exact matching option, and basic validation for manual lines. Also includes multiple purge events, user-specific toggles, partial or exact matching, tabbed UI, dynamic row addition, debug logs, strict prefetch security, and manual purge buttons.
  Version: 0.8.0
  Author: Grf Studio
  Author URI: https://www.grfstudio.com
  Text Domain: wp-admin-cache
  Domain Path: /languages/
  License:
 */

if (!function_exists('add_action')) {
	exit;
}

/**
 * On plugin activation, move plugin to top and (optionally) auto-detect all admin pages.
 */
register_activation_hook(__FILE__, 'wp_admin_cache_all_suggestions_activation');
function wp_admin_cache_all_suggestions_activation() {
	AdminCacheAllSuggestions::movePluginAtTop();
	AdminCacheAllSuggestions::maybeInitializeSettings();
}

function detect_plugin_activation($plugin, $network_activation) {
	if ($plugin === 'wp-admin-cache/index.php' || $plugin === plugin_basename(__FILE__)) {
		AdminCacheAllSuggestions::movePluginAtTop();
	}
}
add_action('activated_plugin', 'detect_plugin_activation', 10, 2);

class AdminCacheAllSuggestions {

	private $settings;
	private $beginStarted   = false;
	private $currentCaching = '';
	private $enabled        = false;

	private $debugFile = WP_CONTENT_DIR . '/debug-wpadmincache.log';

	// We'll store invalid lines in a transient array after form submission, to display a notice.
	private $invalidManualLines = array();

	public function __construct() {
		if (!is_admin()) {
			return;
		}
		$raw = get_option('wp_admin_cache_settings');
		$this->settings = json_decode($raw, true);
		if (!is_array($this->settings)) {
			$this->settings = array();
		}

		$this->enabled = !empty($this->settings['enabled']);

		add_action('admin_menu', array($this, 'init'));
		add_action('admin_print_footer_scripts', array($this, 'writeScripts'));
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));

		// Show invalid lines notice if any
		add_action('admin_notices', array($this, 'maybeShowInvalidLinesNotice'));

		if ($this->enabled) {
			$this->begin();
			$this->autoPurgeCache();
		}
	}

	/** --------------------------------------
	 * If we have invalid lines stored, show an admin notice
	 */
	public function maybeShowInvalidLinesNotice() {
		$invalid = get_transient('wp_admin_cache_invalid_manual_lines');
		if (!empty($invalid) && is_array($invalid)) {
			delete_transient('wp_admin_cache_invalid_manual_lines');
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php _e('Some manual pages were invalid and removed:', 'wp-admin-cache'); ?></strong></p>
				<ul>
					<?php foreach ($invalid as $line): ?>
						<li><?php echo esc_html($line); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}

	/** --------------------------------------
	 * Move plugin to top
	 */
	public static function movePluginAtTop() {
		$path = str_replace(WP_PLUGIN_DIR . '/', '', __FILE__);
		$plugins = get_option('active_plugins');
		if (is_array($plugins) && ($key = array_search($path, $plugins)) !== false) {
			array_splice($plugins, $key, 1);
			array_unshift($plugins, $path);
			update_option('active_plugins', $plugins);
		}
	}

	/** --------------------------------------
	 * Default settings
	 */
	public static function maybeInitializeSettings() {
		$raw = get_option('wp_admin_cache_settings');
		$settings = json_decode($raw, true);

		if (!is_array($settings)) {
			$settings = array(
				'enabled'            => false,
				'mode'               => 'whitelist',
				'enabled-urls'       => array(),
				'excluded-urls'      => array(),
				'regex-urls'         => array(),
				'duration'           => 5,
				'show-label'         => '0',
				'full_purge_events'  => array(),
				'user_purge_events'  => array(),
				'page_durations'     => array(),
				'debug-mode'         => false,
				'strict-prefetch'    => false,
				'strict-whitelist'   => false,
				'only_cache_manually'=> false,
				'manual-urls'        => array(),
				// New exact manual match setting
				'exact_manual_match' => false,
			);
		}

		if (empty($settings['enabled-urls']) && ($settings['mode'] === 'whitelist')) {
			$detected = self::detectAdminPages();
			$settings['enabled-urls'] = $detected;
		}

		foreach (array('excluded-urls','full_purge_events','user_purge_events','page_durations','manual-urls') as $key) {
			if (!isset($settings[$key]) || !is_array($settings[$key])) {
				$settings[$key] = array();
			}
		}
		if (!isset($settings['debug-mode'])) {
			$settings['debug-mode'] = false;
		}
		if (!isset($settings['strict-prefetch'])) {
			$settings['strict-prefetch'] = false;
		}
		if (!isset($settings['strict-whitelist'])) {
			$settings['strict-whitelist'] = false;
		}
		if (!isset($settings['only_cache_manually'])) {
			$settings['only_cache_manually'] = false;
		}
		if (!isset($settings['exact_manual_match'])) {
			$settings['exact_manual_match'] = false;
		}

		update_option('wp_admin_cache_settings', json_encode($settings));
	}

	/** --------------------------------------
	 * Admin menu
	 */
	public function init() {
		add_options_page(
			'WP Admin Cache (Enhanced)',
			'WP Admin Cache',
			'manage_options',
			'wp-admin-cache',
			array($this, 'options_page')
		);

		wp_enqueue_script(
			'wp-admin-cache-script',
			plugin_dir_url(__FILE__) . 'index.js',
			array('jquery'),
			'0.8.0',
			true
		);
		wp_enqueue_style(
			'wp-admin-cache-style',
			plugin_dir_url(__FILE__) . 'index.css',
			array(),
			'0.8.0'
		);

		$session = wp_get_session_token();
		if (!isset($_COOKIE['wp-admin-cache-session']) || $_COOKIE['wp-admin-cache-session'] != $session) {
			setcookie('wp-admin-cache-session', $session, 0, admin_url());
		}
	}

	/** --------------------------------------
	 * Print JS for prefetch
	 */
	public function writeScripts() {
		if ($this->enabled) {
			$urls  = $this->getUrlsForPrefetch();
			$nonce = wp_create_nonce('wp_admin_cache_prefetch_nonce');

			if (!empty($urls)) {
				echo '<script>';
				echo 'var wpAdminCachePrefetchNonce = "' . esc_js($nonce) . '";';
				echo 'wp_admin_cache_prefetch([';
				foreach ($urls as $url) {
					echo '"' . esc_js($url) . '",';
				}
				echo ']);</script>';
			}
		}
	}

	/** --------------------------------------
	 * Link on plugins page
	 */
	public function add_action_links($links) {
		$mylinks = array(
			'<a href="' . esc_url('options-general.php?page=wp-admin-cache') . '">' . __('Settings', 'grfwpt') . '</a>',
		);
		return array_merge($links, $mylinks);
	}

	/** --------------------------------------
	 * Settings page
	 */
	public function options_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		// Manual purge?
		if (isset($_POST['wp-admin-cache-manual-purge'])) {
			check_admin_referer('wp_admin_cache_settings');
			if ($_POST['wp-admin-cache-manual-purge'] === 'site') {
				$this->purgeAllCaches();
				$this->debugLog('Manual site-wide purge triggered by user.');
			} else {
				$this->purgeCurrentUserCache();
				$this->debugLog('Manual user-only purge triggered by user.');
			}
		}

		// Save settings
		if (isset($_POST['wp-admin-cache-save-settings'])) {
			check_admin_referer('wp_admin_cache_settings');

			$mode             = sanitize_text_field($_POST['cache_mode'] ?? 'whitelist');
			$enabled          = !empty($_POST['enabled']);
			$duration         = absint($_POST['duration'] ?? 5);
			$show_label       = !empty($_POST['show_label']) ? '1' : '0';
			$debugMode        = !empty($_POST['debug_mode']) ? true : false;
			$strictPref       = !empty($_POST['strict_prefetch']) ? true : false;
			$strictWL         = !empty($_POST['strict_whitelist']) ? true : false;
			$onlyManually     = !empty($_POST['only_cache_manually']) ? true : false;
			$exactManualMatch = !empty($_POST['exact_manual_match']) ? true : false;

			$enabledUrls = isset($_POST['enabled_urls']) ? array_map('sanitize_text_field', $_POST['enabled_urls']) : array();
			$excludedUrls= isset($_POST['excluded_urls']) ? array_map('sanitize_text_field', $_POST['excluded_urls']) : array();
			$regexUrls   = isset($_POST['regex_urls'])
				? array_map('sanitize_text_field', explode("\n", $_POST['regex_urls']))
				: array();

			// Full purge events
			$possibleFull = $this->getPossibleFullPurgeEvents();
			$selectedFull = array();
			if (!empty($_POST['full_purge_events']) && is_array($_POST['full_purge_events'])) {
				foreach ($_POST['full_purge_events'] as $ev) {
					if (array_key_exists($ev, $possibleFull)) {
						$selectedFull[] = $ev;
					}
				}
			}

			// User-specific purge events
			$possibleUser = $this->getPossibleUserPurgeEvents();
			$selectedUser = array();
			if (!empty($_POST['user_purge_events']) && is_array($_POST['user_purge_events'])) {
				foreach ($_POST['user_purge_events'] as $ev) {
					if (array_key_exists($ev, $possibleUser)) {
						$selectedUser[] = $ev;
					}
				}
			}

			// Page durations
			$pageDurations = array();
			if (!empty($_POST['page_durations'])) {
				foreach ($_POST['page_durations'] as $pageKey => $minutesStr) {
					$pageKey   = sanitize_text_field($pageKey);
					$minutes   = absint($minutesStr);
					if ($pageKey !== '' && $minutes > 0) {
						$pageDurations[$pageKey] = $minutes;
					}
				}
			}

			// Manual-only pages
			$manualUrls = array();
			$invalidLines = array(); // track invalid lines

			if (!empty($_POST['manual_urls'])) {
				$lines = explode("\n", $_POST['manual_urls']);
				foreach ($lines as $line) {
					$lineTrim = trim($line);
					if ($lineTrim !== '') {
						// Validate it
						if (!$this->validateManualLine($lineTrim)) {
							$invalidLines[] = $lineTrim;
						} else {
							$manualUrls[] = $lineTrim;
						}
					}
				}
			}

			// If invalid lines found, store them in a transient to display a notice
			if (!empty($invalidLines)) {
				set_transient('wp_admin_cache_invalid_manual_lines', $invalidLines, 60);
			}

			$newSettings = array(
				'enabled'            => $enabled,
				'mode'               => $mode,
				'enabled-urls'       => $enabledUrls,
				'excluded-urls'      => $excludedUrls,
				'regex-urls'         => $regexUrls,
				'duration'           => $duration,
				'show-label'         => $show_label,
				'full_purge_events'  => $selectedFull,
				'user_purge_events'  => $selectedUser,
				'page_durations'     => $pageDurations,
				'debug-mode'         => $debugMode,
				'strict-prefetch'    => $strictPref,
				'strict-whitelist'   => $strictWL,
				'only_cache_manually'=> $onlyManually,
				'manual-urls'        => $manualUrls,
				'exact_manual_match' => $exactManualMatch,
			);

			update_option('wp_admin_cache_settings', json_encode($newSettings));
			$this->settings = $newSettings;
			$this->enabled  = $enabled;
			$this->debugLog('Settings saved/updated.');
		}

		// Load settings
		$mode             = $this->settings['mode']               ?? 'whitelist';
		$enabled          = !empty($this->settings['enabled']);
		$duration         = $this->settings['duration']           ?? 5;
		$show_label       = $this->settings['show-label']         ?? '0';
		$enabledUrls      = $this->settings['enabled-urls']       ?? array();
		$excludedUrls     = $this->settings['excluded-urls']      ?? array();
		$regexUrls        = $this->settings['regex-urls']         ?? array();
		$chosenFull       = $this->settings['full_purge_events']  ?? array();
		$allFullEvents    = $this->getPossibleFullPurgeEvents();
		$chosenUser       = $this->settings['user_purge_events']  ?? array();
		$allUserEvents    = $this->getPossibleUserPurgeEvents();
		$pageDurations    = $this->settings['page_durations']     ?? array();
		$debugMode        = !empty($this->settings['debug-mode']);
		$strictPref       = !empty($this->settings['strict-prefetch']);
		$strictWL         = !empty($this->settings['strict-whitelist']);
		$onlyManually     = !empty($this->settings['only_cache_manually']);
		$manualUrls       = $this->settings['manual-urls']        ?? array();
		$exactManualMatch = !empty($this->settings['exact_manual_match']);

		$allDetectedPages = self::detectAdminPages();

		$regexString   = implode("\n", $regexUrls);
		$manualUrlsStr = implode("\n", $manualUrls);

		?>
		<div class="wrap">
			<h1><?php _e('WP Admin Cache Settings (Manual Tab + Exact Matching)', 'grfwpt'); ?></h1>

			<!-- Manual Purge Buttons -->
			<form method="post" style="margin-bottom:1em;">
				<?php wp_nonce_field('wp_admin_cache_settings'); ?>
				<input type="hidden" name="wp-admin-cache-manual-purge" value="site" />
				<button class="button button-secondary" type="submit">
					<?php _e('Manual Site-Wide Purge', 'grfwpt'); ?>
				</button>
			</form>
			<form method="post" style="margin-bottom:2em;">
				<?php wp_nonce_field('wp_admin_cache_settings'); ?>
				<input type="hidden" name="wp-admin-cache-manual-purge" value="user" />
				<button class="button button-secondary" type="submit">
					<?php _e('Manual User-Only Purge', 'grfwpt'); ?>
				</button>
			</form>

			<form method="post">
				<?php wp_nonce_field('wp_admin_cache_settings'); ?>

				<h2 class="nav-tab-wrapper wac-tab-nav">
					<a href="#wac-tab-basic" class="nav-tab nav-tab-active">Basic Settings</a>
					<a href="#wac-tab-purge" class="nav-tab">Purge Events</a>
					<a href="#wac-tab-advanced" class="nav-tab">Advanced</a>
					<a href="#wac-tab-manual" class="nav-tab">Manual Pages</a>
				</h2>

				<!-- Basic Settings Tab -->
				<div id="wac-tab-basic" class="wac-tab-content" style="display:block;">
					<table class="form-table">
						<tr>
							<th><?php _e('Enable Admin Caching', 'grfwpt'); ?></th>
							<td>
								<input type="checkbox" name="enabled" value="1" <?php checked($enabled, true); ?> />
							</td>
						</tr>
						<tr>
							<th><?php _e('Caching Mode', 'grfwpt'); ?></th>
							<td>
								<select name="cache_mode">
									<option value="whitelist" <?php selected($mode, 'whitelist'); ?>>Whitelist</option>
									<option value="blacklist" <?php selected($mode, 'blacklist'); ?>>Blacklist</option>
								</select>
								<p class="description">
									<?php _e('Ignored if "Only Cache Manually" is enabled', 'grfwpt'); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php _e('Default Cache Duration (minutes)', 'grfwpt'); ?></th>
							<td>
								<input type="number" name="duration" value="<?php echo esc_attr($duration); ?>" min="1" />
							</td>
						</tr>
						<tr>
							<th><?php _e('Show "Cached Page" Label?', 'grfwpt'); ?></th>
							<td>
								<input type="checkbox" name="show_label" value="1" <?php checked($show_label, '1'); ?> />
							</td>
						</tr>
						<tr>
							<th><?php _e('Debug Mode?', 'grfwpt'); ?></th>
							<td>
								<label><input type="checkbox" name="debug_mode" value="1" <?php checked($debugMode, true); ?> />
									<?php _e('Log cache actions', 'grfwpt'); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php _e('Strict Prefetch Security?', 'grfwpt'); ?></th>
							<td>
								<label><input type="checkbox" name="strict_prefetch" value="1" <?php checked($strictPref, true); ?> />
									<?php _e('Require capability check + nonce to allow prefetching', 'grfwpt'); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php _e('Strict Whitelist?', 'grfwpt'); ?></th>
							<td>
								<label><input type="checkbox" name="strict_whitelist" value="1" <?php checked($strictWL, true); ?> />
									<?php _e('Don’t fallback to partial or regex if page not explicitly listed', 'grfwpt'); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php _e('Only Cache Manually Specified Pages?', 'grfwpt'); ?></th>
							<td>
								<label><input type="checkbox" name="only_cache_manually" value="1" <?php checked($onlyManually, true); ?> />
									<?php _e('Ignore all whitelists, blacklists, regex, durations, unless page is in Manual Pages tab.', 'grfwpt'); ?></label>
							</td>
						</tr>
					</table>
				</div><!-- #wac-tab-basic -->

				<!-- Purge Events Tab -->
				<div id="wac-tab-purge" class="wac-tab-content" style="display:none;">
					<h2><?php _e('Full Purge Events', 'grfwpt'); ?></h2>
					<p><?php _e('These events will purge caches for ALL users.', 'grfwpt'); ?></p>
					<?php foreach ($allFullEvents as $hookName => $hookLabel):
						$isChecked = in_array($hookName, $chosenFull, true);
						?>
						<label style="display:block;margin-left:20px;">
							<input type="checkbox" name="full_purge_events[]"
							       value="<?php echo esc_attr($hookName); ?>"
								<?php checked($isChecked, true); ?> />
							<?php echo esc_html($hookLabel); ?>
						</label>
					<?php endforeach; ?>

					<hr>
					<h2><?php _e('User-Specific Purge Events', 'grfwpt'); ?></h2>
					<p><?php _e('These events will purge only the current user’s admin cache.', 'grfwpt'); ?></p>
					<?php foreach ($allUserEvents as $hookName => $hookLabel):
						$isChecked = in_array($hookName, $chosenUser, true);
						?>
						<label style="display:block;margin-left:20px;">
							<input type="checkbox" name="user_purge_events[]"
							       value="<?php echo esc_attr($hookName); ?>"
								<?php checked($isChecked, true); ?> />
							<?php echo esc_html($hookLabel); ?>
						</label>
					<?php endforeach; ?>
				</div><!-- #wac-tab-purge -->

				<!-- Advanced Tab -->
				<div id="wac-tab-advanced" class="wac-tab-content" style="display:none;">
					<h2><?php _e('Per-Page Durations (Partial Matching)', 'grfwpt'); ?></h2>
					<p class="description">
						<?php _e('Ignored if "Only Cache Manually" is enabled, unless the page is also in the Manual list.', 'grfwpt'); ?>
					</p>

					<table id="wac-durations-table" class="widefat">
						<thead>
						<tr>
							<th><?php _e('Admin Page or Pattern', 'grfwpt'); ?></th>
							<th><?php _e('Minutes', 'grfwpt'); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php
						$rowsToShow = array_keys($pageDurations);
						if (!in_array('', $rowsToShow, true)) {
							$rowsToShow[] = '';
						}
						foreach ($rowsToShow as $pageKey) {
							$minutesVal = isset($pageDurations[$pageKey]) ? $pageDurations[$pageKey] : '';
							?>
							<tr>
								<td>
									<input type="text" name="page_durations[<?php echo esc_attr($pageKey); ?>]"
									       value="<?php echo esc_attr($minutesVal); ?>"
									       placeholder="e.g. index.php or edit.php?post_type=product" />
								</td>
								<td>
									<input type="number" name="page_durations[<?php echo esc_attr($pageKey); ?>]"
									       value="<?php echo esc_attr($minutesVal); ?>" min="0" />
								</td>
							</tr>
							<?php
						}
						?>
						</tbody>
					</table>
					<p><a href="#" id="wac-add-duration-row" class="button"><?php _e('Add New Row', 'grfwpt'); ?></a></p>

					<!-- Hidden template row -->
					<table style="display:none;">
						<tr class="wac-duration-row-template">
							<td>
								<input type="text" name="page_durations[]" placeholder="admin page or pattern" />
							</td>
							<td>
								<input type="number" name="page_durations[]" value="" min="0" />
							</td>
						</tr>
					</table>

					<hr>
					<?php if (!$onlyManually): ?>
						<?php if ($mode === 'whitelist'): ?>
							<h2><?php _e('Whitelist: Enabled Pages', 'grfwpt'); ?></h2>
						<?php else: ?>
							<h2><?php _e('Blacklist: Excluded Pages', 'grfwpt'); ?></h2>
						<?php endif; ?>
						<?php foreach ($allDetectedPages as $page):
							$checked = ($mode === 'whitelist')
								? in_array($page, $enabledUrls, true)
								: in_array($page, $excludedUrls, true);
							?>
							<label style="display:block;">
								<input type="checkbox"
								       name="<?php echo ($mode === 'whitelist') ? 'enabled_urls[]' : 'excluded_urls[]'; ?>"
								       value="<?php echo esc_attr($page); ?>"
									<?php checked($checked, true); ?> />
								<?php echo esc_html($page); ?>
							</label>
						<?php endforeach; ?>
					<?php else: ?>
						<p><em><?php _e('Whitelist/Blacklist are disabled because "Only Cache Manually" is on.', 'grfwpt'); ?></em></p>
					<?php endif; ?>

					<hr>
					<h2><?php _e('Regex Patterns', 'grfwpt'); ?></h2>
					<p><?php _e('Regex lines to match admin pages (ignored if "Only Cache Manually" is on).', 'grfwpt'); ?></p>
					<textarea name="regex_urls" rows="5" cols="70"><?php echo esc_textarea($regexString); ?></textarea>
				</div><!-- #wac-tab-advanced -->

				<!-- Manual Pages Tab -->
				<div id="wac-tab-manual" class="wac-tab-content" style="display:none;">
					<h2><?php _e('Manual Pages', 'grfwpt'); ?></h2>
					<p><?php _e('Enter one page/URL per line. If "Only Cache Manually" is on, only these lines will be cached. Otherwise, this is ignored.', 'grfwpt'); ?></p>
					<textarea name="manual_urls" rows="5" cols="70" placeholder="e.g. https://example.com/wp-admin/index.php"><?php echo esc_textarea($manualUrlsStr); ?></textarea>
					<p class="description"><?php _e('Full or partial admin URLs are allowed. For example, "edit.php?post_type=mytype" or the full domain path. See below for exact vs. partial.', 'grfwpt'); ?></p>

					<hr>
					<h2><?php _e('Exact Matching for Manual Pages?', 'grfwpt'); ?></h2>
					<p><?php _e('If enabled, we compare rtrim() of the line vs. the full current admin URL. If disabled, we do partial substring matching for manual lines.', 'grfwpt'); ?></p>
					<label>
						<input type="checkbox" name="exact_manual_match" value="1" <?php checked($exactManualMatch, true); ?> />
						<?php _e('Enable exact matching (no partial substring).', 'grfwpt'); ?>
					</label>
				</div><!-- #wac-tab-manual -->

				<p class="submit" style="margin-top:1em;">
					<input type="submit" name="wp-admin-cache-save-settings" class="button button-primary"
					       value="<?php _e('Save Settings', 'grfwpt'); ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	/** --------------------------------------
	 * Full purge events
	 */
	private function getPossibleFullPurgeEvents() {
		return array(
			'activated_plugin'           => 'Plugin Activated',
			'deactivated_plugin'         => 'Plugin Deactivated',
			'upgrader_process_complete'  => 'Plugin/Theme Upgraded',
			'switch_theme'               => 'Theme Switched',
			'core_upgrade'               => 'WordPress Core Upgraded',
			'user_register'              => 'New User Registered',
			'deleted_user'               => 'User Deleted',
			'profile_update'             => 'Profile Updated (site-wide purge)',
			'import_done'                => 'Import Finished (WXR Importer)',
		);
	}

	/** --------------------------------------
	 * User-specific purge events
	 */
	private function getPossibleUserPurgeEvents() {
		return array(
			'wp_insert_post'   => 'Post Inserted/Created',
			'save_post'        => 'Post Saved',
			'widget_update_callback' => 'Widget Updated',
		);
	}

	/** --------------------------------------
	 * Basic validation for manual lines
	 * We allow partial for "edit.php...", but we do a parse_url check if it starts with http
	 */
	private function validateManualLine($line) {
		// If it starts with http, let's parse it
		if (stripos($line, 'http') === 0) {
			$parts = @parse_url($line);
			if (empty($parts['host']) && empty($parts['path'])) {
				// Possibly invalid
				return false;
			}
			// If scheme or host is missing, probably invalid
			if (empty($parts['scheme']) || empty($parts['host'])) {
				return false;
			}
			// otherwise we consider it valid
			return true;
		}
		// If not, we allow partial like "edit.php?post_type=foo"
		// Minimal check: ensure there's at least some text
		if (strlen($line) < 2) {
			return false;
		}
		return true;
	}

	/** --------------------------------------
	 * Gather admin pages
	 */
	public static function detectAdminPages() {
		global $menu, $submenu;
		$found = array();

		if (is_array($menu)) {
			foreach ($menu as $item) {
				if (!empty($item[2])) {
					$found[] = $item[2];
				}
			}
		}
		if (is_array($submenu)) {
			foreach ($submenu as $parent => $subItems) {
				foreach ($subItems as $subItem) {
					if (!empty($subItem[2])) {
						$found[] = $subItem[2];
					}
				}
			}
		}
		$found = array_unique($found);
		sort($found);
		return array_values($found);
	}

	/** --------------------------------------
	 * Prefetch list
	 */
	private function getUrlsForPrefetch() {
		if (empty($this->settings) || empty($this->enabled)) {
			return array();
		}
		if (!empty($this->settings['only_cache_manually'])) {
			$manuals = $this->settings['manual-urls'] ?? array();
			$prefetchList = array();
			foreach ($manuals as $line) {
				if (stripos($line, 'http') === 0) {
					$prefetchList[] = $line;
				} else {
					$prefetchList[] = admin_url($line);
				}
			}
			return $prefetchList;
		}

		// otherwise do normal mode
		$mode        = $this->settings['mode'] ?? 'whitelist';
		$enabledUrls = $this->settings['enabled-urls'] ?? array();
		$excluded    = $this->settings['excluded-urls'] ?? array();
		$regexUrls   = $this->settings['regex-urls'] ?? array();

		if ($mode === 'blacklist') {
			$allPages     = self::detectAdminPages();
			$prefetchList = array();
			foreach ($allPages as $page) {
				if (!in_array($page, $excluded, true) && !$this->matchAnyRegex($page, $regexUrls)) {
					$prefetchList[] = admin_url($page);
				}
			}
			return $prefetchList;
		} else {
			$prefetchList = array();
			foreach ($enabledUrls as $page) {
				$prefetchList[] = admin_url($page);
			}
			foreach (self::detectAdminPages() as $possible) {
				if ($this->matchAnyRegex($possible, $regexUrls)) {
					$prefetchList[] = admin_url($possible);
				}
			}
			return array_unique($prefetchList);
		}
	}

	/** --------------------------------------
	 * Hook purge events
	 */
	public function autoPurgeCache() {
		$fullEvents = $this->settings['full_purge_events'] ?? array();
		foreach ($fullEvents as $ev) {
			if ($ev === 'upgrader_process_complete') {
				add_action($ev, array($this, 'purgeAllCaches'), 10, 2);
			} else {
				add_action($ev, array($this, 'purgeAllCaches'));
			}
		}

		$userEvents = $this->settings['user_purge_events'] ?? array();
		foreach ($userEvents as $uev) {
			if ($uev === 'widget_update_callback') {
				add_filter($uev, array($this, 'widget_update_callback'), 10, 3);
			} else {
				add_action($uev, array($this, 'purgeCurrentUserCache'));
			}
		}

		if (isset($_GET['lang'])) {
			$lang = sanitize_text_field($_GET['lang']);
			if (!isset($_COOKIE['wp-admin-cache-lang']) || $lang != $_COOKIE['wp-admin-cache-lang']) {
				add_action('admin_init', array($this, 'purgeCurrentUserCache'));
				setcookie('wp-admin-cache-lang', $lang, 0, admin_url());
			}
		}
	}

	/** --------------------------------------
	 * Purge all
	 */
	public function purgeAllCaches() {
		global $wpdb;
		$like = $wpdb->esc_like('wp-admin-cached-') . '%';
		$sql  = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s";
		$results = $wpdb->get_col($wpdb->prepare($sql, '_transient_' . $like));

		foreach ($results as $optionName) {
			$transientKey = str_replace('_transient_', '', $optionName);
			delete_transient($transientKey);
		}
		$this->debugLog('All caches purged site-wide.');
	}

	/** --------------------------------------
	 * Purge current user
	 */
	public function purgeCurrentUserCache() {
		global $wpdb;
		$token = $this->getToken();
		if (!$token) {
			return;
		}
		$like = $wpdb->esc_like('wp-admin-cached-' . $token . '-') . '%';
		$sql  = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s";
		$results = $wpdb->get_col($wpdb->prepare($sql, '_transient_' . $like));

		foreach ($results as $optionName) {
			$transientKey = str_replace('_transient_', '', $optionName);
			delete_transient($transientKey);
		}
		$this->debugLog('Cache purged for user token: ' . $token);
	}

	/** --------------------------------------
	 * Widget => user purge
	 */
	public function widget_update_callback($instance, $new_instance, $old_instance) {
		$this->purgeCurrentUserCache();
		return $instance;
	}

	/** --------------------------------------
	 * Begin capture
	 */
	private function begin() {
		if ($this->beginStarted) {
			return;
		}
		$token = $this->getToken();
		if ($token === '') {
			return;
		}
		$this->beginStarted = true;

		$currentFullUrl = add_query_arg(null, null);
		$relative       = str_replace(admin_url(), '', $currentFullUrl);

		if (!$this->shouldCache($relative, $currentFullUrl)) {
			return;
		}

		if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['wp_admin_cache_prefetch'])) {
			$this->purgeCurrentUserCache();
			return;
		}

		$tName   = 'wp-admin-cached-' . $token . '-' . md5($relative);
		$content = get_transient($tName);

		if (isset($_POST['wp_admin_cache_refresh']) && $_POST['wp_admin_cache_refresh'] == '1') {
			$content = false;
		}

		if ($content === false) {
			ob_start(array($this, 'end'));
			$this->currentCaching = $tName;
			$this->debugLog('Caching new admin page: ' . $relative);
		} else {
			if (isset($_POST['wp_admin_cache_prefetch'])) {
				if (!isset($_POST['prefetch_nonce']) ||
				    !wp_verify_nonce($_POST['prefetch_nonce'], 'wp_admin_cache_prefetch_nonce')) {
					die('Invalid nonce');
				}
				if (!empty($this->settings['strict-prefetch']) &&
				    !current_user_can('manage_options')) {
					die('Insufficient capability for prefetch.');
				}
				preg_match('/--wp-admin-cached:(.*)--/', $content, $matches);
				if (!empty($matches[1])) {
					$timeCached = (int)$matches[1];
					$defDuration= $this->settings['duration'] ?? 5;
					$remaining  = ($defDuration * 60) - (time() - $timeCached);
					echo 'prefetched:' . max(0, $remaining);
				}
				$this->debugLog('Prefetch request for: ' . $relative);
				die();
			}

			if (!empty($this->settings['show-label']) && $this->settings['show-label'] === '1') {
				$content = str_replace('</body>', '<div class="wp-admin-cache-label">cached page</div></body>', $content);
			}
			echo $content;
			$this->debugLog('Served cached admin page: ' . $relative);
			die();
		}
	}

	/** --------------------------------------
	 * End capture
	 */
	public function end($content) {
		if (strpos($content, '</html>') === false) {
			return $content;
		}

		$duration = $this->getDurationForCurrentPage();

		$content = str_replace('</body>', '<!--wp-admin-cached:' . time() . '--></body>', $content);
		set_transient($this->currentCaching, $content, 60 * $duration);

		if (isset($_POST['wp_admin_cache_prefetch'])) {
			if (!isset($_POST['prefetch_nonce']) ||
			    !wp_verify_nonce($_POST['prefetch_nonce'], 'wp_admin_cache_prefetch_nonce')) {
				return 'Invalid nonce';
			}
			if (!empty($this->settings['strict-prefetch']) &&
			    !current_user_can('manage_options')) {
				return 'Insufficient capability';
			}
			$this->debugLog('Page prefetched, stored for ' . $duration . ' minutes.');
			return 'prefetching:' . (60 * $duration);
		}
		$this->debugLog('Page cached for ' . $duration . ' minutes.');
		return $content;
	}

	/** --------------------------------------
	 * Should we cache?
	 */
	private function shouldCache($relativeUrl, $fullUrl) {
		// If only_cache_manually is on
		if (!empty($this->settings['only_cache_manually'])) {
			$manualUrls = $this->settings['manual-urls'] ?? array();
			$exactMatch = !empty($this->settings['exact_manual_match']);
			foreach ($manualUrls as $line) {
				if ($exactMatch) {
					// rtrim match for exact
					// Check if user typed a full URL or partial
					if (stripos($line, 'http') === 0) {
						// full domain
						if (rtrim($fullUrl, '/') === rtrim($line, '/')) {
							$this->debugLog('Exact match: fullUrl === line => ' . $line);
							return true;
						}
					} else {
						// partial path
						$adminRel = rtrim($relativeUrl, '/');
						$lineRel  = rtrim($line, '/');
						if ($adminRel === $lineRel) {
							$this->debugLog('Exact match: relativeUrl === line => ' . $line);
							return true;
						}
					}
				} else {
					// partial substring check
					if (strpos($fullUrl, $line) !== false) {
						$this->debugLog('Caching because partial fullUrl matched a manual line: ' . $line);
						return true;
					}
					if (strpos($relativeUrl, $line) !== false) {
						$this->debugLog('Caching because partial relative matched a manual line: ' . $line);
						return true;
					}
				}
			}
			$this->debugLog('Skipping because only_cache_manually is on and no manual line matched: ' . $fullUrl);
			return false;
		}

		// Normal logic
		$alwaysSkip = array(
			'post.php','post-new.php','media-new.php','plugin-install.php',
			'theme-install.php','customize.php','user-edit.php','profile.php',
		);
		foreach ($alwaysSkip as $skip) {
			if (strpos($relativeUrl, $skip) !== false) {
				$this->debugLog('Skipping caching because in always-skip: ' . $skip);
				return false;
			}
		}

		$mode         = $this->settings['mode'] ?? 'whitelist';
		$enabledUrls  = $this->settings['enabled-urls'] ?? array();
		$excludedUrls = $this->settings['excluded-urls'] ?? array();
		$regex        = $this->settings['regex-urls']   ?? array();
		$strictWL     = !empty($this->settings['strict-whitelist']);

		if ($mode === 'blacklist') {
			if (in_array($relativeUrl, $excludedUrls, true)) {
				$this->debugLog('Skipping because blacklisted: ' . $relativeUrl);
				return false;
			}
			if ($this->matchAnyRegex($relativeUrl, $regex)) {
				$this->debugLog('Skipping because matched exclude regex: ' . $relativeUrl);
				return false;
			}
			$this->debugLog('Caching because not blacklisted: ' . $relativeUrl);
			return true;
		} else {
			// whitelist
			if (in_array($relativeUrl, $enabledUrls, true)) {
				$this->debugLog('Caching because explicitly in whitelist: ' . $relativeUrl);
				return true;
			}
			if ($strictWL) {
				$this->debugLog('Strict Whitelist is on, skipping: ' . $relativeUrl);
				return false;
			}
			if ($this->matchAnyRegex($relativeUrl, $regex)) {
				$this->debugLog('Caching because matched whitelist regex: ' . $relativeUrl);
				return true;
			}
			$this->debugLog('Skipping because not in whitelist nor matched regex: ' . $relativeUrl);
			return false;
		}
	}

	/** --------------------------------------
	 * Custom durations
	 */
	private function getDurationForCurrentPage() {
		$currentFullUrl = add_query_arg(null, null);
		$relative       = str_replace(admin_url(), '', $currentFullUrl);

		$defaultDuration = (int)($this->settings['duration'] ?? 5);
		$pageDurations   = $this->settings['page_durations'] ?? array();

		foreach ($pageDurations as $pageKey => $minutes) {
			$pageKey = trim($pageKey);
			if ($pageKey === '') {
				continue;
			}
			if (strpos($relative, $pageKey) !== false) {
				$this->debugLog('Found partial match for custom duration: ' . $pageKey . ' => ' . $minutes . ' minutes');
				return (int)$minutes;
			}
		}
		return $defaultDuration;
	}

	/** --------------------------------------
	 * Regex ignoring invalid patterns
	 */
	private function matchAnyRegex($url, $patterns) {
		foreach ($patterns as $pattern) {
			$pattern = trim($pattern);
			if ($pattern === '') {
				continue;
			}
			$delim = substr($pattern, 0, 1);
			if ($delim !== '/' && $delim !== '#') {
				$pattern = '/' . $pattern . '/';
			}
			$result = @preg_match($pattern, $url);
			if ($result === false) {
				$this->debugLog('Invalid regex pattern: ' . $pattern);
				continue;
			}
			if ($result === 1) {
				return true;
			}
		}
		return false;
	}

	/** --------------------------------------
	 * Get user token
	 */
	private function getToken() {
		return $_COOKIE['wp-admin-cache-session'] ?? '';
	}

	/** --------------------------------------
	 * Debug log
	 */
	private function debugLog($message) {
		if (empty($this->settings['debug-mode'])) {
			return;
		}
		$timestamp = date('Y-m-d H:i:s');
		$line = "[{$timestamp}] [WPAdminCache] " . $message . "\n";
		@error_log($line, 3, $this->debugFile);
	}
}

// Instantiate
new AdminCacheAllSuggestions();
