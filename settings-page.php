<?php
// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
	exit;
}

// For convenience, assume that $this->settings is available from the main plugin class.
?>
<div class="wrap">
	<h1><?php _e( 'WP Admin Cache Settings (Enhanced)', 'wp-admin-cache' ); ?></h1>

	<!-- Display any messages or errors here if needed -->

	<form method="post" action="">
		<?php wp_nonce_field( 'wp_admin_cache_settings' ); ?>

		<!-- Basic Settings -->
		<h2 class="nav-tab-wrapper">
			<a href="#wac-tab-basic" class="nav-tab nav-tab-active"><?php _e( 'Basic Settings', 'wp-admin-cache' ); ?></a>
			<a href="#wac-tab-purge" class="nav-tab"><?php _e( 'Purge Events', 'wp-admin-cache' ); ?></a>
			<a href="#wac-tab-advanced" class="nav-tab"><?php _e( 'Advanced', 'wp-admin-cache' ); ?></a>
			<a href="#wac-tab-manual" class="nav-tab"><?php _e( 'Manual Pages', 'wp-admin-cache' ); ?></a>
		</h2>

		<div id="wac-tab-basic" class="wac-tab-content" style="display:block;">
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e( 'Enable Admin Caching', 'wp-admin-cache' ); ?></th>
					<td>
						<input type="checkbox" name="enabled" value="1" <?php checked( $this->settings['enabled'] ?? false, true ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Caching Mode', 'wp-admin-cache' ); ?></th>
					<td>
						<select name="cache_mode">
							<option value="whitelist" <?php selected( $this->settings['mode'] ?? 'whitelist', 'whitelist' ); ?>><?php _e( 'Whitelist', 'wp-admin-cache' ); ?></option>
							<option value="blacklist" <?php selected( $this->settings['mode'] ?? 'whitelist', 'blacklist' ); ?>><?php _e( 'Blacklist', 'wp-admin-cache' ); ?></option>
						</select>
						<p class="description"><?php _e( 'Ignored if "Only Cache Manually" is enabled.', 'wp-admin-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Default Cache Duration (minutes)', 'wp-admin-cache' ); ?></th>
					<td>
						<input type="number" name="duration" value="<?php echo esc_attr( $this->settings['duration'] ?? 5 ); ?>" min="1" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Show "Cached Page" Label?', 'wp-admin-cache' ); ?></th>
					<td>
						<input type="checkbox" name="show_label" value="1" <?php checked( $this->settings['show-label'] ?? '0', '1' ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Debug Mode', 'wp-admin-cache' ); ?></th>
					<td>
						<input type="checkbox" name="debug_mode" value="1" <?php checked( $this->settings['debug-mode'] ?? false, true ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Strict Prefetch Security', 'wp-admin-cache' ); ?></th>
					<td>
						<input type="checkbox" name="strict_prefetch" value="1" <?php checked( $this->settings['strict-prefetch'] ?? false, true ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Strict Whitelist', 'wp-admin-cache' ); ?></th>
					<td>
						<input type="checkbox" name="strict_whitelist" value="1" <?php checked( $this->settings['strict-whitelist'] ?? false, true ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Only Cache Manually Specified Pages?', 'wp-admin-cache' ); ?></th>
					<td>
						<input type="checkbox" name="only_cache_manually" value="1" <?php checked( $this->settings['only_cache_manually'] ?? false, true ); ?> />
					</td>
				</tr>
			</table>
		</div>

		<!-- Purge Events -->
		<div id="wac-tab-purge" class="wac-tab-content" style="display:none;">
			<h2><?php _e( 'Purge Events', 'wp-admin-cache' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php _e( 'Full Purge Events', 'wp-admin-cache' ); ?></th>
					<td>
						<!-- Example checkbox for an event. Add more as needed. -->
						<label><input type="checkbox" name="full_purge_events[]" value="activated_plugin" <?php checked( in_array( 'activated_plugin', $this->settings['full_purge_events'] ?? array() ), true ); ?> /> <?php _e( 'Plugin Activated', 'wp-admin-cache' ); ?></label><br/>
						<label><input type="checkbox" name="full_purge_events[]" value="deactivated_plugin" <?php checked( in_array( 'deactivated_plugin', $this->settings['full_purge_events'] ?? array() ), true ); ?> /> <?php _e( 'Plugin Deactivated', 'wp-admin-cache' ); ?></label>
						<!-- etc. -->
					</td>
				</tr>
				<tr>
					<th><?php _e( 'User-Specific Purge Events', 'wp-admin-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="user_purge_events[]" value="wp_insert_post" <?php checked( in_array( 'wp_insert_post', $this->settings['user_purge_events'] ?? array() ), true ); ?> /> <?php _e( 'Post Inserted/Created', 'wp-admin-cache' ); ?></label><br/>
						<!-- etc. -->
					</td>
				</tr>
			</table>
		</div>

		<!-- Advanced Settings -->
		<div id="wac-tab-advanced" class="wac-tab-content" style="display:none;">
			<h2><?php _e( 'Advanced Settings', 'wp-admin-cache' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php _e( 'Page Durations', 'wp-admin-cache' ); ?></th>
					<td>
						<p class="description"><?php _e( 'Define custom cache durations for pages by specifying the page slug or pattern and the duration (in minutes).', 'wp-admin-cache' ); ?></p>
						<!-- Here you might want to implement dynamic rows. For simplicity, we’ll use a textarea with JSON: -->
						<textarea name="page_durations" rows="5" cols="50" placeholder='<?php _e( '{"page-slug":5}', 'wp-admin-cache' ); ?>'><?php echo esc_textarea( json_encode( $this->settings['page_durations'] ?? array() ) ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th><?php _e( 'Regex Patterns', 'wp-admin-cache' ); ?></th>
					<td>
						<textarea name="regex_urls" rows="5" cols="50" placeholder="<?php _e( '/pattern/', 'wp-admin-cache' ); ?>"><?php echo esc_textarea( implode( "\n", $this->settings['regex-urls'] ?? array() ) ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>

		<!-- Manual Pages Settings -->
		<div id="wac-tab-manual" class="wac-tab-content" style="display:none;">
			<h2><?php _e( 'Manual Pages', 'wp-admin-cache' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php _e( 'Manual URLs', 'wp-admin-cache' ); ?></th>
					<td>
						<textarea name="manual_urls" rows="5" cols="50" placeholder="<?php _e( 'e.g. https://example.com/wp-admin/index.php', 'wp-admin-cache' ); ?>"><?php echo esc_textarea( implode( "\n", $this->settings['manual-urls'] ?? array() ) ); ?></textarea>
						<p class="description"><?php _e( 'Enter one URL or partial admin path per line. If a URL starts with http(s), it will be normalized for scheme-unification.', 'wp-admin-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php _e( 'Exact Matching for Manual Pages', 'wp-admin-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="exact_manual_match" value="1" <?php checked( $this->settings['exact_manual_match'] ?? false, true ); ?> /> <?php _e( 'Enable exact matching (the URL must match exactly after stripping the scheme).', 'wp-admin-cache' ); ?></label>
					</td>
				</tr>
			</table>
		</div>

		<p class="submit">
			<input type="submit" name="wp-admin-cache-save-settings" class="button button-primary" value="<?php _e( 'Save Settings', 'wp-admin-cache' ); ?>" />
		</p>
	</form>
</div>
