<?php
/**
 * Plugin Name:       Simple Google Indexing API
 * Plugin URI:        https://developers.google.com/search/apis/indexing-api/v3/quickstart
 * Description:       ارسال خودکار و دستی درخواست ایندکس (URL_UPDATED / URL_DELETED) به Google Indexing API با داشبورد، ارسال گروهی، کنترل سهمیه و گزارش کامل.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Moh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sgia
 *
 * امنیت بیشتر (اختیاری): فایل کلید را بیرون از public_html بگذارید و در wp-config.php بنویسید:
 *   define( 'SGIA_KEY_FILE', '/home/USER/keys/indexing-service-account.json' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SGIA_VERSION', '2.0.0' );
define( 'SGIA_FILE', __FILE__ );
define( 'SGIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SGIA_URL', plugin_dir_url( __FILE__ ) );

require_once SGIA_DIR . 'includes/class-sgia-api.php';
require_once SGIA_DIR . 'includes/class-sgia-auto.php';
require_once SGIA_DIR . 'includes/class-sgia-admin.php';

SGIA_Auto::init();

if ( is_admin() ) {
	SGIA_Admin::init();
}

register_activation_hook( __FILE__, [ 'SGIA_Admin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SGIA_Auto', 'deactivate' ] );
