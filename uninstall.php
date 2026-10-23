<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'sgia_service_account', 'sgia_settings', 'sgia_log', 'sgia_quota', 'sgia_stats', 'sgia_verified_at' ] as $sgia_option ) {
	delete_option( $sgia_option );
}

delete_transient( 'sgia_access_token' );
delete_transient( 'sgia_welcome' );
delete_post_meta_by_key( '_sgia_submitted_url' );
delete_post_meta_by_key( '_sgia_last' );
wp_clear_scheduled_hook( 'sgia_send_notification' );
