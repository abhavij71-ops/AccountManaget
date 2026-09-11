<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$account = $id ? fetchAccountById($pdo, $id) : null;

if (!$account) {
    flashSet('danger', 'اکانت مورد نظر یافت نشد.');
    header('Location: index.php');
    exit;
}

$security = fetchAccountSecurity($pdo, $id) ?? [];
$recovery = fetchAccountRecovery($pdo, $id) ?? [];
$subscription = fetchSubscription($pdo, $id) ?? [];
$payment = fetchPayment($pdo, $id) ?? [];
$errors = [];

$form = [
    'service_id' => (string) $account['service_id'],
    'email_id' => (string) $account['email_id'],
    'username' => (string) ($account['username'] ?? ''),
    'display_name' => (string) ($account['display_name'] ?? ''),
    'external_account_id' => (string) ($account['external_account_id'] ?? ''),
    'account_url' => (string) ($account['account_url'] ?? ''),
    'login_url' => (string) ($account['login_url'] ?? ''),
    'status' => $account['status'],
    'account_type' => $account['account_type'],
    'created_date' => (string) ($account['created_date'] ?? ''),
    'last_login' => (string) ($account['last_login'] ?? ''),
    'last_verified' => (string) ($account['last_verified'] ?? ''),
    'notes' => (string) ($account['notes'] ?? ''),
    'twofa_status' => $security['twofa_status'] ?? 'Not Set',
    'twofa_method' => (string) ($security['twofa_method'] ?? ''),
    'passkey_status' => $security['passkey_status'] ?? 'Not Set',
    'security_key_status' => $security['security_key_status'] ?? 'Not Set',
    'security_questions_status' => $security['security_questions_status'] ?? 'Not Set',
    'last_security_check' => (string) ($security['last_security_check'] ?? ''),
    'credential_storage' => (string) ($security['credential_storage'] ?? ''),
    'credential_reference' => (string) ($security['credential_reference'] ?? ''),
    'recovery_status' => $recovery['status'] ?? 'Not Set',
    'recovery_email_id' => (string) ($recovery['recovery_email_id'] ?? ''),
    'recovery_phone_id' => (string) ($recovery['recovery_phone_id'] ?? ''),
    'recovery_contact' => (string) ($recovery['recovery_contact'] ?? ''),
    'recovery_codes_status' => $recovery['recovery_codes_status'] ?? 'Not Set',
    'recovery_codes_reference' => (string) ($recovery['recovery_codes_reference'] ?? ''),
    'backup_method' => (string) ($recovery['backup_method'] ?? ''),
    'last_recovery_verification' => (string) ($recovery['last_recovery_verification'] ?? ''),
    'recovery_notes' => (string) ($recovery['recovery_notes'] ?? ''),
    'sub_type' => $subscription['type'] ?? 'Unknown',
    'sub_plan' => (string) ($subscription['plan'] ?? ''),
    'sub_status' => $subscription['status'] ?? 'Unknown',
    'sub_price' => isset($subscription['price']) ? (string) $subscription['price'] : '',
    'sub_currency' => (string) ($subscription['currency'] ?? ''),
    'sub_billing_cycle' => $subscription['billing_cycle'] ?? 'Not Applicable',
    'sub_start_date' => (string) ($subscription['start_date'] ?? ''),
    'sub_renewal_date' => (string) ($subscription['renewal_date'] ?? ''),
    'sub_auto_renewal' => isset($subscription['auto_renewal']) && $subscription['auto_renewal'] !== null ? (string) (int) $subscription['auto_renewal'] : '',
    'pay_required' => isset($payment['payment_required']) && (int) $payment['payment_required'] === 1 ? '1' : '',
    'pay_method' => (string) ($payment['payment_method'] ?? ''),
    'pay_card_brand' => (string) ($payment['card_brand'] ?? ''),
    'pay_last4' => (string) ($payment['last4'] ?? ''),
    'pay_reference' => (string) ($payment['payment_reference'] ?? ''),
    'pay_auto_renewal' => isset($payment['auto_renewal']) && $payment['auto_renewal'] !== null ? (string) (int) $payment['auto_renewal'] : '',
];

