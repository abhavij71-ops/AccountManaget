<div dir="rtl" align="right">

# راهنمای توسعه — Account Manager

🇬🇧 [Read in English](DEVELOPMENT.md)

این سند برای کسی نوشته شده که می‌خواهد روی کد این پروژه کار کند. برای نصب به [INSTALLATION.fa.md](INSTALLATION.fa.md) و برای معرفی محصول به [ABOUT.fa.md](ABOUT.fa.md) مراجعه کنید.

---

## ۱. اصول معماری (قابل مذاکره نیست)

این پنج اصل عمداً انتخاب شده‌اند. هر Pull Request که یکی از آن‌ها را نقض کند باید رد شود.

1. **بدون فریم‌ورک.** PHP خالص ۸.۱ به بالا. بدون Composer، بدون Node، بدون مرحله build. دلیل: باید روی هر هاست اشتراکی با آپلود FTP کار کند.
2. **بدون وابستگی خارجی در زمان اجرا.** Bootstrap، فونت Vazirmatn و JS همگی لوکال هستند. هیچ فراخوانی CDN، هیچ API خارجی.
3. **فقط Prepared Statement.** هیچ‌جا رشته SQL با متغیر الحاق (concatenation) نشود. تنها استثنای مجاز، نام ستون در `ORDER BY` است، آن هم فقط از طریق allow-list (الگوی موجود در `modules/accounts/index.php` را ببینید).
4. **هرگز Secret ذخیره نشود.** نه در جدول، نه در UI، نه در `history`، نه در خروجی Export. فقط `credential_storage` و `credential_reference` به‌عنوان اشاره به محل نگهداری.
5. **مدل پنج‌وضعیتی هرگز فشرده نشود.** `Enabled` / `Disabled` / `Unknown` / `Not Set` / `Not Applicable` معانی متفاوت دارند. هیچ‌جا `Unknown` نباید مثل `Not Set` رفتار کند، و `Not Applicable` نباید باعث کاهش Completeness شود.

---

## ۲. نقشه فایل‌ها

| مسیر | مسئولیت |
|---|---|
| `config.php` | ثابت‌ها، مسیرها، شروع session، تشخیص `APP_BASE_URL` |
| `db.php` | اتصال PDO + مهاجرت‌های سبک runtime |
| `install.php` | ساخت schema + کاربر مدیر — یک‌بار مصرف، بعد از نصب حذف شود |
| `includes/auth.php` | session، `requireLogin()`، CSRF |
| `includes/helpers.php` | نگاشت enumها، badge، صفحه‌بندی، تگ، `log_history()` |
| `includes/lang.php` | تابع `t()`، تشخیص زبان، جهت متن |
| `includes/security-score.php` | محاسبه امتیاز امنیتی Email |
| `includes/needs-attention.php` | موتور قوانین سه‌سطحی |
| `includes/renewals.php` | کوئری تمدیدهای نزدیک/عقب‌افتاده |
| `includes/import.php` | parse، validate و تشخیص تکراری CSV |
| `lang/fa.php`, `lang/en.php` | فایل‌های ترجمه |
| `modules/<entity>/` | CRUD هر موجودیت |

---

## ۳. افزودن یک فیلد جدید

ترتیب کار مهم است. اگر مرحله‌ای را جا بیندازید، باگ خاموش ایجاد می‌شود.

1. **Schema** — عبارت `CREATE TABLE` مربوطه را در `installSchemaStatements()` داخل `install.php` ویرایش کنید.
2. **مهاجرت** — چون نصب‌های موجود دوباره `install.php` را اجرا نمی‌کنند، یک `ALTER TABLE ... ADD COLUMN` محافظت‌شده در `db.php` اضافه کنید (الگوی `emails.is_favorite` را ببینید).
3. **فرم Add و Edit** — فیلد را در آرایه `$form`، در منطق اعتبارسنجی، در `INSERT`/`UPDATE` و در HTML اضافه کنید.
4. **صفحه View** — فیلد را نمایش دهید؛ برای مقدار خالی از `dashOrValue()` و برای enum از `renderBadge()` استفاده کنید.
5. **History** — اگر فیلد مهم است، `log_history()` را در مسیر ویرایش صدا بزنید.
6. **ترجمه** — کلید برچسب را در **هر دو** فایل `lang/fa.php` و `lang/en.php` اضافه کنید.
7. **جستجو** — اگر فیلد باید قابل جستجو باشد، به کوئری `search.php` اضافه شود.
8. **Import** — اگر فیلد باید از CSV بیاید، به نگاشت ستون‌ها در `includes/import.php` اضافه شود.

