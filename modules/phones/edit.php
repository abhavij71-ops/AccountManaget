<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM phones WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$phone = $stmt->fetch();

if (!$phone) {
    flashSet('danger', 'شماره تلفن مورد نظر یافت نشد.');
    header('Location: index.php');
    exit;
}

$errors = [];
$form = [
    'phone_number' => $phone['phone_number'],
    'country' => (string) ($phone['country'] ?? ''),
    'label' => (string) ($phone['label'] ?? ''),
    'status' => $phone['status'],
    'is_primary' => ((int) $phone['is_primary']) === 1 ? '1' : '',
    'notes' => (string) ($phone['notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    foreach (array_keys($form) as $key) {
        if ($key === 'is_primary') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $form['is_primary'] = isset($_POST['is_primary']) ? '1' : '';

    if ($form['phone_number'] === '') {
        $errors[] = 'شماره تلفن الزامی است.';
    }
    if (!array_key_exists($form['status'], PHONE_STATUSES)) {
        $errors[] = 'وضعیت نامعتبر است.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($form['status'] !== $phone['status']) {
                log_history($pdo, 'phone', $id, 'Status Changed', 'status', $phone['status'], $form['status']);
            }
            $baseDiffFields = ['phone_number', 'country', 'label', 'notes'];
            foreach ($baseDiffFields as $f) {
                $old = (string) ($phone[$f] ?? '');
                if ($old !== $form[$f]) {
                    log_history($pdo, 'phone', $id, 'Phone Updated', $f, $old !== '' ? $old : null, $form[$f] !== '' ? $form[$f] : null);
                }
            }
            $oldPrimary = ((int) $phone['is_primary']) === 1 ? '1' : '0';
            $newPrimary = $form['is_primary'] === '1' ? '1' : '0';
            if ($oldPrimary !== $newPrimary) {
                log_history($pdo, 'phone', $id, 'Phone Updated', 'is_primary', $oldPrimary, $newPrimary);
            }

            $stmt = $pdo->prepare('UPDATE phones SET
                phone_number = :phone_number, country = :country, label = :label,
                status = :status, is_primary = :is_primary, notes = :notes
                WHERE id = :id');
            $stmt->execute([
                'phone_number' => $form['phone_number'],
                'country' => $form['country'] !== '' ? $form['country'] : null,
                'label' => $form['label'] !== '' ? $form['label'] : null,
                'status' => $form['status'],
                'is_primary' => $form['is_primary'] === '1' ? 1 : 0,
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
                $errors[] = 'این شماره تلفن قبلاً برای رکورد دیگری ثبت شده است.';
            } else {
                $errors[] = 'خطا در ذخیره تغییرات: ' . $e->getMessage();
            }
        }
    }
}

$csrf = csrfToken();
$pageTitle = 'ویرایش شماره تلفن';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">ویرایش شماره تلفن: <?= e($phone['phone_number']) ?></h1>
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
                <label class="form-label">شماره تلفن *</label>
                <input type="text" name="phone_number" class="form-control" required value="<?= e($form['phone_number']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">کشور</label>
                <input type="text" name="country" class="form-control" value="<?= e($form['country']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">برچسب (Label)</label>
                <input type="text" name="label" class="form-control" value="<?= e($form['label']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">وضعیت *</label>
                <select name="status" class="form-select"><?= optionsHtml(PHONE_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" name="is_primary" id="is_primary" class="form-check-input" value="1" <?= $form['is_primary'] === '1' ? 'checked' : '' ?>>
                    <label for="is_primary" class="form-check-label">شماره اصلی (Primary)</label>
                </div>
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
