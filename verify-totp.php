<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';

if (isLoggedIn()) {
    header('Location: ' . appUrl('index.php'));
    exit;
}

if (isset($_GET['cancel'])) {
    unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_email'], $_SESSION['totp_pending_workspaces'], $_SESSION['totp_pending_redirect']);
    header('Location: ' . appUrl('login.php'));
    exit;
}

// Only reachable after login.php's password step has already succeeded and
// found totp_enabled_at set — nothing here re-checks the password.
if (empty($_SESSION['totp_pending_user_id'])) {
    header('Location: ' . appUrl('login.php'));
    exit;
}

$pendingUserId = (int) $_SESSION['totp_pending_user_id'];
$pendingEmail = (string) ($_SESSION['totp_pending_email'] ?? '');
$pendingWorkspaces = $_SESSION['totp_pending_workspaces'] ?? [];
$pendingRedirect = (string) ($_SESSION['totp_pending_redirect'] ?? '');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string) ($_POST['code'] ?? ''));
    $token = (string) ($_POST['csrf_token'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!verifyCsrfToken($token)) {
        $error = t('msg.invalid_request');
    } elseif ($code === '') {
        $error = t('login.totp_invalid_code');
    } elseif (isLoginLocked($ip, $pendingEmail)) {
        // Same generic message and same (ip, username) keying as the
        // password step — one shared brute-force counter across both factors.
        $error = tOr('login.locked_out', 'Too many failed attempts. Please try again in a few minutes.');
    } else {
        $stmt = platformDb()->prepare('SELECT totp_secret FROM accounts_users WHERE id = ? LIMIT 1');
        $stmt->execute([$pendingUserId]);
        $secret = $stmt->fetchColumn();

        $verified = false;
        if ($secret && preg_match('/^\d{6}$/', $code)) {
            $verified = verifyTotp((string) $secret, $code);
        } else {
            // Not 6 digits — try it as a recovery code instead.
            $codesStmt = platformDb()->prepare('SELECT id, code_hash FROM totp_recovery_codes WHERE user_id = ? AND used_at IS NULL');
            $codesStmt->execute([$pendingUserId]);
            foreach ($codesStmt->fetchAll() as $row) {
                if (verifyRecoveryCodeHash($code, $row['code_hash'])) {
                    platformDb()->prepare("UPDATE totp_recovery_codes SET used_at = datetime('now') WHERE id = ?")
                        ->execute([$row['id']]);
                    $verified = true;
                    break;
                }
            }
        }

        if (!$verified) {
            recordLoginAttempt($ip, $pendingEmail, false);
            $error = t('login.totp_invalid_code');
        } else {
            recordLoginAttempt($ip, $pendingEmail, true);

            unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_pending_email'], $_SESSION['totp_pending_workspaces'], $_SESSION['totp_pending_redirect']);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $pendingUserId;

            if (count($pendingWorkspaces) === 1) {
                $_SESSION['workspace_id'] = (int) $pendingWorkspaces[0]['workspace_id'];
                $_SESSION['role'] = $pendingWorkspaces[0]['role'];
                header('Location: ' . appUrl(safeInternalRedirect($pendingRedirect)));
            } else {
                $target = 'select-workspace.php' . ($pendingRedirect !== '' ? '?redirect=' . urlencode($pendingRedirect) : '');
                header('Location: ' . appUrl($target));
            }
            exit;
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
    <title><?= e(t('login.totp_title')) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:380px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>
            <p class="text-muted text-center mb-4"><?= e(t('login.totp_instructions')) ?></p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

                <div class="mb-3">
                    <label for="code" class="form-label"><?= e(t('login.totp_code_label')) ?></label>
                    <input type="text" class="form-control" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                </div>

                <button type="submit" class="btn btn-primary w-100 mb-2"><?= e(t('login.totp_submit_button')) ?></button>
                <a href="verify-totp.php?cancel=1" class="btn btn-link w-100"><?= e(t('login.totp_cancel_link')) ?></a>
            </form>
        </div>
    </div>
</body>
</html>
