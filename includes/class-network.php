<?php
/**
 * Crawl network settings and credential storage.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Network
 *
 * One place for the network-related settings added in 1.5.0: request timeout,
 * delay between requests, a custom crawl origin, TLS verification and HTTP
 * Basic Auth credentials.
 *
 * Credentials are encrypted at rest with sodium_crypto_secretbox() when the
 * sodium extension is available and with openssl AES-256-GCM otherwise. The
 * key is derived from the site's authentication salts, so a database dump on
 * its own cannot be decrypted without wp-config.php. Passwords are never
 * returned to the settings page: the form only shows whether one is stored.
 */
class AOUWAL_Network {

	/**
	 * Runtime overrides, used by WP-CLI so a flag does not rewrite settings.
	 *
	 * @var array
	 */
	protected static $overrides = array();

	/**
	 * Decrypted passwords for this request, keyed by the stored value.
	 *
	 * @var array
	 */
	protected static $decrypted = array();

	/**
	 * Set runtime overrides.
	 *
	 * @param array $values Key => value. Recognized keys are request_timeout,
	 *                      request_delay, crawl_origin, auth_user, auth_pass.
	 * @return void
	 */
	public static function set_overrides( array $values ) {
		self::$overrides = $values;
	}

	/**
	 * Read a network setting, honoring runtime overrides.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	protected static function value( $key, $fallback ) {
		if ( array_key_exists( $key, self::$overrides ) ) {
			return self::$overrides[ $key ];
		}
		return AOUWAL_Settings::get( $key, $fallback );
	}

	/**
	 * Request timeout in seconds.
	 *
	 * The default matches the value that was hard-coded before 1.5.0, so an
	 * untouched install crawls exactly as it did before.
	 *
	 * @return int
	 */
	public static function timeout() {
		return min( 120, max( 1, (int) self::value( 'request_timeout', 60 ) ) );
	}

	/**
	 * Delay between two HTTP requests in milliseconds.
	 *
	 * @return int
	 */
	public static function delay_ms() {
		return min( 3000, max( 0, (int) self::value( 'request_delay', 0 ) ) );
	}

	/**
	 * Custom crawl origin, or an empty string when the site is crawled at its
	 * own URL.
	 *
	 * @return string
	 */
	public static function origin() {
		return self::sanitize_origin( (string) self::value( 'crawl_origin', '' ) );
	}

	/**
	 * Normalize a crawl origin to scheme://host[:port][/path].
	 *
	 * @param string $value Raw value.
	 * @return string Empty string when the value is not a usable origin.
	 */
	public static function sanitize_origin( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		// An origin with credentials, a query or a fragment is almost always a
		// mistake: credentials belong in the Basic Auth fields, and the other
		// two would be dropped from every crawled URL.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$out = $scheme . '://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$out .= ':' . (int) $parts['port'];
		}

		$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		if ( '' !== $path && '/' !== $path ) {
			$out .= $path;
		}

