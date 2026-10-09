<?php
/**
 * Settings view.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$sts = STS_Settings::all();

$sts_post_types = get_post_types( array( 'public' => true ), 'objects' );
unset( $sts_post_types['attachment'] );
?>
<form method="post" action="options.php" class="sts-settings">
	<?php settings_fields( 'sts_settings_group' ); ?>

	<h2><?php esc_html_e( 'Content', 'site-to-static' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Post types', 'site-to-static' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( $sts_post_types as $sts_pt ) : ?>
						<label class="sts-inline">
							<input type="checkbox" name="sts_settings[post_types][]" value="<?php echo esc_attr( $sts_pt->name ); ?>" <?php checked( in_array( $sts_pt->name, (array) $sts['post_types'], true ) ); ?> />
							<?php echo esc_html( $sts_pt->labels->name ); ?> <code><?php echo esc_html( $sts_pt->name ); ?></code>
						</label><br />
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Archives', 'site-to-static' ); ?></th>
			<td>
				<fieldset>
					<label class="sts-inline"><input type="checkbox" name="sts_settings[include_taxonomies]" value="1" <?php checked( $sts['include_taxonomies'] ); ?> /> <?php esc_html_e( 'Category / tag / taxonomy archives', 'site-to-static' ); ?></label><br />
					<label class="sts-inline"><input type="checkbox" name="sts_settings[include_authors]" value="1" <?php checked( $sts['include_authors'] ); ?> /> <?php esc_html_e( 'Author archives', 'site-to-static' ); ?></label><br />
					<label class="sts-inline"><input type="checkbox" name="sts_settings[include_dates]" value="1" <?php checked( $sts['include_dates'] ); ?> /> <?php esc_html_e( 'Date archives (year / month)', 'site-to-static' ); ?></label><br />
					<label class="sts-inline"><input type="checkbox" name="sts_settings[include_feeds]" value="1" <?php checked( $sts['include_feeds'] ); ?> /> <?php esc_html_e( 'RSS feeds', 'site-to-static' ); ?></label><br />
					<label class="sts-inline"><input type="checkbox" name="sts_settings[include_attachments]" value="1" <?php checked( $sts['include_attachments'] ); ?> /> <?php esc_html_e( 'Attachment pages', 'site-to-static' ); ?></label>
				</fieldset>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Assets &amp; URLs', 'site-to-static' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Asset handling', 'site-to-static' ); ?></th>
			<td>
				<label class="sts-inline"><input type="checkbox" name="sts_settings[download_assets]" value="1" <?php checked( $sts['download_assets'] ); ?> /> <?php esc_html_e( 'Download &amp; localize all assets (CSS, JS, images, fonts)', 'site-to-static' ); ?></label><br />
				<label class="sts-inline"><input type="checkbox" name="sts_settings[strip_query]" value="1" <?php checked( $sts['strip_query'] ); ?> /> <?php esc_html_e( 'Strip cache-busting query strings (e.g. ?ver=1.2)', 'site-to-static' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'URL style', 'site-to-static' ); ?></th>
			<td>
				<fieldset>
					<label class="sts-inline"><input type="radio" name="sts_settings[url_style]" value="relative" <?php checked( $sts['url_style'], 'relative' ); ?> /> <?php esc_html_e( 'Relative (portable, works in a subdirectory)', 'site-to-static' ); ?></label><br />
					<label class="sts-inline"><input type="radio" name="sts_settings[url_style]" value="root" <?php checked( $sts['url_style'], 'root' ); ?> /> <?php esc_html_e( 'Root-absolute (/, best for Cloudflare Pages root domain)', 'site-to-static' ); ?></label>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sts_public_url"><?php esc_html_e( 'Public site URL', 'site-to-static' ); ?></label></th>
			<td>
				<input type="url" class="regular-text" id="sts_public_url" name="sts_settings[public_url]" value="<?php echo esc_attr( $sts['public_url'] ); ?>" placeholder="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Where the static site will live (used for sitemap.xml). Leave blank to use the current site URL.', 'site-to-static' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sts_additional_urls"><?php esc_html_e( 'Additional URLs', 'site-to-static' ); ?></label></th>
			<td>
				<textarea class="large-text code" rows="3" id="sts_additional_urls" name="sts_settings[additional_urls]"><?php echo esc_textarea( $sts['additional_urls'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One URL per line. These will be exported in addition to discovered content.', 'site-to-static' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sts_exclude_urls"><?php esc_html_e( 'Exclude URLs', 'site-to-static' ); ?></label></th>
			<td>
				<textarea class="large-text code" rows="3" id="sts_exclude_urls" name="sts_settings[exclude_urls]"><?php echo esc_textarea( $sts['exclude_urls'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One pattern per line. Plain text matches a substring; wrap in slashes (/regex/) for a regular expression.', 'site-to-static' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Cleanup &amp; output', 'site-to-static' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Remove dynamic features', 'site-to-static' ); ?></th>
			<td>
				<label class="sts-inline"><input type="checkbox" name="sts_settings[strip_comments]" value="1" <?php checked( $sts['strip_comments'] ); ?> /> <?php esc_html_e( 'Remove comment forms and comment sections', 'site-to-static' ); ?></label><br />
				<label class="sts-inline"><input type="checkbox" name="sts_settings[strip_search]" value="1" <?php checked( $sts['strip_search'] ); ?> /> <?php esc_html_e( 'Remove search forms', 'site-to-static' ); ?></label>
				<p class="description"><?php esc_html_e( 'Server-side search and comments cannot work on a static host, so they are removed.', 'site-to-static' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Generated files', 'site-to-static' ); ?></th>
			<td>
				<label class="sts-inline"><input type="checkbox" name="sts_settings[generate_sitemap]" value="1" <?php checked( $sts['generate_sitemap'] ); ?> /> <?php esc_html_e( 'sitemap.xml', 'site-to-static' ); ?></label><br />
				<label class="sts-inline"><input type="checkbox" name="sts_settings[generate_robots]" value="1" <?php checked( $sts['generate_robots'] ); ?> /> <?php esc_html_e( 'robots.txt', 'site-to-static' ); ?></label><br />
				<label class="sts-inline"><input type="checkbox" name="sts_settings[preserve_redirects]" value="1" <?php checked( $sts['preserve_redirects'] ); ?> /> <?php esc_html_e( 'Cloudflare Pages _redirects file', 'site-to-static' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Attribution', 'site-to-static' ); ?></th>
			<td>
				<label class="sts-inline"><input type="checkbox" name="sts_settings[show_credit]" value="1" <?php checked( $sts['show_credit'] ); ?> /> <?php esc_html_e( 'Include an invisible author credit comment in exported files', 'site-to-static' ); ?></label>
				<p class="description"><?php esc_html_e( 'Optional and off by default. Adds a source-code-only credit for Site to Static. It never appears in the browser.', 'site-to-static' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sts_batch_size"><?php esc_html_e( 'Batch size', 'site-to-static' ); ?></label></th>
			<td>
				<input type="number" min="1" max="50" id="sts_batch_size" name="sts_settings[batch_size]" value="<?php echo esc_attr( $sts['batch_size'] ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'URLs processed per request. Lower this if your server times out during export.', 'site-to-static' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button(); ?>
</form>
