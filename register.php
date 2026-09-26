<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/registration.php';

if (isLoggedIn()) {
    header('Location: ' . appUrl('index.php'));
    exit;
}

$errors = [];
$email = '';
$fullName = '';
$workspaceName = '';
$registered = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $workspaceName = trim((string) ($_POST['workspace_name'] ?? ''));

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('register.email_invalid');
    }
    if (mb_strlen($password) < 8) {
        $errors[] = t('register.password_too_short');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = t('register.passwords_mismatch');
    }
    if ($fullName === '') {
        $errors[] = t('register.name_required');
    }
    if ($workspaceName === '') {
        $errors[] = t('register.workspace_name_required');
    }

    if (!$errors) {
        $existsStmt = platformDb()->prepare('SELECT 1 FROM accounts_users WHERE email = ? COLLATE NOCASE LIMIT 1');
        $existsStmt->execute([$email]);
        if ($existsStmt->fetchColumn()) {
            // Deliberately the same generic message a validation failure
            // would show — never "this email is already registered", which
            // would let this form enumerate existing accounts.
            $errors[] = t('register.generic_error');
        }
    }

    if (!$errors) {
        registerPendingUser($email, $password, $fullName, $workspaceName);
        $registered = true;
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
    <title><?= e(t('register.title')) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:420px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4"><?= e(t('register.subtitle')) ?></p>

            <?php if ($registered): ?>
                <div class="alert alert-success"><?= e(t('register.check_email')) ?></div>
                <a href="<?= e(appUrl('login.php')) ?>" class="btn btn-outline-primary w-100"><?= e(t('login.submit_button')) ?></a>
            <?php else: ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger py-2">
                        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                    <div class="mb-3">
                        <label for="full_name" class="form-label"><?= e(t('register.field_full_name')) ?></label>
                        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= e($fullName) ?>" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label"><?= e(t('settings.field_email')) ?></label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label"><?= e(t('settings.new_password')) ?></label>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="8" required>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label"><?= e(t('settings.confirm_new_password')) ?></label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
                    </div>

                    <div class="mb-3">
                        <label for="workspace_name" class="form-label"><?= e(t('register.field_workspace_name')) ?></label>
                        <input type="text" class="form-control" id="workspace_name" name="workspace_name" value="<?= e($workspaceName) ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100"><?= e(t('register.submit_button')) ?></button>
                </form>

                <p class="text-center text-muted small mt-3 mb-0">
                    <a href="<?= e(appUrl('login.php')) ?>"><?= e(t('register.back_to_login')) ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
