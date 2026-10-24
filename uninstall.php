<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'sgia_service_account', 'sgia_settings', 'sgia_log', 'sgia_quota', 'sgia_stats', 'sgia_verified_at', 'sgia_queue_db', 'sgia_queue_settings', 'sgia_queue_day', 'sgia_queue_halt' ] as $sgia_option ) {
	delete_option( $sgia_option );
}

delete_transient( 'sgia_access_token' );
delete_transient( 'sgia_welcome' );
delete_post_meta_by_key( '_sgia_submitted_url' );
delete_post_meta_by_key( '_sgia_last' );
wp_clear_scheduled_hook( 'sgia_send_notification' );
wp_clear_scheduled_hook( 'sgia_queue_tick' );
delete_transient( 'sgia_queue_lock' );

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sgia_queue' ); // phpcs:ignore
