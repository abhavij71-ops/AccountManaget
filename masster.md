# Account Manager

## Product Specification

---

# 1. معرفی محصول

**Account Manager** یک سیستم شخصی برای مدیریت و سازمان‌دهی اطلاعات Emailها، Serviceها و Accountهای کاربر است.

هدف اصلی این سیستم این است که تمام اطلاعات مربوط به حساب‌های کاربر در سرویس‌های مختلف، در یک مکان منظم و قابل جستجو قرار بگیرد.

سیستم باید به کاربر کمک کند تا همیشه بداند:

* چه Emailهایی دارد
* هر Email در چه سرویس‌هایی استفاده شده
* چه Accountهایی دارد
* هر Account مربوط به کدام Service است
* هر Account با کدام Email و Phone مرتبط است
* وضعیت هر Account چیست
* وضعیت امنیتی آن چگونه است
* اطلاعات Recovery آن چیست
* Subscription آن چیست
* چه پرداختی برای آن انجام می‌شود
* چه زمانی تمدید می‌شود
* کدام Accountها نیاز به توجه دارند

---

# 2. مشکل اصلی

با افزایش تعداد Emailها و Accountها، مدیریت دستی اطلاعات بسیار دشوار می‌شود.

برای مثال ممکن است یک کاربر:

* ده‌ها Email داشته باشد
* برای هر Email چندین Account ساخته باشد
* در یک Service چند Account داشته باشد
* یک Phone را برای چند Email استفاده کند
* برای بعضی Accountها Subscription فعال داشته باشد
* بعضی Accountها Free یا Trial باشند
* بعضی Accountها قدیمی یا غیرفعال باشند

در چنین شرایطی پیدا کردن ارتباط بین این اطلاعات دشوار است.

Account Manager برای حل همین مشکل طراحی می‌شود.

---

# 3. هدف اصلی

سیستم باید یک **مرکز مدیریت اطلاعات Accountها** باشد.

کاربر باید بتواند اطلاعات خود را:

* ثبت کند
* ویرایش کند
* دسته‌بندی کند
* به یکدیگر مرتبط کند
* جستجو کند
* بررسی کند
* وضعیت امنیتی آن‌ها را ببیند
* وضعیت Subscription را بررسی کند
* موارد نیازمند توجه را پیدا کند

---

# 4. اصل مهم امنیتی

این برنامه **Password Manager نیست**.

هدف برنامه ذخیره اطلاعات مربوط به Accountها است، نه ذخیره Secretها.

هرگز نباید اطلاعاتی مانند موارد زیر در آن ذخیره شود:

* Password
* API Key
* Access Token
* Secret
* Recovery Code واقعی
* CVV
* شماره کامل کارت بانکی

در عوض، سیستم فقط می‌تواند محل نگهداری Credential را ثبت کند.

مثال:

```text
Credential Storage: KeePass
Credential Reference: GitHub / Work
```

---

# 5. موجودیت‌های اصلی

سیستم از موجودیت‌های اصلی زیر تشکیل می‌شود:

* Email
* Service
* Account
* Phone
* Subscription
* Payment
* Tag
* Custom Field
* History

---

# 6. Email

Email یکی از مهم‌ترین موجودیت‌های سیستم است.

## اطلاعات Email

* Email Address
* Display Name
* Provider
* Type
* Purpose
* Status
* Created Date
* Last Verified
* Notes
* Tags

## نوع Email

```text
Personal
Work
Business
Project
Secondary
Temporary
Other
```

## وضعیت Email

```text
Active
Suspended
Disabled
Abandoned
Unknown
```

---

# 7. Email Security

اطلاعات امنیتی خود Email باید جداگانه مدیریت شود.

موارد قابل ثبت:

* 2FA
* 2FA Method
* Passkey
* Security Key
* Security Questions
* Last Security Check
* Recovery Email
* Recovery Phone
* Recovery Codes Status/Reference
* Backup Method
* Last Recovery Verification

---

# 8. Email Security Score

