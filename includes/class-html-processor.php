<?php
/**
 * HTML rewriting and dynamic-content stripping.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_HTML_Processor
 */
class AOUWAL_HTML_Processor {

	/**
	 * Writer.
	 *
	 * @var AOUWAL_Writer
	 */
	protected $writer;

	/**
	 * Asset file extensions.
	 *
	 * @var string[]
	 */
	protected $asset_extensions = array(
		'css', 'js', 'mjs', 'cjs', 'json', 'map', 'wasm',
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
		'mp4', 'webm', 'ogv', 'mov', 'mp3', 'wav', 'ogg', 'm4a',
		'pdf', 'zip', 'gz', 'rar', '7z',
		'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv',
		'xml', 'txt', 'vtt', 'srt',
	);

	/**
	 * Constructor.
	 *
	 * @param AOUWAL_Writer $writer Writer instance.
	 */
	public function __construct( AOUWAL_Writer $writer ) {
		$this->writer = $writer;
	}

	/**
	 * Process an HTML document.
	 *
	 * @param string $html        Raw HTML.
	 * @param string $source_url  Source URL.
	 * @param string $local_path  Output-relative local path.
	 * @param string $job_id      Export job ID (used for the footer fingerprint).
	 * @return array{html:string,assets:string[]}
	 */
	public function process( $html, $source_url, $local_path, $job_id = '' ) {
		$assets = array();

		if ( '' === trim( (string) $html ) ) {
			return array(
				'html'   => $html,
				'assets' => $assets,
			);
		}

		$doc = $this->load( $html );
		if ( ! $doc ) {
			return array(
				'html'   => $html,
				'assets' => $assets,
			);
		}

		$xpath = new DOMXPath( $doc );

		$this->remove_dynamic( $xpath );
		$crawler = new AOUWAL_Crawler();
		$this->rewrite_urls( $doc, $xpath, $source_url, $local_path, $assets, $crawler );

		$include_credit = AOUWAL_Brand::should_include();
		$branded        = false;
		if ( $include_credit ) {
			$branded = $this->add_brand_comment( $doc );
			$this->add_footer_comment( $doc, $job_id );
		}

		$out = $this->restore_scripts( $this->strip_void_end_tags( $doc->saveHTML() ) );

		if ( $include_credit && ! $branded ) {
			$out = AOUWAL_Brand::html_comment() . "\n" . $out;
		}

		return array(
			'html'   => $out,
			'assets' => array_values( array_unique( $assets ) ),
		);
	}

