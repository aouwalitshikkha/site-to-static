<?php
/**
 * Settings view.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$aouwal = AOUWAL_Settings::all();

$aouwal_post_types = get_post_types( array( 'public' => true ), 'objects' );
unset( $aouwal_post_types['attachment'] );
?>
<form method="post" action="options.php" class="aouwal-settings">
	<?php settings_fields( 'aouwal_settings_group' ); ?>

	<h2><?php esc_html_e( 'Content', 'aouwal-static-site-export' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Post types', 'aouwal-static-site-export' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( $aouwal_post_types as $aouwal_pt ) : ?>
						<label class="aouwal-inline">
							<input type="checkbox" name="aouwal_settings[post_types][]" value="<?php echo esc_attr( $aouwal_pt->name ); ?>" <?php checked( in_array( $aouwal_pt->name, (array) $aouwal['post_types'], true ) ); ?> />
							<?php echo esc_html( $aouwal_pt->labels->name ); ?> <code><?php echo esc_html( $aouwal_pt->name ); ?></code>
						</label><br />
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Archives', 'aouwal-static-site-export' ); ?></th>
			<td>
				<fieldset>
					<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[include_taxonomies]" value="1" <?php checked( $aouwal['include_taxonomies'] ); ?> /> <?php esc_html_e( 'Category / tag / taxonomy archives', 'aouwal-static-site-export' ); ?></label><br />
					<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[include_authors]" value="1" <?php checked( $aouwal['include_authors'] ); ?> /> <?php esc_html_e( 'Author archives', 'aouwal-static-site-export' ); ?></label><br />
					<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[include_dates]" value="1" <?php checked( $aouwal['include_dates'] ); ?> /> <?php esc_html_e( 'Date archives (year / month)', 'aouwal-static-site-export' ); ?></label><br />
					<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[include_feeds]" value="1" <?php checked( $aouwal['include_feeds'] ); ?> /> <?php esc_html_e( 'RSS feeds', 'aouwal-static-site-export' ); ?></label><br />
					<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[include_attachments]" value="1" <?php checked( $aouwal['include_attachments'] ); ?> /> <?php esc_html_e( 'Attachment pages', 'aouwal-static-site-export' ); ?></label>
				</fieldset>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Assets &amp; URLs', 'aouwal-static-site-export' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Asset handling', 'aouwal-static-site-export' ); ?></th>
			<td>
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[download_assets]" value="1" <?php checked( $aouwal['download_assets'] ); ?> /> <?php esc_html_e( 'Download &amp; localize all assets (CSS, JS, images, fonts)', 'aouwal-static-site-export' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_max_asset_mb"><?php esc_html_e( 'Maximum asset size', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="number" min="1" max="1024" id="aouwal_max_asset_mb" name="aouwal_settings[max_asset_mb]" value="<?php echo esc_attr( $aouwal['max_asset_mb'] ); ?>" class="small-text" /> <?php esc_html_e( 'MB', 'aouwal-static-site-export' ); ?>
				<p class="description"><?php esc_html_e( 'Assets larger than this are skipped with an error in the log, so one large video or archive cannot run the export out of memory. Raise it if you need to export big media files.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'URL style', 'aouwal-static-site-export' ); ?></th>
			<td>
				<fieldset>
					<label class="aouwal-inline"><input type="radio" name="aouwal_settings[url_style]" value="relative" <?php checked( $aouwal['url_style'], 'relative' ); ?> /> <?php esc_html_e( 'Relative (portable, works in a subdirectory)', 'aouwal-static-site-export' ); ?></label><br />
					<label class="aouwal-inline"><input type="radio" name="aouwal_settings[url_style]" value="root" <?php checked( $aouwal['url_style'], 'root' ); ?> /> <?php esc_html_e( 'Root-absolute (/, best for Cloudflare Pages root domain)', 'aouwal-static-site-export' ); ?></label>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_public_url"><?php esc_html_e( 'Public site URL', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="url" class="regular-text" id="aouwal_public_url" name="aouwal_settings[public_url]" value="<?php echo esc_attr( $aouwal['public_url'] ); ?>" placeholder="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Where the static site will live (used for sitemap.xml). Leave blank to use the current site URL.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_additional_urls"><?php esc_html_e( 'Additional URLs', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<textarea class="large-text code" rows="3" id="aouwal_additional_urls" name="aouwal_settings[additional_urls]"><?php echo esc_textarea( $aouwal['additional_urls'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One URL per line. These will be exported in addition to discovered content.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_exclude_urls"><?php esc_html_e( 'Exclude URLs', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<textarea class="large-text code" rows="3" id="aouwal_exclude_urls" name="aouwal_settings[exclude_urls]"><?php echo esc_textarea( $aouwal['exclude_urls'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One pattern per line. Plain text matches a substring; wrap in slashes (/regex/) for a regular expression.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Network', 'aouwal-static-site-export' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="aouwal_request_timeout"><?php esc_html_e( 'Request timeout', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="number" min="1" max="120" step="1" id="aouwal_request_timeout" name="aouwal_settings[request_timeout]" value="<?php echo esc_attr( $aouwal['request_timeout'] ); ?>" class="small-text" />
				<?php esc_html_e( 'seconds', 'aouwal-static-site-export' ); ?>
				<p class="description"><?php esc_html_e( 'How long one request may take before it is abandoned. Raise it if slow pages or large files time out during export.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_request_delay"><?php esc_html_e( 'Delay between requests', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="number" min="0" max="3000" step="50" id="aouwal_request_delay" name="aouwal_settings[request_delay]" value="<?php echo esc_attr( $aouwal['request_delay'] ); ?>" class="small-text" />
				<?php esc_html_e( 'milliseconds', 'aouwal-static-site-export' ); ?>
				<p class="description"><?php esc_html_e( 'Pause between two crawl requests. Use it on shared hosting or when the export triggers rate limiting. Zero means no pause.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_batch_size"><?php esc_html_e( 'Batch size (concurrency)', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="number" min="1" max="50" id="aouwal_batch_size" name="aouwal_settings[batch_size]" value="<?php echo esc_attr( $aouwal['batch_size'] ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'URLs processed per request. This is the crawl concurrency: lower it if your server limits PHP execution time or the origin rate-limits the export.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_crawl_origin"><?php esc_html_e( 'Custom crawl origin', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="url" id="aouwal_crawl_origin" name="aouwal_settings[crawl_origin]" value="<?php echo esc_attr( $aouwal['crawl_origin'] ); ?>" class="regular-text code" placeholder="http://wordpress:8080" />
				<p class="description"><?php esc_html_e( 'Optional. Send crawl requests to this URL instead of the site URL, for example an internal container name or a reverse-proxied host. Links in the export still use the site URL. The host is trusted like the site host, but link-local and cloud metadata addresses are refused.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_tls_mode"><?php esc_html_e( 'TLS verification', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<select id="aouwal_tls_mode" name="aouwal_settings[tls_mode]">
					<option value="auto" <?php selected( $aouwal['tls_mode'], 'auto' ); ?>><?php esc_html_e( 'Verify, except on local hosts (default)', 'aouwal-static-site-export' ); ?></option>
					<option value="strict" <?php selected( $aouwal['tls_mode'], 'strict' ); ?>><?php esc_html_e( 'Always verify certificates', 'aouwal-static-site-export' ); ?></option>
					<option value="ca" <?php selected( $aouwal['tls_mode'], 'ca' ); ?>><?php esc_html_e( 'Verify against a custom CA bundle', 'aouwal-static-site-export' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_tls_ca_file"><?php esc_html_e( 'CA bundle path', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="text" id="aouwal_tls_ca_file" name="aouwal_settings[tls_ca_file]" value="<?php echo esc_attr( $aouwal['tls_ca_file'] ); ?>" class="regular-text code" placeholder="/var/www/certs/root-ca.pem" />
				<p class="description"><?php esc_html_e( 'Absolute path on the server to a PEM bundle with one or more CA certificates. Only used when "Verify against a custom CA bundle" is selected; an unreadable path falls back to the system bundle.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'HTTP Basic Auth', 'aouwal-static-site-export' ); ?></th>
			<td>
				<p>
					<label for="aouwal_auth_user"><?php esc_html_e( 'Username', 'aouwal-static-site-export' ); ?></label><br />
					<input type="text" id="aouwal_auth_user" name="aouwal_settings[auth_user]" value="<?php echo esc_attr( $aouwal['auth_user'] ); ?>" class="regular-text" autocomplete="off" />
				</p>
				<p>
					<label for="aouwal_auth_pass"><?php esc_html_e( 'Password', 'aouwal-static-site-export' ); ?></label><br />
					<input type="password" id="aouwal_auth_pass" name="aouwal_settings[auth_pass]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo AOUWAL_Network::has_password() ? esc_attr__( 'Saved', 'aouwal-static-site-export' ) : ''; ?>" />
				</p>
				<p class="description"><?php esc_html_e( 'Sent only to this site\'s own hosts, never to external asset hosts or after a redirect to another domain. The password is stored encrypted and never shown again; leave it blank to keep the saved one.', 'aouwal-static-site-export' ); ?></p>
				<?php if ( AOUWAL_Network::has_credentials() ) : ?>
					<p>
						<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[auth_clear]" value="1" /> <?php esc_html_e( 'Remove stored credentials', 'aouwal-static-site-export' ); ?></label>
					</p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Basic Auth only. Cookie-based protection and WordPress "site password" plugins are not supported.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Cleanup &amp; output', 'aouwal-static-site-export' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Remove dynamic features', 'aouwal-static-site-export' ); ?></th>
			<td>
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[strip_comments]" value="1" <?php checked( $aouwal['strip_comments'] ); ?> /> <?php esc_html_e( 'Remove comment forms and comment sections', 'aouwal-static-site-export' ); ?></label><br />
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[strip_search]" value="1" <?php checked( $aouwal['strip_search'] ); ?> /> <?php esc_html_e( 'Remove search forms', 'aouwal-static-site-export' ); ?></label>
				<p class="description"><?php esc_html_e( 'Server-side search and comments cannot work on a static host, so they are removed.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Generated files', 'aouwal-static-site-export' ); ?></th>
			<td>
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[generate_sitemap]" value="1" <?php checked( $aouwal['generate_sitemap'] ); ?> /> <?php esc_html_e( 'sitemap.xml', 'aouwal-static-site-export' ); ?></label><br />
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[generate_robots]" value="1" <?php checked( $aouwal['generate_robots'] ); ?> /> <?php esc_html_e( 'robots.txt', 'aouwal-static-site-export' ); ?></label><br />
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[preserve_redirects]" value="1" <?php checked( $aouwal['preserve_redirects'] ); ?> /> <?php esc_html_e( 'Cloudflare Pages _redirects file', 'aouwal-static-site-export' ); ?></label><br />
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[generate_headers]" value="1" <?php checked( $aouwal['generate_headers'] ); ?> /> <?php esc_html_e( 'Cloudflare Pages _headers file', 'aouwal-static-site-export' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Attribution', 'aouwal-static-site-export' ); ?></th>
			<td>
				<label class="aouwal-inline"><input type="checkbox" name="aouwal_settings[show_credit]" value="1" <?php checked( $aouwal['show_credit'] ); ?> /> <?php esc_html_e( 'Include an invisible author credit comment in exported files', 'aouwal-static-site-export' ); ?></label>
				<p class="description"><?php esc_html_e( 'Optional and off by default. Adds a source-code-only credit for Aouwal Static Site Export – Static Site Generator. It never appears in the browser.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
