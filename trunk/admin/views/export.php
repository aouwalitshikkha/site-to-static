<?php
/**
 * Export view.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$aouwal_zip_available = AOUWAL_Zip::available();
?>
<div class="aouwal-export">
	<?php if ( ! $aouwal_zip_available ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'The PHP zip extension is not available. The export is disabled because Aouwal Static Site Export cannot package the exported files without it. Ask your host to enable the zip extension, then reload this page.', 'aouwal-static-site-export' ); ?></p>
		</div>
	<?php endif; ?>

	<p class="aouwal-intro">
		<?php esc_html_e( 'Generate a static copy of your site. The plugin crawls every page, downloads and localizes all assets, then packages everything into a ZIP you can push to GitHub and deploy on Cloudflare Pages.', 'aouwal-static-site-export' ); ?>
	</p>

	<p>
		<button type="button" class="button button-primary button-hero" id="aouwal-start" <?php disabled( ! $aouwal_zip_available ); ?>>
			<?php esc_html_e( 'Start static export', 'aouwal-static-site-export' ); ?>
		</button>
		<span class="spinner aouwal-spinner" id="aouwal-spinner"></span>
	</p>

	<div class="aouwal-progress" id="aouwal-progress" hidden>
		<div class="aouwal-bar"><span id="aouwal-bar-fill"></span></div>
		<div class="aouwal-stats">
			<span><strong><?php esc_html_e( 'Phase:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-phase">—</em></span>
			<span><strong><?php esc_html_e( 'Processed:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-processed">0</em></span>
			<span><strong><?php esc_html_e( 'Total:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-total">0</em></span>
			<span><strong><?php esc_html_e( 'Errors:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-errors">0</em></span>
		</div>
	</div>

	<div class="aouwal-result" id="aouwal-result" hidden></div>

	<h2><?php esc_html_e( 'Log', 'aouwal-static-site-export' ); ?></h2>
	<pre class="aouwal-log" id="aouwal-log"></pre>
</div>
