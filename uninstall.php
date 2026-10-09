<?php
/**
 * Uninstall routine.
 *
 * @package WP_Static_Export
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wse_settings' );
delete_option( 'wse_jobs' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpstatic_job_items" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup of this plugin's own table.

$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$dir = trailingslashit( $uploads['basedir'] ) . 'wp-static-export';
	if ( is_dir( $dir ) ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() ) {
			global $wp_filesystem;
			if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
				$wp_filesystem->rmdir( $dir, true );
			}
		}
	}
}