		return $out;
	}

	/**
	 * Host of the custom crawl origin, or an empty string.
	 *
	 * @return string
	 */
	public static function origin_host() {
		$host = wp_parse_url( self::origin(), PHP_URL_HOST );
		return $host ? strtolower( (string) $host ) : '';
	}

	/**
	 * TLS verification mode.
	 *
	 * Modes:
	 *
	 * - auto: verify, except for local development hosts (the pre-1.5.0 rule).
	 * - strict: always verify.
	 * - ca: verify against a custom CA bundle.
	 *
	 * @return string
	 */
	public static function tls_mode() {
		$mode = (string) self::value( 'tls_mode', 'auto' );
		return in_array( $mode, array( 'auto', 'strict', 'ca' ), true ) ? $mode : 'auto';
	}

	/**
	 * Absolute path to a custom CA bundle, or an empty string.
	 *
	 * @return string
	 */
	public static function ca_file() {
		if ( 'ca' !== self::tls_mode() ) {
			return '';
		}

		$file = (string) self::value( 'tls_ca_file', '' );
		$file = trim( $file );
		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * Whether a request to the given host should verify the TLS certificate.
	 *
	 * @param bool $is_local Whether the host is a local development host.
	 * @return bool
	 */
	public static function verify_tls( $is_local ) {
		$mode = self::tls_mode();
		if ( 'strict' === $mode || 'ca' === $mode ) {
			return true;
		}
		return ! $is_local;
	}

	/**
	 * Stored username, or an empty string.
	 *
	 * @return string
	 */
	public static function username() {
		$user = (string) self::value( 'auth_user', '' );
		if ( '' === $user && isset( self::$overrides['auth_user'] ) ) {
			$user = (string) self::$overrides['auth_user'];
		}
		return $user;
	}

	/**
	 * Whether a password is stored.
	 *
	 * @return bool
	 */
	public static function has_password() {
		if ( isset( self::$overrides['auth_pass'] ) && '' !== (string) self::$overrides['auth_pass'] ) {
			return true;
		}
		return '' !== (string) self::value( 'auth_pass', '' );
	}

	/**
	 * Whether any credential is configured.
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		return '' !== self::username() || self::has_password();
	}

	/**
	 * Decrypted credentials.
	 *
	 * @return array{user:string,pass:string}
	 */
	public static function credentials() {
		$user = self::username();

		if ( isset( self::$overrides['auth_pass'] ) ) {
			$pass = (string) self::$overrides['auth_pass'];
		} else {
			$pass = self::decrypt( (string) self::value( 'auth_pass', '' ) );
		}

		if ( '' === $user && '' === $pass ) {
			return array(
				'user' => '',
				'pass' => '',
			);
		}

		return array(
			'user' => $user,
			'pass' => $pass,
		);
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plain Plain text.
	 * @return string|WP_Error Base64 payload with an algorithm prefix, empty
	 *                         string for an empty secret, or WP_Error when the
	 *                         server has no usable crypto extension.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
			try {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			} catch ( Exception $e ) {
				return new WP_Error( 'aouwal_auth_nonce', __( 'Could not generate a random value to encrypt the password.', 'aouwal-static-site-export' ) );
			}

			return 's1' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding our own encrypted payload for storage, not obfuscating code.
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			try {
				$iv = random_bytes( 12 );
			} catch ( Exception $e ) {
				return new WP_Error( 'aouwal_auth_nonce', __( 'Could not generate a random value to encrypt the password.', 'aouwal-static-site-export' ) );
			}

			$tag = '';
			$out = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $out && '' !== $tag ) {
				return 'o1' . base64_encode( $iv . $tag . $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding our own encrypted payload for storage, not obfuscating code.
			}
		}

		return new WP_Error(
			'aouwal_auth_crypto',
			__( 'The password cannot be stored securely because this server has neither the sodium extension nor OpenSSL support.', 'aouwal-static-site-export' )
		);
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $blob Stored value.
	 * @return string Plain text, or an empty string when it cannot be read.
	 */
	public static function decrypt( $blob ) {
		$blob = (string) $blob;
		if ( '' === $blob ) {
			return '';
		}

		if ( isset( self::$decrypted[ $blob ] ) ) {
			return self::$decrypted[ $blob ];
		}

		$key  = self::key();
		$raw  = base64_decode( substr( $blob, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own stored payload, not obfuscated code.
		$pass = '';

		if ( false === $raw ) {
			return '';
		}

		if ( 0 === strpos( $blob, 's1' ) && function_exists( 'sodium_crypto_secretbox_open' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
			if ( is_string( $plain ) ) {
				$pass = $plain;
			}
		} elseif ( 0 === strpos( $blob, 'o1' ) && function_exists( 'openssl_decrypt' ) ) {
			$iv    = substr( $raw, 0, 12 );
			$tag   = substr( $raw, 12, 16 );
			$ct    = substr( $raw, 28 );
			$plain = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( is_string( $plain ) ) {
				$pass = $plain;
			}
		}

		self::$decrypted[ $blob ] = $pass;

		return $pass;
	}

	/**
	 * Encryption key derived from the site salts.
	 *
	 * @return string 32 raw bytes.
	 */
	protected static function key() {
		$salt  = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$salt .= defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';
		if ( '' === $salt ) {
			$salt = wp_salt( 'auth' );
		}

		return hash( 'sha256', 'aouwal-static-site-export|' . $salt, true );
	}
}