هر Email باید یک **Security Score** داشته باشد.

امتیاز باید فقط امنیت خود Email را ارزیابی کند.

وضعیت امنیتی Accountهایی که از آن Email استفاده می‌کنند نباید امتیاز امنیتی خود Email را تغییر دهد.

برای مثال:

```text
Email Security Score: 91/100
```

در کنار آن می‌توان وضعیت امنیتی Accountهای مرتبط را به‌صورت جداگانه نمایش داد.

---

# 9. وضعیت‌های امنیتی

برای اطلاعات امنیتی باید بتوان وضعیت‌های مختلف را از هم تشخیص داد:

```text
Enabled
Disabled
Unknown
Not Set
Not Applicable
```

این وضعیت‌ها معانی متفاوتی دارند.

### Unknown

اطلاعات مشخص نیست.

### Not Set

هنوز مقداری ثبت نشده است.

### Not Applicable

این مورد برای آن Entity کاربرد ندارد.

### Disabled

وضعیت مشخص است و غیرفعال است.

این موارد نباید با یکدیگر اشتباه شوند.

---

# 10. Profile Completeness

سیستم باید میزان کامل بودن اطلاعات هر Profile را نشان دهد.

مثلاً:

```text
Profile Completeness: 96%
```

اطلاعات می‌توانند در سه حالت اصلی باشند:

```text
Known
Unknown
Not Set
```

Unknown نباید دقیقاً مانند Empty در نظر گرفته شود.

همچنین فیلدهای Not Applicable نباید به شکل ناعادلانه باعث کاهش امتیاز شوند.

---

# 11. Service

Service نشان‌دهنده یک سرویس، وب‌سایت یا پلتفرم است.

## اطلاعات Service

* Service Name
* Website
* Login URL
* Category
* Status
* Purpose
* Notes
* Tags

Category باید قابل توسعه باشد و محدود به چند گزینه ثابت نباشد.

---

# 12. Account

Account نشان‌دهنده یک حساب کاربری در یک Service است.

هر Account به یک:

* Service
* Email

مرتبط است.

## اطلاعات پایه

* Service
* Email
* Username
* Display Name
* Account ID
* Account URL
* Login URL
* Status
* Account Type
* Created Date
* Last Login
* Last Verified

---

# 13. Account Type

```text
Personal
Work
Business
Project
Other
```

---

# 14. Account Status

```text
Active
Suspended
Disabled
Closed
Abandoned
Pending
Unknown
```

Account Status باید مستقل از Subscription Status باشد.

مثلاً:

```text
Account Status: Active
Subscription: Cancelled
```

این ترکیب کاملاً معتبر است.

---

# 15. Quick Add

برای اضافه کردن سریع Account، فرم ساده‌ای وجود دارد.

حداقل اطلاعات:

* Service
* Email
* Username
* Status
* Plan
* Notes

هدف Quick Add سرعت است.

---

# 16. Full Edit

برای مدیریت کامل Account، فرم Full Edit تمام اطلاعات مربوط به Account را در اختیار کاربر قرار می‌دهد.

---

# 17. Account Security

اطلاعات امنیتی Account شامل:

* 2FA
* 2FA Method
* Passkey
* Security Key
* Security Questions
* Last Security Check
* Credential Storage
* Credential Reference

است.

امنیت Account باید جدا از Email Security Score نمایش داده شود.

---

# 18. Recovery

Recovery اطلاعات مربوط به روش‌های بازیابی Account است.

موارد قابل ثبت:

* Recovery Email
* Recovery Phone
* Recovery Contact
* Recovery Codes Status/Reference
* Backup Method
* Last Recovery Verification
* Recovery Notes

وضعیت Recovery:

```text
Verified
Not Verified
Unknown
Not Set
Not Applicable
```

---

# 19. Phone

Phone یک موجودیت مستقل است.

دلیل آن این است که یک Phone می‌تواند برای چند Email و چند Account استفاده شود.

## اطلاعات Phone