	/**
	 * Insert the invisible export credit as the first node of <head>.
	 *
	 * @param DOMDocument $doc Document.
	 * @return bool True when the comment was placed in the DOM.
	 */
	protected function add_brand_comment( DOMDocument $doc ) {
		$comment = $doc->createComment( "\n  " . implode( "\n  ", AOUWAL_Brand::lines() ) . "\n" );

		$heads = $doc->getElementsByTagName( 'head' );
		if ( $heads->length ) {
			$head = $heads->item( 0 );
			if ( $head->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$head->insertBefore( $comment, $head->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			} else {
				$head->appendChild( $comment );
			}
			return true;
		}

		$htmls = $doc->getElementsByTagName( 'html' );
		if ( $htmls->length ) {
			$html = $htmls->item( 0 );
			if ( $html->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$html->insertBefore( $comment, $html->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			} else {
				$html->appendChild( $comment );
			}
			return true;
		}

		return false;
	}

	/**
	 * Append the fingerprinted footer credit as the last node of <body>.
	 *
	 * @param DOMDocument $doc    Document.
	 * @param string      $job_id Export job ID.
	 * @return void
	 */
	protected function add_footer_comment( DOMDocument $doc, $job_id ) {
		$comment = $doc->createComment( "\n  " . implode( "\n  ", AOUWAL_Brand::footer_lines( $job_id ) ) . "\n" );

		$bodies = $doc->getElementsByTagName( 'body' );
		if ( $bodies->length ) {
			$bodies->item( 0 )->appendChild( $comment );
			return;
		}

		$htmls = $doc->getElementsByTagName( 'html' );
		if ( $htmls->length ) {
			$htmls->item( 0 )->appendChild( $comment );
		}
	}

	/**
	 * Script bodies saved during parsing.
	 *
	 * @var string[]
	 */
	protected $script_bodies = array();

	/**
	 * Load HTML into a DOMDocument with UTF-8 handling.
	 *
	 * libxml2 ships an HTML4 parser, so HTML5 void elements (source, track,
	 * wbr, embed) are parsed as containers and the elements that follow them
	 * are nested inside. normalise_void_elements() flattens that before the
	 * rest of the pipeline runs, and strip_void_end_tags() removes the closing
	 * tags the serialiser then adds.
	 *
	 * The parser also ends a <script> at any closing tag it finds inside the
	 * body, so protect_scripts() swaps affected bodies for tokens first.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	protected function load( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );

		$this->script_bodies = array();
		$protected           = $this->protect_scripts( $html );
		$encoded             = '<?xml encoding="UTF-8">' . $protected;

		$loaded = $doc->loadHTML( $encoded, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return null;
		}

		foreach ( $doc->childNodes as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$doc->removeChild( $node );
			}
		}
		$doc->encoding = 'UTF-8';

		$this->normalise_void_elements( $doc );

		return $doc;
	}

	/**
	 * Swap script bodies that contain closing tags for opaque tokens.
	 *
	 * libxml ends a script element at the first closing tag in its body, so
	 * inline JavaScript that builds HTML (`var tpl = "</div>"`) would lose
	 * everything after it. Only bodies that actually contain a closing tag
	 * are touched, so the content-based removals in remove_dynamic() keep
	 * working for ordinary scripts.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected function protect_scripts( $html ) {
		$html = (string) $html;

		return (string) preg_replace_callback(
			'~(<script\b[^>]*>)(.*?)(</script\s*>)~is',
			function ( $matches ) {
				if ( ! preg_match( '~</[a-z]~i', $matches[2] ) ) {
					return $matches[0];
				}

				$token                          = 'AOUWALSCRIPTBODY' . count( $this->script_bodies ) . 'TOKEN';
				$this->script_bodies[ $token ] = $matches[2];

				return $matches[1] . $token . $matches[3];
			},
			$html
		);
	}

	/**
	 * Put protected script bodies back after serialisation.
	 *
	 * @param string $html Serialised HTML.
	 * @return string
	 */
	protected function restore_scripts( $html ) {
		if ( empty( $this->script_bodies ) ) {
			return $html;
		}

		return strtr( (string) $html, $this->script_bodies );
	}

	/**
	 * Re-parent children of HTML5 void elements to their parent.
	 *
	 * The HTML4 parser treats <source>, <track>, <wbr> and <embed> as
	 * containers, so a responsive image ends up nested inside its <source>
	 * and a video's <track> inside its <source>. Browsers ignore those
	 * end tags, but the serialised markup is invalid and can confuse
	 * downstream tooling, so the children are moved back where they belong.
	 *
	 * @param DOMDocument $doc Document.
	 * @return void
	 */
	protected function normalise_void_elements( DOMDocument $doc ) {
		$voids = array( 'source', 'track', 'wbr', 'embed', 'keygen', 'menuitem' );

		$targets = array();
		foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
			if ( $node instanceof DOMElement && in_array( strtolower( $node->tagName ), $voids, true ) && $node->hasChildNodes() ) {
				$targets[] = $node;
			}
		}

		foreach ( $targets as $node ) {
			$parent = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! $parent ) {
				continue;
			}
			while ( $node->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$child = $node->firstChild; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$node->removeChild( $child );
				if ( $node->nextSibling ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$parent->insertBefore( $child, $node->nextSibling ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				} else {
					$parent->appendChild( $child );
				}
			}
		}
	}

	/**
	 * Remove closing tags the serialiser adds for HTML5 void elements.
	 *
	 * Raw-text blocks (script, style, textarea, title) and comments are
	 * skipped so their content is never altered.
	 *
	 * @param string $html Serialised HTML.
	 * @return string
	 */
	protected function strip_void_end_tags( $html ) {
		$blocks = '~(<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>|<textarea\b[^>]*>.*?</textarea\s*>|<title\b[^>]*>.*?</title\s*>|<!--.*?-->)~is';
		$parts  = preg_split( $blocks, (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return (string) $html;
		}

		foreach ( $parts as $index => $part ) {
			if ( 0 !== $index % 2 ) {
				continue;
			}
			$parts[ $index ] = preg_replace( '~</(?:source|track|wbr|embed|keygen|menuitem)\s*>~i', '', $part );
		}

		return implode( '', $parts );
	}

	/**
	 * Remove WordPress/dynamic nodes.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return void
	 */
	protected function remove_dynamic( $xpath ) {
		$queries = array(
			'//*[@id="wpadminbar"]',
			'//*[contains(@id,"wp-emoji")]',
			'//meta[@name="generator"]',
			'//meta[@name="robots"][contains(@content,"noindex")]',
			'//base',
			'//link[contains(@rel,"https://api.w.org")]',
			'//link[@rel="EditURI"]',
			'//link[@rel="wlwmanifest"]',
			'//link[@rel="pingback"]',
			'//link[@rel="shortlink"]',
			'//link[contains(@href,"wp-json")]',
			'//script[contains(@src,"wp-emoji")]',
			'//script[contains(@src,"wp-includes/js/wp-embed")]',
			'//script[contains(.,"_wpemojiSettings")]',
			'//script[contains(.,"concatemoji")]',
		);

		if ( AOUWAL_Settings::get( 'strip_comments' ) ) {
			$queries[] = '//*[contains(@class,"wp-block-latest-comments")]';
			$queries[] = '//style[contains(@id,"wp-block-latest-comments-inline-css")]';
			$queries[] = '//script[contains(@src,"comment-reply")]';
			$queries[] = '//link[contains(@href,"/comments/feed")]';
			$queries[] = '//link[contains(@title,"Comments Feed")]';
			$queries[] = '//*[@id="comments"]';
			$queries[] = '//*[@id="respond"]';
			$queries[] = '//*[contains(@class,"comment-respond")]';
			$queries[] = '//*[contains(@class,"comment-form")]';
			$queries[] = '//form[@id="commentform"]';
		}

		if ( AOUWAL_Settings::get( 'strip_search' ) ) {
			$queries[] = '//form[@role="search"]';
			$queries[] = '//*[contains(@class,"search-form")]';
			$queries[] = '//*[@id="searchform"]';
			$queries[] = '//form[contains(@action,"?s=")]';
			$queries[] = '//form[contains(@class,"wp-block-search")]';
		}

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( ! $nodes ) {
				continue;
			}
			$remove = array();
			foreach ( $nodes as $node ) {
				$remove[] = $node;
			}
			foreach ( $remove as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Rewrite URL-bearing attributes.
	 *
	 * @param DOMDocument    $doc         Document.
	 * @param DOMXPath       $xpath       XPath.
	 * @param string         $source_url  Source URL.
	 * @param string         $local_path  Local path.
	 * @param array          $assets      Asset accumulator.
	 * @param AOUWAL_Crawler $crawler     Crawler (SSRF guard for enqueued assets).
	 * @return void
	 */
	protected function rewrite_urls( DOMDocument $doc, DOMXPath $xpath, $source_url, $local_path, array &$assets, AOUWAL_Crawler $crawler ) {
		$attrs = array( 'src', 'href', 'poster', 'data-src', 'data-lazy-src', 'data-bg', 'data-background', 'data-background-image' );

		foreach ( $attrs as $attr ) {
			$nodes = $xpath->query( '//*[@' . $attr . ']' );
			if ( ! $nodes ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$raw = $node->getAttribute( $attr );
				if ( '' === trim( $raw ) ) {
					continue;
				}
				$resolved = $this->process_url( $raw, $source_url, $local_path, $node, $attr, $assets, $crawler );
				if ( null !== $resolved ) {
					$node->setAttribute( $attr, $resolved );
				}
				if ( 'data-lazy-src' === $attr && null !== $resolved && $node instanceof DOMElement && 'img' === strtolower( $node->tagName ) ) {
					$node->setAttribute( 'src', $resolved );
				}
			}
		}

		// srcset attributes.
		foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
			$nodes = $xpath->query( '//*[@' . $attr . ']' );
			if ( ! $nodes ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$raw = $node->getAttribute( $attr );
				if ( '' === trim( $raw ) ) {
					continue;
				}
				$rewritten = $this->rewrite_srcset( $raw, $source_url, $local_path, $assets, $crawler );
				$node->setAttribute( $attr, $rewritten );
			}
		}

		// Inline style attributes.
		$nodes = $xpath->query( '//*[@style]' );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$style = $node->getAttribute( 'style' );
				if ( false === stripos( $style, 'url(' ) ) {
					continue;
				}
				$node->setAttribute( 'style', $this->rewrite_css_urls( $style, $source_url, $local_path, $assets, $crawler ) );
			}
		}

		// <style> blocks.
		$nodes = $xpath->query( '//style' );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$css = $node->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '' === trim( $css ) ) {
					continue;
				}
				$css = preg_replace( '~/\*#\s*source(?:URL|MappingURL)=[^*]*\*/~', '', $css );
				if ( false !== stripos( $css, 'url(' ) ) {
					$css = $this->rewrite_css_urls( $css, $source_url, $local_path, $assets, $crawler );
				}
				$node->nodeValue = $css; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
	}

