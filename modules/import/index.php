<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/import.php';

requireLogin();

$errors = [];
$entity = (string) ($_POST['entity'] ?? 'account');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    if (!array_key_exists($entity, IMPORT_ENTITY_LABELS)) {
        $errors[] = t('import.invalid_entity_type');
    }

    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = t('import.no_file_selected');
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = t('import.upload_error');
    } elseif (strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $errors[] = t('import.only_csv_accepted');
    }

    if (!$errors) {
        $importDir = DATA_DIR . '/imports';
        if (!is_dir($importDir)) {
            mkdir($importDir, 0755, true);
        }
        $token = bin2hex(random_bytes(16));
        $destination = $importDir . '/' . $token . '.csv';

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $errors[] = t('import.save_failed');
        } else {
            $parsed = parseCsvFile($destination);
            if ($parsed['error'] !== null) {
                $errors[] = $parsed['error'];
                unlink($destination);
            } elseif (!$parsed['headers']) {
                $errors[] = t('import.columns_undetected');
                unlink($destination);
            } elseif (!$parsed['rows']) {
                $errors[] = t('import.no_data_rows');
                unlink($destination);
            } else {
                $_SESSION['import'] = [
                    'token' => $token,
                    'entity' => $entity,
                    'file_path' => $destination,
                    'original_filename' => basename($file['name']),
                    'row_count' => count($parsed['rows']),
                ];
                header('Location: mapping.php');
                exit;
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('import.select_file_title');
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('import.from_csv_heading')) ?></h1>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card am-card">
    <div class="card-body">
        <p class="text-muted small">
            <?= e(t('import.steps_note')) ?>
        </p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="mb-3">
                <label class="form-label"><?= e(t('import.field_entity_type_required')) ?></label>
                <select name="entity" class="form-select">
                    <?php foreach (IMPORT_ENTITY_LABELS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $entity === $key ? 'selected' : '' ?>><?= e(importEntityLabel($key)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?= e(t('import.accounts_need_service_email')) ?></div>
            </div>
            <div class="mb-3">
                <label class="form-label"><?= e(t('import.field_csv_file_required')) ?></label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                <div class="form-text"><?= e(t('import.csv_file_hint')) ?></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= e(t('import.continue_button')) ?></button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
