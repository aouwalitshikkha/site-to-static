<?php
/**
 * Export orchestration.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Exporter
 */
class AOUWAL_Exporter {

	/**
	 * Jobs option name.
	 *
	 * @var string
	 */
	const JOBS_OPTION = 'aouwal_jobs';

	/**
	 * Job phase while the queue is being processed.
	 *
	 * @var string
	 */
	const PHASE_CRAWL = 'crawl';

	/**
	 * Job phase while the queue is paused but resumable.
	 *
	 * @var string
	 */
	const PHASE_PAUSED = 'paused';

	/**
	 * Job phase when the ZIP was built.
	 *
	 * @var string
	 */
	const PHASE_DONE = 'done';

	/**
	 * Job phase when the user stopped the export.
	 *
	 * @var string
	 */
	const PHASE_CANCELLED = 'cancelled';

	/**
	 * Writer.
	 *
	 * @var AOUWAL_Writer
	 */
	protected $writer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->writer = new AOUWAL_Writer();
	}

	/**
	 * Job items table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'aouwal_job_items';
	}

	/**
	 * Export mode for this run.
	 *
	 * @return string "full" or "changed".
	 */
	public function mode() {
		return ( 'changed' === AOUWAL_Settings::get( 'export_mode', 'full' ) ) ? 'changed' : 'full';
	}

	/**
	 * Absolute base directory for all export artifacts.
	 *
	 * Defaults to a subdirectory of the WordPress uploads directory, in line
	 * with core's own export storage. The `aouwal_export_dir` filter mirrors
	 * `wp_privacy_exports_dir`, so a site can relocate exports outside the web
	 * root (for example on Nginx, where .htaccess does not apply).
	 *
	 * @return string Directory path without a trailing slash, or empty on error.
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$default = trailingslashit( $uploads['basedir'] ) . 'aouwal-static-site-export';

		/**
		 * Filters the directory used to store export files.
		 *
		 * Return an absolute path outside the web root to keep export
		 * artifacts unreachable by direct URL on any web server. The path
		 * must be writable by the web server user.
		 *
		 * @param string $default Default export directory.
		 */
		$dir = apply_filters( 'aouwal_export_dir', $default );
		if ( ! is_string( $dir ) || '' === trim( $dir ) ) {
			return $default;
		}
		return untrailingslashit( $dir );
	}

	/**
	 * Every directory an export may legitimately live in.
	 *
	 * Includes the resolved base and the default uploads location, so jobs
	 * created before an `aouwal_export_dir` filter was added stay
	 * downloadable. Never broadens access beyond these two plugin-owned paths.
	 *
	 * @return string[]
	 */
	public static function storage_dirs() {
		$dirs = array();

		$base = self::base_dir();
		if ( '' !== $base ) {
			$dirs[] = $base;
		}

		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$dirs[] = trailingslashit( $uploads['basedir'] ) . 'aouwal-static-site-export';
		}

		return array_values( array_unique( array_filter( $dirs ) ) );
	}

	/**
	 * Signed, expiring download URL for a job ZIP.
	 *
	 * Served via admin-post with a WP nonce (12-24h lifetime) and a
	 * manage_options capability check. The URL stops working when the
	 * nonce expires or when the job is pruned.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	public function get_download_url( $job_id ) {
		$job_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $job_id ) );
		$nonce  = wp_create_nonce( 'aouwal_download_' . $job_id );
		return add_query_arg(
			array(
				'action'   => 'aouwal_download',
				'job_id'   => $job_id,
				'_wpnonce' => $nonce,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Drop index.php + .htaccess guards into the export base directory.
	 *
	 * Direct URLs are never advertised in the UI. On Apache these files deny
	 * direct access entirely; on Nginx they are inert, so the signed download
	 * URL is the gate. Relocate with the `aouwal_export_dir` filter to keep
	 * artifacts outside the web root on any server.
	 *
	 * @param string $base Absolute base directory.
	 * @return void
	 */
	protected function protect_base_dir( $base ) {
		if ( '' === $base ) {
			return;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
				return;
			}
			global $wp_filesystem;
		}
		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			return;
		}
		$index = trailingslashit( $base ) . 'index.php';
		if ( ! $wp_filesystem->exists( $index ) ) {
			$wp_filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
		$htaccess = trailingslashit( $base ) . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			$wp_filesystem->put_contents( $htaccess, $rules, FS_CHMOD_FILE );
		}
	}

	/*
	 * -------------------------------------------------------------------
	 * Job lifecycle
	 * -------------------------------------------------------------------
	 */

	/**
	 * Start a new export job.
	 *
	 * @return array|WP_Error Job data or error.
	 */
	public function start() {
		if ( ! AOUWAL_Zip::available() ) {
			return new WP_Error( 'aouwal_zip_missing', __( 'The PHP zip extension is not available.', 'aouwal-static-site-export' ) );
		}

		$job_id = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 20 );

		$base = self::base_dir();
		if ( '' === $base ) {
			return new WP_Error( 'aouwal_uploads', __( 'Could not determine the export directory.', 'aouwal-static-site-export' ) );
		}

		$output   = $base . '/' . $job_id . '/site';
		$zip_path = $base . '/' . $job_id . '.zip';

		if ( ! wp_mkdir_p( $output ) ) {
			return new WP_Error( 'aouwal_mkdir', __( 'Could not create the export directory.', 'aouwal-static-site-export' ) );
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE job_id = %s', $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); values are prepared.

		$this->writer->set_output_dir( $output );

		$mode      = $this->mode();
		$signature = AOUWAL_Export_State::signature();
		$baseline  = ( 'changed' === $mode ) ? AOUWAL_Export_State::baseline() : null;
		$reuse     = false;

		// Start from the previous export when it is still valid for this
		// site. A failed copy falls back to a full export, so the result is
		// never a half-populated directory.
		if ( $baseline && $baseline['signature'] === $signature && is_dir( $baseline['output_dir'] ) ) {
			$reuse = $this->writer->copy_dir( $baseline['output_dir'], $output );
		}

		$discovery = new AOUWAL_URL_Discovery();
		$items     = $discovery->discover_items();

		$states = $reuse ? AOUWAL_Export_State::get_many( 'page', array_keys( $items ) ) : array();

		$pages = array();
		$meta  = array();
		foreach ( $items as $url => $item ) {
			$pages[]      = $url;
			$meta[ $url ] = array(
				'fingerprint' => $item['fingerprint'],
				'skip'        => false,
			);

			if ( ! $reuse || '' === $item['fingerprint'] || ! isset( $states[ $url ] ) ) {
				continue;
			}
			if ( (string) $states[ $url ]->fingerprint !== (string) $item['fingerprint'] ) {
				continue;
			}
			// The fingerprint matches and the copied file is in place, so
			// this page does not need a request at all.
			if ( $this->writer->exists( $this->writer->url_to_local_path( $url, 'page' ) ) ) {
				$meta[ $url ]['skip'] = true;
			}
		}

		$this->insert_items( $job_id, $pages, 'page', '', $meta );

		// Assets already in the export are revalidated with a conditional
		// request even when no page was re-exported, so a replaced image with
		// an unchanged URL is still picked up.
		if ( $reuse ) {
			$assets = array();
			foreach ( AOUWAL_Export_State::rows_for_job( $baseline['job_id'] ) as $row ) {
				if ( 'asset' === $row->item_type ) {
					$assets[] = (string) $row->source_url;
				}
			}

			// An asset that failed last time has no state row, and no
			// unchanged page re-discovers it, so it is queued again here.
			foreach ( $this->errored_assets( (string) $baseline['job_id'] ) as $url ) {
				$assets[] = $url;
			}

			$this->insert_items( $job_id, $assets, 'asset' );
		}

		$job = array(
			'id'           => $job_id,
			'started'      => current_time( 'mysql' ),
			'phase'        => self::PHASE_CRAWL,
			'output_dir'   => $output,
			'zip_path'     => $zip_path,
			'notice'       => array(),
			'mode'         => $mode,
			'signature'    => $signature,
			'baseline_job' => $reuse ? (string) $baseline['job_id'] : '',
			'reused'       => $reuse ? 1 : 0,
		);
		$this->save_job( $job );
		$this->prune_old_jobs( $base, $job_id );
		$this->protect_base_dir( $base );

		$counts = $this->counts( $job_id );

		return array(
			'job_id' => $job_id,
			'total'  => $counts['total'],
			'counts' => $counts,
		);
	}

	/**
	 * Asset URLs that failed in a finished job.
	 *
	 * @param string $job_id Job ID.
	 * @return string[]
	 */
	protected function errored_assets( $job_id ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table; one indexed read per changed-only run. Values are prepared.
		$urls = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT source_url FROM {$table} WHERE job_id = %s AND item_type = 'asset' AND status = 'error'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		return array_map( 'strval', (array) $urls );
	}

	/**
	 * Process a batch of pending items.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $limit  Max items.
	 * @return array|WP_Error
	 */
	public function process_batch( $job_id, $limit = 5 ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'aouwal_no_job', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}

		// A pause or cancel arrives in a different request. Honour it before
		// touching the queue, then check again after the batch below.
		if ( self::PHASE_CANCELLED === $job['phase'] ) {
			$counts = $this->counts( $job_id );
			$this->cleanup_output( $job );
			$this->delete_queue( $job_id );
			return array(
				'done'      => true,
				'cancelled' => true,
				'counts'    => $counts,
			);
		}

		if ( self::PHASE_PAUSED === $job['phase'] ) {
			return array(
				'done'   => false,
				'paused' => true,
				'counts' => $this->counts( $job_id ),
			);
		}

		global $wpdb;
		$table = $this->table();

		$this->writer->set_output_dir( $job['output_dir'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); queue state changes every batch so caching cannot persist across requests. Values are prepared.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_id = %s AND status = 'pending' ORDER BY item_type = 'asset' ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$limit
			)
		);

		if ( empty( $items ) ) {
			return array(
				'done'   => true,
				'counts' => $this->counts( $job_id ),
			);
		}

		$log = array();
		foreach ( $items as $item ) {
			if ( 'page' === $item->item_type ) {
				$this->process_page_item( $job_id, $item, $log );
			} else {
				$this->process_asset_item( $job_id, $item, $log );
			}
		}

		$counts = $this->counts( $job_id );

		// The pause or cancel may have landed while this batch was running.
		// The jobs option was read earlier in this request, so drop the cached
		// copy to observe the other request's write. Without this an in-flight
		// batch could recreate the output that cancel() just deleted.
		wp_cache_delete( 'alloptions', 'options' );
		$fresh = $this->get_job( $job_id );

		if ( $fresh && self::PHASE_CANCELLED === $fresh['phase'] ) {
			$this->cleanup_output( $fresh );
			$this->delete_queue( $job_id );
			return array(
				'done'      => true,
				'cancelled' => true,
				'counts'    => $counts,
			);
		}

		if ( $fresh && self::PHASE_PAUSED === $fresh['phase'] ) {
			return array(
				'done'   => false,
				'paused' => true,
				'counts' => $counts,
				'log'    => array_values( array_filter( $log ) ),
			);
		}

		return array(
			'done'   => ( $counts['pending'] < 1 ),
			'counts' => $counts,
			'log'    => array_values( array_filter( $log ) ),
		);
	}

	/**
	 * Finalize a job: extras, redirects, sitemap, zip.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function finalize( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'aouwal_no_job', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}

		// Never package a stopped job. A paused export still has pending rows
		// and a cancelled one has no output left to zip.
		if ( self::PHASE_CANCELLED === $job['phase'] ) {
			$counts = $this->counts( $job_id );
			$this->cleanup_output( $job );
			$this->delete_queue( $job_id );
			return array(
				'done'      => true,
				'cancelled' => true,
				'counts'    => $counts,
			);
		}

		if ( self::PHASE_PAUSED === $job['phase'] ) {
			return array(
				'done'   => false,
				'paused' => true,
				'counts' => $this->counts( $job_id ),
			);
		}

		$this->writer->set_output_dir( $job['output_dir'] );
		$notices = array();

		$counts = $this->counts( $job_id );
		if ( $counts['pending'] > 0 ) {
			return array(
				'done'   => false,
				'counts' => $counts,
			);
		}

		$page_404 = $this->build_404( $job_id );
		if ( ! empty( $page_404['notice'] ) ) {
			$notices[] = $page_404['notice'];
		}

		$failed = array();
		if ( ! $this->writer->write( '404.html', $page_404['html'] ) ) {
			$failed[] = '404.html';
		}

		if ( AOUWAL_Settings::get( 'generate_sitemap' ) && ! $this->writer->write( 'sitemap.xml', $this->build_sitemap( $job_id ) ) ) {
			$failed[] = 'sitemap.xml';
		}
		if ( AOUWAL_Settings::get( 'generate_robots' ) && ! $this->writer->write( 'robots.txt', $this->build_robots() ) ) {
			$failed[] = 'robots.txt';
		}

		if ( AOUWAL_Settings::get( 'preserve_redirects' ) && ! $this->writer->write( '_redirects', $this->build_redirects() ) ) {
			$failed[] = '_redirects';
		}
		if ( AOUWAL_Settings::get( 'generate_headers' ) && ! $this->writer->write( '_headers', $this->build_headers() ) ) {
			$failed[] = '_headers';
		}

		$zip = new AOUWAL_Zip();
		$res = $zip->zip_dir( $job['output_dir'], $job['zip_path'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( 0 === $counts['done'] ) {
			$notices[] = __( 'No pages were exported. Check your post type settings.', 'aouwal-static-site-export' );
		}

		$health = $this->health( $job_id );

		if ( $counts['error'] > 0 ) {
			$reasons = array();
			foreach ( $health['reasons'] as $reason => $number ) {
				/* translators: 1: number of failed items, 2: failure reason such as "HTTP 503" or "timeout". */
				$reasons[] = sprintf( __( '%1$d× %2$s', 'aouwal-static-site-export' ), (int) $number, (string) $reason );
			}
			$notices[] = sprintf(
				/* translators: 1: number of failed pages or assets, 2: comma-separated list of failure reasons. */
				_n(
					'%1$s item could not be exported (%2$s). See the health report on the Export screen for each URL.',
					'%1$s items could not be exported (%2$s). See the health report on the Export screen for each URL.',
					$counts['error'],
					'aouwal-static-site-export'
				),
				number_format_i18n( $counts['error'] ),
				implode( ', ', $reasons )
			);
		}

		if ( ! empty( $failed ) ) {
			$notices[] = sprintf(
				/* translators: %s: comma-separated list of file names. */
				__( 'These files could not be written: %s', 'aouwal-static-site-export' ),
				implode( ', ', $failed )
			);
		}

		// A changed-only run may have carried files for pages that no longer
		// exist. Removing them here keeps the export a mirror of the site.
		$removed = $this->cleanup_orphans( $job_id, $job );
		if ( $removed > 0 ) {
			$notices[] = sprintf(
				/* translators: %s: number of removed files. */
				_n(
					'%s file from the previous export is no longer part of the site and was removed.',
					'%s files from the previous export are no longer part of the site and were removed.',
					$removed,
					'aouwal-static-site-export'
				),
				number_format_i18n( $removed )
			);
		}

		// Promote the rows this run confirmed, then remember the export as
		// the baseline for the next changed-only run. Both modes do this so a
		// full export always seeds the next incremental one.
		AOUWAL_Export_State::adopt( $job_id, $this->table() );
		AOUWAL_Export_State::set_baseline(
			$job_id,
			$job['output_dir'],
			isset( $job['signature'] ) ? (string) $job['signature'] : AOUWAL_Export_State::signature()
		);

		$job['phase']    = self::PHASE_DONE;
		$job['notice']   = $notices;
		$job['finished'] = current_time( 'mysql' );
		$this->save_job( $job );

		return array(
			'done'   => true,
			'counts' => $counts,
			'zip'    => $this->get_download_url( $job_id ),
			'log'    => $notices,
			'health' => $health,
		);
	}

	/**
	 * Get progress counts.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	public function counts( $job_id ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); counts change every batch so caching cannot persist across requests. Values are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE job_id = %s GROUP BY status", $job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = $this->empty_counts();
		foreach ( (array) $rows as $row ) {
			$status = $row['status'];
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['n'];
			}
			$counts['total'] += (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Counts with every bucket at zero.
	 *
	 * @return array
	 */
	protected function empty_counts() {
		return array(
			'pending' => 0,
			'done'    => 0,
			'error'   => 0,
			'skipped' => 0,
			'total'   => 0,
		);
	}

	/*
	 * -------------------------------------------------------------------
	 * Run control: cancel, pause, resume, status
	 * -------------------------------------------------------------------
	 */

	/**
	 * Cancel a running or paused export.
	 *
	 * The queue runs one batch per request, so this cannot abort a batch that
	 * is already in flight. It flips the phase instead, and the next request
	 * acts on it: process_batch() and finalize() both refuse a cancelled job
	 * and repeat the cleanup, which also catches files an in-flight batch
	 * wrote after the first delete.
	 *
	 * Partial output is removed. The job row stays, with a log line naming the
	 * last processed URL, so the reason survives a page reload.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function cancel( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'aouwal_no_job', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}

		// Count before the queue is deleted so the response can still show how
		// far the export got.
		$counts = $this->counts( $job_id );

		if ( self::PHASE_CANCELLED === $job['phase'] ) {
			return array(
				'cancelled' => true,
				'phase'     => self::PHASE_CANCELLED,
				'counts'    => $counts,
				'log'       => array(),
			);
		}

		if ( self::PHASE_DONE === $job['phase'] ) {
			return new WP_Error( 'aouwal_job_finished', __( 'This export already finished, so there is nothing to cancel.', 'aouwal-static-site-export' ) );
		}

		$last = $this->last_processed_url( $job_id );
		$line = '' !== $last
			? sprintf(
				/* translators: 1: last processed URL, 2: number of processed items, 3: total number of items. */
				__( 'Export cancelled after %1$s (%2$s of %3$s items processed).', 'aouwal-static-site-export' ),
				$last,
				number_format_i18n( $counts['done'] + $counts['error'] ),
				number_format_i18n( $counts['total'] )
			)
			: __( 'Export cancelled before any item was processed.', 'aouwal-static-site-export' );

		$this->cleanup_output( $job );
		$this->delete_queue( $job_id );

		$job['phase']    = self::PHASE_CANCELLED;
		$job['zip_path'] = '';
		$job['notice']   = array( $line );
		$job['finished'] = current_time( 'mysql' );
		$this->save_job( $job );

		return array(
			'cancelled' => true,
			'phase'     => self::PHASE_CANCELLED,
			'counts'    => $counts,
			'log'       => array( $line ),
		);
	}

	/**
	 * Pause a running export without losing progress.
	 *
	 * Queue rows and the output directory are kept, so resume() continues where
	 * this stopped and finalize() can still package the complete site.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function pause( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'aouwal_no_job', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}

		if ( self::PHASE_CANCELLED === $job['phase'] ) {
			return new WP_Error( 'aouwal_job_cancelled', __( 'This export was cancelled. Start a new export.', 'aouwal-static-site-export' ) );
		}

		if ( self::PHASE_DONE === $job['phase'] ) {
			return new WP_Error( 'aouwal_job_finished', __( 'This export already finished.', 'aouwal-static-site-export' ) );
		}

		if ( self::PHASE_PAUSED !== $job['phase'] ) {
			$job['phase']     = self::PHASE_PAUSED;
			$job['paused_at'] = current_time( 'mysql' );
			$this->save_job( $job );
		}

		return array(
			'paused' => true,
			'phase'  => self::PHASE_PAUSED,
			'counts' => $this->counts( $job_id ),
		);
	}

	/**
	 * Resume a paused export.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function resume( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'aouwal_no_job', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}

		if ( self::PHASE_CANCELLED === $job['phase'] ) {
			return new WP_Error( 'aouwal_job_cancelled', __( 'This export was cancelled. Start a new export.', 'aouwal-static-site-export' ) );
		}

		if ( self::PHASE_DONE === $job['phase'] ) {
			return new WP_Error( 'aouwal_job_finished', __( 'This export already finished.', 'aouwal-static-site-export' ) );
		}

		if ( self::PHASE_PAUSED === $job['phase'] ) {
			$job['phase'] = self::PHASE_CRAWL;
			unset( $job['paused_at'] );
			$this->save_job( $job );
		}

		$counts = $this->counts( $job_id );

		return array(
			'paused' => false,
			'phase'  => self::PHASE_CRAWL,
			'counts' => $counts,
			'total'  => $counts['total'],
		);
	}

	/**
	 * Requeue only the failed items of a finished export.
	 *
	 * Already-written files are kept: the same job and output directory are
	 * reused, and finalize() rebuilds the ZIP in place. Transient failures
	 * (timeouts, 429, 5xx) usually succeed on the next attempt.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public function retry_failed( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'aouwal_no_job', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}

		if ( self::PHASE_DONE !== $job['phase'] ) {
			return new WP_Error( 'aouwal_retry_phase', __( 'Only a finished export can retry its failed items.', 'aouwal-static-site-export' ) );
		}

		$counts = $this->counts( $job_id );
		if ( $counts['error'] < 1 ) {
			return new WP_Error( 'aouwal_retry_nothing', __( 'There are no failed items to retry.', 'aouwal-static-site-export' ) );
		}

		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); values are prepared. No caching applies to writes.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', http_status = 0, message = '', attempts = 0 WHERE job_id = %s AND status = 'error'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		$job['phase'] = self::PHASE_CRAWL;
		unset( $job['finished'] );
		$job['notice'] = array();
		$this->save_job( $job );

		$fresh = $this->counts( $job_id );

		return array(
			'phase'   => self::PHASE_CRAWL,
			'counts'  => $fresh,
			'total'   => $fresh['total'],
			'retried' => $counts['error'],
		);
	}

	/**
	 * Crawl health for one job: summary, failure reasons and each failed URL.
	 *
	 * Reads the queue rows the export already stores, so no extra tracking is
	 * needed. The origin URL is the page or stylesheet that referenced an
	 * asset, which is what makes a 404 or 403 actionable.
	 *
	 * @param string $job_id Job ID.
	 * @return array{summary:array,reasons:array,failed:array}
	 */
	public function health( $job_id ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table; one-shot report read. Values are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_type, source_url, http_status, message, attempts, origin_url FROM {$table} WHERE job_id = %s AND status = 'error' ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		$counts  = $this->counts( $job_id );
		$reasons = array();
		$failed  = array();

		foreach ( (array) $rows as $row ) {
			$reason = $this->failure_reason( (int) $row->http_status, (string) $row->message );

			if ( ! isset( $reasons[ $reason ] ) ) {
				$reasons[ $reason ] = 0;
			}
			++$reasons[ $reason ];

			$failed[] = array(
				'url'      => (string) $row->source_url,
				'type'     => (string) $row->item_type,
				'status'   => (int) $row->http_status,
				'reason'   => $reason,
				'message'  => (string) $row->message,
				'attempts' => (int) $row->attempts,
				'origin'   => (string) $row->origin_url,
			);
		}

		arsort( $reasons );

		return array(
			'summary' => array(
				'exported' => $counts['done'],
				'skipped'  => $counts['skipped'],
				'failed'   => $counts['error'],
				'total'    => $counts['total'],
			),
			'reasons' => $reasons,
			'failed'  => $failed,
		);
	}

	/**
	 * Normalize a failure to a short, human-readable reason.
	 *
	 * @param int    $status  HTTP status, 0 for transport errors.
	 * @param string $message Stored error message.
	 * @return string
	 */
	protected function failure_reason( $status, $message ) {
		$text = strtolower( $message );
		if ( '' !== $text && ( false !== strpos( $text, 'timed out' ) || false !== strpos( $text, 'timeout' ) ) ) {
			return __( 'timeout', 'aouwal-static-site-export' );
		}
		if ( $status > 0 ) {
			return 'HTTP ' . (int) $status;
		}
		if ( '' !== $text ) {
			return __( 'network error', 'aouwal-static-site-export' );
		}
		return __( 'failed', 'aouwal-static-site-export' );
	}

	/**
	 * Current export state, for the admin screen and WP-CLI.
	 *
	 * Only the newest job is reported: the UI runs one export at a time and
	 * start() always creates a new job id.
	 *
	 * @return array
	 */
	public function status() {
		$jobs = get_option( self::JOBS_OPTION, array() );
		if ( ! is_array( $jobs ) || empty( $jobs ) ) {
			return array(
				'job_id'      => '',
				'phase'       => '',
				'running'     => false,
				'paused'      => false,
				'stale'       => false,
				'counts'      => $this->empty_counts(),
				'current_url' => '',
				'zip'         => '',
				'notice'      => array(),
				'started'     => '',
				'finished'    => '',
				'mode'        => 'full',
				'reused'      => false,
			);
		}

		$key    = array_key_last( $jobs );
		$job    = ( is_array( $jobs[ $key ] ) ) ? $jobs[ $key ] : array();
		$job_id = isset( $job['id'] ) ? (string) $job['id'] : (string) $key;
		$phase  = isset( $job['phase'] ) ? (string) $job['phase'] : '';
		$counts = $this->counts( $job_id );

		$running = ( self::PHASE_CRAWL === $phase );
		$paused  = ( self::PHASE_PAUSED === $phase );

		return array(
			'job_id'      => $job_id,
			'phase'       => $phase,
			'running'     => $running,
			'paused'      => $paused,
			'stale'       => $running && $this->is_stale( $job ),
			'counts'      => $counts,
			'current_url' => ( $running || $paused ) ? $this->last_processed_url( $job_id ) : '',
			'zip'         => ( self::PHASE_DONE === $phase && ! empty( $job['zip_path'] ) ) ? $this->get_download_url( $job_id ) : '',
			'notice'      => isset( $job['notice'] ) ? (array) $job['notice'] : array(),
			'started'     => isset( $job['started'] ) ? (string) $job['started'] : '',
			'finished'    => isset( $job['finished'] ) ? (string) $job['finished'] : '',
			'mode'        => isset( $job['mode'] ) ? (string) $job['mode'] : 'full',
			'reused'      => ! empty( $job['reused'] ),
		);
	}

	/**
	 * Whether a running job stopped making progress.
	 *
	 * A closed browser tab leaves a job in the crawl phase forever. Every
	 * written page touches the output directory, so its mtime is a free
	 * heartbeat: no extra option write per batch.
	 *
	 * @param array $job Job data.
	 * @return bool
	 */
	protected function is_stale( array $job ) {
		if ( empty( $job['output_dir'] ) ) {
			return true;
		}
		$dir = (string) $job['output_dir'];
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$touched = filemtime( $dir );
		if ( false === $touched ) {
			return true;
		}
		return ( time() - (int) $touched ) > 300;
	}

	/**
	 * Delete the partial output directory and ZIP of a stopped job.
	 *
	 * Paths come from the stored job, never from the request, and each one is
	 * checked against storage_dirs() before anything is removed. Deletion goes
	 * through WP_Filesystem like every other write in the plugin.
	 *
	 * @param array $job Job data.
	 * @return bool True when every path that existed was removed.
	 */
	protected function cleanup_output( array $job ) {
		$paths = array();
		if ( ! empty( $job['output_dir'] ) ) {
			// Remove the job folder too, so no empty shell is left behind.
			$paths[] = dirname( (string) $job['output_dir'] );
			$paths[] = (string) $job['output_dir'];
		}
		if ( ! empty( $job['zip_path'] ) ) {
			$paths[] = (string) $job['zip_path'];
		}

		$fs = AOUWAL_Writer::filesystem_instance();
		if ( ! $fs ) {
			return false;
		}

		$ok = true;
		foreach ( $paths as $path ) {
			$path = wp_normalize_path( $path );
			if ( '' === $path || ! $this->is_inside_storage( $path ) ) {
				continue;
			}
			if ( $fs->is_dir( $path ) ) {
				if ( ! $fs->rmdir( $path, true ) ) {
					$ok = false;
				}
				continue;
			}
			if ( $fs->exists( $path ) && ! $fs->delete( $path ) ) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Whether an absolute path sits inside an export storage directory.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	protected function is_inside_storage( $path ) {
		$path = wp_normalize_path( $path );
		foreach ( self::storage_dirs() as $dir ) {
			$dir = untrailingslashit( wp_normalize_path( $dir ) );
			if ( '' !== $dir && 0 === strpos( $path, $dir . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete files a changed-only export carried forward for removed URLs.
	 *
	 * A row is an orphan when the state table knows it but the current job
	 * never queued it, in any status. A URL that merely failed is still in
	 * the queue, so its previous file is kept and the next run retries it.
	 *
	 * Only runs when the job actually reused a previous export: a full export
	 * starts from an empty directory, so it cannot leave stale files behind.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $job    Job data.
	 * @return int Number of removed files.
	 */
	protected function cleanup_orphans( $job_id, array $job ) {
		if ( empty( $job['reused'] ) || 'changed' !== ( isset( $job['mode'] ) ? $job['mode'] : '' ) ) {
			return 0;
		}

		global $wpdb;
		$state = AOUWAL_Export_State::table();
		$items = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom tables; one set-based read per changed-only run. Values are prepared.
		$orphans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.url_hash, s.local_path FROM {$state} s LEFT JOIN {$items} i ON i.url_hash = s.url_hash AND i.job_id = %s WHERE i.id IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		if ( empty( $orphans ) ) {
			return 0;
		}

		$hashes  = array();
		$removed = 0;
		foreach ( $orphans as $orphan ) {
			$hashes[] = (string) $orphan->url_hash;
			if ( '' !== (string) $orphan->local_path && $this->writer->delete_local( (string) $orphan->local_path ) ) {
				++$removed;
			}
		}

		AOUWAL_Export_State::delete( $hashes );

		return $removed;
	}

	/**
	 * Delete every queue row of a job.
	 *
	 * A cancelled job never resumes, so its rows have no further use and would
	 * otherwise stay in the table.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	protected function delete_queue( $job_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE job_id = %s', $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); values are prepared.
	}

	/**
	 * Last processed page URL, or an empty string.
	 *
	 * Pages are queued before assets and the table has no processed timestamp,
	 * so the highest row id is the most recent item.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	protected function last_processed_url( $job_id ) {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); values are prepared.
		$url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT source_url FROM {$table} WHERE job_id = %s AND item_type = 'page' AND status IN ('done', 'error') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		if ( null === $url || '' === $url ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); values are prepared.
			$url = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT source_url FROM {$table} WHERE job_id = %s AND status IN ('done', 'error') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$job_id
				)
			);
		}

		return (string) $url;
	}

	/*
	 * -------------------------------------------------------------------
	 * Item processing
	 * -------------------------------------------------------------------
	 */

	/**
	 * Process a page item.
	 *
	 * Transient failures are retried by the crawler and the attempt count is
	 * stored so the crawl health report can show how many tries a URL needed.
	 *
	 * @param string $job_id Job ID.
	 * @param object $item   Row.
	 * @param array  $log    Log lines, appended by reference.
	 * @return void
	 */
	protected function process_page_item( $job_id, $item, array &$log ) {
		$crawler = new AOUWAL_Crawler();

		// Pages are not revalidated with a conditional request: a page whose
		// fingerprint changed must be rebuilt from a real response, and a
		// cached 304 from a stale Last-Modified would keep old markup. Only
		// static assets, whose validators describe the file exactly, use
		// conditional GET.
		$result = $crawler->fetch_with_retry( $item->source_url, false );

		foreach ( (array) $result['retries'] as $line ) {
			$log[] = $line;
		}

		if ( ! empty( $result['not_modified'] ) && $this->writer->exists( $item->local_path ) ) {
			$this->mark( $item->id, 'skipped', 304, '', $result['attempts'] );
			$log[] = sprintf( '[page skipped] %s (not modified)', $item->source_url );
			return;
		}

		if ( '' !== $result['error'] || $result['status'] >= 400 || '' === $result['body'] ) {
			$this->mark( $item->id, 'error', $result['status'], '' !== $result['error'] ? $result['error'] : 'HTTP ' . $result['status'], $result['attempts'] );
			$log[] = sprintf( '[error] %s (%s)', $item->source_url, $result['status'] );
			return;
		}

		$processor = new AOUWAL_HTML_Processor( $this->writer );

		if ( $this->is_non_html( $item->local_path, $result['headers'] ) ) {
			$body = $this->rewrite_absolute_urls( $result['body'] );
			if ( AOUWAL_Brand::should_include() ) {
				$body = $this->inject_xml_comment( $body );
			}
			if ( ! $this->writer->write( $item->local_path, $body ) ) {
				$log[] = $this->write_failure( $item, $result['status'], $result['attempts'] );
				return;
			}
			$this->mark( $item->id, 'done', $result['status'], '', $result['attempts'] );
			$this->save_state( $job_id, 'page', $item, $result );
			$log[] = sprintf( '[feed] %s -> %s', $item->source_url, $item->local_path );
			return;
		}

		$processed = $processor->process( $result['body'], $item->source_url, $item->local_path, $job_id );

		if ( ! $this->writer->write( $item->local_path, $processed['html'] ) ) {
			$log[] = $this->write_failure( $item, $result['status'], $result['attempts'] );
			return;
		}

		if ( ! empty( $processed['assets'] ) ) {
			$this->insert_items( $job_id, $processed['assets'], 'asset', $item->source_url );
		}

		$this->mark( $item->id, 'done', $result['status'], '', $result['attempts'] );
		$this->save_state( $job_id, 'page', $item, $result );
		$log[] = sprintf( '[page] %s -> %s', $item->source_url, $item->local_path );
	}

	/**
	 * Store the fingerprint and validators for a fetched item.
	 *
	 * The fingerprint comes from the queue row, which carries what discovery
	 * computed. It is what the next changed-only run compares against.
	 *
	 * @param string $job_id Job ID.
	 * @param string $type   "page" or "asset".
	 * @param object $item   Queue row.
	 * @param array  $result Fetch result.
	 * @return void
	 */
	protected function save_state( $job_id, $type, $item, array $result ) {
		$headers = isset( $result['headers'] ) && is_array( $result['headers'] ) ? $result['headers'] : array();

		AOUWAL_Export_State::save(
			$job_id,
			$type,
			$item->source_url,
			$item->local_path,
			array(
				'fingerprint'   => isset( $item->fingerprint ) ? (string) $item->fingerprint : '',
				'etag'          => $this->header_value( $headers, 'etag' ),
				'last_modified' => $this->header_value( $headers, 'last-modified' ),
				'bytes'         => isset( $result['bytes'] ) ? (int) $result['bytes'] : ( isset( $result['body'] ) ? strlen( (string) $result['body'] ) : 0 ),
			)
		);
	}

	/**
	 * Read a response header as a string.
	 *
	 * @param array  $headers Lower-cased header map.
	 * @param string $key     Header name.
	 * @return string
	 */
	protected function header_value( array $headers, $key ) {
		if ( ! isset( $headers[ $key ] ) ) {
			return '';
		}
		$value = $headers[ $key ];
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return trim( (string) $value );
	}

	/**
	 * Mark an item as failed because its file could not be written.
	 *
	 * The item is not marked "done", so the failure shows up in the final
	 * counts and in the notice shown when the export finishes.
	 *
	 * @param object $item        Row.
	 * @param int    $http_status HTTP status of the fetched response.
	 * @param int    $attempts    Number of fetch attempts made.
	 * @return string Log line.
	 */
	protected function write_failure( $item, $http_status, $attempts = 1 ) {
		/* translators: %s: local file path. */
		$message = sprintf( __( 'Could not write %s', 'aouwal-static-site-export' ), $item->local_path );
		$this->mark( $item->id, 'error', $http_status, $message, $attempts );
		return sprintf( '[error] %s (%s)', $item->source_url, $message );
	}

	/**
	 * Process an asset item.
	 *
	 * @param string $job_id Job ID.
	 * @param object $item   Row.
	 * @param array  $log    Log lines, appended by reference.
	 * @return void
	 */
	protected function process_asset_item( $job_id, $item, array &$log ) {
		$state  = AOUWAL_Export_State::get( 'asset', $item->source_url );
		$exists = $this->writer->exists( $item->local_path );

		// With stored validators a copied-forward file can be revalidated
		// instead of downloaded again. Without them the old "already there"
		// shortcut still applies.
		$conditional = ( $state && $exists )
			? array(
				'etag'          => (string) $state->etag,
				'last_modified' => (string) $state->last_modified,
			)
			: array();

		if ( empty( $conditional ) && $exists ) {
			$this->mark( $item->id, 'skipped', 0, 'already downloaded' );
			return;
		}

		$processor = new AOUWAL_Asset_Processor( $this->writer, new AOUWAL_Crawler() );
		$result    = $processor->process( $item->source_url, $item->local_path, $conditional );

		foreach ( (array) $result['retries'] as $line ) {
			$log[] = $line;
		}

		if ( ! empty( $result['not_modified'] ) && $exists ) {
			$this->mark( $item->id, 'skipped', 304, 'not modified', $result['attempts'] );
			$log[] = sprintf( '[asset skipped] %s (not modified)', $item->source_url );
			return;
		}

		if ( '' !== $result['error'] ) {
			$this->mark( $item->id, 'error', $result['status'], $result['error'], $result['attempts'] );
			$log[] = sprintf( '[asset error] %s (%s)', $item->source_url, $result['error'] );
			return;
		}

		if ( ! empty( $result['assets'] ) ) {
			$this->insert_items( $job_id, $result['assets'], 'asset', $item->source_url );
		}

		$this->mark( $item->id, 'done', $result['status'], '', $result['attempts'] );
		$this->save_state( $job_id, 'asset', $item, $result );
		$log[] = sprintf( '[asset] %s', $item->source_url );
	}

	/**
	 * Insert items (deduplicated).
	 *
	 * @param string   $job_id Job ID.
	 * @param string[] $urls   URLs.
	 * @param string   $type   "page" or "asset".
	 * @param string   $origin URL of the page or stylesheet that referenced the item.
	 * @param array    $meta   Per-URL fingerprint and skip flag, keyed by URL.
	 * @return int
	 */
	protected function insert_items( $job_id, $urls, $type, $origin = '', array $meta = array() ) {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql' );
		$count = 0;
		$chunk = array();

		foreach ( (array) $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			$hash = md5( $type . '|' . $url );
			$path = ( 'page' === $type )
				? $this->writer->url_to_local_path( $url, 'page' )
				: $this->writer->url_to_local_path( $url, 'asset' );

			$info        = isset( $meta[ $url ] ) ? (array) $meta[ $url ] : array();
			$status      = ! empty( $info['skip'] ) ? 'skipped' : 'pending';
			$fingerprint = isset( $info['fingerprint'] ) ? substr( (string) $info['fingerprint'], 0, 64 ) : '';

			$chunk[] = $wpdb->prepare( '(%s, %s, %s, %s, %s, %s, %s, %s, %s)', $job_id, $type, $url, $hash, $path, $status, $now, (string) $origin, $fingerprint );

			if ( count( $chunk ) >= 100 ) {
				$count += $this->flush_insert( $chunk );
				$chunk  = array();
			}
		}

		if ( ! empty( $chunk ) ) {
			$count += $this->flush_insert( $chunk );
		}

		return $count;
	}

	/**
	 * Flush a chunk of prepared value tuples via INSERT IGNORE.
	 *
	 * @param array $chunk Prepared tuples.
	 * @return int
	 */
	protected function flush_insert( array $chunk ) {
		global $wpdb;
		$table  = $this->table();
		$sql    = "INSERT IGNORE INTO {$table} (job_id, item_type, source_url, url_hash, local_path, status, created_at, origin_url, fingerprint) VALUES " . implode( ', ', $chunk );
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table; bulk insert of pre-escaped tuples. No caching applies to writes.
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Mark an item status.
	 *
	 * @param int    $id          Item ID.
	 * @param string $status      Status.
	 * @param int    $http_status HTTP status.
	 * @param string $message     Message.
	 * @param int    $attempts    Number of fetch attempts made.
	 * @return void
	 */
	protected function mark( $id, $status, $http_status, $message, $attempts = 0 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table; $wpdb->update() escapes values via format arrays. No caching applies to writes.
		$wpdb->update(
			$this->table(),
			array(
				'status'      => $status,
				'http_status' => (int) $http_status,
				'message'     => $message,
				'attempts'    => max( 0, (int) $attempts ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	/*
	 * -------------------------------------------------------------------
	 * Job storage
	 * -------------------------------------------------------------------
	 */

	/**
	 * Save job data.
	 *
	 * @param array $job Job data.
	 * @return void
	 */
	protected function save_job( array $job ) {
		$jobs = get_option( self::JOBS_OPTION, array() );
		if ( ! is_array( $jobs ) ) {
			$jobs = array();
		}
		$jobs[ $job['id'] ] = $job;

		if ( count( $jobs ) > 5 ) {
			$jobs = array_slice( $jobs, -5, 5, true );
		}
		update_option( self::JOBS_OPTION, $jobs );
	}

	/**
	 * Get job data.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	public function get_job( $job_id ) {
		$jobs = get_option( self::JOBS_OPTION, array() );
		return ( is_array( $jobs ) && isset( $jobs[ $job_id ] ) ) ? $jobs[ $job_id ] : null;
	}

	/**
	 * Delete export directories and ZIPs that no longer have a stored job.
	 *
	 * Export output is kept only for the jobs retained via save_job() (5).
	 * Removal uses WP_Filesystem and never touches paths outside $base.
	 *
	 * @param string $base   Absolute base directory for exports.
	 * @param string $job_id Current job ID (always kept).
	 * @return void
	 */
	protected function prune_old_jobs( $base, $job_id ) {
		$jobs = get_option( self::JOBS_OPTION, array() );
		if ( ! is_array( $jobs ) ) {
			$jobs = array();
		}
		$keep = array( $job_id );

		// The copy-forward baseline is read by the next changed-only run even
		// after its job row has been pruned from the option list.
		$baseline = AOUWAL_Export_State::baseline();
		if ( $baseline ) {
			$keep[] = (string) $baseline['job_id'];
		}

		foreach ( $jobs as $id => $stored ) {
			// A cancelled job keeps its row for the log but owns no files.
			// Skipping it means a batch that was still in flight when the user
			// cancelled cannot leave a directory that is then kept forever.
			if ( isset( $stored['phase'] ) && self::PHASE_CANCELLED === $stored['phase'] ) {
				continue;
			}
			$keep[] = (string) $id;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
				return;
			}
			global $wp_filesystem;
		}
		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			return;
		}

		$entries = $wp_filesystem->dirlist( $base );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $name => $info ) {
			$name = (string) $name;
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( '.' === $name[0] || 'index.php' === $name ) {
				continue;
			}
			$id = preg_replace( '/\.zip$/', '', $name );
			if ( in_array( $id, $keep, true ) ) {
				continue;
			}
			$path = trailingslashit( $base ) . $name;
			if ( ! empty( $info['type'] ) && 'd' === $info['type'] ) {
				$wp_filesystem->rmdir( $path, true );
			} else {
				$wp_filesystem->delete( $path );
			}
		}
	}

	/*
	 * -------------------------------------------------------------------
	 * Static extras
	 * -------------------------------------------------------------------
	 */

	/**
	 * Whether a response should be stored without HTML processing.
	 *
	 * @param string $local_path Local path.
	 * @param array  $headers    Response headers.
	 * @return bool
	 */
	protected function is_non_html( $local_path, array $headers ) {
		if ( preg_match( '~\.(?:xml|json|txt|rss|atom|xsl)$~i', $local_path ) ) {
			return true;
		}

		$ct = '';
		if ( isset( $headers['content-type'] ) ) {
			$ct = is_array( $headers['content-type'] ) ? reset( $headers['content-type'] ) : $headers['content-type'];
		}
		$ct = strtolower( (string) $ct );
		if ( '' === $ct || false !== strpos( $ct, 'html' ) ) {
			return false;
		}
		foreach ( array( 'xml', 'rss', 'atom', 'json', 'plain' ) as $needle ) {
			if ( false !== strpos( $ct, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace local origin URLs with the configured public URL in raw text.
	 *
	 * @param string $body Raw content.
	 * @return string
	 */
	protected function rewrite_absolute_urls( $body ) {
		$public = AOUWAL_Settings::get( 'public_url' );
		$target = $public ? untrailingslashit( $public ) : untrailingslashit( home_url() );

		$body = str_replace( untrailingslashit( home_url() ), $target, $body );
		$body = str_replace( untrailingslashit( site_url() ), $target, $body );

		return $body;
	}

	/**
	 * Place the export credit after the XML declaration.
	 *
	 * @param string $body Raw XML.
	 * @return string
	 */
	protected function inject_xml_comment( $body ) {
		$comment = AOUWAL_Brand::xml_comment();
		$trimmed = ltrim( $body );
		if ( 0 === strpos( $trimmed, '<?xml' ) ) {
			$offset = strpos( $body, '<?xml' );
			$end    = strpos( $body, '?>', $offset );
			if ( false !== $end ) {
				return substr( $body, 0, $end + 2 ) . "\n" . $comment . substr( $body, $end + 2 );
			}
		}
		return $comment . "\n" . $body;
	}

	/**
	 * Build a static 404 page.
	 *
	 * The theme's 404 template is only used when WordPress actually answers
	 * the synthetic URL with a 404. Sites that redirect unknown URLs to the
	 * front page (or answer 200 with the homepage) would otherwise export a
	 * copy of the homepage as 404.html. A 200 response is still accepted when
	 * its body differs from the homepage, which covers setups that render the
	 * 404 template with a 200 status (soft 404).
	 *
	 * @param string $job_id Export job ID.
	 * @return array{html:string,notice:string} Page HTML and an optional notice.
	 */
	protected function build_404( $job_id ) {
		$url     = home_url( '/aouwal-404-' . wp_generate_password( 10, false ) . '/' );
		$crawler = new AOUWAL_Crawler();
		$result  = $crawler->fetch( $url, false );

		$status   = (int) $result['status'];
		$use_body = '' !== $result['body'] && 404 === $status;
		$notice   = '';

		if ( ! $use_body && '' !== $result['body'] && 200 === $status ) {
			$home = $crawler->fetch( home_url( '/' ), false );
			if ( '' !== $home['body'] ) {
				$use_body = $this->page_fingerprint( $result['body'] ) !== $this->page_fingerprint( $home['body'] );
			} else {
				$use_body = true;
			}
		}

		if ( ! $use_body ) {
			$notice   = __( 'The site did not return a 404 for unknown URLs, so a generic 404 page was generated instead of the theme template.', 'aouwal-static-site-export' );
			$fallback = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
			if ( AOUWAL_Brand::should_include() ) {
				$fallback = AOUWAL_Brand::html_comment() . "\n" . $fallback;
			}
			return array(
				'html'   => $fallback,
				'notice' => $notice,
			);
		}

		$processor = new AOUWAL_HTML_Processor( $this->writer );
		$processed = $processor->process( $result['body'], $url, '404.html', $job_id );

		$this->download_recursive( $processed['assets'] );

		return array(
			'html'   => $processed['html'],
			'notice' => $notice,
		);
	}

	/**
	 * Reduce a page body to a comparable fingerprint.
	 *
	 * Used to tell a real 404 template from the homepage served for an
	 * unknown URL. Markup, scripts, styles and whitespace are ignored.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	protected function page_fingerprint( $html ) {
		$html = (string) preg_replace( '~<(script|style)\b.*?</\1>~is', ' ', (string) $html );
		$text = wp_strip_all_tags( $html );
		$text = (string) preg_replace( '~\s+~u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Download a set of assets (and their references), skipping existing files.
	 *
	 * @param string[] $urls URLs.
	 * @return void
	 */
	protected function download_recursive( array $urls ) {
		$processor = new AOUWAL_Asset_Processor( $this->writer, new AOUWAL_Crawler() );
		$queue     = array_values( array_unique( $urls ) );
		$seen      = array();
		$guard     = 0;

		while ( ! empty( $queue ) && $guard < 500 ) {
			++$guard;
			$url = array_shift( $queue );
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$local = $this->writer->url_to_local_path( $url, 'asset' );
			if ( $this->writer->exists( $local ) ) {
				continue;
			}
			$res = $processor->process( $url, $local );
			foreach ( (array) $res['assets'] as $new ) {
				if ( ! isset( $seen[ $new ] ) ) {
					$queue[] = $new;
				}
			}
		}
	}

	/**
	 * Build sitemap.xml.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	protected function build_sitemap( $job_id ) {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); one-shot export read. Values are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_url FROM {$table} WHERE job_id = %s AND item_type = 'page' AND status IN ('done','skipped')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		$public = AOUWAL_Settings::get( 'public_url' );
		$public = $public ? untrailingslashit( $public ) : untrailingslashit( home_url() );
		$base   = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$base   = '/' === $base ? '' : untrailingslashit( $base );

		global $wp_rewrite;
		$pagination_base = ( $wp_rewrite instanceof WP_Rewrite && ! empty( $wp_rewrite->pagination_base ) )
			? $wp_rewrite->pagination_base
			: 'page';

		$urls = array();
		foreach ( (array) $rows as $row ) {
			if ( preg_match( '~[?&]feed=~i', $row->source_url ) ) {
				continue;
			}
			$path = (string) wp_parse_url( $row->source_url, PHP_URL_PATH );
			if ( '' !== $base && 0 === strpos( $path, $base ) ) {
				$path = substr( $path, strlen( $base ) );
			}
			$path = '/' . ltrim( $path, '/' );
			if ( preg_match( '~/feed/?$~', $path ) || preg_match( '~\.(?:xml|json|txt)$~i', $path ) ) {
				continue;
			}
			// Discovered archive pagination (1.4.0) is crawlable but stays out
			// of the sitemap, matching WordPress core sitemap behavior.
			if ( preg_match( '~/' . preg_quote( $pagination_base, '~' ) . '/\d+/?$~', $path ) ) {
				continue;
			}
			$urls[] = $public . $path;
		}
		$urls = array_values( array_unique( $urls ) );

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ( AOUWAL_Brand::should_include() ) {
			$xml .= AOUWAL_Brand::xml_comment() . "\n";
		}
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $urls as $u ) {
			$xml .= "\t<url><loc>" . esc_url( $u ) . "</loc></url>\n";
		}
		$xml .= '</urlset>' . "\n";

		return $xml;
	}

	/**
	 * Build robots.txt.
	 *
	 * @return string
	 */
	protected function build_robots() {
		$public = AOUWAL_Settings::get( 'public_url' );
		$public = $public ? untrailingslashit( $public ) : untrailingslashit( home_url() );

		$out = '';
		if ( AOUWAL_Brand::should_include() ) {
			$out .= AOUWAL_Brand::text_comment() . "\n";
		}
		$out .= "User-agent: *\n";
		$out .= "Allow: /\n\n";
		if ( AOUWAL_Settings::get( 'generate_sitemap' ) ) {
			$out .= "Sitemap: {$public}/sitemap.xml\n";
		}
		return $out;
	}

	/**
	 * Build the Cloudflare Pages _redirects file.
	 *
	 * @return string
	 */
	protected function build_redirects() {
		$lines = array();
		if ( AOUWAL_Brand::should_include() ) {
			$lines[] = AOUWAL_Brand::text_comment();
		}
		$lines[] = '/index.php / 301';
		$lines[] = '/wp-login.php / 301';
		$lines[] = '/wp-admin/* / 301';
		$lines[] = '/xmlrpc.php / 301';

		if ( AOUWAL_Settings::get( 'include_feeds' ) ) {
			$lines[] = '/feed/ /feed/index.xml 200';
			$lines[] = '/feed /feed/index.xml 200';
			$lines[] = '/*/feed/ /:splat/feed/index.xml 200';
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Build the Cloudflare Pages _headers file.
	 *
	 * @return string
	 */
	protected function build_headers() {
		$lines = array();
		if ( AOUWAL_Brand::should_include() ) {
			$lines[] = AOUWAL_Brand::text_comment();
		}
		$lines[] = '/*.css';
		$lines[] = '  Cache-Control: public, max-age=2592000';
		$lines[] = '/*.js';
		$lines[] = '  Cache-Control: public, max-age=2592000';
		if ( AOUWAL_Settings::get( 'rewrite_uploads_path' ) ) {
			$target = trim( str_replace( '\\', '/', (string) AOUWAL_Settings::get( 'uploads_path', 'uploads' ) ), '/' );
			if ( '' === $target ) {
				$target = 'uploads';
			}
			$lines[] = '/' . $target . '/*';
		} else {
			$lines[] = '/wp-content/uploads/*';
		}
		$lines[] = '  Cache-Control: public, max-age=31536000, immutable';
		$lines[] = '/assets/*';
		$lines[] = '  Cache-Control: public, max-age=31536000, immutable';
		$lines[] = '/index.html';
		$lines[] = '  Cache-Control: public, max-age=0, must-revalidate';
		return implode( "\n", $lines ) . "\n";
	}
}
