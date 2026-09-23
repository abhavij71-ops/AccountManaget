<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireRole('owner', 'admin', 'member');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$email = $id ? fetchEmailById($pdo, $id) : null;

if (!$email || !canSeeRecord($email['visibility'] ?? null, isset($email['owner_user_id']) ? (int) $email['owner_user_id'] : null)) {
    notFoundResponse(t('emails.not_found'));
}

$ownerUserId = isset($email['owner_user_id']) && $email['owner_user_id'] !== null ? (int) $email['owner_user_id'] : null;
$canManageVisibility = canManageRecordVisibility($ownerUserId);

$security = fetchEmailSecurity($pdo, $id) ?? [];
$errors = [];

$form = [
    'email_address' => $email['email_address'],
    'visibility' => $email['visibility'] ?? 'workspace',
    'display_name' => (string) ($email['display_name'] ?? ''),
    'provider' => (string) ($email['provider'] ?? ''),
    'type' => $email['type'],
    'purpose' => (string) ($email['purpose'] ?? ''),
    'status' => $email['status'],
    'created_date' => (string) ($email['created_date'] ?? ''),
    'last_verified' => (string) ($email['last_verified'] ?? ''),
    'notes' => (string) ($email['notes'] ?? ''),
    'twofa_status' => $security['twofa_status'] ?? 'Not Set',
    'twofa_method' => (string) ($security['twofa_method'] ?? ''),
    'passkey_status' => $security['passkey_status'] ?? 'Not Set',
    'security_key_status' => $security['security_key_status'] ?? 'Not Set',
    'security_questions_status' => $security['security_questions_status'] ?? 'Not Set',
    'last_security_check' => (string) ($security['last_security_check'] ?? ''),
    'recovery_email_id' => (string) ($security['recovery_email_id'] ?? ''),
    'recovery_phone_id' => (string) ($security['recovery_phone_id'] ?? ''),
    'recovery_codes_status' => $security['recovery_codes_status'] ?? 'Not Set',
    'recovery_codes_reference' => (string) ($security['recovery_codes_reference'] ?? ''),
    'backup_method' => (string) ($security['backup_method'] ?? ''),
    'last_recovery_verification' => (string) ($security['last_recovery_verification'] ?? ''),
];

