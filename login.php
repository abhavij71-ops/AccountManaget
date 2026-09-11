<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . appUrl('index.php'));
    exit;
}

$error = '';
$redirect = isset($_GET['redirect']) ? (string) $_GET['redirect'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');
    $redirect = (string) ($_POST['redirect'] ?? '');

    if (!verifyCsrfToken($token)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif ($username === '' || $password === '') {
        $error = 'نام کاربری و رمز عبور را وارد کنید.';
    } else {
        $stmt = db()->prepare('SELECT id, username, password_hash, is_active FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'نام کاربری یا رمز عبور اشتباه است.';
        } elseif ((int) $user['is_active'] !== 1) {
            $error = 'این حساب کاربری غیرفعال شده است.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];

            $upd = db()->prepare('UPDATE users SET last_login_at = datetime(\'now\') WHERE id = ?');
            $upd->execute([$user['id']]);

            $target = $redirect !== '' ? $redirect : appUrl('index.php');
            header('Location: ' . $target);
            exit;
        }
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ورود | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:380px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4">ورود به پنل مدیریت</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label">نام کاربری</label>
                    <input type="text" class="form-control" id="username" name="username" autocomplete="username" required autofocus>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">رمز عبور</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">ورود</button>
            </form>
        </div>
    </div>
</body>
</html>
