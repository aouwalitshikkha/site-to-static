<?php
/**
 * Settings storage and sanitization.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WSE_Settings
 */
class WSE_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'wse_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types'          => array( 'post', 'page' ),
			'include_taxonomies'  => 1,
			'include_authors'     => 1,
			'include_dates'       => 0,
			'include_feeds'       => 1,
			'include_attachments' => 0,
			'download_assets'     => 1,
			'url_style'           => 'relative',
			'strip_query'         => 1,
			'strip_comments'      => 1,
			'strip_search'        => 1,
			'batch_size'          => 5,
			'public_url'          => '',
			'additional_urls'     => '',
			'exclude_urls'        => '',
			'preserve_redirects'  => 1,
			'generate_sitemap'    => 1,
			'generate_robots'     => 1,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$d = self::defaults();
		$out = array();

		$post_types = array();
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $pt ) {
				$pt = sanitize_key( $pt );
				if ( $pt ) {
					$post_types[] = $pt;
				}
			}
		}
		if ( empty( $post_types ) ) {
			$post_types = $d['post_types'];
		}
		$out['post_types'] = array_values( array_unique( $post_types ) );

		$booleans = array(
			'include_taxonomies',
			'include_authors',
			'include_dates',
			'include_feeds',
			'include_attachments',
			'download_assets',
			'strip_query',
			'strip_comments',
			'strip_search',
			'preserve_redirects',
			'generate_sitemap',
			'generate_robots',
		);
		foreach ( $booleans as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		$out['url_style'] = ( isset( $input['url_style'] ) && 'root' === $input['url_style'] ) ? 'root' : 'relative';

		$batch = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $d['batch_size'];
		$out['batch_size'] = min( 50, max( 1, $batch ) );

		$out['public_url']      = isset( $input['public_url'] ) ? esc_url_raw( trim( (string) $input['public_url'] ) ) : '';
		$out['additional_urls'] = isset( $input['additional_urls'] ) ? sanitize_textarea_field( (string) $input['additional_urls'] ) : '';
		$out['exclude_urls']    = isset( $input['exclude_urls'] ) ? sanitize_textarea_field( (string) $input['exclude_urls'] ) : '';

		return wp_parse_args( $out, $d );
	}

	/**
	 * Return the parsed list of additional URLs.
	 *
	 * @return string[]
	 */
	public static function additional_urls() {
		return self::parse_lines( (string) self::get( 'additional_urls', '' ) );
	}

	/**
	 * Return the parsed list of excluded URL patterns.
	 *
	 * @return string[]
	 */
	public static function exclude_urls() {
		return self::parse_lines( (string) self::get( 'exclude_urls', '' ) );
	}

	/**
	 * Split a textarea into trimmed, non-empty lines.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	private static function parse_lines( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}
}
