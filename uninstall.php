<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'sgia_service_account', 'sgia_settings', 'sgia_log', 'sgia_quota', 'sgia_stats', 'sgia_verified_at', 'sgia_queue_db', 'sgia_queue_settings', 'sgia_queue_day', 'sgia_queue_halt', 'sgia_daily', 'sgia_inspect_day', 'sgia_inspect_halt', 'sgia_indexnow_buffer', 'sgia_indexnow_bulk', 'sgia_report_last', 'sgia_alert_last', 'sgia_cron_seen' ] as $sgia_option ) {
	delete_option( $sgia_option );
}

delete_transient( 'sgia_access_token' );
delete_transient( 'sgia_access_token_v2' );
delete_transient( 'sgia_gsc_property' );
delete_transient( 'sgia_welcome' );
delete_post_meta_by_key( '_sgia_submitted_url' );
delete_post_meta_by_key( '_sgia_last' );
wp_clear_scheduled_hook( 'sgia_send_notification' );
wp_clear_scheduled_hook( 'sgia_queue_tick' );
wp_clear_scheduled_hook( 'sgia_indexnow_flush' );
wp_clear_scheduled_hook( 'sgia_indexnow_bulk_step' );
wp_clear_scheduled_hook( 'sgia_report_tick' );
delete_transient( 'sgia_queue_lock' );

// IndexNow key file written to the site root (only if it still holds our key).
$sgia_settings = get_option( 'sgia_settings', [] );
if ( ! empty( $sgia_settings['indexnow_key'] ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$sgia_key_file = trailingslashit( get_home_path() ) . $sgia_settings['indexnow_key'] . '.txt';
	if ( file_exists( $sgia_key_file ) && trim( (string) file_get_contents( $sgia_key_file ) ) === $sgia_settings['indexnow_key'] ) { // phpcs:ignore
		@unlink( $sgia_key_file ); // phpcs:ignore
	}
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sgia_queue' ); // phpcs:ignore
