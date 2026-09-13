<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();

$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = [];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn ($v) => $v > 0)));

if (!$ids) {
    flashSet('danger', t('emails.no_emails_selected'));
    header('Location: index.php');
    exit;
}

$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM emails WHERE id IN ($placeholders) ORDER BY email_address");
$stmt->execute($ids);
$emails = $stmt->fetchAll();

if (!$emails) {
    flashSet('danger', t('emails.no_valid_emails'));
    header('Location: index.php');
    exit;
}

$ids = array_map(static fn ($e) => (int) $e['id'], $emails);
$errors = [];
$mode = ($_POST['mode'] ?? 'bulk') === 'individual' ? 'individual' : 'bulk';

if (($_POST['action'] ?? '') === 'apply') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    } else {
        try {
            $pdo->beginTransaction();

            if ($mode === 'bulk') {
                $newStatus = (string) ($_POST['bulk_status'] ?? '');
                $newType = (string) ($_POST['bulk_type'] ?? '');

                if ($newStatus !== '' && !array_key_exists($newStatus, EMAIL_STATUSES)) {
                    $errors[] = t('emails.invalid_status_selected');
                }
                if ($newType !== '' && !array_key_exists($newType, EMAIL_TYPES)) {
                    $errors[] = t('emails.invalid_type_selected');
                }

                if (!$errors) {
                    foreach ($emails as $email) {
                        $updates = [];
                        if ($newStatus !== '' && $newStatus !== $email['status']) {
                            $updates['status'] = $newStatus;
                        }
                        if ($newType !== '' && $newType !== $email['type']) {
                            $updates['type'] = $newType;
                        }
                        applyEmailQuickUpdate($pdo, (int) $email['id'], $updates, $email);
                    }
                }
            } else {
                $statusInput = is_array($_POST['status'] ?? null) ? $_POST['status'] : [];
                $typeInput = is_array($_POST['type'] ?? null) ? $_POST['type'] : [];

                foreach ($emails as $email) {
                    $id = (int) $email['id'];
                    $rowStatus = (string) ($statusInput[$id] ?? $email['status']);
                    $rowType = (string) ($typeInput[$id] ?? $email['type']);

                    if (!array_key_exists($rowStatus, EMAIL_STATUSES) || !array_key_exists($rowType, EMAIL_TYPES)) {
                        $errors[] = t('emails.invalid_row_value');
                        break;
                    }
                }

                if (!$errors) {
                    foreach ($emails as $email) {
                        $id = (int) $email['id'];
                        $rowStatus = (string) ($statusInput[$id] ?? $email['status']);
                        $rowType = (string) ($typeInput[$id] ?? $email['type']);

                        $updates = [];
                        if ($rowStatus !== $email['status']) {
                            $updates['status'] = $rowStatus;
                        }
                        if ($rowType !== $email['type']) {
                            $updates['type'] = $rowType;
                        }
                        applyEmailQuickUpdate($pdo, $id, $updates, $email);
                    }
                }
            }

            if ($errors) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
                flashSet('success', t('emails.bulk_apply_success', ['count' => count($emails)]));
                header('Location: index.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = t('msg.save_error') . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('emails.bulk_edit_title', ['count' => count($emails)]);
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('emails.bulk_edit_title', ['count' => count($emails)])) ?></h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card am-card mb-3">
    <div class="card-body">
        <div class="mb-2 fw-bold"><?= e(t('emails.selected_emails_label')) ?></div>
        <ul class="mb-0">
            <?php foreach ($emails as $email): ?>
                <li><?= e($email['email_address']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<form method="post" id="bulk-edit-apply-form">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="apply">
    <?php foreach ($ids as $id): ?>
        <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
    <?php endforeach; ?>

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('emails.edit_method_title')) ?></div>
        <div class="card-body">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mode" id="mode-bulk" value="bulk" <?= $mode === 'bulk' ? 'checked' : '' ?>>
                <label class="form-check-label" for="mode-bulk"><?= e(t('emails.mode_shared_label')) ?></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mode" id="mode-individual" value="individual" <?= $mode === 'individual' ? 'checked' : '' ?>>
                <label class="form-check-label" for="mode-individual"><?= e(t('emails.mode_individual_label')) ?></label>
            </div>
        </div>
    </div>

    <div id="panel-bulk" class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('emails.shared_value_title')) ?></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= e(t('common.field_status')) ?></label>
                <select name="bulk_status" class="form-select">
                    <option value=""><?= e(t('emails.no_change_option')) ?></option>
                    <?= optionsHtml(EMAIL_STATUSES) ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('common.field_type')) ?></label>
                <select name="bulk_type" class="form-select">
                    <option value=""><?= e(t('emails.no_change_option')) ?></option>
                    <?= optionsHtml(EMAIL_TYPES) ?>
                </select>
            </div>
            <div class="col-12 text-muted small"><?= e(t('emails.no_change_hint')) ?></div>
        </div>
    </div>

    <div id="panel-individual" class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('emails.individual_edit_title')) ?></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th><?= e(t('emails.th_address')) ?></th>
                        <th style="width:220px;"><?= e(t('common.field_status')) ?></th>
                        <th style="width:220px;"><?= e(t('common.field_type')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emails as $email): ?>
                        <tr>
                            <td><?= e($email['email_address']) ?></td>
                            <td>
                                <select name="status[<?= (int) $email['id'] ?>]" class="form-select form-select-sm">
                                    <?= optionsHtml(EMAIL_STATUSES, $email['status']) ?>
                                </select>
                            </td>
                            <td>
                                <select name="type[<?= (int) $email['id'] ?>]" class="form-select form-select-sm">
                                    <?= optionsHtml(EMAIL_TYPES, $email['type']) ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><?= e(t('emails.apply_changes_button')) ?></button>
    <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>

<script>
(function () {
    var bulkRadio = document.getElementById('mode-bulk');
    var individualRadio = document.getElementById('mode-individual');
    var panelBulk = document.getElementById('panel-bulk');
    var panelIndividual = document.getElementById('panel-individual');

    function sync() {
        var isIndividual = individualRadio.checked;
        panelBulk.style.display = isIndividual ? 'none' : '';
        panelIndividual.style.display = isIndividual ? '' : 'none';
    }

    bulkRadio.addEventListener('change', sync);
    individualRadio.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
