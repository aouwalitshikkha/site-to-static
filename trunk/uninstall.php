<?php
/**
 * Uninstall routine.
 *
 * @package Aouwal_Static_Export
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'aouwal_settings' );
delete_option( 'aouwal_jobs' );
delete_option( 'sts_settings' );
delete_option( 'sts_jobs' );
delete_option( 'wse_settings' );
delete_option( 'wse_jobs' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aouwal_job_items" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup of this plugin's own table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sts_job_items" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup of this plugin's previous slug table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpstatic_job_items" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup of this plugin's pre-release table.

$aouwal_uploads = wp_upload_dir();
if ( empty( $aouwal_uploads['error'] ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() ) {
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			foreach ( array( 'aouwal-static-site-export', 'site-to-static' ) as $aouwal_folder ) {
				$aouwal_dir = trailingslashit( $aouwal_uploads['basedir'] ) . $aouwal_folder;
				if ( is_dir( $aouwal_dir ) ) {
					$wp_filesystem->rmdir( $aouwal_dir, true );
				}
			}
		}
	}
}
