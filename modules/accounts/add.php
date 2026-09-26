<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../../includes/plans.php';

requireLogin();

$pdo = db();
$errors = [];
$planLimitReached = false;
$form = [
    'service_id' => '',
    'identity_type' => 'email',
    'email_id' => '',
    'identity_phone_id' => '',
    'identity_value' => '',
    'username' => '',
    'display_name' => '',
    'external_account_id' => '',
    'account_url' => '',
    'login_url' => '',
    'status' => 'Unknown',
    'account_type' => 'Not Set',
    'created_date' => '',
    'last_login' => '',
    'last_verified' => '',
    'notes' => '',
    'twofa_status' => 'Not Set',
    'twofa_method' => '',
    'passkey_status' => 'Not Set',
    'security_key_status' => 'Not Set',
    'security_questions_status' => 'Not Set',
    'last_security_check' => '',
    'credential_storage' => '',
    'credential_reference' => '',
    'recovery_status' => 'Not Set',
    'recovery_email_id' => '',
    'recovery_phone_id' => '',
    'recovery_contact' => '',
    'recovery_codes_status' => 'Not Set',
    'recovery_codes_reference' => '',
    'backup_method' => '',
    'last_recovery_verification' => '',
    'recovery_notes' => '',
    'sub_type' => 'Unknown',
    'sub_plan' => '',
    'sub_status' => 'Unknown',
    'sub_price' => '',
    'sub_currency' => '',
    'sub_billing_cycle' => 'Not Applicable',
    'sub_start_date' => '',
    'sub_renewal_date' => '',
    'sub_auto_renewal' => '',
    'pay_required' => '',
    'pay_method' => '',
    'pay_card_brand' => '',
    'pay_last4' => '',
    'pay_reference' => '',
    'pay_auto_renewal' => '',
];

