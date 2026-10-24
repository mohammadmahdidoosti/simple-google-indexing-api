<?php
/**
 * Send queue: import a list of URLs (XLSX / CSV / TXT / pasted text), store it in
 * a table, and send a capped batch every day after Google's quota resets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Queue {

	const TABLE        = 'sgia_queue';
	const DB_VERSION   = '1';
	const OPT_DB       = 'sgia_queue_db';
	const OPT_SETTINGS = 'sgia_queue_settings';
	const OPT_DAY      = 'sgia_queue_day';
	const OPT_HALT     = 'sgia_queue_halt';
	const CRON         = 'sgia_queue_tick';
	const SCHEDULE     = 'sgia_quarter_hour';
	const LOCK         = 'sgia_queue_lock';
	const BATCH        = 25;   // max URLs per run, keeps each request short
	const TIME_BUDGET  = 20;   // seconds per run, safe under 30s max_execution_time
	const MAX_ATTEMPTS = 3;    // network / 5xx retries before marking failed
	const MAX_IMPORT   = 20000;
	const MAX_FILE     = 10485760; // 10 MB

	public static function init() {
		self::maybe_install();
		add_filter( 'cron_schedules', [ __CLASS__, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON, [ __CLASS__, 'cron' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
	}

	public static function schedules( $s ) {
		$s[ self::SCHEDULE ] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Indexing API queue)' ];
		return $s;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
		delete_transient( self::LOCK );
	}

	/* ------------------------------------------------------------------ *
	 * Table
	 * ------------------------------------------------------------------ */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function maybe_install() {
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url text NOT NULL,
			url_hash char(32) NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			added_at datetime NOT NULL,
			sent_at datetime NULL DEFAULT NULL,
			last_code smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status (status)
		) {$charset};" );

		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function settings() {
		$s     = get_option( self::OPT_SETTINGS, [] );
		$limit = SGIA_API::settings()['daily_limit'];
		$s     = wp_parse_args( is_array( $s ) ? $s : [], [
			'active'  => 1,
			'per_day' => max( 1, min( 180, $limit - 20 ) ),
		] );

		$s['active']  = empty( $s['active'] ) ? 0 : 1;
		$s['per_day'] = max( 1, (int) $s['per_day'] );
		$s['cap']     = min( $s['per_day'], $limit ); // effective, never above the global daily limit
		return $s;
	}

	public static function update_settings( $active, $per_day ) {
		update_option( self::OPT_SETTINGS, [
			'active'  => $active ? 1 : 0,
			'per_day' => max( 1, (int) $per_day ),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Import
	 * ------------------------------------------------------------------ */

	private static function allowed_hosts() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$bare = preg_replace( '/^www\./', '', $host );
		return array_unique( [ $host, $bare, 'www.' . $bare ] );
	}

	public static function site_host() {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	/** Dedupe key: percent-encoded and decoded forms of the same URL are one entry. */
	private static function key( $url ) {
		return md5( rawurldecode( $url ) );
	}

	/** Pull every http(s) URL out of arbitrary text. */
	public static function extract_urls( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		if ( ! preg_match_all( '~https?://[^\s<>"\'`{}|\\\\^\[\]]+~iu', $text, $m ) ) {
			return [];
		}
		return array_map( function ( $u ) {
			return rtrim( $u, '.,;:!?)' );
		}, $m[0] );
	}

	/**
	 * Read URLs from an uploaded file. Every sheet of an XLSX is scanned, so a
	 * Search Console "Not indexed" export works as-is.
	 *
	 * @return string[]|WP_Error
	 */
	public static function read_file( $path, $name ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );

		if ( filesize( $path ) > self::MAX_FILE ) {
			return new WP_Error( 'sgia_file', 'حجم فایل بیشتر از ۱۰ مگابایت است.' );
		}

		if ( 'xls' === $ext ) {
			return new WP_Error( 'sgia_file', 'فرمت قدیمی xls پشتیبانی نمی‌شود. فایل را در اکسل با فرمت xlsx یا CSV ذخیره کنید.' );
		}

		if ( 'xlsx' === $ext ) {
			$text = self::xlsx_text( $path );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
		} elseif ( in_array( $ext, [ 'csv', 'txt', 'tsv' ], true ) ) {
			$text = self::to_utf8( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			return new WP_Error( 'sgia_file', 'فقط فایل‌های xlsx، csv و txt پذیرفته می‌شوند.' );
		}

		return self::extract_urls( $text );
	}

	/** Excel "Unicode text" is UTF-16, Persian Windows CSV is often Windows-1256. */
	private static function to_utf8( $s ) {
		if ( 0 === strpos( $s, "\xFF\xFE" ) ) {
			return mb_convert_encoding( substr( $s, 2 ), 'UTF-8', 'UTF-16LE' );
		}
		if ( 0 === strpos( $s, "\xFE\xFF" ) ) {
			return mb_convert_encoding( substr( $s, 2 ), 'UTF-8', 'UTF-16BE' );
		}
		if ( 0 === strpos( $s, "\xEF\xBB\xBF" ) ) {
			$s = substr( $s, 3 );
		}
		if ( ! mb_check_encoding( $s, 'UTF-8' ) && in_array( 'Windows-1256', mb_list_encodings(), true ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'Windows-1256' );
		}
		return $s;
	}

	/** Text of all shared strings and worksheets, tags stripped. No external library. */
	private static function xlsx_text( $path ) {
		$pattern = '~^xl/(sharedStrings\.xml|worksheets/sheet\d+\.xml)$~';
		$xml     = '';

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return new WP_Error( 'sgia_file', 'فایل اکسل باز نشد؛ ممکن است خراب باشد.' );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				if ( preg_match( $pattern, (string) $zip->getNameIndex( $i ) ) ) {
					$xml .= ' ' . $zip->getFromIndex( $i );
				}
			}
			$zip->close();
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip  = new PclZip( $path );
			$list = $zip->extract( PCLZIP_OPT_EXTRACT_AS_STRING, PCLZIP_OPT_BY_PREG, $pattern );
			if ( ! is_array( $list ) ) {
				return new WP_Error( 'sgia_file', 'فایل اکسل باز نشد؛ ممکن است خراب باشد.' );
			}
			foreach ( $list as $f ) {
				$xml .= ' ' . $f['content'];
			}
		}

		if ( '' === trim( $xml ) ) {
			return new WP_Error( 'sgia_file', 'داده‌ای در فایل اکسل پیدا نشد.' );
		}
		// Strip tags (and their xmlns schema URLs); keep cell text separated.
		return preg_replace( '/<[^>]+>/', ' ', $xml );
	}

	/**
	 * @param string[] $urls
	 * @return array{added:int,duplicate:int,foreign:int,invalid:int,foreign_host:string,truncated:bool}
	 */
	public static function import( array $urls ) {
		global $wpdb;
		self::maybe_install();

		$out   = [ 'added' => 0, 'duplicate' => 0, 'foreign' => 0, 'invalid' => 0, 'foreign_host' => '', 'truncated' => false ];
		$hosts = self::allowed_hosts();
		$fresh = [];

		if ( count( $urls ) > self::MAX_IMPORT ) {
			$urls             = array_slice( $urls, 0, self::MAX_IMPORT );
			$out['truncated'] = true;
		}

		foreach ( $urls as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( ! SGIA_API::valid_url( $u ) || strlen( $u ) > 2000 ) {
				$out['invalid']++;
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
			if ( ! in_array( $host, $hosts, true ) ) {
				$out['foreign']++;
				if ( ! $out['foreign_host'] ) {
					$out['foreign_host'] = $host;
				}
				continue;
			}
			$k = self::key( $u );
			if ( isset( $fresh[ $k ] ) ) {
				$out['duplicate']++;
				continue;
			}
			$fresh[ $k ] = $u;
		}

		$t = self::table();
		foreach ( array_chunk( array_keys( $fresh ), 500 ) as $chunk ) {
			$in       = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$existing = $wpdb->get_col( $wpdb->prepare( "SELECT url_hash FROM {$t} WHERE url_hash IN ({$in})", $chunk ) ); // phpcs:ignore
			foreach ( (array) $existing as $h ) {
				if ( isset( $fresh[ $h ] ) ) {
					unset( $fresh[ $h ] );
					$out['duplicate']++;
				}
			}
		}

		$now = current_time( 'mysql', true );
		foreach ( array_chunk( $fresh, 100, true ) as $chunk ) {
			$rows = [];
			$args = [];
			foreach ( $chunk as $hash => $url ) {
				$rows[] = "(%s, %s, 'pending', 0, %s, 0, '')";
				array_push( $args, $url, $hash, $now );
			}
			$sql = "INSERT INTO {$t} (url, url_hash, status, attempts, added_at, last_code, last_error) VALUES " . implode( ',', $rows );
			$ok  = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
			if ( false !== $ok ) {
				$out['added'] += count( $chunk );
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Daily counters & halt
	 * ------------------------------------------------------------------ */

	public static function sent_today() {
		$d = get_option( self::OPT_DAY, [] );
		return ( is_array( $d ) && isset( $d['day'] ) && SGIA_API::pacific_day() === $d['day'] ) ? (int) $d['count'] : 0;
	}

	private static function bump_today() {
		update_option( self::OPT_DAY, [ 'day' => SGIA_API::pacific_day(), 'count' => self::sent_today() + 1 ], false );
	}

	/** Reason sending stopped for today (Google 429, auth problem), or ''. */
	public static function halted() {
		$h = get_option( self::OPT_HALT, [] );
		return ( is_array( $h ) && isset( $h['day'] ) && SGIA_API::pacific_day() === $h['day'] ) ? (string) $h['reason'] : '';
	}

	private static function halt( $reason ) {
		update_option( self::OPT_HALT, [ 'day' => SGIA_API::pacific_day(), 'reason' => $reason ], false );
	}

	/* ------------------------------------------------------------------ *
	 * Sending
	 * ------------------------------------------------------------------ */

	public static function cron() {
		self::run( false );
	}

	/**
	 * Send one batch. Automatic runs respect the on/off switch and today's halt;
	 * a manual run ignores both (so it can be used right after fixing access).
	 *
	 * @return array{ok:bool,sent:int,failed:int,msg:string}
	 */
	public static function run( $manual = false ) {
		global $wpdb;
		self::maybe_install();

		$s   = self::settings();
		$res = [ 'ok' => false, 'sent' => 0, 'failed' => 0, 'msg' => '' ];

		if ( ! $manual && ! $s['active'] ) {
			$res['msg'] = 'ارسال خودکار صف متوقف است.';
			return $res;
		}
		if ( ! SGIA_API::has_key() ) {
			$res['msg'] = 'کلید سرویس اکانت تنظیم نشده است.';
			return $res;
		}
		if ( ! $manual && self::halted() ) {
			$res['msg'] = 'ارسال امروز متوقف شده: ' . self::halted();
			return $res;
		}

		$allowed = min( $s['cap'] - self::sent_today(), SGIA_API::quota()['remaining'], self::BATCH );
		if ( $allowed <= 0 ) {
			$res['msg'] = 'سهمیه امروز صف تمام شده؛ ادامه بعد از ریست سهمیه گوگل.';
			return $res;
		}

		if ( get_transient( self::LOCK ) ) {
			$res['msg'] = 'یک ارسال دیگر در حال اجراست؛ کمی بعد دوباره تلاش کنید.';
			return $res;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		if ( $manual ) {
			delete_option( self::OPT_HALT );
		}

		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, attempts FROM {$t} WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $allowed ) ); // phpcs:ignore

		if ( ! $rows ) {
			delete_transient( self::LOCK );
			$res['ok']  = true;
			$res['msg'] = 'آدرسی در صف نمانده است.';
			return $res;
		}

		$start = microtime( true );
		$stop  = '';

		foreach ( $rows as $row ) {
			if ( microtime( true ) - $start > self::TIME_BUDGET ) {
				break;
			}

			$post_id = url_to_postid( $row->url );
			$r       = SGIA_API::publish( $row->url, 'URL_UPDATED', 'queue', $post_id );
			$code    = is_wp_error( $r ) ? 0 : (int) $r['code'];
			$err     = is_wp_error( $r ) ? $r->get_error_code() : '';
			$now     = current_time( 'mysql', true );

			if ( SGIA_API::is_ok( $r ) ) {
				$wpdb->update( $t, [ 'status' => 'sent', 'sent_at' => $now, 'attempts' => $row->attempts + 1, 'last_code' => $code, 'last_error' => '' ], [ 'id' => $row->id ] );
				self::bump_today();
				if ( $post_id ) {
					update_post_meta( $post_id, SGIA_API::META_URL, $row->url );
				}
				$res['sent']++;
				continue;
			}

			$msg = mb_substr( SGIA_API::error_text( $r ), 0, 250 );

			// Problems that would fail every URL: stop for today, keep the row pending.
			if ( 429 === $code || 'sgia_quota' === $err ) {
				$stop = 'سهمیه گوگل پر شده است.';
			} elseif ( in_array( $code, [ 401, 403 ], true ) || in_array( $err, [ 'sgia_no_key', 'sgia_token', 'sgia_key', 'sgia_sign', 'sgia_openssl', 'sgia_auth' ], true ) ) {
				$hint = SGIA_API::hint( $r );
				$stop = $msg . ( $hint ? ' — ' . $hint : '' );
			}
			if ( $stop ) {
				$wpdb->update( $t, [ 'last_code' => $code, 'last_error' => $msg ], [ 'id' => $row->id ] );
				break;
			}

			// URL-specific rejection: no point retrying. Network/5xx: retry a few times.
			$attempts = $row->attempts + 1;
			$final    = ( $code >= 400 && $code < 500 ) || $attempts >= self::MAX_ATTEMPTS;
			$wpdb->update( $t, [ 'status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'last_code' => $code, 'last_error' => $msg ], [ 'id' => $row->id ] );
			$res['failed']++;
		}

		delete_transient( self::LOCK );

		if ( $stop ) {
			self::halt( $stop );
		}

		$res['ok']  = ! $stop;
		$res['msg'] = sprintf( '%d ارسال شد، %d ناموفق.', $res['sent'], $res['failed'] ) . ( $stop ? ' ارسال امروز متوقف شد: ' . $stop : '' );
		return $res;
	}

	/* ------------------------------------------------------------------ *
	 * Reading & maintenance
	 * ------------------------------------------------------------------ */

	public static function counts() {
		global $wpdb;
		self::maybe_install();
		$t   = self::table();
		$out = [ 'pending' => 0, 'sent' => 0, 'failed' => 0 ];
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status" ) as $r ) { // phpcs:ignore
			if ( isset( $out[ $r->status ] ) ) {
				$out[ $r->status ] = (int) $r->n;
			}
		}
		$out['total'] = $out['pending'] + $out['sent'] + $out['failed'];
		return $out;
	}

	/** @return array{rows:object[],total:int} */
	public static function rows( $status = '', $search = '', $page = 1, $per = 50 ) {
		global $wpdb;
		$t     = self::table();
		$where = '1=1';
		$args  = [];

		if ( in_array( $status, [ 'pending', 'sent', 'failed' ], true ) ) {
			$where .= ' AND status = %s';
			$args[] = $status;
		}
		if ( '' !== $search ) {
			$where .= ' AND url LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$order = 'sent' === $status ? 'sent_at DESC, id DESC' : 'id ASC';
		$sql   = "SELECT * FROM {$t} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $per, max( 0, ( $page - 1 ) * $per ) ] ) ) ); // phpcs:ignore

		return [ 'rows' => (array) $rows, 'total' => $total ];
	}

	public static function retry_failed() {
		global $wpdb;
		return (int) $wpdb->query( "UPDATE " . self::table() . " SET status = 'pending', attempts = 0 WHERE status = 'failed'" ); // phpcs:ignore
	}

	public static function clear( $status = '' ) {
		global $wpdb;
		$t = self::table();
		if ( in_array( $status, [ 'pending', 'sent', 'failed' ], true ) ) {
			return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE status = %s", $status ) ); // phpcs:ignore
		}
		return (int) $wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
	}

	public static function delete_row( $id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'id' => (int) $id ] );
	}
}
