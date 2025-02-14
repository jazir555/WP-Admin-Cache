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
	 * Normalize a URL by stripping the http/https scheme only if present.
	 */
	private function normalizeUrl($url) {
		$trimmed = trim($url);
		if ( preg_match('/^(https?):\/\//i', $trimmed) ) {
			return preg_replace('/^https?:\/\//i', '', $trimmed);
		}
		return $trimmed;
	}

	/** ----------------------------------------------------------------
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

	/** ----------------------------------------------------------------
	 * maybeInitializeSettings: Merge defaults and initialize the registry.
	 */
	public static function maybeInitializeSettings() {
		$raw = get_option('wp_admin_cache_settings');
		$settings = is_string($raw) ? json_decode($raw, true) : array();
		if ( ! is_array($settings) ) {
			$settings = array();
		}
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
		$settings = array_merge($defaults, $settings);
		if ( empty($settings['enabled-urls']) && $settings['mode'] === 'whitelist' ) {
			$detected = self::detectAdminPages();
			$settings['enabled-urls'] = $detected;
		}
		if ( ! is_array(get_option('wp_admin_cache_registry')) ) {
			update_option('wp_admin_cache_registry', array());
		}
		update_option('wp_admin_cache_settings', json_encode($settings));
	}

	/** ----------------------------------------------------------------
	 * Cleanup expired transients to avoid DB bloat.
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
					$transientName = str_replace('_timeout_', '', $row->option_name);
					delete_transient(str_replace('_transient_', '', $transientName));
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
	 * Get the cached registry, or load it.
	 */
	private function getRegistry() {
		if ($this->registryCache === null) {
			$this->registryCache = get_option('wp_admin_cache_registry', array());
		}
		return $this->registryCache;
	}

	/**
	 * Update the registry cache and option.
	 */
	private function updateRegistry($registry) {
		$this->registryCache = $registry;
		update_option('wp_admin_cache_registry', $registry);
	}

	/** ----------------------------------------------------------------
	 * Admin UI initialization. Also sets the cookie using COOKIEPATH/COOKIE_DOMAIN.
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
		// Use "/" as cookie path during AJAX to avoid conflicts.
		$cookiePath = (defined('DOING_AJAX') && DOING_AJAX) ? '/' : COOKIEPATH;
		if (!isset($_COOKIE['wp-admin-cache-session']) || $_COOKIE['wp-admin-cache-session'] !== $session) {
			@setcookie(
				'wp-admin-cache-session',
				$session,
				0,
				$cookiePath,
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
	 * Add a settings link on the plugin page.
	 */
	public function add_action_links($links) {
		$mylinks = array(
			'<a href="' . esc_url('options-general.php?page=wp-admin-cache') . '">' . __('Settings', 'wp-admin-cache') . '</a>',
		);
		return array_merge($links, $mylinks);
	}

	/** ----------------------------------------------------------------
	 * Settings page. (UI HTML is included separately via settings-page.php.)
	 */
	public function options_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		// Process manual purge requests...
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
		// Process settings save (code omitted for brevity; see previous versions)
		// ...
		include_once __DIR__ . '/settings-page.php';
	}

	/** ----------------------------------------------------------------
	 * Validate a manual line. For HTTP URLs, ensure scheme and host are present.
	 */
	private function validateManualLine($line) {
		if (stripos($line, 'http') === 0) {
			$parts = @parse_url($line);
			if (empty($parts['host']) || empty($parts['scheme'])) {
				return false;
			}
			return true;
		} else {
			return (strlen($line) >= 2);
		}
	}

	/** ----------------------------------------------------------------
	 * detectAdminPages: Return an array of admin page slugs.
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

	/** ----------------------------------------------------------------
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
	 * Instead of a large LIKE query, use a registry-based approach.
	 */
	public function purgeAllCaches() {
		$registry = get_option('wp_admin_cache_registry', array());
		if (is_array($registry)) {
			foreach ($registry as $key) {
				delete_transient($key);
			}
		}
		$this->updateRegistry(array());
		$this->debugLog('All caches purged site-wide (using registry).');
	}

	/**
	 * Purge caches for the current user using the registry.
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

	public function widget_update_callback($instance, $new_instance, $old_instance) {
		$this->purgeCurrentUserCache();
		return $instance;
	}

	/** ----------------------------------------------------------------
	 * Begin capturing output.
	 */
	private function begin() {
		if ($this->beginStarted) {
			return;
		}
		ob_start(array($this, 'end'));
		$token = $this->getToken();
		if ($token === '') {
			$this->debugLog('No session token found; caching disabled for this user.');
			return;
		}
		$this->beginStarted = true;
		$currentFullUrl = add_query_arg(null, null);
		// Unify the scheme by stripping http/https if present.
		$currentFullUrl = preg_replace('/^https?:\/\//i', '', $currentFullUrl);
		$adminUrlNoScheme = preg_replace('/^https?:\/\//i', '', admin_url());
		$relative = str_replace($adminUrlNoScheme, '', $currentFullUrl);
		if (!$this->shouldCache($relative, $currentFullUrl)) {
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

	/** ----------------------------------------------------------------
	 * End capturing output and store the transient.
	 */
	public function end($content) {
		if (strpos($content, '</html>') === false) {
			return $content;
		}
		$duration = $this->getDurationForCurrentPage();
		$content = str_replace('</body>', '<!--wp-admin-cached:' . time() . '--></body>', $content);
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
	 * For "only_cache_manually", we compare normalized (scheme-stripped) URLs.
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
		// Normal caching logic
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
	 * Determine the cache duration for the current page.
	 */
	private function getDurationForCurrentPage() {
		$currentFullUrl = preg_replace('/^https?:\/\//i', '', add_query_arg(null, null));
		$adminUrlNoScheme = preg_replace('/^https?:\/\//i', '', admin_url());
		$relative = str_replace($adminUrlNoScheme, '', $currentFullUrl);
		$defaultDuration = (int)($this->settings['duration'] ?? 5);
		$pageDurations = $this->settings['page_durations'] ?? array();
		foreach ($pageDurations as $pageKey => $minutes) {
			if (strpos($relative, trim($pageKey)) !== false) {
				$this->debugLog('Partial match for custom duration: ' . $pageKey . ' => ' . $minutes . ' minutes');
				return (int)$minutes;
			}
		}
		return $defaultDuration;
	}

	/**
	 * Check URL against an array of regex patterns.
	 */
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

	/**
	 * Retrieve the user's cache session token.
	 */
	private function getToken() {
		return isset($_COOKIE['wp-admin-cache-session']) ? sanitize_text_field($_COOKIE['wp-admin-cache-session']) : '';
	}

	/**
	 * Write a debug log message if debug mode is on.
	 * Also, if the debug log file exceeds 5MB, it is cleared.
	 */
	private function debugLog($message) {
		if (empty($this->settings['debug-mode'])) {
			return;
		}
		$timestamp = date('Y-m-d H:i:s');
		$line = "[{$timestamp}] [WPAdminCache] " . $message . "\n";
		if (file_exists($this->debugFile) && filesize($this->debugFile) > 5 * 1024 * 1024) {
			file_put_contents($this->debugFile, ""); // Clear log file if over 5MB.
		}
		@error_log($line, 3, $this->debugFile);
	}

	// ----------------------------------------------------------------
	// Finally, methods for purging caches using a registry.

	/**
	 * Purge all caches using the registry.
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

	public function widget_update_callback($instance, $new_instance, $old_instance) {
		$this->purgeCurrentUserCache();
		return $instance;
	}

	/**
	 * Begin capturing output.
	 */
	private function begin() {
		if ($this->beginStarted) {
			return;
		}
		ob_start(array($this, 'end'));
		$token = $this->getToken();
		if ($token === '') {
			$this->debugLog('No session token found; caching disabled for this user.');
			return;
		}
		$this->beginStarted = true;
		$currentFullUrl = add_query_arg(null, null);
		// Unify the scheme by stripping http/https.
		$currentFullUrl = preg_replace('/^https?:\/\//i', '', $currentFullUrl);
		$adminUrlNoScheme = preg_replace('/^https?:\/\//i', '', admin_url());
		$relative = str_replace($adminUrlNoScheme, '', $currentFullUrl);
		if (!$this->shouldCache($relative, $currentFullUrl)) {
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
	 * End capturing output and store the transient.
	 */
	public function end($content) {
		if (strpos($content, '</html>') === false) {
			return $content;
		}
		$duration = $this->getDurationForCurrentPage();
		$content = str_replace('</body>', '<!--wp-admin-cached:' . time() . '--></body>', $content);
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
	 * Retrieve the registry from the database.
	 */
	private function getRegistry() {
		if ($this->registryCache === null) {
			$this->registryCache = get_option('wp_admin_cache_registry', array());
		}
		return $this->registryCache;
	}

	/**
	 * Update the registry.
	 */
	private function updateRegistry($registry) {
		$this->registryCache = $registry;
		update_option('wp_admin_cache_registry', $registry);
	}

	/**
	 * Instead of directly calling set_transient, register the key.
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
			if (false !== strpos($relativeUrl, $skip)) {
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
				$this->debugLog('Skipping because blacklisted: ' . $relativeUrl);
				return false;
			}
			if ($this->matchAnyRegex($relativeUrl, $regex)) {
				$this->debugLog('Skipping because matched exclude regex: ' . $relativeUrl);
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
			$this->debugLog('Skipping because not in whitelist nor matched regex: ' . $relativeUrl);
			return false;
		}
	}

	/**
	 * Determine the cache duration for the current page.
	 */
	private function getDurationForCurrentPage() {
		$currentFullUrl = preg_replace('/^https?:\/\//i', '', add_query_arg(null, null));
		$adminUrlNoScheme = preg_replace('/^https?:\/\//i', '', admin_url());
		$relative = str_replace($adminUrlNoScheme, '', $currentFullUrl);
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
	 */
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

	/**
	 * Retrieve the user's cache session token.
	 */
	private function getToken() {
		return isset($_COOKIE['wp-admin-cache-session']) ? sanitize_text_field($_COOKIE['wp-admin-cache-session']) : '';
	}

	/**
	 * Write a debug log message (with basic file rotation) if debug mode is enabled.
	 */
	private function debugLog($message) {
		if (empty($this->settings['debug-mode'])) {
			return;
		}
		$timestamp = date('Y-m-d H:i:s');
		$line = "[{$timestamp}] [WPAdminCache] " . $message . "\n";
		if (file_exists($this->debugFile) && filesize($this->debugFile) > 5 * 1024 * 1024) {
			file_put_contents($this->debugFile, "");
		}
		@error_log($line, 3, $this->debugFile);
	}

}

// Finally instantiate the plugin
new AdminCacheAllSuggestions();
