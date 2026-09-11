<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$pdo = db();
$errors = [];
$form = [
    'service_id' => '',
    'email_id' => '',
    'username' => '',
    'status' => 'Active',
    'plan' => '',
    'notes' => '',
];

$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();
$emails = $pdo->query('SELECT id, email_address FROM emails ORDER BY email_address')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $serviceId = (int) $form['service_id'];
    $emailId = (int) $form['email_id'];

    if (!$services) {
        $errors[] = 'ابتدا باید حداقل یک سرویس ثبت کنید.';
    } elseif ($serviceId <= 0 || !in_array($serviceId, array_column($services, 'id'), true)) {
        $errors[] = 'سرویس معتبر انتخاب کنید.';
    }
    if (!$emails) {
        $errors[] = 'ابتدا باید حداقل یک ایمیل ثبت کنید.';
    } elseif ($emailId <= 0 || !in_array($emailId, array_column($emails, 'id'), true)) {
        $errors[] = 'ایمیل معتبر انتخاب کنید.';
    }
    if (!array_key_exists($form['status'], ACCOUNT_STATUSES)) {
        $errors[] = 'وضعیت نامعتبر است.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO accounts (service_id, email_id, username, status, notes)
                VALUES (:service_id, :email_id, :username, :status, :notes)');
            $stmt->execute([
                'service_id' => $serviceId,
                'email_id' => $emailId,
                'username' => $form['username'] !== '' ? $form['username'] : null,
                'status' => $form['status'],
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $accountId = (int) $pdo->lastInsertId();

            if ($form['plan'] !== '') {
                $stmt = $pdo->prepare('INSERT INTO subscriptions (account_id, plan) VALUES (?, ?)');
                $stmt->execute([$accountId, $form['plan']]);
            }

            log_history($pdo, 'account', $accountId, 'Account Created');

            $pdo->commit();
            flashSet('success', 'اکانت با موفقیت ثبت شد.');
            header('Location: view.php?id=' . $accountId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'خطا در ثبت اکانت: ' . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
$pageTitle = 'افزودن سریع اکانت';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">افزودن سریع اکانت</h1>
    <div class="d-flex gap-2">
        <a href="add.php" class="btn btn-outline-secondary btn-sm">فرم کامل</a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">بازگشت به فهرست</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$services || !$emails): ?>
    <div class="alert alert-warning">
        برای افزودن اکانت ابتدا باید حداقل یک سرویس و یک ایمیل ثبت شده باشد.
        <a href="../services/add.php">افزودن سرویس</a> — <a href="../emails/add.php">افزودن ایمیل</a>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div class="card am-card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">سرویس *</label>
                <select name="service_id" class="form-select" required>
                    <option value="">— انتخاب کنید —</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $form['service_id'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['service_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">ایمیل *</label>
                <select name="email_id" class="form-select" required>
                    <option value="">— انتخاب کنید —</option>
                    <?php foreach ($emails as $em): ?>
                        <option value="<?= (int) $em['id'] ?>" <?= $form['email_id'] === (string) $em['id'] ? 'selected' : '' ?>><?= e($em['email_address']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">نام کاربری</label>
                <input type="text" name="username" class="form-control" value="<?= e($form['username']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">وضعیت *</label>
                <select name="status" class="form-select"><?= optionsHtml(ACCOUNT_STATUSES, $form['status']) ?></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">پلن (Plan)</label>
                <input type="text" name="plan" class="form-control" value="<?= e($form['plan']) ?>" placeholder="مثلاً Free, Pro">
            </div>
            <div class="col-12">
                <label class="form-label">یادداشت</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">ذخیره اکانت</button>
    <a href="index.php" class="btn btn-outline-secondary">انصراف</a>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
