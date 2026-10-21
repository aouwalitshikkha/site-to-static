<?php
/**
 * Deploy view.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$aouwal_jobs = get_option( AOUWAL_Exporter::JOBS_OPTION, array() );
$aouwal_last = null;
if ( is_array( $aouwal_jobs ) && ! empty( $aouwal_jobs ) ) {
	$aouwal_last = end( $aouwal_jobs );
}
$aouwal_download_url = '';
if ( $aouwal_last && ! empty( $aouwal_last['id'] ) ) {
	$aouwal_exporter     = new AOUWAL_Exporter();
	$aouwal_download_url = $aouwal_exporter->get_download_url( $aouwal_last['id'] );
}

$aouwal_uploads       = wp_upload_dir();
$aouwal_under_uploads = false;
$aouwal_export_rel    = '';
if ( empty( $aouwal_uploads['error'] ) && ! empty( $aouwal_uploads['baseurl'] ) ) {
	$aouwal_default_base = trailingslashit( $aouwal_uploads['basedir'] ) . 'aouwal-static-site-export';
	if ( untrailingslashit( AOUWAL_Exporter::base_dir() ) === untrailingslashit( $aouwal_default_base ) ) {
		$aouwal_under_uploads = true;
		$aouwal_export_rel    = trailingslashit( (string) wp_parse_url( $aouwal_uploads['baseurl'], PHP_URL_PATH ) ) . 'aouwal-static-site-export/';
	}
}
$aouwal_is_nginx = false !== stripos( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '', 'nginx' );
?>
<div class="aouwal-deploy">
	<h2><?php esc_html_e( 'Your last export', 'aouwal-static-site-export' ); ?></h2>
	<?php if ( $aouwal_download_url ) : ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $aouwal_download_url ); ?>" download>
				<?php esc_html_e( 'Download export ZIP', 'aouwal-static-site-export' ); ?>
			</a>
			<code><?php echo esc_html( $aouwal_last['id'] ); ?></code>
		</p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No export yet. Run an export from the Export tab first.', 'aouwal-static-site-export' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Deploy to Cloudflare Pages via GitHub', 'aouwal-static-site-export' ); ?></h2>
	<ol class="aouwal-steps">
		<li>
			<p><strong><?php esc_html_e( 'Download the export and unzip it into a new folder.', 'aouwal-static-site-export' ); ?></strong></p>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Create a GitHub repository and push the files.', 'aouwal-static-site-export' ); ?></strong></p>
			<pre class="aouwal-code"># from inside the folder where you unzipped the export
git init
git add .
git commit -m "Initial static export"
git branch -M main
git remote add origin https://github.com/USERNAME/REPO.git
git push -u origin main</pre>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Connect the repository to Cloudflare Pages.', 'aouwal-static-site-export' ); ?></strong></p>
			<ol class="aouwal-sublist">
				<li><?php esc_html_e( 'Open the Cloudflare dashboard → Workers &amp; Pages → Create → Pages → Connect to Git.', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Select your repository.', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Build command: leave blank.', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Build output directory: / (the repository root).', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Click Save and Deploy.', 'aouwal-static-site-export' ); ?></li>
			</ol>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Every future push redeploys automatically.', 'aouwal-static-site-export' ); ?></strong></p>
		</li>
	</ol>

	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'When enabled in Settings, the export includes a _redirects file (Cloudflare Pages) and a _headers file for caching. Query-string URLs such as /?p=123 cannot be redirected by Cloudflare Pages; use path-based URLs going forward.', 'aouwal-static-site-export' ); ?>
		</p>
	</div>

	<div class="notice notice-info inline">
		<?php if ( $aouwal_under_uploads ) : ?>
			<p>
				<?php esc_html_e( 'Export files are stored in a subdirectory of your uploads folder, protected from browsing with an index.php file and, on Apache, a .htaccess deny rule. The ZIP is downloadable only through the signed link above, which expires and stops working when the job is pruned.', 'aouwal-static-site-export' ); ?>
			</p>
			<?php if ( $aouwal_is_nginx ) : ?>
				<p>
					<?php esc_html_e( 'This server runs Nginx, which ignores .htaccess. To block direct access to the export folder, add this to your server block and reload Nginx, or move exports outside the web root with the aouwal_export_dir filter:', 'aouwal-static-site-export' ); ?>
				</p>
				<pre class="aouwal-code">location ^~ <?php echo esc_html( $aouwal_export_rel ); ?> {
	deny all;
}</pre>
			<?php endif; ?>
		<?php else : ?>
			<p>
				<?php esc_html_e( 'Export files are stored outside the web root, so they cannot be reached by any direct URL on this server. The ZIP is downloadable only through the signed link above, which expires and stops working when the job is pruned.', 'aouwal-static-site-export' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
