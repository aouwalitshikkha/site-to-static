<?php
/**
 * URL discovery.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_URL_Discovery
 */
class AOUWAL_URL_Discovery {

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
		$this->excludes = AOUWAL_Settings::exclude_urls();
	}

	/**
	 * Discover all URLs to export.
	 *
	 * @return string[]
	 */
	public function discover() {
		$urls = array();

		$urls[] = home_url( '/' );
		$urls   = array_merge( $urls, $this->discover_home_pagination() );

		$post_types = (array) AOUWAL_Settings::get( 'post_types', array( 'post', 'page' ) );
		if ( AOUWAL_Settings::get( 'include_attachments' ) ) {
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

		if ( AOUWAL_Settings::get( 'include_taxonomies' ) ) {
			$urls = array_merge( $urls, $this->discover_taxonomies() );
		}

		if ( AOUWAL_Settings::get( 'include_authors' ) ) {
			$urls = array_merge( $urls, $this->discover_authors() );
		}

		if ( AOUWAL_Settings::get( 'include_dates' ) ) {
			$urls = array_merge( $urls, $this->discover_dates() );
		}

		if ( AOUWAL_Settings::get( 'include_feeds' ) ) {
			$urls = array_merge( $urls, $this->discover_feeds() );
		}

		$urls = array_merge( $urls, AOUWAL_Settings::additional_urls() );

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
	 * Discover pagination for the blog index (home or the posts page).
	 *
	 * @return string[]
	 */
	protected function discover_home_pagination() {
		$posts_page = (int) get_option( 'page_for_posts' );

		if ( $posts_page > 0 ) {
			$base = get_permalink( $posts_page );
		} elseif ( 'posts' === get_option( 'show_on_front' ) ) {
			$base = home_url( '/' );
		} else {
			// A static front page has no posts to paginate.
			return array();
		}

		if ( ! $base ) {
			return array();
		}

		$counts = wp_count_posts( 'post' );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;

		return $this->paginate( $base, $total );
	}

	/**
	 * Build the paginated URLs for an archive.
	 *
	 * Mirrors get_pagenum_link(): the home root, an optional index.php, the
	 * request path, then the pagination base and the page number.
	 *
	 * @param string $base     Archive URL (page 1).
	 * @param int    $count    Published items in the archive.
	 * @param int    $per_page Items per page, 0 to use the site setting.
	 * @return string[]
	 */
	protected function paginate( $base, $count, $per_page = 0 ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return array();
		}

		$per_page = (int) $per_page;
		if ( $per_page < 1 ) {
			$per_page = (int) get_option( 'posts_per_page', 10 );
		}
		if ( $per_page < 1 ) {
			$per_page = 10;
		}

		$pages = (int) ceil( $count / $per_page );
		if ( $pages < 2 ) {
			return array();
		}

		// Query-based pagination (plain permalinks) cannot be exported: the
		// writer strips query strings, so every ?paged=N URL would overwrite
		// page 1 instead of getting its own file.
		if ( ! get_option( 'permalink_structure' ) ) {
			return array();
		}

		global $wp_rewrite;
		$base = untrailingslashit( (string) $base );
		$home = untrailingslashit( home_url() );

		$path = $base;
		if ( 0 === strpos( $path, $home ) ) {
			$path = ltrim( substr( $path, strlen( $home ) ), '/' );
		}

		$index = '';
		if ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->using_index_permalinks() ) {
			$index = $wp_rewrite->index;
		}

		$urls = array();
		for ( $page = 2; $page <= $pages; $page++ ) {
			$parts = array( $home );
			if ( '' !== $index ) {
				$parts[] = $index;
			}
			if ( '' !== $path ) {
				$parts[] = $path;
			}
			$parts[] = $this->pagination_base();
			$parts[] = $page;

			$urls[] = user_trailingslashit( implode( '/', array_filter( $parts ) ), 'paged' );
		}

		return $urls;
	}

	/**
	 * Pagination base from the rewrite rules.
	 *
	 * @return string
	 */
	protected function pagination_base() {
		global $wp_rewrite;
		if ( $wp_rewrite instanceof WP_Rewrite && ! empty( $wp_rewrite->pagination_base ) ) {
			return $wp_rewrite->pagination_base;
		}
		return 'page';
	}

	/**
	 * Published post counts grouped by year and month.
	 *
	 * One grouped lookup keeps date-archive discovery to a single query
	 * instead of one WP_Query per archive.
	 *
	 * @return array{year:array<string,int>,month:array<string,int>}
	 */
	protected function date_counts() {
		static $counts = null;

		if ( null !== $counts ) {
			return $counts;
		}

		global $wpdb;
		$counts = array(
			'year'  => array(),
			'month' => array(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One aggregate lookup per discovery run; caching would serve stale counts to the next export.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(ID) AS total FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' GROUP BY y, m",
				'post'
			)
		);

		foreach ( (array) $rows as $row ) {
			$year  = (string) (int) $row->y;
			$month = str_pad( (string) (int) $row->m, 2, '0', STR_PAD_LEFT );
			$key   = $year . '-' . $month;

			$counts['year'][ $year ]  = ( isset( $counts['year'][ $year ] ) ? $counts['year'][ $year ] : 0 ) + (int) $row->total;
			$counts['month'][ $key ]  = (int) $row->total;
		}

		return $counts;
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
					$urls   = array_merge( $urls, $this->paginate( $link, (int) $term->count ) );
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
				$urls   = array_merge( $urls, $this->paginate( $link, (int) count_user_posts( (int) $user_id, 'post', true ) ) );
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
		$urls   = array();
		$counts = $this->date_counts();

		$years = wp_get_archives(
			array(
				'type'   => 'yearly',
				'format' => 'custom',
				'echo'   => false,
			)
		);
		if ( preg_match_all( '#href=["\']([^"\']+)["\']#i', (string) $years, $m ) ) {
			foreach ( $m[1] as $link ) {
				$urls[] = $link;
				if ( preg_match( '#/(\d{4})/?$#', $link, $year ) && ! empty( $counts['year'][ $year[1] ] ) ) {
					$urls = array_merge( $urls, $this->paginate( $link, (int) $counts['year'][ $year[1] ] ) );
				}
			}
		}

		$months = wp_get_archives(
			array(
				'type'   => 'monthly',
				'format' => 'custom',
				'echo'   => false,
			)
		);
		if ( preg_match_all( '#href=["\']([^"\']+)["\']#i', (string) $months, $m2 ) ) {
			foreach ( $m2[1] as $link ) {
				$urls[] = $link;
				if ( ! preg_match( '#/(\d{4})/(\d{2})/?$#', $link, $ym ) ) {
					continue;
				}
				$key = $ym[1] . '-' . $ym[2];
				if ( ! empty( $counts['month'][ $key ] ) ) {
					$urls = array_merge( $urls, $this->paginate( $link, (int) $counts['month'][ $key ] ) );
				}
			}
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

		if ( AOUWAL_Settings::get( 'include_authors' ) ) {
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

		if ( ! ( new AOUWAL_Writer() )->is_internal_url( $url ) ) {
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
