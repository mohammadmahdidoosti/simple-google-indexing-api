<?php
/**
 * Plugin Name:       Simple Google Indexing API
 * Plugin URI:        https://developers.google.com/search/apis/indexing-api/v3/quickstart
 * Description:       ارسال خودکار و دستی درخواست ایندکس (URL_UPDATED / URL_DELETED) به Google Indexing API با داشبورد، ارسال گروهی، کنترل سهمیه و گزارش کامل.
 * Version:           2.3.2
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

// Another copy of this plugin (e.g. an old folder renamed but still active) is already
// loaded: don't load twice — that would be a fatal error. Ask to deactivate one copy.
if ( defined( 'SGIA_VERSION' ) ) {
	$sgia_dup_file = __FILE__;
	add_action( 'admin_notices', function () use ( $sgia_dup_file ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>Simple Google Indexing API:</strong> دو نسخه از این افزونه هم‌زمان فعال است (نسخه در حال اجرا: %1$s). یکی را از صفحه افزونه‌ها <b>غیرفعال</b> کنید. نسخه دوم: <code>%2$s</code></p></div>',
			esc_html( SGIA_VERSION ),
			esc_html( plugin_basename( $sgia_dup_file ) )
		);
	} );
	return;
}

define( 'SGIA_VERSION', '2.3.2' );
define( 'SGIA_FILE', __FILE__ );
define( 'SGIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SGIA_URL', plugin_dir_url( __FILE__ ) );

require_once SGIA_DIR . 'includes/class-sgia-http.php';
require_once SGIA_DIR . 'includes/class-sgia-api.php';
require_once SGIA_DIR . 'includes/class-sgia-stats.php';
require_once SGIA_DIR . 'includes/class-sgia-inspect.php';
require_once SGIA_DIR . 'includes/class-sgia-check.php';
require_once SGIA_DIR . 'includes/class-sgia-indexnow.php';
require_once SGIA_DIR . 'includes/class-sgia-report.php';
require_once SGIA_DIR . 'includes/class-sgia-auto.php';
require_once SGIA_DIR . 'includes/class-sgia-queue.php';
require_once SGIA_DIR . 'includes/class-sgia-admin.php';

SGIA_Auto::init();
SGIA_Queue::init();
SGIA_IndexNow::init();
SGIA_Report::init();

if ( is_admin() ) {
	SGIA_Admin::init();
}

register_activation_hook( __FILE__, [ 'SGIA_Admin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SGIA_Auto', 'deactivate' ] );
register_deactivation_hook( __FILE__, [ 'SGIA_Queue', 'deactivate' ] );
register_deactivation_hook( __FILE__, [ 'SGIA_IndexNow', 'deactivate' ] );
register_deactivation_hook( __FILE__, [ 'SGIA_Report', 'deactivate' ] );
