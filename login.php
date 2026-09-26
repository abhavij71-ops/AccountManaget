<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';

if (isLoggedIn()) {
    header('Location: ' . appUrl('index.php'));
    exit;
}

$error = '';
$redirect = isset($_GET['redirect']) ? (string) $_GET['redirect'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Field is still posted as "username" (see the form below) but now holds
    // the accounts_users login identifier, which is an email address.
    $email = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');
    $redirect = (string) ($_POST['redirect'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!verifyCsrfToken($token)) {
        $error = t('msg.invalid_request');
    } elseif ($email === '' || $password === '') {
        $error = t('login.enter_credentials');
    } elseif (isLoginLocked($ip, $email)) {
        // Deliberately generic: never reveals whether it's this IP or this
        // username that's over the attempt limit.
        $error = tOr('login.locked_out', 'Too many failed attempts. Please try again in a few minutes.');
    } else {
        $stmt = platformDb()->prepare('SELECT id, email, password_hash, is_active, totp_enabled_at FROM accounts_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            recordLoginAttempt($ip, $email, false);
            $error = t('login.invalid_credentials');
        } elseif ((int) $user['is_active'] !== 1) {
            recordLoginAttempt($ip, $email, false);
            $error = t('login.account_disabled');
        } else {
            $membershipStmt = platformDb()->prepare(
                'SELECT m.workspace_id, m.role, w.name
                 FROM memberships m
                 JOIN workspaces w ON w.id = m.workspace_id
                 WHERE m.user_id = ?
                 ORDER BY w.name COLLATE NOCASE'
            );
            $membershipStmt->execute([$user['id']]);
            $workspaces = $membershipStmt->fetchAll();

            if (count($workspaces) === 0) {
                // Reuses "account disabled" rather than a distinct "no workspace
                // access" string — from the user's side, an account with
                // nothing to open behaves the same as one that isn't usable.
                recordLoginAttempt($ip, $email, false);
                $error = t('login.account_disabled');
            } else {
                // The password factor succeeded — recorded as such regardless
                // of what happens next; a wrong TOTP/recovery code afterwards
                // is tracked separately by verify-totp.php, against the same
                // login_attempts table.
                recordLoginAttempt($ip, $email, true);

                if (!empty($user['totp_enabled_at'])) {
                    session_regenerate_id(true);
                    $_SESSION['totp_pending_user_id'] = (int) $user['id'];
                    $_SESSION['totp_pending_email'] = $email;
                    $_SESSION['totp_pending_workspaces'] = $workspaces;
                    $_SESSION['totp_pending_redirect'] = $redirect;
                    header('Location: ' . appUrl('verify-totp.php'));
                    exit;
                }

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];

                if (count($workspaces) === 1) {
                    $_SESSION['workspace_id'] = (int) $workspaces[0]['workspace_id'];
                    $_SESSION['role'] = $workspaces[0]['role'];
                    header('Location: ' . appUrl(safeInternalRedirect($redirect)));
                } else {
                    $target = 'select-workspace.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : '');
                    header('Location: ' . appUrl($target));
                }
                exit;
            }
        }
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
    <title><?= e(t('login.submit_button')) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:380px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4"><?= e(t('login.subtitle')) ?></p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label"><?= e(t('common.field_username')) ?></label>
                    <input type="text" class="form-control" id="username" name="username" autocomplete="username" required autofocus>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label"><?= e(t('login.password_label')) ?></label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100"><?= e(t('login.submit_button')) ?></button>
            </form>

            <p class="text-center text-muted small mt-3 mb-1">
                <a href="<?= e(appUrl('forgot-password.php')) ?>"><?= e(t('login.forgot_password_link')) ?></a>
            </p>
            <p class="text-center text-muted small mb-0">
                <a href="<?= e(appUrl('register.php')) ?>"><?= e(t('login.create_account_link')) ?></a>
            </p>
        </div>
    </div>
</body>
</html>
