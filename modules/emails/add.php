<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();
$errors = [];
$form = [
    'email_address' => '',
    'display_name' => '',
    'provider' => '',
    'type' => 'Not Set',
    'purpose' => '',
    'status' => 'Unknown',
    'created_date' => '',
    'last_verified' => '',
    'notes' => '',
    'twofa_status' => 'Not Set',
    'twofa_method' => '',
    'passkey_status' => 'Not Set',
    'security_key_status' => 'Not Set',
    'security_questions_status' => 'Not Set',
    'last_security_check' => '',
    'recovery_email_id' => '',
    'recovery_phone_id' => '',
    'recovery_codes_status' => 'Not Set',
    'recovery_codes_reference' => '',
    'backup_method' => '',
    'last_recovery_verification' => '',
];

$otherEmails = $pdo->query('SELECT id, email_address FROM emails ORDER BY email_address')->fetchAll();
$allPhones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['email_address'] === '' || !filter_var($form['email_address'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'آدرس ایمیل معتبر وارد کنید.';
    }
    if (!array_key_exists($form['type'], EMAIL_TYPES)) {
        $errors[] = 'نوع ایمیل نامعتبر است.';
    }
    if (!array_key_exists($form['status'], EMAIL_STATUSES)) {
        $errors[] = 'وضعیت ایمیل نامعتبر است.';
    }
    foreach (['twofa_status', 'passkey_status', 'security_key_status', 'security_questions_status', 'recovery_codes_status'] as $secField) {
        if (!array_key_exists($form[$secField], SECURITY_STATES)) {
            $errors[] = 'مقدار وضعیت امنیتی نامعتبر است.';
            break;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO emails
                (email_address, display_name, provider, type, purpose, status, created_date, last_verified, notes)
                VALUES (:email_address, :display_name, :provider, :type, :purpose, :status, :created_date, :last_verified, :notes)');
            $stmt->execute([
                'email_address' => $form['email_address'],
                'display_name' => $form['display_name'] !== '' ? $form['display_name'] : null,
                'provider' => $form['provider'] !== '' ? $form['provider'] : null,
                'type' => $form['type'],
                'purpose' => $form['purpose'] !== '' ? $form['purpose'] : null,
                'status' => $form['status'],
                'created_date' => $form['created_date'] !== '' ? $form['created_date'] : null,
                'last_verified' => $form['last_verified'] !== '' ? $form['last_verified'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $emailId = (int) $pdo->lastInsertId();

            upsertEmailSecurity($pdo, $emailId, [
                'twofa_status' => $form['twofa_status'],
                'twofa_method' => $form['twofa_method'] !== '' ? $form['twofa_method'] : null,
                'passkey_status' => $form['passkey_status'],
                'security_key_status' => $form['security_key_status'],
                'security_questions_status' => $form['security_questions_status'],
                'last_security_check' => $form['last_security_check'] !== '' ? $form['last_security_check'] : null,
                'recovery_email_id' => $form['recovery_email_id'] !== '' ? (int) $form['recovery_email_id'] : null,
                'recovery_phone_id' => $form['recovery_phone_id'] !== '' ? (int) $form['recovery_phone_id'] : null,
                'recovery_codes_status' => $form['recovery_codes_status'],
                'recovery_codes_reference' => $form['recovery_codes_reference'] !== '' ? $form['recovery_codes_reference'] : null,
                'backup_method' => $form['backup_method'] !== '' ? $form['backup_method'] : null,
                'last_recovery_verification' => $form['last_recovery_verification'] !== '' ? $form['last_recovery_verification'] : null,
            ]);

            log_history($pdo, 'email', $emailId, 'Email Created');

            $pdo->commit();
            flashSet('success', 'ایمیل با موفقیت ثبت شد.');
            header('Location: view.php?id=' . $emailId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = 'این آدرس ایمیل قبلاً ثبت شده است.';
            } else {
                $errors[] = 'خطا در ثبت ایمیل: ' . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = 'افزودن ایمیل';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">افزودن ایمیل</h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">بازگشت به فهرست</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">هویت</div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">آدرس ایمیل *</label>
                <input type="email" name="email_address" class="form-control" required value="<?= e($form['email_address']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">نام نمایشی</label>
                <input type="text" name="display_name" class="form-control" value="<?= e($form['display_name']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">ارائه‌دهنده (Provider)</label>
                <input type="text" name="provider" class="form-control" value="<?= e($form['provider']) ?>" placeholder="مثلاً Gmail, Outlook">
            </div>
            <div class="col-md-6">
                <label class="form-label">هدف استفاده (Purpose)</label>
                <input type="text" name="purpose" class="form-control" value="<?= e($form['purpose']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">نوع *</label>
                <select name="type" class="form-select"><?= optionsHtml(EMAIL_TYPES, $form['type']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">وضعیت *</label>
                <select name="status" class="form-select"><?= optionsHtml(EMAIL_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">تاریخ ایجاد</label>
                <input type="date" name="created_date" class="form-control" value="<?= e($form['created_date']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">آخرین تأیید</label>
                <input type="date" name="last_verified" class="form-control" value="<?= e($form['last_verified']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">یادداشت</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">امنیت ایمیل</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">تأیید دومرحله‌ای (2FA)</label>
                <select name="twofa_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['twofa_status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label">روش 2FA</label>
                <input type="text" name="twofa_method" class="form-control" value="<?= e($form['twofa_method']) ?>" placeholder="مثلاً Authenticator App, SMS">
            </div>
            <div class="col-md-4">
                <label class="form-label">Passkey</label>
                <select name="passkey_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['passkey_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label">کلید امنیتی (Security Key)</label>
                <select name="security_key_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['security_key_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label">سوالات امنیتی</label>
                <select name="security_questions_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['security_questions_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label">آخرین بررسی امنیتی</label>
                <input type="date" name="last_security_check" class="form-control" value="<?= e($form['last_security_check']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">روش بازیابی پشتیبان (Backup Method)</label>
                <input type="text" name="backup_method" class="form-control" value="<?= e($form['backup_method']) ?>">
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">بازیابی (Recovery)</div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">ایمیل بازیابی</label>
                <select name="recovery_email_id" class="form-select">
                    <option value="">— انتخاب نشده —</option>
                    <?php foreach ($otherEmails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['recovery_email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">شماره تلفن بازیابی</label>
                <select name="recovery_phone_id" class="form-select">
                    <option value="">— انتخاب نشده —</option>
                    <?php foreach ($allPhones as $ph): ?>
                        <option value="<?= (int) $ph['id'] ?>" <?= $form['recovery_phone_id'] === (string) $ph['id'] ? 'selected' : '' ?>><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">وضعیت کدهای بازیابی</label>
                <select name="recovery_codes_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['recovery_codes_status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label">مرجع کدهای بازیابی</label>
                <input type="text" name="recovery_codes_reference" class="form-control" value="<?= e($form['recovery_codes_reference']) ?>" placeholder="مثلاً محل نگهداری، نه خود کد">
            </div>
            <div class="col-md-6">
                <label class="form-label">آخرین تأیید بازیابی</label>
                <input type="date" name="last_recovery_verification" class="form-control" value="<?= e($form['last_recovery_verification']) ?>">
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">ذخیره ایمیل</button>
    <a href="index.php" class="btn btn-outline-secondary">انصراف</a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
