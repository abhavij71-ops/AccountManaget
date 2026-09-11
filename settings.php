<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

$pdo = db();
$user = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([currentUserId()]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, $hash)) {
            $errors[] = 'رمز عبور فعلی صحیح نیست.';
        } elseif (mb_strlen($newPassword) < 8) {
            $errors[] = 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.';
        } elseif ($newPassword !== $newPasswordConfirm) {
            $errors[] = 'رمز عبور جدید و تکرار آن یکسان نیستند.';
        }
    }

    if (!$errors) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, currentUserId()]);
        flashSet('success', 'رمز عبور با موفقیت تغییر کرد.');
        header('Location: settings.php');
        exit;
    }
}

$csrf = csrfToken();
$pageTitle = 'تنظیمات';
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4">تنظیمات</h1>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">حساب کاربری</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">نام کاربری</dt><dd class="col-7"><?= e($user['username'] ?? '') ?></dd>
                    <dt class="col-5">نام کامل</dt><dd class="col-7"><?= dashOrValue($user['full_name'] ?? null) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">تغییر رمز عبور</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <div class="mb-3">
                        <label class="form-label">رمز عبور فعلی</label>
                        <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رمز عبور جدید</label>
                        <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تکرار رمز عبور جدید</label>
                        <input type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-primary">تغییر رمز عبور</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
