<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../../includes/plans.php';

requireLogin();
requireWriteAccess();

$pdo = db();
$planLimitReached = false;

$services = $pdo->query('SELECT id, service_name, visibility, owner_user_id FROM services ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address, visibility, owner_user_id FROM emails ORDER BY email_address')->fetchAll();

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
        $errors[] = t('msg.invalid_request');
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
        $errors[] = t('accounts.min_one_service');
    }
    if (!$form['email_ids']) {
        $errors[] = t('accounts.min_one_email');
    }
    if (!array_key_exists($form['status'], ACCOUNT_STATUSES)) {
        $errors[] = t('emails.invalid_status_selected');
    }
    if (!array_key_exists($form['account_type'], ACCOUNT_TYPES)) {
        $errors[] = t('accounts.bulk_type_invalid');
    }

    // Whole-batch check up front — every combo requested (service_ids ×
    // email_ids) is the worst case that could be created, checked once
    // before anything is written, not row by row inside the loop below.
    if (!$errors) {
        try {
            assertCanAddAccounts(count($form['service_ids']) * count($form['email_ids']));
        } catch (PlanLimitException $e) {
            $planLimitReached = true;
        }
    }

    if (!$errors && !$planLimitReached) {
        try {
            $pdo->beginTransaction();

            $checkStmt = $pdo->prepare('SELECT id FROM accounts WHERE service_id = ? AND email_id = ? LIMIT 1');
            $insertStmt = $pdo->prepare('INSERT INTO accounts (service_id, email_id, username, status, account_type, owner_user_id)
                VALUES (:service_id, :email_id, :username, :status, :account_type, :owner_user_id)');

            $servicesById = array_column($services, null, 'id');
            $emailsById = array_column($emails, null, 'id');
            $recordIsEditable = static function (array $row): bool {
                return canEditRecord($row['visibility'], $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null);
            };

            $created = 0;
            $skipped = 0;
            $permissionSkipped = 0;
            $ownerUserId = currentUserId();

            foreach ($form['service_ids'] as $serviceId) {
                $service = $servicesById[$serviceId] ?? null;

                foreach ($form['email_ids'] as $emailId) {
                    $email = $emailsById[$emailId] ?? null;

                    // Bulk operations: a member may only act on rows they can
                    // edit (docs/PERMISSIONS.md) — silently skip any
                    // service/email pairing where either side isn't theirs to
                    // edit, rather than erroring or partially applying it.
                    if (($service !== null && !$recordIsEditable($service)) || ($email !== null && !$recordIsEditable($email))) {
                        $permissionSkipped++;
                        continue;
                    }

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
                        'owner_user_id' => $ownerUserId,
                    ]);
                    $accountId = (int) $pdo->lastInsertId();
                    log_history($pdo, 'account', $accountId, 'Account Created');
                    $created++;
                }
            }

            $pdo->commit();

            $message = t('accounts.bulk_created_message', ['count' => $created]);
            if ($skipped > 0) {
                $message .= ' ' . t('accounts.bulk_skipped_message', ['count' => $skipped]);
            }
            if ($permissionSkipped > 0) {
                $message .= ' ' . t('accounts.bulk_permission_skipped_message', ['count' => $permissionSkipped]);
            }
            flashSet($created > 0 ? 'success' : 'warning', $message);
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = t('accounts.bulk_create_error') . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('accounts.bulk_assign_title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('accounts.bulk_assign_title')) ?></h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
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
        <?= e(t('accounts.need_service_and_email_bulk')) ?>
        <a href="../services/add.php"><?= e(t('services.add_title')) ?></a> — <a href="../emails/add.php"><?= e(t('emails.add_title')) ?></a>
    </div>
<?php else: ?>
    <p class="text-muted"><?= e(t('accounts.bulk_assign_intro')) ?></p>

    <form method="post" id="bulk-assign-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card am-card mb-3">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span><?= e(t('services.title')) ?></span>
                        <div class="form-check mb-0">
                            <input type="checkbox" class="form-check-input" id="select-all-services">
                            <label class="form-check-label small" for="select-all-services"><?= e(t('common.select_all')) ?></label>
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
                        <span><?= e(t('emails.title')) ?></span>
                        <div class="form-check mb-0">
                            <input type="checkbox" class="form-check-input" id="select-all-emails">
                            <label class="form-check-label small" for="select-all-emails"><?= e(t('common.select_all')) ?></label>
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
            <div class="card-header bg-white fw-bold"><?= e(t('accounts.bulk_defaults_title')) ?></div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label"><?= e(t('common.field_status')) ?></label>
                    <select name="status" class="form-select"><?= optionsHtml(ACCOUNT_STATUSES, $form['status']) ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= e(t('accounts.field_type_plain')) ?></label>
                    <select name="account_type" class="form-select"><?= optionsHtml(ACCOUNT_TYPES, $form['account_type']) ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= e(t('accounts.field_shared_username')) ?></label>
                    <input type="text" name="username" class="form-control" value="<?= e($form['username']) ?>">
                    <div class="form-text"><?= e(t('accounts.shared_username_hint')) ?></div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-primary"><?= e(t('accounts.create_accounts_button')) ?></button>
            <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
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
        var COUNTER_TEMPLATE = <?= json_encode(t('accounts.bulk_assign_counter'), JSON_UNESCAPED_UNICODE) ?>;

        function countChecked(list) {
            return Array.prototype.filter.call(list, function (cb) { return cb.checked; }).length;
        }

        function updateCounter() {
            var s = countChecked(serviceBoxes);
            var e = countChecked(emailBoxes);
            counter.textContent = (s && e) ? COUNTER_TEMPLATE.replace('{count}', s * e).replace('{s}', s).replace('{e}', e) : '';
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