$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address FROM emails ORDER BY email_address')->fetchAll();
$phones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
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
    $identityPhoneId = (int) $form['identity_phone_id'];

    if (!in_array($serviceId, array_column($services, 'id'), true)) {
        $errors[] = t('accounts.service_required');
    }
    if (!in_array($form['identity_type'], ['email', 'phone', 'username', 'other'], true)) {
        $errors[] = t('accounts.identity_type_invalid');
    } elseif ($form['identity_type'] === 'email') {
        if (!in_array($emailId, array_column($emails, 'id'), true)) {
            $errors[] = t('accounts.email_required');
        }
    } elseif ($form['identity_type'] === 'phone') {
        if (!in_array($identityPhoneId, array_column($phones, 'id'), true)) {
            $errors[] = t('accounts.identity_phone_required');
        }
    } elseif ($form['identity_type'] === 'username' && $form['username'] === '') {
        $errors[] = t('accounts.identity_username_required');
    }
    if (!array_key_exists($form['status'], ACCOUNT_STATUSES)) {
        $errors[] = t('accounts.status_invalid');
    }
    if (!array_key_exists($form['account_type'], ACCOUNT_TYPES)) {
        $errors[] = t('accounts.type_invalid');
    }
    foreach (['twofa_status', 'passkey_status', 'security_key_status', 'security_questions_status'] as $f) {
        if (!array_key_exists($form[$f], SECURITY_STATES)) {
            $errors[] = t('msg.invalid_security_status');
            break;
        }
    }
    if (!array_key_exists($form['recovery_status'], RECOVERY_STATUSES)) {
        $errors[] = t('accounts.recovery_status_invalid');
    }
    if (!array_key_exists($form['recovery_codes_status'], SECURITY_STATES)) {
        $errors[] = t('accounts.recovery_codes_status_invalid');
    }
    if (!array_key_exists($form['sub_type'], SUBSCRIPTION_TYPES)) {
        $errors[] = t('accounts.sub_type_invalid');
    }
    if (!array_key_exists($form['sub_status'], SUBSCRIPTION_STATUSES)) {
        $errors[] = t('accounts.sub_status_invalid');
    }
    if (!array_key_exists($form['sub_billing_cycle'], BILLING_CYCLES)) {
        $errors[] = t('accounts.billing_cycle_invalid');
    }
    if ($form['sub_price'] !== '' && !is_numeric($form['sub_price'])) {
        $errors[] = t('accounts.price_must_be_numeric');
    }
    if ($form['pay_last4'] !== '' && !preg_match('/^\d{4}$/', $form['pay_last4'])) {
        $errors[] = t('accounts.last4_invalid');
    }

    if (!$errors && !checkPlanLimit('accounts', null, $pdo)) {
        $planLimitReached = true;
    }

    if (!$errors && !$planLimitReached) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO accounts
                (service_id, email_id, identity_type, identity_phone_id, identity_value, username, display_name, external_account_id, account_url, login_url,
                 status, account_type, created_date, last_login, last_verified, notes)
                VALUES (:service_id, :email_id, :identity_type, :identity_phone_id, :identity_value, :username, :display_name, :external_account_id, :account_url, :login_url,
                 :status, :account_type, :created_date, :last_login, :last_verified, :notes)');
            $stmt->execute([
                'service_id' => $serviceId,
                'email_id' => $emailId !== 0 ? $emailId : null,
                'identity_type' => $form['identity_type'],
                'identity_phone_id' => $identityPhoneId !== 0 ? $identityPhoneId : null,
                'identity_value' => $form['identity_value'] !== '' ? $form['identity_value'] : null,
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
            ]);
            $accountId = (int) $pdo->lastInsertId();

            upsertAccountSecurity($pdo, $accountId, [
                'twofa_status' => $form['twofa_status'],
                'twofa_method' => $form['twofa_method'] !== '' ? $form['twofa_method'] : null,
                'passkey_status' => $form['passkey_status'],
                'security_key_status' => $form['security_key_status'],
                'security_questions_status' => $form['security_questions_status'],
                'last_security_check' => $form['last_security_check'] !== '' ? $form['last_security_check'] : null,
                'credential_storage' => $form['credential_storage'] !== '' ? $form['credential_storage'] : null,
                'credential_reference' => $form['credential_reference'] !== '' ? $form['credential_reference'] : null,
            ]);

            upsertAccountRecovery($pdo, $accountId, [
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

            upsertSubscription($pdo, $accountId, [
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

            upsertPayment($pdo, $accountId, [
                'payment_required' => $form['pay_required'] === '1' ? 1 : 0,
                'payment_method' => $form['pay_method'] !== '' ? $form['pay_method'] : null,
                'card_brand' => $form['pay_card_brand'] !== '' ? $form['pay_card_brand'] : null,
                'last4' => $form['pay_last4'] !== '' ? $form['pay_last4'] : null,
                'payment_reference' => $form['pay_reference'] !== '' ? $form['pay_reference'] : null,
                'auto_renewal' => $form['pay_auto_renewal'] !== '' ? (int) $form['pay_auto_renewal'] : null,
            ]);

            log_history($pdo, 'account', $accountId, 'Account Created');

            $pdo->commit();
            flashSet('success', t('accounts.created_success'));
            header('Location: view.php?id=' . $accountId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = t('accounts.create_error') . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('accounts.add_title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('accounts.add_heading')) ?></h1>
    <div class="d-flex gap-2">
        <a href="quick-add.php" class="btn btn-outline-secondary btn-sm"><?= e(t('accounts.quick_add_plain')) ?></a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($planLimitReached): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= e(t('plans.limit_accounts_reached')) ?></span>
        <a href="<?= e(appUrl('plans.php')) ?>" class="btn btn-sm btn-primary"><?= e(t('plans.upgrade_button')) ?></a>
    </div>
<?php endif; ?>

<?php if (!$services || !$emails): ?>
    <div class="alert alert-warning">
        <?= e(t('accounts.need_service_and_email')) ?>
        <a href="../services/add.php"><?= e(t('services.add_title')) ?></a> — <a href="../emails/add.php"><?= e(t('emails.add_title')) ?></a>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('common.basic_info')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_identity_type_required')) ?> <span id="inherited_identity_type" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="identity_type" id="identity_type" class="form-select" required>
                    <option value="email" <?= $form['identity_type'] === 'email' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_email')) ?></option>
                    <option value="phone" <?= $form['identity_type'] === 'phone' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_phone')) ?></option>
                    <option value="username" <?= $form['identity_type'] === 'username' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_username')) ?></option>
                    <option value="other" <?= $form['identity_type'] === 'other' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_other')) ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_service_required')) ?></label>
                <div class="input-group">
                    <select name="service_id" id="service_id" class="form-select" required>
                        <option value=""><?= e(t('common.select_placeholder')) ?></option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= $form['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="new-service-btn">+ New</button>
                </div>
                <div id="new-service-panel" class="input-group input-group-sm mt-2" style="display:none;">
                    <input type="text" id="new-service-name" class="form-control" placeholder="Service name">
                    <button type="button" class="btn btn-primary" id="new-service-save"><?= e(t('common.add')) ?></button>
                    <button type="button" class="btn btn-outline-secondary" id="new-service-cancel"><?= e(t('common.cancel')) ?></button>
                </div>
                <div id="new-service-error" class="text-danger small mt-1"></div>
            </div>
            <div class="col-md-4" id="identity-email-group">
                <label class="form-label"><?= e(t('accounts.field_email_required')) ?></label>
                <div class="input-group">
                    <select name="email_id" id="email_id" class="form-select">
                        <option value=""><?= e(t('common.select_placeholder')) ?></option>
                        <?php foreach ($emails as $em): ?>
                            <option value="<?= (int) $em['id'] ?>" <?= $form['email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="new-email-btn">+ New</button>
                </div>
                <div id="new-email-panel" class="input-group input-group-sm mt-2" style="display:none;">
                    <input type="email" id="new-email-address" class="form-control" placeholder="name@example.com">
                    <button type="button" class="btn btn-primary" id="new-email-save"><?= e(t('common.add')) ?></button>
                    <button type="button" class="btn btn-outline-secondary" id="new-email-cancel"><?= e(t('common.cancel')) ?></button>
                </div>
                <div id="new-email-error" class="text-danger small mt-1"></div>
            </div>
            <div class="col-md-4" id="identity-phone-group" style="display:none;">
                <label class="form-label"><?= e(t('accounts.field_identity_phone_required')) ?></label>
                <select name="identity_phone_id" class="form-select">
                    <option value=""><?= e(t('common.select_placeholder')) ?></option>
                    <?php foreach ($phones as $ph): ?>
                        <option value="<?= (int) $ph['id'] ?>" <?= $form['identity_phone_id'] === (string) $ph['id'] ? 'selected' : '' ?>><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4" id="identity-other-group" style="display:none;">
                <label class="form-label"><?= e(t('accounts.field_identity_value')) ?></label>
                <input type="text" name="identity_value" class="form-control" value="<?= e($form['identity_value']) ?>" placeholder="<?= e(t('accounts.identity_value_placeholder')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('common.field_username')) ?></label>
                <input type="text" name="username" class="form-control" value="<?= e($form['username']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('common.field_display_name')) ?></label>
                <input type="text" name="display_name" class="form-control" value="<?= e($form['display_name']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_external_id')) ?></label>
                <input type="text" name="external_account_id" class="form-control" value="<?= e($form['external_account_id']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_account_url')) ?></label>
                <input type="url" name="account_url" class="form-control" value="<?= e($form['account_url']) ?>" placeholder="https://">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('services.field_login_url')) ?></label>
                <input type="url" name="login_url" class="form-control" value="<?= e($form['login_url']) ?>" placeholder="https://">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('common.field_status_required')) ?></label>
                <select name="status" class="form-select"><?= optionsHtml(ACCOUNT_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_type_required')) ?></label>
                <select name="account_type" class="form-select"><?= optionsHtml(ACCOUNT_TYPES, $form['account_type']) ?></select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= e(t('common.field_created_date')) ?></label>
                <input type="date" name="created_date" class="form-control" value="<?= e($form['created_date']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= e(t('accounts.field_last_login')) ?></label>
                <input type="date" name="last_login" class="form-control" value="<?= e($form['last_login']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= e(t('common.field_last_verified')) ?></label>
                <input type="date" name="last_verified" class="form-control" value="<?= e($form['last_verified']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label"><?= e(t('common.field_notes')) ?></label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('accounts.section_security')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.twofa')) ?> <span id="inherited_twofa_status" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="twofa_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['twofa_status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label"><?= e(t('field.twofa_method')) ?> <span id="inherited_twofa_method" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <input type="text" name="twofa_method" class="form-control" value="<?= e($form['twofa_method']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.passkey')) ?> <span id="inherited_passkey_status" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="passkey_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['passkey_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('emails.view_security_key')) ?></label>
                <select name="security_key_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['security_key_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.security_questions')) ?> <span id="inherited_security_questions_status" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="security_questions_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['security_questions_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.last_security_check')) ?></label>
                <input type="date" name="last_security_check" class="form-control" value="<?= e($form['last_security_check']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_credential_storage')) ?></label>
                <input type="text" name="credential_storage" class="form-control" value="<?= e($form['credential_storage']) ?>" placeholder="<?= e(t('accounts.credential_storage_placeholder')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_credential_reference')) ?></label>
                <input type="text" name="credential_reference" class="form-control" value="<?= e($form['credential_reference']) ?>" placeholder="<?= e(t('accounts.credential_reference_placeholder')) ?>">
            </div>
            <div class="col-12">
                <p class="text-muted small mb-0"><?= e(t('accounts.credential_disclaimer')) ?></p>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('section.recovery')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_recovery_status')) ?> <span id="inherited_recovery_status" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="recovery_status" class="form-select"><?= optionsHtml(RECOVERY_STATUSES, $form['recovery_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.recovery_email')) ?> <span id="inherited_recovery_email_id" class="text-muted small fw-normal" style="display:none;">(<?= e(t('accounts.marker_same_as_identity')) ?>)</span></label>
                <select name="recovery_email_id" class="form-select">
                    <option value=""><?= e(t('common.none_selected')) ?></option>
                    <?php foreach ($emails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['recovery_email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('emails.recovery_phone_label')) ?> <span id="inherited_recovery_phone_id" class="text-muted small fw-normal" style="display:none;">(<?= e(t('accounts.marker_same_as_identity')) ?>)</span></label>
                <select name="recovery_phone_id" class="form-select">
                    <option value=""><?= e(t('common.none_selected')) ?></option>
                    <?php foreach ($phones as $ph): ?>
                        <option value="<?= (int) $ph['id'] ?>" <?= $form['recovery_phone_id'] === (string) $ph['id'] ? 'selected' : '' ?>><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_recovery_contact')) ?></label>
                <input type="text" name="recovery_contact" class="form-control" value="<?= e($form['recovery_contact']) ?>" placeholder="<?= e(t('accounts.recovery_contact_placeholder')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('field.recovery_codes_status')) ?></label>
                <select name="recovery_codes_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['recovery_codes_status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('field.recovery_codes_reference')) ?></label>
                <input type="text" name="recovery_codes_reference" class="form-control" value="<?= e($form['recovery_codes_reference']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_backup_method')) ?></label>
                <input type="text" name="backup_method" class="form-control" value="<?= e($form['backup_method']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('field.last_recovery_verification')) ?></label>
                <input type="date" name="last_recovery_verification" class="form-control" value="<?= e($form['last_recovery_verification']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label"><?= e(t('accounts.field_recovery_notes')) ?></label>
                <textarea name="recovery_notes" class="form-control" rows="2"><?= e($form['recovery_notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">Subscription</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= e(t('emails.field_type_required')) ?> <span id="inherited_sub_type" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="sub_type" id="sub_type" class="form-select"><?= optionsHtml(SUBSCRIPTION_TYPES, $form['sub_type']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_plan')) ?></label>
                <input type="text" name="sub_plan" class="form-control" value="<?= e($form['sub_plan']) ?>" placeholder="<?= e(t('accounts.plan_placeholder')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('common.field_status_required')) ?> <span id="inherited_sub_status" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="sub_status" class="form-select"><?= optionsHtml(SUBSCRIPTION_STATUSES, $form['sub_status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_price')) ?></label>
                <input type="text" inputmode="decimal" name="sub_price" class="form-control" value="<?= e($form['sub_price']) ?>" placeholder="<?= e(t('accounts.price_placeholder')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_currency')) ?> <span id="inherited_sub_currency" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <input type="text" name="sub_currency" class="form-control" value="<?= e($form['sub_currency']) ?>" maxlength="8" placeholder="<?= e(t('accounts.currency_placeholder')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_billing_cycle_required')) ?> <span id="inherited_sub_billing_cycle" class="text-muted small fw-normal" style="display:none;">(<?= e(t('services.marker_from_template')) ?>)</span></label>
                <select name="sub_billing_cycle" class="form-select"><?= optionsHtml(BILLING_CYCLES, $form['sub_billing_cycle']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_auto_renewal')) ?></label>
                <select name="sub_auto_renewal" class="form-select">
                    <option value="" <?= $form['sub_auto_renewal'] === '' ? 'selected' : '' ?>><?= e(t('enum.Unknown')) ?></option>
                    <option value="1" <?= $form['sub_auto_renewal'] === '1' ? 'selected' : '' ?>><?= e(t('common.yes')) ?></option>
                    <option value="0" <?= $form['sub_auto_renewal'] === '0' ? 'selected' : '' ?>><?= e(t('common.no')) ?></option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_start_date')) ?></label>
                <input type="date" name="sub_start_date" class="form-control" value="<?= e($form['sub_start_date']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_renewal_date')) ?></label>
                <input type="date" name="sub_renewal_date" class="form-control" value="<?= e($form['sub_renewal_date']) ?>">
            </div>
            <div class="col-12">
                <p class="text-muted small mb-0">
                    <?= e(t('accounts.currency_disclaimer')) ?>
                </p>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold">Payment</div>
        <div class="card-body">
            <p class="text-muted small"><?= e(t('accounts.payment_disclaimer')) ?></p>
            <p id="payment-free-note" class="text-muted small fst-italic" style="display:none;">
                <?= e(t('accounts.free_subscription_note')) ?>
            </p>
            <div id="payment-fields" class="row g-3">
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="pay_required" id="pay_required" class="form-check-input" value="1" <?= $form['pay_required'] === '1' ? 'checked' : '' ?>>
                        <label for="pay_required" class="form-check-label"><?= e(t('accounts.field_payment_required')) ?></label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= e(t('accounts.field_payment_method')) ?></label>
                    <input type="text" name="pay_method" class="form-control" value="<?= e($form['pay_method']) ?>" placeholder="<?= e(t('accounts.payment_method_placeholder')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= e(t('accounts.field_card_brand')) ?></label>
                    <input type="text" name="pay_card_brand" class="form-control" value="<?= e($form['pay_card_brand']) ?>" placeholder="<?= e(t('accounts.card_brand_placeholder')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= e(t('accounts.field_last4')) ?></label>
                    <input type="text" inputmode="numeric" maxlength="4" pattern="\d{4}" name="pay_last4" class="form-control" value="<?= e($form['pay_last4']) ?>" placeholder="<?= e(t('accounts.last4_placeholder')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= e(t('accounts.field_payment_reference')) ?></label>
                    <input type="text" name="pay_reference" class="form-control" value="<?= e($form['pay_reference']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= e(t('accounts.field_payment_auto_renewal')) ?></label>
                    <select name="pay_auto_renewal" class="form-select">
                        <option value="" <?= $form['pay_auto_renewal'] === '' ? 'selected' : '' ?>><?= e(t('enum.Unknown')) ?></option>
                        <option value="1" <?= $form['pay_auto_renewal'] === '1' ? 'selected' : '' ?>><?= e(t('common.yes')) ?></option>
                        <option value="0" <?= $form['pay_auto_renewal'] === '0' ? 'selected' : '' ?>><?= e(t('common.no')) ?></option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><?= e(t('accounts.save_button')) ?></button>
    <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
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
(function () {
    var identitySelect = document.getElementById('identity_type');
    var emailGroup = document.getElementById('identity-email-group');
    var phoneGroup = document.getElementById('identity-phone-group');
    var otherGroup = document.getElementById('identity-other-group');
    if (!identitySelect || !emailGroup || !phoneGroup || !otherGroup) {
        return;
    }
    function update() {
        var type = identitySelect.value;
        emailGroup.style.display = type === 'email' ? '' : 'none';
        phoneGroup.style.display = type === 'phone' ? '' : 'none';
        otherGroup.style.display = type === 'other' ? '' : 'none';
    }
    identitySelect.addEventListener('change', update);
    update();
})();
(function () {
    var csrfInput = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    function setupInlineCreate(opts) {
        var btn = document.getElementById(opts.btnId);
        var panel = document.getElementById(opts.panelId);
        var input = document.getElementById(opts.inputId);
        var saveBtn = document.getElementById(opts.saveId);
        var cancelBtn = document.getElementById(opts.cancelId);
        var errorEl = document.getElementById(opts.errorId);
        var select = document.getElementById(opts.selectId);
        if (!btn || !panel || !input || !saveBtn || !cancelBtn || !errorEl || !select) {
            return;
        }

        function closePanel() {
            panel.style.display = 'none';
            errorEl.textContent = '';
            input.value = '';
        }

        btn.addEventListener('click', function () {
            if (panel.style.display === 'none') {
                panel.style.display = '';
                errorEl.textContent = '';
                input.focus();
            } else {
                closePanel();
            }
        });

        cancelBtn.addEventListener('click', closePanel);

        function save() {
            var value = input.value.trim();
            if (!value) {
                return;
            }
            errorEl.textContent = '';
            saveBtn.disabled = true;

            var body = new URLSearchParams();
            body.set('csrf_token', csrfToken);
            body.set(opts.fieldName, value);

            fetch(opts.url, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (res) {
                    return res.json().then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (result) {
                    saveBtn.disabled = false;
                    if (!result.ok || result.data.error) {
                        errorEl.textContent = (result.data && result.data.error) || 'Error';
                        return;
                    }
                    var option = document.createElement('option');
                    option.value = result.data.id;
                    option.textContent = result.data[opts.labelKey];
                    select.appendChild(option);
                    select.value = String(result.data.id);
                    closePanel();
                })
                .catch(function () {
                    saveBtn.disabled = false;
                    errorEl.textContent = 'Error';
                });
        }

        saveBtn.addEventListener('click', save);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                save();
            }
        });
    }

    setupInlineCreate({
        btnId: 'new-service-btn', panelId: 'new-service-panel', inputId: 'new-service-name',
        saveId: 'new-service-save', cancelId: 'new-service-cancel', errorId: 'new-service-error',
        selectId: 'service_id', url: '../services/create-inline.php', fieldName: 'service_name', labelKey: 'name',
    });
    setupInlineCreate({
        btnId: 'new-email-btn', panelId: 'new-email-panel', inputId: 'new-email-address',
        saveId: 'new-email-save', cancelId: 'new-email-cancel', errorId: 'new-email-error',
        selectId: 'email_id', url: '../emails/create-inline.php', fieldName: 'email_address', labelKey: 'address',
    });
})();
(function () {
    // Service Defaults: pre-fills form fields from the selected service's template
    // (CORE RULE — never writes anything, only sets the visible form value the user
    // still has to submit) plus a separate derivation: when the template's
    // recovery_follows_identity is on, the Recovery Email/Phone select mirrors
    // whichever identity field is currently chosen. Both kinds are tracked the same
    // way: a field we set programmatically shows its "inherited" marker and keeps
    // following future service/identity changes right up until the user edits that
    // field themselves — real user input is a trusted event (e.isTrusted), our own
    // dispatched change events are not, so the two are easy to tell apart.
    var serviceSelect = document.getElementById('service_id');
    var identitySelect = document.getElementById('identity_type');
    var emailSelect = document.getElementById('email_id');
    var phoneSelect = document.querySelector('select[name="identity_phone_id"]');
    if (!serviceSelect) {
        return;
    }

    var templateFields = {
        identity_type: 'default_identity_type',
        twofa_status: 'default_twofa_status',
        twofa_method: 'default_twofa_method',
        passkey_status: 'default_passkey_status',
        security_questions_status: 'default_security_questions_status',
        recovery_status: 'default_recovery_status',
        sub_type: 'default_subscription_type',
        sub_status: 'default_subscription_status',
        sub_billing_cycle: 'default_billing_cycle',
        sub_currency: 'default_currency',
    };
    var derivedFields = ['recovery_email_id', 'recovery_phone_id'];
    var allFields = Object.keys(templateFields).concat(derivedFields);

    var touched = {};
    var pristine = {};
    var recoveryFollowsIdentity = false;

    allFields.forEach(function (name) {
        var el = document.querySelector('[name="' + name + '"]');
        if (!el) {
            return;
        }
        pristine[name] = el.value;
        el.addEventListener('input', onUserEdit);
        el.addEventListener('change', onUserEdit);
        function onUserEdit(e) {
            if (e.isTrusted === false) {
                return;
            }
            touched[name] = true;
            var marker = document.getElementById('inherited_' + name);
            if (marker) {
                marker.style.display = 'none';
            }
        }
    });

    function applyValue(name, value) {
        if (touched[name] || value === null || value === undefined || value === '') {
            return;
        }
        var el = document.querySelector('[name="' + name + '"]');
        if (!el) {
            return;
        }
        el.value = String(value);
        var marker = document.getElementById('inherited_' + name);
        if (marker) {
            marker.style.display = '';
        }
        el.dispatchEvent(new Event('change'));
    }

    function resetInherited() {
        allFields.forEach(function (name) {
            if (touched[name]) {
                return;
            }
            var el = document.querySelector('[name="' + name + '"]');
            if (!el) {
                return;
            }
            el.value = pristine[name];
            var marker = document.getElementById('inherited_' + name);
            if (marker) {
                marker.style.display = 'none';
            }
            el.dispatchEvent(new Event('change'));
        });
    }

    function deriveRecovery() {
        var type = identitySelect ? identitySelect.value : '';
        if (!recoveryFollowsIdentity) {
            return;
        }
        if (type === 'email' && emailSelect && emailSelect.value) {
            applyValue('recovery_email_id', emailSelect.value);
        } else if (type === 'phone' && phoneSelect && phoneSelect.value) {
            applyValue('recovery_phone_id', phoneSelect.value);
        }
    }

    serviceSelect.addEventListener('change', function () {
        resetInherited();
        recoveryFollowsIdentity = false;
        var id = serviceSelect.value;
        if (!id) {
            return;
        }
        fetch('../services/get-defaults.php?service_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (result) {
                var d = result && result.defaults;
                if (!d) {
                    return;
                }
                Object.keys(templateFields).forEach(function (formField) {
                    applyValue(formField, d[templateFields[formField]]);
                });
                recoveryFollowsIdentity = !!d.recovery_follows_identity;
                deriveRecovery();
            })
            .catch(function () { /* best-effort pre-fill — leave the form exactly as it was on failure */ });
    });

    if (identitySelect) {
        identitySelect.addEventListener('change', deriveRecovery);
    }
    if (emailSelect) {
        emailSelect.addEventListener('change', deriveRecovery);
    }
    if (phoneSelect) {
        phoneSelect.addEventListener('change', deriveRecovery);
    }
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
