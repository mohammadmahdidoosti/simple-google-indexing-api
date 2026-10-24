<?php
/**
 * Per-day counters (Pacific day, like Google's quota), kept for 35 days.
 * Feed the daily report and the dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Stats {

	const OPT  = 'sgia_daily';
	const KEEP = 35;

	/**
	 * Keys:
	 *  g_ok, g_fail     Google Indexing API publish results
	 *  q_sent           sent from the queue
	 *  skipped          rejected before sending (pre-check / already indexed / out of stock)
	 *  inspected        URL Inspection calls
	 *  found_indexed    queue URLs later confirmed indexed after being sent
	 *  indexnow         URLs submitted to IndexNow
	 */
	public static function bump( $key, $n = 1 ) {
		if ( $n <= 0 ) {
			return;
		}
		$all = self::all();
		$day = SGIA_API::pacific_day();
		if ( ! isset( $all[ $day ] ) ) {
			$all[ $day ] = [];
		}
		$all[ $day ][ $key ] = ( isset( $all[ $day ][ $key ] ) ? (int) $all[ $day ][ $key ] : 0 ) + (int) $n;

		if ( count( $all ) > self::KEEP ) {
			krsort( $all );
			$all = array_slice( $all, 0, self::KEEP, true );
		}
		update_option( self::OPT, $all, false );
	}

	public static function all() {
		$a = get_option( self::OPT, [] );
		return is_array( $a ) ? $a : [];
	}

	public static function day( $day ) {
		$all = self::all();
		$d   = isset( $all[ $day ] ) && is_array( $all[ $day ] ) ? $all[ $day ] : [];
		return wp_parse_args( $d, [ 'g_ok' => 0, 'g_fail' => 0, 'q_sent' => 0, 'skipped' => 0, 'inspected' => 0, 'found_indexed' => 0, 'indexnow' => 0 ] );
	}

	public static function today() {
		return self::day( SGIA_API::pacific_day() );
	}

	/** Sum over the last $days Pacific days (including today). */
	public static function range( $days ) {
		$sum = self::day( '' );
		for ( $i = 0; $i < $days; $i++ ) {
			foreach ( self::day( SGIA_API::pacific_day( -$i ) ) as $k => $v ) {
				$sum[ $k ] += (int) $v;
			}
		}
		return $sum;
	}
}
