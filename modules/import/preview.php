<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/import.php';

requireLogin();

$pdo = db();
$importState = $_SESSION['import'] ?? null;
if (!$importState || empty($importState['mapping']) || !is_file($importState['file_path'])) {
    flashSet('danger', 'ابتدا فایل و تطبیق ستون‌ها را مشخص کنید.');
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
$pageTitle = 'Import — پیش‌نمایش';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-1">پیش‌نمایش و تشخیص موارد تکراری</h1>
<p class="text-muted">نوع: <?= e(IMPORT_ENTITY_LABELS[$entity]) ?> — فایل: <?= e($importState['original_filename']) ?></p>

<div class="d-flex gap-2 flex-wrap mb-3">
    <span class="badge badge-category">جدید: <?= (int) $summary['New'] ?></span>
    <span class="badge badge-status-suspended">تکراری دقیق (Exact): <?= (int) $summary['Exact Duplicate'] ?></span>
    <span class="badge badge-status-pending">احتمالاً تکراری (Possible): <?= (int) $summary['Possible Duplicate'] ?></span>
    <span class="badge badge-disabled">خطای اعتبارسنجی: <?= (int) $summary['Error'] ?></span>
</div>

<?php if ($summary['Error'] > 0): ?>
    <div class="alert alert-warning">
        ردیف‌های دارای خطای اعتبارسنجی به‌طور خودکار نادیده گرفته می‌شوند و Import نمی‌شوند؛ بقیه ردیف‌ها همچنان قابل Import هستند.
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
                        <th>وضعیت</th>
                        <th>اقدام</th>
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
                                <span class="badge badge-disabled" title="<?= e(implode(' | ', $row['errors'])) ?>">خطا</span>
                                <div class="small text-danger"><?= e($row['errors'][0]) ?></div>
                            <?php elseif ($row['dup']['status'] === 'New'): ?>
                                <span class="badge badge-category">جدید</span>
                            <?php elseif ($row['dup']['status'] === 'Exact Duplicate'): ?>
                                <span class="badge badge-status-suspended">تکراری دقیق</span>
                            <?php else: ?>
                                <span class="badge badge-status-pending">احتمالاً تکراری</span>
                                <div class="small text-muted">تفاوت در: <?= e(implode('، ', $row['dup']['diff_fields'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['errors']): ?>
                                <input type="hidden" name="action_<?= (int) $row['index'] ?>" value="skip">
                                <span class="text-muted small">نادیده گرفته می‌شود</span>
                            <?php elseif ($row['dup']['status'] === 'New'): ?>
                                <select name="action_<?= (int) $row['index'] ?>" class="form-select form-select-sm">
                                    <option value="create" selected>ایجاد</option>
                                    <option value="skip">رد کردن</option>
                                </select>
                            <?php else: ?>
                                <select name="action_<?= (int) $row['index'] ?>" class="form-select form-select-sm">
                                    <option value="skip" selected>رد کردن (Skip)</option>
                                    <option value="update">به‌روزرسانی موجود (Update Existing)</option>
                                    <option value="create">ایجاد رکورد جدید (Create New)</option>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small">هیچ رکورد موجودی بدون انتخاب صریح «به‌روزرسانی موجود» توسط شما تغییر نخواهد کرد.</p>
    <button type="submit" class="btn btn-primary">تأیید و Import</button>
    <a href="index.php" class="btn btn-outline-secondary">انصراف</a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