	/**
	 * Process a single URL attribute value.
	 *
	 * @param string         $raw        Raw value.
	 * @param string         $source_url Source URL.
	 * @param string         $local_path Local path.
	 * @param DOMNode        $node       Node.
	 * @param string         $attr       Attribute name.
	 * @param array          $assets     Asset accumulator.
	 * @param AOUWAL_Crawler $crawler    Crawler (SSRF guard for enqueued assets).
	 * @return string|null
	 */
	protected function process_url( $raw, $source_url, $local_path, $node, $attr, array &$assets, AOUWAL_Crawler $crawler ) {
		$raw = trim( $raw );

		if ( preg_match( '~^(?:data:|mailto:|tel:|javascript:|sms:|#)~i', $raw ) ) {
			return null;
		}

		$resolved = $this->resolve_url( $source_url, $raw );
		if ( '' === $resolved ) {
			return null;
		}

		$tag = $node instanceof DOMElement ? strtolower( $node->tagName ) : '';
		$rel = $node instanceof DOMElement ? strtolower( $node->getAttribute( 'rel' ) ) : '';

		if ( 'a' === $tag && preg_match( '~/(?:wp-admin|wp-login\.php|wp-json)(?:/|\?|$)~i', $resolved ) ) {
			return '#';
		}

		$kind = $this->classify( $tag, $attr, $resolved, $rel );

		if ( 'skip' === $kind ) {
			return null;
		}

		if ( 'asset' === $kind ) {
			if ( ! AOUWAL_Settings::get( 'download_assets' ) ) {
				return null;
			}
			$asset_key = $this->strip_query( $resolved );
			if ( ! $crawler->is_url_allowed_for_fetch( $asset_key ) ) {
				return null;
			}
			$assets[] = $asset_key;
			$target   = $this->writer->url_to_local_path( $asset_key, 'asset' );

			// Fragments address a part of the file (SVG sprites such as
			// <use href="sprite.svg#icon">), so they must survive the rewrite.
			$fragment = '';
			$parts    = wp_parse_url( $resolved );
			if ( ! empty( $parts['fragment'] ) ) {
				$fragment = '#' . $parts['fragment'];
			}

			return $this->writer->href( $local_path, $target, false ) . $fragment;
		}

		// Page link.
		if ( ! $this->writer->is_internal_url( $resolved ) ) {
			return null;
		}

		$fragment = '';
		$parts    = wp_parse_url( $resolved );
		if ( ! empty( $parts['fragment'] ) ) {
			$fragment = '#' . $parts['fragment'];
		}

		$target = $this->writer->url_to_local_path( $resolved, 'page' );
		$is_dir = (bool) preg_match( '~(^|/)index\.html$~', $target );

		return $this->writer->href( $local_path, $target, $is_dir ) . $fragment;
	}

