<?php
/*
  Plugin Name: WP Admin Cache (Enhanced + Exact/Manual Tab + Scheme-Unification + No Large LIKE Query)
  Plugin URI: https://www.wpadmincache.com
  Description: WP Admin Cache plugin with a separate "Manual Pages" tab, exact matching option, improved security, partial or exact matching, dynamic row addition for page durations, debug logs, strict prefetch security, manual purge buttons, scheme-unification for URLs, and no large LIKE query.
  Version: 1.0.0
  Author: Grf Studio
  Author URI: https://www.wpadmincache.com
  Text Domain: wp-admin-cache
  Domain Path: /languages/
  License:
 */

// Prevent direct access
if (!defined('WPINC')) {
	exit;
}

// Activation Hook
register_activation_hook(__FILE__, 'wp_admin_cache_all_suggestions_activation');
function wp_admin_cache_all_suggestions_activation() {
	AdminCacheAllSuggestions::movePluginAtTop();
	AdminCacheAllSuggestions::maybeInitializeSettings();
	// Optionally run a version check or run migrations if needed
}

// Detect Plugin Activation
add_action('activated_plugin', 'detect_plugin_activation', 10, 2);
function detect_plugin_activation($plugin, $network_activation) {
	if ($plugin === 'wp-admin-cache/index.php' || $plugin === plugin_basename(__FILE__)) {
		AdminCacheAllSuggestions::movePluginAtTop();
	}
}

class AdminCacheAllSuggestions {

	private $settings;
	private $beginStarted   = false;
	private $currentCaching = '';
	private $enabled        = false;

	private $debugFile;

	// We'll store invalid lines in a transient array after form submission, to display a notice.
	private $invalidManualLines = array();

