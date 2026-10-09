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

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpstatic_job_items" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$dir = trailingslashit( $uploads['basedir'] ) . 'wp-static-export';
	if ( is_dir( $dir ) ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$removed = false;
		if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() ) {
			global $wp_filesystem;
			if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
				$removed = (bool) $wp_filesystem->rmdir( $dir, true );
			}
		}
		if ( ! $removed ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( $item->isDir() ) {
					@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fallback when WP_Filesystem is unavailable.
				} else {
					@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- fallback when WP_Filesystem is unavailable.
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fallback when WP_Filesystem is unavailable.
		}
	}
}