	/**
	 * Decide whether a URL is an asset or a page in context.
	 *
	 * @param string $tag      Tag name.
	 * @param string $attr     Attribute name.
	 * @param string $url      Resolved URL.
	 * @param string $rel      rel attribute.
	 * @return string "asset", "page" or "skip".
	 */
	protected function classify( $tag, $attr, $url, $rel ) {
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$is_asset_ext = in_array( $ext, $this->asset_extensions, true );

		if ( 'link' === $tag ) {
			foreach ( preg_split( '/\s+/', trim( $rel ) ) as $token ) {
				if ( in_array( $token, array( 'preconnect', 'dns-prefetch', 'prerender', 'profile' ), true ) ) {
					return 'skip';
				}
			}
			if ( in_array( $rel, array( 'canonical', 'alternate', 'next', 'prev', 'author' ), true ) ) {
				return 'page';
			}
			return 'asset';
		}

		if ( in_array( $tag, array( 'script', 'img', 'source', 'video', 'audio', 'embed', 'track', 'object', 'input' ), true ) ) {
			return 'asset';
		}

		if ( 'iframe' === $tag ) {
			return $is_asset_ext ? 'asset' : 'page';
		}

		if ( 'a' === $tag ) {
			if ( 'href' === $attr && $is_asset_ext && ! in_array( $ext, array( 'html', 'htm', 'php' ), true ) ) {
				return 'asset';
			}
			return 'page';
		}

		return $is_asset_ext ? 'asset' : 'page';
	}

