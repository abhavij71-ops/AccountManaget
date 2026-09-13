<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/import.php';

requireLogin();

$pdo = db();
$importState = $_SESSION['import'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$importState || empty($importState['mapping']) || !is_file($importState['file_path'])) {
    flashSet('danger', t('import.session_not_found'));
    header('Location: index.php');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    flashSet('danger', t('msg.invalid_request'));
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
    flashSet('danger', t('import.error_prefix') . $e->getMessage());
    header('Location: preview.php');
    exit;
}

if (is_file($importState['file_path'])) {
    unlink($importState['file_path']);
}
unset($_SESSION['import']);

$pageTitle = t('import.result_title');
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('import.result_heading')) ?></h1>

<div class="card am-card">
    <div class="card-body text-center py-4">
        <p class="mb-3"><?= e(t('import.result_summary', ['file' => $source, 'type' => importEntityLabel($entity)])) ?></p>
        <div class="d-flex justify-content-center gap-4">
            <div>
                <div class="h3 mb-0 text-success"><?= (int) $created ?></div>
                <div class="text-muted small"><?= e(t('import.created_label')) ?></div>
            </div>
            <div>
                <div class="h3 mb-0 text-primary"><?= (int) $updated ?></div>
                <div class="text-muted small"><?= e(t('import.updated_label')) ?></div>
            </div>
            <div>
                <div class="h3 mb-0 text-muted"><?= (int) $skipped ?></div>
                <div class="text-muted small"><?= e(t('import.skipped_label')) ?></div>
            </div>
        </div>
        <div class="mt-4 d-flex justify-content-center gap-2">
            <a href="index.php" class="btn btn-outline-primary"><?= e(t('import.another_import')) ?></a>
            <a href="../<?= e($entity) ?>s/index.php" class="btn btn-primary"><?= e(t('import.view_list')) ?></a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
