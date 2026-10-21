<?php
/**
 * Asset downloading and CSS reference recursion.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Asset_Processor
 */
class AOUWAL_Asset_Processor {

	/**
	 * Writer.
	 *
	 * @var AOUWAL_Writer
	 */
	protected $writer;

	/**
	 * Crawler.
	 *
	 * @var AOUWAL_Crawler
	 */
	protected $crawler;

	/**
	 * Constructor.
	 *
	 * @param AOUWAL_Writer  $writer  Writer.
	 * @param AOUWAL_Crawler $crawler Crawler.
	 */
	public function __construct( AOUWAL_Writer $writer, AOUWAL_Crawler $crawler ) {
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
			$body       = $processed['css'];
			if ( AOUWAL_Brand::should_include() ) {
				$body = $this->prepend_css_credit( $body );
			}
			$discovered = $processed['assets'];
		} elseif ( $this->is_manifest( $ext, $result['body'] ) ) {
			$processed  = $this->process_manifest( $result['body'], $url, $local_path );
			$body       = $processed['manifest'];
			$discovered = $processed['assets'];
		} else {
			$body = $result['body'];
			if ( AOUWAL_Brand::should_include() && in_array( $ext, array( 'js', 'mjs', 'cjs' ), true ) ) {
				$body = AOUWAL_Brand::js_comment() . "\n" . $body;
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
	 * Whether an asset looks like a web app manifest.
	 *
	 * @param string $ext  File extension.
	 * @param string $body Raw body.
	 * @return bool
	 */
	protected function is_manifest( $ext, $body ) {
		if ( ! in_array( $ext, array( 'json', 'webmanifest' ), true ) ) {
			return false;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) && ( isset( $data['icons'] ) || isset( $data['start_url'] ) );
	}

	/**
	 * Rewrite icon/src URLs inside a web app manifest and collect icons.
	 *
	 * @param string $manifest   Raw manifest JSON.
	 * @param string $base_url   Manifest URL (base for relative refs).
	 * @param string $local_path Local path of the manifest file.
	 * @return array{manifest:string,assets:string[]}
	 */
	protected function process_manifest( $manifest, $base_url, $local_path ) {
		$assets = array();
		$data   = json_decode( $manifest, true );

		if ( ! is_array( $data ) ) {
			return array(
				'manifest' => $manifest,
				'assets'   => $assets,
			);
		}

		$rewrite = function ( $url ) use ( $base_url, $local_path, &$assets ) {
			$url = trim( (string) $url );
			if ( '' === $url || preg_match( '~^(?:data:|#|about:)~i', $url ) ) {
				return $url;
			}
			$resolved = $this->strip_query( $this->resolve_url( $base_url, $url ) );
			if ( ! $this->crawler->is_url_allowed_for_fetch( $resolved ) ) {
				return $url;
			}
			$assets[] = $resolved;
			$target   = $this->writer->url_to_local_path( $resolved, 'asset' );
			return $this->writer->href( $local_path, $target, false );
		};

		if ( isset( $data['icons'] ) && is_array( $data['icons'] ) ) {
			foreach ( $data['icons'] as $i => $icon ) {
				if ( is_array( $icon ) && isset( $icon['src'] ) ) {
					$data['icons'][ $i ]['src'] = $rewrite( $icon['src'] );
				}
			}
		}

		if ( isset( $data['shortcuts'] ) && is_array( $data['shortcuts'] ) ) {
			foreach ( $data['shortcuts'] as $s => $shortcut ) {
				if ( ! is_array( $shortcut ) || empty( $shortcut['icons'] ) || ! is_array( $shortcut['icons'] ) ) {
					continue;
				}
				foreach ( $shortcut['icons'] as $i => $icon ) {
					if ( is_array( $icon ) && isset( $icon['src'] ) ) {
						$data['shortcuts'][ $s ]['icons'][ $i ]['src'] = $rewrite( $icon['src'] );
					}
				}
			}
		}

		$encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return array(
				'manifest' => $manifest,
				'assets'   => array_values( array_unique( $assets ) ),
			);
		}

		return array(
			'manifest' => $encoded,
			'assets'   => array_values( array_unique( $assets ) ),
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
			if ( ! $this->crawler->is_url_allowed_for_fetch( $resolved ) ) {
				return null;
			}
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
		$credit = AOUWAL_Brand::css_comment();
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
	 * A static file system has no notion of query strings, so a cached copy
	 * saved as `style.css` would never be served for `style.css?ver=1.2`.
	 * Query strings are therefore always dropped from asset keys.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function strip_query( $url ) {
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
