<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireRole('owner', 'admin', 'member');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$account = $id ? fetchAccountById($pdo, $id) : null;

if (!$account || !canSeeRecord($account['visibility'] ?? null, isset($account['owner_user_id']) ? (int) $account['owner_user_id'] : null)) {
    notFoundResponse(t('accounts.not_found'));
}
requireEditRecord($account);

$ownerUserId = isset($account['owner_user_id']) && $account['owner_user_id'] !== null ? (int) $account['owner_user_id'] : null;
$canManageVisibility = canManageRecordVisibility($ownerUserId);

$security = fetchAccountSecurity($pdo, $id) ?? [];
$recovery = fetchAccountRecovery($pdo, $id) ?? [];
$subscription = fetchSubscription($pdo, $id) ?? [];
$payment = fetchPayment($pdo, $id) ?? [];
$errors = [];

$form = [
    'service_id' => (string) $account['service_id'],
    'visibility' => $account['visibility'] ?? 'workspace',
    'identity_type' => (string) ($account['identity_type'] ?? 'email'),
    'email_id' => (string) ($account['email_id'] ?? ''),
    'identity_phone_id' => (string) ($account['identity_phone_id'] ?? ''),
    'identity_value' => (string) ($account['identity_value'] ?? ''),
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

// Scoped to what the current user may see (docs/PERMISSIONS.md) — see
// add.php's identical fix for the VERIFIED leak this closes. The account's
// OWN already-linked service/email/phone/recovery values are still allowed
// to save unchanged below even if scoping drops them from these lists (a
// private resource someone else owns, linked before this fix existed) —
// only a NEWLY chosen id has to be in the visible set.
$services = $pdo->query('SELECT id, service_name FROM services WHERE ' . visibilityScope('services') . ' ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address FROM emails WHERE ' . visibilityScope('emails') . ' ORDER BY email_address')->fetchAll();
$phones = $pdo->query('SELECT id, phone_number, label FROM phones WHERE ' . visibilityScope('phones') . ' ORDER BY phone_number')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'pay_required' || $key === 'visibility') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $form['pay_required'] = isset($_POST['pay_required']) ? '1' : '';
    // Only honored when the viewer is actually allowed to manage it — a
    // tampered POST from anyone else leaves the stored value untouched.
    if ($canManageVisibility) {
        $postedVisibility = (string) ($_POST['visibility'] ?? $form['visibility']);
        $form['visibility'] = in_array($postedVisibility, ['private', 'workspace'], true) ? $postedVisibility : $form['visibility'];
    }

    $serviceId = (int) $form['service_id'];
    $emailId = (int) $form['email_id'];
    $identityPhoneId = (int) $form['identity_phone_id'];

    // An id equal to what's already stored is always allowed through
    // unchanged, even if visibilityScope() now excludes it from the
    // dropdown (a linked private resource someone else owns, from before
    // this fix existed) — only a NEWLY chosen id has to be visible.
    $serviceIdUnchanged = $serviceId === (int) $account['service_id'];
    $emailIdUnchanged = $emailId === (int) ($account['email_id'] ?? 0);
    $identityPhoneIdUnchanged = $identityPhoneId === (int) ($account['identity_phone_id'] ?? 0);

    if (!$serviceIdUnchanged && !in_array($serviceId, array_column($services, 'id'), true)) {
        $errors[] = t('accounts.service_required');
    }
    if (!in_array($form['identity_type'], ['email', 'phone', 'username', 'other'], true)) {
        $errors[] = t('accounts.identity_type_invalid');
    } elseif ($form['identity_type'] === 'email') {
        if (!$emailIdUnchanged && !in_array($emailId, array_column($emails, 'id'), true)) {
            $errors[] = t('accounts.email_required');
        }
    } elseif ($form['identity_type'] === 'phone') {
        if (!$identityPhoneIdUnchanged && !in_array($identityPhoneId, array_column($phones, 'id'), true)) {
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
    // A hidden id posted by hand must not be linkable: recovery_email_id/
    // recovery_phone_id were otherwise stored straight from POST with no
    // existence/visibility check at all. Same unchanged-is-allowed
    // exception as service/email/phone above.
    $recoveryEmailIdUnchanged = $form['recovery_email_id'] === (string) ($recovery['recovery_email_id'] ?? '');
    $recoveryPhoneIdUnchanged = $form['recovery_phone_id'] === (string) ($recovery['recovery_phone_id'] ?? '');
    if ($form['recovery_email_id'] !== '' && !$recoveryEmailIdUnchanged && !in_array((int) $form['recovery_email_id'], array_column($emails, 'id'), true)) {
        $errors[] = t('accounts.recovery_email_invalid');
    }
    if ($form['recovery_phone_id'] !== '' && !$recoveryPhoneIdUnchanged && !in_array((int) $form['recovery_phone_id'], array_column($phones, 'id'), true)) {
        $errors[] = t('accounts.recovery_phone_invalid');
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

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($form['status'] !== $account['status']) {
                log_history($pdo, 'account', $id, 'Status Changed', 'status', $account['status'], $form['status']);
            }
            if ($emailId !== (int) ($account['email_id'] ?? 0)) {
                if (!empty($account['email_address'])) {
                    log_history($pdo, 'account', $id, 'Email Unlinked', 'email_id', $account['email_address'], null);
                }
                if ($emailId !== 0) {
                    $newEmailAddr = $emails[array_search($emailId, array_column($emails, 'id'), true)]['email_address'] ?? (string) $emailId;
                    log_history($pdo, 'account', $id, 'Email Linked', 'email_id', null, $newEmailAddr);
                }
            }
            $baseDiffFields = [
                'identity_type', 'identity_value', 'username', 'display_name', 'external_account_id', 'account_url', 'login_url',
                'account_type', 'created_date', 'last_login', 'last_verified', 'notes', 'visibility',
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
                service_id = :service_id, email_id = :email_id, identity_type = :identity_type, identity_phone_id = :identity_phone_id,
                identity_value = :identity_value,
                username = :username, display_name = :display_name,
                external_account_id = :external_account_id, account_url = :account_url, login_url = :login_url,
                status = :status, account_type = :account_type, created_date = :created_date,
                last_login = :last_login, last_verified = :last_verified, notes = :notes, visibility = :visibility
                WHERE id = :id');
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
                'visibility' => $form['visibility'],
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
            flashSet('success', t('msg.saved_changes'));
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = t('msg.save_error') . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('accounts.edit_heading', ['service' => $account['service_name'], 'email' => accountDisplayIdentity($account)]);
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('accounts.edit_heading', ['service' => $account['service_name'], 'email' => accountDisplayIdentity($account)])) ?></h1>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_profile')) ?></a>
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
        <div class="card-header bg-white fw-bold"><?= e(t('common.basic_info')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_identity_type_required')) ?></label>
                <select name="identity_type" id="identity_type" class="form-select" required>
                    <option value="email" <?= $form['identity_type'] === 'email' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_email')) ?></option>
                    <option value="phone" <?= $form['identity_type'] === 'phone' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_phone')) ?></option>
                    <option value="username" <?= $form['identity_type'] === 'username' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_username')) ?></option>
                    <option value="other" <?= $form['identity_type'] === 'other' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_other')) ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_service_required')) ?></label>
                <select name="service_id" class="form-select" required>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $form['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4" id="identity-email-group">
                <label class="form-label"><?= e(t('accounts.field_email_required')) ?></label>
                <select name="email_id" class="form-select">
                    <option value=""><?= e(t('common.select_placeholder')) ?></option>
                    <?php foreach ($emails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
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
                <input type="url" name="account_url" class="form-control" value="<?= e($form['account_url']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('services.field_login_url')) ?></label>
                <input type="url" name="login_url" class="form-control" value="<?= e($form['login_url']) ?>">
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
            <?php if ($canManageVisibility): ?>
                <div class="col-md-3">
                    <label class="form-label"><?= e(tOr('common.field_visibility', 'Visibility')) ?></label>
                    <select name="visibility" class="form-select">
                        <option value="workspace" <?= $form['visibility'] === 'workspace' ? 'selected' : '' ?>><?= e(tOr('visibility.workspace', 'Workspace')) ?></option>
                        <option value="private" <?= $form['visibility'] === 'private' ? 'selected' : '' ?>><?= e(tOr('visibility.private', 'Private')) ?></option>
                    </select>
                    <p class="text-muted small mb-0 mt-1"><?= e(tOr('common.field_visibility_hint', 'Private records are visible only to you and workspace owners/admins.')) ?></p>
                </div>
            <?php endif; ?>
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
                <label class="form-label"><?= e(t('field.twofa')) ?></label>
                <select name="twofa_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['twofa_status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label"><?= e(t('field.twofa_method')) ?></label>
                <input type="text" name="twofa_method" class="form-control" value="<?= e($form['twofa_method']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.passkey')) ?></label>
                <select name="passkey_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['passkey_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('emails.view_security_key')) ?></label>
                <select name="security_key_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['security_key_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.security_questions')) ?></label>
                <select name="security_questions_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['security_questions_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.last_security_check')) ?></label>
                <input type="date" name="last_security_check" class="form-control" value="<?= e($form['last_security_check']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_credential_storage')) ?></label>
                <input type="text" name="credential_storage" class="form-control" value="<?= e($form['credential_storage']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_credential_reference')) ?></label>
                <input type="text" name="credential_reference" class="form-control" value="<?= e($form['credential_reference']) ?>">
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
                <label class="form-label"><?= e(t('accounts.field_recovery_status')) ?></label>
                <select name="recovery_status" class="form-select"><?= optionsHtml(RECOVERY_STATUSES, $form['recovery_status']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.recovery_email')) ?></label>
                <select name="recovery_email_id" class="form-select">
                    <option value=""><?= e(t('common.none_selected')) ?></option>
                    <?php foreach ($emails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['recovery_email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('emails.recovery_phone_label')) ?></label>
                <select name="recovery_phone_id" class="form-select">
                    <option value=""><?= e(t('common.none_selected')) ?></option>
                    <?php foreach ($phones as $ph): ?>
                        <option value="<?= (int) $ph['id'] ?>" <?= $form['recovery_phone_id'] === (string) $ph['id'] ? 'selected' : '' ?>><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_recovery_contact')) ?></label>
                <input type="text" name="recovery_contact" class="form-control" value="<?= e($form['recovery_contact']) ?>">
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
                <label class="form-label"><?= e(t('emails.field_type_required')) ?></label>
                <select name="sub_type" id="sub_type" class="form-select"><?= optionsHtml(SUBSCRIPTION_TYPES, $form['sub_type']) ?></select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('accounts.field_plan')) ?></label>
                <input type="text" name="sub_plan" class="form-control" value="<?= e($form['sub_plan']) ?>" placeholder="<?= e(t('accounts.plan_placeholder')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('common.field_status_required')) ?></label>
                <select name="sub_status" class="form-select"><?= optionsHtml(SUBSCRIPTION_STATUSES, $form['sub_status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_price')) ?></label>
                <input type="text" inputmode="decimal" name="sub_price" class="form-control" value="<?= e($form['sub_price']) ?>" placeholder="<?= e(t('accounts.price_placeholder')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_currency')) ?></label>
                <input type="text" name="sub_currency" class="form-control" value="<?= e($form['sub_currency']) ?>" maxlength="8" placeholder="<?= e(t('accounts.currency_placeholder')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_billing_cycle_required')) ?></label>
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

    <button type="submit" class="btn btn-primary"><?= e(t('common.save_changes')) ?></button>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
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
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
