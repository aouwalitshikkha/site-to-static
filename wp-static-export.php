<?php
/**
 * Plugin Name:       WP Static Export
 * Plugin URI:        https://github.com/aouwalitshikkha/wp-static-export
 * Description:       Export your WordPress site to a fully static HTML bundle (with localized assets) ready to push to GitHub and deploy on Cloudflare Pages.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Abdul Aouwal
 * Author URI:        https://abdulaouwal.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-static-export
 * Domain Path:       /languages
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

define( 'WSE_VERSION', '1.0.0' );
define( 'WSE_PLUGIN_FILE', __FILE__ );
define( 'WSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WSE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Note: the GitHub distribution defines WSE_FORCE_CREDIT as true so the
// export credit is always included. This WordPress.org build intentionally
// omits it, so the credit follows the opt-in "show_credit" setting
// (default off) instead.

require_once WSE_PLUGIN_DIR . 'includes/class-brand.php';
require_once WSE_PLUGIN_DIR . 'includes/class-plugin.php';
require_once WSE_PLUGIN_DIR . 'includes/class-settings.php';
require_once WSE_PLUGIN_DIR . 'includes/class-writer.php';
require_once WSE_PLUGIN_DIR . 'includes/class-url-discovery.php';
require_once WSE_PLUGIN_DIR . 'includes/class-crawler.php';
require_once WSE_PLUGIN_DIR . 'includes/class-html-processor.php';
require_once WSE_PLUGIN_DIR . 'includes/class-asset-processor.php';
require_once WSE_PLUGIN_DIR . 'includes/class-zip.php';
require_once WSE_PLUGIN_DIR . 'includes/class-exporter.php';

if ( is_admin() ) {
	require_once WSE_PLUGIN_DIR . 'admin/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WSE_PLUGIN_DIR . 'cli/class-cli.php';
}

/**
 * Activation: create the job items table.
 *
 * @return void
 */
function wse_activate() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'wpstatic_job_items';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		job_id VARCHAR(32) NOT NULL,
		item_type VARCHAR(10) NOT NULL DEFAULT 'page',
		source_url TEXT NOT NULL,
		url_hash CHAR(32) NOT NULL,
		local_path TEXT NOT NULL,
		status VARCHAR(10) NOT NULL DEFAULT 'pending',
		http_status SMALLINT NOT NULL DEFAULT 0,
		message TEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY job_status (job_id, status),
		UNIQUE KEY job_url (job_id, url_hash)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	if ( false === get_option( 'wse_settings', false ) ) {
		add_option( 'wse_settings', WSE_Settings::defaults() );
	}
}
register_activation_hook( __FILE__, 'wse_activate' );

/**
 * Deactivation: clear scheduled events.
 *
 * @return void
 */
function wse_deactivate() {
	$timestamp = wp_next_scheduled( 'wse_cron_export' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wse_cron_export' );
	}
}
register_deactivation_hook( __FILE__, 'wse_deactivate' );

WSE_Plugin::instance();
