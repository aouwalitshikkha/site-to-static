<?php
/**
 * Output path mapping and file writing.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WSE_Writer
 */
class WSE_Writer {

	/**
	 * Absolute output directory for a job.
	 *
	 * @var string
	 */
	protected $output_dir;

	/**
	 * Home URL without trailing slash.
	 *
	 * @var string
	 */
	protected $home_url;

	/**
	 * Site URL without trailing slash.
	 *
	 * @var string
	 */
	protected $site_url;

	/**
	 * Home path component (e.g. "/w").
	 *
	 * @var string
	 */
	protected $home_path;

	/**
	 * Site path component (e.g. "/w").
	 *
	 * @var string
	 */
	protected $site_path;

	/**
	 * Hosts considered internal.
	 *
	 * @var string[]
	 */
	protected $hosts;

	/**
	 * Constructor.
	 *
	 * @param string $output_dir Absolute output directory.
	 */
	public function __construct( $output_dir = '' ) {
		$this->output_dir = rtrim( $output_dir, "/\\" );
		$this->home_url   = untrailingslashit( home_url() );
		$this->site_url   = untrailingslashit( site_url() );
		$this->home_path  = (string) wp_parse_url( $this->home_url, PHP_URL_PATH );
		$this->site_path  = (string) wp_parse_url( $this->site_url, PHP_URL_PATH );
		$this->home_path  = '/' === $this->home_path ? '' : untrailingslashit( $this->home_path );
		$this->site_path  = '/' === $this->site_path ? '' : untrailingslashit( $this->site_path );

		$hosts = array();
		foreach ( array( $this->home_url, $this->site_url ) as $u ) {
			$host = wp_parse_url( $u, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		$this->hosts = array_values( array_unique( $hosts ) );
	}

	/**
	 * Set the output directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public function set_output_dir( $dir ) {
		$this->output_dir = rtrim( $dir, "/\\" );
	}

	/**
	 * Absolute path for a local (output-relative) path.
	 *
	 * @param string $local Local path.
	 * @return string
	 */
	public function abs_path( $local ) {
		return $this->output_dir . '/' . ltrim( $local, '/' );
	}

	/**
	 * Whether a URL belongs to this site.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_internal_host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return true; // Protocol-relative / relative => internal.
		}
		return in_array( strtolower( $host ), $this->hosts, true );
	}

	/**
	 * Whether a URL is internal to the site (host + within home/site path).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_internal_url( $url ) {
		if ( ! $this->is_internal_host( $url ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return true;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( array( $this->home_path, $this->site_path ) as $base ) {
			if ( '' === $base ) {
				return true;
			}
			if ( 0 === strpos( $path, $base ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Strip the site base path from a URL path.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	protected function strip_base( $path ) {
		foreach ( array( $this->home_path, $this->site_path ) as $base ) {
			if ( '' !== $base && 0 === strpos( $path, $base ) ) {
				$path = substr( $path, strlen( $base ) );
				break;
			}
		}
		return ltrim( $path, '/' );
	}

	/**
	 * Map a URL to an output-relative local path.
	 *
	 * @param string $url  Absolute URL.
	 * @param string $type "page" or "asset".
	 * @return string
	 */
	public function url_to_local_path( $url, $type = 'page' ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$path  = rawurldecode( $path );

		$internal = $this->is_internal_host( $url );

		if ( 'asset' === $type ) {
			$rel = $this->strip_base( $path );
			if ( ! $internal ) {
				$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : 'external';
				if ( ! empty( $parts['port'] ) ) {
					$host .= '_' . $parts['port'];
				}
				$rel = 'assets/' . $host . '/' . ltrim( $rel, '/' );
			} elseif ( '' === $rel ) {
				$rel = 'assets/' . md5( $url );
			}
			return $this->clean_path( $rel );
		}

		// Page.
		$rel = $this->strip_base( $path );

		if ( '' === $rel ) {
			if ( '' !== $query ) {
				return '_q/' . md5( $url ) . '.html';
			}
			return 'index.html';
		}

		if ( $this->looks_like_feed( $rel, $query ) ) {
			$rel = rtrim( $rel, '/' );
			if ( 0 === strpos( $query, 'feed=' ) ) {
				$fmt = sanitize_file_name( substr( $query, 5 ) );
				return $this->clean_path( $rel . '/feed-' . $fmt . '.xml' );
			}
			return $this->clean_path( $rel . '/index.xml' );
		}

		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( '' !== $ext && ! in_array( $ext, array( 'html', 'htm', 'php' ), true ) ) {
			return $this->clean_path( $rel );
		}

		$rel = rtrim( $rel, '/' );
		if ( '' !== $ext ) {
			$rel = substr( $rel, 0, -( strlen( $ext ) + 1 ) );
		}
		return $this->clean_path( $rel . '/index.html' );
	}

	/**
	 * Whether a path looks like a feed endpoint.
	 *
	 * @param string $path  URL path.
	 * @param string $query Query string.
	 * @return bool
	 */
	protected function looks_like_feed( $path, $query ) {
		if ( preg_match( '#(^|/)feed/?$#', $path ) ) {
			return true;
		}
		if ( preg_match( '#(^|/)rss/?$#', $path ) ) {
			return true;
		}
		if ( '' !== $query && preg_match( '/(^|&)feed=/', $query ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Normalize a path (collapse slashes, resolve dots).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function clean_path( $path ) {
		$path     = str_replace( '\\', '/', $path );
		$path     = preg_replace( '#/+#', '/', $path );
		$segments = explode( '/', (string) $path );
		$out      = array();
		foreach ( $segments as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $out );
				continue;
			}
			$seg = preg_replace( '/[\x00-\x1F\x7F]/', '', $seg );
			$out[] = $seg;
		}
		return implode( '/', $out );
	}

	/**
	 * Build a relative href from one local path to another.
	 *
	 * @param string $from_local Local path of the current document.
	 * @param string $to_local   Local path of the target.
	 * @param bool   $to_dir     Whether the target is a directory index.
	 * @return string
	 */
	public function relative_href( $from_local, $to_local, $to_dir = false ) {
		$from_dir = dirname( $from_local );
		if ( '.' === $from_dir ) {
			$from_dir = '';
		}
		$from_parts = array_values( array_filter( explode( '/', str_replace( '\\', '/', $from_dir ) ), 'strlen' ) );
		$to_parts   = array_values( array_filter( explode( '/', str_replace( '\\', '/', $to_local ) ), 'strlen' ) );

		if ( $to_dir && count( $to_parts ) > 0 && 'index.html' === end( $to_parts ) ) {
			array_pop( $to_parts );
		}

		$common = 0;
		$max    = min( count( $from_parts ), count( $to_parts ) );
		while ( $common < $max && $from_parts[ $common ] === $to_parts[ $common ] ) {
			$common++;
		}

		$up  = count( $from_parts ) - $common;
		$rel = str_repeat( '../', $up ) . implode( '/', array_slice( $to_parts, $common ) );

		if ( '' === $rel ) {
			$rel = './';
		} elseif ( $to_dir ) {
			$rel = rtrim( $rel, '/' ) . '/';
		}

		return $rel;
	}

	/**
	 * Build an href for the configured URL style.
	 *
	 * @param string $from_local Local path of current document.
	 * @param string $to_local   Target local path.
	 * @param bool   $to_dir     Whether target is a directory index.
	 * @return string
	 */
	public function href( $from_local, $to_local, $to_dir = false ) {
		if ( 'root' === WSE_Settings::get( 'url_style', 'relative' ) ) {
			$target = $to_local;
			if ( $to_dir && 'index.html' === basename( $target ) ) {
				$target = dirname( $target );
				$target = ( '.' === $target || '' === $target ) ? '' : $target;
			}
			if ( '' === $target || 'index.html' === $target ) {
				return '/';
			}
			return '/' . ltrim( $target, '/' );
		}
		return $this->relative_href( $from_local, $to_local, $to_dir );
	}

	/**
	 * Ensure the directory for a local path exists.
	 *
	 * @param string $local Local path.
	 * @return void
	 */
	public function ensure_dir_for( $local ) {
		$abs = $this->abs_path( $local );
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	/**
	 * Write string contents to a local path.
	 *
	 * @param string $local    Local path.
	 * @param string $contents Contents.
	 * @return bool
	 */
	public function write( $local, $contents ) {
		$this->ensure_dir_for( $local );
		return false !== file_put_contents( $this->abs_path( $local ), $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Whether a local file already exists.
	 *
	 * @param string $local Local path.
	 * @return bool
	 */
	public function exists( $local ) {
		return file_exists( $this->abs_path( $local ) );
	}
}
