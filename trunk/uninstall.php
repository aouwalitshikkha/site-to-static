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
if ( empty( $aouwal_uploads['error'] ) && ! empty( $aouwal_uploads['basedir'] ) ) {
	$aouwal_default = trailingslashit( $aouwal_uploads['basedir'] ) . 'aouwal-static-site-export';

	$aouwal_dirs = array( $aouwal_default );

	$aouwal_filtered = apply_filters( 'aouwal_export_dir', $aouwal_default );
	if ( is_string( $aouwal_filtered ) && '' !== trim( $aouwal_filtered ) ) {
		$aouwal_filtered = untrailingslashit( $aouwal_filtered );
		if ( untrailingslashit( $aouwal_default ) !== $aouwal_filtered ) {
			$aouwal_dirs[] = $aouwal_filtered;
		}
	}

	$aouwal_dirs[] = trailingslashit( $aouwal_uploads['basedir'] ) . 'site-to-static';

	$aouwal_roots = array( ABSPATH, WP_CONTENT_DIR, trailingslashit( dirname( ABSPATH ) ) );
	$aouwal_roots = array_map( 'wp_normalize_path', array_filter( $aouwal_roots ) );

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() ) {
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			foreach ( $aouwal_dirs as $aouwal_dir ) {
				$aouwal_dir = untrailingslashit( wp_normalize_path( $aouwal_dir ) );
				if ( '' === $aouwal_dir || '/' === $aouwal_dir ) {
					continue;
				}
				$aouwal_safe = true;
				foreach ( $aouwal_roots as $aouwal_root ) {
					$aouwal_root = untrailingslashit( $aouwal_root );
					if ( '' === $aouwal_root ) {
						continue;
					}
					if ( $aouwal_dir === $aouwal_root || 0 === strpos( $aouwal_root . '/', $aouwal_dir . '/' ) ) {
						$aouwal_safe = false;
						break;
					}
				}
				if ( ! $aouwal_safe ) {
					continue;
				}
				if ( is_dir( $aouwal_dir ) ) {
					$wp_filesystem->rmdir( $aouwal_dir, true );
				}
			}
		}
	}
}
