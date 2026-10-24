<?php
/**
 * Search Console URL Inspection: is a URL actually in Google's index?
 * Separate quota from the Indexing API (2,000 per property per day).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Inspect {

	const ENDPOINT   = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
	const SITES      = 'https://www.googleapis.com/webmasters/v3/sites';
	const OPT_DAY    = 'sgia_inspect_day';
	const OPT_HALT   = 'sgia_inspect_halt';
	const PROP_CACHE = 'sgia_gsc_property';

	/* ------------------------------------------------------------------ *
	 * Property
	 * ------------------------------------------------------------------ */

	/** @return array<int,array{url:string,level:string}>|WP_Error */
	public static function list_sites() {
		$res = SGIA_API::request( 'GET', self::SITES );
		if ( ! SGIA_API::is_ok( $res ) ) {
			return new WP_Error( 'sgia_sites', SGIA_API::error_text( $res ), self::hint( $res ) );
		}
		$out = [];
		foreach ( (array) ( isset( $res['body']['siteEntry'] ) ? $res['body']['siteEntry'] : [] ) as $e ) {
			$out[] = [ 'url' => (string) $e['siteUrl'], 'level' => (string) $e['permissionLevel'] ];
		}
		return $out;
	}

	/** Pick the Search Console property that covers this site. */
	public static function detect() {
		$sites = self::list_sites();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$bare = preg_replace( '/^www\./', '', $host );
		$home = trailingslashit( home_url() );

		$want = [ 'sc-domain:' . $bare, $home, 'https://' . $host . '/', 'https://www.' . $bare . '/', 'https://' . $bare . '/', 'http://' . $host . '/' ];
		$have = [];
		foreach ( $sites as $s ) {
			if ( 'siteUnverifiedUser' !== $s['level'] ) {
				$have[ strtolower( $s['url'] ) ] = $s['url'];
			}
		}
		foreach ( $want as $w ) {
			if ( isset( $have[ strtolower( $w ) ] ) ) {
				set_transient( self::PROP_CACHE, $have[ strtolower( $w ) ], DAY_IN_SECONDS );
				return $have[ strtolower( $w ) ];
			}
		}
		return new WP_Error( 'sgia_no_property', 'سرویس اکانت به property این سایت در Search Console دسترسی ندارد.', 'ایمیل سرویس اکانت را در Search Console به property سایت ' . $bare . ' اضافه کنید.' );
	}

	/** @return string|WP_Error */
	public static function property() {
		$s = SGIA_API::settings();
		if ( $s['gsc_property'] ) {
			return $s['gsc_property'];
		}
		$cached = get_transient( self::PROP_CACHE );
		return $cached ? $cached : self::detect();
	}

	/* ------------------------------------------------------------------ *
	 * Daily quota & halt
	 * ------------------------------------------------------------------ */

	public static function used_today() {
		$d = get_option( self::OPT_DAY, [] );
		return ( is_array( $d ) && isset( $d['day'] ) && SGIA_API::pacific_day() === $d['day'] ) ? (int) $d['count'] : 0;
	}

	public static function remaining() {
		return max( 0, SGIA_API::settings()['inspect_limit'] - self::used_today() );
	}

	private static function bump() {
		update_option( self::OPT_DAY, [ 'day' => SGIA_API::pacific_day(), 'count' => self::used_today() + 1 ], false );
		SGIA_Stats::bump( 'inspected' );
	}

	public static function halted() {
		$h = get_option( self::OPT_HALT, [] );
		return ( is_array( $h ) && isset( $h['day'] ) && SGIA_API::pacific_day() === $h['day'] ) ? (string) $h['reason'] : '';
	}

	/** Can the queue use inspection right now? */
	public static function available() {
		return SGIA_API::has_key() && self::remaining() > 0 && ! self::halted();
	}

	/* ------------------------------------------------------------------ *
	 * Inspect
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{indexed:bool,state:string,verdict:string,coverage:string,coverage_fa:string,last_crawl:string,link:string}|WP_Error
	 */
	public static function inspect( $url, $manual = false ) {
		if ( ! $manual && self::remaining() <= 0 ) {
			return new WP_Error( 'sgia_iquota', 'سقف روزانه بررسی ایندکس پر شده است.' );
		}
		$prop = self::property();
		if ( is_wp_error( $prop ) ) {
			return $prop;
		}

		$res = SGIA_API::request( 'POST', self::ENDPOINT, [
			'inspectionUrl' => $url,
			'siteUrl'       => $prop,
			'languageCode'  => 'en-US',
		] );
		if ( ! is_wp_error( $res ) ) {
			self::bump();
		}

		if ( ! SGIA_API::is_ok( $res ) ) {
			$code = is_wp_error( $res ) ? 0 : (int) $res['code'];
			$msg  = SGIA_API::error_text( $res );
			$hint = self::hint( $res );
			// Problems that will hit every URL: stop inspecting for today.
			if ( in_array( $code, [ 401, 403, 429 ], true ) ) {
				update_option( self::OPT_HALT, [ 'day' => SGIA_API::pacific_day(), 'reason' => $msg . ( $hint ? ' — ' . $hint : '' ) ], false );
			}
			return new WP_Error( 'sgia_inspect', $msg, $hint );
		}

		$r = isset( $res['body']['inspectionResult'] ) ? $res['body']['inspectionResult'] : [];
		$i = isset( $r['indexStatusResult'] ) ? $r['indexStatusResult'] : [];

		$verdict  = isset( $i['verdict'] ) ? (string) $i['verdict'] : '';
		$coverage = isset( $i['coverageState'] ) ? (string) $i['coverageState'] : '';
		$indexed  = 'PASS' === $verdict;

		return [
			'indexed'     => $indexed,
			'state'       => $indexed ? 'indexed' : 'not_indexed',
			'verdict'     => $verdict,
			'coverage'    => $coverage,
			'coverage_fa' => self::translate( $coverage, $indexed ),
			'last_crawl'  => isset( $i['lastCrawlTime'] ) ? (string) $i['lastCrawlTime'] : '',
			'link'        => isset( $r['inspectionResultLink'] ) ? (string) $r['inspectionResultLink'] : '',
		];
	}

	public static function translate( $coverage, $indexed = false ) {
		$map = [
			'Submitted and indexed'                                  => 'ایندکس شده',
			'Indexed, not submitted in sitemap'                      => 'ایندکس شده (خارج از sitemap)',
			'Indexed, though blocked by robots.txt'                  => 'ایندکس شده با وجود robots.txt',
			'Crawled - currently not indexed'                        => 'خزش‌شده، فعلاً ایندکس نشده',
			'Discovered - currently not indexed'                     => 'کشف‌شده، هنوز خزش نشده',
			'URL is unknown to Google'                               => 'برای گوگل ناشناخته',
			'Duplicate without user-selected canonical'              => 'تکراری، بدون canonical',
			'Duplicate, Google chose different canonical than user' => 'تکراری؛ گوگل canonical دیگری انتخاب کرده',
			'Duplicate, submitted URL not selected as canonical'     => 'تکراری؛ به‌عنوان canonical انتخاب نشده',
			'Alternate page with proper canonical tag'               => 'صفحه جایگزین با canonical درست',
			"Excluded by 'noindex' tag"                              => 'حذف‌شده با noindex',
			'Page with redirect'                                     => 'صفحه دارای ریدایرکت',
			'Not found (404)'                                        => 'پیدا نشد (404)',
			'Soft 404'                                               => 'Soft 404',
			'Blocked by robots.txt'                                  => 'مسدود در robots.txt',
			'Server error (5xx)'                                     => 'خطای سرور (5xx)',
			'Blocked due to unauthorized request (401)'              => 'مسدود (401)',
			'Blocked due to access forbidden (403)'                  => 'مسدود (403)',
		];
		if ( isset( $map[ $coverage ] ) ) {
			return $map[ $coverage ];
		}
		if ( '' === $coverage ) {
			return $indexed ? 'ایندکس شده' : 'نامشخص';
		}
		return $coverage;
	}

	public static function hint( $res ) {
		if ( SGIA_API::is_ok( $res ) ) {
			return '';
		}
		if ( is_wp_error( $res ) ) {
			$d = $res->get_error_data();
			return is_string( $d ) ? $d : SGIA_API::hint( $res );
		}
		$msg = strtolower( SGIA_API::error_text( $res ) );
		switch ( (int) $res['code'] ) {
			case 403:
				if ( false !== strpos( $msg, 'has not been used' ) || false !== strpos( $msg, 'disabled' ) ) {
					return 'Google Search Console API را در Google Cloud Console برای همین پروژه فعال کنید.';
				}
				if ( false !== strpos( $msg, 'insufficient' ) || false !== strpos( $msg, 'scope' ) ) {
					return 'ایمیل سرویس اکانت را در Search Console به property سایت اضافه کنید و property را در تنظیمات دوباره تشخیص دهید.';
				}
				return 'دسترسی سرویس اکانت به Search Console را بررسی کنید.';
			case 429:
				return 'سهمیه روزانه بررسی ایندکس در گوگل پر شده؛ فردا ادامه پیدا می‌کند.';
			case 400:
				return 'آدرس با property انتخاب‌شده در Search Console هم‌خوانی ندارد.';
		}
		return '';
	}
}
