<?php
/**
 * Loopback HTTP crawler.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WSE_Crawler
 */
class WSE_Crawler {

	/**
	 * Fetch a URL.
	 *
	 * @param string $url     URL to fetch.
	 * @param bool   $is_asset Whether this is a binary asset.
	 * @return array{body:string,status:int,error:string,headers:array}
	 */
	public function fetch( $url, $is_asset = false ) {
		$args = array(
			'timeout'     => 60,
			'redirection' => 5,
			'sslverify'   => false,
			'user-agent'  => 'WP-Static-Export/' . WSE_VERSION . '; ' . home_url(),
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
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 60,
					'ignore_errors' => true,
					'header'        => "Accept: */*\r\nAccept-Encoding: identity\r\n",
				),
				'ssl'  => array(
					'verify_peer'      => false,
					'verify_peer_name' => false,
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
}
