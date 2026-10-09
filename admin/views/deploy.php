<?php
/**
 * Deploy view.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$sts_jobs = get_option( STS_Exporter::JOBS_OPTION, array() );
$sts_last = null;
if ( is_array( $sts_jobs ) && ! empty( $sts_jobs ) ) {
	$sts_last = end( $sts_jobs );
}
?>
<div class="sts-deploy">
	<h2><?php esc_html_e( 'Your last export', 'site-to-static' ); ?></h2>
	<?php if ( $sts_last && ! empty( $sts_last['zip_url'] ) ) : ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $sts_last['zip_url'] ); ?>" download>
				<?php esc_html_e( 'Download export ZIP', 'site-to-static' ); ?>
			</a>
			<code><?php echo esc_html( $sts_last['id'] ); ?></code>
		</p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No export yet. Run an export from the Export tab first.', 'site-to-static' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Deploy to Cloudflare Pages via GitHub', 'site-to-static' ); ?></h2>
	<ol class="sts-steps">
		<li>
			<p><strong><?php esc_html_e( 'Download and unzip the export.', 'site-to-static' ); ?></strong></p>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Create a GitHub repository and push the files.', 'site-to-static' ); ?></strong></p>
			<pre class="sts-code">cd site-to-static-site
git init
git add .
git commit -m "Initial static export"
git branch -M main
git remote add origin https://github.com/USERNAME/REPO.git
git push -u origin main</pre>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Connect the repository to Cloudflare Pages.', 'site-to-static' ); ?></strong></p>
			<ol class="sts-sublist">
				<li><?php esc_html_e( 'Open the Cloudflare dashboard → Workers &amp; Pages → Create → Pages → Connect to Git.', 'site-to-static' ); ?></li>
				<li><?php esc_html_e( 'Select your repository.', 'site-to-static' ); ?></li>
				<li><?php esc_html_e( 'Build command: leave blank.', 'site-to-static' ); ?></li>
				<li><?php esc_html_e( 'Build output directory: / (the repository root).', 'site-to-static' ); ?></li>
				<li><?php esc_html_e( 'Click Save and Deploy.', 'site-to-static' ); ?></li>
			</ol>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Every future push redeploys automatically.', 'site-to-static' ); ?></strong></p>
		</li>
	</ol>

	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'The export includes a _redirects file (Cloudflare Pages) and a _headers file for caching. Query-string URLs such as /?p=123 cannot be redirected by Cloudflare Pages; use path-based URLs going forward.', 'site-to-static' ); ?>
		</p>
	</div>
</div>