* Phone Number
* Country
* Label
* Status
* Primary
* Notes

روابط Phone با Email و Account باید قابل مشاهده و مدیریت باشند.

---

# 20. روابط اصلی

یک Email می‌تواند به چند Account متصل باشد.

یک Service می‌تواند چند Account داشته باشد.

یک Phone می‌تواند به چند Email متصل باشد.

یک Phone می‌تواند به چند Account متصل باشد.

ساختار کلی:

```text
Email
 ├── Account
 ├── Account
 └── Account

Service
 ├── Account
 ├── Account
 └── Account

Phone
 ├── Email
 ├── Email
 └── Account
```

---

# 21. Email Profile

صفحه Email Profile باید یک نمای کامل از Email ارائه کند.

## Header

* Email Address
* Type
* Purpose
* Status
* Security Score
* Profile Completeness

## آمار

* تعداد Accountها
* تعداد Serviceها
* تعداد Accountهای Paid
* تعداد Issues

## بخش‌ها

* Identity
* Security
* Recovery
* Phone Numbers
* Accounts Using This Email
* Needs Attention
* Tags
* Notes
* History

کاربر باید بتواند از Email مستقیماً وارد Account مرتبط شود.

---

# 22. Email Usage Map

برای هر Email بهتر است مشخص باشد:

* چند Account دارد
* در چند Service استفاده شده
* در چه Categoryهایی استفاده شده
* چند Account Paid دارد
* چند مورد نیازمند توجه دارد

این بخش باید رابطه Email با سایر موجودیت‌ها را سریع و واضح نشان دهد.

---

# 23. Service Profile

Service Profile باید شامل:

* Service Name
* Website
* Category
* Status
* تعداد Accountها
* تعداد Emailها
* تعداد Paid Accountها
* تعداد Issues

باشد.

---

# 24. Accounts در Service

در صفحه Service باید Accountهای مرتبط نمایش داده شوند.

اطلاعات اصلی:

* Email
* Username
* Status
* Plan
* 2FA
* Last Verified

کاربر باید بتواند:

* مرتب‌سازی کند
* فیلتر کند
* Account را باز کند

---

# 25. Service Security Overview

در سطح Service باید وضعیت امنیتی Accountهای آن سرویس قابل مشاهده باشد.

مثلاً:

```text
2FA Enabled
2FA Disabled
Unknown
```

این گزارش مربوط به Accountهای Service است و با Email Security Score تفاوت دارد.

---

# 26. Account Profile

Account Profile باید تمام اطلاعات مهم یک Account را در یک صفحه نشان دهد.

## Header

* Service
* Username / Email
* Status
* Completeness
* Last Verified

## Quick Actions

* Edit
* Verify
* Open Login
* Open Credential Reference
* Archive

## Sections

1. Basic Information
2. Security
3. Recovery
4. Subscription
5. Payment
6. Credential Reference
7. Custom Fields
8. Notes
9. Tags
10. History

---

# 27. Subscription

هر Account می‌تواند اطلاعات Subscription داشته باشد.

## Subscription Type

```text
Free
Paid
Trial
Promotional
Lifetime
Enterprise
Unknown
Not Applicable
```

## اطلاعات Subscription

* Type
* Plan
* Status
* Price
* Currency
* Billing Cycle
* Start Date
* Renewal Date
* Auto Renewal

---

# 28. Payment

اطلاعات Payment فقط به‌صورت غیرحساس ثبت می‌شود.

موارد قابل ثبت:

* Payment Required
* Payment Method
* Card Brand
* Last 4 Digits
* Payment Reference
* Auto Renewal

شماره کامل کارت یا CVV نباید ذخیره شود.

---

# 29. Conditional Subscription Information

رابط کاربری باید متناسب با نوع Subscription رفتار کند.

### Free

اطلاعات Payment غیرضروری نباید فضای صفحه را اشغال کند.

### Paid

نمایش:

* Price
* Currency
* Billing Cycle
* Renewal Date
* Auto Renewal
* Payment

