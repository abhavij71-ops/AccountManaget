<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();
requireWriteAccess();

$pdo = db();
$errors = [];
$form = [
    'service_name' => '',
    'website' => '',
    'login_url' => '',
    'category' => '',
    'status' => 'Unknown',
    'purpose' => '',
    'notes' => '',
    'default_identity_type' => '',
    'default_twofa_status' => '',
    'default_twofa_method' => '',
    'default_passkey_status' => '',
    'default_security_questions_status' => '',
    'default_recovery_status' => '',
    'recovery_follows_identity' => '',
    'default_subscription_type' => '',
    'default_subscription_status' => '',
    'default_billing_cycle' => '',
    'default_currency' => '',
];

$categories = $pdo->query("SELECT DISTINCT category FROM services WHERE category != 'Not Set' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'recovery_follows_identity') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $form['recovery_follows_identity'] = isset($_POST['recovery_follows_identity']) ? '1' : '';

    if ($form['service_name'] === '') {
        $errors[] = t('services.name_required');
    }
    if (!array_key_exists($form['status'], SERVICE_STATUSES)) {
        $errors[] = t('services.status_invalid');
    }
    if ($form['category'] === '') {
        $form['category'] = 'Not Set';
    }
    if ($form['default_identity_type'] !== '' && !in_array($form['default_identity_type'], ['email', 'phone', 'username', 'other'], true)) {
        $errors[] = t('accounts.identity_type_invalid');
    }
    foreach (['default_twofa_status', 'default_passkey_status', 'default_security_questions_status'] as $f) {
        if ($form[$f] !== '' && !array_key_exists($form[$f], SECURITY_STATES)) {
            $errors[] = t('msg.invalid_security_status');
            break;
        }
    }
    if ($form['default_recovery_status'] !== '' && !array_key_exists($form['default_recovery_status'], RECOVERY_STATUSES)) {
        $errors[] = t('accounts.recovery_status_invalid');
    }
    if ($form['default_subscription_type'] !== '' && !array_key_exists($form['default_subscription_type'], SUBSCRIPTION_TYPES)) {
        $errors[] = t('accounts.sub_type_invalid');
    }
    if ($form['default_subscription_status'] !== '' && !array_key_exists($form['default_subscription_status'], SUBSCRIPTION_STATUSES)) {
        $errors[] = t('accounts.sub_status_invalid');
    }
    if ($form['default_billing_cycle'] !== '' && !array_key_exists($form['default_billing_cycle'], BILLING_CYCLES)) {
        $errors[] = t('accounts.billing_cycle_invalid');
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO services (service_name, website, login_url, category, status, purpose, notes, owner_user_id)
                VALUES (:service_name, :website, :login_url, :category, :status, :purpose, :notes, :owner_user_id)');
            $stmt->execute([
                'service_name' => $form['service_name'],
                'website' => $form['website'] !== '' ? $form['website'] : null,
                'login_url' => $form['login_url'] !== '' ? $form['login_url'] : null,
                'category' => $form['category'],
                'status' => $form['status'],
                'purpose' => $form['purpose'] !== '' ? $form['purpose'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'owner_user_id' => currentUserId(),
            ]);
            $newId = (int) $pdo->lastInsertId();
            log_history($pdo, 'service', $newId, 'Service Created');

            upsertServiceDefaults($pdo, $newId, [
                'default_identity_type' => $form['default_identity_type'] !== '' ? $form['default_identity_type'] : null,
                'default_twofa_status' => $form['default_twofa_status'] !== '' ? $form['default_twofa_status'] : null,
                'default_twofa_method' => $form['default_twofa_method'] !== '' ? $form['default_twofa_method'] : null,
                'default_passkey_status' => $form['default_passkey_status'] !== '' ? $form['default_passkey_status'] : null,
                'default_security_questions_status' => $form['default_security_questions_status'] !== '' ? $form['default_security_questions_status'] : null,
                'default_recovery_status' => $form['default_recovery_status'] !== '' ? $form['default_recovery_status'] : null,
                'recovery_follows_identity' => $form['recovery_follows_identity'] === '1' ? 1 : 0,
                'default_subscription_type' => $form['default_subscription_type'] !== '' ? $form['default_subscription_type'] : null,
                'default_subscription_status' => $form['default_subscription_status'] !== '' ? $form['default_subscription_status'] : null,
                'default_billing_cycle' => $form['default_billing_cycle'] !== '' ? $form['default_billing_cycle'] : null,
                'default_currency' => $form['default_currency'] !== '' ? $form['default_currency'] : null,
            ]);

            $pdo->commit();
            flashSet('success', t('services.created_success'));
            header('Location: view.php?id=' . $newId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = t('services.duplicate_name');
            } else {
                $errors[] = t('services.create_error') . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('services.add_title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('services.add_title')) ?></h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
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
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= e(t('services.field_name_required')) ?></label>
                <input type="text" name="service_name" class="form-control" required value="<?= e($form['service_name']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('services.field_category')) ?></label>
                <input type="text" name="category" class="form-control" list="category-list" value="<?= e($form['category']) ?>" placeholder="<?= e(t('services.field_category_placeholder')) ?>">
                <datalist id="category-list">
                    <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('services.field_website')) ?></label>
                <input type="url" name="website" class="form-control" value="<?= e($form['website']) ?>" placeholder="https://">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('services.field_login_url')) ?></label>
                <input type="url" name="login_url" class="form-control" value="<?= e($form['login_url']) ?>" placeholder="https://">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('common.field_status_required')) ?></label>
                <select name="status" class="form-select"><?= optionsHtml(SERVICE_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label"><?= e(t('services.field_purpose')) ?></label>
                <input type="text" name="purpose" class="form-control" value="<?= e($form['purpose']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label"><?= e(t('common.field_notes')) ?></label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('services.defaults_title')) ?></div>
        <div class="card-body row g-3">
            <p class="text-muted small col-12 mb-0"><?= e(t('services.defaults_description')) ?></p>

            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_identity_type')) ?></label>
                <select name="default_identity_type" class="form-select">
                    <option value="" <?= $form['default_identity_type'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <option value="email" <?= $form['default_identity_type'] === 'email' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_email')) ?></option>
                    <option value="phone" <?= $form['default_identity_type'] === 'phone' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_phone')) ?></option>
                    <option value="username" <?= $form['default_identity_type'] === 'username' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_username')) ?></option>
                    <option value="other" <?= $form['default_identity_type'] === 'other' ? 'selected' : '' ?>><?= e(t('accounts.identity_type_other')) ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_twofa_status')) ?></label>
                <select name="default_twofa_status" class="form-select">
                    <option value="" <?= $form['default_twofa_status'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <?= optionsHtml(SECURITY_STATES, $form['default_twofa_status']) ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_twofa_method')) ?></label>
                <input type="text" name="default_twofa_method" class="form-control" value="<?= e($form['default_twofa_method']) ?>" placeholder="<?= e(t('field.twofa_method_placeholder')) ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_passkey_status')) ?></label>
                <select name="default_passkey_status" class="form-select">
                    <option value="" <?= $form['default_passkey_status'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <?= optionsHtml(SECURITY_STATES, $form['default_passkey_status']) ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_security_questions_status')) ?></label>
                <select name="default_security_questions_status" class="form-select">
                    <option value="" <?= $form['default_security_questions_status'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <?= optionsHtml(SECURITY_STATES, $form['default_security_questions_status']) ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_recovery_status')) ?></label>
                <select name="default_recovery_status" class="form-select">
                    <option value="" <?= $form['default_recovery_status'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <?= optionsHtml(RECOVERY_STATUSES, $form['default_recovery_status']) ?>
                </select>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" name="recovery_follows_identity" id="recovery_follows_identity" class="form-check-input" value="1" <?= $form['recovery_follows_identity'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="recovery_follows_identity"><?= e(t('services.field_recovery_follows_identity')) ?></label>
                </div>
                <p class="text-muted small mb-0"><?= e(t('services.recovery_follows_identity_hint')) ?></p>
            </div>

            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_subscription_type')) ?></label>
                <select name="default_subscription_type" class="form-select">
                    <option value="" <?= $form['default_subscription_type'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <?= optionsHtml(SUBSCRIPTION_TYPES, $form['default_subscription_type']) ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_subscription_status')) ?></label>
                <select name="default_subscription_status" class="form-select">
                    <option value="" <?= $form['default_subscription_status'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <?= optionsHtml(SUBSCRIPTION_STATUSES, $form['default_subscription_status']) ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_billing_cycle')) ?></label>
                <select name="default_billing_cycle" class="form-select">
                    <option value="" <?= $form['default_billing_cycle'] === '' ? 'selected' : '' ?>><?= e(t('services.no_default_option')) ?></option>
                    <?= optionsHtml(BILLING_CYCLES, $form['default_billing_cycle']) ?>
                </select>
            </div>
            <p class="text-muted small col-12 mb-0"><?= e(t('services.subscription_free_hint')) ?></p>

            <div class="col-md-4">
                <label class="form-label"><?= e(t('services.field_default_currency')) ?></label>
                <input type="text" name="default_currency" class="form-control" value="<?= e($form['default_currency']) ?>" placeholder="<?= e(t('accounts.currency_placeholder')) ?>">
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><?= e(t('services.save_button')) ?></button>
    <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
