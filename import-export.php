<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

$pageTitle = 'ورود / خروجی اطلاعات';
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4">ورود / خروجی اطلاعات</h1>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold">Import</div>
            <div class="card-body">
                <p class="text-muted small">
                    اطلاعات Emails، Services، Phones یا Accounts را از یک فایل CSV وارد کنید.
                    قبل از نوشتن هر تغییری، پیش‌نمایش، اعتبارسنجی و تشخیص موارد تکراری انجام می‌شود و هیچ
                    رکورد موجودی بدون تأیید صریح شما بازنویسی نخواهد شد.
                </p>
                <a href="modules/import/index.php" class="btn btn-primary">شروع Import</a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold">Export</div>
            <div class="card-body">
                <p class="text-muted small mb-0">خروجی گرفتن از Emails، Accounts، Services، Subscriptions و گزارش امنیتی در فاز بعد اضافه می‌شود.</p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