### Trial

اطلاعات مربوط به دوره آزمایشی باید قابل مشاهده باشد.

---

# 30. هزینه‌ها

سیستم باید هزینه Subscriptionها را قابل مشاهده کند.

اما Currencyهای مختلف نباید بدون تبدیل معتبر با هم جمع شوند.

مثلاً:

```text
EUR 100 / month
USD 100 / month
```

نباید تبدیل به:

```text
200 / month
```

شود.

اگر نرخ تبدیل معتبر در دسترس نباشد، هزینه‌ها باید بر اساس Currency جداگانه نمایش داده شوند.

Monthly و Yearly نیز نباید بدون منطق مشخص با یکدیگر ترکیب شوند.

---

# 31. Renewal

سیستم باید Subscriptionهای:

* نزدیک به Renewal
* Overdue
* Auto Renew

را مشخص کند.

تاریخ‌ها باید بر اساس اطلاعات واقعی ثبت‌شده باشند.

---

# 32. Tags

Tag برای دسته‌بندی سریع اطلاعات استفاده می‌شود.

مثلاً:

```text
Work
Important
Development
Personal
Old
Finance
AI
Cloud
```

Tagها باید قابل ایجاد و حذف و اتصال و جدا کردن باشند.

Tag می‌تواند به:

* Email
* Service
* Account
* Phone

متصل شود.

---

# 33. Custom Fields

برای اطلاعاتی که در فیلدهای استاندارد وجود ندارند، Account باید Custom Field داشته باشد.

مثال:

```text
Team: Development
Internal ID: DEV-2381
Region: EU
Owner: John
```

Custom Fieldها باید محدودیت مصنوعی نداشته باشند.

نباید از Custom Field برای ذخیره Secret استفاده شود.

---

# 34. Notes

هر Entity می‌تواند Notes داشته باشد.

Notes برای اطلاعات آزاد و غیرساختاریافته هستند.

مثلاً:

```text
This account is used for development only.
```

---

# 35. History

سیستم باید تغییرات مهم را ثبت کند.

مثلاً:

```text
Account Created
Account Updated
Status Changed
2FA Changed
Subscription Changed
Email Linked
Email Unlinked
Phone Linked
Phone Unlinked
Account Archived
```

History باید بتواند نشان دهد:

* چه چیزی تغییر کرده
* مقدار قبلی چه بوده
* مقدار جدید چه شده
* چه زمانی تغییر کرده

History نباید حاوی Secret باشد.

---

# 36. Needs Attention

سیستم باید مواردی را که نیاز به بررسی دارند مشخص کند.

## سطح اهمیت

```text
Critical
Warning
Informational
```

### نمونه Critical

* 2FA غیرفعال
* Recovery وجود ندارد
* وضعیت مهم امنیتی نامشخص است

### Warning

* Recovery تأیید نشده
* اطلاعات قدیمی است
* Renewal نزدیک است
* Subscription مشکل دارد

### Informational

* اطلاعات ناقص
* Unknown
* نیاز به بررسی دوره‌ای

مواردی مانند Accountهای Closed یا Abandoned نباید بدون دلیل به‌عنوان مشکل فعال نمایش داده شوند.

---

# 37. Global Search

کاربر باید بتواند از هر قسمت سیستم جستجو کند.

Search باید در موارد زیر کار کند:

* Email Address
* Username
* Service Name
* Account Name
* Phone Number
* Account ID
* Tags
* Notes
* Custom Fields
* Payment Reference

جستجو باید Partial Matching را پشتیبانی کند.

---

# 38. Search Results

نتایج Search بهتر است بر اساس Entity دسته‌بندی شوند.

مثلاً:

```text
Emails
Services
Accounts
Phones
```

هر نتیجه باید امکان رفتن مستقیم به Profile مربوطه را داشته باشد.

همچنین رابطه‌های مهم نیز باید قابل مشاهده باشند.

مثلاً:

```text
john@gmail.com
→ GitHub
→ Personal Account
```

