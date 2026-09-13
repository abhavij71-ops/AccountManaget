<div dir="rtl" align="right">

# مدل هویت و پیشنهاد تغییر — Account Manager

این سند به یک پرسش طراحی پاسخ می‌دهد: **آیا Phone هویت مستقل دارد؟** و تغییری را پیشنهاد می‌کند که برای سرویس‌های ایرانی مثل دیوار ضروری است.

---

## ۱. وضعیت فعلی

### Phone به‌عنوان یک موجودیت — بله، مستقل است

```sql
CREATE TABLE phones (
    id, phone_number UNIQUE, country, label, status, is_primary, notes, ...
);
CREATE TABLE phone_email   (phone_id, email_id);    -- چند به چند
CREATE TABLE phone_account (phone_id, account_id);  -- چند به چند
```

Phone جدول خودش را دارد، به‌تنهایی ساخته و ویرایش می‌شود، صفحه پروفایل دارد، تگ می‌پذیرد و می‌تواند هم‌زمان به چند Email و چند Account متصل شود. از این منظر کاملاً یک موجودیت درجه‌یک است.

### Phone به‌عنوان هویت یک اکانت — خیر، نیست

```sql
CREATE TABLE accounts (
    service_id INTEGER NOT NULL REFERENCES services(id),
    email_id   INTEGER NOT NULL REFERENCES emails(id),   -- ← اجباری
    ...
);
```

ستون `email_id` با `NOT NULL` تعریف شده است. یعنی **هیچ اکانتی بدون ایمیل نمی‌تواند وجود داشته باشد**. رابطه Phone با Account از طریق جدول `phone_account` برقرار می‌شود که فقط یک پیوند جانبی است — نه صاحب هویت.

نتیجه: Phone یک **صفت** اکانت است، نه **لنگرگاه هویت** آن.

---

## ۲. چرا این یک مشکل واقعی است

بسیاری از سرویس‌های ایرانی و منطقه‌ای فقط با شماره تلفن ثبت‌نام می‌کنند و اصلاً فیلد ایمیل ندارند:

| سرویس | روش ثبت‌نام |
|---|---|
| دیوار | فقط شماره موبایل |
| اسنپ / تپسی | فقط شماره موبایل |
| تلگرام | فقط شماره موبایل |
| واتساپ | فقط شماره موبایل |
| بله / ایتا / روبیکا | فقط شماره موبایل |
| بانک‌های ایرانی (اپ موبایل) | شماره موبایل + کد ملی |

برای ثبت یک اکانت دیوار در وضعیت فعلی، کاربر مجبور است یکی از این کارهای بد را انجام دهد:

1. یک ایمیل ساختگی (مثلاً `divar-placeholder@none.local`) بسازد → دیتابیس آلوده می‌شود و آمار Email اشتباه می‌شود
2. یک ایمیل نامرتبط را به‌زور به آن وصل کند → رابطه دروغین ایجاد می‌شود که دقیقاً برخلاف اصل «Relationship Visibility» محصول است
3. اصلاً آن اکانت را ثبت نکند → هدف اصلی محصول شکست می‌خورد

هر سه گزینه بد هستند. این یک محدودیت schema است، نه یک تصمیم طراحی آگاهانه.

---

## ۳. تغییر پیشنهادی: هویت لنگرگاهی (Identity Anchor)

مفهوم درست این است که هر اکانت دقیقاً **یک لنگرگاه هویت** دارد که یا Email است یا Phone.

### تغییر schema

```sql
-- ۱. email_id را اختیاری کن
-- ۲. phone_id به‌عنوان لنگرگاه جایگزین اضافه کن
-- ۳. نوع لنگرگاه را صریح ذخیره کن

ALTER TABLE accounts ADD COLUMN identity_type TEXT NOT NULL DEFAULT 'email'
    CHECK (identity_type IN ('email','phone','username','other'));
ALTER TABLE accounts ADD COLUMN identity_phone_id INTEGER REFERENCES phones(id) ON DELETE RESTRICT;
```

و قید یکپارچگی:

