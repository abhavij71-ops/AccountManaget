<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) {
    flashSet('danger', 'سرویس مورد نظر یافت نشد.');
    header('Location: index.php');
    exit;
}

$errors = [];
$form = [
    'service_name' => $service['service_name'],
    'website' => (string) ($service['website'] ?? ''),
    'login_url' => (string) ($service['login_url'] ?? ''),
    'category' => $service['category'],
    'status' => $service['status'],
    'purpose' => (string) ($service['purpose'] ?? ''),
    'notes' => (string) ($service['notes'] ?? ''),
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

            if ($form['status'] !== $service['status']) {
                log_history($pdo, 'service', $id, 'Status Changed', 'status', $service['status'], $form['status']);
            }
            $baseDiffFields = ['service_name', 'website', 'login_url', 'category', 'purpose', 'notes'];
            foreach ($baseDiffFields as $f) {
                $old = (string) ($service[$f] ?? '');
                if ($old !== $form[$f]) {
                    log_history($pdo, 'service', $id, 'Service Updated', $f, $old !== '' ? $old : null, $form[$f] !== '' ? $form[$f] : null);
                }
            }

            $stmt = $pdo->prepare('UPDATE services SET
                service_name = :service_name, website = :website, login_url = :login_url,
                category = :category, status = :status, purpose = :purpose, notes = :notes
                WHERE id = :id');
            $stmt->execute([
                'service_name' => $form['service_name'],
                'website' => $form['website'] !== '' ? $form['website'] : null,
                'login_url' => $form['login_url'] !== '' ? $form['login_url'] : null,
                'category' => $form['category'],
                'status' => $form['status'],
                'purpose' => $form['purpose'] !== '' ? $form['purpose'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'id' => $id,
            ]);

            $pdo->commit();
            flashSet('success', 'تغییرات با موفقیت ذخیره شد.');
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = 'سرویسی با این نام قبلاً ثبت شده است.';
            } else {
                $errors[] = 'خطا در ذخیره تغییرات: ' . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = 'ویرایش سرویس';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">ویرایش سرویس: <?= e($service['service_name']) ?></h1>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary btn-sm">بازگشت به پروفایل</a>
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
                <input type="text" name="category" class="form-control" list="category-list" value="<?= e($form['category']) ?>">
                <datalist id="category-list">
                    <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-6">
                <label class="form-label">وب‌سایت</label>
                <input type="url" name="website" class="form-control" value="<?= e($form['website']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">آدرس ورود (Login URL)</label>
                <input type="url" name="login_url" class="form-control" value="<?= e($form['login_url']) ?>">
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
    <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">انصراف</a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
