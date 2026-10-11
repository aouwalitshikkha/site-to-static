<?php
/**
 * Loopback HTTP crawler.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Crawler
 */
class AOUWAL_Crawler {

	/**
	 * Fetch a URL.
	 *
	 * @param string $url     URL to fetch.
	 * @param bool   $is_asset Whether this is a binary asset.
	 * @return array{body:string,status:int,error:string,headers:array}
	 */
	public function fetch( $url, $is_asset = false ) {
		if ( ! $this->is_url_allowed_for_fetch( $url ) ) {
			return array(
				'body'    => '',
				'status'  => 0,
				'error'   => __( 'URL is not allowed.', 'aouwal-static-site-export' ),
				'headers' => array(),
			);
		}

		$args = array(
			'timeout'     => 60,
			'redirection' => 5,
			'sslverify'   => ! $this->is_local_host( $url ),
			'user-agent'  => 'aouwal-static-site-export/' . AOUWAL_VERSION . '; ' . home_url(),
			'headers'     => array(
				'Accept'          => $is_asset ? '*/*' : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Encoding' => 'identity',
				'Cache-Control'   => 'no-cache',
			),
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$fallback = $this->fetch_fallback( $url );
			if ( null !== $fallback ) {
				return $fallback;
			}
			return array(
				'body'    => '',
				'status'  => 0,
				'error'   => $response->get_error_message(),
				'headers' => array(),
			);
		}

		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $response ) as $key => $value ) {
			$headers[ strtolower( $key ) ] = $value;
		}

		return array(
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'error'   => '',
			'headers' => $headers,
		);
	}

	/**
	 * Fallback fetch using a stream context.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	protected function fetch_fallback( $url ) {
		if ( ! $this->is_url_allowed_for_fetch( $url ) ) {
			return null;
		}
		$verify = ! $this->is_local_host( $url );
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 60,
					'ignore_errors' => true,
					'header'        => "Accept: */*\r\nAccept-Encoding: identity\r\n",
				),
				'ssl'  => array(
					'verify_peer'      => $verify,
					'verify_peer_name' => $verify,
				),
			)
		);

		$body = @file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $body ) {
			return null;
		}

		$status = 200;
		if ( isset( $http_response_header[0] ) && preg_match( '#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m ) ) {
			$status = (int) $m[1];
		}

		return array(
			'body'    => (string) $body,
			'status'  => $status,
			'error'   => '',
			'headers' => array(),
		);
	}

	/**
	 * Whether a URL is allowed to be fetched server-side (SSRF guard).
	 *
	 * Only http(s) URLs without embedded credentials pass. The site's own
	 * hosts (home/site URL, including loopback development hosts) are always
	 * allowed. Any other host must resolve exclusively to public IP addresses;
	 * unresolvable hosts and private/reserved ranges are refused.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_url_allowed_for_fetch( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );
		if ( in_array( $host, $this->site_hosts(), true ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		$ips = gethostbynamel( $host );
		if ( ! is_array( $ips ) || empty( $ips ) ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hosts belonging to this site (allowed fetch targets).
	 *
	 * @return string[]
	 */
	protected function site_hosts() {
		static $hosts = null;
		if ( null === $hosts ) {
			$hosts = array();
			foreach ( array( home_url(), site_url() ) as $u ) {
				$h = wp_parse_url( $u, PHP_URL_HOST );
				if ( $h ) {
					$hosts[] = strtolower( $h );
				}
			}
			$hosts = array_values( array_unique( $hosts ) );
		}
		return $hosts;
	}

	/**
	 * Whether a URL points to a local development host.
	 * SSL verification is only relaxed for these hosts.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	protected function is_local_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return true;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		foreach ( array( '.local', '.localhost', '.test', '.invalid' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}
}
