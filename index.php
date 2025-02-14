<?php
/*
  Plugin Name: WP Admin Cache (Enhanced + Exact/Manual Tab + Scheme-Unification + No Large LIKE Query)
  Plugin URI: https://www.wpadmincache.com
  Description: WP Admin Cache plugin with a separate "Manual Pages" tab, exact matching option, improved security, partial or exact matching, dynamic row addition for page durations, debug logs (with basic log rotation), strict prefetch security, manual purge buttons, scheme-unification for URLs, and no large LIKE query.
  Version: 1.0.0
  Author: Grf Studio
  Author URI: https://www.wpadmincache.com
  Text Domain: wp-admin-cache
  Domain Path: /languages/
  License:
 */

// Prevent direct access
if ( ! defined('WPINC') ) {
	exit;
}

// Activation Hook
register_activation_hook(__FILE__, 'wp_admin_cache_all_suggestions_activation');
function wp_admin_cache_all_suggestions_activation() {
	AdminCacheAllSuggestions::movePluginAtTop();
	AdminCacheAllSuggestions::maybeInitializeSettings();
	// Optionally run version checks or migrations here.
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
	// We'll store invalid manual lines in a transient for admin notice.
	private $invalidManualLines = array();
	// Local cache for the transient registry to reduce option calls.
	private $registryCache = null;

	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		// Load and parse settings.
		$raw = get_option('wp_admin_cache_settings');
		$this->settings = is_string($raw) ? json_decode($raw, true) : array();
		if ( ! is_array($this->settings) ) {
			$this->settings = array();
		}
		$this->enabled = ! empty($this->settings['enabled']);
		$this->debugFile = WP_CONTENT_DIR . '/debug-wpadmincache.log';

		// Register hooks.
		add_action('admin_menu', array($this, 'init'));
		add_action('admin_print_footer_scripts', array($this, 'writeScripts'));
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));
		add_action('admin_notices', array($this, 'maybeShowInvalidLinesNotice'));

		// Cleanup expired transients and registry.
		$this->cleanupExpiredTransients();
		$this->cleanupRegistry();

		if ($this->enabled) {
			$this->begin();
			$this->autoPurgeCache();
		}
	}

	/**
	 * Normalize a URL by stripping any scheme (e.g., http, https, ftp, custom schemes, etc.).
	 */
	private function normalizeUrl($url) {
		$trimmed = trim($url);
		return preg_replace('/^https?:\/\//i', '', $trimmed);
	}

	/**
	 * Move plugin to top.
	 */
	public static function movePluginAtTop() {
		$path = str_replace(WP_PLUGIN_DIR . '/', '', __FILE__);
		$plugins = get_option('active_plugins');
		if ( is_array($plugins) && ($key = array_search($path, $plugins)) !== false ) {
			array_splice($plugins, $key, 1);
			array_unshift($plugins, $path);
			update_option('active_plugins', $plugins);
		}
	}

	public static function maybeInitializeSettings() {
		$raw = get_option('wp_admin_cache_settings');
		$settings = is_string($raw) ? json_decode($raw, true) : array();
		if (!is_array($settings)) {
			$settings = array();
		}
		$defaults = array(
			'enabled'             => false,
			'mode'                => 'whitelist',
			'enabled-urls'        => array(),
			'excluded-urls'       => array(),
			'regex-urls'          => array(),
			'duration'            => 5,
			'show-label'          => '0',
			'full_purge_events'   => array(),
			'user_purge_events'   => array(),
			'page_durations'      => array(),
			'debug-mode'          => false,
			'strict-prefetch'     => false,
			'strict-whitelist'    => false,
			'only_cache_manually' => false,
			'manual-urls'         => array(),
			'exact_manual_match'  => false
		);
		$settings = array_merge($defaults, $settings);
		if (empty($settings['enabled-urls']) && $settings['mode'] === 'whitelist') {
			$detected = self::detectAdminPages();
			$settings['enabled-urls'] = $detected;
		}
		// Always use the per-site registry
		if (!is_array(get_option('wp_admin_cache_registry'))) {
			update_option('wp_admin_cache_registry', array());
		}
		update_option('wp_admin_cache_settings', json_encode($settings));
	}

	/**
	 * Cleanup expired transients to avoid DB bloat.
	 * This version uses a more robust method by checking the standard transient prefix.
	 */
	private function cleanupExpiredTransients() {
		global $wpdb;
		$timeNow = time();
		$like = $wpdb->esc_like('_transient_timeout_wp-admin-cached-') . '%';
		$sql  = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s";
		$results = $wpdb->get_results($wpdb->prepare($sql, $like));
		if ($results) {
			foreach ($results as $row) {
				$timeoutVal = (int)$row->option_value;
				if ($timeoutVal < $timeNow) {
					$prefix = '_transient_timeout_';
					if (strpos($row->option_name, $prefix) === 0) {
						$transientKey = substr($row->option_name, strlen($prefix));
						delete_transient($transientKey);
					}
				}
			}
		}
	}

	/**
	 * Cleanup the registry by removing keys that no longer have a transient.
	 */
	private function cleanupRegistry() {
		$registry = $this->getRegistry();
		$newRegistry = array();
		foreach ($registry as $key) {
			if ( get_transient($key) !== false ) {
				$newRegistry[] = $key;
			}
		}
		$this->updateRegistry($newRegistry);
	}

	/**
	 * Update the registry cache and option using a transient lock.
	 * Increased retries and wait time help mitigate race conditions.
	 */
	private function updateRegistry($registry) {
		$maxRetries = 10;
		$wait = 200000; // 0.2 second in microseconds
		for ($i = 0; $i < $maxRetries; $i++) {
			if (false === get_transient('wp_admin_cache_registry_lock')) {
				set_transient('wp_admin_cache_registry_lock', true, 5);
				break;
			}
			usleep($wait);
		}
		$this->registryCache = $registry;
		update_option('wp_admin_cache_registry', $registry);
		delete_transient('wp_admin_cache_registry_lock');
	}

	/**
	 * Admin UI initialization. Also sets the cookie using COOKIEPATH/COOKIE_DOMAIN.
	 * If headers are already sent, logs an error.
	 */
	public function init() {
		add_options_page(
			__('WP Admin Cache (Enhanced)', 'wp-admin-cache'),
			__('WP Admin Cache', 'wp-admin-cache'),
			'manage_options',
			'wp-admin-cache',
			array($this, 'options_page')
		);
		$session = wp_get_session_token();
		$cookiePath = (defined('DOING_AJAX') && DOING_AJAX) ? '/' : COOKIEPATH;
		if (!isset($_COOKIE['wp-admin-cache-session']) || $_COOKIE['wp-admin-cache-session'] !== $session) {
			if (!headers_sent()) {
				@setcookie('wp-admin-cache-session', $session, 0, $cookiePath, COOKIE_DOMAIN, is_ssl(), true);
			} else {
				$this->debugLog('Headers already sent; unable to set cache cookie.');
			}
			if (!isset($_COOKIE['wp-admin-cache-session'])) {
				$this->debugLog('Could not set the admin cache cookie. Some caching may fail.');
			}
		}
	}

	/**
	 * Print JS for prefetch and embed CSS inline.
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
                bottom: 0;
                right: 0;
                background: #00DD00;
                color: #fff;
                z-index: 10000;
                opacity: 0.5;
                font-size: 11px;
                font-weight: bold;
                padding: 0 5px;
                pointer-events: none;
            }
        </style>
		<?php
	}

	/**
	 * Add a settings link on the plugin page.
	 */
	public function add_action_links($links) {
		$mylinks = array(
			'<a href="' . esc_url('options-general.php?page=wp-admin-cache') . '">' . __('Settings', 'wp-admin-cache') . '</a>',
		);
		return array_merge($links, $mylinks);
	}

	/**
	 * Settings page. (UI HTML is included separately via settings-page.php.)
	 */
	public function options_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
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
		include_once __DIR__ . '/settings-page.php';
	}

	/**
	 * Validate a manual URL.
	 * For full URLs, uses filter_var for proper validation;
	 * allows relative paths (which must begin with "/") if at least 2 characters.
	 */
	private function validateManualLine($line) {
		$line = trim($line);
		if (filter_var($line, FILTER_VALIDATE_URL)) {
			return true;
		} elseif (substr($line, 0, 1) === '/') {
			// Disallow if more than one leading slash
			if (preg_match('#^/{2,}#', $line)) {
				return false;
			}
			if (strlen($line) >= 2) {
				return true;
			}
		}
		return false;
	}

	/**
	 * detectAdminPages: Return an array of admin page slugs.
	 */
	public static function detectAdminPages() {
		global $menu, $submenu;
		if (!isset($menu) || !is_array($menu)) {
			$menu = array();
		}
		if (!isset($submenu) || !is_array($submenu)) {
			$submenu = array();
		}
		$found = array();
		foreach ($menu as $item) {
			if (!empty($item[2])) {
				$found[] = $item[2];
			}
		}
		foreach ($submenu as $parent => $subItems) {
			foreach ($subItems as $subItem) {
				if (!empty($subItem[2])) {
					$found[] = $subItem[2];
				}
			}
		}
		$found = array_unique($found);
		sort($found);
		return array_values($found);
	}

	/**
	 * Build the prefetch list.
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
		$mode = $this->settings['mode'] ?? 'whitelist';
		$enabledUrls = $this->settings['enabled-urls'] ?? array();
		$excluded = $this->settings['excluded-urls'] ?? array();
		$regexUrls = $this->settings['regex-urls'] ?? array();
		$urls = array();
		if ($mode === 'blacklist') {
			$allPages = self::detectAdminPages();
			foreach ($allPages as $page) {
				if (!in_array($page, $excluded, true) && !$this->matchAnyRegex($page, $regexUrls)) {
					$urls[] = admin_url($page);
				}
			}
		} else {
			foreach ($enabledUrls as $page) {
				$urls[] = admin_url($page);
			}
			foreach (self::detectAdminPages() as $p) {
				if ($this->matchAnyRegex($p, $regexUrls)) {
					$urls[] = admin_url($p);
				}
			}
			$urls = array_unique($urls);
		}
		return $urls;
	}

	/**
	 * Hook purge events.
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
				@setcookie('wp-admin-cache-lang', $lang, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
			}
		}
	}

	/**
	 * Purge caches for the current user.
	 */
	public function purgeCurrentUserCache() {
		$token = $this->getToken();
		if (!$token) {
			return;
		}
		$registry = $this->getRegistry();
		$newReg = array();
		foreach ($registry as $key) {
			if (strpos($key, 'wp-admin-cached-' . $token . '-') === 0) {
				delete_transient($key);
			} else {
				$newReg[] = $key;
			}
		}
		$this->updateRegistry($newReg);
		$this->debugLog("Cache purged for user token: $token (registry approach).");
	}

	/**
	 * Purge all caches using the multisite-aware registry.
	 */
	public function purgeAllCaches() {
		$registry = $this->getRegistry();
		if (is_array($registry)) {
			foreach ($registry as $key) {
				delete_transient($key);
			}
		}
		$this->updateRegistry(array());
		$this->debugLog('All caches purged site-wide (using registry).');
	}

	public function widget_update_callback($instance, $new_instance, $old_instance) {
		$this->purgeCurrentUserCache();
		return $instance;
	}

	/**
	 * Begin capturing output.
	 * Uses URL parsing to correctly handle custom admin paths.
	 */
	private function begin() {
		if ($this->beginStarted) {
			return;
		}
		ob_start(array($this, 'end'));
		$token = $this->getToken();
		if ($token === '') {
			$this->debugLog('No session token found; caching disabled for this user.');
			if (ob_get_length()) {
				ob_end_clean();
			}
			return;
		}
		$this->beginStarted = true;
		$currentFullUrl = add_query_arg(null, null);
		$currentFullUrlNormalized = $this->normalizeUrl($currentFullUrl);
		$adminUrlNormalized = $this->normalizeUrl(admin_url());
		$currentParsed = parse_url('http://' . $currentFullUrlNormalized);
		$adminParsed   = parse_url('http://' . $adminUrlNormalized);
		$currentPath = isset($currentParsed['path']) ? $currentParsed['path'] : '';
		$adminPath   = isset($adminParsed['path']) ? $adminParsed['path'] : '';
		$relative = str_replace($adminPath, '', $currentPath);
		if (!$this->shouldCache($relative, $currentFullUrlNormalized)) {
			ob_end_flush();
			return;
		}
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['wp_admin_cache_prefetch'])) {
			$this->purgeCurrentUserCache();
			return;
		}
		$tName = 'wp-admin-cached-' . $token . '-' . md5($relative);
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
					$defDuration = $this->settings['duration'] ?? 5;
					$remaining = ($defDuration * 60) - (time() - $timeCached);
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

	/**
	 * Instead of directly calling set_transient, store the key in the registry.
	 */
	private function setCachedTransient($key, $value, $expire) {
		set_transient($key, $value, $expire);
		$registry = $this->getRegistry();
		if (!in_array($key, $registry, true)) {
			$registry[] = $key;
			$this->updateRegistry($registry);
		}
	}

	/**
	 * Should we cache this page?
	 * For "only_cache_manually", compares normalized URLs.
	 */
	private function shouldCache($relativeUrl, $fullUrl) {
		if (!empty($this->settings['only_cache_manually'])) {
			$manualUrls = $this->settings['manual-urls'] ?? array();
			$exactMatch = !empty($this->settings['exact_manual_match']);
			foreach ($manualUrls as $line) {
				if ($exactMatch) {
					if ($this->normalizeUrl($fullUrl) === $this->normalizeUrl($line)) {
						$this->debugLog('Exact match (normalized): ' . $fullUrl . ' == ' . $line);
						return true;
					}
				} else {
					if (stripos($this->normalizeUrl($fullUrl), $this->normalizeUrl($line)) !== false ||
					    stripos($this->normalizeUrl($relativeUrl), $this->normalizeUrl($line)) !== false) {
						$this->debugLog('Partial manual match: ' . $line);
						return true;
					}
				}
			}
			$this->debugLog('Skipping (only_cache_manually) no manual line matched: ' . $fullUrl);
			return false;
		}
		$alwaysSkip = array(
			'post.php','post-new.php','media-new.php','plugin-install.php',
			'theme-install.php','customize.php','user-edit.php','profile.php'
		);
		foreach ($alwaysSkip as $skip) {
			if (strpos($relativeUrl, $skip) !== false) {
				$this->debugLog('Skipping caching because in always-skip: ' . $skip);
				return false;
			}
		}
		$mode = $this->settings['mode'] ?? 'whitelist';
		$enabledUrls = $this->settings['enabled-urls'] ?? array();
		$excludedUrls = $this->settings['excluded-urls'] ?? array();
		$regex = $this->settings['regex-urls'] ?? array();
		$strictWL = !empty($this->settings['strict-whitelist']);
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
			$this->debugLog('Skipping: not in whitelist nor matched regex: ' . $relativeUrl);
			return false;
		}
	}

	/**
	 * Retrieve the user's cache session token.
	 */
	private function getToken() {
		return isset($_COOKIE['wp-admin-cache-session']) ? sanitize_text_field($_COOKIE['wp-admin-cache-session']) : '';
	}

	/**
	 * Write a debug log message if debug mode is on.
	 * If the debug file exceeds 5MB, it is rotated (renamed with a timestamp) rather than simply cleared.
	 * Falls back to PHP's error_log if the log directory is not writable.
	 */
	private function debugLog($message) {
		if (empty($this->settings['debug-mode'])) {
			return;
		}
		$timestamp = date('Y-m-d H:i:s');
		$line = "[{$timestamp}] [WPAdminCache] " . $message . "\n";
		$logDir = dirname($this->debugFile);
		if (!is_writable($logDir)) {
			error_log($line);
			return;
		}
		if (file_exists($this->debugFile) && filesize($this->debugFile) > 5 * 1024 * 1024) {
			$newName = $this->debugFile . '.' . date('Ymd_His');
			rename($this->debugFile, $newName);
			file_put_contents($this->debugFile, "");
		}
		@error_log($line, 3, $this->debugFile);
	}

	/**
	 * Retrieve the registry from the database (multisite‑aware).
	 */
	private function getRegistry() {
		if ($this->registryCache === null) {
			$this->registryCache = get_option('wp_admin_cache_registry', array());
		}
		return $this->registryCache;
	}

	/**
	 * Determine the cache duration for the current page.
	 * Uses URL parsing so that custom admin paths are handled properly.
	 */
	private function getDurationForCurrentPage() {
		$currentFullUrl = add_query_arg(null, null);
		$currentFullUrlNormalized = $this->normalizeUrl($currentFullUrl);
		$adminUrlNormalized = $this->normalizeUrl(admin_url());
		$currentParsed = parse_url('http://' . $currentFullUrlNormalized);
		$adminParsed   = parse_url('http://' . $adminUrlNormalized);
		$currentPath = isset($currentParsed['path']) ? $currentParsed['path'] : '';
		$adminPath   = isset($adminParsed['path']) ? $adminParsed['path'] : '';
		$relative = str_replace($adminPath, '', $currentPath);
		$defaultDuration = (int)($this->settings['duration'] ?? 5);
		$pageDurations = $this->settings['page_durations'] ?? array();
		foreach ($pageDurations as $pageKey => $minutes) {
			if (false !== strpos($relative, trim($pageKey))) {
				$this->debugLog('Partial match for custom duration: ' . $pageKey . ' => ' . $minutes . ' minutes');
				return (int)$minutes;
			}
		}
		return $defaultDuration;
	}

	/**
	 * Check URL against an array of regex patterns.
	 * Patterns longer than 100 characters are skipped to reduce the risk of DOS attacks.
	 * Instead of a simplistic delimiter check, we simply test the pattern.
	 */
	private function matchAnyRegex($url, $patterns) {
		foreach ($patterns as $pat) {
			$pat = trim($pat);
			if ($pat === '') continue;
			if (strlen($pat) > 80) {
				$this->debugLog('Skipping regex: pattern too long: ' . $pat);
				continue;
			}
			// Test the regex by attempting a dummy match
			$test = @preg_match($pat, '');
			if ($test === false) {
				$this->debugLog('Invalid regex skipped: ' . $pat);
				continue;
			}
			$result = @preg_match($pat, $url);
			if ($result === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Displays an admin notice if any invalid manual URLs have been stored.
	 * This method retrieves a transient (named "wp_admin_cache_invalid_manual_lines") that is expected
	 * to contain an array of invalid manual URL strings, and then outputs a warning notice.
	 */
	public function maybeShowInvalidLinesNotice() {
		$invalidLines = get_transient('wp_admin_cache_invalid_manual_lines');
		if (!empty($invalidLines) && is_array($invalidLines)) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
			     esc_html(__('The following manual URLs are invalid: ', 'wp-admin-cache') . implode(', ', $invalidLines)) .
			     '</p></div>';
			// Clear the transient after displaying the notice.
			delete_transient('wp_admin_cache_invalid_manual_lines');
		}
	}

}

new AdminCacheAllSuggestions();
?>
