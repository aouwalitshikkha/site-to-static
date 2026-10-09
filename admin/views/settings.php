<?php
/**
 * Settings view.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$wse = WSE_Settings::all();

$wse_post_types = get_post_types( array( 'public' => true ), 'objects' );
unset( $wse_post_types['attachment'] );
?>
<form method="post" action="options.php" class="wse-settings">
	<?php settings_fields( 'wse_settings_group' ); ?>

	<h2><?php esc_html_e( 'Content', 'wp-static-export' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Post types', 'wp-static-export' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( $wse_post_types as $wse_pt ) : ?>
						<label class="wse-inline">
							<input type="checkbox" name="wse_settings[post_types][]" value="<?php echo esc_attr( $wse_pt->name ); ?>" <?php checked( in_array( $wse_pt->name, (array) $wse['post_types'], true ) ); ?> />
							<?php echo esc_html( $wse_pt->labels->name ); ?> <code><?php echo esc_html( $wse_pt->name ); ?></code>
						</label><br />
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Archives', 'wp-static-export' ); ?></th>
			<td>
				<fieldset>
					<label class="wse-inline"><input type="checkbox" name="wse_settings[include_taxonomies]" value="1" <?php checked( $wse['include_taxonomies'] ); ?> /> <?php esc_html_e( 'Category / tag / taxonomy archives', 'wp-static-export' ); ?></label><br />
					<label class="wse-inline"><input type="checkbox" name="wse_settings[include_authors]" value="1" <?php checked( $wse['include_authors'] ); ?> /> <?php esc_html_e( 'Author archives', 'wp-static-export' ); ?></label><br />
					<label class="wse-inline"><input type="checkbox" name="wse_settings[include_dates]" value="1" <?php checked( $wse['include_dates'] ); ?> /> <?php esc_html_e( 'Date archives (year / month)', 'wp-static-export' ); ?></label><br />
					<label class="wse-inline"><input type="checkbox" name="wse_settings[include_feeds]" value="1" <?php checked( $wse['include_feeds'] ); ?> /> <?php esc_html_e( 'RSS feeds', 'wp-static-export' ); ?></label><br />
					<label class="wse-inline"><input type="checkbox" name="wse_settings[include_attachments]" value="1" <?php checked( $wse['include_attachments'] ); ?> /> <?php esc_html_e( 'Attachment pages', 'wp-static-export' ); ?></label>
				</fieldset>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Assets &amp; URLs', 'wp-static-export' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Asset handling', 'wp-static-export' ); ?></th>
			<td>
				<label class="wse-inline"><input type="checkbox" name="wse_settings[download_assets]" value="1" <?php checked( $wse['download_assets'] ); ?> /> <?php esc_html_e( 'Download &amp; localize all assets (CSS, JS, images, fonts)', 'wp-static-export' ); ?></label><br />
				<label class="wse-inline"><input type="checkbox" name="wse_settings[strip_query]" value="1" <?php checked( $wse['strip_query'] ); ?> /> <?php esc_html_e( 'Strip cache-busting query strings (e.g. ?ver=1.2)', 'wp-static-export' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'URL style', 'wp-static-export' ); ?></th>
			<td>
				<fieldset>
					<label class="wse-inline"><input type="radio" name="wse_settings[url_style]" value="relative" <?php checked( $wse['url_style'], 'relative' ); ?> /> <?php esc_html_e( 'Relative (portable, works in a subdirectory)', 'wp-static-export' ); ?></label><br />
					<label class="wse-inline"><input type="radio" name="wse_settings[url_style]" value="root" <?php checked( $wse['url_style'], 'root' ); ?> /> <?php esc_html_e( 'Root-absolute (/, best for Cloudflare Pages root domain)', 'wp-static-export' ); ?></label>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wse_public_url"><?php esc_html_e( 'Public site URL', 'wp-static-export' ); ?></label></th>
			<td>
				<input type="url" class="regular-text" id="wse_public_url" name="wse_settings[public_url]" value="<?php echo esc_attr( $wse['public_url'] ); ?>" placeholder="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Where the static site will live (used for sitemap.xml). Leave blank to use the current site URL.', 'wp-static-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wse_additional_urls"><?php esc_html_e( 'Additional URLs', 'wp-static-export' ); ?></label></th>
			<td>
				<textarea class="large-text code" rows="3" id="wse_additional_urls" name="wse_settings[additional_urls]"><?php echo esc_textarea( $wse['additional_urls'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One URL per line. These will be exported in addition to discovered content.', 'wp-static-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wse_exclude_urls"><?php esc_html_e( 'Exclude URLs', 'wp-static-export' ); ?></label></th>
			<td>
				<textarea class="large-text code" rows="3" id="wse_exclude_urls" name="wse_settings[exclude_urls]"><?php echo esc_textarea( $wse['exclude_urls'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One pattern per line. Plain text matches a substring; wrap in slashes (/regex/) for a regular expression.', 'wp-static-export' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Cleanup &amp; output', 'wp-static-export' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Remove dynamic features', 'wp-static-export' ); ?></th>
			<td>
				<label class="wse-inline"><input type="checkbox" name="wse_settings[strip_comments]" value="1" <?php checked( $wse['strip_comments'] ); ?> /> <?php esc_html_e( 'Remove comment forms and comment sections', 'wp-static-export' ); ?></label><br />
				<label class="wse-inline"><input type="checkbox" name="wse_settings[strip_search]" value="1" <?php checked( $wse['strip_search'] ); ?> /> <?php esc_html_e( 'Remove search forms', 'wp-static-export' ); ?></label>
				<p class="description"><?php esc_html_e( 'Server-side search and comments cannot work on a static host, so they are removed.', 'wp-static-export' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Generated files', 'wp-static-export' ); ?></th>
			<td>
				<label class="wse-inline"><input type="checkbox" name="wse_settings[generate_sitemap]" value="1" <?php checked( $wse['generate_sitemap'] ); ?> /> <?php esc_html_e( 'sitemap.xml', 'wp-static-export' ); ?></label><br />
				<label class="wse-inline"><input type="checkbox" name="wse_settings[generate_robots]" value="1" <?php checked( $wse['generate_robots'] ); ?> /> <?php esc_html_e( 'robots.txt', 'wp-static-export' ); ?></label><br />
				<label class="wse-inline"><input type="checkbox" name="wse_settings[preserve_redirects]" value="1" <?php checked( $wse['preserve_redirects'] ); ?> /> <?php esc_html_e( 'Cloudflare Pages _redirects file', 'wp-static-export' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wse_batch_size"><?php esc_html_e( 'Batch size', 'wp-static-export' ); ?></label></th>
			<td>
				<input type="number" min="1" max="50" id="wse_batch_size" name="wse_settings[batch_size]" value="<?php echo esc_attr( $wse['batch_size'] ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'URLs processed per request. Lower this if your server times out during export.', 'wp-static-export' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