---

# 39. Dashboard

Dashboard نمای کلی سیستم است.

## Summary

* Emails
* Services
* Accounts
* Paid Accounts
* Needs Attention

## Security

* Email Security Overview
* Account Security Overview

این دو بخش باید مستقل از یکدیگر باشند.

## Subscription

* Upcoming Renewals
* Overdue Renewals
* Auto Renewals
* Costs by Currency
* Monthly Costs
* Yearly Costs

## Recent Activity

تغییرات اخیر سیستم.

## Quick Actions

* Add Email
* Add Account
* Add Service
* Add Phone
* Import

---

# 40. Import

سیستم باید امکان ورود اطلاعات از فایل را داشته باشد.

فرمت‌ها:

```text
CSV
TXT
```

---

# 41. CSV Import

فرآیند CSV باید شامل:

1. انتخاب فایل
2. تشخیص ستون‌ها
3. Column Mapping
4. Preview
5. Validation
6. Duplicate Detection
7. Confirmation
8. Import

باشد.

---

# 42. Duplicate Detection

هر رکورد واردشده می‌تواند:

```text
New
Exact Duplicate
Possible Duplicate
```

باشد.

برای Duplicate کاربر باید بتواند انتخاب کند:

```text
Skip
Update Existing
Create New
```

سیستم نباید بدون اطلاع کاربر اطلاعات موجود را Overwrite کند.

---

# 43. TXT Import

TXT می‌تواند ساختاریافته یا نیمه‌ساختاریافته باشد.

سیستم باید:

```text
Parse
→ Preview
→ Validate
→ Detect Duplicates
→ Confirm
→ Import
```

را انجام دهد.

---

# 44. Import Templates

برای ساختارهای رایج، Templateهای Import می‌توانند وجود داشته باشند.

اطلاعات Import مانند Source و زمان Import نیز در صورت نیاز قابل ثبت باشند.

---

# 45. Export

کاربر باید بتواند اطلاعات را Export کند.

موارد قابل Export:

* Emails
* Accounts
* Services
* Subscriptions
* Security Reports

فرمت‌ها:

```text
CSV
TXT
```

هیچ Secret واقعی نباید در Export وجود داشته باشد.

---

# 46. Archive و Delete

برای حذف اطلاعات مهم باید Confirmation وجود داشته باشد.

Archive و Delete باید از یکدیگر قابل تشخیص باشند.

حذف یک Relationship نباید Entity اصلی را حذف کند.

مثلاً:

```text
Unlink Phone
```

نباید باعث حذف Phone شود.

---

# 47. Navigation

ساختار اصلی برنامه باید شامل بخش‌هایی مانند:

```text
Dashboard
Emails
Services
Accounts
Phones
Needs Attention
Search
Import / Export
Settings
```

باشد.

---

# 48. تجربه کاربری

برنامه باید:

* ساده
* سریع
* تمیز
* حرفه‌ای
* قابل فهم
* Responsive
* مناسب Desktop
* قابل استفاده روی Mobile

باشد.

وضعیت‌ها باید از نظر بصری واضح باشند.

---

# 49. Empty States

وقتی اطلاعاتی وجود ندارد، سیستم نباید صفحه خالی نمایش دهد.

مثلاً:

```text
No accounts found.
Add your first account.
```

همراه با Action مناسب.

---

# 50. Error States

خطاها باید واضح و قابل فهم باشند.

کاربر باید بداند:

* چه اتفاقی افتاده
* چه چیزی ذخیره نشده
* چه کاری باید انجام دهد

---

# 51. Confirmation

عملیات حساس مانند:

* Delete
* Archive
* Unlink
* Update Existing در Import

باید در صورت نیاز با Confirmation انجام شوند.

---

# 52. محدودیت‌های عمدی نسخه اول

این برنامه در نسخه اول قرار نیست تبدیل به یک سیستم بسیار پیچیده شود.

موارد زیر در محدوده اصلی محصول نیستند:

