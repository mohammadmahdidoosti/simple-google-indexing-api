=== Simple Google Indexing API ===
Contributors: moh
Tags: google, indexing api, seo, search console, index
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ارسال خودکار و دستی درخواست ایندکس به Google Indexing API با داشبورد، ارسال گروهی، کنترل سهمیه و گزارش.

== Description ==

* ارسال خودکار URL_UPDATED هنگام انتشار/ویرایش و URL_DELETED هنگام حذف، لغو انتشار یا تغییر نامک
* داشبورد با مصرف سهمیه روزانه، نرخ موفقیت و فعالیت‌های اخیر
* ارسال گروهی تا صدها آدرس با نوار پیشرفت، توقف و نتیجه جداگانه برای هر آدرس
* بررسی وضعیت آدرس (urlNotifications.metadata)
* بارگذاری کلید سرویس اکانت با کشیدن و رها کردن و اعتبارسنجی فوری
* ستون وضعیت، دکمه «درخواست ایندکس» و Bulk Action در لیست نوشته‌ها
* متاباکس در صفحه ویرایش نوشته
* گزارش کامل با فیلتر، جستجو، راهنمای رفع خطا و خروجی CSV
* بدون وابستگی به کتابخانه‌های خارجی (JWT با OpenSSL)

نکته: گوگل این API را رسماً فقط برای صفحات دارای JobPosting یا BroadcastEvent داخل VideoObject پشتیبانی می‌کند.

== Installation ==

1. فایل zip را از «افزونه‌ها ← افزودن ← بارگذاری افزونه» نصب و فعال کنید.
2. در Google Cloud، Web Search Indexing API را فعال کنید و یک سرویس اکانت با کلید JSON بسازید.
3. ایمیل سرویس اکانت را در Search Console با نقش Owner اضافه کنید.
4. از منوی «Indexing API ← تنظیمات» فایل JSON را بارگذاری کنید و «تست اتصال» را بزنید.

برای امنیت بیشتر می‌توانید به جای ذخیره کلید در دیتابیس، در wp-config.php بنویسید:
define( 'SGIA_KEY_FILE', '/path/outside/public_html/key.json' );

== Changelog ==

= 2.0.0 =
* رابط کاربری کامل: داشبورد، ارسال گروهی AJAX، تنظیمات با dropzone، گزارش با فیلتر و CSV
* کنترل سهمیه روزانه بر اساس نیمه‌شب Pacific
* متاباکس ویرایشگر و ستون وضعیت در لیست نوشته‌ها
* راهنمای فارسی برای خطاهای رایج (403، 429، invalid_grant و ...)

= 1.0.0 =
* نسخه اولیه