$otherEmails = $pdo->prepare('SELECT id, email_address FROM emails WHERE id != ? ORDER BY email_address');
$otherEmails->execute([$id]);
$otherEmails = $otherEmails->fetchAll();
$allPhones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'visibility') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    // Only honored when the viewer is actually allowed to manage it — a
    // tampered POST from anyone else leaves the stored value untouched.
    if ($canManageVisibility) {
        $postedVisibility = (string) ($_POST['visibility'] ?? $form['visibility']);
        $form['visibility'] = in_array($postedVisibility, ['private', 'workspace'], true) ? $postedVisibility : $form['visibility'];
    }

    if ($form['email_address'] === '' || !filter_var($form['email_address'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('emails.email_invalid');
    }
    if (!array_key_exists($form['type'], EMAIL_TYPES)) {
        $errors[] = t('emails.type_invalid');
    }
    if (!array_key_exists($form['status'], EMAIL_STATUSES)) {
        $errors[] = t('emails.status_invalid');
    }
    foreach (['twofa_status', 'passkey_status', 'security_key_status', 'security_questions_status', 'recovery_codes_status'] as $secField) {
        if (!array_key_exists($form[$secField], SECURITY_STATES)) {
            $errors[] = t('msg.invalid_security_status');
            break;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($form['status'] !== $email['status']) {
                log_history($pdo, 'email', $id, 'Status Changed', 'status', $email['status'], $form['status']);
            }
            $oldTwofa = $security['twofa_status'] ?? 'Not Set';
            if ($oldTwofa !== $form['twofa_status']) {
                log_history($pdo, 'email', $id, '2FA Changed', 'twofa_status', $oldTwofa, $form['twofa_status']);
            }
            $baseDiffFields = ['email_address', 'display_name', 'provider', 'type', 'purpose', 'created_date', 'last_verified', 'notes', 'visibility'];
            foreach ($baseDiffFields as $f) {
                $old = (string) ($email[$f] ?? '');
                if ($old !== $form[$f]) {
                    log_history($pdo, 'email', $id, 'Email Updated', $f, $old !== '' ? $old : null, $form[$f] !== '' ? $form[$f] : null);
                }
            }

            $stmt = $pdo->prepare('UPDATE emails SET
                email_address = :email_address, display_name = :display_name, provider = :provider,
                type = :type, purpose = :purpose, status = :status, created_date = :created_date,
                last_verified = :last_verified, notes = :notes, visibility = :visibility
                WHERE id = :id');
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
                'visibility' => $form['visibility'],
                'id' => $id,
            ]);

            upsertEmailSecurity($pdo, $id, [
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

            $pdo->commit();
            flashSet('success', t('msg.saved_changes'));
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = t('emails.duplicate_address_other');
            } else {
                $errors[] = t('msg.save_error') . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('emails.edit_title_prefix') . $email['email_address'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('emails.edit_title_prefix')) ?><?= e($email['email_address']) ?></h1>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_profile')) ?></a>
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
        <div class="card-header bg-white fw-bold"><?= e(t('section.identity')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= e(t('emails.field_address_required')) ?></label>
                <input type="email" name="email_address" class="form-control" required value="<?= e($form['email_address']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('common.field_display_name')) ?></label>
                <input type="text" name="display_name" class="form-control" value="<?= e($form['display_name']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('emails.field_provider')) ?></label>
                <input type="text" name="provider" class="form-control" value="<?= e($form['provider']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('emails.field_purpose')) ?></label>
                <input type="text" name="purpose" class="form-control" value="<?= e($form['purpose']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('emails.field_type_required')) ?></label>
                <select name="type" class="form-select"><?= optionsHtml(EMAIL_TYPES, $form['type']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('common.field_status_required')) ?></label>
                <select name="status" class="form-select"><?= optionsHtml(EMAIL_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('common.field_created_date')) ?></label>
                <input type="date" name="created_date" class="form-control" value="<?= e($form['created_date']) ?>">
            </div>
            <div class="col-md-3">
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
        <div class="card-header bg-white fw-bold"><?= e(t('emails.section_security')) ?></div>
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
                <label class="form-label"><?= e(t('emails.field_security_key')) ?></label>
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
                <label class="form-label"><?= e(t('emails.field_backup_method')) ?></label>
                <input type="text" name="backup_method" class="form-control" value="<?= e($form['backup_method']) ?>">
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('section.recovery')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= e(t('field.recovery_email')) ?></label>
                <select name="recovery_email_id" class="form-select">
                    <option value=""><?= e(t('common.none_selected')) ?></option>
                    <?php foreach ($otherEmails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['recovery_email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('emails.field_recovery_phone')) ?></label>
                <select name="recovery_phone_id" class="form-select">
                    <option value=""><?= e(t('common.none_selected')) ?></option>
                    <?php foreach ($allPhones as $ph): ?>
                        <option value="<?= (int) $ph['id'] ?>" <?= $form['recovery_phone_id'] === (string) $ph['id'] ? 'selected' : '' ?>><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('field.recovery_codes_status')) ?></label>
                <select name="recovery_codes_status" class="form-select"><?= optionsHtml(SECURITY_STATES, $form['recovery_codes_status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label"><?= e(t('field.recovery_codes_reference')) ?></label>
                <input type="text" name="recovery_codes_reference" class="form-control" value="<?= e($form['recovery_codes_reference']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('field.last_recovery_verification')) ?></label>
                <input type="date" name="last_recovery_verification" class="form-control" value="<?= e($form['last_recovery_verification']) ?>">
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><?= e(t('common.save_changes')) ?></button>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