* Password Manager کامل
* ذخیره Secret
* Multi-user
* سیستم پیچیده Permission
* اتوماسیون گسترده
* اتصال خودکار به تمام سرویس‌ها
* کشف خودکار Accountها
* سیستم پیچیده مالی
* اپلیکیشن مستقل موبایل
* Browser Extension

این موارد در صورت نیاز می‌توانند در آینده بررسی شوند.

---

# 53. اصول داده

اطلاعات باید تا حد ممکن دقیق و شفاف باشند.

تفاوت میان موارد زیر باید حفظ شود:

```text
Unknown
Not Set
Not Applicable
Disabled
```

سیستم نباید این وضعیت‌ها را برای ساده‌سازی با یکدیگر ترکیب کند.

---

# 54. اصول رابطه‌ای

رابطه‌ها بخش بسیار مهم محصول هستند.

کاربر باید بتواند از هر Entity به Entityهای مرتبط برسد.

مثلاً:

```text
Email
 ↓
Accounts
 ↓
Services
 ↓
Phones
```

یا:

```text
Service
 ↓
Accounts
 ↓
Emails
 ↓
Phones
```

یا:

```text
Phone
 ↓
Emails
 ↓
Accounts
 ↓
Services
```

---

# 55. سناریوی اصلی کاربر

کاربر یک Email را باز می‌کند:

```text
john@gmail.com
```

می‌بیند:

```text
Type: Work
Status: Active
Security Score: 91
Completeness: 96%
Accounts: 37
Services: 29
Paid Accounts: 8
Issues: 3
```

سپس وارد Accounts می‌شود و مثلاً می‌بیند:

```text
GitHub
AWS
OpenAI
Anthropic
Slack
...
```

کاربر می‌تواند هر Account را باز کند و ببیند:

```text
Service
Email
Username
Security
Recovery
Subscription
Payment
Credential Reference
Tags
Notes
History
```

این جریان یکی از مهم‌ترین Use Caseهای سیستم است.

---

# 56. سناریوی معکوس

کاربر یک Service را باز می‌کند:

```text
GitHub
```

و می‌بیند:

```text
Accounts: 4
Emails: 3
Paid Accounts: 1
```

سپس Accountها را مشاهده می‌کند:

```text
Account 1 → work@gmail.com
Account 2 → personal@gmail.com
Account 3 → dev@gmail.com
Account 4 → project@gmail.com
```

---

# 57. سناریوی Phone

کاربر Phone خود را باز می‌کند و می‌بیند:

```text
+31 ...
```

این Phone ممکن است به:

```text
Emails:
- work@gmail.com
- personal@gmail.com

Accounts:
- GitHub
- AWS
- Google
```

متصل باشد.

---

# 58. اصل Relationship Visibility

یکی از مهم‌ترین ویژگی‌های محصول این است که کاربر بتواند سریع بفهمد:

> این Email کجا استفاده شده؟

> این Account با چه Emailی ساخته شده؟

> این Service چه Accountهایی دارد؟

> این Phone به چه چیزهایی متصل است؟

بنابراین Relationshipها نباید در عمق زیاد مخفی شوند.

---

# 59. چهار سؤال اصلی محصول

در نهایت کل سیستم باید بتواند به چهار سؤال اصلی پاسخ دهد:

### 1. چه Emailهایی دارم؟

### 2. هر Email در چه Serviceهایی استفاده شده؟

### 3. هر Service چه Accountهایی دارد؟

### 4. کدام Accountها نیاز به توجه دارند؟

تمام قابلیت‌های دیگر باید در خدمت پاسخ سریع و دقیق به این سؤالات باشند.

---

# 60. اصل نهایی محصول

Account Manager یک سیستم برای:

**مدیریت اطلاعات Accountها و روابط بین آن‌ها**

است.

نه:

**سیستم ذخیره Password و Secret.**

اصل محصول:

> **Manage account information and relationships — not secrets.**

و از نظر تجربه کاربری:

> **Simple, clear, searchable, relationship-focused.**
