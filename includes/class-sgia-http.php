<?php
/**
 * Parallel HTTP for the queue: several requests at once (curl_multi via the
 * Requests library bundled with WordPress), configured like wp_remote_request()
 * — same proxy (WP_PROXY_*), CA bundle, https_ssl_verify, http_request_args and
 * pre_http_request filters — and returning the same array|WP_Error shape.
 *
 * Why: on this host each request to Google or to the site itself takes seconds,
 * so doing them one after another let a 20-second cron run handle ~2 URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Http {

	/**
	 * @param array<string|int,array{url:string,method?:string,headers?:array,body?:string,timeout?:int,redirection?:int,limit?:int,user-agent?:string}> $reqs
	 * @param int $concurrency Max requests in flight at once.
	 * @return array<string|int,array|WP_Error> Same keys as $reqs.
	 */
	/**
	 * Can this server really send requests in parallel? Many shared hosts put
	 * curl_multi_exec / curl_multi_init in disable_functions; calling them is a
	 * fatal error, so check first and fall back to one-by-one requests.
	 */
	public static function parallel() {
		static $ok = null;
		if ( null === $ok ) {
			$ok = (bool) apply_filters( 'sgia_parallel_http', self::requests_class() && class_exists( 'WP_HTTP_Requests_Response' ) && class_exists( 'WP_HTTP_Requests_Hooks' ) && self::curl_multi_ok() );
		}
		return $ok;
	}

	private static function curl_multi_ok() {
		$disabled = array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) );
		foreach ( [ 'curl_init', 'curl_exec', 'curl_multi_init', 'curl_multi_exec', 'curl_multi_select', 'curl_multi_add_handle', 'curl_multi_remove_handle', 'curl_multi_info_read', 'curl_multi_getcontent', 'curl_multi_close' ] as $f ) {
			if ( ! function_exists( $f ) || in_array( $f, $disabled, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function requests_class() {
		$c = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
		return ( $c && method_exists( $c, 'request_multiple' ) ) ? $c : '';
	}

	private static function sequential( array $reqs ) {
		$out = [];
		foreach ( $reqs as $k => $r ) {
			$out[ $k ] = wp_remote_request( $r['url'], self::wp_args( $r ) );
		}
		return $out;
	}

	public static function multi( array $reqs, $concurrency = 8 ) {
		$out = [];
		if ( ! $reqs ) {
			return $out;
		}

		if ( ! self::parallel() ) {
			return self::sequential( $reqs );
		}
		$requests_class = self::requests_class();

		$pending = [];
		foreach ( $reqs as $k => $r ) {
			$args = apply_filters( 'http_request_args', self::wp_args( $r ), $r['url'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			$pre  = apply_filters( 'pre_http_request', false, $args, $r['url'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			if ( false !== $pre ) {
				$out[ $k ] = $pre;
				continue;
			}
			$pending[ $k ] = [ $r['url'], $args ];
		}

		foreach ( array_chunk( $pending, max( 1, (int) $concurrency ), true ) as $chunk ) {
			$batch = [];
			foreach ( $chunk as $k => $item ) {
				$batch[ $k ] = self::to_requests( $item[0], $item[1] );
			}

			mbstring_binary_safe_encoding();
			$crashed = false;
			try {
				$responses = call_user_func( [ $requests_class, 'request_multiple' ], $batch );
			} catch ( \Exception $e ) {
				$responses = array_fill_keys( array_keys( $batch ), $e );
			} catch ( \Throwable $e ) { // e.g. a curl_multi function missing at runtime
				$crashed = true;
			}
			reset_mbstring_encoding();

			if ( $crashed ) {
				$sub = [];
				foreach ( $chunk as $k => $unused ) {
					$sub[ $k ] = $reqs[ $k ];
				}
				$out = $sub ? self::sequential( $sub ) + $out : $out;
				continue;
			}

			foreach ( $batch as $k => $unused ) {
				$resp = isset( $responses[ $k ] ) ? $responses[ $k ] : null;
				if ( $resp instanceof \Exception || ! is_object( $resp ) ) {
					$out[ $k ] = new WP_Error( 'http_request_failed', $resp instanceof \Exception ? $resp->getMessage() : 'No response.' );
					continue;
				}
				$http      = new WP_HTTP_Requests_Response( $resp );
				$out[ $k ] = $http->to_array();
			}
		}

		// Preserve the caller's key order.
		$ordered = [];
		foreach ( array_keys( $reqs ) as $k ) {
			$ordered[ $k ] = $out[ $k ];
		}
		return $ordered;
	}

	private static function wp_args( array $r ) {
		$args = [
			'method'              => isset( $r['method'] ) ? $r['method'] : 'GET',
			'timeout'             => isset( $r['timeout'] ) ? (int) $r['timeout'] : 20,
			'redirection'         => isset( $r['redirection'] ) ? (int) $r['redirection'] : 5,
			'headers'             => isset( $r['headers'] ) ? $r['headers'] : [],
			'body'                => isset( $r['body'] ) ? $r['body'] : null,
			'user-agent'          => isset( $r['user-agent'] ) ? $r['user-agent'] : 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			'sslverify'           => true,
			'sslcertificates'     => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'blocking'            => true,
			'reject_unsafe_urls'  => false,
			'stream'              => false,
			'filename'            => null,
			'cookies'             => [],
			'httpversion'         => '1.1',
			'compress'            => false,
			'decompress'          => true,
		];
		if ( ! empty( $r['limit'] ) ) {
			$args['limit_response_size'] = (int) $r['limit'];
		}
		return $args;
	}

	/** Mirror of WP_Http::request() option building for one request. */
	private static function to_requests( $url, array $a ) {
		$options = [
			'timeout'   => $a['timeout'],
			'useragent' => $a['user-agent'],
			'blocking'  => true,
			'hooks'     => new WP_HTTP_Requests_Hooks( $url, $a ),
		];
		if ( empty( $a['redirection'] ) ) {
			$options['follow_redirects'] = false;
		} else {
			$options['redirects'] = $a['redirection'];
		}
		if ( isset( $a['limit_response_size'] ) ) {
			$options['max_bytes'] = $a['limit_response_size'];
		}
		if ( empty( $a['sslverify'] ) ) {
			$options['verify']     = false;
			$options['verifyname'] = false;
		} else {
			$options['verify'] = $a['sslcertificates'];
		}
		$options['verify'] = apply_filters( 'https_ssl_verify', $options['verify'], $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		$type = strtoupper( $a['method'] );
		if ( 'HEAD' !== $type && 'GET' !== $type ) {
			$options['data_format'] = 'body';
		}

		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			$proxy_class        = class_exists( '\WpOrg\Requests\Proxy\Http' ) ? '\WpOrg\Requests\Proxy\Http' : 'Requests_Proxy_HTTP';
			$options['proxy']   = new $proxy_class( $proxy->host() . ':' . $proxy->port() );
			if ( $proxy->use_authentication() ) {
				$options['proxy']->use_authentication = true;
				$options['proxy']->user               = $proxy->username();
				$options['proxy']->pass               = $proxy->password();
			}
		}

		return [
			'url'     => $url,
			'headers' => (array) $a['headers'],
			'data'    => null === $a['body'] ? [] : $a['body'],
			'type'    => $type,
			'options' => $options,
		];
	}
}
