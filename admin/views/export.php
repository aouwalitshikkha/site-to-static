<?php
/**
 * Export view.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wse-export">
	<p class="wse-intro">
		<?php esc_html_e( 'Generate a static copy of your site. The plugin crawls every page, downloads and localizes all assets, then packages everything into a ZIP you can push to GitHub and deploy on Cloudflare Pages.', 'wp-static-export' ); ?>
	</p>

	<p>
		<button type="button" class="button button-primary button-hero" id="wse-start">
			<?php esc_html_e( 'Start static export', 'wp-static-export' ); ?>
		</button>
		<span class="spinner wse-spinner" id="wse-spinner"></span>
	</p>

	<div class="wse-progress" id="wse-progress" hidden>
		<div class="wse-bar"><span id="wse-bar-fill"></span></div>
		<div class="wse-stats">
			<span><strong><?php esc_html_e( 'Phase:', 'wp-static-export' ); ?></strong> <em id="wse-phase">—</em></span>
			<span><strong><?php esc_html_e( 'Processed:', 'wp-static-export' ); ?></strong> <em id="wse-processed">0</em></span>
			<span><strong><?php esc_html_e( 'Total:', 'wp-static-export' ); ?></strong> <em id="wse-total">0</em></span>
			<span><strong><?php esc_html_e( 'Errors:', 'wp-static-export' ); ?></strong> <em id="wse-errors">0</em></span>
		</div>
	</div>

	<div class="wse-result" id="wse-result" hidden></div>

	<h2><?php esc_html_e( 'Log', 'wp-static-export' ); ?></h2>
	<pre class="wse-log" id="wse-log"></pre>
</div>
