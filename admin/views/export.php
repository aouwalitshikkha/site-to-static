<?php
/**
 * Export view.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sts-export">
	<p class="sts-intro">
		<?php esc_html_e( 'Generate a static copy of your site. The plugin crawls every page, downloads and localizes all assets, then packages everything into a ZIP you can push to GitHub and deploy on Cloudflare Pages.', 'site-to-static' ); ?>
	</p>

	<p>
		<button type="button" class="button button-primary button-hero" id="sts-start">
			<?php esc_html_e( 'Start static export', 'site-to-static' ); ?>
		</button>
		<span class="spinner sts-spinner" id="sts-spinner"></span>
	</p>

	<div class="sts-progress" id="sts-progress" hidden>
		<div class="sts-bar"><span id="sts-bar-fill"></span></div>
		<div class="sts-stats">
			<span><strong><?php esc_html_e( 'Phase:', 'site-to-static' ); ?></strong> <em id="sts-phase">—</em></span>
			<span><strong><?php esc_html_e( 'Processed:', 'site-to-static' ); ?></strong> <em id="sts-processed">0</em></span>
			<span><strong><?php esc_html_e( 'Total:', 'site-to-static' ); ?></strong> <em id="sts-total">0</em></span>
			<span><strong><?php esc_html_e( 'Errors:', 'site-to-static' ); ?></strong> <em id="sts-errors">0</em></span>
		</div>
	</div>

	<div class="sts-result" id="sts-result" hidden></div>

	<h2><?php esc_html_e( 'Log', 'site-to-static' ); ?></h2>
	<pre class="sts-log" id="sts-log"></pre>
</div>
