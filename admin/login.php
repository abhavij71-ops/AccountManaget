<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

if (isAdminAuthenticated()) {
    header('Location: ' . APP_BASE_URL . '/admin/index.php');
    exit;
}

// Same login_attempts table and rules as tenant login (includes/
// login-lockout.php) — there's no real per-operator username here (a
// single shared ADMIN_PASSWORD), so every attempt is keyed under this one
// fixed literal instead, same as a single-account username would be.
const ADMIN_LOGIN_USERNAME = 'platform-admin';

$error = '';
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyAdminCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request.';
    } elseif (isLoginLocked($ip, ADMIN_LOGIN_USERNAME)) {
        $error = 'Too many failed attempts. Try again later.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $expected = loadSecret('ADMIN_PASSWORD');
        // A fixed delay makes brute-forcing this one shared password
        // meaningfully slower even within the lockout window.
        usleep(300000);
        $success = $expected !== '' && hash_equals($expected, $password);
        recordLoginAttempt($ip, ADMIN_LOGIN_USERNAME, $success);

        if (!$success) {
            $error = 'Incorrect password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            header('Location: ' . APP_BASE_URL . '/admin/index.php');
            exit;
        }
    }
}

$csrf = adminCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform admin sign in</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card shadow-sm" style="width:100%; max-width:360px;">
        <div class="card-body p-4">
            <h1 class="h5 mb-3 text-center">Platform admin</h1>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" autocomplete="current-password" required autofocus>
                </div>
                <button type="submit" class="btn btn-dark w-100">Sign in</button>
            </form>
        </div>
    </div>
</body>
</html>
