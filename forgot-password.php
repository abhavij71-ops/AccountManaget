<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/password-reset.php';

if (isLoggedIn()) {
    header('Location: ' . appUrl('index.php'));
    exit;
}

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email !== '') {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            // Checked and recorded before anything else — a flood must never
            // queue more mail, regardless of whether the email exists.
            if (!isPasswordResetRequestLocked($email, $ip)) {
                recordPasswordResetRequest($email, $ip);

                $stmt = platformDb()->prepare('SELECT id FROM accounts_users WHERE email = ? COLLATE NOCASE AND is_active = 1 LIMIT 1');
                $stmt->execute([$email]);
                $userId = $stmt->fetchColumn();
                if ($userId !== false) {
                    $token = createPasswordReset((int) $userId);
                    $resetUrl = appUrl('reset-password.php?token=' . $token);
                    queueMail(
                        $email,
                        t('forgot_password.email_subject'),
                        '<p>' . e(t('forgot_password.email_intro')) . '</p><p><a href="' . e($resetUrl) . '">' . e($resetUrl) . '</a></p>'
                    );
                }
            }
            // Same response whether or not that email exists, is active, or
            // was just throttled — this form must never reveal any of that.
        }
        $submitted = true;
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
    <title><?= e(t('forgot_password.title')) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:380px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4"><?= e(t('forgot_password.subtitle')) ?></p>

            <?php if ($submitted): ?>
                <div class="alert alert-success"><?= e(t('forgot_password.submitted')) ?></div>
                <a href="<?= e(appUrl('login.php')) ?>" class="btn btn-outline-primary w-100"><?= e(t('login.submit_button')) ?></a>
            <?php else: ?>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <div class="mb-3">
                        <label for="email" class="form-label"><?= e(t('settings.field_email')) ?></label>
                        <input type="email" class="form-control" id="email" name="email" autocomplete="email" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?= e(t('forgot_password.submit_button')) ?></button>
                </form>
                <p class="text-center text-muted small mt-3 mb-0">
                    <a href="<?= e(appUrl('login.php')) ?>"><?= e(t('forgot_password.back_to_login')) ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
