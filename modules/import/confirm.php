<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/import.php';

requireLogin();

$pdo = db();
$importState = $_SESSION['import'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$importState || empty($importState['mapping']) || !is_file($importState['file_path'])) {
    flashSet('danger', 'جلسه Import یافت نشد. لطفاً دوباره شروع کنید.');
    header('Location: index.php');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    flashSet('danger', 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
    header('Location: preview.php');
    exit;
}

$entity = $importState['entity'];
$mapping = $importState['mapping'];
$source = $importState['original_filename'];
$parsed = parseCsvFile($importState['file_path']);

$created = 0;
$updated = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    foreach ($parsed['rows'] as $i => $rawRow) {
        $mapped = applyMapping($rawRow, $mapping);
        $errors = validateImportRow($entity, $mapped, $pdo);

        if ($errors) {
            $skipped++;
            continue;
        }

        $dup = detectDuplicateStatus($entity, $mapped, $pdo);
        $requestedAction = (string) ($_POST['action_' . $i] ?? 'skip');
        $allowedActions = $dup['status'] === 'New' ? ['create', 'skip'] : ['skip', 'update', 'create'];
        $action = in_array($requestedAction, $allowedActions, true) ? $requestedAction : 'skip';

        $result = importRow($pdo, $entity, $mapped, $mapping, $action, $dup['existing_id'], $source);

        match ($result['status']) {
            'created' => $created++,
            'updated' => $updated++,
            default => $skipped++,
        };
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    flashSet('danger', 'خطا در Import: ' . $e->getMessage());
    header('Location: preview.php');
    exit;
}

if (is_file($importState['file_path'])) {
    unlink($importState['file_path']);
}
unset($_SESSION['import']);

$pageTitle = 'Import — نتیجه';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-4">نتیجه Import</h1>

<div class="card am-card">
    <div class="card-body text-center py-4">
        <p class="mb-3">Import از فایل «<?= e($source) ?>» برای <?= e(IMPORT_ENTITY_LABELS[$entity]) ?> انجام شد.</p>
        <div class="d-flex justify-content-center gap-4">
            <div>
                <div class="h3 mb-0 text-success"><?= (int) $created ?></div>
                <div class="text-muted small">ایجادشده</div>
            </div>
            <div>
                <div class="h3 mb-0 text-primary"><?= (int) $updated ?></div>
                <div class="text-muted small">به‌روزرسانی‌شده</div>
            </div>
            <div>
                <div class="h3 mb-0 text-muted"><?= (int) $skipped ?></div>
                <div class="text-muted small">رد شده</div>
            </div>
        </div>
        <div class="mt-4 d-flex justify-content-center gap-2">
            <a href="index.php" class="btn btn-outline-primary">Import دیگر</a>
            <a href="../<?= e($entity) ?>s/index.php" class="btn btn-primary">مشاهده فهرست</a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
