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
	 * Why the last is_url_allowed_for_fetch() call said no.
	 *
	 * @var string
	 */
	protected $blocked_reason = '';

	/**
	 * Fetch a URL.
	 *
	 * Redirects are followed manually so that every hop is checked by the
	 * SSRF guard; a public URL must not be able to bounce the request to a
	 * private address.
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
				'error'   => $this->last_error(),
				'headers' => array(),
			);
		}

		$args = array(
			'timeout'     => AOUWAL_Network::timeout(),
			'redirection' => 0,
			'user-agent'  => 'aouwal-static-site-export/' . AOUWAL_VERSION . '; ' . home_url(),
		);

		$ca_bundle = AOUWAL_Network::ca_file();
		if ( '' !== $ca_bundle ) {
			$args['sslcertificates'] = $ca_bundle;
		}

		// Assets are capped so a single large file cannot exhaust PHP memory.
		// The cURL transport aborts the transfer (CURLE_WRITE_ERROR) when the
		// body passes the cap, while the streams transport returns a truncated
		// body, so both signals are checked below.
		$limit = $is_asset ? $this->asset_size_limit() : 0;
		if ( $limit > 0 ) {
			$args['limit_response_size'] = $limit + 1;
		}

		$current = $this->apply_origin( $url );

		for ( $hop = 0; $hop <= 5; $hop++ ) {
			if ( ! $this->is_url_allowed_for_fetch( $current ) ) {
				return array(
					'body'    => '',
					'status'  => 0,
					/* translators: %s: URL. */
					'error'   => sprintf( __( 'Redirect blocked: %1$s (%2$s)', 'aouwal-static-site-export' ), $current, $this->last_error() ),
					'headers' => array(),
				);
			}

			$this->throttle();

			// Headers are rebuilt for every hop: a redirect to another host must
			// not inherit this site's credentials.
			$args['headers'] = $this->request_headers( $current, $is_asset );

			// TLS policy is per hop as well, so a local origin is judged on its
			// own host rather than on the site URL that was mapped to it.
			$args['sslverify'] = AOUWAL_Network::verify_tls( $this->is_local_host( $current ) );

			$response = wp_remote_get( $current, $args );

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				if ( $limit > 0 && $this->is_size_abort( $message ) ) {
					$message = $this->too_large_message( $limit );
				}
				return array(
					'body'    => '',
					'status'  => 0,
					'error'   => $message,
					'headers' => array(),
				);
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( ! $this->is_redirect( $status ) || '' === $location ) {
				break;
			}
			if ( 5 === $hop ) {
				return array(
					'body'    => '',
					'status'  => $status,
					'error'   => __( 'Too many redirects.', 'aouwal-static-site-export' ),
					'headers' => array(),
				);
			}

			$current = $this->apply_origin( WP_Http::make_absolute_url( $location, $current ) );
		}

		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $response ) as $key => $value ) {
			$headers[ strtolower( $key ) ] = $value;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( $limit > 0 && strlen( $body ) > $limit ) {
			return array(
				'body'    => '',
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'error'   => $this->too_large_message( $limit ),
				'headers' => $headers,
			);
		}

		return array(
			'body'    => $body,
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'error'   => '',
			'headers' => $headers,
		);
	}

	/**
	 * Request headers for one hop.
	 *
	 * @param string $url      Request URL.
	 * @param bool   $is_asset Whether this is a binary asset.
	 * @return array
	 */
	protected function request_headers( $url, $is_asset ) {
		$headers = array(
			'Accept'          => $is_asset ? '*/*' : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Encoding' => 'identity',
			'Cache-Control'   => 'no-cache',
		);

		$auth = $this->auth_header( $url );
		if ( '' !== $auth ) {
			$headers['Authorization'] = $auth;
		}

		return $headers;
	}

	/**
	 * Authorization header for a request URL, or an empty string.
	 *
	 * Credentials are only ever sent to the site's own hosts and to the
	 * admin-configured crawl origin. External asset hosts (CDNs, Google Fonts,
	 * Gravatars) must never see them.
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	protected function auth_header( $url ) {
		$credentials = AOUWAL_Network::credentials();
		if ( '' === $credentials['user'] && '' === $credentials['pass'] ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}

		$allowed = $this->site_hosts();
		$origin  = AOUWAL_Network::origin_host();
		if ( '' !== $origin ) {
			$allowed[] = $origin;
		}

		if ( ! in_array( $host, $allowed, true ) ) {
			return '';
		}

		return 'Basic ' . base64_encode( $credentials['user'] . ':' . $credentials['pass'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth encoding, not obfuscation.
	}

	/**
	 * Wait between requests when a delay is configured.
	 *
	 * The last request time is static so the delay applies across the crawler
	 * instances that the exporter creates per page and per asset processor.
	 * It is measured from the start of the previous request, so a request that
	 * already took longer than the delay is not delayed again.
	 *
	 * @return void
	 */
	protected function throttle() {
		static $last = 0.0;

		$delay = AOUWAL_Network::delay_ms() / 1000;
		if ( $delay > 0 && $last > 0 ) {
			$wait = $delay - ( microtime( true ) - $last );
			if ( $wait > 0 ) {
				usleep( (int) ceil( $wait * 1000000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.usleep -- Deliberate inter-request delay, not a blocking wait for a resource.
			}
		}

		$last = microtime( true );
	}

	/**
	 * Map a site URL to the custom crawl origin, when one is configured.
	 *
	 * Only URLs on the site's own hosts are rewritten. Links, asset paths and
	 * the sitemap keep using the site URL, because this only decides where the
	 * request goes.
	 *
	 * @param string $url Site URL.
	 * @return string Request URL.
	 */
	protected function apply_origin( $url ) {
		$origin = AOUWAL_Network::origin();
		if ( '' === $origin ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		if ( ! in_array( strtolower( (string) $parts['host'] ), $this->site_hosts(), true ) ) {
			return $url;
		}

		$target = wp_parse_url( $origin );
		if ( ! is_array( $target ) || empty( $target['host'] ) ) {
			return $url;
		}

		$site_base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$site_base = ( '/' === $site_base ) ? '' : untrailingslashit( $site_base );

		$origin_base = isset( $target['path'] ) ? (string) $target['path'] : '';
		$origin_base = ( '/' === $origin_base ) ? '' : untrailingslashit( $origin_base );

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' !== $site_base && 0 === strpos( $path, $site_base ) ) {
			$path = substr( $path, strlen( $site_base ) );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		$request = strtolower( (string) $target['scheme'] ) . '://' . strtolower( (string) $target['host'] );
		if ( ! empty( $target['port'] ) ) {
			$request .= ':' . (int) $target['port'];
		}
		$request .= $origin_base . $path;

		if ( ! empty( $parts['query'] ) ) {
			$request .= '?' . $parts['query'];
		}

		return $request;
	}

	/**
	 * Whether an HTTP status is a redirect that can be followed.
	 *
	 * @param int $status Status code.
	 * @return bool
	 */
	protected function is_redirect( $status ) {
		return in_array( $status, array( 301, 302, 303, 307, 308 ), true );
	}

	/**
	 * Reason the last URL was refused, for use in error messages.
	 *
	 * @return string
	 */
	public function last_error() {
		return '' !== $this->blocked_reason ? $this->blocked_reason : __( 'URL is not allowed.', 'aouwal-static-site-export' );
	}

	/**
	 * Maximum asset size in bytes, or 0 for no limit.
	 *
	 * @return int
	 */
	protected function asset_size_limit() {
		$mb = (int) AOUWAL_Settings::get( 'max_asset_mb', 25 );
		return $mb > 0 ? $mb * MB_IN_BYTES : 0;
	}

	/**
	 * Whether a transport error came from hitting the response size cap.
	 *
	 * @param string $message Error message.
	 * @return bool
	 */
	protected function is_size_abort( $message ) {
		return false !== stripos( $message, 'cURL error 23' ) || false !== stripos( $message, 'Failed writing body' );
	}

	/**
	 * Build the "file is too large" error message.
	 *
	 * @param int $limit Limit in bytes.
	 * @return string
	 */
	protected function too_large_message( $limit ) {
		return sprintf(
			/* translators: %s: size limit in megabytes. */
			__( 'File is larger than the %s MB asset limit. Raise the limit in Settings or exclude this URL.', 'aouwal-static-site-export' ),
			number_format_i18n( $limit / MB_IN_BYTES, 0 )
		);
	}

	/**
	 * Whether a URL is allowed to be fetched server-side (SSRF guard).
	 *
	 * Only http(s) URLs without embedded credentials pass. The site's own
	 * hosts (home/site URL, including loopback development hosts) are always
	 * allowed, and so is the host of an admin-configured crawl origin, which
	 * is trusted like the site because it exists to reach the same install
	 * through a proxy or container name. Any other host must resolve
	 * exclusively to public IP addresses: both A and AAAA records are checked,
	 * and unresolvable hosts and private/reserved ranges are refused.
	 *
	 * A trusted crawl origin is still refused when it points at a link-local
	 * or cloud metadata address (169.254.0.0/16, fe80::/10), so the setting
	 * cannot be used to reach the instance metadata service.
	 *
	 * Residual risk (DNS rebinding): the guard resolves a host and the HTTP
	 * transport resolves it again for the actual request, so a DNS server that
	 * changes its answer between the two lookups can still point the request at
	 * a private address. Blocking this fully would require pinning the
	 * validated IP for the connection (CURLOPT_RESOLVE / --resolve), which the
	 * WordPress HTTP API does not expose. Mitigations kept in place: every
	 * redirect hop is validated separately, non-public literals and IPv4-mapped
	 * IPv6 addresses are refused, and results are cached per request so a
	 * single export cannot be steered by repeated lookups.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_url_allowed_for_fetch( $url ) {
		$this->blocked_reason = '';

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			$this->blocked_reason = __( 'The URL has no host.', 'aouwal-static-site-export' );
			return false;
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$this->blocked_reason = __( 'Only http and https URLs can be fetched.', 'aouwal-static-site-export' );
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			$this->blocked_reason = __( 'URLs with embedded credentials are not fetched.', 'aouwal-static-site-export' );
			return false;
		}

		$host = trim( strtolower( $parts['host'] ), '[]' );
		if ( in_array( $host, $this->site_hosts(), true ) ) {
			return true;
		}

		// The custom crawl origin is a deliberate admin choice that exists so a
		// proxied or containerised install can be reached. It is trusted like
		// the site host, except for link-local and metadata addresses.
		$origin_host = AOUWAL_Network::origin_host();
		if ( '' !== $origin_host && $host === $origin_host ) {
			if ( $this->is_link_local( $host ) ) {
				$this->blocked_reason = __( 'The custom crawl origin points to a link-local or cloud metadata address.', 'aouwal-static-site-export' );
				return false;
			}
			return true;
		}

		// IP literal (IPv4 or IPv6).
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! $this->is_public_ip( $host ) ) {
				$this->blocked_reason = __( 'The address is private, reserved or loopback.', 'aouwal-static-site-export' );
				return false;
			}
			return true;
		}

		$ips = $this->resolve_host( $host );
		if ( empty( $ips ) ) {
			$this->blocked_reason = __( 'The host name could not be resolved (DNS lookup failed).', 'aouwal-static-site-export' );
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! $this->is_public_ip( $ip ) ) {
				$this->blocked_reason = __( 'The host name resolves to a private, reserved or loopback address.', 'aouwal-static-site-export' );
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an IP address is publicly routable.
	 *
	 * IPv4-mapped IPv6 addresses (::ffff:127.0.0.1) are judged as IPv4 so the
	 * loopback/private checks cannot be side-stepped.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	protected function is_public_ip( $ip ) {
		$ip = (string) $ip;
		if ( preg_match( '/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $matches ) ) {
			$ip = $matches[1];
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether a host points at a link-local or cloud metadata address.
	 *
	 * Used only for the trusted custom crawl origin: 169.254.169.254 (and the
	 * equivalent IPv6 ranges) must never be reachable through a setting, even
	 * though the origin host otherwise skips the public-IP check.
	 *
	 * @param string $host Host name or IP literal.
	 * @return bool
	 */
	protected function is_link_local( $host ) {
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = $this->resolve_host( $host );
		}

		foreach ( $ips as $ip ) {
			$ip = (string) $ip;
			// Judge IPv4-mapped IPv6 addresses as IPv4.
			if ( preg_match( '/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $matches ) ) {
				$ip = $matches[1];
			}

			if ( false !== strpos( $ip, ':' ) ) {
				$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on malformed input; the array check below handles it.
				if ( is_string( $packed ) && 16 === strlen( $packed ) ) {
					// fe80::/10 link-local.
					if ( 0xfe === ord( $packed[0] ) && 0x80 === ( ord( $packed[1] ) & 0xc0 ) ) {
						return true;
					}
				}
				continue;
			}

			if ( 0 === strpos( $ip, '169.254.' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a host name to its A and AAAA addresses.
	 *
	 * Results are cached for the request so a batch does not repeat lookups
	 * for the same CDN host. PHP offers no per-lookup timeout for
	 * dns_get_record(), so a black-holed resolver can still stall a request;
	 * the HTTP timeout does not cover DNS.
	 *
	 * @param string $host Host name.
	 * @return string[] IP addresses, empty when the lookup fails.
	 */
	protected function resolve_host( $host ) {
		static $cache = array();

		if ( isset( $cache[ $host ] ) ) {
			return $cache[ $host ];
		}

		$ips = array();

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() warns on NXDOMAIN and on hosts without the requested record; the empty result is handled below.
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					} elseif ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		// Fallback for hosts without AAAA support in dns_get_record() results.
		if ( empty( $ips ) ) {
			$v4 = gethostbynamel( $host );
			if ( is_array( $v4 ) ) {
				$ips = $v4;
			}
		}

		$ips            = array_values( array_unique( $ips ) );
		$cache[ $host ] = $ips;

		return $ips;
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
