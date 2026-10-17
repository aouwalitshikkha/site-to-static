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

	/* ---------------------------------------------------------------------
	 * Job lifecycle
	 * ------------------------------------------------------------------ */

	/**
	 * Start a new export job.
	 *
	 * @return array|WP_Error Job data or error.
	 */
	public function start() {
		$job_id = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 20 );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'aouwal_uploads', $uploads['error'] );
		}

		$base      = trailingslashit( $uploads['basedir'] ) . 'aouwal-static-site-export';
		$output    = $base . '/' . $job_id . '/site';
		$zip_path  = $base . '/' . $job_id . '.zip';

		if ( ! wp_mkdir_p( $output ) ) {
			return new WP_Error( 'aouwal_mkdir', __( 'Could not create the export directory.', 'aouwal-static-site-export' ) );
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE job_id = %s', $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table ($wpdb->prefix + static name); values are prepared.

		$this->writer->set_output_dir( $output );

		$discovery = new AOUWAL_URL_Discovery();
		$urls      = $discovery->discover();

		$inserted = $this->insert_items( $job_id, $urls, 'page' );

		$job = array(
			'id'         => $job_id,
			'started'    => current_time( 'mysql' ),
			'phase'      => 'crawl',
			'output_dir' => $output,
			'zip_path'   => $zip_path,
			'zip_url'    => trailingslashit( $uploads['baseurl'] ) . 'aouwal-static-site-export/' . $job_id . '.zip',
			'notice'     => array(),
		);
		$this->save_job( $job );
		$this->prune_old_jobs( $base, $job_id );

		$counts = $this->counts( $job_id );

		return array(
			'job_id' => $job_id,
			'total'  => $counts['total'],
			'counts' => $counts,
		);
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
				$log[] = $this->process_page_item( $job_id, $item );
			} else {
				$log[] = $this->process_asset_item( $job_id, $item );
			}
		}

		$counts = $this->counts( $job_id );

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

		$this->writer->set_output_dir( $job['output_dir'] );
		$notices = array();

		$counts = $this->counts( $job_id );
		if ( $counts['pending'] > 0 ) {
			return array(
				'done'   => false,
				'counts' => $counts,
			);
		}

		$this->writer->write( '404.html', $this->build_404( $job_id ) );

		if ( AOUWAL_Settings::get( 'generate_sitemap' ) ) {
			$this->writer->write( 'sitemap.xml', $this->build_sitemap( $job_id ) );
		}
		if ( AOUWAL_Settings::get( 'generate_robots' ) ) {
			$this->writer->write( 'robots.txt', $this->build_robots() );
		}

		$this->writer->write( '_redirects', $this->build_redirects() );
		$this->writer->write( '_headers', $this->build_headers() );

		$zip = new AOUWAL_Zip();
		$res = $zip->zip_dir( $job['output_dir'], $job['zip_path'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( 0 === $counts['done'] ) {
			$notices[] = __( 'No pages were exported. Check your post type settings.', 'aouwal-static-site-export' );
		}

		$job['phase']  = 'done';
		$job['notice'] = $notices;
		$job['finished'] = current_time( 'mysql' );
		$this->save_job( $job );

		return array(
			'done'   => true,
			'counts' => $counts,
			'zip'    => $job['zip_url'],
			'log'    => $notices,
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
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE job_id = %s GROUP BY status", $job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array(
			'pending' => 0,
			'done'    => 0,
			'error'   => 0,
			'skipped' => 0,
			'total'   => 0,
		);
		foreach ( (array) $rows as $row ) {
			$status = $row['status'];
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['n'];
			}
			$counts['total'] += (int) $row['n'];
		}
		return $counts;
	}

	/* ---------------------------------------------------------------------
	 * Item processing
	 * ------------------------------------------------------------------ */

	/**
	 * Process a page item.
	 *
	 * @param string $job_id Job ID.
	 * @param object $item   Row.
	 * @return string Log line.
	 */
	protected function process_page_item( $job_id, $item ) {
		$crawler = new AOUWAL_Crawler();
		$result  = $crawler->fetch( $item->source_url, false );

		if ( '' !== $result['error'] || $result['status'] >= 400 || '' === $result['body'] ) {
			$this->mark( $item->id, 'error', $result['status'], '' !== $result['error'] ? $result['error'] : 'HTTP ' . $result['status'] );
			return sprintf( '[error] %s (%s)', $item->source_url, $result['status'] );
		}

		$processor = new AOUWAL_HTML_Processor( $this->writer );

		if ( $this->is_non_html( $item->local_path, $result['headers'] ) ) {
			$body = $this->rewrite_absolute_urls( $result['body'] );
			if ( AOUWAL_Brand::should_include() ) {
				$body = $this->inject_xml_comment( $body );
			}
			$this->writer->write( $item->local_path, $body );
			$this->mark( $item->id, 'done', $result['status'], '' );
			return sprintf( '[feed] %s -> %s', $item->source_url, $item->local_path );
		}

		$processed = $processor->process( $result['body'], $item->source_url, $item->local_path, $job_id );

		$this->writer->write( $item->local_path, $processed['html'] );

		if ( ! empty( $processed['assets'] ) ) {
			$this->insert_items( $job_id, $processed['assets'], 'asset' );
		}

		$this->mark( $item->id, 'done', $result['status'], '' );
		return sprintf( '[page] %s -> %s', $item->source_url, $item->local_path );
	}

	/**
	 * Process an asset item.
	 *
	 * @param string $job_id Job ID.
	 * @param object $item   Row.
	 * @return string Log line.
	 */
	protected function process_asset_item( $job_id, $item ) {
		if ( $this->writer->exists( $item->local_path ) ) {
			$this->mark( $item->id, 'skipped', 0, 'already downloaded' );
			return '';
		}

		$processor = new AOUWAL_Asset_Processor( $this->writer, new AOUWAL_Crawler() );
		$result    = $processor->process( $item->source_url, $item->local_path );

		if ( ! empty( $result['assets'] ) ) {
			$this->insert_items( $job_id, $result['assets'], 'asset' );
		}

		if ( '' !== $result['error'] ) {
			$this->mark( $item->id, 'error', $result['status'], $result['error'] );
			return sprintf( '[asset error] %s (%s)', $item->source_url, $result['error'] );
		}

		$this->mark( $item->id, 'done', $result['status'], '' );
		return sprintf( '[asset] %s', $item->source_url );
	}

	/**
	 * Insert items (deduplicated).
	 *
	 * @param string   $job_id Job ID.
	 * @param string[] $urls   URLs.
	 * @param string   $type   "page" or "asset".
	 * @return int
	 */
	protected function insert_items( $job_id, $urls, $type ) {
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

			$chunk[] = $wpdb->prepare( '(%s, %s, %s, %s, %s, %s, %s)', $job_id, $type, $url, $hash, $path, 'pending', $now );

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
		$table = $this->table();
		$sql   = "INSERT IGNORE INTO {$table} (job_id, item_type, source_url, url_hash, local_path, status, created_at) VALUES " . implode( ', ', $chunk );
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
	 * @return void
	 */
	protected function mark( $id, $status, $http_status, $message ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom job-queue table; $wpdb->update() escapes values via format arrays. No caching applies to writes.
		$wpdb->update(
			$this->table(),
			array(
				'status'      => $status,
				'http_status' => (int) $http_status,
				'message'     => $message,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Job storage
	 * ------------------------------------------------------------------ */

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
		foreach ( $jobs as $id => $stored ) {
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

	/* ---------------------------------------------------------------------
	 * Static extras
	 * ------------------------------------------------------------------ */

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
	 * @param string $job_id Export job ID.
	 * @return string
	 */
	protected function build_404( $job_id ) {
		$url     = home_url( '/aouwal-404-' . wp_generate_password( 10, false ) . '/' );
		$crawler = new AOUWAL_Crawler();
		$result  = $crawler->fetch( $url, false );

		if ( '' === $result['body'] ) {
			$fallback = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
			if ( AOUWAL_Brand::should_include() ) {
				$fallback = AOUWAL_Brand::html_comment() . "\n" . $fallback;
			}
			return $fallback;
		}

		$processor = new AOUWAL_HTML_Processor( $this->writer );
		$processed = $processor->process( $result['body'], $url, '404.html', $job_id );

		$this->download_recursive( $processed['assets'] );

		return $processed['html'];
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
			$guard++;
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
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_url FROM {$table} WHERE job_id = %s AND item_type = 'page' AND status IN ('done','skipped')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);

		$public = AOUWAL_Settings::get( 'public_url' );
		$public = $public ? untrailingslashit( $public ) : untrailingslashit( home_url() );
		$base   = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$base   = '/' === $base ? '' : untrailingslashit( $base );

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
			$urls[] = $public . $path;
		}
		$urls = array_values( array_unique( $urls ) );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
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
		$lines   = array();
		if ( AOUWAL_Brand::should_include() ) {
			$lines[] = AOUWAL_Brand::text_comment();
		}
		$lines[] = '/index.php / 301';
		$lines[] = '/wp-login.php / 301';
		$lines[] = '/wp-admin/* / 301';
		$lines[] = '/xmlrpc.php / 301';
		$lines[] = '/feed/ /feed/index.xml 200';
		$lines[] = '/feed /feed/index.xml 200';
		$lines[] = '/:path*/feed/ /:path*/feed/index.xml 200';

		if ( AOUWAL_Settings::get( 'preserve_redirects' ) ) {
			$lines[] = '# Add legacy path redirects below as needed.';
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
		$lines[] = '/wp-content/uploads/*';
		$lines[] = '  Cache-Control: public, max-age=31536000, immutable';
		$lines[] = '/assets/*';
		$lines[] = '  Cache-Control: public, max-age=31536000, immutable';
		$lines[] = '/index.html';
		$lines[] = '  Cache-Control: public, max-age=0, must-revalidate';
		return implode( "\n", $lines ) . "\n";
	}
}
