<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();
$errors = [];
$form = [
    'service_id' => '',
    'identity_type' => 'email',
    'email_id' => '',
    'identity_phone_id' => '',
    'identity_value' => '',
    'username' => '',
    'status' => 'Active',
    'plan' => '',
    'notes' => '',
];

$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address FROM emails ORDER BY email_address')->fetchAll();
$phones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $serviceId = (int) $form['service_id'];
    $emailId = (int) $form['email_id'];
    $identityPhoneId = (int) $form['identity_phone_id'];

    if (!$services) {
        $errors[] = t('accounts.no_services_yet');
    } elseif ($serviceId <= 0 || !in_array($serviceId, array_column($services, 'id'), true)) {
        $errors[] = t('accounts.service_required');
    }
    if (!in_array($form['identity_type'], ['email', 'phone', 'username', 'other'], true)) {
        $errors[] = t('accounts.identity_type_invalid');
    } elseif ($form['identity_type'] === 'email') {
        if (!$emails) {
            $errors[] = t('accounts.no_emails_yet');
        } elseif ($emailId <= 0 || !in_array($emailId, array_column($emails, 'id'), true)) {
            $errors[] = t('accounts.email_required');
        }
    } elseif ($form['identity_type'] === 'phone') {
        if ($identityPhoneId <= 0 || !in_array($identityPhoneId, array_column($phones, 'id'), true)) {
            $errors[] = t('accounts.identity_phone_required');
        }
    } elseif ($form['identity_type'] === 'username' && $form['username'] === '') {
        $errors[] = t('accounts.identity_username_required');
    }
    if (!array_key_exists($form['status'], ACCOUNT_STATUSES)) {
        $errors[] = t('phones.status_invalid');
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO accounts (service_id, email_id, identity_type, identity_phone_id, identity_value, username, status, notes)
                VALUES (:service_id, :email_id, :identity_type, :identity_phone_id, :identity_value, :username, :status, :notes)');
            $stmt->execute([
                'service_id' => $serviceId,
                'email_id' => $emailId !== 0 ? $emailId : null,
                'identity_type' => $form['identity_type'],
                'identity_phone_id' => $identityPhoneId !== 0 ? $identityPhoneId : null,
                'identity_value' => $form['identity_value'] !== '' ? $form['identity_value'] : null,
                'username' => $form['username'] !== '' ? $form['username'] : null,
                'status' => $form['status'],
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $accountId = (int) $pdo->lastInsertId();

            if ($form['plan'] !== '') {
                $stmt = $pdo->prepare('INSERT INTO subscriptions (account_id, plan) VALUES (?, ?)');
                $stmt->execute([$accountId, $form['plan']]);
            }

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
$pageTitle = t('accounts.quick_add_title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('accounts.quick_add_title')) ?></h1>
    <div class="d-flex gap-2">
        <a href="add.php" class="btn btn-outline-secondary btn-sm"><?= e(t('accounts.full_form_link')) ?></a>
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

<?php if (!$services || !$emails): ?>
    <div class="alert alert-warning">
        <?= e(t('accounts.need_service_and_email')) ?>
        <a href="../services/add.php"><?= e(t('services.add_title')) ?></a> — <a href="../emails/add.php"><?= e(t('emails.add_title')) ?></a>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="card am-card mb-3">
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
            <div class="col-md-6">
                <label class="form-label"><?= e(t('common.field_username')) ?></label>
                <input type="text" name="username" class="form-control" value="<?= e($form['username']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('common.field_status_required')) ?></label>
                <select name="status" class="form-select"><?= optionsHtml(ACCOUNT_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('accounts.field_plan')) ?></label>
                <input type="text" name="plan" class="form-control" value="<?= e($form['plan']) ?>" placeholder="<?= e(t('accounts.quick_plan_placeholder')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label"><?= e(t('common.field_notes')) ?></label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><?= e(t('accounts.save_button')) ?></button>
    <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>

<script>
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
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
