<?php
/**
 * Deploy view.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$wse_jobs = get_option( WSE_Exporter::JOBS_OPTION, array() );
$wse_last = null;
if ( is_array( $wse_jobs ) && ! empty( $wse_jobs ) ) {
	$wse_last = end( $wse_jobs );
}
?>
<div class="wse-deploy">
	<h2><?php esc_html_e( 'Your last export', 'wp-static-export' ); ?></h2>
	<?php if ( $wse_last && ! empty( $wse_last['zip_url'] ) ) : ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $wse_last['zip_url'] ); ?>" download>
				<?php esc_html_e( 'Download export ZIP', 'wp-static-export' ); ?>
			</a>
			<code><?php echo esc_html( $wse_last['id'] ); ?></code>
		</p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No export yet. Run an export from the Export tab first.', 'wp-static-export' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Deploy to Cloudflare Pages via GitHub', 'wp-static-export' ); ?></h2>
	<ol class="wse-steps">
		<li>
			<p><strong><?php esc_html_e( 'Download and unzip the export.', 'wp-static-export' ); ?></strong></p>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Create a GitHub repository and push the files.', 'wp-static-export' ); ?></strong></p>
			<pre class="wse-code">cd wp-static-export-site
git init
git add .
git commit -m "Initial static export"
git branch -M main
git remote add origin https://github.com/USERNAME/REPO.git
git push -u origin main</pre>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Connect the repository to Cloudflare Pages.', 'wp-static-export' ); ?></strong></p>
			<ol class="wse-sublist">
				<li><?php esc_html_e( 'Open the Cloudflare dashboard → Workers &amp; Pages → Create → Pages → Connect to Git.', 'wp-static-export' ); ?></li>
				<li><?php esc_html_e( 'Select your repository.', 'wp-static-export' ); ?></li>
				<li><?php esc_html_e( 'Build command: leave blank.', 'wp-static-export' ); ?></li>
				<li><?php esc_html_e( 'Build output directory: / (the repository root).', 'wp-static-export' ); ?></li>
				<li><?php esc_html_e( 'Click Save and Deploy.', 'wp-static-export' ); ?></li>
			</ol>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Every future push redeploys automatically.', 'wp-static-export' ); ?></strong></p>
		</li>
	</ol>

	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'The export includes a _redirects file (Cloudflare Pages) and a _headers file for caching. Query-string URLs such as /?p=123 cannot be redirected by Cloudflare Pages; use path-based URLs going forward.', 'wp-static-export' ); ?>
		</p>
	</div>
</div>
