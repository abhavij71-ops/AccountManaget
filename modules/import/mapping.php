<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/import.php';

requireLogin();
requireRole('owner', 'admin');

$importState = $_SESSION['import'] ?? null;
if (!$importState || !is_file($importState['file_path'])) {
    flashSet('danger', t('import.select_file_first'));
    header('Location: index.php');
    exit;
}

$entity = $importState['entity'];
$fields = importEntityFields($entity);
$parsed = parseCsvFile($importState['file_path']);
$headers = $parsed['headers'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    $mapping = [];
    foreach ($fields as $field) {
        $selected = (string) ($_POST['map_' . $field['key']] ?? '');
        $mapping[$field['key']] = ($selected !== '' && in_array($selected, $headers, true)) ? $selected : null;
    }

    $missingRequired = [];
    foreach ($fields as $field) {
        if ($field['required'] && $mapping[$field['key']] === null) {
            $missingRequired[] = $field['label'];
        }
    }
    if ($missingRequired) {
        $errors[] = t('import.required_fields_prefix') . implode(t('common.list_separator'), $missingRequired);
    }

    if (!$errors) {
        $_SESSION['import']['mapping'] = $mapping;
        header('Location: preview.php');
        exit;
    }
}

$guessedMapping = guessColumnMapping($headers, $fields);

$csrf = csrfToken();
$pageTitle = t('import.mapping_title');
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-1"><?= e(t('import.map_columns_heading')) ?></h1>
<p class="text-muted"><?= e(t('import.type_label')) ?> <?= e(importEntityLabel($entity)) ?> — <?= e(t('import.file_label')) ?> <?= e($importState['original_filename']) ?> — <?= (int) $importState['row_count'] ?> <?= e(t('import.rows_suffix')) ?></p>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card am-card">
    <div class="card-body">
        <p class="text-muted small"><?= e(t('import.map_instructions')) ?></p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th><?= e(t('import.th_field')) ?></th><th><?= e(t('import.th_csv_column')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($fields as $field): ?>
                        <tr>
                            <td class="text-nowrap"><?= e($field['label']) ?><?= $field['required'] ? ' *' : '' ?></td>
                            <td>
                                <select name="map_<?= e($field['key']) ?>" class="form-select form-select-sm">
                                    <option value=""><?= e(t('import.ignore_option')) ?></option>
                                    <?php foreach ($headers as $h): ?>
                                        <option value="<?= e($h) ?>" <?= $guessedMapping[$field['key']] === $h ? 'selected' : '' ?>><?= e($h) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary"><?= e(t('import.continue_to_preview')) ?></button>
            <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
