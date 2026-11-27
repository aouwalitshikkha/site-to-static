<?php
/**
 * On-server static serving.
 *
 * Opt-in mode that serves the latest finished export for anonymous GET
 * requests, with WordPress as the fallback for every dynamic context.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Static_Server
 */
class AOUWAL_Static_Server {

	/**
	 * Cron hook that rebuilds an expired export.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'aouwal_refresh_static';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 20 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_change' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * Whether static serving is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) AOUWAL_Settings::get( 'serve_static', 0 );
	}

	/**
	 * TTL in seconds.
	 *
	 * @return int
	 */
	public static function ttl() {
		$hours = (int) AOUWAL_Settings::get( 'serve_static_ttl', 24 );
		return max( 1, $hours ) * HOUR_IN_SECONDS;
	}

	/**
	 * Serve the static file for an anonymous front-end GET.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		if ( ! self::enabled() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		if (
			is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| is_user_logged_in()
			|| is_preview()
			|| is_customize_preview()
			|| is_feed()
			|| is_embed()
			|| is_404()
			// The exporter's own loopback crawl must see live WordPress output, not the old export.
			|| ! empty( $_SERVER['HTTP_X_AOUWAL_CRAWL'] )
			|| ! empty( $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache gate; no data is processed.
			|| ! empty( $_SERVER['HTTP_RANGE'] )
		) {
			return;
		}

		if ( is_singular() && post_password_required() ) {
			return;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return;
		}

		$relative = self::request_relative_path();
		if ( '' === $relative || '.gz' === substr( $relative, -3 ) ) {
			return;
		}

		$job  = AOUWAL_Exporter::latest_done_job();
		$root = $job ? untrailingslashit( wp_normalize_path( (string) $job['output_dir'] ) ) : '';
		if ( ! $job || ! is_dir( $root ) ) {
			return;
		}

		$file = trailingslashit( $root ) . $relative;
		$real = realpath( $file );
		$base = realpath( $root );
		if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
			return;
		}

		$age = time() - (int) filemtime( $real );
		$ttl = self::ttl();
		if ( $age > $ttl ) {
			self::schedule_refresh();
			return;
		}

		self::serve( $real, max( 0, $ttl - $age ), $method );
	}

	/**
	 * Map the request path to a file inside the export.
	 *
	 * Directory requests only resolve with a trailing slash, so WordPress can
	 * still perform its canonical /about -> /about/ redirect.
	 *
	 * @return string
	 */
	protected static function request_relative_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path is decoded and validated below.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? untrailingslashit( $home_path ) : '';
		if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$path = rawurldecode( $path );
		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) ) {
			return '';
		}

		$path = ltrim( $path, '/' );
		if ( '' === $path ) {
			return 'index.html';
		}
		if ( '/' === substr( $path, -1 ) ) {
			return rtrim( $path, '/' ) . '/index.html';
		}
		return $path;
	}

	/**
	 * Send a static file with a short cache header.
	 *
	 * @param string $file      Absolute file path.
	 * @param int    $remaining Remaining TTL seconds.
	 * @param string $method    HTTP method.
	 * @return void
	 */
	protected static function serve( $file, $remaining, $method ) {
		if ( headers_sent() ) {
			return;
		}

		$type     = wp_check_filetype( $file, self::mime_types() );
		$mime     = ! empty( $type['type'] ) ? (string) $type['type'] : 'application/octet-stream';
		$compress = self::compressed_variant( $file );
		$send     = '' !== $compress ? $compress : $file;
		if ( 'text/html' === $mime ) {
			$mime .= '; charset=UTF-8';
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $send ) );
		header( 'Cache-Control: public, max-age=' . (int) $remaining );
		header( 'Vary: Cookie, Accept-Encoding' );
		header( 'X-Aouwal-Static: 1' );
		if ( '' !== $compress ) {
			header( 'Content-Encoding: gzip' );
		}

		if ( 'HEAD' === $method ) {
			exit;
		}

		readfile( $send ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a local static HTML/CSS file; WP_Filesystem would load the whole file into memory.
		exit;
	}

	/**
	 * Mime map for served files.
	 *
	 * The wp_check_filetype() helper defaults to get_allowed_mime_types(), which
	 * is empty for anonymous visitors because it is filtered by upload
	 * capability. That made static HTML download as application/octet-stream.
	 * Passing the full web map keeps the Content-Type correct on the front end.
	 *
	 * @return array
	 */
	protected static function mime_types() {
		return array(
			'html|htm' => 'text/html',
			'css'      => 'text/css',
			'js|mjs'   => 'text/javascript',
			'json|map' => 'application/json',
			'xml'      => 'application/xml',
			'svg'      => 'image/svg+xml',
			'txt'      => 'text/plain',
			'png'      => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'gif'      => 'image/gif',
			'webp'     => 'image/webp',
			'avif'     => 'image/avif',
			'ico'      => 'image/x-icon',
			'bmp'      => 'image/bmp',
			'woff'     => 'font/woff',
			'woff2'    => 'font/woff2',
			'ttf'      => 'font/ttf',
			'otf'      => 'font/otf',
			'eot'      => 'application/vnd.ms-fontobject',
			'mp4'      => 'video/mp4',
			'webm'     => 'video/webm',
			'mp3'      => 'audio/mpeg',
			'ogg'      => 'audio/ogg',
			'wav'      => 'audio/wav',
			'pdf'      => 'application/pdf',
			'wasm'     => 'application/wasm',
		);
	}

	/**
	 * Pre-compressed sidecar for a file when the client accepts gzip.
	 *
	 * @param string $file Absolute file path.
	 * @return string Empty when no usable sidecar exists.
	 */
	protected static function compressed_variant( $file ) {
		if ( ! AOUWAL_Settings::get( 'gzip_static', 0 ) ) {
			return '';
		}

		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'html', 'css', 'js', 'mjs', 'json', 'svg', 'xml' ), true ) ) {
			return '';
		}
		if ( empty( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
			return '';
		}

		$accept = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) );
		if ( false === strpos( $accept, 'gzip' ) ) {
			return '';
		}

		$gz = $file . '.gz';
		return is_file( $gz ) ? $gz : '';
	}

	/**
	 * Size summary for the export currently used for serving.
	 *
	 * Cached for a few minutes because counting a large export is not cheap.
	 *
	 * @return array{pages:int,files:int,bytes:int}|null
	 */
	public static function stats() {
		$job = AOUWAL_Exporter::latest_done_job();
		if ( ! $job || empty( $job['output_dir'] ) || ! is_dir( (string) $job['output_dir'] ) ) {
			return null;
		}

		$key    = 'aouwal_static_stats_' . md5( (string) $job['id'] . '|' . (string) $job['output_dir'] );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['pages'], $cached['files'], $cached['bytes'] ) ) {
			return $cached;
		}

		$stats = array(
			'pages' => 0,
			'files' => 0,
			'bytes' => 0,
		);

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( (string) $job['output_dir'], FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $item ) {
				if ( ! $item->isFile() ) {
					continue;
				}
				++$stats['files'];
				$stats['bytes'] += (int) $item->getSize();
				// Pages are what the static mode replaces, so count them separately.
				if ( 'index.html' === strtolower( $item->getFilename() ) ) {
					++$stats['pages'];
				}
			}
		} catch ( Exception $e ) {
			return null;
		}

		set_transient( $key, $stats, 5 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/**
	 * Schedule one background refresh.
	 *
	 * @return void
	 */
	public static function schedule_refresh() {
		if ( ! self::enabled() || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
	}

	/**
	 * Rebuild the export in cron-sized chunks.
	 *
	 * The shared runner keeps static mode and scheduled exports on one code
	 * path: same lock, same pause handling, same finalize.
	 *
	 * @return void
	 */
	public static function refresh() {
		if ( ! self::enabled() ) {
			return;
		}
		AOUWAL_Scheduler::run_export( self::CRON_HOOK );
	}

	/**
	 * Purge the last served output and schedule a rebuild.
	 *
	 * The ZIP is kept; only the copy used for serving is removed.
	 *
	 * @return bool
	 */
	public static function purge_cache() {
		$job = AOUWAL_Exporter::latest_done_job();
		if ( ! $job || empty( $job['output_dir'] ) ) {
			return false;
		}

		$path  = untrailingslashit( wp_normalize_path( (string) $job['output_dir'] ) );
		$allow = false;
		foreach ( AOUWAL_Exporter::storage_dirs() as $dir ) {
			$dir = untrailingslashit( wp_normalize_path( $dir ) );
			if ( '' !== $dir && 0 === strpos( $path, $dir . '/' ) ) {
				$allow = true;
				break;
			}
		}
		if ( ! $allow ) {
			return false;
		}

		$fs = AOUWAL_Writer::filesystem_instance();
		if ( ! $fs || ! $fs->is_dir( $path ) || ! $fs->rmdir( $path, true ) ) {
			return false;
		}

		self::schedule_refresh();
		return true;
	}

	/**
	 * Delete static files for a post that was published or updated.
	 *
	 * Archives and listings are left to the scheduled refresh; the post page,
	 * home and posts page are removed immediately so they cannot stay stale.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public static function on_post_change( $new_status, $old_status, $post ) {
		if ( ! self::enabled() ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post->ID ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, (array) AOUWAL_Settings::get( 'post_types', array( 'post', 'page' ) ), true ) ) {
			return;
		}

		$job = AOUWAL_Exporter::latest_done_job();
		if ( ! $job ) {
			return;
		}

		$writer = new AOUWAL_Writer();
		$writer->set_output_dir( (string) $job['output_dir'] );

		$urls           = array( get_permalink( $post ), home_url( '/' ) );
		$page_for_posts = (int) get_option( 'page_for_posts' );
		if ( $page_for_posts ) {
			$urls[] = get_permalink( $page_for_posts );
		}

		foreach ( (array) $urls as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$writer->delete_local( $writer->url_to_local_path( $url, 'page' ) );
		}

		self::schedule_refresh();
	}
}
