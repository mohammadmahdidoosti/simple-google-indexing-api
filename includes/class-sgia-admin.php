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

		foreach ( [ 'submit', 'test', 'save_settings', 'delete_key', 'recent', 'clear_log', 'post_send' ] as $a ) {
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
		$h    = floor( $diff / HOUR_IN_SECONDS );
		$m    = floor( ( $diff % HOUR_IN_SECONDS ) / 60 );
		return ( $h ? self::fa( $h ) . ' ساعت و ' : '' ) . self::fa( $m ) . ' دقیقه';
	}

	private static function type_label( $type ) {
		return 'URL_DELETED' === $type ? 'حذف' : 'به‌روزرسانی';
	}

	private static function source_label( $source ) {
		$map = [ 'auto' => 'خودکار', 'manual' => 'دستی', 'bulk' => 'گروهی', 'post' => 'ویرایشگر' ];
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
			'settings'  => [ 'تنظیمات', 'dashicons-admin-generic' ],
			'log'       => [ 'گزارش‌ها', 'dashicons-list-view' ],
		];
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}
		$q = SGIA_API::quota();
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
		$rate     = SGIA_API::recent_rate();
		$q        = SGIA_API::quota();
		$s        = SGIA_API::settings();
		$recent   = array_slice( SGIA_API::get_log(), 0, 6 );

		$steps = [
			[ $has, 'بارگذاری کلید سرویس اکانت', 'فایل JSON را از Google Cloud دانلود و در تنظیمات بارگذاری کنید.' ],
			[ $verified > 0, 'تأیید اتصال به گوگل', 'دکمه «تست اتصال» را بزنید تا توکن دریافت شود.' ],
			[ $stats['ok'] > 0, 'اولین ارسال موفق', 'ایمیل سرویس اکانت را در Search Console با نقش Owner اضافه کنید و یک آدرس بفرستید.' ],
		];
		$done = count( array_filter( wp_list_pluck( $steps, 0 ) ) );

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

		<div class="sgia-stats">
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">مصرف سهمیه امروز</span>
				<strong class="sgia-stat__value"><span data-q-used><?php echo esc_html( self::fa( $q['used'] ) ); ?></span><small> / <?php echo esc_html( self::fa( $q['limit'] ) ); ?></small></strong>
				<span class="sgia-meter"><i data-q-bar style="width:<?php echo (int) $q['percent']; ?>%"></i></span>
				<span class="sgia-stat__sub">ریست در <?php echo esc_html( self::until( $q['reset_at'] ) ); ?> دیگر</span>
			</section>
			<section class="sgia-card sgia-stat">
				<span class="sgia-stat__label">نرخ موفقیت ۲۴ ساعت</span>
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
								<span class="sgia-dot <?php echo $row['ok'] ? 'is-ok' : 'is-fail'; ?>"></span>
								<div class="sgia-activity__main">
									<span class="sgia-activity__url" dir="ltr"><?php echo esc_html( self::display_url( $row['url'] ) ); ?></span>
									<span class="sgia-activity__meta">
										<?php echo esc_html( self::type_label( $row['type'] ) . ' · ' . self::source_label( isset( $row['source'] ) ? $row['source'] : '' ) ); ?>
										<?php if ( ! $row['ok'] ) : ?> · <em><?php echo esc_html( $row['msg'] ); ?></em><?php endif; ?>
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
					<p>هر خط یک آدرس کامل. آدرس‌های تکراری و نامعتبر خودکار کنار گذاشته می‌شوند.</p>
				</div>
			</div>

			<div class="sgia-segment" role="radiogroup" aria-label="نوع درخواست">
				<label><input type="radio" name="sgia-type" value="update" checked><span><i class="dashicons dashicons-update"></i>اعلام انتشار / به‌روزرسانی</span></label>
				<label><input type="radio" name="sgia-type" value="delete"><span><i class="dashicons dashicons-trash"></i>اعلام حذف</span></label>
				<label><input type="radio" name="sgia-type" value="status"><span><i class="dashicons dashicons-visibility"></i>بررسی وضعیت</span></label>
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
	 * Tab: settings
	 * ------------------------------------------------------------------ */

	private static function tab_settings() {
		$cred      = SGIA_API::credentials();
		$has       = ! is_wp_error( $cred );
		$from_file = SGIA_API::key_from_file();
		$s         = SGIA_API::settings();
		$verified  = (int) get_option( SGIA_API::OPT_VERIFIED, 0 );
		$email     = $has ? $cred['client_email'] : '';
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
		$log  = SGIA_API::get_log();
		$ok   = count( array_filter( wp_list_pluck( $log, 'ok' ) ) );
		$fail = count( $log ) - $ok;
		$csv  = wp_nonce_url( admin_url( 'admin-post.php?action=sgia_export' ), 'sgia_export' );
		?>
		<section class="sgia-card sgia-log">
			<div class="sgia-toolbar">
				<div class="sgia-segment sgia-segment--sm" role="group" aria-label="فیلتر">
					<button type="button" class="is-active" data-sgia-filter="all">همه <b><?php echo esc_html( self::fa( count( $log ) ) ); ?></b></button>
					<button type="button" data-sgia-filter="ok">موفق <b><?php echo esc_html( self::fa( $ok ) ); ?></b></button>
					<button type="button" data-sgia-filter="fail">ناموفق <b><?php echo esc_html( self::fa( $fail ) ); ?></b></button>
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
								<tr data-ok="<?php echo $row['ok'] ? '1' : '0'; ?>">
									<td>
										<span class="sgia-badge <?php echo $row['ok'] ? 'sgia-badge--ok' : 'sgia-badge--fail'; ?>">
											<?php echo $row['ok'] ? 'موفق' : 'ناموفق'; ?><?php echo $row['code'] ? ' · ' . (int) $row['code'] : ''; ?>
										</span>
									</td>
									<td class="sgia-table__url" dir="ltr"><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( self::display_url( $row['url'] ) ); ?></a></td>
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
					update_post_meta( $id, SGIA_API::META_URL, $url );
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
					<button type="button" class="button" data-sgia-mb="status">بررسی وضعیت</button>
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

		$api_type = ( 'delete' === $type ) ? 'URL_DELETED' : 'URL_UPDATED';
		$post_id  = url_to_postid( $url );
		$res      = SGIA_API::publish( $url, $api_type, 'manual', $post_id );

		if ( $post_id && 'URL_UPDATED' === $api_type && SGIA_API::is_ok( $res ) ) {
			update_post_meta( $post_id, SGIA_API::META_URL, $url );
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
			wp_send_json_success( self::status_payload( SGIA_API::metadata( $url ) ) );
		}

		$res = SGIA_API::publish( $url, 'URL_UPDATED', 'post', $post_id );
		if ( SGIA_API::is_ok( $res ) ) {
			update_post_meta( $post_id, SGIA_API::META_URL, $url );
		}
		$p          = SGIA_API::payload( $res );
		$p['quota'] = SGIA_API::quota();
		wp_send_json_success( $p );
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

		SGIA_API::update_settings( [
			'auto'        => empty( $_POST['auto'] ) ? 0 : 1,
			'types'       => array_values( array_intersect( $types, $allowed ) ),
			'daily_limit' => isset( $_POST['daily_limit'] ) ? max( 1, absint( $_POST['daily_limit'] ) ) : 200,
		] );

		if ( $old['types'] !== SGIA_API::settings()['types'] || $old['daily_limit'] !== SGIA_API::settings()['daily_limit'] ) {
			$reload = true;
		}

		wp_send_json_success( [
			'ok'     => true,
			'msg'    => $reload && '' !== $json ? 'کلید ذخیره شد. حالا «تست اتصال» را بزنید.' : 'تنظیمات ذخیره شد.',
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
