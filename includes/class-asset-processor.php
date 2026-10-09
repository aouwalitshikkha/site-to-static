<?php
/**
 * Asset downloading and CSS reference recursion.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WSE_Asset_Processor
 */
class WSE_Asset_Processor {

	/**
	 * Writer.
	 *
	 * @var WSE_Writer
	 */
	protected $writer;

	/**
	 * Crawler.
	 *
	 * @var WSE_Crawler
	 */
	protected $crawler;

	/**
	 * Constructor.
	 *
	 * @param WSE_Writer  $writer  Writer.
	 * @param WSE_Crawler $crawler Crawler.
	 */
	public function __construct( WSE_Writer $writer, WSE_Crawler $crawler ) {
		$this->writer  = $writer;
		$this->crawler = $crawler;
	}

	/**
	 * Download and store an asset.
	 *
	 * @param string $url        Absolute asset URL.
	 * @param string $local_path Output-relative path.
	 * @return array{assets:string[],status:int,error:string}
	 */
	public function process( $url, $local_path ) {
		$result = $this->crawler->fetch( $url, true );

		if ( '' !== $result['error'] || '' === $result['body'] ) {
			return array(
				'assets' => array(),
				'status' => $result['status'],
				'error'  => '' !== $result['error'] ? $result['error'] : 'empty response',
			);
		}

		$discovered = array();
		$path       = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext        = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'css' === $ext ) {
			$processed  = $this->process_css( $result['body'], $url, $local_path );
			$body       = $this->prepend_css_credit( $processed['css'] );
			$discovered = $processed['assets'];
		} else {
			$body = $result['body'];
			if ( in_array( $ext, array( 'js', 'mjs', 'cjs' ), true ) ) {
				$body = WSE_Brand::js_comment() . "\n" . $body;
			}
		}

		$this->writer->write( $local_path, $body );

		return array(
			'assets' => $discovered,
			'status' => $result['status'],
			'error'  => '',
		);
	}

	/**
	 * Rewrite CSS url()/@import references and collect referenced assets.
	 *
	 * @param string $css        CSS contents.
	 * @param string $base_url   Asset URL (base for relative refs).
	 * @param string $local_path Local path of the CSS file.
	 * @return array{css:string,assets:string[]}
	 */
	protected function process_css( $css, $base_url, $local_path ) {
		$assets = array();

		$callback = function ( $url ) use ( $base_url, $local_path, &$assets ) {
			if ( '' === $url || preg_match( '~^(?:data:|#|about:)~i', $url ) ) {
				return null;
			}
			$resolved = $this->strip_query( $this->resolve_url( $base_url, $url ) );
			$assets[] = $resolved;
			$target   = $this->writer->url_to_local_path( $resolved, 'asset' );
			return $this->writer->href( $local_path, $target, false );
		};

		$css = preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			function ( $m ) use ( $callback ) {
				$new = $callback( trim( $m[2] ) );
				return null === $new ? $m[0] : 'url(' . $new . ')';
			},
			$css
		);

		$css = preg_replace_callback(
			'#@import\s+([\'"])([^\'"]+)\1#i',
			function ( $m ) use ( $callback ) {
				$new = $callback( trim( $m[2] ) );
				return null === $new ? $m[0] : '@import "' . $new . '"';
			},
			$css
		);

		return array(
			'css'    => $css,
			'assets' => array_values( array_unique( $assets ) ),
		);
	}

	/**
	 * Prepend the export credit to CSS. Keeps @charset first when present.
	 *
	 * @param string $css CSS contents.
	 * @return string
	 */
	protected function prepend_css_credit( $css ) {
		$credit = WSE_Brand::css_comment();
		$trimmed = ltrim( $css, "\xEF\xBB\xBF \t\r\n" );
		if ( 0 === stripos( $trimmed, '@charset' ) ) {
			$semi = strpos( $trimmed, ';' );
			if ( false !== $semi ) {
				return substr( $trimmed, 0, $semi + 1 ) . "\n" . $credit . substr( $trimmed, $semi + 1 );
			}
		}
		return $credit . "\n" . $css;
	}

	/**
	 * Strip query string (keeping fragment).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function strip_query( $url ) {
		if ( ! WSE_Settings::get( 'strip_query' ) ) {
			return $url;
		}
		$pos = strpos( $url, '?' );
		if ( false === $pos ) {
			return $url;
		}
		$hash = strpos( $url, '#' );
		if ( false !== $hash && $hash > $pos ) {
			return substr( $url, 0, $pos ) . substr( $url, $hash );
		}
		return substr( $url, 0, $pos );
	}

	/**
	 * Resolve a relative URL against a base URL.
	 *
	 * @param string $base Base URL.
	 * @param string $rel  Relative URL.
	 * @return string
	 */
	protected function resolve_url( $base, $rel ) {
		$rel = trim( $rel );
		if ( '' === $rel ) {
			return '';
		}
		if ( 0 === strpos( $rel, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $rel;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $rel ) ) {
			return $rel;
		}

		$b      = wp_parse_url( $base );
		$scheme = isset( $b['scheme'] ) ? $b['scheme'] : 'http';
		$host   = isset( $b['host'] ) ? $b['host'] : '';
		$port   = isset( $b['port'] ) ? ':' . $b['port'] : '';
		$path   = isset( $b['path'] ) ? $b['path'] : '/';
		$origin = $scheme . '://' . $host . $port;

		if ( 0 === strpos( $rel, '?' ) ) {
			return $origin . $path . $rel;
		}
		if ( 0 === strpos( $rel, '/' ) ) {
			return $origin . $rel;
		}

		$dir      = substr( $path, 0, strrpos( $path, '/' ) + 1 );
		$combined = $dir . $rel;

		return $origin . $this->normalize_path( $combined );
	}

	/**
	 * Normalize ./ and ../ segments.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function normalize_path( $path ) {
		$is_abs   = ( 0 === strpos( $path, '/' ) );
		$segments = explode( '/', $path );
		$out      = array();
		foreach ( $segments as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $out );
				continue;
			}
			$out[] = $seg;
		}
		return ( $is_abs ? '/' : '' ) . implode( '/', $out );
	}
}
