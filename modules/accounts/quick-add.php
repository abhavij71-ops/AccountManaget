<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();
$errors = [];
$form = [
    'service_id' => '',
    'email_id' => '',
    'username' => '',
    'status' => 'Active',
    'plan' => '',
    'notes' => '',
];

$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address FROM emails ORDER BY email_address')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $serviceId = (int) $form['service_id'];
    $emailId = (int) $form['email_id'];

    if (!$services) {
        $errors[] = t('accounts.no_services_yet');
    } elseif ($serviceId <= 0 || !in_array($serviceId, array_column($services, 'id'), true)) {
        $errors[] = t('accounts.service_required');
    }
    if (!$emails) {
        $errors[] = t('accounts.no_emails_yet');
    } elseif ($emailId <= 0 || !in_array($emailId, array_column($emails, 'id'), true)) {
        $errors[] = t('accounts.email_required');
    }
    if (!array_key_exists($form['status'], ACCOUNT_STATUSES)) {
        $errors[] = t('phones.status_invalid');
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO accounts (service_id, email_id, username, status, notes)
                VALUES (:service_id, :email_id, :username, :status, :notes)');
            $stmt->execute([
                'service_id' => $serviceId,
                'email_id' => $emailId,
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
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_service_required')) ?></label>
                <select name="service_id" class="form-select" required>
                    <option value=""><?= e(t('common.select_placeholder')) ?></option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $form['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('accounts.field_email_required')) ?></label>
                <select name="email_id" class="form-select" required>
                    <option value=""><?= e(t('common.select_placeholder')) ?></option>
                    <?php foreach ($emails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
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
<?php require __DIR__ . '/../../includes/footer.php'; ?>
