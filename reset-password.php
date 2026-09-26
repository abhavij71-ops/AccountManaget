<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/password-reset.php';

if (isLoggedIn()) {
    header('Location: ' . appUrl('index.php'));
    exit;
}

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$success = false;
$reset = $token !== '' ? findValidPasswordReset($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }
    if ($reset === null) {
        $errors[] = t('reset_password.invalid_or_expired');
    } elseif (mb_strlen($password) < 8) {
        $errors[] = t('register.password_too_short');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = t('register.passwords_mismatch');
    }

    if (!$errors && $reset !== null) {
        consumePasswordReset((int) $reset['id'], (int) $reset['user_id'], $password);
        $success = true;
    }
}

$csrf = csrfToken();
$bs = currentTextDirection() === 'rtl' ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
?>
<!DOCTYPE html>
<html lang="<?= e(currentLanguage()) ?>" dir="<?= e(currentTextDirection()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('reset_password.title')) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:380px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4"><?= e(t('reset_password.subtitle')) ?></p>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= e(t('reset_password.success')) ?></div>
                <a href="<?= e(appUrl('login.php')) ?>" class="btn btn-primary w-100"><?= e(t('login.submit_button')) ?></a>
            <?php elseif ($reset === null): ?>
                <div class="alert alert-danger"><?= e(t('reset_password.invalid_or_expired')) ?></div>
                <a href="<?= e(appUrl('forgot-password.php')) ?>" class="btn btn-outline-primary w-100"><?= e(t('forgot_password.title')) ?></a>
            <?php else: ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger py-2">
                        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="mb-3">
                        <label for="password" class="form-label"><?= e(t('settings.new_password')) ?></label>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="8" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password_confirm" class="form-label"><?= e(t('settings.confirm_new_password')) ?></label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?= e(t('reset_password.submit_button')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
