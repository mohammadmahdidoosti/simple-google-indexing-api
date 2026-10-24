<?php
/**
 * Daily report (email / Telegram / Bale), instant alerts, and WP-Cron health.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Report {

	const CRON      = 'sgia_report_tick';
	const OPT_LAST  = 'sgia_report_last';
	const OPT_ALERT = 'sgia_alert_last';
	const OPT_SEEN  = 'sgia_cron_seen';
	const BALE_API  = 'https://tapi.bale.ai';
	const TG_API    = 'https://api.telegram.org';

	public static function init() {
		add_action( self::CRON, [ __CLASS__, 'tick' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function email_on() {
		return (bool) SGIA_API::settings()['report_email'];
	}

	public static function msgr_on() {
		$s = SGIA_API::settings();
		return $s['report_msgr'] && $s['report_token'] && $s['report_chat'];
	}

	public static function enabled() {
		return self::email_on() || self::msgr_on();
	}

	/** Once per Pacific day, right after Google's quota reset, report the day that just ended. */
	public static function tick() {
		self::seen();
		if ( ! self::enabled() ) {
			return;
		}
		$prev = SGIA_API::pacific_day( -1 );
		if ( get_option( self::OPT_LAST ) === $prev ) {
			return;
		}
		update_option( self::OPT_LAST, $prev, false );
		self::send( self::build( $prev ), 'گزارش روزانه Indexing API — ' . $prev );
	}

	public static function build( $day ) {
		$d    = SGIA_Stats::day( $day );
		$c    = SGIA_Queue::counts();
		$cap  = SGIA_Queue::settings()['cap'];
		$host = SGIA_Queue::site_host();
		$f    = function ( $n ) {
			return number_format( (int) $n );
		};

		$lines   = [];
		$lines[] = '📊 گزارش Indexing API — ' . $host;
		$lines[] = 'روز ' . $day . ' (به وقت Pacific)';
		$lines[] = '';
		$lines[] = '✅ ارسال موفق به گوگل: ' . $f( $d['g_ok'] ) . ( $d['q_sent'] ? ' (از صف: ' . $f( $d['q_sent'] ) . ')' : '' );
		$lines[] = '❌ ناموفق: ' . $f( $d['g_fail'] );
		if ( $d['skipped'] ) {
			$lines[] = '⏭ ردشده پیش از ارسال: ' . $f( $d['skipped'] );
		}
		if ( $d['inspected'] ) {
			$lines[] = '🔎 بررسی ایندکس: ' . $f( $d['inspected'] ) . ( $d['found_indexed'] ? ' — ' . $f( $d['found_indexed'] ) . ' صفحه ارسال‌شده ایندکس شد' : '' );
		}
		if ( $d['indexnow'] ) {
			$lines[] = '🔁 IndexNow: ' . $f( $d['indexnow'] ) . ' آدرس';
		}
		if ( $c['total'] ) {
			$lines[] = '📥 مانده در صف: ' . $f( $c['pending'] ) . ( $c['pending'] ? ' (حدود ' . (int) ceil( $c['pending'] / max( 1, $cap ) ) . ' روز)' : '' );
			if ( $c['checked'] ) {
				$lines[] = '📈 ایندکس پس از ارسال (کل): ' . $f( $c['indexed'] ) . ' از ' . $f( $c['checked'] ) . ' بررسی‌شده';
			}
		}
		$health = self::health();
		if ( $health ) {
			$lines[] = '';
			$lines[] = '⚠️ ' . $health;
		}
		return implode( "\n", $lines );
	}

	/** Instant alert, at most once per day per reason. */
	public static function alert( $reason ) {
		$s = SGIA_API::settings();
		if ( ! $s['report_alerts'] || ! self::enabled() ) {
			return;
		}
		$key = SGIA_API::pacific_day() . ':' . md5( $reason );
		if ( get_option( self::OPT_ALERT ) === $key ) {
			return;
		}
		update_option( self::OPT_ALERT, $key, false );
		self::send( "⚠️ ارسال صف Indexing API در " . SGIA_Queue::site_host() . " متوقف شد:\n" . $reason, 'هشدار Indexing API' );
	}

	/** @return array{email:true|WP_Error|null,msgr:true|WP_Error|null} */
	public static function send( $text, $subject ) {
		$s   = SGIA_API::settings();
		$out = [ 'email' => null, 'msgr' => null ];

		if ( self::email_on() ) {
			$to           = $s['report_email_to'] ? $s['report_email_to'] : get_option( 'admin_email' );
			$out['email'] = wp_mail( $to, $subject, $text ) ? true : new WP_Error( 'sgia_mail', 'ارسال ایمیل ناموفق بود؛ تنظیمات ایمیل (SMTP) سایت را بررسی کنید.' );
		}

		if ( self::msgr_on() ) {
			$base = 'bale' === $s['report_msgr'] ? self::BALE_API : ( $s['report_api_base'] ? untrailingslashit( $s['report_api_base'] ) : self::TG_API );
			$res  = wp_remote_post( $base . '/bot' . $s['report_token'] . '/sendMessage', [
				'timeout' => 15,
				'body'    => [ 'chat_id' => $s['report_chat'], 'text' => $text ],
			] );
			$json = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && ! empty( $json['ok'] ) ) {
				$out['msgr'] = true;
			} else {
				$name = 'bale' === $s['report_msgr'] ? 'بله' : 'تلگرام';
				$why  = is_wp_error( $res ) ? $res->get_error_message() : ( isset( $json['description'] ) ? $json['description'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
				$tip  = is_wp_error( $res ) && 'telegram' === $s['report_msgr'] ? ' — اگر سرور در ایران است، API تلگرام احتمالاً مسدود است؛ از «بله» یا آدرس پراکسی استفاده کنید.' : '';
				$out['msgr'] = new WP_Error( 'sgia_msgr', 'ارسال به ' . $name . ' ناموفق بود: ' . $why . $tip );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * WP-Cron health
	 * ------------------------------------------------------------------ */

	public static function seen() {
		update_option( self::OPT_SEEN, time(), false );
	}

	/** '' when fine, otherwise a Persian explanation. */
	public static function health() {
		$next = wp_next_scheduled( SGIA_Queue::CRON );
		if ( ! $next || $next > time() - HOUR_IN_SECONDS ) {
			return '';
		}
		$late = (int) floor( ( time() - $next ) / MINUTE_IN_SECONDS );
		$msg  = 'زمان‌بندی وردپرس (WP-Cron) اجرا نمی‌شود؛ اجرای صف ' . $late . ' دقیقه عقب افتاده است.';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$msg .= ' ثابت DISABLE_WP_CRON فعال است ولی cron سرور فراخوانی نمی‌شود.';
		}
		return $msg;
	}

	public static function cron_command() {
		return '*/15 * * * * wget -q -O - "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" >/dev/null 2>&1';
	}
}