$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address FROM emails ORDER BY email_address')->fetchAll();
$phones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'pay_required') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $form['pay_required'] = isset($_POST['pay_required']) ? '1' : '';

    $serviceId = (int) $form['service_id'];
    $emailId = (int) $form['email_id'];

    if (!in_array($serviceId, array_column($services, 'id'), true)) {
        $errors[] = 'سرویس معتبر انتخاب کنید.';
    }
    if (!in_array($emailId, array_column($emails, 'id'), true)) {
        $errors[] = 'ایمیل معتبر انتخاب کنید.';
    }
    if (!array_key_exists($form['status'], ACCOUNT_STATUSES)) {
        $errors[] = 'وضعیت اکانت نامعتبر است.';
    }
    if (!array_key_exists($form['account_type'], ACCOUNT_TYPES)) {
        $errors[] = 'نوع اکانت نامعتبر است.';
    }
    foreach (['twofa_status', 'passkey_status', 'security_key_status', 'security_questions_status'] as $f) {
        if (!array_key_exists($form[$f], SECURITY_STATES)) {
            $errors[] = 'مقدار وضعیت امنیتی نامعتبر است.';
            break;
        }
    }
    if (!array_key_exists($form['recovery_status'], RECOVERY_STATUSES)) {
        $errors[] = 'وضعیت بازیابی نامعتبر است.';
    }
    if (!array_key_exists($form['recovery_codes_status'], SECURITY_STATES)) {
        $errors[] = 'وضعیت کدهای بازیابی نامعتبر است.';
    }
    if (!array_key_exists($form['sub_type'], SUBSCRIPTION_TYPES)) {
        $errors[] = 'نوع Subscription نامعتبر است.';
    }
    if (!array_key_exists($form['sub_status'], SUBSCRIPTION_STATUSES)) {
        $errors[] = 'وضعیت Subscription نامعتبر است.';
    }
    if (!array_key_exists($form['sub_billing_cycle'], BILLING_CYCLES)) {
        $errors[] = 'دوره صورتحساب نامعتبر است.';
    }
    if ($form['sub_price'] !== '' && !is_numeric($form['sub_price'])) {
        $errors[] = 'قیمت باید عدد باشد.';
    }
    if ($form['pay_last4'] !== '' && !preg_match('/^\d{4}$/', $form['pay_last4'])) {
        $errors[] = '۴ رقم آخر کارت باید دقیقاً ۴ رقم باشد — هرگز شماره کامل کارت را وارد نکنید.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($form['status'] !== $account['status']) {
                log_history($pdo, 'account', $id, 'Status Changed', 'status', $account['status'], $form['status']);
            }
            if ($emailId !== (int) $account['email_id']) {
                log_history($pdo, 'account', $id, 'Email Unlinked', 'email_id', $account['email_address'], null);
                $newEmailAddr = $emails[array_search($emailId, array_column($emails, 'id'), true)]['email_address'] ?? (string) $emailId;
                log_history($pdo, 'account', $id, 'Email Linked', 'email_id', null, $newEmailAddr);
            }
            $baseDiffFields = [
                'username', 'display_name', 'external_account_id', 'account_url', 'login_url',
                'account_type', 'created_date', 'last_login', 'last_verified', 'notes',
            ];
            foreach ($baseDiffFields as $f) {
                $old = (string) ($account[$f] ?? '');
                if ($old !== $form[$f]) {
                    log_history($pdo, 'account', $id, 'Account Updated', $f, $old !== '' ? $old : null, $form[$f] !== '' ? $form[$f] : null);
                }
            }
            $oldTwofa = $security['twofa_status'] ?? 'Not Set';
            if ($oldTwofa !== $form['twofa_status']) {
                log_history($pdo, 'account', $id, '2FA Changed', 'twofa_status', $oldTwofa, $form['twofa_status']);
            }

            $subDiffFields = ['sub_type' => 'type', 'sub_status' => 'status', 'sub_price' => 'price', 'sub_billing_cycle' => 'billing_cycle', 'sub_renewal_date' => 'renewal_date'];
            foreach ($subDiffFields as $formKey => $label) {
                $old = match ($formKey) {
                    'sub_type' => $subscription['type'] ?? 'Unknown',
                    'sub_status' => $subscription['status'] ?? 'Unknown',
                    'sub_price' => isset($subscription['price']) ? (string) $subscription['price'] : '',
                    'sub_billing_cycle' => $subscription['billing_cycle'] ?? 'Not Applicable',
                    'sub_renewal_date' => (string) ($subscription['renewal_date'] ?? ''),
                };
                if ((string) $old !== $form[$formKey]) {
                    log_history($pdo, 'account', $id, 'Subscription Changed', $label, $old !== '' ? (string) $old : null, $form[$formKey] !== '' ? $form[$formKey] : null);
                }
            }

            $stmt = $pdo->prepare('UPDATE accounts SET
                service_id = :service_id, email_id = :email_id, username = :username, display_name = :display_name,
                external_account_id = :external_account_id, account_url = :account_url, login_url = :login_url,
                status = :status, account_type = :account_type, created_date = :created_date,
                last_login = :last_login, last_verified = :last_verified, notes = :notes
                WHERE id = :id');
            $stmt->execute([
                'service_id' => $serviceId,
                'email_id' => $emailId,
                'username' => $form['username'] !== '' ? $form['username'] : null,
                'display_name' => $form['display_name'] !== '' ? $form['display_name'] : null,
                'external_account_id' => $form['external_account_id'] !== '' ? $form['external_account_id'] : null,
                'account_url' => $form['account_url'] !== '' ? $form['account_url'] : null,
                'login_url' => $form['login_url'] !== '' ? $form['login_url'] : null,
                'status' => $form['status'],
                'account_type' => $form['account_type'],
                'created_date' => $form['created_date'] !== '' ? $form['created_date'] : null,
                'last_login' => $form['last_login'] !== '' ? $form['last_login'] : null,
                'last_verified' => $form['last_verified'] !== '' ? $form['last_verified'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'id' => $id,
            ]);

            upsertAccountSecurity($pdo, $id, [
                'twofa_status' => $form['twofa_status'],
                'twofa_method' => $form['twofa_method'] !== '' ? $form['twofa_method'] : null,
                'passkey_status' => $form['passkey_status'],
                'security_key_status' => $form['security_key_status'],
                'security_questions_status' => $form['security_questions_status'],
                'last_security_check' => $form['last_security_check'] !== '' ? $form['last_security_check'] : null,
                'credential_storage' => $form['credential_storage'] !== '' ? $form['credential_storage'] : null,
                'credential_reference' => $form['credential_reference'] !== '' ? $form['credential_reference'] : null,
            ]);

            upsertAccountRecovery($pdo, $id, [
                'status' => $form['recovery_status'],
                'recovery_email_id' => $form['recovery_email_id'] !== '' ? (int) $form['recovery_email_id'] : null,
                'recovery_phone_id' => $form['recovery_phone_id'] !== '' ? (int) $form['recovery_phone_id'] : null,
                'recovery_contact' => $form['recovery_contact'] !== '' ? $form['recovery_contact'] : null,
                'recovery_codes_status' => $form['recovery_codes_status'],
                'recovery_codes_reference' => $form['recovery_codes_reference'] !== '' ? $form['recovery_codes_reference'] : null,
                'backup_method' => $form['backup_method'] !== '' ? $form['backup_method'] : null,
                'last_recovery_verification' => $form['last_recovery_verification'] !== '' ? $form['last_recovery_verification'] : null,
                'recovery_notes' => $form['recovery_notes'] !== '' ? $form['recovery_notes'] : null,
            ]);

            upsertSubscription($pdo, $id, [
                'type' => $form['sub_type'],
                'plan' => $form['sub_plan'] !== '' ? $form['sub_plan'] : null,
                'status' => $form['sub_status'],
                'price' => $form['sub_price'] !== '' ? (float) $form['sub_price'] : null,
                'currency' => $form['sub_currency'] !== '' ? strtoupper($form['sub_currency']) : null,
                'billing_cycle' => $form['sub_billing_cycle'],
                'start_date' => $form['sub_start_date'] !== '' ? $form['sub_start_date'] : null,
                'renewal_date' => $form['sub_renewal_date'] !== '' ? $form['sub_renewal_date'] : null,
                'auto_renewal' => $form['sub_auto_renewal'] !== '' ? (int) $form['sub_auto_renewal'] : null,
            ]);

            upsertPayment($pdo, $id, [
                'payment_required' => $form['pay_required'] === '1' ? 1 : 0,
                'payment_method' => $form['pay_method'] !== '' ? $form['pay_method'] : null,
                'card_brand' => $form['pay_card_brand'] !== '' ? $form['pay_card_brand'] : null,
                'last4' => $form['pay_last4'] !== '' ? $form['pay_last4'] : null,
                'payment_reference' => $form['pay_reference'] !== '' ? $form['pay_reference'] : null,
                'auto_renewal' => $form['pay_auto_renewal'] !== '' ? (int) $form['pay_auto_renewal'] : null,
            ]);

            $pdo->commit();
            flashSet('success', 'تغییرات با موفقیت ذخیره شد.');
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'خطا در ذخیره تغییرات: ' . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
$pageTitle = 'ویرایش اکانت';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">ویرایش اکانت: <?= e($account['service_name']) ?> — <?= e($account['email_address']) ?></h1>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary btn-sm">بازگشت به پروفایل</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">اطلاعات پایه</div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">سرویس *</label>
                <select name="service_id" class="form-select" required>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $form['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">ایمیل *</label>
                <select name="email_id" class="form-select" required>
                    <?php foreach ($emails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">نام کاربری</label>
                <input type="text" name="username" class="form-control" value="<?= e($form['username']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">نام نمایشی</label>
                <input type="text" name="display_name" class="form-control" value="<?= e($form['display_name']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">شناسه اکانت (Account ID)</label>
                <input type="text" name="external_account_id" class="form-control" value="<?= e($form['external_account_id']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">آدرس اکانت (Account URL)</label>
                <input type="url" name="account_url" class="form-control" value="<?= e($form['account_url']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">آدرس ورود (Login URL)</label>
                <input type="url" name="login_url" class="form-control" value="<?= e($form['login_url']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">وضعیت *</label>
                <select name="status" class="form-select"><?= optionsHtml(ACCOUNT_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">نوع اکانت *</label>
                <select name="account_type" class="form-select"><?= optionsHtml(ACCOUNT_TYPES, $form['account_type']) ?></select>
            </div>
            <div class="col-md-2">
                <label class="form-label">تاریخ ایجاد</label>
                <input type="date" name="created_date" class="form-control" value="<?= e($form['created_date']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">آخرین ورود</label>
                <input type="date" name="last_login" class="form-control" value="<?= e($form['last_login']) ?>">
            </div>
            <div class="col-md-2">
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
        <div class="card-header bg-white fw-bold">امنیت اکانت</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">تأیید دومرحله‌ای (2FA)</label>
                <select name="twofa_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['twofa_status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label">روش 2FA</label>
                <input type="text" name="twofa_method" class="form-control" value="<?= e($form['twofa_method']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Passkey</label>
                <select name="passkey_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['passkey_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label">کلید امنیتی</label>
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
                <label class="form-label">محل نگهداری Credential</label>
                <input type="text" name="credential_storage" class="form-control" value="<?= e($form['credential_storage']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">مرجع Credential</label>
                <input type="text" name="credential_reference" class="form-control" value="<?= e($form['credential_reference']) ?>">
            </div>
            <div class="col-12">
                <p class="text-muted small mb-0">این سیستم Password Manager نیست — فقط محل نگهداری Credential را ثبت کنید، نه خود رمز عبور، API Key یا کد بازیابی واقعی.</p>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">بازیابی (Recovery)</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">وضعیت بازیابی</label>
                <select name="recovery_status" class="form-select"><?= optionsHtml(RECOVERY_STATUSES, $form['recovery_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label">ایمیل بازیابی</label>
                <select name="recovery_email_id" class="form-select">
                    <option value="">— انتخاب نشده —</option>
                    <?php foreach ($emails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['recovery_email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">تلفن بازیابی</label>
                <select name="recovery_phone_id" class="form-select">
                    <option value="">— انتخاب نشده —</option>
                    <?php foreach ($phones as $ph): ?>
                        <option value="<?= (int) $ph['id'] ?>" <?= $form['recovery_phone_id'] === (string) $ph['id'] ? 'selected' : '' ?>><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">مخاطب بازیابی (Recovery Contact)</label>
                <input type="text" name="recovery_contact" class="form-control" value="<?= e($form['recovery_contact']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">وضعیت کدهای بازیابی</label>
                <select name="recovery_codes_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['recovery_codes_status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">مرجع کدهای بازیابی</label>
                <input type="text" name="recovery_codes_reference" class="form-control" value="<?= e($form['recovery_codes_reference']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">روش پشتیبان (Backup Method)</label>
                <input type="text" name="backup_method" class="form-control" value="<?= e($form['backup_method']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">آخرین تأیید بازیابی</label>
                <input type="date" name="last_recovery_verification" class="form-control" value="<?= e($form['last_recovery_verification']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">یادداشت بازیابی</label>
                <textarea name="recovery_notes" class="form-control" rows="2"><?= e($form['recovery_notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">Subscription</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">نوع *</label>
                <select name="sub_type" id="sub_type" class="form-select"><?= optionsHtml(SUBSCRIPTION_TYPES, $form['sub_type']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label">پلن (Plan)</label>
                <input type="text" name="sub_plan" class="form-control" value="<?= e($form['sub_plan']) ?>" placeholder="مثلاً Pro, Team">
            </div>
            <div class="col-md-4">
                <label class="form-label">وضعیت *</label>
                <select name="sub_status" class="form-select"><?= optionsHtml(SUBSCRIPTION_STATUSES, $form['sub_status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">قیمت</label>
                <input type="text" inputmode="decimal" name="sub_price" class="form-control" value="<?= e($form['sub_price']) ?>" placeholder="مثلاً 9.99">
            </div>
            <div class="col-md-3">
                <label class="form-label">ارز (Currency)</label>
                <input type="text" name="sub_currency" class="form-control" value="<?= e($form['sub_currency']) ?>" maxlength="8" placeholder="مثلاً USD">
            </div>
            <div class="col-md-3">
                <label class="form-label">دوره صورتحساب *</label>
                <select name="sub_billing_cycle" class="form-select"><?= optionsHtml(BILLING_CYCLES, $form['sub_billing_cycle']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">تمدید خودکار</label>
                <select name="sub_auto_renewal" class="form-select">
                    <option value="" <?= $form['sub_auto_renewal'] === '' ? 'selected' : '' ?>>نامشخص</option>
                    <option value="1" <?= $form['sub_auto_renewal'] === '1' ? 'selected' : '' ?>>بله</option>
                    <option value="0" <?= $form['sub_auto_renewal'] === '0' ? 'selected' : '' ?>>خیر</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">تاریخ شروع</label>
                <input type="date" name="sub_start_date" class="form-control" value="<?= e($form['sub_start_date']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">تاریخ تمدید (Renewal Date)</label>
                <input type="date" name="sub_renewal_date" class="form-control" value="<?= e($form['sub_renewal_date']) ?>">
            </div>
            <div class="col-12">
                <p class="text-muted small mb-0">
                    هزینه‌های ارزهای مختلف هرگز با هم جمع نمی‌شوند — هر ارز جداگانه گزارش می‌شود.
                </p>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">Payment</div>
        <div class="card-body">
            <p class="text-muted small">شماره کامل کارت یا CVV هرگز نباید ذخیره شود — فقط ۴ رقم آخر.</p>
            <p id="payment-free-note" class="text-muted small fst-italic" style="display:none;">
                این Subscription رایگان است — نیازی به اطلاعات پرداخت نیست.
            </p>
            <div id="payment-fields" class="row g-3">
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="pay_required" id="pay_required" class="form-check-input" value="1" <?= $form['pay_required'] === '1' ? 'checked' : '' ?>>
                        <label for="pay_required" class="form-check-label">پرداخت لازم است</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">روش پرداخت</label>
                    <input type="text" name="pay_method" class="form-control" value="<?= e($form['pay_method']) ?>" placeholder="مثلاً Credit Card">
                </div>
                <div class="col-md-3">
                    <label class="form-label">برند کارت</label>
                    <input type="text" name="pay_card_brand" class="form-control" value="<?= e($form['pay_card_brand']) ?>" placeholder="مثلاً Visa">
                </div>
                <div class="col-md-3">
                    <label class="form-label">۴ رقم آخر کارت</label>
                    <input type="text" inputmode="numeric" maxlength="4" pattern="\d{4}" name="pay_last4" class="form-control" value="<?= e($form['pay_last4']) ?>" placeholder="۴ رقم">
                </div>
                <div class="col-md-6">
                    <label class="form-label">مرجع پرداخت (Payment Reference)</label>
                    <input type="text" name="pay_reference" class="form-control" value="<?= e($form['pay_reference']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">تمدید خودکار پرداخت</label>
                    <select name="pay_auto_renewal" class="form-select">
                        <option value="" <?= $form['pay_auto_renewal'] === '' ? 'selected' : '' ?>>نامشخص</option>
                        <option value="1" <?= $form['pay_auto_renewal'] === '1' ? 'selected' : '' ?>>بله</option>
                        <option value="0" <?= $form['pay_auto_renewal'] === '0' ? 'selected' : '' ?>>خیر</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">انصراف</a>
</form>

<script>
(function () {
    var typeSelect = document.getElementById('sub_type');
    var paymentFields = document.getElementById('payment-fields');
    var freeNote = document.getElementById('payment-free-note');
    if (!typeSelect || !paymentFields || !freeNote) {
        return;
    }
    function update() {
        var isFree = typeSelect.value === 'Free';
        paymentFields.style.display = isFree ? 'none' : '';
        freeNote.style.display = isFree ? '' : 'none';
    }
    typeSelect.addEventListener('change', update);
    update();
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
