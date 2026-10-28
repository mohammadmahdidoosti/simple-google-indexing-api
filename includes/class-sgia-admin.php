<?php
/**
 * Admin UI: menu page (dashboard / submit / settings / log), AJAX,
 * post list column + row action + bulk action, edit-screen meta box.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'sgia';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_list_hooks' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_action( 'admin_post_sgia_export', [ __CLASS__, 'export_csv' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( SGIA_FILE ), [ __CLASS__, 'action_links' ] );

		foreach ( [ 'submit', 'test', 'save_settings', 'delete_key', 'recent', 'clear_log', 'post_send', 'queue_import', 'queue_save', 'queue_run', 'queue_action', 'gsc_detect', 'report_test', 'indexnow_test', 'indexnow_bulk', 'indexnow_status', 'indexnow_submit' ] as $a ) {
			add_action( "wp_ajax_sgia_{$a}", [ __CLASS__, "ajax_{$a}" ] );
		}
	}

	public static function activate() {
		if ( false === get_option( SGIA_API::OPT_SETTINGS ) ) {
			add_option( SGIA_API::OPT_SETTINGS, SGIA_API::defaults() );
		}
		set_transient( 'sgia_welcome', 1, 300 );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function fa( $value ) {
		return strtr( (string) $value, [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ] );
	}

	/** Human-readable URL: decodes Persian slugs (%d8%a7…) for display only. */
	private static function display_url( $url ) {
		$decoded = rawurldecode( (string) $url );
		return ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $decoded, 'UTF-8' ) ) ? $url : $decoded;
	}

	/** On/off row used across settings. */
	private static function switch_row( $name, $on, $title, $desc = '', $attrs = '' ) {
		?>
		<label class="sgia-switch-row" <?php echo $attrs; // phpcs:ignore ?>>
			<span>
				<strong><?php echo esc_html( $title ); ?></strong>
				<?php if ( $desc ) : ?><small><?php echo wp_kses( $desc, [ 'code' => [], 'b' => [] ] ); ?></small><?php endif; ?>
			</span>
			<span class="sgia-switch">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on, 1 ); ?>>
				<i></i>
			</span>
		</label>
		<?php
	}

	/** Site-wide problems shown on top of the plugin pages. */
	private static function health_alert() {
		$h = SGIA_Report::health();
		if ( ! $h ) {
			return;
		}
		?>
		<div class="sgia-alert sgia-alert--fail sgia-alert--top">
			<strong><?php echo esc_html( $h ); ?></strong>
			<span class="sgia-alert__sub">صف و گزارش روزانه به WP-Cron وابسته‌اند. در کنترل پنل هاست (cPanel/DirectAdmin ← Cron Jobs) این دستور را هر ۱۵ دقیقه اجرا کنید:</span>
			<button type="button" class="sgia-copy-chip sgia-copy-chip--block" data-sgia-copy="<?php echo esc_attr( SGIA_Report::cron_command() ); ?>" dir="ltr"><?php echo self::icon( 'copy' ); // phpcs:ignore ?><span><?php echo esc_html( SGIA_Report::cron_command() ); ?></span></button>
		</div>
		<?php
	}

	private static function page_url( $tab = 'dashboard' ) {
		return admin_url( 'admin.php?page=' . self::SLUG . ( 'dashboard' === $tab ? '' : '&tab=' . $tab ) );
	}

	private static function ago( $ts ) {
		if ( ! $ts ) {
			return '—';
		}
		$diff = time() - (int) $ts;
		if ( $diff < 60 ) {
			return 'لحظاتی پیش';
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			return self::fa( floor( $diff / 60 ) ) . ' دقیقه پیش';
		}
		if ( $diff < DAY_IN_SECONDS ) {
			return self::fa( floor( $diff / HOUR_IN_SECONDS ) ) . ' ساعت پیش';
		}
		return self::fa( floor( $diff / DAY_IN_SECONDS ) ) . ' روز پیش';
	}

	private static function until( $ts ) {
		$diff = max( 0, (int) $ts - time() );
		if ( $diff >= 36 * HOUR_IN_SECONDS ) {
			return self::fa( (int) round( $diff / DAY_IN_SECONDS ) ) . ' روز';
		}
		$h    = floor( $diff / HOUR_IN_SECONDS );
		$m    = floor( ( $diff % HOUR_IN_SECONDS ) / 60 );
		return ( $h ? self::fa( $h ) . ' ساعت و ' : '' ) . self::fa( $m ) . ' دقیقه';
	}

	private static function type_label( $type ) {
		$map = [ 'URL_DELETED' => 'حذف', 'URL_UPDATED' => 'به‌روزرسانی', 'INDEXNOW' => 'IndexNow', 'SKIP' => 'رد پیش از ارسال' ];
		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/** Dot / badge class for a log row: ok | fail | skip. */
	private static function row_state( $row ) {
		if ( isset( $row['type'] ) && 'SKIP' === $row['type'] ) {
			return 'skip';
		}
		return ! empty( $row['ok'] ) ? 'ok' : 'fail';
	}

	private static function source_label( $source ) {
		$map = [ 'auto' => 'خودکار', 'manual' => 'دستی', 'bulk' => 'گروهی', 'post' => 'ویرایشگر', 'queue' => 'صف', 'gscii' => 'ایندکس گوگل' ];
		return isset( $map[ $source ] ) ? $map[ $source ] : '—';
	}

	private static function icon( $name ) {
		$icons = [
			'logo'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m20 20-4.6-4.6"/><path d="M10.5 7.5v6M7.5 10.5l3-3 3 3"/></svg>',
			'check'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17 19 7"/></svg>',
			'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>',
			'key'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l2 2M15 8l2 2"/></svg>',
			'copy'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>',
			'info'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
		];
		return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
	}

	/* ------------------------------------------------------------------ *
	 * Menu & assets
	 * ------------------------------------------------------------------ */

	public static function menu() {
		self::$hook = add_menu_page(
			'Google Indexing API',
			'Indexing API',
			self::CAP,
			self::SLUG,
			[ __CLASS__, 'render' ],
			'dashicons-search',
			81
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url( 'settings' ) ) . '">تنظیمات</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = ( $hook === self::$hook );
		$lists  = $screen && in_array( $screen->base, [ 'edit', 'post' ], true )
			&& in_array( $screen->post_type, SGIA_API::settings()['types'], true );

		if ( ! $ours && ! $lists ) {
			return;
		}

		wp_enqueue_style( 'sgia-admin', SGIA_URL . 'assets/admin.css', [], SGIA_VERSION );
		wp_enqueue_script( 'sgia-admin', SGIA_URL . 'assets/admin.js', [], SGIA_VERSION, true );
		wp_localize_script( 'sgia-admin', 'SGIA', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sgia_ajax' ),
			'quota' => SGIA_API::quota(),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Page shell
	 * ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$tabs = [
			'dashboard' => [ 'داشبورد', 'dashicons-chart-area' ],
			'submit'    => [ 'ارسال', 'dashicons-upload' ],
			'queue'     => [ 'صف ارسال', 'dashicons-backup' ],
			'settings'  => [ 'تنظیمات', 'dashicons-admin-generic' ],
			'log'       => [ 'گزارش‌ها', 'dashicons-list-view' ],
		];
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}
		$q       = SGIA_API::quota();
		$pending = SGIA_Queue::counts()['pending'];
		?>
		<div class="wrap sgia">
			<header class="sgia-header">
				<div class="sgia-brand">
					<span class="sgia-logo" aria-hidden="true"><?php echo self::icon( 'logo' ); // phpcs:ignore ?></span>
					<div>
						<h1 class="sgia-title">Google Indexing API</h1>
						<p class="sgia-subtitle">اطلاع‌رسانی سریع انتشار، تغییر و حذف صفحات به گوگل</p>
					</div>
				</div>
				<div class="sgia-quota-pill" title="<?php echo esc_attr( 'ریست در ' . self::until( $q['reset_at'] ) . ' دیگر' ); ?>">
					<span class="sgia-quota-pill__label">سهمیه امروز</span>
					<strong><span data-q-used><?php echo esc_html( self::fa( $q['used'] ) ); ?></span> / <span data-q-limit><?php echo esc_html( self::fa( $q['limit'] ) ); ?></span></strong>
					<span class="sgia-meter"><i data-q-bar style="width:<?php echo (int) $q['percent']; ?>%"></i></span>
				</div>
			</header>
			<hr class="wp-header-end">

			<nav class="sgia-tabs" aria-label="بخش‌ها">
				<?php foreach ( $tabs as $key => $t ) : ?>
					<a href="<?php echo esc_url( self::page_url( $key ) ); ?>" class="sgia-tab<?php echo $key === $tab ? ' is-active' : ''; ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $t[1] ); ?>"></span><?php echo esc_html( $t[0] ); ?>
						<?php if ( 'queue' === $key && $pending ) : ?><b class="sgia-tab__badge"><?php echo esc_html( self::fa( number_format( $pending ) ) ); ?></b><?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="sgia-body">
				<?php call_user_func( [ __CLASS__, 'tab_' . $tab ] ); ?>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Tab: dashboard
	 * ------------------------------------------------------------------ */

	private static function tab_dashboard() {
		$has      = SGIA_API::has_key();
		$verified = (int) get_option( SGIA_API::OPT_VERIFIED, 0 );
		$stats    = SGIA_API::stats();
		$day      = SGIA_Stats::today();
		$tried    = $day['g_ok'] + $day['g_fail'];
		$rate     = [ 'ok' => $day['g_ok'], 'total' => $tried, 'rate' => $tried ? (int) round( $day['g_ok'] * 100 / $tried ) : null ];
		$q        = SGIA_API::quota();
		$s        = SGIA_API::settings();
		$recent   = array_slice( SGIA_API::get_log(), 0, 6 );

		$steps = [
			[ $has, 'بارگذاری کلید سرویس اکانت', 'فایل JSON را از Google Cloud دانلود و در تنظیمات بارگذاری کنید.' ],
			[ $verified > 0, 'تأیید اتصال به گوگل', 'دکمه «تست اتصال» را بزنید تا توکن دریافت شود.' ],
			[ $stats['ok'] > 0, 'اولین ارسال موفق', 'ایمیل سرویس اکانت را در Search Console با نقش Owner اضافه کنید و یک آدرس بفرستید.' ],
		];
		$done = count( array_filter( wp_list_pluck( $steps, 0 ) ) );

		self::health_alert();

		if ( $done < 3 ) :
			?>
			<section class="sgia-card sgia-onboard">
				<div class="sgia-onboard__head">
					<div>
						<h2>راه‌اندازی افزونه</h2>
						<p><?php echo esc_html( self::fa( $done ) ); ?> از ۳ مرحله انجام شده</p>
					</div>
					<a class="sgia-btn sgia-btn--primary" href="<?php echo esc_url( self::page_url( $has ? 'submit' : 'settings' ) ); ?>"><?php echo $has ? 'ارسال اولین آدرس' : 'شروع راه‌اندازی'; ?></a>
				</div>
				<span class="sgia-meter sgia-meter--lg"><i style="width:<?php echo (int) round( $done * 100 / 3 ); ?>%"></i></span>
				<ol class="sgia-steps">
					<?php foreach ( $steps as $i => $step ) : ?>
						<li class="<?php echo $step[0] ? 'is-done' : ''; ?>">
							<span class="sgia-steps__dot"><?php echo $step[0] ? self::icon( 'check' ) : esc_html( self::fa( $i + 1 ) ); // phpcs:ignore ?></span>
							<div><strong><?php echo esc_html( $step[1] ); ?></strong><span><?php echo esc_html( $step[2] ); ?></span></div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

		<div class="sgia-stats sgia-stats--4">
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">مصرف سهمیه امروز</span>
				<strong class="sgia-stat__value"><span data-q-used><?php echo esc_html( self::fa( $q['used'] ) ); ?></span><small> / <?php echo esc_html( self::fa( $q['limit'] ) ); ?></small></strong>
				<span class="sgia-meter"><i data-q-bar style="width:<?php echo (int) $q['percent']; ?>%"></i></span>
				<span class="sgia-stat__sub">ریست در <?php echo esc_html( self::until( $q['reset_at'] ) ); ?> دیگر</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">نرخ موفقیت امروز</span>
				<strong class="sgia-stat__value"><?php echo null === $rate['rate'] ? '—' : esc_html( self::fa( $rate['rate'] ) ) . '<small>٪</small>'; ?></strong>
				<span class="sgia-stat__sub"><?php echo esc_html( self::fa( $rate['ok'] ) . ' موفق از ' . self::fa( $rate['total'] ) . ' درخواست' ); ?></span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">کل ارسال‌های موفق</span>
				<strong class="sgia-stat__value"><?php echo esc_html( self::fa( number_format( $stats['ok'] ) ) ); ?></strong>
				<span class="sgia-stat__sub">ناموفق: <?php echo esc_html( self::fa( $stats['fail'] ) ); ?> · آخرین: <?php echo esc_html( self::ago( $stats['last'] ) ); ?></span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">ارسال خودکار</span>
				<strong class="sgia-stat__value">
					<span class="sgia-badge <?php echo $s['auto'] && $has ? 'sgia-badge--ok' : 'sgia-badge--muted'; ?>"><?php echo $s['auto'] && $has ? 'فعال' : 'غیرفعال'; ?></span>
				</strong>
				<span class="sgia-stat__sub"><?php echo esc_html( $s['types'] ? implode( '، ', $s['types'] ) : 'هیچ نوع محتوایی انتخاب نشده' ); ?></span>
			</section>
			<?php
			$qc   = SGIA_Queue::counts();
			$week = SGIA_Stats::range( 7 );
			$irate = $qc['checked'] ? (int) round( $qc['indexed'] * 100 / $qc['checked'] ) : null;
			?>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">ایندکس پس از ارسال</span>
				<strong class="sgia-stat__value"><?php echo null === $irate ? '—' : esc_html( self::fa( $irate ) ) . '<small>٪</small>'; ?></strong>
				<?php if ( null !== $irate ) : ?><span class="sgia-meter"><i class="is-ok" style="width:<?php echo (int) $irate; ?>%"></i></span><?php endif; ?>
				<span class="sgia-stat__sub">
					<?php
					echo esc_html( $qc['checked']
						? self::fa( number_format( $qc['indexed'] ) ) . ' از ' . self::fa( number_format( $qc['checked'] ) ) . ' آدرس بررسی‌شده'
						: ( $s['recheck_days'] ? self::fa( $s['recheck_days'] ) . ' روز بعد از ارسال بررسی می‌شود' : 'بررسی پس از ارسال خاموش است' ) );
					?>
				</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">صف ارسال</span>
				<?php $qcap = SGIA_Queue::settings()['cap']; ?>
				<strong class="sgia-stat__value"><?php echo esc_html( self::fa( number_format( $qc['pending'] ) ) ); ?><small> در انتظار</small></strong>
				<span class="sgia-stat__sub">
					<?php if ( $qc['pending'] ) : ?>
						<?php echo esc_html( 'حدود ' . self::fa( (int) ceil( $qc['pending'] / max( 1, $qcap ) ) ) . ' روز · امروز ' . self::fa( SGIA_Queue::sent_today() ) . ' از ' . self::fa( $qcap ) ); ?>
					<?php else : ?>
						<a href="<?php echo esc_url( self::page_url( 'queue' ) ); ?>">افزودن فایل اکسل ←</a>
					<?php endif; ?>
				</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">سهمیه صرفه‌جویی‌شده (۷ روز)</span>
				<strong class="sgia-stat__value"><?php echo esc_html( self::fa( number_format( $week['skipped'] ) ) ); ?></strong>
				<span class="sgia-stat__sub">آدرس پیش از ارسال رد شد (ایندکس‌شده، noindex، ۴۰۴، ناموجود …)</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">IndexNow (۷ روز)</span>
				<?php if ( SGIA_IndexNow::enabled() ) : ?>
					<strong class="sgia-stat__value"><?php echo esc_html( self::fa( number_format( $week['indexnow'] ) ) ); ?></strong>
					<span class="sgia-stat__sub">آدرس به Bing، Yandex و … ارسال شد</span>
				<?php else : ?>
					<strong class="sgia-stat__value"><span class="sgia-badge sgia-badge--muted">غیرفعال</span></strong>
					<span class="sgia-stat__sub"><a href="<?php echo esc_url( self::page_url( 'settings' ) . '#sgia-sec-gsc' ); ?>">فعال‌سازی در تنظیمات ←</a></span>
				<?php endif; ?>
			</section>
		</div>

		<div class="sgia-split">
			<section class="sgia-card">
				<div class="sgia-card__head">
					<h2>فعالیت‌های اخیر</h2>
					<a href="<?php echo esc_url( self::page_url( 'log' ) ); ?>">همه گزارش‌ها ←</a>
				</div>
				<?php if ( ! $recent ) : ?>
					<div class="sgia-empty">
						<span class="dashicons dashicons-clock"></span>
						<p>هنوز درخواستی ارسال نشده.</p>
					</div>
				<?php else : ?>
					<ul class="sgia-activity">
						<?php foreach ( $recent as $row ) : ?>
							<li>
								<?php $st = self::row_state( $row ); ?>
								<span class="sgia-dot is-<?php echo esc_attr( $st ); ?>"></span>
								<div class="sgia-activity__main">
									<?php if ( 'INDEXNOW' === $row['type'] ) : ?>
										<span class="sgia-activity__url sgia-activity__url--text"><?php echo esc_html( self::fa( $row['url'] ) ); ?></span>
									<?php else : ?>
										<span class="sgia-activity__url" dir="ltr"><?php echo esc_html( self::display_url( $row['url'] ) ); ?></span>
									<?php endif; ?>
									<span class="sgia-activity__meta">
										<?php echo esc_html( self::type_label( $row['type'] ) . ' · ' . self::source_label( isset( $row['source'] ) ? $row['source'] : '' ) ); ?>
										<?php if ( 'ok' !== $st ) : ?> · <em class="is-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $row['msg'] ); ?></em><?php endif; ?>
									</span>
								</div>
								<time><?php echo esc_html( self::ago( $row['time'] ) ); ?></time>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<aside class="sgia-stack">
				<section class="sgia-card">
					<div class="sgia-card__head"><h2>میان‌بُرها</h2></div>
					<div class="sgia-quick">
						<a class="sgia-btn sgia-btn--primary sgia-btn--block" href="<?php echo esc_url( self::page_url( 'submit' ) ); ?>"><span class="dashicons dashicons-upload"></span>ارسال دستی آدرس‌ها</a>
						<?php $qc = SGIA_Queue::counts(); ?>
						<a class="sgia-btn sgia-btn--block" href="<?php echo esc_url( self::page_url( 'queue' ) ); ?>"><span class="dashicons dashicons-backup"></span>صف ارسال<?php echo $qc['pending'] ? esc_html( ' (' . self::fa( number_format( $qc['pending'] ) ) . ' در انتظار)' ) : ''; ?></a>
						<button type="button" class="sgia-btn sgia-btn--block" data-sgia-test <?php disabled( ! $has ); ?>><span class="dashicons dashicons-yes-alt"></span>تست اتصال</button>
						<a class="sgia-btn sgia-btn--ghost sgia-btn--block" href="https://search.google.com/search-console" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span>Search Console</a>
					</div>
				</section>
				<section class="sgia-callout">
					<span class="sgia-callout__icon"><?php echo self::icon( 'info' ); // phpcs:ignore ?></span>
					<div>
						<strong>محدودیت رسمی گوگل</strong>
						<p>گوگل این API را رسماً فقط برای صفحات دارای JobPosting یا BroadcastEvent داخل VideoObject پشتیبانی می‌کند. پاسخ موفق یعنی سیگنال خزش ثبت شد، نه تضمین ایندکس.</p>
					</div>
				</section>
			</aside>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Tab: submit
	 * ------------------------------------------------------------------ */

	private static function tab_submit() {
		if ( ! SGIA_API::has_key() ) {
			self::need_key();
			return;
		}
		?>
		<section class="sgia-card sgia-submit">
			<div class="sgia-card__head">
				<div>
					<h2>ارسال دستی</h2>
					<p>هر خط یک آدرس کامل. آدرس‌های تکراری و نامعتبر خودکار کنار گذاشته می‌شوند. «وضعیت ایندکس» از Search Console می‌پرسد و سهمیه Indexing API مصرف نمی‌کند.</p>
				</div>
			</div>

			<div class="sgia-segment" role="radiogroup" aria-label="نوع درخواست">
				<label><input type="radio" name="sgia-type" value="update" checked><span><i class="dashicons dashicons-update"></i>اعلام انتشار / به‌روزرسانی</span></label>
				<label><input type="radio" name="sgia-type" value="delete"><span><i class="dashicons dashicons-trash"></i>اعلام حذف</span></label>
				<label><input type="radio" name="sgia-type" value="inspect"><span><i class="dashicons dashicons-search"></i>وضعیت ایندکس</span></label>
				<label><input type="radio" name="sgia-type" value="status"><span><i class="dashicons dashicons-visibility"></i>آخرین اعلان</span></label>
				<?php if ( SGIA_IndexNow::enabled() ) : ?>
					<label><input type="radio" name="sgia-type" value="indexnow"><span><i class="dashicons dashicons-share-alt2"></i>IndexNow</span></label>
				<?php endif; ?>
			</div>

			<div class="sgia-field">
				<textarea id="sgia-urls" rows="9" dir="ltr" spellcheck="false" placeholder="<?php echo esc_attr( home_url( '/sample-page/' ) . "\n" . home_url( '/another-post/' ) ); ?>"></textarea>
				<div class="sgia-field__foot">
					<div class="sgia-counts">
						<span class="sgia-chip sgia-chip--accent" id="sgia-count-valid">۰ آدرس معتبر</span>
						<span class="sgia-chip sgia-chip--warn" id="sgia-count-invalid" hidden></span>
					</div>
					<div class="sgia-inline-actions">
						<button type="button" class="sgia-link-btn" data-sgia-fill="recent"><span class="dashicons dashicons-plus-alt2"></span>۲۰ نوشته اخیر</button>
						<button type="button" class="sgia-link-btn" data-sgia-fill="unsent"><span class="dashicons dashicons-plus-alt2"></span>منتشرشده‌های ارسال‌نشده</button>
						<button type="button" class="sgia-link-btn sgia-link-btn--muted" data-sgia-clear-urls>پاک کردن</button>
					</div>
				</div>
			</div>

			<div class="sgia-alert sgia-alert--warn" id="sgia-quota-warn" hidden></div>

			<div class="sgia-actions">
				<button type="button" class="sgia-btn sgia-btn--primary sgia-btn--lg" id="sgia-run" disabled><span class="dashicons dashicons-controls-play"></span><span data-label>شروع ارسال</span></button>
				<button type="button" class="sgia-btn sgia-btn--lg" id="sgia-stop" hidden><span class="dashicons dashicons-controls-pause"></span>توقف</button>
			</div>

			<div class="sgia-progress" id="sgia-progress" hidden>
				<div class="sgia-progress__top">
					<span id="sgia-progress-label"></span>
					<span id="sgia-progress-summary"></span>
				</div>
				<span class="sgia-meter sgia-meter--lg"><i id="sgia-progress-bar" style="width:0"></i></span>
			</div>

			<ul class="sgia-results" id="sgia-results" aria-live="polite"></ul>
		</section>
		<?php
	}

	private static function need_key() {
		?>
		<section class="sgia-card sgia-empty sgia-empty--lg">
			<span class="sgia-empty__icon"><?php echo self::icon( 'key' ); // phpcs:ignore ?></span>
			<h2>ابتدا کلید سرویس اکانت را تنظیم کنید</h2>
			<p>برای ارسال درخواست به گوگل، فایل JSON سرویس اکانت لازم است.</p>
			<a class="sgia-btn sgia-btn--primary" href="<?php echo esc_url( self::page_url( 'settings' ) ); ?>">رفتن به تنظیمات</a>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Tab: queue
	 * ------------------------------------------------------------------ */

	private static function gmt_ts( $mysql ) {
		return $mysql ? strtotime( $mysql . ' UTC' ) : 0;
	}

	private static function tab_queue() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only list filters
		$status = isset( $_GET['qstatus'] ) ? sanitize_key( wp_unslash( $_GET['qstatus'] ) ) : '';
		$search = isset( $_GET['qs'] ) ? sanitize_text_field( wp_unslash( $_GET['qs'] ) ) : '';
		$page   = isset( $_GET['qpage'] ) ? max( 1, absint( $_GET['qpage'] ) ) : 1;
		// phpcs:enable

		$c     = SGIA_Queue::counts();
		$s     = SGIA_Queue::settings();
		$limit = SGIA_API::settings()['daily_limit'];
		$today = SGIA_Queue::sent_today();
		$halt  = SGIA_Queue::halted();
		$next  = wp_next_scheduled( SGIA_Queue::CRON );
		$per   = 50;
		$list  = SGIA_Queue::rows( $status, $search, $page, $per );
		$pages = max( 1, (int) ceil( $list['total'] / $per ) );
		$days  = $c['pending'] ? (int) ceil( $c['pending'] / $s['cap'] ) : 0;
		$pct   = $c['total'] ? (int) round( $c['sent'] * 100 / $c['total'] ) : 0;
		$host  = SGIA_Queue::site_host();
		$base  = add_query_arg( array_filter( [ 'qstatus' => $status, 'qs' => $search ] ), self::page_url( 'queue' ) );
		?>
		<div id="sgia-queue">

		<?php self::health_alert(); ?>
		<?php if ( ! SGIA_API::has_key() ) : ?>
			<div class="sgia-alert sgia-alert--warn sgia-alert--top">کلید سرویس اکانت تنظیم نشده؛ می‌توانید فهرست را اضافه کنید، ولی ارسال بعد از <a href="<?php echo esc_url( self::page_url( 'settings' ) ); ?>">تنظیم کلید</a> شروع می‌شود.</div>
		<?php elseif ( $halt ) : ?>
			<div class="sgia-alert sgia-alert--fail sgia-alert--top">
				<strong>ارسال صف برای امروز متوقف شده:</strong> <?php echo esc_html( $halt ); ?>
				<span class="sgia-alert__sub">بعد از ریست سهمیه گوگل خودکار ادامه پیدا می‌کند. اگر مشکل دسترسی را برطرف کرده‌اید، «ارسال یک دسته الان» را بزنید.</span>
			</div>
		<?php endif; ?>

		<div class="sgia-stats">
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">در انتظار ارسال</span>
				<strong class="sgia-stat__value"><?php echo esc_html( self::fa( number_format( $c['pending'] ) ) ); ?></strong>
				<span class="sgia-stat__sub">از <?php echo esc_html( self::fa( number_format( $c['total'] ) ) ); ?> آدرس در صف</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">ارسال‌شده</span>
				<strong class="sgia-stat__value"><?php echo esc_html( self::fa( number_format( $c['sent'] ) ) ); ?><small> · <?php echo esc_html( self::fa( $pct ) ); ?>٪</small></strong>
				<span class="sgia-meter"><i class="is-ok" style="width:<?php echo (int) $pct; ?>%"></i></span>
				<span class="sgia-stat__sub">
					<?php echo esc_html( 'ردشده: ' . self::fa( number_format( $c['skipped'] ) ) . ' · ناموفق: ' . self::fa( $c['failed'] ) ); ?>
					<?php if ( $c['checked'] ) : ?><br><?php echo esc_html( 'ایندکس پس از ارسال: ' . self::fa( $c['indexed'] ) . ' از ' . self::fa( $c['checked'] ) ); ?><?php endif; ?>
				</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">ارسال امروز از صف</span>
				<strong class="sgia-stat__value"><?php echo esc_html( self::fa( $today ) ); ?><small> / <?php echo esc_html( self::fa( $s['cap'] ) ); ?></small></strong>
				<span class="sgia-meter"><i style="width:<?php echo (int) min( 100, round( $today * 100 / $s['cap'] ) ); ?>%"></i></span>
				<span class="sgia-stat__sub">ریست سهمیه در <?php echo esc_html( self::until( SGIA_API::quota()['reset_at'] ) ); ?> دیگر</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">پایان تقریبی</span>
				<strong class="sgia-stat__value">
					<?php if ( ! $c['pending'] ) : ?>
						<span class="sgia-badge sgia-badge--ok">صف خالی است</span>
					<?php else : ?>
						<?php echo esc_html( self::fa( $days ) ); ?><small> روز</small>
					<?php endif; ?>
				</strong>
				<span class="sgia-stat__sub">
					<?php echo $s['active'] ? esc_html( 'روزانه ' . self::fa( $s['cap'] ) . ' آدرس' ) : '<span class="sgia-badge sgia-badge--muted">متوقف</span>'; ?>
				</span>
			</section>
		</div>

		<div class="sgia-split sgia-split--wide">
			<section class="sgia-card">
				<div class="sgia-card__head">
					<div>
						<h2>افزودن فهرست به صف</h2>
						<p>خروجی اکسل Search Console را مستقیم بارگذاری کنید: Pages ← Not indexed ← یک دلیل ← Export ← Download Excel.</p>
					</div>
				</div>

				<label class="sgia-drop" id="sgia-q-drop" for="sgia-q-file">
					<input type="file" id="sgia-q-file" accept=".xlsx,.csv,.txt,.tsv" hidden>
					<span class="sgia-drop__icon"><?php echo self::icon( 'upload' ); // phpcs:ignore ?></span>
					<strong>فایل اکسل یا CSV را اینجا رها کنید</strong>
					<span>xlsx، csv یا txt — همه شیت‌ها و ستون‌ها خوانده می‌شوند</span>
				</label>

				<div class="sgia-file-chip" id="sgia-q-picked" hidden>
					<span class="dashicons dashicons-media-spreadsheet"></span>
					<span class="sgia-file-chip__name" data-name></span>
					<span class="sgia-file-chip__size" data-size></span>
					<button type="button" class="sgia-icon-btn sgia-icon-btn--sm" data-sgia-q-unpick title="حذف فایل"><span class="dashicons dashicons-no-alt"></span></button>
				</div>

				<details class="sgia-details">
					<summary>یا آدرس‌ها را مستقیم بچسبانید</summary>
					<textarea id="sgia-q-text" rows="5" dir="ltr" spellcheck="false" placeholder="<?php echo esc_attr( home_url( '/product/a/' ) . "\n" . home_url( '/product/b/' ) ); ?>"></textarea>
				</details>

				<ul class="sgia-rules">
					<li>فقط آدرس‌های دامنه <code dir="ltr"><?php echo esc_html( $host ); ?></code> نگه داشته می‌شوند.</li>
					<li>آدرس‌های تکراری و آدرس‌هایی که قبلاً در صف بوده‌اند دوباره اضافه نمی‌شوند.</li>
					<?php $g = SGIA_API::settings(); ?>
					<?php if ( SGIA_Queue::woo_active() && $g['woo_priority'] ) : ?>
						<li>محصولات تازه و پرفروش زودتر ارسال می‌شوند؛ بقیه به ترتیب فایل.</li>
					<?php else : ?>
						<li>ترتیب ارسال، همان ترتیب فایل است.</li>
					<?php endif; ?>
					<?php if ( $g['precheck'] || $g['inspect_before'] || $g['woo_skip_oos'] ) : ?>
						<li>آدرس‌های ایندکس‌شده، noindex، ریدایرکت، ۴۰۴<?php echo SGIA_Queue::woo_active() && $g['woo_skip_oos'] ? ' و محصولات ناموجود' : ''; ?> پیش از ارسال رد می‌شوند و سهمیه مصرف نمی‌کنند.</li>
					<?php endif; ?>
					<?php if ( SGIA_IndexNow::enabled() && $g['indexnow_queue'] ) : ?>
						<li>کل فهرست همان لحظه به IndexNow (Bing، Yandex) هم ارسال می‌شود.</li>
					<?php endif; ?>
				</ul>

				<div class="sgia-actions">
					<button type="button" class="sgia-btn sgia-btn--primary sgia-btn--lg" id="sgia-q-import" disabled><span class="dashicons dashicons-plus-alt2"></span>افزودن به صف</button>
				</div>
			</section>

			<aside class="sgia-card">
				<div class="sgia-card__head"><h2>زمان‌بندی ارسال</h2></div>
				<form id="sgia-q-settings" autocomplete="off">
					<label class="sgia-switch-row">
						<span>
							<strong>ارسال خودکار روزانه</strong>
							<small>هر ۱۵ دقیقه یک دسته تا سقف روزانه</small>
						</span>
						<span class="sgia-switch">
							<input type="checkbox" name="active" value="1" <?php checked( $s['active'], 1 ); ?>>
							<i></i>
						</span>
					</label>

					<div class="sgia-field-group">
						<label class="sgia-field-group__label" for="sgia-q-per-day">سقف ارسال روزانه از صف</label>
						<div class="sgia-input-suffix">
							<input type="number" id="sgia-q-per-day" name="per_day" min="1" max="<?php echo (int) $limit; ?>" value="<?php echo (int) $s['per_day']; ?>" dir="ltr">
							<span>از <?php echo esc_html( self::fa( $limit ) ); ?></span>
						</div>
						<p class="sgia-help">
							<?php echo esc_html( 'باقی‌مانده سهمیه (' . self::fa( max( 0, $limit - $s['cap'] ) ) . ' درخواست) برای ارسال خودکار نوشته‌های تازه آزاد می‌ماند.' ); ?>
						</p>
					</div>

					<?php
					$g    = SGIA_API::settings();
					$seen = (int) get_option( SGIA_Report::OPT_SEEN, 0 );
					?>
					<dl class="sgia-kv">
						<div><dt>اجرای بعدی</dt><dd><?php echo $next ? ( $next > time() ? esc_html( self::until( $next ) . ' دیگر' ) : ( time() - $next < HOUR_IN_SECONDS ? 'به‌زودی' : 'عقب افتاده' ) ) : '—'; ?></dd></div>
						<?php $lr = SGIA_Queue::last_run(); ?>
						<div><dt>آخرین اجرا</dt><dd><?php echo esc_html( $seen ? self::ago( $seen ) : '—' ); ?></dd></div>
						<?php if ( $lr ) : ?>
							<div><dt>نتیجه آخرین اجرا</dt><dd><?php echo esc_html( self::fa( $lr['sent'] ) . ' ارسال' . ( $lr['skipped'] ? '، ' . self::fa( $lr['skipped'] ) . ' رد' : '' ) . ( $lr['rechecked'] ? '، ' . self::fa( $lr['rechecked'] ) . ' بررسی' : '' ) . ' در ' . self::fa( $lr['secs'] ) . ' ثانیه' ); ?></dd></div>
						<?php endif; ?>
						<div><dt>هر اجرا</dt><dd>حداکثر <?php echo esc_html( self::fa( SGIA_Queue::BATCH ) ); ?> آدرس<?php echo SGIA_Http::parallel() ? '' : ' (یکی‌یکی، تا جایی که وقت اجرا برسد)'; ?></dd></div>
						<div><dt>بررسی پیش از ارسال</dt><dd><?php echo $g['precheck'] ? ( $g['precheck_http'] ? 'کامل' : 'فقط داخلی' ) : 'خاموش'; ?></dd></div>
						<div><dt>بررسی ایندکس</dt><dd><?php echo esc_html( ( $g['inspect_before'] ? 'پیش از ارسال' : '' ) . ( $g['inspect_before'] && $g['recheck_days'] ? ' + ' : '' ) . ( $g['recheck_days'] ? self::fa( $g['recheck_days'] ) . ' روز بعد' : '' ) ?: 'خاموش' ); ?></dd></div>
					</dl>

					<div class="sgia-quick">
						<button type="submit" class="sgia-btn sgia-btn--primary sgia-btn--block"><span data-label>ذخیره زمان‌بندی</span></button>
						<button type="button" class="sgia-btn sgia-btn--block" data-sgia-q-run <?php disabled( ! $c['pending'] || ! SGIA_API::has_key() ); ?>><span class="dashicons dashicons-controls-play"></span>ارسال یک دسته الان</button>
					</div>
				</form>
			</aside>
		</div>

		<section class="sgia-card sgia-log">
			<div class="sgia-toolbar">
				<nav class="sgia-segment sgia-segment--sm" aria-label="فیلتر وضعیت">
					<?php
					$filters = [
						''        => [ 'همه', $c['total'] ],
						'pending' => [ 'در صف', $c['pending'] ],
						'sent'    => [ 'ارسال‌شده', $c['sent'] ],
						'indexed' => [ 'ایندکس‌شده', $c['idx_any'] ],
						'skipped' => [ 'ردشده', $c['skipped'] ],
						'failed'  => [ 'ناموفق', $c['failed'] ],
					];
					$filters = array_filter( $filters, function ( $f, $k ) use ( $status ) {
						return '' === $k || $f[1] || $k === $status;
					}, ARRAY_FILTER_USE_BOTH );
					foreach ( $filters as $key => $f ) :
						$url = add_query_arg( array_filter( [ 'qstatus' => $key, 'qs' => $search ] ), self::page_url( 'queue' ) );
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $key === $status ? 'is-active' : ''; ?>"><?php echo esc_html( $f[0] ); ?> <b><?php echo esc_html( self::fa( number_format( $f[1] ) ) ); ?></b></a>
					<?php endforeach; ?>
				</nav>

				<form class="sgia-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="tab" value="queue">
					<?php if ( $status ) : ?><input type="hidden" name="qstatus" value="<?php echo esc_attr( $status ); ?>"><?php endif; ?>
					<span class="dashicons dashicons-search"></span>
					<input type="search" name="qs" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجو در آدرس‌ها…">
				</form>

				<div class="sgia-toolbar__end">
					<?php if ( $c['skipped'] ) : ?>
						<button type="button" class="sgia-btn sgia-btn--sm" data-sgia-q-op="retry_skipped" data-confirm="آدرس‌های ردشده به صف برگردند؟ هنگام ارسال دوباره بررسی می‌شوند."><span class="dashicons dashicons-undo"></span>بازگرداندن ردشده‌ها</button>
					<?php endif; ?>
					<?php if ( $c['failed'] ) : ?>
						<button type="button" class="sgia-btn sgia-btn--sm" data-sgia-q-op="retry"><span class="dashicons dashicons-update"></span>تلاش دوباره ناموفق‌ها</button>
					<?php endif; ?>
					<button type="button" class="sgia-btn sgia-btn--sm" data-sgia-q-op="clear_sent" data-confirm="آدرس‌های ارسال‌شده از جدول حذف شوند؟" <?php disabled( ! $c['sent'] ); ?>>پاک کردن ارسال‌شده‌ها</button>
					<button type="button" class="sgia-btn sgia-btn--sm sgia-btn--danger" data-sgia-q-op="clear_all" data-confirm="کل صف (همه آدرس‌ها) حذف شود؟ این کار قابل برگشت نیست." <?php disabled( ! $c['total'] ); ?>>خالی کردن صف</button>
				</div>
			</div>

			<?php if ( ! $list['rows'] ) : ?>
				<div class="sgia-empty">
					<span class="dashicons dashicons-backup"></span>
					<p><?php echo $c['total'] ? 'موردی با این فیلتر پیدا نشد.' : 'صف خالی است. فایل اکسل صفحات ایندکس‌نشده را بارگذاری کنید.'; ?></p>
				</div>
			<?php else : ?>
				<div class="sgia-table-wrap">
					<table class="sgia-table">
						<thead><tr><th>وضعیت</th><th>آدرس</th><th>ایندکس</th><th>زمان</th><th>پیام</th><th><span class="screen-reader-text">عملیات</span></th></tr></thead>
						<tbody>
						<?php
						$labels = [ 'pending' => [ 'در صف', 'muted' ], 'sent' => [ 'ارسال شد', 'ok' ], 'failed' => [ 'ناموفق', 'fail' ], 'skipped' => [ 'ردشده', 'warn' ] ];
						foreach ( $list['rows'] as $r ) :
							$lb = isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : [ $r->status, 'muted' ];
							$ts = 'sent' === $r->status ? self::gmt_ts( $r->sent_at ) : self::gmt_ts( $r->added_at );
							?>
							<tr data-row="<?php echo (int) $r->id; ?>">
								<td><span class="sgia-badge sgia-badge--<?php echo esc_attr( $lb[1] ); ?>"><?php echo esc_html( $lb[0] ); ?><?php echo $r->last_code ? ' · ' . (int) $r->last_code : ''; ?></span></td>
								<td class="sgia-table__url" dir="ltr"><a href="<?php echo esc_url( $r->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::display_url( $r->url ) ); ?></a><?php if ( 'gscii' === $r->source ) : ?> <span class="sgia-badge sgia-badge--muted sgia-badge--xs" dir="rtl" title="افزوده‌شده از افزونه‌ی ایندکس گوگل">ایندکس گوگل</span><?php endif; ?></td>
								<td class="sgia-table__idx">
									<?php if ( 'indexed' === $r->index_state ) : ?>
										<span class="sgia-badge sgia-badge--ok" title="<?php echo esc_attr( $r->coverage . ' — ' . wp_date( 'Y-m-d H:i', self::gmt_ts( $r->checked_at ) ) ); ?>">ایندکس شده</span>
									<?php elseif ( 'not_indexed' === $r->index_state ) : ?>
										<span class="sgia-badge sgia-badge--warn" title="<?php echo esc_attr( wp_date( 'Y-m-d H:i', self::gmt_ts( $r->checked_at ) ) ); ?>"><?php echo esc_html( $r->coverage ? $r->coverage : 'ایندکس نشده' ); ?></span>
										<?php if ( $r->recheck_at ) : ?><small class="sgia-muted">بررسی بعدی: <?php echo esc_html( wp_date( 'Y-m-d', self::gmt_ts( $r->recheck_at ) ) ); ?></small><?php endif; ?>
									<?php elseif ( 'sent' === $r->status && $r->recheck_at ) : ?>
										<span class="sgia-muted" title="<?php echo esc_attr( wp_date( 'Y-m-d H:i', self::gmt_ts( $r->recheck_at ) ) ); ?>">بررسی در <?php echo esc_html( self::until( self::gmt_ts( $r->recheck_at ) ) ); ?></span>
									<?php else : ?>
										<span class="sgia-muted">—</span>
									<?php endif; ?>
								</td>
								<td title="<?php echo esc_attr( wp_date( 'Y-m-d H:i', $ts ) ); ?>"><?php echo esc_html( ( 'sent' === $r->status ? 'ارسال: ' : 'افزوده: ' ) . self::ago( $ts ) ); ?></td>
								<td class="sgia-table__msg<?php echo 'skipped' === $r->status ? ' is-skip' : ''; ?>">
									<?php if ( $r->last_error ) : ?>
										<span><?php echo esc_html( $r->last_error ); ?></span>
										<?php if ( (int) $r->attempts > 1 ) : ?><small class="sgia-muted">(<?php echo esc_html( self::fa( $r->attempts ) ); ?> تلاش)</small><?php endif; ?>
									<?php else : ?>
										<span class="sgia-muted">—</span>
									<?php endif; ?>
								</td>
								<td><button type="button" class="sgia-icon-btn sgia-icon-btn--sm" data-sgia-q-op="delete" data-id="<?php echo (int) $r->id; ?>" title="حذف از صف"><span class="dashicons dashicons-trash"></span></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<nav class="sgia-pages" aria-label="صفحه‌بندی">
						<span class="sgia-pages__info"><?php echo esc_html( 'صفحه ' . self::fa( $page ) . ' از ' . self::fa( $pages ) . ' · ' . self::fa( number_format( $list['total'] ) ) . ' آدرس' ); ?></span>
						<span class="sgia-pages__links">
							<?php if ( $page > 1 ) : ?>
								<a class="sgia-btn sgia-btn--sm" href="<?php echo esc_url( add_query_arg( 'qpage', $page - 1, $base ) ); ?>">→ قبلی</a>
							<?php endif; ?>
							<?php if ( $page < $pages ) : ?>
								<a class="sgia-btn sgia-btn--sm" href="<?php echo esc_url( add_query_arg( 'qpage', $page + 1, $base ) ); ?>">بعدی ←</a>
							<?php endif; ?>
						</span>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Tab: settings
	 * ------------------------------------------------------------------ */

	private static function tab_settings() {
		$cred      = SGIA_API::credentials();
		$has       = ! is_wp_error( $cred );
		$from_file = SGIA_API::key_from_file();
		$s         = SGIA_API::settings();
		$verified  = (int) get_option( SGIA_API::OPT_VERIFIED, 0 );
		$email     = $has ? $cred['client_email'] : '';
		$prop      = $has ? ( $s['gsc_property'] ? $s['gsc_property'] : get_transient( SGIA_Inspect::PROP_CACHE ) ) : '';
		if ( $has && ! $prop && $verified ) {
			$prop = SGIA_Inspect::detect();
		}
		self::health_alert();
		?>
		<form id="sgia-settings-form" class="sgia-settings" autocomplete="off">
			<div class="sgia-stack">

				<section class="sgia-card">
					<div class="sgia-card__head">
						<div>
							<h2>کلید سرویس اکانت</h2>
							<p>فایل JSON که از Google Cloud Console دانلود کرده‌اید.</p>
						</div>
						<?php if ( $has ) : ?>
							<span class="sgia-badge <?php echo $verified ? 'sgia-badge--ok' : 'sgia-badge--warn'; ?>"><?php echo $verified ? 'اتصال تأیید شده' : 'تست نشده'; ?></span>
						<?php endif; ?>
					</div>

					<?php if ( $has ) : ?>
						<div class="sgia-account">
							<span class="sgia-account__avatar"><?php echo self::icon( 'key' ); // phpcs:ignore ?></span>
							<div class="sgia-account__info">
								<span class="sgia-account__email" dir="ltr"><?php echo esc_html( $email ); ?></span>
								<span class="sgia-account__meta">
									<?php echo ! empty( $cred['project_id'] ) ? 'پروژه: <code dir="ltr">' . esc_html( $cred['project_id'] ) . '</code>' : ''; ?>
									<?php echo $from_file ? ' · خوانده‌شده از <code>SGIA_KEY_FILE</code>' : ''; ?>
								</span>
							</div>
							<div class="sgia-account__actions">
								<button type="button" class="sgia-icon-btn" data-sgia-copy="<?php echo esc_attr( $email ); ?>" title="کپی ایمیل"><?php echo self::icon( 'copy' ); // phpcs:ignore ?></button>
								<?php if ( ! $from_file ) : ?>
									<button type="button" class="sgia-btn sgia-btn--sm" data-sgia-toggle="#sgia-drop-wrap">جایگزینی</button>
									<button type="button" class="sgia-btn sgia-btn--sm sgia-btn--danger" data-sgia-delete-key>حذف</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( ! $from_file ) : ?>
						<div id="sgia-drop-wrap" <?php echo $has ? 'hidden' : ''; ?>>
							<label class="sgia-drop" id="sgia-drop" for="sgia-key-file">
								<input type="file" id="sgia-key-file" accept=".json,application/json" hidden>
								<span class="sgia-drop__icon"><?php echo self::icon( 'upload' ); // phpcs:ignore ?></span>
								<strong>فایل JSON را اینجا رها کنید</strong>
								<span>یا برای انتخاب فایل کلیک کنید</span>
							</label>
							<div class="sgia-drop-preview" id="sgia-drop-preview" hidden></div>
							<details class="sgia-details">
								<summary>یا محتوای JSON را مستقیم بچسبانید</summary>
								<textarea name="key_json" id="sgia-key-json" rows="5" dir="ltr" spellcheck="false" placeholder='{"type": "service_account", "client_email": "...", "private_key": "..."}'></textarea>
							</details>
							<p class="sgia-help">کلید فقط در دیتابیس سایت خودتان ذخیره می‌شود. برای امنیت بیشتر می‌توانید ثابت <code>SGIA_KEY_FILE</code> را در wp-config.php تعریف کنید.</p>
						</div>
					<?php endif; ?>
				</section>

				<section class="sgia-card">
					<div class="sgia-card__head">
						<div>
							<h2>ارسال خودکار</h2>
							<p>هنگام انتشار یا ویرایش، به‌روزرسانی؛ هنگام حذف یا لغو انتشار، حذف اعلام می‌شود.</p>
						</div>
					</div>

					<label class="sgia-switch-row">
						<span>
							<strong>فعال‌سازی ارسال خودکار</strong>
							<small>تغییر نامک (slug) هم تشخیص داده می‌شود و آدرس قدیمی حذف اعلام می‌شود.</small>
						</span>
						<span class="sgia-switch">
							<input type="checkbox" name="auto" value="1" <?php checked( $s['auto'], 1 ); ?>>
							<i></i>
						</span>
					</label>

					<div class="sgia-field-group">
						<span class="sgia-field-group__label">نوع محتوا</span>
						<div class="sgia-chips">
							<?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) : ?>
								<?php
								if ( 'attachment' === $pt->name ) {
									continue;
								}
								?>
								<label class="sgia-check-chip">
									<input type="checkbox" name="types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $s['types'], true ) ); ?>>
									<span><?php echo esc_html( $pt->labels->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="sgia-field-group">
						<label class="sgia-field-group__label" for="sgia-limit">سقف ارسال روزانه</label>
						<div class="sgia-input-suffix">
							<input type="number" id="sgia-limit" name="daily_limit" min="1" max="100000" value="<?php echo (int) $s['daily_limit']; ?>" dir="ltr">
							<span>درخواست در روز</span>
						</div>
						<p class="sgia-help">سهمیه پیش‌فرض گوگل ۲۰۰ درخواست در روز برای هر پروژه است و نیمه‌شب به وقت Pacific ریست می‌شود. اگر سهمیه بیشتری گرفته‌اید این عدد را بالا ببرید.</p>
					</div>
				</section>

				<section class="sgia-card" id="sgia-sec-gsc">
					<div class="sgia-card__head">
						<div>
							<h2>بررسی ایندکس (Search Console)</h2>
							<p>با URL Inspection API معلوم می‌شود صفحه واقعاً در ایندکس گوگل هست یا نه. سهمیه‌اش جداست (۲۰۰۰ در روز).</p>
						</div>
						<?php if ( $has ) : ?>
							<span class="sgia-badge <?php echo $prop && ! is_wp_error( $prop ) ? 'sgia-badge--ok' : 'sgia-badge--warn'; ?>"><?php echo $prop && ! is_wp_error( $prop ) ? 'متصل' : 'property پیدا نشد'; ?></span>
						<?php endif; ?>
					</div>

					<div class="sgia-kv sgia-kv--top">
						<div><dt>Property</dt><dd dir="ltr" data-sgia-prop><?php echo $prop && ! is_wp_error( $prop ) ? esc_html( $prop ) : '—'; ?></dd></div>
						<div><dt>استفاده امروز</dt><dd><?php echo esc_html( self::fa( SGIA_Inspect::used_today() ) . ' از ' . self::fa( $s['inspect_limit'] ) ); ?></dd></div>
						<?php if ( SGIA_Inspect::halted() ) : ?>
							<div class="is-warn"><dt>متوقف تا فردا</dt><dd><?php echo esc_html( SGIA_Inspect::halted() ); ?></dd></div>
						<?php endif; ?>
					</div>
					<input type="hidden" name="gsc_property" value="<?php echo esc_attr( $s['gsc_property'] ); ?>" data-sgia-prop-input>
					<div class="sgia-inline-actions sgia-inline-actions--start">
						<button type="button" class="sgia-btn sgia-btn--sm" data-sgia-detect <?php disabled( ! $has ); ?>><span class="dashicons dashicons-search"></span>تشخیص property</button>
					</div>
					<?php if ( $prop && is_wp_error( $prop ) && $has ) : ?>
						<p class="sgia-help sgia-help--warn"><?php echo esc_html( $prop->get_error_message() . ' ' . ( is_string( $prop->get_error_data() ) ? $prop->get_error_data() : '' ) ); ?></p>
					<?php endif; ?>

					<?php self::switch_row( 'inspect_before', $s['inspect_before'], 'پیش از ارسال از صف، وضعیت ایندکس بررسی شود', 'صفحه‌هایی که از قبل ایندکس شده‌اند ارسال نمی‌شوند و سهمیه Indexing API هدر نمی‌رود.' ); ?>

					<div class="sgia-field-row">
						<div class="sgia-field-group">
							<label class="sgia-field-group__label" for="sgia-recheck">بررسی دوباره پس از ارسال</label>
							<div class="sgia-input-suffix">
								<input type="number" id="sgia-recheck" name="recheck_days" min="0" max="30" value="<?php echo (int) $s['recheck_days']; ?>" dir="ltr">
								<span>روز بعد (۰ = خاموش)</span>
							</div>
						</div>
						<div class="sgia-field-group">
							<label class="sgia-field-group__label" for="sgia-ilimit">سقف بررسی روزانه</label>
							<div class="sgia-input-suffix">
								<input type="number" id="sgia-ilimit" name="inspect_limit" min="0" max="2000" value="<?php echo (int) $s['inspect_limit']; ?>" dir="ltr">
								<span>از ۲۰۰۰</span>
							</div>
						</div>
					</div>
					<p class="sgia-help">هر آدرس ارسال‌شده تا ۳ بار بررسی می‌شود تا معلوم شود ایندکس شده یا نه. نتیجه در داشبورد و جدول صف دیده می‌شود.</p>
				</section>

				<section class="sgia-card">
					<div class="sgia-card__head">
						<div>
							<h2>بررسی پیش از ارسال</h2>
							<p>صفحه‌هایی که گوگل به هر حال ایندکس نمی‌کند ارسال نمی‌شوند.</p>
						</div>
					</div>
					<?php self::switch_row( 'precheck', $s['precheck'], 'بررسی پیش از ارسال (صف و ارسال خودکار)', 'منتشر نبودن، رمزدار بودن، noindex یا canonical در Rank Math / Yoast.' ); ?>
					<div class="sgia-sub">
						<?php self::switch_row( 'precheck_http', $s['precheck_http'], 'باز کردن خود صفحه', 'خطای ۴۰۴، ریدایرکت، تگ <code>noindex</code> و <code>canonical</code> واقعی صفحه را هم بررسی می‌کند. اگر فایروال یا CDN پاسخ ۴۰۳ یا ۵۰۳ بدهد، آدرس رد نمی‌شود.' ); ?>
					</div>
				</section>

				<section class="sgia-card">
					<div class="sgia-card__head">
						<div>
							<h2>IndexNow (Bing، Yandex و ...)</h2>
							<p>رسماً برای همه نوع صفحه، تا ۱۰٬۰۰۰ آدرس در هر درخواست و بدون سقف سخت روزانه. گوگل از IndexNow استفاده نمی‌کند.</p>
						</div>
						<span class="sgia-badge <?php echo SGIA_IndexNow::enabled() ? 'sgia-badge--ok' : 'sgia-badge--muted'; ?>"><?php echo SGIA_IndexNow::enabled() ? 'فعال' : 'غیرفعال'; ?></span>
					</div>

					<?php if ( SGIA_IndexNow::rank_math_conflict() && SGIA_IndexNow::enabled() ) : ?>
						<div class="sgia-alert sgia-alert--warn sgia-alert--tight">ماژول «Instant Indexing» در Rank Math هم IndexNow ارسال می‌کند. برای جلوگیری از ارسال تکراری، یکی از این دو را خاموش کنید.</div>
					<?php elseif ( SGIA_IndexNow::rank_math_conflict() ) : ?>
						<p class="sgia-help sgia-help--top">ماژول «Instant Indexing» در Rank Math همین الان IndexNow را ارسال می‌کند؛ لازم نیست اینجا هم فعالش کنید.</p>
					<?php endif; ?>

					<?php self::switch_row( 'indexnow', $s['indexnow'], 'فعال‌سازی IndexNow' ); ?>

					<?php if ( $s['indexnow_key'] ) : ?>
						<div class="sgia-kv">
							<div><dt>کلید</dt><dd dir="ltr"><code><?php echo esc_html( $s['indexnow_key'] ); ?></code></dd></div>
							<div><dt>فایل کلید</dt><dd dir="ltr"><a href="<?php echo esc_url( SGIA_IndexNow::key_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( SGIA_IndexNow::key_url() ) ); ?></a></dd></div>
						</div>
					<?php endif; ?>

					<div class="sgia-sub">
						<?php self::switch_row( 'indexnow_auto', $s['indexnow_auto'], 'ارسال خودکار هنگام انتشار، ویرایش و حذف', 'برای همه انواع محتوا، از جمله محصولات.' ); ?>
						<?php self::switch_row( 'indexnow_queue', $s['indexnow_queue'], 'ارسال فهرست‌های صف به IndexNow', 'آدرس‌های فایل اکسل همان لحظه یک‌جا به IndexNow هم فرستاده می‌شوند.' ); ?>
					</div>

					<?php if ( SGIA_IndexNow::enabled() ) : ?>
						<?php $bulk = SGIA_IndexNow::bulk_state(); ?>
						<div class="sgia-bulk" data-sgia-bulk data-running="<?php echo $bulk && ! $bulk['done'] ? '1' : '0'; ?>">
							<div class="sgia-bulk__text">
								<strong>ارسال همه صفحات منتشرشده</strong>
								<span data-sgia-bulk-status>
									<?php
									if ( $bulk ) {
										echo esc_html( $bulk['done'] ? ( $bulk['error'] ? 'متوقف شد: ' . $bulk['error'] : 'آخرین بار: ' . self::fa( number_format( $bulk['sent'] ) ) . ' آدرس، ' . self::ago( $bulk['started'] ) ) : 'در حال ارسال… ' . self::fa( number_format( $bulk['sent'] ) ) . ' از ' . self::fa( number_format( $bulk['total'] ) ) );
									} else {
										echo 'نوشته‌ها، برگه‌ها و محصولات؛ در پس‌زمینه و دسته‌های ۲۰۰۰تایی.';
									}
									?>
								</span>
							</div>
							<div class="sgia-inline-actions">
								<button type="button" class="sgia-btn sgia-btn--sm" data-sgia-indexnow-test>تست اتصال</button>
								<button type="button" class="sgia-btn sgia-btn--sm sgia-btn--primary" data-sgia-indexnow-bulk <?php disabled( $bulk && ! $bulk['done'] ); ?>>شروع ارسال همه</button>
							</div>
						</div>
					<?php endif; ?>
				</section>

				<section class="sgia-card">
					<div class="sgia-card__head">
						<div>
							<h2>ووکامرس</h2>
							<p>ترتیب ارسال صف و رد کردن محصولات ناموجود.</p>
						</div>
						<?php if ( ! SGIA_Queue::woo_active() ) : ?><span class="sgia-badge sgia-badge--muted">ووکامرس نصب نیست</span><?php endif; ?>
					</div>
					<?php self::switch_row( 'woo_priority', $s['woo_priority'], 'اولویت محصولات تازه و پرفروش', 'محصولات ۳۰ روز اخیر اول، سپس به ترتیب تعداد فروش. بقیه آدرس‌ها به ترتیب فایل.' ); ?>
					<?php self::switch_row( 'woo_skip_oos', $s['woo_skip_oos'], 'ارسال نکردن محصولات ناموجود', 'محصولات با وضعیت «ناموجود» از صف رد می‌شوند و سهمیه مصرف نمی‌کنند.' ); ?>
				</section>

				<section class="sgia-card" id="sgia-sec-report">
					<div class="sgia-card__head">
						<div>
							<h2>گزارش روزانه و هشدار</h2>
							<p>هر روز بعد از ریست سهمیه گوگل (نیمه‌شب Pacific؛ الان ساعت <?php echo esc_html( self::fa( wp_date( 'H:i', SGIA_API::quota()['reset_at'] ) ) ); ?> به وقت سایت، ممکن است تا یک ساعت بعد) خلاصه روز قبل ارسال می‌شود.</p>
						</div>
					</div>

					<?php self::switch_row( 'report_email', $s['report_email'], 'ارسال با ایمیل' ); ?>
					<div class="sgia-sub">
						<div class="sgia-field-group sgia-field-group--tight">
							<input type="email" class="sgia-input" name="report_email_to" value="<?php echo esc_attr( $s['report_email_to'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" dir="ltr">
						</div>
					</div>

					<div class="sgia-field-group">
						<span class="sgia-field-group__label">پیام‌رسان</span>
						<div class="sgia-segment sgia-segment--sm sgia-segment--radio" role="radiogroup">
							<label><input type="radio" name="report_msgr" value="" <?php checked( $s['report_msgr'], '' ); ?>><span>خاموش</span></label>
							<label><input type="radio" name="report_msgr" value="bale" <?php checked( $s['report_msgr'], 'bale' ); ?>><span>بله</span></label>
							<label><input type="radio" name="report_msgr" value="telegram" <?php checked( $s['report_msgr'], 'telegram' ); ?>><span>تلگرام</span></label>
						</div>
					</div>

					<div class="sgia-msgr" data-sgia-msgr <?php echo $s['report_msgr'] ? '' : 'hidden'; ?>>
						<div class="sgia-field-row">
							<div class="sgia-field-group sgia-field-group--tight">
								<label class="sgia-field-group__label" for="sgia-rtoken">توکن ربات</label>
								<input type="password" id="sgia-rtoken" class="sgia-input" name="report_token" value="" dir="ltr" autocomplete="new-password" placeholder="<?php echo esc_attr( $s['report_token'] ? 'ذخیره شده — برای تغییر، توکن جدید را وارد کنید' : '123456:ABC-...' ); ?>">
							</div>
							<div class="sgia-field-group sgia-field-group--tight">
								<label class="sgia-field-group__label" for="sgia-rchat">شناسه چت (chat_id)</label>
								<input type="text" id="sgia-rchat" class="sgia-input" name="report_chat" value="<?php echo esc_attr( $s['report_chat'] ); ?>" dir="ltr" placeholder="123456789">
							</div>
						</div>
						<p class="sgia-help" data-sgia-msgr-help="bale">ربات را با <b>@botfather</b> در بله بسازید و یک پیام به ربات خودتان بفرستید. سپس آدرس <code dir="ltr">https://tapi.bale.ai/bot&lt;توکن&gt;/getUpdates</code> را در مرورگر باز کنید؛ عدد <code>chat.id</code> همان شناسه چت است. سرورهای داخل ایران به بله دسترسی دارند.</p>
						<div data-sgia-msgr-help="telegram">
							<p class="sgia-help">سرورهای داخل ایران معمولاً به API تلگرام دسترسی ندارند. اگر از پراکسی (مثلاً Cloudflare Worker) استفاده می‌کنید، آدرسش را وارد کنید.</p>
							<input type="url" class="sgia-input" name="report_api_base" value="<?php echo esc_attr( $s['report_api_base'] ); ?>" placeholder="https://api.telegram.org" dir="ltr">
						</div>
					</div>

					<?php self::switch_row( 'report_alerts', $s['report_alerts'], 'هشدار فوری', 'اگر ارسال صف به خاطر خطای دسترسی یا سهمیه متوقف شود، همان لحظه خبر می‌دهد (روزی یک بار).' ); ?>

					<div class="sgia-inline-actions sgia-inline-actions--start">
						<button type="button" class="sgia-btn sgia-btn--sm" data-sgia-report-test>ارسال پیام آزمایشی</button>
						<span class="sgia-help sgia-help--inline">ابتدا تنظیمات را ذخیره کنید.</span>
					</div>
				</section>

				<section class="sgia-card">
					<div class="sgia-card__head">
						<div>
							<h2>زمان‌بندی (WP-Cron)</h2>
							<p>صف، بررسی ایندکس و گزارش روزانه با WP-Cron اجرا می‌شوند.</p>
						</div>
						<?php $health = SGIA_Report::health(); ?>
						<span class="sgia-badge <?php echo $health ? 'sgia-badge--fail' : 'sgia-badge--ok'; ?>"><?php echo $health ? 'مشکل دارد' : 'سالم'; ?></span>
					</div>
					<div class="sgia-kv sgia-kv--top">
						<?php $seen = (int) get_option( SGIA_Report::OPT_SEEN, 0 ); ?>
						<div><dt>آخرین اجرا</dt><dd><?php echo esc_html( $seen ? self::ago( $seen ) : 'هنوز اجرا نشده' ); ?></dd></div>
						<div><dt>DISABLE_WP_CRON</dt><dd><?php echo defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'فعال (cron سرور لازم است)' : 'غیرفعال'; ?></dd></div>
					</div>
					<?php if ( $health ) : ?><p class="sgia-help sgia-help--warn"><?php echo esc_html( $health ); ?></p><?php endif; ?>
					<p class="sgia-help">برای اجرای دقیق‌تر، در هاست یک Cron Job هر ۱۵ دقیقه با این دستور بسازید:</p>
					<button type="button" class="sgia-copy-chip sgia-copy-chip--block" data-sgia-copy="<?php echo esc_attr( SGIA_Report::cron_command() ); ?>" dir="ltr"><?php echo self::icon( 'copy' ); // phpcs:ignore ?><span><?php echo esc_html( SGIA_Report::cron_command() ); ?></span></button>
				</section>

				<div class="sgia-savebar">
					<button type="submit" class="sgia-btn sgia-btn--primary sgia-btn--lg"><span data-label>ذخیره تنظیمات</span></button>
					<button type="button" class="sgia-btn sgia-btn--lg" data-sgia-test <?php disabled( ! $has ); ?>>تست اتصال</button>
				</div>
			</div>

			<aside class="sgia-card sgia-guide">
				<div class="sgia-card__head"><h2>راهنمای اتصال</h2></div>
				<ol class="sgia-guide__steps">
					<li>
						<strong>فعال‌سازی API</strong>
						<span>در Google Cloud یک پروژه بسازید و <em>Web Search Indexing API</em> را فعال کنید.</span>
						<a href="https://console.cloud.google.com/apis/library/indexing.googleapis.com" target="_blank" rel="noopener">باز کردن Google Cloud ←</a>
					</li>
					<li>
						<strong>ساخت سرویس اکانت</strong>
						<span>از IAM ← Service Accounts یک اکانت بسازید و از تب Keys یک کلید JSON دانلود کنید.</span>
						<a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Service Accounts ←</a>
					</li>
					<li>
						<strong>دسترسی در Search Console</strong>
						<span>در Settings ← Users and permissions ایمیل سرویس اکانت را با نقش <b>Owner</b> اضافه کنید.</span>
						<?php if ( $email ) : ?>
							<button type="button" class="sgia-copy-chip" data-sgia-copy="<?php echo esc_attr( $email ); ?>" dir="ltr"><?php echo self::icon( 'copy' ); // phpcs:ignore ?><span><?php echo esc_html( $email ); ?></span></button>
						<?php endif; ?>
						<a href="https://search.google.com/search-console/settings" target="_blank" rel="noopener">Search Console ←</a>
					</li>
					<li>
						<strong>بارگذاری و تست</strong>
						<span>فایل JSON را در همین صفحه بارگذاری کنید، ذخیره کنید و «تست اتصال» را بزنید.</span>
					</li>
				</ol>
			</aside>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Tab: log
	 * ------------------------------------------------------------------ */

	private static function tab_log() {
		$log   = SGIA_API::get_log();
		$state = array_count_values( array_map( [ __CLASS__, 'row_state' ], $log ) );
		$ok    = isset( $state['ok'] ) ? $state['ok'] : 0;
		$fail  = isset( $state['fail'] ) ? $state['fail'] : 0;
		$skip  = isset( $state['skip'] ) ? $state['skip'] : 0;
		$csv  = wp_nonce_url( admin_url( 'admin-post.php?action=sgia_export' ), 'sgia_export' );
		?>
		<section class="sgia-card sgia-log">
			<div class="sgia-toolbar">
				<div class="sgia-segment sgia-segment--sm" role="group" aria-label="فیلتر">
					<button type="button" class="is-active" data-sgia-filter="all">همه <b><?php echo esc_html( self::fa( count( $log ) ) ); ?></b></button>
					<button type="button" data-sgia-filter="ok">موفق <b><?php echo esc_html( self::fa( $ok ) ); ?></b></button>
					<button type="button" data-sgia-filter="fail">ناموفق <b><?php echo esc_html( self::fa( $fail ) ); ?></b></button>
					<?php if ( $skip ) : ?><button type="button" data-sgia-filter="skip">ردشده <b><?php echo esc_html( self::fa( $skip ) ); ?></b></button><?php endif; ?>
				</div>
				<div class="sgia-search">
					<span class="dashicons dashicons-search"></span>
					<input type="search" id="sgia-log-search" placeholder="جستجو در آدرس یا پیام…">
				</div>
				<div class="sgia-toolbar__end">
					<a class="sgia-btn sgia-btn--sm" href="<?php echo esc_url( $csv ); ?>" <?php echo $log ? '' : 'aria-disabled="true"'; ?>><span class="dashicons dashicons-download"></span>خروجی CSV</a>
					<button type="button" class="sgia-btn sgia-btn--sm sgia-btn--danger" data-sgia-clear-log <?php disabled( ! $log ); ?>>پاک کردن</button>
				</div>
			</div>

			<?php if ( ! $log ) : ?>
				<div class="sgia-empty">
					<span class="dashicons dashicons-list-view"></span>
					<p>هنوز گزارشی ثبت نشده.</p>
				</div>
			<?php else : ?>
				<div class="sgia-table-wrap">
					<table class="sgia-table">
						<thead>
							<tr><th>وضعیت</th><th>آدرس</th><th>نوع</th><th>منبع</th><th>زمان</th><th>پیام</th></tr>
						</thead>
						<tbody>
							<?php foreach ( $log as $row ) : ?>
								<?php
								$st     = self::row_state( $row );
								$labels = [ 'ok' => [ 'موفق', 'ok' ], 'fail' => [ 'ناموفق', 'fail' ], 'skip' => [ 'ردشده', 'warn' ] ];
								?>
								<tr data-ok="<?php echo esc_attr( 'ok' === $st ? '1' : ( 'fail' === $st ? '0' : 'skip' ) ); ?>">
									<td>
										<span class="sgia-badge sgia-badge--<?php echo esc_attr( $labels[ $st ][1] ); ?>">
											<?php echo esc_html( $labels[ $st ][0] ); ?><?php echo $row['code'] ? ' · ' . (int) $row['code'] : ''; ?>
										</span>
									</td>
									<?php if ( 'INDEXNOW' === $row['type'] ) : ?>
										<td class="sgia-table__url"><?php echo esc_html( self::fa( $row['url'] ) ); ?></td>
									<?php else : ?>
										<td class="sgia-table__url" dir="ltr"><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::display_url( $row['url'] ) ); ?></a></td>
									<?php endif; ?>
									<td><?php echo esc_html( self::type_label( $row['type'] ) ); ?></td>
									<td><?php echo esc_html( self::source_label( isset( $row['source'] ) ? $row['source'] : '' ) ); ?></td>
									<td title="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', $row['time'] ) ); ?>"><?php echo esc_html( self::ago( $row['time'] ) ); ?></td>
									<td class="sgia-table__msg">
										<?php if ( $row['ok'] ) : ?>
											<span class="sgia-muted">—</span>
										<?php else : ?>
											<span><?php echo esc_html( $row['msg'] ); ?></span>
											<?php if ( ! empty( $row['hint'] ) ) : ?>
												<small class="sgia-hint"><?php echo esc_html( $row['hint'] ); ?></small>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr class="sgia-table__none" hidden><td colspan="6">موردی با این فیلتر پیدا نشد.</td></tr>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Posts list: column, row action, bulk actions
	 * ------------------------------------------------------------------ */

	public static function register_list_hooks() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		foreach ( SGIA_API::settings()['types'] as $type ) {
			add_filter( "bulk_actions-edit-{$type}", [ __CLASS__, 'bulk_label' ] );
			add_filter( "handle_bulk_actions-edit-{$type}", [ __CLASS__, 'bulk_handle' ], 10, 3 );
			add_filter( "manage_{$type}_posts_columns", [ __CLASS__, 'column_head' ] );
			add_action( "manage_{$type}_posts_custom_column", [ __CLASS__, 'column_body' ], 10, 2 );
		}
		add_filter( 'post_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
	}

	public static function column_head( $cols ) {
		$cols['sgia'] = '<span class="dashicons dashicons-search" title="Indexing API"></span><span class="screen-reader-text">Indexing API</span>';
		return $cols;
	}

	public static function column_body( $col, $post_id ) {
		if ( 'sgia' !== $col ) {
			return;
		}
		$last = get_post_meta( $post_id, SGIA_API::META_LAST, true );
		if ( ! is_array( $last ) ) {
			echo '<span class="sgia-dot is-none" title="ارسال نشده"></span>';
			return;
		}
		$title = ( $last['ok'] ? 'موفق' : 'ناموفق: ' . $last['msg'] ) . ' — ' . self::type_label( $last['type'] ) . ' — ' . self::ago( $last['time'] );
		printf( '<span class="sgia-dot %s" title="%s" data-sgia-dot="%d"></span>', $last['ok'] ? 'is-ok' : 'is-fail', esc_attr( $title ), (int) $post_id );
	}

	public static function row_action( $actions, $post ) {
		if ( current_user_can( self::CAP ) && SGIA_Auto::is_enabled_post( $post ) && 'publish' === $post->post_status ) {
			$actions['sgia'] = '<a href="#" data-sgia-row="' . (int) $post->ID . '">درخواست ایندکس</a>';
		}
		return $actions;
	}

	public static function bulk_label( $actions ) {
		$actions['sgia_bulk_update'] = 'Indexing API: درخواست ایندکس';
		$actions['sgia_bulk_delete'] = 'Indexing API: اعلام حذف';
		return $actions;
	}

	public static function bulk_handle( $redirect, $action, $ids ) {
		if ( ! in_array( $action, [ 'sgia_bulk_update', 'sgia_bulk_delete' ], true ) || ! current_user_can( self::CAP ) ) {
			return $redirect;
		}
		$type = ( 'sgia_bulk_delete' === $action ) ? 'URL_DELETED' : 'URL_UPDATED';
		$ok   = 0;
		$fail = 0;
		$last = '';

		foreach ( array_slice( (array) $ids, 0, 100 ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$saved = get_post_meta( $id, SGIA_API::META_URL, true );
			$url   = ( 'URL_DELETED' === $type && $saved ) ? $saved : get_permalink( $post );
			$res   = SGIA_API::publish( $url, $type, 'bulk', $id );

			if ( SGIA_API::is_ok( $res ) ) {
				$ok++;
				if ( 'URL_UPDATED' === $type ) {
					update_post_meta( $id, SGIA_API::META_URL, get_permalink( $post ) );
				}
			} else {
				$fail++;
				$last = SGIA_API::error_text( $res ) . ( SGIA_API::hint( $res ) ? ' — ' . SGIA_API::hint( $res ) : '' );
			}
		}

		$msg = sprintf( 'Indexing API — موفق: <strong>%s</strong>، ناموفق: <strong>%s</strong>', self::fa( $ok ), self::fa( $fail ) );
		if ( $last ) {
			$msg .= '<br>آخرین خطا: ' . esc_html( $last );
		}
		set_transient( 'sgia_notice_' . get_current_user_id(), [ $fail ? 'warning' : 'success', $msg ], 120 );
		return $redirect;
	}

	public static function notices() {
		$key    = 'sgia_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice[0] ),
				wp_kses( $notice[1], [ 'br' => [], 'strong' => [] ] )
			);
		}

		if ( get_transient( 'sgia_welcome' ) && current_user_can( self::CAP ) ) {
			$screen = get_current_screen();
			if ( $screen && 'plugins' === $screen->base ) {
				delete_transient( 'sgia_welcome' );
				printf(
					'<div class="notice notice-info is-dismissible"><p><strong>Google Indexing API</strong> فعال شد. <a href="%s">راه‌اندازی را شروع کنید ←</a></p></div>',
					esc_url( self::page_url( 'settings' ) )
				);
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Edit screen meta box
	 * ------------------------------------------------------------------ */

	public static function add_meta_box() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		foreach ( SGIA_API::settings()['types'] as $type ) {
			add_meta_box( 'sgia-box', 'Google Indexing API', [ __CLASS__, 'render_meta_box' ], $type, 'side', 'default' );
		}
	}

	public static function render_meta_box( $post ) {
		$last      = get_post_meta( $post->ID, SGIA_API::META_LAST, true );
		$published = 'publish' === $post->post_status;
		?>
		<div class="sgia-mb" data-post="<?php echo (int) $post->ID; ?>">
			<div class="sgia-mb__status" data-sgia-mb-status>
				<?php if ( is_array( $last ) ) : ?>
					<span class="sgia-dot <?php echo $last['ok'] ? 'is-ok' : 'is-fail'; ?>"></span>
					<span>
						<strong><?php echo $last['ok'] ? 'ارسال موفق' : 'ارسال ناموفق'; ?></strong>
						<small><?php echo esc_html( self::type_label( $last['type'] ) . ' · ' . self::ago( $last['time'] ) ); ?></small>
						<?php if ( ! $last['ok'] ) : ?><small class="sgia-hint"><?php echo esc_html( $last['msg'] ); ?></small><?php endif; ?>
					</span>
				<?php else : ?>
					<span class="sgia-dot is-none"></span><span><small>هنوز برای این صفحه درخواستی ارسال نشده.</small></span>
				<?php endif; ?>
			</div>
			<?php if ( ! SGIA_API::has_key() ) : ?>
				<p class="sgia-mb__note">ابتدا <a href="<?php echo esc_url( self::page_url( 'settings' ) ); ?>">کلید سرویس اکانت</a> را تنظیم کنید.</p>
			<?php elseif ( $published ) : ?>
				<div class="sgia-mb__actions">
					<button type="button" class="button button-primary" data-sgia-mb="update">درخواست ایندکس</button>
					<button type="button" class="button" data-sgia-mb="status">وضعیت ایندکس</button>
				</div>
				<div class="sgia-mb__out" data-sgia-mb-out aria-live="polite"></div>
			<?php else : ?>
				<p class="sgia-mb__note">پس از انتشار، درخواست ایندکس فعال می‌شود.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * AJAX
	 * ------------------------------------------------------------------ */

	private static function guard_ajax() {
		check_ajax_referer( 'sgia_ajax', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'ok' => false, 'msg' => 'دسترسی ندارید.' ], 403 );
		}
	}

	private static function status_payload( $res ) {
		$p = SGIA_API::payload( $res );
		if ( $p['ok'] ) {
			$b         = is_array( $res['body'] ) ? $res['body'] : [];
			$p['meta'] = [
				'updated' => isset( $b['latestUpdate']['notifyTime'] ) ? $b['latestUpdate']['notifyTime'] : null,
				'removed' => isset( $b['latestRemove']['notifyTime'] ) ? $b['latestRemove']['notifyTime'] : null,
			];
		}
		return $p;
	}

	public static function ajax_submit() {
		self::guard_ajax();
		$url  = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : ''; // phpcs:ignore
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'update';

		if ( 'status' === $type ) {
			wp_send_json_success( self::status_payload( SGIA_API::metadata( $url ) ) );
		}
		if ( 'inspect' === $type ) {
			wp_send_json_success( self::inspect_payload( SGIA_Inspect::inspect( $url, true ) ) );
		}

		$api_type = ( 'delete' === $type ) ? 'URL_DELETED' : 'URL_UPDATED';
		$post_id  = url_to_postid( $url );
		$res      = SGIA_API::publish( $url, $api_type, 'manual', $post_id );

		if ( $post_id && 'URL_UPDATED' === $api_type && SGIA_API::is_ok( $res ) && get_permalink( $post_id ) ) {
			update_post_meta( $post_id, SGIA_API::META_URL, get_permalink( $post_id ) );
		}

		$p          = SGIA_API::payload( $res );
		$p['quota'] = SGIA_API::quota();
		wp_send_json_success( $p );
	}

	public static function ajax_post_send() {
		self::guard_ajax();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'update';
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => 'این نوشته منتشر نشده است.', 'hint' => '' ] );
		}
		$url = get_permalink( $post );

		if ( 'status' === $type ) {
			$ins = SGIA_Inspect::inspect( $url, true );
			if ( is_wp_error( $ins ) && 'sgia_no_property' !== $ins->get_error_code() && 'sgia_inspect' !== $ins->get_error_code() ) {
				wp_send_json_success( self::status_payload( SGIA_API::metadata( $url ) ) );
			}
			wp_send_json_success( self::inspect_payload( $ins ) );
		}

		$res = SGIA_API::publish( $url, 'URL_UPDATED', 'post', $post_id );
		if ( SGIA_API::is_ok( $res ) ) {
			update_post_meta( $post_id, SGIA_API::META_URL, $url );
		}
		$p          = SGIA_API::payload( $res );
		$p['quota'] = SGIA_API::quota();
		wp_send_json_success( $p );
	}

	private static function inspect_payload( $ins ) {
		if ( is_wp_error( $ins ) ) {
			$d = $ins->get_error_data();
			return [ 'ok' => false, 'msg' => $ins->get_error_message(), 'hint' => is_string( $d ) ? $d : '' ];
		}
		return [
			'ok'      => true,
			'inspect' => [
				'indexed'    => $ins['indexed'],
				'coverage'   => $ins['coverage_fa'],
				'last_crawl' => $ins['last_crawl'],
				'link'       => $ins['link'],
			],
		];
	}

	public static function ajax_gsc_detect() {
		self::guard_ajax();
		delete_transient( SGIA_Inspect::PROP_CACHE );
		delete_transient( SGIA_Inspect::PROP_FAIL );
		$p = SGIA_Inspect::detect();
		if ( is_wp_error( $p ) ) {
			$d = $p->get_error_data();
			wp_send_json_success( [ 'ok' => false, 'msg' => $p->get_error_message(), 'hint' => is_string( $d ) ? $d : '' ] );
		}
		delete_option( SGIA_Inspect::OPT_HALT );
		wp_send_json_success( [ 'ok' => true, 'property' => $p, 'msg' => 'Property پیدا شد: ' . $p, 'hint' => 'برای ثبت، «ذخیره تنظیمات» را بزنید.' ] );
	}

	public static function ajax_report_test() {
		self::guard_ajax();
		if ( ! SGIA_Report::enabled() ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => 'ایمیل یا پیام‌رسان فعال نیست؛ ابتدا تنظیمات را ذخیره کنید.' ] );
		}
		$r    = SGIA_Report::send( "✅ پیام آزمایشی Indexing API\n\n" . SGIA_Report::build( SGIA_API::pacific_day() ), 'پیام آزمایشی Indexing API' );
		$ok   = [];
		$fail = [];
		foreach ( [ 'email' => 'ایمیل', 'msgr' => 'پیام‌رسان' ] as $k => $label ) {
			if ( true === $r[ $k ] ) {
				$ok[] = $label;
			} elseif ( is_wp_error( $r[ $k ] ) ) {
				$fail[] = $r[ $k ]->get_error_message();
			}
		}
		wp_send_json_success( [
			'ok'   => ! $fail,
			'msg'  => $fail ? implode( ' — ', $fail ) : 'پیام آزمایشی ارسال شد (' . implode( ' و ', $ok ) . ').',
			'hint' => $fail && $ok ? 'ارسال‌شده: ' . implode( ' و ', $ok ) : '',
		] );
	}

	public static function ajax_indexnow_test() {
		self::guard_ajax();
		$check = SGIA_IndexNow::verify_key();
		if ( is_wp_error( $check ) ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => $check->get_error_message() ] );
		}
		$r = SGIA_IndexNow::submit( [ home_url( '/' ) ], 'manual' );
		wp_send_json_success( [ 'ok' => $r['ok'], 'msg' => $r['ok'] ? 'فایل کلید در دسترس است و IndexNow درخواست را پذیرفت.' : $r['msg'], 'hint' => $r['hint'] ] );
	}

	public static function ajax_indexnow_bulk() {
		self::guard_ajax();
		if ( ! SGIA_IndexNow::enabled() ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => 'IndexNow فعال نیست.' ] );
		}
		$s = SGIA_IndexNow::bulk_start();
		spawn_cron();
		wp_send_json_success( [ 'ok' => true, 'msg' => 'ارسال ' . self::fa( number_format( $s['total'] ) ) . ' آدرس در پس‌زمینه شروع شد.', 'state' => self::bulk_payload( $s ) ] );
	}

	public static function ajax_indexnow_status() {
		self::guard_ajax();
		$s = SGIA_IndexNow::bulk_state();
		if ( $s && ! $s['done'] ) {
			spawn_cron(); // keep the chain moving even on low-traffic sites
		}
		wp_send_json_success( [ 'ok' => true, 'state' => $s ? self::bulk_payload( $s ) : null ] );
	}

	private static function bulk_payload( $s ) {
		return [
			'done'  => (bool) $s['done'],
			'error' => $s['error'],
			'text'  => $s['done']
				? ( $s['error'] ? 'متوقف شد: ' . $s['error'] : 'تمام شد: ' . self::fa( number_format( $s['sent'] ) ) . ' آدرس ارسال شد.' )
				: 'در حال ارسال… ' . self::fa( number_format( $s['sent'] ) ) . ' از ' . self::fa( number_format( $s['total'] ) ),
		];
	}

	public static function ajax_indexnow_submit() {
		self::guard_ajax();
		$urls = SGIA_Queue::extract_urls( isset( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : '' ); // phpcs:ignore
		$r    = SGIA_IndexNow::submit( $urls, 'manual' );
		wp_send_json_success( [ 'ok' => $r['ok'], 'sent' => $r['sent'], 'msg' => self::fa( $r['msg'] ), 'hint' => $r['hint'] ] );
	}

	public static function ajax_test() {
		self::guard_ajax();
		$token = SGIA_API::access_token( true );
		if ( is_wp_error( $token ) ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => $token->get_error_message(), 'hint' => SGIA_API::hint( $token ) ] );
		}
		wp_send_json_success( [ 'ok' => true, 'msg' => 'اتصال به گوگل برقرار است و توکن دریافت شد.', 'hint' => 'برای تأیید نهایی دسترسی Search Console، یک آدرس از تب «ارسال» بفرستید.' ] );
	}

	public static function ajax_save_settings() {
		self::guard_ajax();
		$reload = false;

		$json = isset( $_POST['key_json'] ) ? trim( (string) wp_unslash( $_POST['key_json'] ) ) : ''; // phpcs:ignore
		if ( '' !== $json && ! SGIA_API::key_from_file() ) {
			$saved = SGIA_API::save_key( $json );
			if ( is_wp_error( $saved ) ) {
				wp_send_json_success( [ 'ok' => false, 'msg' => $saved->get_error_message(), 'hint' => '' ] );
			}
			$reload = true;
		}

		$types   = isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : []; // phpcs:ignore
		$allowed = get_post_types( [ 'public' => true ] );
		$old     = SGIA_API::settings();

		// phpcs:disable WordPress.Security.NonceVerification -- guard_ajax() checked the nonce.
		$bool = function ( $k ) {
			return empty( $_POST[ $k ] ) ? 0 : 1;
		};
		$text = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		};
		$base = esc_url_raw( $text( 'report_api_base' ) );

		SGIA_API::update_settings( [
			'auto'            => $bool( 'auto' ),
			'types'           => array_values( array_intersect( $types, $allowed ) ),
			'daily_limit'     => isset( $_POST['daily_limit'] ) ? max( 1, absint( $_POST['daily_limit'] ) ) : 200,
			'gsc_property'    => $text( 'gsc_property' ),
			'inspect_before'  => $bool( 'inspect_before' ),
			'recheck_days'    => isset( $_POST['recheck_days'] ) ? absint( $_POST['recheck_days'] ) : 3,
			'inspect_limit'   => isset( $_POST['inspect_limit'] ) ? absint( $_POST['inspect_limit'] ) : 1500,
			'precheck'        => $bool( 'precheck' ),
			'precheck_http'   => $bool( 'precheck_http' ),
			'indexnow'        => $bool( 'indexnow' ),
			'indexnow_auto'   => $bool( 'indexnow_auto' ),
			'indexnow_queue'  => $bool( 'indexnow_queue' ),
			'woo_priority'    => $bool( 'woo_priority' ),
			'woo_skip_oos'    => $bool( 'woo_skip_oos' ),
			'report_email'    => $bool( 'report_email' ),
			'report_email_to' => sanitize_email( $text( 'report_email_to' ) ),
			'report_msgr'     => $text( 'report_msgr' ),
			'report_token'    => '' !== $text( 'report_token' ) ? preg_replace( '/[^A-Za-z0-9:_\-]/', '', $text( 'report_token' ) ) : $old['report_token'], // empty field = keep
			'report_chat'     => preg_replace( '/[^0-9@A-Za-z_\-]/', '', $text( 'report_chat' ) ),
			'report_api_base' => $base && 0 === strpos( $base, 'https://' ) ? untrailingslashit( $base ) : '',
			'report_alerts'   => $bool( 'report_alerts' ),
		] );
		// phpcs:enable
		$new = SGIA_API::settings();
		$msg = '';

		if ( $new['indexnow'] && ! $old['indexnow'] ) {
			SGIA_IndexNow::ensure_key();
			$check = SGIA_IndexNow::verify_key();
			$msg   = is_wp_error( $check ) ? $check->get_error_message() : '';
			$reload = true;
		}
		if ( ! $new['indexnow'] && $old['indexnow'] ) {
			SGIA_IndexNow::delete_key_file();
		}
		if ( $old['gsc_property'] !== $new['gsc_property'] ) {
			delete_transient( SGIA_Inspect::PROP_CACHE );
			delete_transient( SGIA_Inspect::PROP_FAIL );
			delete_option( SGIA_Inspect::OPT_HALT );
		}
		foreach ( [ 'types', 'daily_limit', 'indexnow', 'report_msgr', 'woo_priority' ] as $k ) {
			if ( $old[ $k ] !== $new[ $k ] ) {
				$reload = true;
			}
		}
		if ( $old['woo_priority'] !== $new['woo_priority'] || $old['woo_skip_oos'] !== $new['woo_skip_oos'] ) {
			// Re-rank pending rows with the new rules.
			global $wpdb;
			$wpdb->query( 'UPDATE ' . SGIA_Queue::table() . " SET enriched = 0 WHERE status = 'pending'" ); // phpcs:ignore
		}

		wp_send_json_success( [
			'ok'     => true,
			'msg'    => $reload && '' !== $json ? 'کلید ذخیره شد. حالا «تست اتصال» را بزنید.' : 'تنظیمات ذخیره شد.',
			'hint'   => $msg,
			'reload' => $reload,
		] );
	}

	public static function ajax_delete_key() {
		self::guard_ajax();
		SGIA_API::delete_key();
		wp_send_json_success( [ 'ok' => true, 'msg' => 'کلید حذف شد.' ] );
	}

	public static function ajax_recent() {
		self::guard_ajax();
		$mode  = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'recent';
		$types = SGIA_API::settings()['types'];

		if ( ! $types ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => 'در تنظیمات هیچ نوع محتوایی انتخاب نشده.', 'urls' => [] ] );
		}

		$args = [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 'unsent' === $mode ? 100 : 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( 'unsent' === $mode ) {
			$args['meta_query'] = [ [ 'key' => SGIA_API::META_URL, 'compare' => 'NOT EXISTS' ] ]; // phpcs:ignore
		}

		$urls = array_values( array_filter( array_map( 'get_permalink', get_posts( $args ) ) ) );
		wp_send_json_success( [ 'ok' => true, 'urls' => $urls ] );
	}

	public static function ajax_clear_log() {
		self::guard_ajax();
		SGIA_API::clear_log();
		wp_send_json_success( [ 'ok' => true, 'msg' => 'گزارش‌ها پاک شد.' ] );
	}

	/* ------------------------------------------------------------------ *
	 * AJAX: queue
	 * ------------------------------------------------------------------ */

	public static function ajax_queue_import() {
		self::guard_ajax();

		if ( ! empty( $_FILES['file']['tmp_name'] ) ) {
			$f = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
				wp_send_json_success( [ 'ok' => false, 'msg' => 'بارگذاری فایل ناموفق بود.', 'hint' => 'حجم فایل را بررسی کنید (حداکثر ۱۰ مگابایت).' ] );
			}
			$urls = SGIA_Queue::read_file( $f['tmp_name'], sanitize_file_name( $f['name'] ) );
		} else {
			$urls = SGIA_Queue::extract_urls( isset( $_POST['text'] ) ? wp_unslash( $_POST['text'] ) : '' ); // phpcs:ignore
		}

		if ( is_wp_error( $urls ) ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => $urls->get_error_message(), 'hint' => '' ] );
		}
		if ( ! $urls ) {
			wp_send_json_success( [ 'ok' => false, 'msg' => 'هیچ آدرسی در فایل پیدا نشد.', 'hint' => 'آدرس‌ها باید کامل و با http:// یا https:// شروع شوند.' ] );
		}

		$r     = SGIA_Queue::import( $urls );
		$parts = [];
		if ( $r['duplicate'] ) {
			$parts[] = self::fa( number_format( $r['duplicate'] ) ) . ' تکراری';
		}
		if ( $r['foreign'] ) {
			$parts[] = self::fa( number_format( $r['foreign'] ) ) . ' مربوط به دامنه دیگر (' . $r['foreign_host'] . ')';
		}
		if ( $r['invalid'] ) {
			$parts[] = self::fa( number_format( $r['invalid'] ) ) . ' نامعتبر';
		}
		$hint = $parts ? 'کنار گذاشته شد: ' . implode( '، ', $parts ) : '';
		if ( $r['truncated'] ) {
			$hint .= ( $hint ? ' — ' : '' ) . 'فقط ' . self::fa( number_format( SGIA_Queue::MAX_IMPORT ) ) . ' آدرس اول فایل خوانده شد.';
		}

		if ( ! empty( $r['indexnow'] ) ) {
			$hint .= ( $hint ? ' — ' : '' ) . self::fa( number_format( $r['indexnow'] ) ) . ' آدرس به IndexNow هم ارسال شد.';
		}
		$msg = $r['added']
			? self::fa( number_format( $r['added'] ) ) . ' آدرس به صف اضافه شد.'
			: ( $r['duplicate'] ? 'همه آدرس‌ها از قبل در صف بودند.' : 'آدرس قابل قبولی برای این سایت پیدا نشد.' );

		wp_send_json_success( [ 'ok' => $r['added'] > 0 || $r['duplicate'] > 0, 'added' => $r['added'], 'msg' => $msg, 'hint' => $hint ] );
	}

	public static function ajax_queue_save() {
		self::guard_ajax();
		SGIA_Queue::update_settings( ! empty( $_POST['active'] ), isset( $_POST['per_day'] ) ? absint( $_POST['per_day'] ) : 180 );
		$s     = SGIA_Queue::settings();
		$limit = SGIA_API::settings()['daily_limit'];
		$hint  = $s['per_day'] > $limit ? 'سقف صف از سقف کل روزانه (' . self::fa( $limit ) . ') بیشتر است؛ عملاً ' . self::fa( $limit ) . ' در نظر گرفته می‌شود.' : '';
		wp_send_json_success( [ 'ok' => true, 'msg' => 'زمان‌بندی ذخیره شد.', 'hint' => $hint ] );
	}

	public static function ajax_queue_run() {
		self::guard_ajax();
		$r = SGIA_Queue::run( true );
		wp_send_json_success( [
			'ok'    => $r['ok'],
			'sent'  => $r['sent'],
			'msg'   => strtr( $r['msg'], [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ] ),
			'quota' => SGIA_API::quota(),
		] );
	}

	public static function ajax_queue_action() {
		self::guard_ajax();
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'retry':
				$n   = SGIA_Queue::retry_failed();
				$msg = self::fa( $n ) . ' آدرس دوباره به صف برگشت.';
				break;
			case 'retry_skipped':
				$n   = SGIA_Queue::retry_skipped();
				$msg = self::fa( number_format( $n ) ) . ' آدرس به صف برگشت.';
				break;
			case 'clear_sent':
				$n   = SGIA_Queue::clear( 'sent' );
				$msg = self::fa( number_format( $n ) ) . ' آدرس ارسال‌شده پاک شد.';
				break;
			case 'clear_all':
				$n   = SGIA_Queue::clear();
				$msg = 'صف خالی شد.';
				break;
			case 'delete':
				$n   = SGIA_Queue::delete_row( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
				$msg = $n ? 'از صف حذف شد.' : 'این مورد پیدا نشد.';
				break;
			default:
				wp_send_json_success( [ 'ok' => false, 'msg' => 'عملیات نامعتبر.' ] );
		}
		wp_send_json_success( [ 'ok' => true, 'msg' => $msg, 'reload' => 'delete' !== $op ] );
	}

	/* ------------------------------------------------------------------ *
	 * CSV export
	 * ------------------------------------------------------------------ */

	public static function export_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'sgia_export' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=indexing-api-log-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore -- BOM so Excel reads UTF-8
		fputcsv( $out, [ 'time', 'url', 'type', 'source', 'http_code', 'ok', 'message', 'hint' ] );
		foreach ( SGIA_API::get_log() as $r ) {
			fputcsv( $out, [
				wp_date( 'Y-m-d H:i:s', $r['time'] ),
				$r['url'],
				$r['type'],
				isset( $r['source'] ) ? $r['source'] : '',
				$r['code'],
				$r['ok'] ? 1 : 0,
				$r['msg'],
				isset( $r['hint'] ) ? $r['hint'] : '',
			] );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}
}
