<?php
/**
 * ZIP packaging.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Zip
 */
class AOUWAL_Zip {

	/**
	 * Whether ZipArchive is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Zip a directory.
	 *
	 * @param string $source_dir Absolute source directory.
	 * @param string $zip_path   Absolute destination zip path.
	 * @return int|WP_Error Number of files added, or WP_Error.
	 */
	public function zip_dir( $source_dir, $zip_path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'aouwal_zip_missing', __( 'The PHP zip extension is not available.', 'aouwal-static-site-export' ) );
		}

		$source_dir = rtrim( $source_dir, "/\\" );
		if ( ! is_dir( $source_dir ) ) {
			return new WP_Error( 'aouwal_zip_nodir', __( 'Output directory not found.', 'aouwal-static-site-export' ) );
		}

		if ( file_exists( $zip_path ) ) {
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'aouwal_zip_open', __( 'Could not create the ZIP archive.', 'aouwal-static-site-export' ) );
		}

		$count = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$pathname = $item->getPathname();
			$relative = str_replace( '\\', '/', substr( $pathname, strlen( $source_dir ) + 1 ) );

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $relative );
				continue;
			}

			$zip->addFile( $pathname, $relative );
			$count++;
		}

		$zip->close();

		return $count;
	}
}
