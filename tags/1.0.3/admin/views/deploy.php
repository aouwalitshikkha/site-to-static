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
?>
<div class="aouwal-deploy">
	<h2><?php esc_html_e( 'Your last export', 'aouwal-static-site-export' ); ?></h2>
	<?php if ( $aouwal_last && ! empty( $aouwal_last['zip_url'] ) ) : ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $aouwal_last['zip_url'] ); ?>" download>
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
			<p><strong><?php esc_html_e( 'Download and unzip the export.', 'aouwal-static-site-export' ); ?></strong></p>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Create a GitHub repository and push the files.', 'aouwal-static-site-export' ); ?></strong></p>
			<pre class="aouwal-code">cd aouwal-static-site-export-site
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
			<?php esc_html_e( 'The export includes a _redirects file (Cloudflare Pages) and a _headers file for caching. Query-string URLs such as /?p=123 cannot be redirected by Cloudflare Pages; use path-based URLs going forward.', 'aouwal-static-site-export' ); ?>
		</p>
	</div>
</div>