	public function __construct() {
		// This plugin only runs in admin
		if (!is_admin()) {
			return;
		}

		// Load and parse settings
		$raw = get_option('wp_admin_cache_settings');
		$this->settings = is_string($raw) ? json_decode($raw, true) : array();
		if (!is_array($this->settings)) {
			$this->settings = array();
		}

		$this->enabled = !empty($this->settings['enabled']);

		// Debug file defaults to wp-content/debug-wpadmincache.log
		$this->debugFile = WP_CONTENT_DIR . '/debug-wpadmincache.log';

		// Register admin-related hooks
		add_action('admin_menu', array($this, 'init'));
		add_action('admin_print_footer_scripts', array($this, 'writeScripts'));
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));

		// Show invalid lines notice if any
		add_action('admin_notices', array($this, 'maybeShowInvalidLinesNotice'));

		// Attempt to cleanup old transients on each load (optional approach if you still want).
		$this->cleanupExpiredTransients();

		if ($this->enabled) {
			$this->begin();
			$this->autoPurgeCache();
		}
	}

	/** ----------------------------------------------------------------
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

	/** ----------------------------------------------------------------
	 * maybeInitializeSettings
	 */
	public static function maybeInitializeSettings() {
		$raw = get_option('wp_admin_cache_settings');
		$settings = is_string($raw) ? json_decode($raw, true) : array();
		if (!is_array($settings)) {
			$settings = array();
		}

		// If missing, fill defaults
		$defaults = array(
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
			'exact_manual_match' => false
		);

		// Merge any missing keys
		$settings = array_merge($defaults, $settings);

		// If empty whitelist, attempt autodetect
		if (empty($settings['enabled-urls']) && $settings['mode'] === 'whitelist') {
			$detected = self::detectAdminPages();
			$settings['enabled-urls'] = $detected;
		}

		// Also init the registry for caching keys if not present:
		if (!is_array(get_option('wp_admin_cache_registry'))) {
			update_option('wp_admin_cache_registry', array());
		}

		update_option('wp_admin_cache_settings', json_encode($settings));
	}

	/** ----------------------------------------------------------------
	 * Cleanup Expired Transients - optional
	 */
	private function cleanupExpiredTransients() {
		// You can remove or keep this if you want minimal overhead
		global $wpdb;
		$timeNow = time();
		$like = $wpdb->esc_like('_transient_timeout_wp-admin-cached-') . '%';
		$sql  = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s";
		$results = $wpdb->get_results($wpdb->prepare($sql, $like));
		if ($results) {
			foreach ($results as $row) {
				$timeoutVal = (int)$row->option_value;
				if ($timeoutVal < $timeNow) {
					$transientName = str_replace('_timeout_', '', $row->option_name);
					delete_transient(str_replace('_transient_', '', $transientName));
				}
			}
		}
	}

	/** ----------------------------------------------------------------
	 * Admin UI initialization
	 */
	public function init() {
		add_options_page(
			__('WP Admin Cache (Enhanced)', 'wp-admin-cache'),
			__('WP Admin Cache', 'wp-admin-cache'),
			'manage_options',
			'wp-admin-cache',
			array($this, 'options_page')
		);

		// Attempt to set cookie
		$session = wp_get_session_token();
		if (!isset($_COOKIE['wp-admin-cache-session']) || $_COOKIE['wp-admin-cache-session'] !== $session) {
			@setcookie(
				'wp-admin-cache-session',
				$session,
				0,
				COOKIEPATH,
				COOKIE_DOMAIN,
				is_ssl(),
				true
			);
			if (!isset($_COOKIE['wp-admin-cache-session'])) {
				$this->debugLog('Could not set the admin cache cookie. Some caching may fail.');
			}
		}
	}

	/** ----------------------------------------------------------------
	 * Print JS + inline CSS
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
		?>
        <style>
            .wp-admin-cache-pageList {
                background-color: rgba(255, 255, 255, 0.7);
                padding: 5px;
            }
            .wp-admin-cache-label {
                position: fixed;
                bottom: 0px;
                right: 0px;
                background: #00DD00;
                color: #fff;
                z-index: 10000;
                opacity: .5;
                font-size: 11px;
                font-weight: bold;
                padding: 0px 5px;
                pointer-events: none;
            }
        </style>
		<?php
	}

	/** ----------------------------------------------------------------
	 * Link on plugin page
	 */
	public function add_action_links($links) {
		$mylinks = array(
			'<a href="' . esc_url('options-general.php?page=wp-admin-cache') . '">' . __('Settings', 'wp-admin-cache') . '</a>',
		);
		return array_merge($links, $mylinks);
	}

	/** ----------------------------------------------------------------
	 * Maybe show invalid lines
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

	/** ----------------------------------------------------------------
	 * The main settings page
	 */
	public function options_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		// Manual Purge
		if (isset($_POST['wp-admin-cache-manual-purge'])) {
			check_admin_referer('wp_admin_cache_settings');
			$which = sanitize_text_field($_POST['wp-admin-cache-manual-purge']);
			if ($which === 'site') {
				$this->purgeAllCaches();
				$this->debugLog('Manual site-wide purge triggered by user.');
			} else {
				$this->purgeCurrentUserCache();
				$this->debugLog('Manual user-only purge triggered by user.');
			}
		}

		// Save Settings
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

			$enabledUrls = array();
			if (!empty($_POST['enabled_urls']) && is_array($_POST['enabled_urls'])) {
				foreach ($_POST['enabled_urls'] as $url) {
					$enabledUrls[] = sanitize_text_field($url);
				}
			}
			$excludedUrls = array();
			if (!empty($_POST['excluded_urls']) && is_array($_POST['excluded_urls'])) {
				foreach ($_POST['excluded_urls'] as $url) {
					$excludedUrls[] = sanitize_text_field($url);
				}
			}
			// Regex lines
			$regexLines = isset($_POST['regex_urls']) ? explode("\n", $_POST['regex_urls']) : array();
			$regexUrls  = array();
			foreach ($regexLines as $pat) {
				$pat = trim($pat);
				if (!empty($pat)) {
					$regexUrls[] = $pat;
				}
			}

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

			// User purge events
			$possibleUser = $this->getPossibleUserPurgeEvents();
			$selectedUser = array();
			if (!empty($_POST['user_purge_events']) && is_array($_POST['user_purge_events'])) {
				foreach ($_POST['user_purge_events'] as $ev) {
					if (array_key_exists($ev, $possibleUser)) {
						$selectedUser[] = $ev;
					}
				}
			}

			// Page durations - now we expect: page_durations[0][key], page_durations[0][value]...
			$pageDurations = array();
			if (!empty($_POST['page_durations']) && is_array($_POST['page_durations'])) {
				foreach ($_POST['page_durations'] as $row) {
					$key   = isset($row['key']) ? trim(sanitize_text_field($row['key'])) : '';
					$value = isset($row['value']) ? absint($row['value']) : 0;
					if ($key !== '' && $value > 0) {
						$pageDurations[$key] = $value;
					}
				}
			}

			// Manual-only pages
			$manualUrls = array();
			$invalidLines = array();
			if (!empty($_POST['manual_urls'])) {
				$lines = explode("\n", $_POST['manual_urls']);
				foreach ($lines as $rawLine) {
					$lineTrim = trim($rawLine);
					if ($lineTrim !== '') {
						if (!$this->validateManualLine($lineTrim)) {
							$invalidLines[] = $lineTrim;
						} else {
							$manualUrls[] = $lineTrim;
						}
					}
				}
			}
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

		// Load final settings for display
		include_once __DIR__ . '/settings-page.php';  // Example: you can put your HTML here
	}

	/** ----------------------------------------------------------------
	 * Validate a manual line
	 * Also strip scheme for partial unification if you prefer
	 */
	private function validateManualLine($line) {
		// If it starts with http, parse it
		if (stripos($line, 'http') === 0) {
			$parts = @parse_url($line);
			if (empty($parts['host']) || empty($parts['scheme'])) {
				return false;
			}
			return true;
		} else {
			// If not HTTP, we allow partial lines but ensure length >= 2
			return (strlen($line) >= 2);
		}
	}

	/** ----------------------------------------------------------------
	 * detectAdminPages
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

	/** ----------------------------------------------------------------
	 * Build prefetch list
	 */
	private function getUrlsForPrefetch() {
		if (empty($this->enabled)) {
			return array();
		}
		if (!empty($this->settings['only_cache_manually'])) {
			$manuals = $this->settings['manual-urls'] ?? array();
			$list = array();
			foreach ($manuals as $m) {
				if (stripos($m, 'http') === 0) {
					$list[] = $m;
				} else {
					$list[] = admin_url($m);
				}
			}
			return $list;
		}

		$mode        = $this->settings['mode'] ?? 'whitelist';
		$enabledUrls = $this->settings['enabled-urls'] ?? array();
		$excluded    = $this->settings['excluded-urls'] ?? array();
		$regexLines  = $this->settings['regex-urls']   ?? array();
		$urls = array();

		if ($mode === 'blacklist') {
			$allPages = self::detectAdminPages();
			foreach ($allPages as $page) {
				if (!in_array($page, $excluded, true) && !$this->matchAnyRegex($page, $regexLines)) {
					$urls[] = admin_url($page);
				}
			}
		} else {
			// whitelist
			foreach ($enabledUrls as $page) {
				$urls[] = admin_url($page);
			}
			foreach (self::detectAdminPages() as $p) {
				if ($this->matchAnyRegex($p, $regexLines)) {
					$urls[] = admin_url($p);
				}
			}
			$urls = array_unique($urls);
		}
		return $urls;
	}

	/** ----------------------------------------------------------------
	 * Purge events
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
		foreach ($userEvents as $ue) {
			if ($ue === 'widget_update_callback') {
				add_filter($ue, array($this, 'widget_update_callback'), 10, 3);
			} else {
				add_action($ue, array($this, 'purgeCurrentUserCache'));
			}
		}

		if (isset($_GET['lang'])) {
			$lang = sanitize_text_field($_GET['lang']);
			if (!isset($_COOKIE['wp-admin-cache-lang']) || $lang !== $_COOKIE['wp-admin-cache-lang']) {
				add_action('admin_init', array($this, 'purgeCurrentUserCache'));
				@setcookie(
					'wp-admin-cache-lang',
					$lang,
					0,
					COOKIEPATH,
					COOKIE_DOMAIN,
					is_ssl(),
					true
				);
			}
		}
	}

	/**
	 * Instead of a large LIKE query, we store cached transient keys in an option named wp_admin_cache_registry.
	 * Then we simply iterate that registry to purge.
	 */
	public function purgeAllCaches() {
		$registry = get_option('wp_admin_cache_registry', array());
		if (is_array($registry)) {
			foreach ($registry as $key) {
				delete_transient($key);
			}
		}
		// Reset it
		update_option('wp_admin_cache_registry', array());

		$this->debugLog('All caches purged site-wide (using registry).');
	}

	/**
	 * Similarly, we read the registry and only remove keys that match the current user's token.
	 */
	public function purgeCurrentUserCache() {
		$token = $this->getToken();
		if (!$token) {
			return;
		}
		$registry = get_option('wp_admin_cache_registry', array());
		$newReg   = array();
		foreach ($registry as $key) {
			// The stored key might look like "wp-admin-cached-TOKEN-<md5>".
			if (strpos($key, 'wp-admin-cached-' . $token . '-') === 0) {
				delete_transient($key);
			} else {
				$newReg[] = $key;
			}
		}
		update_option('wp_admin_cache_registry', $newReg);

		$this->debugLog("Cache purged for user token: $token (registry approach).");
	}

	public function widget_update_callback($instance, $new_instance, $old_instance) {
		$this->purgeCurrentUserCache();
		return $instance;
	}

	private function begin() {
		if ($this->beginStarted) {
			return;
		}
		if (!ob_get_level()) {
			ob_start(array($this, 'end'));
		} else {
			ob_start(array($this, 'end'));
		}

		$token = $this->getToken();
		if ($token === '') {
			$this->debugLog('No session token found; caching disabled for this user.');
			return;
		}
		$this->beginStarted = true;

		$currentFullUrl = add_query_arg(null, null);

		// 1) We unify the scheme by removing "http://" or "https://"
		$currentFullUrl = preg_replace('/^https?:\/\//i', '', $currentFullUrl);

		$relative = str_replace(preg_replace('/^https?:\/\//i', '', admin_url()), '', $currentFullUrl);

		if (!$this->shouldCache($relative, $currentFullUrl)) {
			ob_end_flush();
			return;
		}

		if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['wp_admin_cache_prefetch'])) {
			$this->purgeCurrentUserCache();
			return;
		}

		// Build the actual transient key
		$tName   = 'wp-admin-cached-' . $token . '-' . md5($relative);
		$content = get_transient($tName);

		if (isset($_POST['wp_admin_cache_refresh']) && $_POST['wp_admin_cache_refresh'] == '1') {
			$content = false;
		}

		if ($content !== false) {
			if (isset($_POST['wp_admin_cache_prefetch'])) {
				if (!isset($_POST['prefetch_nonce']) || !wp_verify_nonce($_POST['prefetch_nonce'], 'wp_admin_cache_prefetch_nonce')) {
					die('Invalid nonce');
				}
				if (!empty($this->settings['strict-prefetch']) && !current_user_can('manage_options')) {
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
		$this->currentCaching = $tName;
		$this->debugLog('Caching new admin page: ' . $relative);
	}

	public function end($content) {
		if (strpos($content, '</html>') === false) {
			return $content;
		}
		$duration = $this->getDurationForCurrentPage();

		$content = str_replace('</body>', '<!--wp-admin-cached:' . time() . '--></body>', $content);
		// Instead of just set_transient, we also register the key in "wp_admin_cache_registry"
		$this->setCachedTransient($this->currentCaching, $content, 60 * $duration);

		if (isset($_POST['wp_admin_cache_prefetch'])) {
			if (!isset($_POST['prefetch_nonce']) || !wp_verify_nonce($_POST['prefetch_nonce'], 'wp_admin_cache_prefetch_nonce')) {
				return 'Invalid nonce';
			}
			if (!empty($this->settings['strict-prefetch']) && !current_user_can('manage_options')) {
				return 'Insufficient capability';
			}
			$this->debugLog('Page prefetched, stored for ' . $duration . ' minutes.');
			return 'prefetching:' . (60 * $duration);
		}
		$this->debugLog('Page cached for ' . $duration . ' minutes.');
		return $content;
	}

	/**
	 * Instead of a direct call to set_transient, store the key in registry
	 */
	private function setCachedTransient($key, $value, $expire) {
		set_transient($key, $value, $expire);

		$registry = get_option('wp_admin_cache_registry', array());
		if (!in_array($key, $registry, true)) {
			$registry[] = $key;
			update_option('wp_admin_cache_registry', $registry);
		}
	}

	private function shouldCache($relativeUrl, $fullUrl) {
		// If only_cache_manually is on
		if (!empty($this->settings['only_cache_manually'])) {
			$manualUrls = $this->settings['manual-urls'] ?? array();
			$exactMatch = !empty($this->settings['exact_manual_match']);
			foreach ($manualUrls as $line) {
				if ($exactMatch) {
					if (stripos($line, 'http') === 0) {
						if (rtrim($fullUrl, '/') === rtrim($line, '/')) {
							$this->debugLog('Exact match: ' . $fullUrl . ' == ' . $line);
							return true;
						}
					} else {
						$adminRel = rtrim($relativeUrl, '/');
						$lineRel  = rtrim($line, '/');
						if ($adminRel === $lineRel) {
							$this->debugLog('Exact match (relative): ' . $adminRel . ' == ' . $line);
							return true;
						}
					}
				} else {
					if (false !== strpos($fullUrl, $line) || false !== strpos($relativeUrl, $line)) {
						$this->debugLog('Partial match: ' . $line);
						return true;
					}
				}
			}
			return false;
		}

		// Normal logic
		$alwaysSkip = array(
			'post.php','post-new.php','media-new.php','plugin-install.php',
			'theme-install.php','customize.php','user-edit.php','profile.php',
		);
		foreach ($alwaysSkip as $skip) {
			if (false !== strpos($relativeUrl, $skip)) {
				$this->debugLog('Skipping caching, always skip: ' . $skip);
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
				$this->debugLog('Skipping, blacklisted: ' . $relativeUrl);
				return false;
			}
			if ($this->matchAnyRegex($relativeUrl, $regex)) {
				$this->debugLog('Skipping, matched exclude regex: ' . $relativeUrl);
				return false;
			}
			return true;
		} else {
			// whitelist
			if (in_array($relativeUrl, $enabledUrls, true)) {
				return true;
			}
			if ($strictWL) {
				return false;
			}
			if ($this->matchAnyRegex($relativeUrl, $regex)) {
				return true;
			}
			return false;
		}
	}

	private function getDurationForCurrentPage() {
		// strip scheme for consistent matching
		$currentFullUrl = preg_replace('/^https?:\/\//i', '', add_query_arg(null, null));

		$relative       = str_replace(preg_replace('/^https?:\/\//i', '', admin_url()), '', $currentFullUrl);

		$defaultDuration = (int)($this->settings['duration'] ?? 5);
		$pageDurations   = $this->settings['page_durations'] ?? array();

		foreach ($pageDurations as $pageKey => $minutes) {
			if (false !== strpos($relative, $pageKey)) {
				$this->debugLog('Partial match for custom duration: ' . $pageKey . ' => ' . $minutes);
				return (int)$minutes;
			}
		}
		return $defaultDuration;
	}

	private function matchAnyRegex($url, $patterns) {
		foreach ($patterns as $pat) {
			$pat = trim($pat);
			if ($pat === '') continue;

			if (!preg_match('/^[@#\/].+[@#\/][imsxeuADSUXJ]*$/', $pat)) {
				$this->debugLog('Skipping invalid regex (no delimiters): ' . $pat);
				continue;
			}
			$result = @preg_match($pat, $url);
			if ($result === false) {
				$this->debugLog('Regex compile failed: ' . $pat);
				continue;
			}
			if ($result === 1) {
				return true;
			}
		}
		return false;
	}

	private function getToken() {
		return isset($_COOKIE['wp-admin-cache-session']) ? sanitize_text_field($_COOKIE['wp-admin-cache-session']) : '';
	}

	private function debugLog($msg) {
		if (empty($this->settings['debug-mode'])) {
			return;
		}
		$time = date('Y-m-d H:i:s');
		$line = "[{$time}] [WPAdminCache] " . $msg . "\n";
		@error_log($line, 3, $this->debugFile);
	}
}

// Finally instantiate
new AdminCacheAllSuggestions();
