<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/platform-db.php';
require_once __DIR__ . '/includes/workspaces.php';

/**
 * First-run setup only — deliberately never calls db(). db() resolves a
 * workspace from $_SESSION['workspace_id'], which cannot exist on a truly
 * fresh install with no accounts_users/workspaces rows at all yet; calling
 * it here was the original bug (db() -> select-workspace.php ->
 * login.php, a dead end that created no database at all). platformDb()
 * alone is everything this page needs: opening it creates
 * data/platform.sqlite and runs every migrations/platform/*.php file,
 * bringing the central database up to date before anything below tries to
 * write to it.
 *
 * Locked permanently (spec item 2) the moment any accounts_users row
 * exists — re-checked immediately before the write below too, since the
 * GET-time check and the POST that follows it are two separate requests.
 */

$platform = platformDb();

$alreadyInstalled = (int) $platform->query('SELECT COUNT(*) FROM accounts_users')->fetchColumn() > 0;

$error = '';
$success = false;

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $workspaceName = trim((string) ($_POST['workspace_name'] ?? ''));

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'یک ایمیل معتبر برای مدیر سیستم وارد کنید.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'رمز عبور و تکرار آن یکسان نیستند.';
    } elseif ($fullName === '') {
        $error = 'نام خود را وارد کنید.';
    } elseif ($workspaceName === '') {
        $error = 'یک نام برای ورک‌اسپیس وارد کنید.';
    } elseif ((int) $platform->query('SELECT COUNT(*) FROM accounts_users')->fetchColumn() > 0) {
        // Re-checked right before writing — a one-shot, irreversible action.
        $error = 'سیستم قبلاً نصب شده است.';
        $alreadyInstalled = true;
    } else {
        try {
            // The install-time admin is trusted directly: active and
            // email-verified immediately, with no verification-email step
            // — there may not even be a working SMTP setup yet, and this
            // person is already sitting at the server's own install page.
            $platform->prepare(
                "INSERT INTO accounts_users (email, password_hash, full_name, is_active, email_verified_at)
                 VALUES (?, ?, ?, 1, datetime('now'))"
            )->execute([$email, password_hash($password, PASSWORD_DEFAULT), $fullName]);
            $userId = (int) $platform->lastInsertId();

            $workspaceId = createWorkspace($workspaceName, $userId);

            $platform->prepare("INSERT INTO memberships (workspace_id, user_id, role) VALUES (?, ?, 'owner')")
                ->execute([$workspaceId, $userId]);

            $success = true;
            $alreadyInstalled = true;
        } catch (Throwable $e) {
            $error = 'خطا در نصب: ' . $e->getMessage();
        }
    }
}

$csrf = csrfToken();
// No language session exists yet at install time — default to RTL.
$bs = 'bootstrap.rtl.min.css';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نصب | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:460px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4">نصب اولیه سیستم</p>

            <?php if ($alreadyInstalled && !$success): ?>
                <div class="alert alert-info">
                    سیستم قبلاً نصب شده است. برای ورود از صفحه ورود استفاده کنید.
                </div>
                <a href="login.php" class="btn btn-primary w-100">رفتن به صفحه ورود</a>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    نصب با موفقیت انجام شد. اکنون می‌توانید وارد شوید.
                </div>
                <a href="login.php" class="btn btn-primary w-100">رفتن به صفحه ورود</a>
                <p class="text-danger small mt-3 mb-0 text-center">
                    برای امنیت بیشتر، فایل install.php را از روی سرور حذف کنید.
                </p>
            <?php else: ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger py-2"><?= e($error) ?></div>
                <?php endif; ?>

                <p class="text-muted small">برای تکمیل نصب، حساب مدیر و اولین ورک‌اسپیس را بسازید.</p>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                    <div class="mb-3">
                        <label for="full_name" class="form-label">نام شما</label>
                        <input type="text" class="form-control" id="full_name" name="full_name"
                               value="<?= e($_POST['full_name'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">ایمیل مدیر</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= e($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">رمز عبور</label>
                        <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">تکرار رمز عبور</label>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required>
                    </div>

                    <div class="mb-3">
                        <label for="workspace_name" class="form-label">نام ورک‌اسپیس</label>
                        <input type="text" class="form-control" id="workspace_name" name="workspace_name"
                               value="<?= e($_POST['workspace_name'] ?? '') ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">ایجاد حساب مدیر و تکمیل نصب</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
