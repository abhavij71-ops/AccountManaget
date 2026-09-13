<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/import.php';

requireLogin();

$pdo = db();
$importState = $_SESSION['import'] ?? null;
if (!$importState || empty($importState['mapping']) || !is_file($importState['file_path'])) {
    flashSet('danger', t('import.select_mapping_first'));
    header('Location: index.php');
    exit;
}

$entity = $importState['entity'];
$mapping = $importState['mapping'];
$parsed = parseCsvFile($importState['file_path']);

$rowsInfo = [];
$summary = ['New' => 0, 'Exact Duplicate' => 0, 'Possible Duplicate' => 0, 'Error' => 0];

foreach ($parsed['rows'] as $i => $rawRow) {
    $mapped = applyMapping($rawRow, $mapping);
    $errors = validateImportRow($entity, $mapped, $pdo);

    if ($errors) {
        $summary['Error']++;
        $rowsInfo[] = ['index' => $i, 'mapped' => $mapped, 'errors' => $errors, 'dup' => null];
        continue;
    }

    $dup = detectDuplicateStatus($entity, $mapped, $pdo);
    $summary[$dup['status']]++;
    $rowsInfo[] = ['index' => $i, 'mapped' => $mapped, 'errors' => [], 'dup' => $dup];
}

$fields = importEntityFields($entity);
$previewFieldKeys = array_slice(array_column($fields, 'key'), 0, 4);

$csrf = csrfToken();
$pageTitle = t('import.preview_title');
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-1"><?= e(t('import.preview_heading')) ?></h1>
<p class="text-muted"><?= e(t('import.type_label')) ?> <?= e(importEntityLabel($entity)) ?> — <?= e(t('import.file_label')) ?> <?= e($importState['original_filename']) ?></p>

<div class="d-flex gap-2 flex-wrap mb-3">
    <span class="badge badge-category"><?= e(t('import.new_label')) ?>: <?= (int) $summary['New'] ?></span>
    <span class="badge badge-status-suspended"><?= e(t('import.exact_dup_label')) ?>: <?= (int) $summary['Exact Duplicate'] ?></span>
    <span class="badge badge-status-pending"><?= e(t('import.possible_dup_label')) ?>: <?= (int) $summary['Possible Duplicate'] ?></span>
    <span class="badge badge-disabled"><?= e(t('import.validation_error_label')) ?>: <?= (int) $summary['Error'] ?></span>
</div>

<?php if ($summary['Error'] > 0): ?>
    <div class="alert alert-warning">
        <?= e(t('import.error_rows_note')) ?>
    </div>
<?php endif; ?>

<form method="post" action="confirm.php">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="card am-card mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php foreach ($previewFieldKeys as $k): ?>
                            <th><?= e($fields[array_search($k, array_column($fields, 'key'), true)]['label']) ?></th>
                        <?php endforeach; ?>
                        <th><?= e(t('common.field_status')) ?></th>
                        <th><?= e(t('import.th_action')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rowsInfo as $row): ?>
                    <tr>
                        <td><?= (int) $row['index'] + 1 ?></td>
                        <?php foreach ($previewFieldKeys as $k): ?>
                            <td><?= dashOrValue($row['mapped'][$k] ?? null) ?></td>
                        <?php endforeach; ?>
                        <td>
                            <?php if ($row['errors']): ?>
                                <span class="badge badge-disabled" title="<?= e(implode(' | ', $row['errors'])) ?>"><?= e(t('import.error_badge')) ?></span>
                                <div class="small text-danger"><?= e($row['errors'][0]) ?></div>
                            <?php elseif ($row['dup']['status'] === 'New'): ?>
                                <span class="badge badge-category"><?= e(t('import.new_label')) ?></span>
                            <?php elseif ($row['dup']['status'] === 'Exact Duplicate'): ?>
                                <span class="badge badge-status-suspended"><?= e(t('import.exact_dup_short')) ?></span>
                            <?php else: ?>
                                <span class="badge badge-status-pending"><?= e(t('import.possible_dup_short')) ?></span>
                                <div class="small text-muted"><?= e(t('import.diff_in_prefix')) ?><?= e(implode('، ', $row['dup']['diff_fields'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['errors']): ?>
                                <input type="hidden" name="action_<?= (int) $row['index'] ?>" value="skip">
                                <span class="text-muted small"><?= e(t('import.skipped_auto')) ?></span>
                            <?php elseif ($row['dup']['status'] === 'New'): ?>
                                <select name="action_<?= (int) $row['index'] ?>" class="form-select form-select-sm">
                                    <option value="create" selected><?= e(t('import.action_create')) ?></option>
                                    <option value="skip"><?= e(t('import.action_skip')) ?></option>
                                </select>
                            <?php else: ?>
                                <select name="action_<?= (int) $row['index'] ?>" class="form-select form-select-sm">
                                    <option value="skip" selected><?= e(t('import.action_skip_full')) ?></option>
                                    <option value="update"><?= e(t('import.action_update_full')) ?></option>
                                    <option value="create"><?= e(t('import.action_create_full')) ?></option>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small"><?= e(t('import.no_overwrite_note')) ?></p>
    <button type="submit" class="btn btn-primary"><?= e(t('import.confirm_button')) ?></button>
    <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
