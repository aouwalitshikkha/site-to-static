<?php
/**
 * Settings storage and sanitization.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Settings
 */
class AOUWAL_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'aouwal_settings';

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
			'max_asset_mb'        => 25,
			'url_style'           => 'relative',
			'strip_comments'      => 1,
			'strip_search'        => 1,
			'batch_size'          => 5,
			'public_url'          => '',
			'additional_urls'     => '',
			'exclude_urls'        => '',
			'request_timeout'     => 60,
			'request_delay'       => 0,
			'crawl_origin'        => '',
			'tls_mode'            => 'auto',
			'tls_ca_file'         => '',
			'auth_user'           => '',
			'auth_pass'           => '',
			'preserve_redirects'  => 1,
			'generate_sitemap'    => 1,
			'generate_robots'     => 1,
			'generate_headers'    => 1,
			'show_credit'         => 0,
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
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when the setting is not stored.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Register a settings error when the admin UI is loaded.
	 *
	 * The function add_settings_error() lives in wp-admin, so it does not exist
	 * when the sanitizer runs from WP-CLI, cron or a REST request. The
	 * sanitized value is already the safe fallback in that case; only the
	 * admin notice is lost.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return void
	 */
	protected static function notice( $code, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message );
		}
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$d   = self::defaults();
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
			'strip_comments',
			'strip_search',
			'preserve_redirects',
			'generate_sitemap',
			'generate_robots',
			'generate_headers',
			'show_credit',
		);
		foreach ( $booleans as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		$out['url_style'] = ( isset( $input['url_style'] ) && 'root' === $input['url_style'] ) ? 'root' : 'relative';

		$batch             = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $d['batch_size'];
		$out['batch_size'] = min( 50, max( 1, $batch ) );

		$max_asset_mb        = isset( $input['max_asset_mb'] ) ? absint( $input['max_asset_mb'] ) : $d['max_asset_mb'];
		$out['max_asset_mb'] = min( 1024, max( 1, $max_asset_mb ) );

		$out['public_url']      = isset( $input['public_url'] ) ? esc_url_raw( trim( (string) $input['public_url'] ) ) : '';
		$out['additional_urls'] = isset( $input['additional_urls'] ) ? sanitize_textarea_field( (string) $input['additional_urls'] ) : '';
		$out['exclude_urls']    = isset( $input['exclude_urls'] ) ? sanitize_textarea_field( (string) $input['exclude_urls'] ) : '';

		$out['request_timeout'] = min( 120, max( 1, isset( $input['request_timeout'] ) ? (int) $input['request_timeout'] : $d['request_timeout'] ) );
		$out['request_delay']   = min( 3000, max( 0, isset( $input['request_delay'] ) ? (int) $input['request_delay'] : $d['request_delay'] ) );

		$origin_input        = isset( $input['crawl_origin'] ) ? trim( (string) $input['crawl_origin'] ) : '';
		$out['crawl_origin'] = AOUWAL_Network::sanitize_origin( $origin_input );
		if ( '' !== $origin_input && '' === $out['crawl_origin'] ) {
			self::notice(
				'aouwal_crawl_origin',
				__( 'The custom crawl origin was ignored. Use an http:// or https:// URL with a host, without credentials, a query string or a fragment.', 'aouwal-static-site-export' )
			);
		}

		$out['tls_mode'] = ( isset( $input['tls_mode'] ) && in_array( $input['tls_mode'], array( 'auto', 'strict', 'ca' ), true ) )
			? (string) $input['tls_mode']
			: 'auto';

		$out['tls_ca_file'] = isset( $input['tls_ca_file'] ) ? sanitize_text_field( (string) $input['tls_ca_file'] ) : '';
		if ( 'ca' === $out['tls_mode'] ) {
			if ( '' === $out['tls_ca_file'] || ! is_readable( $out['tls_ca_file'] ) ) {
				self::notice(
					'aouwal_tls_ca_file',
					__( 'The CA bundle path is empty or not readable, so certificates are verified against the system bundle instead.', 'aouwal-static-site-export' )
				);
			}
		}

		// HTTP Basic Auth. A blank password field keeps the stored password, so
		// saving an unrelated setting does not wipe it. Removal is explicit.
		$stored           = self::all();
		$out['auth_user'] = isset( $input['auth_user'] ) ? sanitize_text_field( (string) $input['auth_user'] ) : '';
		$out['auth_pass'] = isset( $stored['auth_pass'] ) ? (string) $stored['auth_pass'] : '';

		if ( ! empty( $input['auth_clear'] ) ) {
			$out['auth_user'] = '';
			$out['auth_pass'] = '';
		} elseif ( isset( $input['auth_pass'] ) && '' !== (string) $input['auth_pass'] ) {
			$encrypted = AOUWAL_Network::encrypt( (string) $input['auth_pass'] );
			if ( is_wp_error( $encrypted ) ) {
				self::notice( 'aouwal_auth_pass', $encrypted->get_error_message() );
			} else {
				$out['auth_pass'] = (string) $encrypted;
			}
		}

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
