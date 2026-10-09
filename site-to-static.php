<?php
/**
 * Plugin Name:       Site to Static
 * Plugin URI:        https://github.com/aouwalitshikkha/site-to-static
 * Description:       Export your WordPress site to a fully static HTML bundle (with localized assets) ready to push to GitHub and deploy on Cloudflare Pages.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Abdul Aouwal
 * Author URI:        https://abdulaouwal.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-to-static
 * Domain Path:       /languages
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

define( 'STS_VERSION', '1.0.0' );
define( 'STS_PLUGIN_FILE', __FILE__ );
define( 'STS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Note: the GitHub distribution defines STS_FORCE_CREDIT as true so the
// export credit is always included. This WordPress.org build intentionally
// omits it, so the credit follows the opt-in "show_credit" setting
// (default off) instead.

require_once STS_PLUGIN_DIR . 'includes/class-brand.php';
require_once STS_PLUGIN_DIR . 'includes/class-settings.php';
require_once STS_PLUGIN_DIR . 'includes/class-writer.php';
require_once STS_PLUGIN_DIR . 'includes/class-url-discovery.php';
require_once STS_PLUGIN_DIR . 'includes/class-crawler.php';
require_once STS_PLUGIN_DIR . 'includes/class-html-processor.php';
require_once STS_PLUGIN_DIR . 'includes/class-asset-processor.php';
require_once STS_PLUGIN_DIR . 'includes/class-zip.php';
require_once STS_PLUGIN_DIR . 'includes/class-exporter.php';

if ( is_admin() ) {
	require_once STS_PLUGIN_DIR . 'admin/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once STS_PLUGIN_DIR . 'cli/class-cli.php';
}

/**
 * Activation: create the job items table.
 *
 * @return void
 */
function sts_activate() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'sts_job_items';
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

	if ( false === get_option( 'sts_settings', false ) ) {
		add_option( 'sts_settings', STS_Settings::defaults() );
	}

	sts_remove_legacy_data();
}
register_activation_hook( __FILE__, 'sts_activate' );

/**
 * Remove data left by the pre-release "WP Static Export" (wse_) builds.
 *
 * @return void
 */
function sts_remove_legacy_data() {
	global $wpdb;

	delete_option( 'wse_settings' );
	delete_option( 'wse_jobs' );

	$legacy = $wpdb->prefix . 'wpstatic_job_items';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time legacy cleanup of this plugin's own pre-release table; table name is $wpdb->prefix plus a hardcoded string.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) === $legacy ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time legacy cleanup; identifiers cannot use placeholders.
	}
}

/**
 * Deactivation: clear scheduled events.
 *
 * @return void
 */
function sts_deactivate() {
	$timestamp = wp_next_scheduled( 'sts_cron_export' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'sts_cron_export' );
	}
}
register_deactivation_hook( __FILE__, 'sts_deactivate' );
