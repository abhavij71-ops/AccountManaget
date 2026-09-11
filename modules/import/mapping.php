<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/import.php';

requireLogin();

$importState = $_SESSION['import'] ?? null;
if (!$importState || !is_file($importState['file_path'])) {
    flashSet('danger', 'ابتدا یک فایل برای Import انتخاب کنید.');
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
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
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
        $errors[] = 'این فیلدهای الزامی باید به یک ستون نگاشت شوند: ' . implode('، ', $missingRequired);
    }

    if (!$errors) {
        $_SESSION['import']['mapping'] = $mapping;
        header('Location: preview.php');
        exit;
    }
}

$guessedMapping = guessColumnMapping($headers, $fields);

$csrf = csrfToken();
$pageTitle = 'Import — تطبیق ستون‌ها';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-1">تطبیق ستون‌ها</h1>
<p class="text-muted">نوع: <?= e(IMPORT_ENTITY_LABELS[$entity]) ?> — فایل: <?= e($importState['original_filename']) ?> — <?= (int) $importState['row_count'] ?> ردیف</p>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card am-card">
    <div class="card-body">
        <p class="text-muted small">برای هر فیلد، ستون متناظر در فایل CSV را انتخاب کنید. فیلدهای بدون ستون نگاشته‌نشده، خالی در نظر گرفته می‌شوند.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>فیلد</th><th>ستون در فایل CSV</th></tr></thead>
                    <tbody>
                    <?php foreach ($fields as $field): ?>
                        <tr>
                            <td class="text-nowrap"><?= e($field['label']) ?><?= $field['required'] ? ' *' : '' ?></td>
                            <td>
                                <select name="map_<?= e($field['key']) ?>" class="form-select form-select-sm">
                                    <option value="">— نادیده گرفتن —</option>
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
            <button type="submit" class="btn btn-primary">ادامه به پیش‌نمایش</button>
            <a href="index.php" class="btn btn-outline-secondary">انصراف</a>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
