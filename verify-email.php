<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/registration.php';

$token = (string) ($_GET['token'] ?? '');
$success = false;
$errorMessage = '';

if ($token === '') {
    $errorMessage = t('verify_email.invalid_or_expired');
} else {
    $verification = findValidEmailVerification($token);
    if ($verification === null) {
        $errorMessage = t('verify_email.invalid_or_expired');
    } else {
        try {
            completeEmailVerification($verification);
            $success = true;
        } catch (Throwable $e) {
            error_log('Account Manager: email verification failed: ' . $e->getMessage());
            $errorMessage = t('verify_email.failed');
        }
    }
}

$bs = currentTextDirection() === 'rtl' ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
?>
<!DOCTYPE html>
<html lang="<?= e(currentLanguage()) ?>" dir="<?= e(currentTextDirection()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('verify_email.title')) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:420px;">
        <div class="card-body p-4 text-center">
            <h1 class="h4 mb-3"><?= e(APP_NAME) ?></h1>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= e(t('verify_email.success')) ?></div>
                <a href="<?= e(appUrl('login.php')) ?>" class="btn btn-primary w-100"><?= e(t('login.submit_button')) ?></a>
            <?php else: ?>
                <div class="alert alert-danger"><?= e($errorMessage) ?></div>
                <a href="<?= e(appUrl('register.php')) ?>" class="btn btn-outline-primary w-100"><?= e(t('register.title')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
