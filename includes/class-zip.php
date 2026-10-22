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
	 * ZipArchive and RecursiveDirectoryIterator have no WP_Filesystem
	 * equivalent: the extension writes the archive itself and the SPL
	 * iterator only enumerates paths. That is the one deliberate exception to
	 * the plugin's "all filesystem access goes through WP_Filesystem" rule.
	 * Both operate strictly inside $source_dir, which the caller has already
	 * validated with AOUWAL_Writer::is_within_output_dir(): every path added
	 * to the archive is derived from the iterator below, so nothing outside
	 * the job output directory can be packaged. The existing ZIP file, by
	 * contrast, is removed through WP_Filesystem like every other delete.
	 *
	 * @param string $source_dir Absolute source directory.
	 * @param string $zip_path   Absolute destination zip path.
	 * @return int|WP_Error Number of files added, or WP_Error.
	 */
	public function zip_dir( $source_dir, $zip_path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'aouwal_zip_missing', __( 'The PHP zip extension is not available.', 'aouwal-static-site-export' ) );
		}

		$source_dir = rtrim( $source_dir, '/\\' );
		if ( ! is_dir( $source_dir ) ) {
			return new WP_Error( 'aouwal_zip_nodir', __( 'Output directory not found.', 'aouwal-static-site-export' ) );
		}

		if ( file_exists( $zip_path ) ) {
			$fs = AOUWAL_Writer::filesystem_instance();
			if ( ! $fs || ! $fs->delete( $zip_path ) ) {
				return new WP_Error( 'aouwal_zip_old_file', __( 'Could not remove the previous ZIP file. Check the uploads directory permissions.', 'aouwal-static-site-export' ) );
			}
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'aouwal_zip_open', __( 'Could not create the ZIP archive.', 'aouwal-static-site-export' ) );
		}

		$count    = 0;
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
			++$count;
		}

		$zip->close();

		return $count;
	}
}
