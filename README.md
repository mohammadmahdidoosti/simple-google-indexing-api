# Simple Google Indexing API

افزونه وردپرس برای ارسال خودکار و دستی درخواست ایندکس (`URL_UPDATED` / `URL_DELETED`) به [Google Indexing API](https://developers.google.com/search/apis/indexing-api/v3/quickstart)، با داشبورد، ارسال گروهی، کنترل سهمیه و گزارش کامل. رابط کاربری فارسی و راست‌چین است.

## امکانات

- **ارسال خودکار:** هنگام انتشار/ویرایش ← `URL_UPDATED`؛ هنگام حذف، لغو انتشار یا تغییر نامک ← `URL_DELETED` برای آدرس قدیمی
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

## محدودیت‌ها

- گوگل این API را رسماً فقط برای صفحات دارای `JobPosting` یا `BroadcastEvent` داخل `VideoObject` پشتیبانی می‌کند. پاسخ موفق یعنی سیگنال خزش ثبت شد، نه تضمین ایندکس.
- سهمیه پیش‌فرض: ۲۰۰ درخواست publish در روز برای هر پروژه، ریست در نیمه‌شب به وقت Pacific.

## ساختار پروژه

```
simple-google-indexing-api.php   فایل اصلی افزونه
includes/class-sgia-api.php      احراز هویت، ارسال، سهمیه، لاگ
includes/class-sgia-auto.php     ارسال خودکار هنگام انتشار/حذف
includes/class-sgia-admin.php    صفحات مدیریت، AJAX، متاباکس، ستون لیست
assets/admin.css, admin.js       رابط کاربری
uninstall.php                    پاک‌سازی داده‌ها هنگام حذف افزونه
```

## انتشار نسخه جدید

شماره نسخه را در `simple-google-indexing-api.php` (هدر و ثابت `SGIA_VERSION`) و `readme.txt` به‌روز کنید، سپس یک تگ بزنید:

```bash
git tag v2.0.1 && git push origin v2.0.1
```

GitHub Actions سینتکس PHP را بررسی می‌کند و فایل zip قابل نصب را به Release اضافه می‌کند.

## مجوز

GPL-2.0-or-later
