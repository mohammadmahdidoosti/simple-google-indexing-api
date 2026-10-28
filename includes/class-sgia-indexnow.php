<?php
/**
 * IndexNow (Bing, Yandex, Seznam, Naver …): officially for every kind of page,
 * up to 10,000 URLs per request, no hard daily cap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_IndexNow {

	const ENDPOINT   = 'https://api.indexnow.org/indexnow';
	const CHUNK      = 10000;
	const OPT_BUF    = 'sgia_indexnow_buffer';
	const CRON_FLUSH = 'sgia_indexnow_flush';
	const OPT_BULK   = 'sgia_indexnow_bulk';
	const CRON_BULK  = 'sgia_indexnow_bulk_step';
	const BULK_STEP  = 2000;

	public static function init() {
		add_action( 'init', [ __CLASS__, 'serve_key' ], 0 );
		add_action( self::CRON_FLUSH, [ __CLASS__, 'flush' ] );
		add_action( self::CRON_BULK, [ __CLASS__, 'bulk_step' ] );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_FLUSH );
		wp_clear_scheduled_hook( self::CRON_BULK );
	}

	public static function enabled() {
		$s = SGIA_API::settings();
		return $s['indexnow'] && '' !== $s['indexnow_key'];
	}

	/** Create the key the first time IndexNow is switched on, and write the key file. */
	public static function ensure_key() {
		$s = SGIA_API::settings();
		if ( '' === $s['indexnow_key'] ) {
			SGIA_API::update_settings( [ 'indexnow_key' => bin2hex( random_bytes( 16 ) ) ] );
		}
		self::write_key_file();
		return SGIA_API::settings()['indexnow_key'];
	}

	/**
	 * Physical /{key}.txt in the site root. The virtual one (serve_key) covers most
	 * servers, but some nginx setups answer *.txt themselves and never reach WordPress.
	 */
	public static function key_file_path() {
		$key = SGIA_API::settings()['indexnow_key'];
		if ( ! $key || ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return $key ? trailingslashit( get_home_path() ) . $key . '.txt' : '';
	}

	public static function write_key_file() {
		$path = self::key_file_path();
		if ( ! $path || file_exists( $path ) ) {
			return (bool) $path;
		}
		return (bool) @file_put_contents( $path, SGIA_API::settings()['indexnow_key'] ); // phpcs:ignore
	}

	public static function delete_key_file() {
		$path = self::key_file_path();
		if ( $path && file_exists( $path ) && trim( (string) file_get_contents( $path ) ) === SGIA_API::settings()['indexnow_key'] ) { // phpcs:ignore
			@unlink( $path ); // phpcs:ignore
		}
	}

	/** Does the key URL really answer with the key? (what search engines check) */
	public static function verify_key() {
		$res = wp_remote_get( self::key_url(), [ 'timeout' => 10, 'redirection' => 2 ] );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'sgia_key_url', 'آدرس فایل کلید باز نشد: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code || trim( wp_remote_retrieve_body( $res ) ) !== SGIA_API::settings()['indexnow_key'] ) {
			return new WP_Error( 'sgia_key_url', 'فایل کلید در دسترس نیست (HTTP ' . $code . '). فایل ' . basename( self::key_file_path() ) . ' را با محتوای کلید در ریشه سایت بسازید.' );
		}
		return true;
	}

	public static function key_url() {
		return home_url( '/' . SGIA_API::settings()['indexnow_key'] . '.txt' );
	}

	/** Answer /{key}.txt without touching rewrite rules or the filesystem. */
	public static function serve_key() {
		if ( ! self::enabled() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore
		if ( $path && wp_parse_url( self::key_url(), PHP_URL_PATH ) === $path ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( SGIA_API::settings()['indexnow_key'] );
			exit;
		}
	}

	/** Rank Math's Instant Indexing module also sends IndexNow. */
	public static function rank_math_conflict() {
		$m = get_option( 'rank_math_modules' );
		return is_array( $m ) && in_array( 'instant-indexing', $m, true );
	}

	/* ------------------------------------------------------------------ *
	 * Submit
	 * ------------------------------------------------------------------ */

	/** @return array{ok:bool,sent:int,code:int,msg:string,hint:string} */
	public static function submit( array $urls, $source = 'manual' ) {
		$out = [ 'ok' => false, 'sent' => 0, 'code' => 0, 'msg' => '', 'hint' => '' ];
		if ( ! self::enabled() ) {
			$out['msg'] = 'IndexNow در تنظیمات فعال نیست.';
			return $out;
		}

		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$clean = [];
		foreach ( $urls as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( $u && strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) === $host ) {
				$clean[ $u ] = true;
			}
		}
		$clean = array_keys( $clean );
		if ( ! $clean ) {
			$out['msg'] = 'آدرسی از دامنه ' . $host . ' برای ارسال نبود.';
			return $out;
		}

		$out['ok'] = true;
		foreach ( array_chunk( $clean, self::CHUNK ) as $chunk ) {
			$res  = wp_remote_post( self::ENDPOINT, [
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'    => wp_json_encode( [
					'host'        => $host,
					'key'         => SGIA_API::settings()['indexnow_key'],
					'keyLocation' => self::key_url(),
					'urlList'     => array_values( $chunk ),
				], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			] );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$ok   = in_array( $code, [ 200, 202 ], true );
			$hint = self::hint( $code, $res );

			SGIA_API::log_entry( [
				'url'    => 'IndexNow · ' . number_format( count( $chunk ) ) . ' آدرس',
				'type'   => 'INDEXNOW',
				'source' => $source,
				'code'   => $code,
				'ok'     => $ok,
				'msg'    => $ok ? 'OK' : ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code . ' ' . mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) ), 0, 200 ) ),
				'hint'   => $hint,
			] );

			$out['code'] = $code;
			if ( ! $ok ) {
				$out['ok']   = false;
				$out['msg']  = is_wp_error( $res ) ? $res->get_error_message() : 'IndexNow پاسخ ' . $code . ' داد.';
				$out['hint'] = $hint;
				break;
			}
			$out['sent'] += count( $chunk );
			SGIA_Stats::bump( 'indexnow', count( $chunk ) );
		}

		if ( $out['ok'] ) {
			$out['msg'] = number_format( $out['sent'] ) . ' آدرس به IndexNow ارسال شد.';
		}
		return $out;
	}

	private static function hint( $code, $res ) {
		switch ( $code ) {
			case 0:
				return 'سرور به api.indexnow.org دسترسی ندارد.';
			case 403:
				return 'کلید تأیید نشد؛ این آدرس باید از بیرون باز شود و فقط کلید را نشان دهد: ' . self::key_url();
			case 422:
				return 'آدرس‌ها با دامنه یا کلید هم‌خوانی ندارند.';
			case 429:
				return 'درخواست‌ها زیاد بوده؛ کمی بعد دوباره تلاش کنید.';
		}
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Buffer for automatic submits (publish / update / delete)
	 * ------------------------------------------------------------------ */

	public static function buffer_add( array $urls ) {
		if ( ! self::enabled() || ! SGIA_API::settings()['indexnow_auto'] ) {
			return;
		}
		$buf = get_option( self::OPT_BUF, [] );
		$buf = array_slice( array_values( array_unique( array_merge( is_array( $buf ) ? $buf : [], $urls ) ) ), -20000 );
		update_option( self::OPT_BUF, $buf, false );
		if ( ! wp_next_scheduled( self::CRON_FLUSH ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_FLUSH );
		}
	}

	public static function flush() {
		$buf = get_option( self::OPT_BUF, [] );
		delete_option( self::OPT_BUF );
		if ( is_array( $buf ) && $buf ) {
			$r = self::submit( $buf, 'auto' );
			if ( ! $r['ok'] && $r['code'] !== 403 && $r['code'] !== 422 ) {
				// Temporary failure (network, 429, 5xx): put the URLs back and try again in 15 minutes.
				$now = get_option( self::OPT_BUF, [] );
				update_option( self::OPT_BUF, array_slice( array_values( array_unique( array_merge( $buf, is_array( $now ) ? $now : [] ) ) ), -20000 ), false );
				if ( ! wp_next_scheduled( self::CRON_FLUSH ) ) {
					wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::CRON_FLUSH );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Bulk: every published post/page/product, in the background
	 * ------------------------------------------------------------------ */

	private static function bulk_types() {
		return array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
	}

	public static function bulk_start() {
		global $wpdb;
		$types = self::bulk_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ({$in})", $types ) ); // phpcs:ignore

		$state = [ 'last_id' => 0, 'total' => $total, 'sent' => 0, 'started' => time(), 'done' => false, 'error' => '' ];
		update_option( self::OPT_BULK, $state, false );
		wp_clear_scheduled_hook( self::CRON_BULK );
		wp_schedule_single_event( time(), self::CRON_BULK );
		return $state;
	}

	public static function bulk_state() {
		$s = get_option( self::OPT_BULK, [] );
		return is_array( $s ) && $s ? $s : null;
	}

	public static function bulk_step() {
		global $wpdb;
		$state = self::bulk_state();
		if ( ! $state || $state['done'] ) {
			return;
		}

		$types = self::bulk_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ({$in}) AND ID > %d ORDER BY ID ASC LIMIT %d",
			array_merge( $types, [ (int) $state['last_id'], self::BULK_STEP ] )
		) );

		if ( $ids ) {
			$urls = array_filter( array_map( 'get_permalink', array_map( 'intval', $ids ) ) );
			$r    = self::submit( $urls, 'bulk' );
			if ( ! $r['ok'] ) {
				$state['error'] = $r['msg'] . ( $r['hint'] ? ' — ' . $r['hint'] : '' );
				$state['done']  = true;
				update_option( self::OPT_BULK, $state, false );
				return;
			}
			$state['sent']   += $r['sent'];
			$state['last_id'] = (int) end( $ids );
		}

		if ( count( $ids ) < self::BULK_STEP ) {
			$state['done'] = true;
		} else {
			wp_schedule_single_event( time() + 20, self::CRON_BULK );
		}
		update_option( self::OPT_BULK, $state, false );
	}
}
