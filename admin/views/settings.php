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
				<p class="description"><?php esc_html_e( 'Optional and off by default. Adds a source-code-only credit for Aouwal Static Site Export. It never appears in the browser.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="aouwal_batch_size"><?php esc_html_e( 'Batch size', 'aouwal-static-site-export' ); ?></label></th>
			<td>
				<input type="number" min="1" max="50" id="aouwal_batch_size" name="aouwal_settings[batch_size]" value="<?php echo esc_attr( $aouwal['batch_size'] ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'URLs processed per request. Lower this if your server times out during export.', 'aouwal-static-site-export' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
