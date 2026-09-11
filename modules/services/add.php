<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();
$errors = [];
$form = [
    'service_name' => '',
    'website' => '',
    'login_url' => '',
    'category' => '',
    'status' => 'Unknown',
    'purpose' => '',
    'notes' => '',
];

$categories = $pdo->query("SELECT DISTINCT category FROM services WHERE category != 'Not Set' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['service_name'] === '') {
        $errors[] = 'نام سرویس الزامی است.';
    }
    if (!array_key_exists($form['status'], SERVICE_STATUSES)) {
        $errors[] = 'وضعیت سرویس نامعتبر است.';
    }
    if ($form['category'] === '') {
        $form['category'] = 'Not Set';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO services (service_name, website, login_url, category, status, purpose, notes)
                VALUES (:service_name, :website, :login_url, :category, :status, :purpose, :notes)');
            $stmt->execute([
                'service_name' => $form['service_name'],
                'website' => $form['website'] !== '' ? $form['website'] : null,
                'login_url' => $form['login_url'] !== '' ? $form['login_url'] : null,
                'category' => $form['category'],
                'status' => $form['status'],
                'purpose' => $form['purpose'] !== '' ? $form['purpose'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            log_history($pdo, 'service', $newId, 'Service Created');

            $pdo->commit();
            flashSet('success', 'سرویس با موفقیت ثبت شد.');
            header('Location: view.php?id=' . $newId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = 'سرویسی با این نام قبلاً ثبت شده است.';
            } else {
                $errors[] = 'خطا در ثبت سرویس: ' . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = 'افزودن سرویس';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">افزودن سرویس</h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">بازگشت به فهرست</a>
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
                <label class="form-label">نام سرویس *</label>
                <input type="text" name="service_name" class="form-control" required value="<?= e($form['service_name']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">دسته‌بندی</label>
                <input type="text" name="category" class="form-control" list="category-list" value="<?= e($form['category']) ?>" placeholder="مثلاً Dev Tools, Cloud, Social">
                <datalist id="category-list">
                    <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-6">
                <label class="form-label">وب‌سایت</label>
                <input type="url" name="website" class="form-control" value="<?= e($form['website']) ?>" placeholder="https://">
            </div>
            <div class="col-md-6">
                <label class="form-label">آدرس ورود (Login URL)</label>
                <input type="url" name="login_url" class="form-control" value="<?= e($form['login_url']) ?>" placeholder="https://">
            </div>
            <div class="col-md-4">
                <label class="form-label">وضعیت *</label>
                <select name="status" class="form-select"><?= optionsHtml(SERVICE_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-8">
                <label class="form-label">هدف استفاده (Purpose)</label>
                <input type="text" name="purpose" class="form-control" value="<?= e($form['purpose']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">یادداشت</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">ذخیره سرویس</button>
    <a href="index.php" class="btn btn-outline-secondary">انصراف</a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
