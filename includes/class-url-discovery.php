<?php
/**
 * URL discovery.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WSE_URL_Discovery
 */
class WSE_URL_Discovery {

	/**
	 * Exclude patterns.
	 *
	 * @var string[]
	 */
	protected $excludes;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->excludes = WSE_Settings::exclude_urls();
	}

	/**
	 * Discover all URLs to export.
	 *
	 * @return string[]
	 */
	public function discover() {
		$urls = array();

		$urls[] = home_url( '/' );

		$post_types = (array) WSE_Settings::get( 'post_types', array( 'post', 'page' ) );
		if ( WSE_Settings::get( 'include_attachments' ) ) {
			$post_types[] = 'attachment';
		}

		foreach ( array_unique( $post_types ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				foreach ( $this->get_post_ids( 'attachment' ) as $id ) {
					$link = get_attachment_link( $id );
					if ( $link ) {
						$urls[] = $link;
					}
				}
				continue;
			}
			foreach ( $this->get_post_ids( $post_type ) as $id ) {
				$link = get_permalink( $id );
				if ( $link ) {
					$urls[] = $link;
				}
			}
		}

		if ( WSE_Settings::get( 'include_taxonomies' ) ) {
			$urls = array_merge( $urls, $this->discover_taxonomies() );
		}

		if ( WSE_Settings::get( 'include_authors' ) ) {
			$urls = array_merge( $urls, $this->discover_authors() );
		}

		if ( WSE_Settings::get( 'include_dates' ) ) {
			$urls = array_merge( $urls, $this->discover_dates() );
		}

		if ( WSE_Settings::get( 'include_feeds' ) ) {
			$urls = array_merge( $urls, $this->discover_feeds() );
		}

		$urls = array_merge( $urls, WSE_Settings::additional_urls() );

		$urls = array_map( array( $this, 'normalize' ), $urls );
		$urls = array_filter( $urls );
		$urls = array_values( array_unique( $urls ) );

		return array_values( array_filter( $urls, array( $this, 'is_allowed' ) ) );
	}

	/**
	 * Get published post IDs for a type.
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	protected function get_post_ids( $post_type ) {
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Discover taxonomy archive URLs.
	 *
	 * @return string[]
	 */
	protected function discover_taxonomies() {
		$urls       = array();
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 0,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		return $urls;
	}

	/**
	 * Discover author archive URLs.
	 *
	 * @return string[]
	 */
	protected function discover_authors() {
		$urls = array();
		$users = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => 'ID',
			)
		);
		foreach ( $users as $user_id ) {
			$link = get_author_posts_url( (int) $user_id );
			if ( $link ) {
				$urls[] = $link;
			}
		}
		return $urls;
	}

	/**
	 * Discover date archive URLs (yearly + monthly).
	 *
	 * @return string[]
	 */
	protected function discover_dates() {
		$urls = array();
		$years = wp_get_archives(
			array(
				'type'   => 'yearly',
				'format' => 'custom',
				'echo'   => false,
			)
		);
		if ( preg_match_all( '#href=["\']([^"\']+)["\']#i', (string) $years, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}
		$months = wp_get_archives(
			array(
				'type'   => 'monthly',
				'format' => 'custom',
				'echo'   => false,
			)
		);
		if ( preg_match_all( '#href=["\']([^"\']+)["\']#i', (string) $months, $m2 ) ) {
			$urls = array_merge( $urls, $m2[1] );
		}
		return $urls;
	}

	/**
	 * Discover feed URLs.
	 *
	 * @return string[]
	 */
	protected function discover_feeds() {
		$urls = array();

		$main = get_feed_link();
		if ( $main ) {
			$urls[] = $main;
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 0,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$feed = get_term_feed_link( $term->term_id, $taxonomy );
				if ( $feed && ! is_wp_error( $feed ) ) {
					$urls[] = $feed;
				}
			}
		}

		if ( WSE_Settings::get( 'include_authors' ) ) {
			$users = get_users(
				array(
					'has_published_posts' => true,
					'fields'              => 'ID',
				)
			);
			foreach ( $users as $user_id ) {
				$feed = get_author_feed_link( (int) $user_id );
				if ( $feed && ! is_wp_error( $feed ) ) {
					$urls[] = $feed;
				}
			}
		}

		return $urls;
	}

	/**
	 * Normalize a URL to absolute internal form.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		return $url;
	}

	/**
	 * Whether a URL passes exclusion rules.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_allowed( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}

		if ( ! ( new WSE_Writer() )->is_internal_url( $url ) ) {
			return false;
		}

		foreach ( $this->excludes as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( strlen( $pattern ) > 2 && '/' === $pattern[0] && '/' === substr( $pattern, -1 ) ) {
				$regex = substr( $pattern, 1, -1 );
				if ( @preg_match( '#' . str_replace( '#', '\\#', $regex ) . '#i', $url ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return false;
				}
			} elseif ( false !== stripos( $url, $pattern ) ) {
				return false;
			}
		}

		return true;
	}
}