```sql
CHECK (
    (identity_type = 'email'    AND email_id IS NOT NULL) OR
    (identity_type = 'phone'    AND identity_phone_id IS NOT NULL) OR
    (identity_type = 'username' AND username IS NOT NULL) OR
    (identity_type = 'other')
)
```

> **توجه SQLite:** افزودن CHECK به جدول موجود ممکن نیست. باید جدول جدید ساخته، داده کپی و rename شود. حتماً پیش از اجرا از `data/database.sqlite` نسخه پشتیبان بگیرید.

### مهاجرت داده‌های موجود

```sql
UPDATE accounts SET identity_type = 'email' WHERE email_id IS NOT NULL;
```
همه رکوردهای فعلی به‌درستی `email` می‌شوند. هیچ داده‌ای از بین نمی‌رود.

---

## ۴. تأثیر بر بقیه برنامه

| بخش | تغییر لازم |
|---|---|
| فرم Add / Quick Add | انتخاب «نوع هویت» در ابتدا؛ فیلد Email یا Phone بر اساس انتخاب نمایش داده شود |
| فرم Edit | همان الگو + امکان تغییر نوع لنگرگاه |
| Account Profile | در Header به‌جای Email ثابت، «هویت» با آیکون متناسب نمایش داده شود |
| لیست Accounts | ستون Email به ستون «هویت» تبدیل شود |
| Email Profile → Usage Map | بدون تغییر (فقط اکانت‌های `identity_type='email'` را می‌شمارد) |
| Phone Profile | یک بخش جدید: «اکانت‌هایی که با این شماره ساخته شده‌اند» — جدا از «اکانت‌های متصل» |
| Needs Attention | قانون جدید: اکانت با لنگرگاه Phone و بدون Recovery = Critical (چون از دست دادن سیم‌کارت یعنی از دست دادن کامل اکانت) |
| Global Search | جستجو در `identity_phone_id` هم انجام شود |
| CSV Import | ستون `identity_type` و `phone` به نگاشت اضافه شود |
| Security Score | Phone لنگرگاه باید Security Score خودش را داشته باشد (وضعیت PIN سیم‌کارت، port-out lock) |

---

## ۵. اثر جانبی مثبت: Security Score برای Phone

وقتی Phone می‌تواند لنگرگاه هویت باشد، امنیت خود شماره اهمیت پیدا می‌کند. جدول `phone_security` موازی با `email_security` منطقی می‌شود:

```sql
CREATE TABLE phone_security (
    phone_id INTEGER UNIQUE REFERENCES phones(id) ON DELETE CASCADE,
    sim_pin_status      TEXT DEFAULT 'Not Set',   -- پنج‌وضعیتی
    port_out_lock       TEXT DEFAULT 'Not Set',   -- قفل انتقال شماره نزد اپراتور
    carrier             TEXT,
    esim                INTEGER,
    last_security_check TEXT,
    security_score      INTEGER
);
```

این موضوع برای بازار ایران اهمیت عملی دارد: حمله SIM-swap راه اصلی از دست رفتن اکانت‌های مبتنی بر شماره است، و اگر کاربر ندانَد کدام اکانت‌هایش فقط با شماره محافظت می‌شوند، نمی‌تواند ریسک را بسنجد.

---

## ۶. پاسخ کوتاه

**آیا Phone هویت مستقل دارد؟**
به‌عنوان موجودیت — بله. به‌عنوان لنگرگاه هویت اکانت — خیر، و این یک محدودیت واقعی است که باید برطرف شود.

**آیا این تغییر باید انجام شود؟**
بله. با توجه به بازار هدف (کاربران ایرانی و عراقی)، اکانت‌های مبتنی بر شماره تلفن یک حالت استثنایی نیستند بلکه بخش قابل‌توجهی از موارد استفاده هستند. بدون این تغییر، برنامه بخش بزرگی از حساب‌های کاربر را نمی‌تواند به‌درستی ثبت کند.

**اولویت:** بالا — پیش از اینکه حجم زیادی داده وارد شود، چون مهاجرت بعدی سخت‌تر خواهد بود.

</div>
