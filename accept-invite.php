<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';

// Deliberately does not require login or render includes/header.php — like
// login.php and install.php, this page must work for a signed-out (or not
// yet existing) visitor. It builds its own minimal shell instead.

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

$platform = platformDb();
$invitation = null;
if ($token !== '') {
    $stmt = $platform->prepare('SELECT * FROM invitations WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $invitation = $stmt->fetch() ?: null;
}

$isValid = $invitation !== null
    && $invitation['accepted_at'] === null
    && strtotime($invitation['expires_at']) > time();

$workspaceName = '';
$existingUser = null;
if ($isValid) {
    $wsStmt = $platform->prepare('SELECT name FROM workspaces WHERE id = ? LIMIT 1');
    $wsStmt->execute([$invitation['workspace_id']]);
    $workspaceName = (string) $wsStmt->fetchColumn();

    $userStmt = $platform->prepare('SELECT id, email, full_name FROM accounts_users WHERE email = ? COLLATE NOCASE LIMIT 1');
    $userStmt->execute([$invitation['email']]);
    $existingUser = $userStmt->fetch() ?: null;
}

/**
 * Creates the membership (a no-op if one somehow already exists), marks the
 * invitation accepted, and signs the browser into the new workspace.
 */
function completeInviteAcceptance(PDO $platform, array $invitation, int $userId): void
{
    $platform->beginTransaction();
    try {
        $platform->prepare('INSERT OR IGNORE INTO memberships (workspace_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([(int) $invitation['workspace_id'], $userId, $invitation['role']]);
        $platform->prepare("UPDATE invitations SET accepted_at = datetime('now') WHERE id = ?")
            ->execute([(int) $invitation['id']]);
        $platform->commit();
    } catch (Throwable $e) {
        $platform->rollBack();
        throw $e;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['workspace_id'] = (int) $invitation['workspace_id'];
    $_SESSION['role'] = $invitation['role'];
}

$signupErrors = [];
$sameAccountLoggedIn = $isValid && $existingUser !== null && isLoggedIn() && currentUser() !== null
    && strcasecmp((string) currentUser()['email'], (string) $invitation['email']) === 0;

if ($isValid && $existingUser !== null && !isLoggedIn()) {
    header('Location: ' . appUrl('login.php?redirect=' . urlencode('accept-invite.php?token=' . $token)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isValid) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $signupErrors[] = t('msg.invalid_request');
    } elseif ($sameAccountLoggedIn) {
        completeInviteAcceptance($platform, $invitation, (int) $existingUser['id']);
        flashSet('success', t('accept_invite.joined_success'));
        header('Location: ' . appUrl('index.php'));
        exit;
    } elseif ($existingUser === null) {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($password === '' || mb_strlen($password) < 8) {
            $signupErrors[] = t('accept_invite.password_too_short');
        } elseif ($password !== $passwordConfirm) {
            $signupErrors[] = t('accept_invite.passwords_mismatch');
        }

        if (!$signupErrors) {
            try {
                $platform->beginTransaction();
                $platform->prepare(
                    "INSERT INTO accounts_users (email, password_hash, full_name, is_active, email_verified_at)
                     VALUES (?, ?, ?, 1, datetime('now'))"
                )->execute([$invitation['email'], password_hash($password, PASSWORD_DEFAULT), $fullName !== '' ? $fullName : null]);
                $newUserId = (int) $platform->lastInsertId();

                $platform->prepare('INSERT INTO memberships (workspace_id, user_id, role) VALUES (?, ?, ?)')
                    ->execute([(int) $invitation['workspace_id'], $newUserId, $invitation['role']]);
                $platform->prepare("UPDATE invitations SET accepted_at = datetime('now') WHERE id = ?")
                    ->execute([(int) $invitation['id']]);
                $platform->commit();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['workspace_id'] = (int) $invitation['workspace_id'];
                $_SESSION['role'] = $invitation['role'];

                flashSet('success', t('accept_invite.joined_success'));
                header('Location: ' . appUrl('index.php'));
                exit;
            } catch (Throwable $e) {
                if ($platform->inTransaction()) {
                    $platform->rollBack();
                }
                $signupErrors[] = str_contains($e->getMessage(), 'UNIQUE')
                    ? t('accept_invite.email_taken')
                    : t('msg.save_error') . $e->getMessage();
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
    <title><?= e(t('accept_invite.title')) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e('assets/css/' . $bs) ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card am-card shadow-sm" style="width:100%; max-width:460px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center"><?= e(APP_NAME) ?></h1>

            <?php if (!$isValid): ?>
                <p class="text-muted text-center mb-4"><?= e(t('accept_invite.invalid_title')) ?></p>
                <div class="alert alert-danger"><?= e(t('accept_invite.invalid_message')) ?></div>
                <a href="<?= e(appUrl('login.php')) ?>" class="btn btn-outline-secondary w-100"><?= e(t('login.submit_button')) ?></a>

            <?php elseif ($existingUser !== null && !$sameAccountLoggedIn): ?>
                <p class="text-muted text-center mb-4"><?= e(t('accept_invite.wrong_account_title')) ?></p>
                <div class="alert alert-warning"><?= e(t('accept_invite.wrong_account_message', ['email' => $invitation['email']])) ?></div>
                <a href="<?= e(appUrl('logout.php')) ?>" class="btn btn-outline-secondary w-100"><?= e(t('accept_invite.logout_link')) ?></a>

            <?php elseif ($sameAccountLoggedIn): ?>
                <p class="text-muted text-center mb-4"><?= e(t('accept_invite.join_confirm_title', ['workspace' => $workspaceName])) ?></p>

                <?php if ($signupErrors): ?>
                    <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($signupErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <p class="text-muted small"><?= e(t('accept_invite.join_confirm_message', ['workspace' => $workspaceName, 'role' => tOr('role.' . $invitation['role'], ucfirst($invitation['role']))])) ?></p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <button type="submit" class="btn btn-primary w-100"><?= e(t('accept_invite.join_button')) ?></button>
                </form>

            <?php else: ?>
                <p class="text-muted text-center mb-4"><?= e(t('accept_invite.signup_title', ['workspace' => $workspaceName])) ?></p>

                <?php if ($signupErrors): ?>
                    <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($signupErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <p class="text-muted small"><?= e(t('accept_invite.signup_intro', ['workspace' => $workspaceName, 'role' => tOr('role.' . $invitation['role'], ucfirst($invitation['role']))])) ?></p>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('accept_invite.field_email')) ?></label>
                        <input type="email" class="form-control" value="<?= e($invitation['email']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('accept_invite.field_full_name')) ?></label>
                        <input type="text" name="full_name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('accept_invite.field_password')) ?></label>
                        <input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('accept_invite.field_password_confirm')) ?></label>
                        <input type="password" name="password_confirm" class="form-control" minlength="8" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?= e(t('accept_invite.submit_button')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
