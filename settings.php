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
        $errors[] = t('msg.invalid_request');
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([currentUserId()]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, $hash)) {
            $errors[] = t('settings.password_incorrect');
        } elseif (mb_strlen($newPassword) < 8) {
            $errors[] = t('settings.password_too_short');
        } elseif ($newPassword !== $newPasswordConfirm) {
            $errors[] = t('settings.passwords_mismatch');
        }
    }

    if (!$errors) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, currentUserId()]);
        flashSet('success', t('settings.password_changed_success'));
        header('Location: settings.php');
        exit;
    }
}

$csrf = csrfToken();
$pageTitle = t('nav.settings');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('nav.settings')) ?></h1>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.account_info_title')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(t('common.field_username')) ?></dt><dd class="col-7"><?= e($user['username'] ?? '') ?></dd>
                    <dt class="col-5"><?= e(t('settings.field_full_name')) ?></dt><dd class="col-7"><?= dashOrValue($user['full_name'] ?? null) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.about_title')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(t('settings.system_name')) ?></dt><dd class="col-7"><?= e(APP_NAME) ?></dd>
                    <dt class="col-5"><?= e(t('settings.version')) ?></dt><dd class="col-7">v<?= e(APP_VERSION) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.change_password_title')) ?></div>
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
                        <label class="form-label"><?= e(t('settings.current_password')) ?></label>
                        <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('settings.new_password')) ?></label>
                        <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('settings.confirm_new_password')) ?></label>
                        <input type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= e(t('settings.change_password_title')) ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
