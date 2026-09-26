<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
requireWriteAccess();

$pdo = db();
$errors = [];
$form = [
    'phone_number' => '',
    'country' => '',
    'label' => '',
    'status' => 'Unknown',
    'is_primary' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'is_primary') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $form['is_primary'] = isset($_POST['is_primary']) ? '1' : '';

    if ($form['phone_number'] === '') {
        $errors[] = t('phones.number_required');
    }
    if (!array_key_exists($form['status'], PHONE_STATUSES)) {
        $errors[] = t('phones.status_invalid');
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO phones (phone_number, country, label, status, is_primary, notes, owner_user_id)
                VALUES (:phone_number, :country, :label, :status, :is_primary, :notes, :owner_user_id)');
            $stmt->execute([
                'phone_number' => $form['phone_number'],
                'country' => $form['country'] !== '' ? $form['country'] : null,
                'label' => $form['label'] !== '' ? $form['label'] : null,
                'status' => $form['status'],
                'is_primary' => $form['is_primary'] === '1' ? 1 : 0,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'owner_user_id' => currentUserId(),
            ]);
            $newId = (int) $pdo->lastInsertId();
            log_history($pdo, 'phone', $newId, 'Phone Created');

            $pdo->commit();
            flashSet('success', t('phones.created_success'));
            header('Location: view.php?id=' . $newId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = t('phones.duplicate_number');
            } else {
                $errors[] = t('phones.create_error') . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('phones.add_title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('phones.add_title')) ?></h1>
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
                <label class="form-label"><?= e(t('phones.field_number_required')) ?></label>
                <input type="text" name="phone_number" class="form-control" required value="<?= e($form['phone_number']) ?>" placeholder="<?= e(t('phones.field_number_placeholder')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('phones.field_country')) ?></label>
                <input type="text" name="country" class="form-control" value="<?= e($form['country']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= e(t('phones.field_label')) ?></label>
                <input type="text" name="label" class="form-control" value="<?= e($form['label']) ?>" placeholder="<?= e(t('phones.field_label_placeholder')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= e(t('common.field_status_required')) ?></label>
                <select name="status" class="form-select"><?= optionsHtml(PHONE_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" name="is_primary" id="is_primary" class="form-check-input" value="1" <?= $form['is_primary'] === '1' ? 'checked' : '' ?>>
                    <label for="is_primary" class="form-check-label"><?= e(t('phones.field_is_primary')) ?></label>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label"><?= e(t('common.field_notes')) ?></label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary"><?= e(t('phones.save_button')) ?></button>
    <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
