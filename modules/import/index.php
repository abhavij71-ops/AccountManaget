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
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    if (!array_key_exists($entity, IMPORT_ENTITY_LABELS)) {
        $errors[] = 'نوع اطلاعات نامعتبر است.';
    }

    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'یک فایل CSV انتخاب کنید.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'خطا در آپلود فایل.';
    } elseif (strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $errors[] = 'فقط فایل با پسوند csv. پذیرفته می‌شود.';
    }

    if (!$errors) {
        $importDir = DATA_DIR . '/imports';
        if (!is_dir($importDir)) {
            mkdir($importDir, 0755, true);
        }
        $token = bin2hex(random_bytes(16));
        $destination = $importDir . '/' . $token . '.csv';

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $errors[] = 'ذخیره فایل ممکن نشد.';
        } else {
            $parsed = parseCsvFile($destination);
            if ($parsed['error'] !== null) {
                $errors[] = $parsed['error'];
                unlink($destination);
            } elseif (!$parsed['headers']) {
                $errors[] = 'ستون‌های فایل قابل تشخیص نبود.';
                unlink($destination);
            } elseif (!$parsed['rows']) {
                $errors[] = 'فایل هیچ ردیف داده‌ای ندارد.';
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
$pageTitle = 'Import — انتخاب فایل';
require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-4">Import از فایل CSV</h1>

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
            مراحل: انتخاب فایل &larr; تشخیص ستون‌ها &larr; تطبیق ستون‌ها &larr; پیش‌نمایش و اعتبارسنجی &larr;
            تشخیص موارد تکراری &larr; تأیید &larr; Import. هیچ رکورد موجودی بدون تأیید صریح شما بازنویسی نمی‌شود.
        </p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="mb-3">
                <label class="form-label">نوع اطلاعات *</label>
                <select name="entity" class="form-select">
                    <?php foreach (IMPORT_ENTITY_LABELS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $entity === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">برای Import اکانت‌ها، سرویس و ایمیل مربوطه باید از قبل در سیستم ثبت شده باشند.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">فایل CSV *</label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                <div class="form-text">ردیف اول فایل باید عنوان ستون‌ها باشد. حداکثر ۲۰۰۰ ردیف.</div>
            </div>
            <button type="submit" class="btn btn-primary">ادامه</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