	/**
	 * Rewrite a srcset attribute.
	 *
	 * @param string         $srcset     Raw srcset.
	 * @param string         $source_url Source URL.
	 * @param string         $local_path Local path.
	 * @param array          $assets     Asset accumulator.
	 * @param AOUWAL_Crawler $crawler    Crawler (SSRF guard for enqueued assets).
	 * @return string
	 */
	protected function rewrite_srcset( $srcset, $source_url, $local_path, array &$assets, AOUWAL_Crawler $crawler ) {
		$parts = explode( ',', $srcset );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $part, 2 );
			$url  = $bits[0];
			$desc = isset( $bits[1] ) ? $bits[1] : '';

			if ( AOUWAL_Settings::get( 'download_assets' ) && ! preg_match( '~^(?:data:|#)~i', $url ) ) {
				$resolved  = $this->resolve_url( $source_url, $url );
				$resolved  = $this->strip_query( $resolved );
				if ( ! $crawler->is_url_allowed_for_fetch( $resolved ) ) {
					$out[] = trim( $url . ' ' . $desc );
					continue;
				}

				$fragment = '';
				$hash     = strpos( $resolved, '#' );
				if ( false !== $hash ) {
					$fragment = substr( $resolved, $hash );
				}

				$assets[] = $resolved;
				$target   = $this->writer->url_to_local_path( $resolved, 'asset' );
				$url      = $this->writer->href( $local_path, $target, false ) . $fragment;
			}

			$out[] = trim( $url . ' ' . $desc );
		}
		return implode( ', ', $out );
	}

	/**
	 * Rewrite url() references inside a CSS string.
	 *
	 * @param string         $css        CSS.
	 * @param string         $source_url Base URL.
	 * @param string         $local_path Local path.
	 * @param array          $assets     Asset accumulator.
	 * @param AOUWAL_Crawler $crawler    Crawler (SSRF guard for enqueued assets).
	 * @return string
	 */
	protected function rewrite_css_urls( $css, $source_url, $local_path, array &$assets, AOUWAL_Crawler $crawler ) {
		return preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			function ( $m ) use ( $source_url, $local_path, &$assets, $crawler ) {
				$url = trim( $m[2] );
				if ( '' === $url || preg_match( '~^(?:data:|#)~i', $url ) ) {
					return $m[0];
				}
				if ( ! AOUWAL_Settings::get( 'download_assets' ) ) {
					return $m[0];
				}
				$resolved = $this->strip_query( $this->resolve_url( $source_url, $url ) );
				if ( ! $crawler->is_url_allowed_for_fetch( $resolved ) ) {
					return $m[0];
				}
				$assets[] = $resolved;
				$target   = $this->writer->url_to_local_path( $resolved, 'asset' );
				return 'url(' . $this->writer->href( $local_path, $target, false ) . ')';
			},
			$css
		);
	}

	/**
	 * Strip the query string from a URL.
	 *
	 * Always applied: a static file system cannot distinguish `style.css`
	 * from `style.css?ver=1.2`, so keeping the query string would produce
	 * links and asset keys that never resolve on the exported site.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function strip_query( $url ) {
		$pos = strpos( $url, '?' );
		if ( false === $pos ) {
			return $url;
		}
		$frag = '';
		$hash = strpos( $url, '#' );
		if ( false !== $hash && $hash > $pos ) {
			$frag = substr( $url, $hash );
			$url  = substr( $url, 0, $hash );
		}
		return substr( $url, 0, $pos ) . $frag;
	}

	/**
	 * Resolve a possibly-relative URL against a base URL.
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

		if ( 0 === strpos( $rel, '#' ) ) {
			return $base . $rel;
		}

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
	 * Resolve ./ and ../ segments in a path.
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