---

## ۴. افزودن یک موجودیت جدید

۱. جدول را با ستون‌های استاندارد (`id`, `created_at`, `updated_at`) بسازید و trigger `updated_at` را در `installTriggerStatements()` ثبت کنید.
۲. پوشه `modules/<entity>/` با چهار فایل `index.php`, `add.php`, `edit.php`, `view.php` بسازید.
۳. آیتم ناوبری را در `$navItems` داخل `includes/header.php` اضافه کنید.
۴. `entity_type` جدید را به CHECK constraint جدول‌های `taggables` و `history` اضافه کنید.
۵. توابع `entityProfileUrl()` و `entityDisplayLabel()` در `helpers.php` را گسترش دهید.
۶. موجودیت را به `search.php` اضافه کنید.

---

## ۵. قوانین کدنویسی

**خروجی همیشه escape شود.** از `e()` استفاده کنید، نه `htmlspecialchars` مستقیم:

```php
<?= e($account['username']) ?>
```

**هر فرم POST باید CSRF داشته باشد:**

```php
<input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
```
و در سمت سرور:
```php
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { /* رد کن */ }
```

**مرتب‌سازی پویا فقط با allow-list:**

```php
$allowed = ['username', 'status', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowed, true) ? $_GET['sort'] : 'created_at';
```

**پیام‌های موفقیت با flash، نه echo مستقیم:**

```php
flashSet('success', t('common.saved'));
header('Location: ' . appUrl('modules/emails/index.php'));
exit;
```

---

## ۶. مهاجرت دیتابیس روی نصب‌های موجود

`install.php` فقط یک‌بار اجرا می‌شود. برای تغییر schema در نصب‌های زنده، الگوی محافظت‌شده را در `db.php` به‌کار ببرید:

```php
$cols = $pdo->query("PRAGMA table_info(emails)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('is_favorite', $cols, true)) {
    $pdo->exec("ALTER TABLE emails ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0");
}
```

**هشدار SQLite:** پشتیبانی از `ALTER TABLE` محدود است. حذف ستون یا تغییر CHECK constraint نیازمند ساخت جدول جدید، کپی داده و rename است. قبل از هر مهاجرت سنگین از فایل `data/database.sqlite` نسخه پشتیبان بگیرید.

---

## ۷. تست دستی پیش از انتشار

چون تست خودکار وجود ندارد، این چک‌لیست را دستی اجرا کنید:

1. نصب تازه: حذف `data/database.sqlite` → اجرای `install.php` → ورود
2. هر چهار موجودیت: افزودن، ویرایش، مشاهده، آرشیو، حذف
3. اتصال و قطع اتصال Phone به Email و Account — بررسی اینکه Unlink موجودیت را حذف نکند
4. سوییچ زبان روی هر صفحه — بررسی اینکه redirect به همان صفحه برگردد
5. Import یک CSV با یک رکورد تکراری — بررسی اینکه بدون تأیید بازنویسی نشود
6. نمای موبایل (عرض زیر ۷۶۸px) — منوی offcanvas و جدول‌های اسکرول‌شونده
7. صفحه Needs Attention — بررسی اینکه رکوردهای Closed/Abandoned به‌عنوان مشکل فعال نیایند

---

## ۸. انتشار نسخه

۱. `APP_VERSION` را در `config.php` به‌روز کنید.
۲. یک بخش جدید به `CHANGELOG.md` اضافه کنید (Add / Fix / Note).
۳. اگر رفتار کاربر تغییر کرده، `README.md` و `README.fa.md` را هم‌زمان به‌روز کنید.
۴. Tag بزنید و commit کنید.

</div>
