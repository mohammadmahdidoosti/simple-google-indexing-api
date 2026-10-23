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

	public static function on_status_change( $new_status, $old_status, $post ) {
		if ( ! self::active() || ! self::is_enabled_post( $post ) ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			$url = get_permalink( $post );
			if ( ! $url ) {
				return;
			}
			$prev = get_post_meta( $post->ID, SGIA_API::META_URL, true );
			if ( $prev && $prev !== $url ) {
				self::queue( $prev, 'URL_DELETED', $post->ID ); // slug changed
			}
			update_post_meta( $post->ID, SGIA_API::META_URL, $url );
			self::queue( $url, 'URL_UPDATED', $post->ID );
			return;
		}

		if ( 'publish' === $old_status ) {
			$url = get_post_meta( $post->ID, SGIA_API::META_URL, true );
			if ( $url ) {
				self::queue( $url, 'URL_DELETED', $post->ID );
			}
		}
	}

	/** Remember the real permalink before WordPress adds the __trashed suffix. */
	public static function on_before_trash( $post_id ) {
		$post = get_post( $post_id );
		if ( self::is_enabled_post( $post ) && 'publish' === $post->post_status
			&& ! get_post_meta( $post_id, SGIA_API::META_URL, true ) ) {
			update_post_meta( $post_id, SGIA_API::META_URL, get_permalink( $post ) );
		}
	}

	/** Permanent delete of a still-published post fires no status transition. */
	public static function on_before_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! self::active() || ! self::is_enabled_post( $post ) || 'publish' !== $post->post_status ) {
			return;
		}
		$url = get_post_meta( $post_id, SGIA_API::META_URL, true );
		if ( ! $url ) {
			$url = get_permalink( $post );
		}
		if ( $url ) {
			self::queue( $url, 'URL_DELETED', 0 );
		}
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
		SGIA_API::publish( $url, $type, 'auto', $post_id );
	}
}
