<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();

$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address FROM emails ORDER BY email_address')->fetchAll();

$errors = [];
$form = [
    'service_ids' => [],
    'email_ids' => [],
    'username' => '',
    'status' => 'Active',
    'account_type' => 'Not Set',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    $validServiceIds = array_column($services, 'id');
    $validEmailIds = array_column($emails, 'id');

    $postedServiceIds = array_map('intval', is_array($_POST['service_ids'] ?? null) ? $_POST['service_ids'] : []);
    $postedEmailIds = array_map('intval', is_array($_POST['email_ids'] ?? null) ? $_POST['email_ids'] : []);

    $form['service_ids'] = array_values(array_intersect($postedServiceIds, $validServiceIds));
    $form['email_ids'] = array_values(array_intersect($postedEmailIds, $validEmailIds));
    $form['username'] = trim((string) ($_POST['username'] ?? ''));
    $form['status'] = (string) ($_POST['status'] ?? 'Active');
    $form['account_type'] = (string) ($_POST['account_type'] ?? 'Not Set');

    if (!$form['service_ids']) {
        $errors[] = 'حداقل یک سرویس انتخاب کنید.';
    }
    if (!$form['email_ids']) {
        $errors[] = 'حداقل یک ایمیل انتخاب کنید.';
    }
    if (!array_key_exists($form['status'], ACCOUNT_STATUSES)) {
        $errors[] = 'وضعیت انتخاب‌شده نامعتبر است.';
    }
    if (!array_key_exists($form['account_type'], ACCOUNT_TYPES)) {
        $errors[] = 'نوع اکانت انتخاب‌شده نامعتبر است.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $checkStmt = $pdo->prepare('SELECT id FROM accounts WHERE service_id = ? AND email_id = ? LIMIT 1');
            $insertStmt = $pdo->prepare('INSERT INTO accounts (service_id, email_id, username, status, account_type)
                VALUES (:service_id, :email_id, :username, :status, :account_type)');

            $created = 0;
            $skipped = 0;

            foreach ($form['service_ids'] as $serviceId) {
                foreach ($form['email_ids'] as $emailId) {
                    $checkStmt->execute([$serviceId, $emailId]);
                    if ($checkStmt->fetchColumn()) {
                        $skipped++;
                        continue;
                    }

                    $insertStmt->execute([
                        'service_id' => $serviceId,
                        'email_id' => $emailId,
                        'username' => $form['username'] !== '' ? $form['username'] : null,
                        'status' => $form['status'],
                        'account_type' => $form['account_type'],
                    ]);
                    $accountId = (int) $pdo->lastInsertId();
                    log_history($pdo, 'account', $accountId, 'Account Created');
                    $created++;
                }
            }

            $pdo->commit();

            $message = $created . ' اکانت جدید ساخته شد.';
            if ($skipped > 0) {
                $message .= ' ' . $skipped . ' مورد به دلیل وجود قبلی رد شد.';
            }
            flashSet($created > 0 ? 'success' : 'warning', $message);
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'خطا در ساخت اکانت‌ها: ' . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
$pageTitle = 'تخصیص گروهی سرویس به ایمیل‌ها';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">تخصیص گروهی سرویس به ایمیل‌ها</h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">بازگشت به فهرست</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$services || !$emails): ?>
    <div class="alert alert-warning">
        برای تخصیص گروهی ابتدا باید حداقل یک سرویس و یک ایمیل ثبت شده باشد.
        <a href="../services/add.php">افزودن سرویس</a> — <a href="../emails/add.php">افزودن ایمیل</a>
    </div>
<?php else: ?>
    <p class="text-muted">یک یا چند سرویس و یک یا چند ایمیل انتخاب کنید. برای هر ترکیب سرویس×ایمیل که هنوز اکانتی برایش ثبت نشده، یک اکانت جدید ساخته می‌شود؛ ترکیب‌هایی که از قبل اکانت دارند رد می‌شوند.</p>

    <form method="post" id="bulk-assign-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card am-card mb-3">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span>سرویس‌ها</span>
                        <div class="form-check mb-0">
                            <input type="checkbox" class="form-check-input" id="select-all-services">
                            <label class="form-check-label small" for="select-all-services">انتخاب همه</label>
                        </div>
                    </div>
                    <div class="card-body" style="max-height:340px; overflow-y:auto;">
                        <?php foreach ($services as $s): ?>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input am-service-checkbox" name="service_ids[]" value="<?= (int) $s['id'] ?>" id="svc-<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $form['service_ids'], true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="svc-<?= (int) $s['id'] ?>"><?= e($s['service_name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card am-card mb-3">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span>ایمیل‌ها</span>
                        <div class="form-check mb-0">
                            <input type="checkbox" class="form-check-input" id="select-all-emails">
                            <label class="form-check-label small" for="select-all-emails">انتخاب همه</label>
                        </div>
                    </div>
                    <div class="card-body" style="max-height:340px; overflow-y:auto;">
                        <?php foreach ($emails as $em): ?>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input am-email-checkbox" name="email_ids[]" value="<?= (int) $em['id'] ?>" id="eml-<?= (int) $em['id'] ?>" <?= in_array((int) $em['id'], $form['email_ids'], true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="eml-<?= (int) $em['id'] ?>"><?= e($em['email_address']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">مقادیر پیش‌فرض اکانت‌های جدید</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">وضعیت</label>
                    <select name="status" class="form-select"><?= optionsHtml(ACCOUNT_STATUSES, $form['status']) ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">نوع اکانت</label>
                    <select name="account_type" class="form-select"><?= optionsHtml(ACCOUNT_TYPES, $form['account_type']) ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">نام کاربری مشترک (اختیاری)</label>
                    <input type="text" name="username" class="form-control" value="<?= e($form['username']) ?>">
                    <div class="form-text">در صورت پر بودن، روی همهٔ اکانت‌های جدید اعمال می‌شود.</div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-primary">ساخت اکانت‌ها</button>
            <a href="index.php" class="btn btn-outline-secondary">انصراف</a>
            <span id="bulk-assign-count" class="text-muted small"></span>
        </div>
    </form>

    <script>
    (function () {
        var serviceBoxes = document.querySelectorAll('.am-service-checkbox');
        var emailBoxes = document.querySelectorAll('.am-email-checkbox');
        var selectAllServices = document.getElementById('select-all-services');
        var selectAllEmails = document.getElementById('select-all-emails');
        var counter = document.getElementById('bulk-assign-count');

        function countChecked(list) {
            return Array.prototype.filter.call(list, function (cb) { return cb.checked; }).length;
        }

        function updateCounter() {
            var s = countChecked(serviceBoxes);
            var e = countChecked(emailBoxes);
            counter.textContent = (s && e) ? ('حداکثر ' + (s * e) + ' اکانت ساخته می‌شود (' + s + ' سرویس × ' + e + ' ایمیل)') : '';
        }

        function wireSelectAll(selectAll, boxes) {
            if (!selectAll) { return; }
            selectAll.addEventListener('change', function () {
                boxes.forEach(function (cb) { cb.checked = selectAll.checked; });
                updateCounter();
            });
        }

        wireSelectAll(selectAllServices, serviceBoxes);
        wireSelectAll(selectAllEmails, emailBoxes);
        serviceBoxes.forEach(function (cb) { cb.addEventListener('change', updateCounter); });
        emailBoxes.forEach(function (cb) { cb.addEventListener('change', updateCounter); });
        updateCounter();
    })();
    </script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
