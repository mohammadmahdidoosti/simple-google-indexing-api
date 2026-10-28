<?php
/**
 * Automatic notifications on publish / update / unpublish / delete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Auto {

	const CRON_HOOK = 'sgia_send_notification';

	public static function init() {
		add_action( 'transition_post_status', [ __CLASS__, 'on_status_change' ], 10, 3 );
		add_action( 'wp_trash_post', [ __CLASS__, 'on_before_trash' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_send' ], 10, 3 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( SGIA_API::TOKEN_CACHE );
	}

	public static function is_enabled_post( $post ) {
		return $post instanceof WP_Post
			&& in_array( $post->post_type, SGIA_API::settings()['types'], true )
			&& ! wp_is_post_revision( $post )
			&& ! wp_is_post_autosave( $post );
	}

	private static function active() {
		return SGIA_API::settings()['auto'] && SGIA_API::has_key();
	}

	/** Google: only the post types chosen in settings (quota is small). */
	private static function google_for( $post ) {
		return self::active() && self::is_enabled_post( $post );
	}

	/** IndexNow: every public post type (no tight quota). */
	private static function indexnow_for( $post ) {
		return $post instanceof WP_Post
			&& SGIA_IndexNow::enabled() && SGIA_API::settings()['indexnow_auto']
			&& is_post_type_viewable( $post->post_type ) && 'attachment' !== $post->post_type
			&& ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post );
	}

	public static function on_status_change( $new_status, $old_status, $post ) {
		$google = self::google_for( $post );
		$inow   = self::indexnow_for( $post );
		if ( ! $google && ! $inow ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			$url = get_permalink( $post );
			if ( ! $url ) {
				return;
			}
			$prev  = get_post_meta( $post->ID, SGIA_API::META_URL, true );
			// Same page in another spelling (%D8 vs %d8, decoded Persian, trailing slash) is not a slug change.
			$moved = $prev && ! SGIA_Check::same( $prev, $url );
			update_post_meta( $post->ID, SGIA_API::META_URL, $url );

			if ( $google ) {
				if ( $moved ) {
					self::queue( $prev, 'URL_DELETED', $post->ID );
				}
				if ( $moved || ! self::recently_sent( $post->ID, $url ) ) {
					self::queue( $url, 'URL_UPDATED', $post->ID );
				}
			}
			if ( $inow ) {
				SGIA_IndexNow::buffer_add( $moved ? [ $prev, $url ] : [ $url ] );
			}
			return;
		}

		if ( 'publish' === $old_status ) {
			$url = get_post_meta( $post->ID, SGIA_API::META_URL, true );
			if ( $url ) {
				if ( $google ) {
					self::queue( $url, 'URL_DELETED', $post->ID );
				}
				if ( $inow ) {
					SGIA_IndexNow::buffer_add( [ $url ] );
				}
			}
		}
	}

	/** Remember the real permalink before WordPress adds the __trashed suffix. */
	public static function on_before_trash( $post_id ) {
		$post = get_post( $post_id );
		if ( ( self::is_enabled_post( $post ) || self::indexnow_for( $post ) ) && 'publish' === $post->post_status
			&& ! get_post_meta( $post_id, SGIA_API::META_URL, true ) ) {
			update_post_meta( $post_id, SGIA_API::META_URL, get_permalink( $post ) );
		}
	}

	/** Permanent delete of a still-published post fires no status transition. */
	public static function on_before_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		$google = self::google_for( $post );
		$inow   = self::indexnow_for( $post );
		if ( ! $google && ! $inow ) {
			return;
		}
		$url = get_post_meta( $post_id, SGIA_API::META_URL, true );
		if ( ! $url ) {
			$url = get_permalink( $post );
		}
		if ( $url && $google ) {
			self::queue( $url, 'URL_DELETED', 0 );
		}
		if ( $url && $inow ) {
			SGIA_IndexNow::buffer_add( [ $url ] );
		}
	}

	/**
	 * Repeated saves of the same published post (quick edits, price/stock updates)
	 * shouldn't each spend quota: skip if Google accepted this URL in the last 30 minutes.
	 */
	private static function recently_sent( $post_id, $url ) {
		$last = get_post_meta( $post_id, SGIA_API::META_LAST, true );
		return is_array( $last ) && ! empty( $last['ok'] ) && 'URL_UPDATED' === $last['type']
			&& time() - (int) $last['time'] < 30 * MINUTE_IN_SECONDS
			&& SGIA_Check::same( $last['url'], $url );
	}

	private static function queue( $url, $type, $post_id ) {
		$args = [ $url, $type, (int) $post_id ];
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, $args );
		}
	}

	public static function cron_send( $url, $type, $post_id = 0 ) {
		if ( $post_id && ! get_post( $post_id ) ) {
			$post_id = 0;
		}
		if ( 'URL_UPDATED' === $type ) {
			$chk = SGIA_Check::run( $url, $post_id );
			if ( ! $chk['ok'] ) {
				SGIA_Stats::bump( 'skipped' );
				SGIA_API::log_entry( [ 'url' => $url, 'type' => 'SKIP', 'source' => 'auto', 'code' => (int) $chk['code'], 'msg' => $chk['reason'] ] );
				return;
			}
		}
		SGIA_API::publish( $url, $type, 'auto', $post_id );
	}
}
