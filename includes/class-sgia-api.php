<?php
/**
 * Google Indexing API client: auth, publish, metadata, quota, log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_API {

	const OPT_KEY      = 'sgia_service_account';
	const OPT_SETTINGS = 'sgia_settings';
	const OPT_LOG      = 'sgia_log';
	const OPT_QUOTA    = 'sgia_quota';
	const OPT_STATS    = 'sgia_stats';
	const OPT_VERIFIED = 'sgia_verified_at';
	const TOKEN_CACHE  = 'sgia_access_token_v2'; // v2: token also carries the Search Console scope
	const META_URL     = '_sgia_submitted_url';
	const META_LAST    = '_sgia_last';
	const SCOPE        = 'https://www.googleapis.com/auth/indexing https://www.googleapis.com/auth/webmasters.readonly';
	const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
	const PUBLISH_URL  = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
	const METADATA_URL = 'https://indexing.googleapis.com/v3/urlNotifications/metadata';
	const LOG_MAX      = 300;

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return [
			'auto'            => 1,
			'types'           => [ 'post', 'page' ],
			'daily_limit'     => 200,
			// Search Console (URL Inspection)
			'gsc_property'    => '',
			'inspect_before'  => 1,
			'recheck_days'    => 3,
			'inspect_limit'   => 1500,
			// Pre-send checks
			'precheck'        => 1,
			'precheck_http'   => 1,
			// IndexNow
			'indexnow'        => 0,
			'indexnow_auto'   => 1,
			'indexnow_queue'  => 1,
			'indexnow_key'    => '',
			// WooCommerce
			'woo_priority'    => 1,
			'woo_skip_oos'    => 1,
			// Reports
			'report_email'    => 0,
			'report_email_to' => '',
			'report_msgr'     => '',
			'report_token'    => '',
			'report_chat'     => '',
			'report_api_base' => '',
			'report_alerts'   => 1,
		];
	}

	public static function settings() {
		$s = get_option( self::OPT_SETTINGS, [] );
		$s = wp_parse_args( is_array( $s ) ? $s : [], self::defaults() );

		$s['types']       = is_array( $s['types'] ) ? array_values( $s['types'] ) : [];
		$s['daily_limit'] = max( 1, (int) $s['daily_limit'] );
		foreach ( [ 'auto', 'inspect_before', 'precheck', 'precheck_http', 'indexnow', 'indexnow_auto', 'indexnow_queue', 'woo_priority', 'woo_skip_oos', 'report_email', 'report_alerts' ] as $b ) {
			$s[ $b ] = empty( $s[ $b ] ) ? 0 : 1;
		}
		$s['recheck_days']  = max( 0, min( 30, (int) $s['recheck_days'] ) );
		$s['inspect_limit'] = max( 0, min( 2000, (int) $s['inspect_limit'] ) );
		$s['report_msgr']   = in_array( $s['report_msgr'], [ 'telegram', 'bale' ], true ) ? $s['report_msgr'] : '';
		return $s;
	}

	public static function update_settings( array $new ) {
		update_option( self::OPT_SETTINGS, wp_parse_args( $new, self::settings() ) );
	}

	/* ------------------------------------------------------------------ *
	 * Service account key
	 * ------------------------------------------------------------------ */

	public static function key_from_file() {
		return defined( 'SGIA_KEY_FILE' );
	}

	public static function credentials() {
		if ( self::key_from_file() ) {
			if ( ! is_readable( SGIA_KEY_FILE ) ) {
				return new WP_Error( 'sgia_no_key', 'فایل تعریف‌شده در SGIA_KEY_FILE قابل خواندن نیست.' );
			}
			$json = file_get_contents( SGIA_KEY_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			$json = get_option( self::OPT_KEY, '' );
		}

		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'sgia_no_key', 'کلید سرویس اکانت تنظیم نشده است.' );
		}
		return $data;
	}

	public static function has_key() {
		return ! is_wp_error( self::credentials() );
	}

	public static function validate_key_json( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sgia_bad_json', 'محتوای واردشده JSON معتبر نیست.' );
		}
		if ( isset( $data['type'] ) && 'service_account' !== $data['type'] ) {
			return new WP_Error( 'sgia_bad_json', 'این فایل کلید «سرویس اکانت» نیست. از بخش Service Accounts کلید JSON بسازید.' );
		}
		if ( empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'sgia_bad_json', 'فیلدهای client_email یا private_key در فایل وجود ندارد.' );
		}
		if ( ! function_exists( 'openssl_pkey_get_private' ) ) {
			return new WP_Error( 'sgia_openssl', 'اکستنشن OpenSSL در PHP فعال نیست.' );
		}
		if ( ! openssl_pkey_get_private( $data['private_key'] ) ) {
			return new WP_Error( 'sgia_bad_json', 'کلید خصوصی داخل فایل قابل خواندن نیست.' );
		}
		return $data;
	}

	public static function save_key( $json ) {
		$data = self::validate_key_json( $json );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		update_option( self::OPT_KEY, wp_json_encode( $data ), false );
		delete_transient( self::TOKEN_CACHE );
		delete_option( self::OPT_VERIFIED );
		return $data;
	}

	public static function delete_key() {
		delete_option( self::OPT_KEY );
		delete_option( self::OPT_VERIFIED );
		delete_transient( self::TOKEN_CACHE );
	}

	/* ------------------------------------------------------------------ *
	 * OAuth2 (service account JWT bearer)
	 * ------------------------------------------------------------------ */

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function access_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_CACHE );
			if ( $cached ) {
				return $cached;
			}
		}

		$cred = self::credentials();
		if ( is_wp_error( $cred ) ) {
			return $cred;
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'sgia_openssl', 'اکستنشن OpenSSL در PHP فعال نیست.' );
		}

		$now    = time();
		$header = self::b64url( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$claims = self::b64url( wp_json_encode( [
			'iss'   => $cred['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		] ) );
		$input = $header . '.' . $claims;

		$key = openssl_pkey_get_private( $cred['private_key'] );
		if ( ! $key ) {
			return new WP_Error( 'sgia_key', 'کلید خصوصی سرویس اکانت قابل خواندن نیست.' );
		}
		$signature = '';
		if ( ! openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'sgia_sign', 'امضای JWT ناموفق بود.' );
		}

		$res = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $input . '.' . self::b64url( $signature ),
			],
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$raw  = wp_remote_retrieve_body( $res );
		$body = json_decode( $raw, true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : $raw );
			return new WP_Error( 'sgia_token', 'دریافت توکن از گوگل ناموفق بود: ' . $err );
		}

		$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 120 : 3300;
		set_transient( self::TOKEN_CACHE, $body['access_token'], max( 60, $ttl ) );
		update_option( self::OPT_VERIFIED, time(), false );
		return $body['access_token'];
	}

	/* ------------------------------------------------------------------ *
	 * HTTP
	 * ------------------------------------------------------------------ */

	/** Authenticated call to any Google API this token's scopes cover. */
	public static function request( $method, $endpoint, $body = null ) {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = self::access_token( $attempt > 0 );
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$args = [
				'method'  => $method,
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
			];
			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
			}

			$res = wp_remote_request( $endpoint, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 401 === $code && 0 === $attempt ) {
				delete_transient( self::TOKEN_CACHE );
				continue;
			}

			$raw = wp_remote_retrieve_body( $res );
			return [
				'code' => $code,
				'body' => json_decode( $raw, true ),
				'raw'  => $raw,
			];
		}
		return new WP_Error( 'sgia_auth', 'احراز هویت ناموفق بود.' );
	}

	/* ------------------------------------------------------------------ *
	 * Public operations
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $url
	 * @param string $type    URL_UPDATED | URL_DELETED
	 * @param string $source  auto | manual | bulk | post
	 * @param int    $post_id
	 * @return array|WP_Error
	 */
	/** Syntactic check only: Google fetches the URL, not this server, so no DNS lookup. */
	public static function valid_url( $url ) {
		if ( ! $url ) {
			return false;
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		// filter_var() only understands ASCII: punycode the host and percent-encode
		// non-ASCII path chars (Persian slugs) for the check only.
		$ascii = $url;
		if ( $host && preg_match( '/[^\x20-\x7e]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$puny = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( $puny ) {
				$ascii = str_replace( $host, $puny, $ascii );
			}
		}
		$ascii = preg_replace_callback( '/[^\x20-\x7e]+/u', function ( $m ) {
			return rawurlencode( $m[0] );
		}, $ascii );

		if ( ! filter_var( $ascii, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ! $host ) {
			return false;
		}
		return false !== strpos( $host, '.' ) || 'localhost' === $host;
	}

	public static function publish( $url, $type = 'URL_UPDATED', $source = 'manual', $post_id = 0 ) {
		$type = ( 'URL_DELETED' === $type ) ? 'URL_DELETED' : 'URL_UPDATED';
		$url  = esc_url_raw( trim( (string) $url ) );

		if ( ! self::valid_url( $url ) ) {
			$res = new WP_Error( 'sgia_bad_url', 'آدرس نامعتبر است.' );
		} elseif ( self::quota()['remaining'] <= 0 ) {
			$res = new WP_Error( 'sgia_quota', 'سهمیه روزانه تعیین‌شده در تنظیمات تمام شده است.' );
		} else {
			$res = self::request( 'POST', self::PUBLISH_URL, [ 'url' => $url, 'type' => $type ] );
			if ( ! is_wp_error( $res ) ) {
				self::bump_quota();
			}
		}

		self::record( $url, $type, $res, $source, $post_id );
		return $res;
	}

	public static function metadata( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( ! self::valid_url( $url ) ) {
			return new WP_Error( 'sgia_bad_url', 'آدرس نامعتبر است.' );
		}
		return self::request( 'GET', self::METADATA_URL . '?url=' . rawurlencode( $url ) );
	}

	public static function is_ok( $res ) {
		return ! is_wp_error( $res ) && $res['code'] >= 200 && $res['code'] < 300;
	}

	public static function error_text( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res->get_error_message();
		}
		if ( ! empty( $res['body']['error']['message'] ) ) {
			return $res['body']['error']['message'];
		}
		return 'HTTP ' . $res['code'] . ( $res['raw'] ? ': ' . wp_strip_all_tags( $res['raw'] ) : '' );
	}

	/** A short Persian hint that tells the user how to fix a failure. */
	public static function hint( $res ) {
		if ( self::is_ok( $res ) ) {
			return '';
		}
		if ( is_wp_error( $res ) ) {
			switch ( $res->get_error_code() ) {
				case 'sgia_no_key':
					return 'از تب «تنظیمات» فایل JSON سرویس اکانت را بارگذاری کنید.';
				case 'sgia_quota':
					return 'سقف روزانه را در تنظیمات بالا ببرید یا بعد از نیمه‌شب به وقت Pacific دوباره تلاش کنید.';
				case 'sgia_token':
					if ( false !== stripos( $res->get_error_message(), 'invalid_grant' ) ) {
						return 'ساعت سرور را بررسی کنید؛ یا کلید حذف/منقضی شده و باید کلید جدید بسازید.';
					}
					return 'کلید سرویس اکانت را بررسی کنید.';
				case 'http_request_failed':
					return 'سرور شما به googleapis.com دسترسی ندارد (فایروال، DNS یا cURL).';
			}
			return '';
		}

		$msg = strtolower( self::error_text( $res ) );
		switch ( (int) $res['code'] ) {
			case 403:
				if ( false !== strpos( $msg, 'has not been used' ) || false !== strpos( $msg, 'disabled' ) ) {
					return 'Web Search Indexing API را در Google Cloud Console برای این پروژه فعال کنید.';
				}
				return 'ایمیل سرویس اکانت را در Search Console با نقش Owner (نه Full) اضافه کنید.';
			case 429:
				return 'سهمیه گوگل پر شده؛ بعد از نیمه‌شب به وقت Pacific دوباره تلاش کنید.';
			case 400:
				return 'آدرس یا نوع درخواست نامعتبر است.';
			case 404:
				return 'هنوز هیچ اعلانی برای این آدرس در گوگل ثبت نشده.';
		}
		return '';
	}

	/** Normalized result for AJAX responses. */
	public static function payload( $res ) {
		$ok = self::is_ok( $res );
		return [
			'ok'   => $ok,
			'code' => is_wp_error( $res ) ? 0 : (int) $res['code'],
			'msg'  => $ok ? 'ارسال شد' : self::error_text( $res ),
			'hint' => self::hint( $res ),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Quota (resets at midnight America/Los_Angeles, like Google's)
	 * ------------------------------------------------------------------ */

	public static function pacific_day( $offset_days = 0 ) {
		$dt = new DateTime( 'now', new DateTimeZone( 'America/Los_Angeles' ) );
		if ( $offset_days ) {
			$dt->modify( sprintf( '%+d day', $offset_days ) );
		}
		return $dt->format( 'Y-m-d' );
	}

	public static function quota() {
		$q     = get_option( self::OPT_QUOTA, [] );
		$day   = self::pacific_day();
		$used  = ( is_array( $q ) && isset( $q['day'] ) && $q['day'] === $day ) ? (int) $q['count'] : 0;
		$limit = self::settings()['daily_limit'];
		$next  = new DateTime( 'tomorrow', new DateTimeZone( 'America/Los_Angeles' ) );

		return [
			'used'      => $used,
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $used ),
			'percent'   => min( 100, (int) round( $used * 100 / $limit ) ),
			'reset_at'  => $next->getTimestamp(),
		];
	}

	private static function bump_quota() {
		$day = self::pacific_day();
		$q   = get_option( self::OPT_QUOTA, [] );
		if ( ! is_array( $q ) || ! isset( $q['day'] ) || $q['day'] !== $day ) {
			$q = [ 'day' => $day, 'count' => 0 ];
		}
		$q['count']++;
		update_option( self::OPT_QUOTA, $q, false );
	}

	/* ------------------------------------------------------------------ *
	 * Log & stats
	 * ------------------------------------------------------------------ */

	private static function record( $url, $type, $res, $source, $post_id ) {
		$ok    = self::is_ok( $res );
		$entry = [
			'time'   => time(),
			'url'    => $url,
			'type'   => $type,
			'source' => $source,
			'code'   => is_wp_error( $res ) ? 0 : (int) $res['code'],
			'ok'     => $ok,
			'msg'    => $ok ? 'OK' : mb_substr( self::error_text( $res ), 0, 300 ),
			'hint'   => self::hint( $res ),
		];

		$log = self::get_log();
		array_unshift( $log, $entry );
		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ), false );

		$stats = self::stats();
		$stats[ $ok ? 'ok' : 'fail' ]++;
		$stats['last'] = $entry['time'];
		update_option( self::OPT_STATS, $stats, false );
		SGIA_Stats::bump( $ok ? 'g_ok' : 'g_fail' );

		if ( $post_id ) {
			update_post_meta( $post_id, self::META_LAST, $entry );
		}

		/**
		 * Fires after every Indexing API publish attempt (manual, bulk, auto, post or queue).
		 *
		 * @param string $url     Submitted URL.
		 * @param string $type    URL_UPDATED | URL_DELETED.
		 * @param bool   $ok      Whether Google accepted it.
		 * @param int    $code    HTTP status (0 for local errors).
		 * @param string $source  auto | manual | bulk | post | queue.
		 * @param int    $post_id Related post ID or 0.
		 */
		do_action( 'sgia_published', $url, $type, $ok, $entry['code'], $source, (int) $post_id );
	}

	/**
	 * Non-Google log line: a URL skipped before sending, or an IndexNow batch.
	 * Not counted in the Google success/failure totals.
	 */
	public static function log_entry( array $e ) {
		$e = wp_parse_args( $e, [ 'time' => time(), 'url' => '', 'type' => 'SKIP', 'source' => '', 'code' => 0, 'ok' => false, 'msg' => '', 'hint' => '' ] );
		$log = self::get_log();
		array_unshift( $log, $e );
		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	public static function get_log() {
		$log = get_option( self::OPT_LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	public static function clear_log() {
		delete_option( self::OPT_LOG );
	}

	public static function stats() {
		$s = get_option( self::OPT_STATS, [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [ 'ok' => 0, 'fail' => 0, 'last' => 0 ] );
	}

	/** Success rate of the last 24h from the log. */
	public static function recent_rate() {
		$since = time() - DAY_IN_SECONDS;
		$total = 0;
		$ok    = 0;
		foreach ( self::get_log() as $row ) {
			if ( $row['time'] < $since ) {
				break;
			}
			if ( ! in_array( $row['type'], [ 'URL_UPDATED', 'URL_DELETED' ], true ) ) {
				continue;
			}
			$total++;
			if ( ! empty( $row['ok'] ) ) {
				$ok++;
			}
		}
		return [
			'total' => $total,
			'ok'    => $ok,
			'rate'  => $total ? (int) round( $ok * 100 / $total ) : null,
		];
	}
}
