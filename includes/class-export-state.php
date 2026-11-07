<?php
/**
 * Cross-run export state.
 *
 * Stores one fingerprint per page and per asset so a changed-only export can
 * skip work that is already in the output. Kept out of the job queue on
 * purpose: queue rows are per job and pruned, while these rows must outlive
 * several runs.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Export_State
 */
class AOUWAL_Export_State {

	/**
	 * Option holding the last reusable export (the copy-forward baseline).
	 *
	 * @var string
	 */
	const OPTION_BASELINE = 'aouwal_export_baseline';

	/**
	 * State table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aouwal_export_state';
	}

	/**
	 * Unique key for a page or asset URL.
	 *
	 * The type is part of the hash so a URL can be tracked both as a page and
	 * as an asset without the two rows colliding.
	 *
	 * @param string $type "page" or "asset".
	 * @param string $url  URL.
	 * @return string
	 */
	public static function hash( $type, $url ) {
		return md5( $type . '|' . (string) $url );
	}

	/**
	 * Read one row.
	 *
	 * @param string $type "page" or "asset".
	 * @param string $url  URL.
	 * @return object|null
	 */
	public static function get( $type, $url ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom state table ($wpdb->prefix + static name); one indexed lookup per item. Values are prepared.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE url_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::hash( $type, $url )
			)
		);
	}

	/**
	 * Read many rows of one type, keyed by URL.
	 *
	 * @param string   $type "page" or "asset".
	 * @param string[] $urls URLs.
	 * @return array<string,object>
	 */
	public static function get_many( $type, array $urls ) {
		global $wpdb;
		$table = self::table();
		$out   = array();

		$map = array();
		foreach ( $urls as $url ) {
			$map[ self::hash( $type, $url ) ] = (string) $url;
		}
		if ( empty( $map ) ) {
			return $out;
		}

		foreach ( array_chunk( array_keys( $map ), 400 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom state table; chunked indexed read for one export run. Values are prepared.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE url_hash IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated from the chunk size; values are prepared.
					$chunk
				)
			);
			foreach ( (array) $rows as $row ) {
				if ( isset( $map[ $row->url_hash ] ) ) {
					$out[ $map[ $row->url_hash ] ] = $row;
				}
			}
		}

		return $out;
	}

	/**
	 * Insert or update one row.
	 *
	 * @param string $job_id     Job that produced the row.
	 * @param string $type       "page" or "asset".
	 * @param string $url        URL.
	 * @param string $local_path Output-relative path.
	 * @param array  $data       fingerprint, etag, last_modified, bytes.
	 * @return void
	 */
	public static function save( $job_id, $type, $url, $local_path, array $data = array() ) {
		global $wpdb;

		$row = array(
			'url_hash'      => self::hash( $type, $url ),
			'job_id'        => (string) $job_id,
			'item_type'     => (string) $type,
			'source_url'    => (string) $url,
			'local_path'    => (string) $local_path,
			'fingerprint'   => isset( $data['fingerprint'] ) ? substr( (string) $data['fingerprint'], 0, 64 ) : '',
			'etag'          => isset( $data['etag'] ) ? substr( (string) $data['etag'], 0, 255 ) : '',
			'last_modified' => isset( $data['last_modified'] ) ? substr( (string) $data['last_modified'], 0, 64 ) : '',
			'bytes'         => isset( $data['bytes'] ) ? max( 0, (int) $data['bytes'] ) : 0,
			'updated_at'    => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom state table; REPLACE() escapes values via the format array. No caching applies to writes.
		$wpdb->replace(
			self::table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Rows last confirmed by a job.
	 *
	 * @param string $job_id Job ID.
	 * @return object[]
	 */
	public static function rows_for_job( $job_id ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom state table; one indexed read per export run. Values are prepared.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_hash, source_url, local_path, item_type, etag, last_modified FROM {$table} WHERE job_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);
	}

	/**
	 * Drop rows by hash.
	 *
	 * @param string[] $hashes Row hashes.
	 * @return void
	 */
	public static function delete( array $hashes ) {
		global $wpdb;
		$table = self::table();

		foreach ( array_chunk( array_filter( $hashes ), 400 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom state table; chunked delete of this plugin's own rows. Values are prepared.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE url_hash IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated from the chunk size; values are prepared.
					$chunk
				)
			);
		}
	}

	/**
	 * Move the rows confirmed by this job from the baseline job to the new one.
	 *
	 * Only rows that the new job actually produced (done or skipped) move, so
	 * a failed URL keeps its old fingerprint and is retried next time.
	 *
	 * @param string $job_id     New job ID.
	 * @param string $items_table Job items table name.
	 * @return void
	 */
	public static function adopt( $job_id, $items_table ) {
		global $wpdb;
		$state = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom tables; single set-based update instead of one query per item. Values are prepared.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$state} SET job_id = %s, updated_at = %s WHERE url_hash IN (SELECT url_hash FROM {$items_table} WHERE job_id = %s AND status IN ('done', 'skipped'))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				current_time( 'mysql' ),
				$job_id
			)
		);
	}

	/**
	 * Fingerprint of everything that makes an existing export stale.
	 *
	 * When any of these change, a changed-only run cannot trust the previous
	 * files and falls back to a full export: a different plugin version may
	 * render different markup, a new theme or permalink structure moves every
	 * path, and so on.
	 *
	 * @return string
	 */
	public static function signature() {
		$theme = wp_get_theme();

		$parts = array(
			'plugin'    => AOUWAL_VERSION,
			'wp'        => (string) get_bloginfo( 'version' ),
			'theme'     => get_stylesheet() . ':' . (string) $theme->get( 'Version' ),
			'home'      => home_url( '/' ),
			'site'      => site_url( '/' ),
			'permalink' => (string) get_option( 'permalink_structure' ),
			'front'     => implode(
				':',
				array(
					(string) get_option( 'show_on_front' ),
					(string) get_option( 'page_on_front' ),
					(string) get_option( 'page_for_posts' ),
				)
			),
			'settings'  => array(
				'post_types'           => array_values( (array) AOUWAL_Settings::get( 'post_types', array() ) ),
				'include_taxonomies'   => (int) AOUWAL_Settings::get( 'include_taxonomies' ),
				'include_authors'      => (int) AOUWAL_Settings::get( 'include_authors' ),
				'include_dates'        => (int) AOUWAL_Settings::get( 'include_dates' ),
				'include_feeds'        => (int) AOUWAL_Settings::get( 'include_feeds' ),
				'include_attachments'  => (int) AOUWAL_Settings::get( 'include_attachments' ),
				'download_assets'      => (int) AOUWAL_Settings::get( 'download_assets' ),
				'url_style'            => (string) AOUWAL_Settings::get( 'url_style' ),
				'rewrite_uploads_path' => (int) AOUWAL_Settings::get( 'rewrite_uploads_path' ),
				'uploads_path'         => (string) AOUWAL_Settings::get( 'uploads_path' ),
				'public_url'           => (string) AOUWAL_Settings::get( 'public_url' ),
				'additional_urls'      => (string) AOUWAL_Settings::get( 'additional_urls' ),
				'exclude_urls'         => (string) AOUWAL_Settings::get( 'exclude_urls' ),
				'strip_comments'       => (int) AOUWAL_Settings::get( 'strip_comments' ),
				'strip_search'         => (int) AOUWAL_Settings::get( 'strip_search' ),
				'preserve_redirects'   => (int) AOUWAL_Settings::get( 'preserve_redirects' ),
				'generate_sitemap'     => (int) AOUWAL_Settings::get( 'generate_sitemap' ),
				'generate_robots'      => (int) AOUWAL_Settings::get( 'generate_robots' ),
				'generate_headers'     => (int) AOUWAL_Settings::get( 'generate_headers' ),
				'show_credit'          => AOUWAL_Brand::should_include() ? 1 : 0,
			),
		);

		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Last reusable export, if any.
	 *
	 * @return array{job_id:string,output_dir:string,signature:string,finished:string}|null
	 */
	public static function baseline() {
		$stored = get_option( self::OPTION_BASELINE, array() );
		if ( ! is_array( $stored ) || empty( $stored['job_id'] ) || empty( $stored['output_dir'] ) ) {
			return null;
		}
		if ( ! isset( $stored['signature'] ) ) {
			$stored['signature'] = '';
		}
		if ( ! isset( $stored['finished'] ) ) {
			$stored['finished'] = '';
		}
		return $stored;
	}

	/**
	 * Remember an export as the copy-forward baseline.
	 *
	 * @param string $job_id     Job ID.
	 * @param string $output_dir Absolute output directory.
	 * @param string $signature  Signature the export was built with.
	 * @return void
	 */
	public static function set_baseline( $job_id, $output_dir, $signature ) {
		update_option(
			self::OPTION_BASELINE,
			array(
				'job_id'     => (string) $job_id,
				'output_dir' => (string) $output_dir,
				'signature'  => (string) $signature,
				'finished'   => current_time( 'mysql' ),
			),
			false
		);
	}

	/**
	 * Forget the baseline.
	 *
	 * @return void
	 */
	public static function clear_baseline() {
		delete_option( self::OPTION_BASELINE );
	}
}
