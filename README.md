# Simple Google Indexing API

افزونه وردپرس برای ارسال خودکار و دستی درخواست ایندکس (`URL_UPDATED` / `URL_DELETED`) به [Google Indexing API](https://developers.google.com/search/apis/indexing-api/v3/quickstart)، با داشبورد، ارسال گروهی، کنترل سهمیه و گزارش کامل. رابط کاربری فارسی و راست‌چین است.

## امکانات

- **ارسال خودکار:** هنگام انتشار/ویرایش ← `URL_UPDATED`؛ هنگام حذف، لغو انتشار یا تغییر نامک ← `URL_DELETED` برای آدرس قدیمی
- **صف ارسال:** فایل اکسل/CSV صفحات ایندکس‌نشده (مثلاً خروجی Search Console) را بارگذاری کنید؛ آدرس‌ها ذخیره و هر روز تا سقف تعیین‌شده خودکار ارسال می‌شوند. اگر گوگل خطای 429 یا دسترسی بدهد، ارسال تا روز بعد متوقف می‌شود و چیزی از صف کم نمی‌شود.
- **بررسی ایندکس (Search Console):** با URL Inspection API قبل از ارسال چک می‌شود صفحه از قبل ایندکس شده یا نه، و چند روز بعد از ارسال دوباره بررسی می‌شود تا نرخ واقعی ایندکس معلوم شود.
- **بررسی پیش از ارسال:** صفحه‌های noindex (Rank Math / Yoast / meta robots / X-Robots-Tag)، canonical به آدرس دیگر، ریدایرکت و ۴۰۴ ارسال نمی‌شوند. پاسخ‌های مبهم (۴۰۳/۵۰۳ از فایروال یا CDN) آدرس را رد نمی‌کنند.
- **IndexNow:** ارسال خودکار به Bing، Yandex و ... هنگام انتشار/ویرایش/حذف، فهرست‌های صف و همه صفحات منتشرشده (در پس‌زمینه).
- **ووکامرس:** محصولات تازه و پرفروش زودتر از صف ارسال می‌شوند؛ ناموجودها رد می‌شوند.
- **گزارش روزانه:** ایمیل، پیام‌رسان بله یا تلگرام، به‌علاوه هشدار فوری هنگام توقف ارسال.
- **سلامت WP-Cron:** اگر زمان‌بندی وردپرس اجرا نشود هشدار می‌دهد و دستور Cron Job هاست را آماده می‌کند.
- **داشبورد:** مصرف سهمیه امروز، نرخ موفقیت ۲۴ ساعت، فعالیت‌های اخیر، چک‌لیست راه‌اندازی
- **ارسال گروهی:** تا صدها آدرس با نوار پیشرفت، توقف، و نتیجه جداگانه برای هر آدرس؛ بررسی وضعیت (`urlNotifications.metadata`)
- **تنظیمات:** بارگذاری کلید سرویس اکانت با کشیدن و رها کردن و اعتبارسنجی فوری، انتخاب نوع محتوا، سقف روزانه
- **گزارش‌ها:** فیلتر، جستجو، خروجی CSV و راهنمای فارسی رفع خطا (403، 429، `invalid_grant` و ...)
- **داخل وردپرس:** متاباکس در ویرایشگر، ستون وضعیت و دکمه «درخواست ایندکس» در لیست نوشته‌ها، Bulk Action
- پشتیبانی کامل از نامک‌های فارسی و دامنه‌های IDN
- بدون وابستگی خارجی (امضای JWT با OpenSSL خود PHP)

## نصب

1. از بخش [Releases](../../releases) فایل `simple-google-indexing-api.zip` را دانلود کنید.
2. وردپرس ← افزونه‌ها ← افزودن ← بارگذاری افزونه ← فعال‌سازی.

## راه‌اندازی گوگل

1. در Google Cloud یک پروژه بسازید و **Web Search Indexing API** را فعال کنید.
2. یک سرویس اکانت بسازید و از تب Keys یک کلید **JSON** دانلود کنید.
3. در Search Console ← Settings ← Users and permissions، ایمیل سرویس اکانت را با نقش **Owner** اضافه کنید.
4. در وردپرس ← Indexing API ← تنظیمات، فایل JSON را بارگذاری کنید و «تست اتصال» را بزنید.

برای امنیت بیشتر می‌توانید کلید را بیرون از `public_html` نگه دارید و در `wp-config.php` بنویسید:

```php
define( 'SGIA_KEY_FILE', '/home/USER/keys/indexing-service-account.json' );
```

## API برای افزونه‌های دیگر

```php
SGIA_Queue::import( array $urls, string $source = '' ); // افزودن به صف؛ $source در جدول صف برچسب می‌شود (مثلاً 'gscii')
SGIA_Queue::lookup( array $urls );                      // url => status, sent_at, added_at, last_code, last_error, source, index_state, coverage, checked_at
SGIA_Queue::capacity_today();                           // تعداد ارسالی که صف امروز هنوز انجام می‌دهد (۰ اگر متوقف یا خاموش)
// فیلتر برای تست/پراکسی: آدرس endpointهای گوگل
add_filter( 'sgia_api_url', function ( $url ) { return $url; } );
add_action( 'sgia_published', function ( $url, $type, $ok, $code, $source, $post_id ) {}, 10, 6 );
```

مقادیر `status`: `pending`، `sent`، `failed` و از ۲.۲ به بعد `skipped` (ردشده پیش از ارسال؛ دلیل در `last_error`).

## محدودیت‌ها

- گوگل این API را رسماً فقط برای صفحات دارای `JobPosting` یا `BroadcastEvent` داخل `VideoObject` پشتیبانی می‌کند. پاسخ موفق یعنی سیگنال خزش ثبت شد، نه تضمین ایندکس.
- سهمیه پیش‌فرض: ۲۰۰ درخواست publish در روز برای هر پروژه، ریست در نیمه‌شب به وقت Pacific.
- URL Inspection API سهمیه جداگانه دارد (۲۰۰۰ در روز برای هر property) و به فعال بودن Google Search Console API در همان پروژه Google Cloud نیاز دارد.

## ساختار پروژه

```
simple-google-indexing-api.php   فایل اصلی افزونه
includes/class-sgia-api.php      احراز هویت، ارسال، سهمیه، لاگ
includes/class-sgia-auto.php     ارسال خودکار هنگام انتشار/حذف
includes/class-sgia-queue.php    صف ارسال: جدول، خواندن xlsx/csv، اولویت، ارسال زمان‌بندی‌شده، بررسی دوباره
includes/class-sgia-inspect.php  Search Console URL Inspection و تشخیص property
includes/class-sgia-check.php    بررسی پیش از ارسال (داخلی و HTTP)
includes/class-sgia-indexnow.php IndexNow: فایل کلید، ارسال خودکار، ارسال همه صفحات
includes/class-sgia-report.php   گزارش روزانه، هشدار، سلامت WP-Cron
includes/class-sgia-stats.php    شمارنده‌های روزانه
includes/class-sgia-http.php     درخواست‌های هم‌زمان (curl_multi) با همان تنظیمات پراکسی/SSL وردپرس
includes/class-sgia-admin.php    صفحات مدیریت، AJAX، متاباکس، ستون لیست
assets/admin.css, admin.js       رابط کاربری
uninstall.php                    پاک‌سازی داده‌ها هنگام حذف افزونه
```

## انتشار نسخه جدید

شماره نسخه را در `simple-google-indexing-api.php` (هدر و ثابت `SGIA_VERSION`) و `readme.txt` به‌روز کنید، سپس یک تگ بزنید:

```bash
git tag v2.1.1 && git push origin v2.1.1
```

GitHub Actions سینتکس PHP را بررسی می‌کند و فایل zip قابل نصب را به Release اضافه می‌کند.

## مجوز

GPL-2.0-or-later
