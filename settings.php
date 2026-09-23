<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

$user = currentUser();
$errors = [];
$totpErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'change_password');

    if ($action === 'download_backup') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            flashSet('danger', t('msg.invalid_request'));
            header('Location: settings.php');
            exit;
        }
        // requireLogin() now re-verifies is_active on every request (see includes/auth.php),
        // so a deactivated account's session can no longer reach this far — no local re-check needed.
        $filename = 'account-manager-backup-' . date('Y-m-d-His') . '.sqlite';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize(DB_PATH));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile(DB_PATH);
        exit;
    } elseif ($action === 'start_totp_enroll') {
        if (verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            // A fresh secret every time this is clicked — abandoning a
            // half-finished enrollment and starting over is always safe.
            $_SESSION['totp_pending_secret'] = generateTotpSecret();
        } else {
            flashSet('danger', t('msg.invalid_request'));
        }
        header('Location: settings.php');
        exit;
    } elseif ($action === 'cancel_totp_enroll') {
        unset($_SESSION['totp_pending_secret']);
        header('Location: settings.php');
        exit;
    } elseif ($action === 'confirm_totp_enroll') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            $totpErrors[] = t('msg.invalid_request');
        } else {
            $pendingSecret = (string) ($_SESSION['totp_pending_secret'] ?? '');
            $code = trim((string) ($_POST['code'] ?? ''));

            if ($pendingSecret === '' || !verifyTotp($pendingSecret, $code)) {
                $totpErrors[] = t('settings.totp_invalid_code');
            } else {
                $recoveryCodes = generateRecoveryCodes();
                $platform = platformDb();
                $platform->beginTransaction();
                try {
                    $platform->prepare("UPDATE accounts_users SET totp_secret = ?, totp_enabled_at = datetime('now') WHERE id = ?")
                        ->execute([$pendingSecret, currentUserId()]);
                    $platform->prepare('DELETE FROM totp_recovery_codes WHERE user_id = ?')->execute([currentUserId()]);
                    foreach ($recoveryCodes as $recoveryCode) {
                        $platform->prepare('INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)')
                            ->execute([currentUserId(), hashRecoveryCode($recoveryCode)]);
                    }
                    $platform->commit();
                } catch (Throwable $e) {
                    $platform->rollBack();
                    $totpErrors[] = t('msg.save_error') . $e->getMessage();
                }

                if (!$totpErrors) {
                    unset($_SESSION['totp_pending_secret']);
                    // Consumed once by the render below, then gone — this is
                    // the only time these plain-text codes ever exist outside
                    // this one request.
                    $_SESSION['totp_new_recovery_codes'] = $recoveryCodes;
                    flashSet('success', t('settings.totp_enabled_success'));
                    header('Location: settings.php');
                    exit;
                }
            }
        }
    } elseif ($action === 'disable_totp') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            $totpErrors[] = t('msg.invalid_request');
        } else {
            $stmt = platformDb()->prepare('SELECT password_hash FROM accounts_users WHERE id = ?');
            $stmt->execute([currentUserId()]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify((string) ($_POST['current_password'] ?? ''), $hash)) {
                $totpErrors[] = t('settings.totp_disable_wrong_password');
            } else {
                $platform = platformDb();
                $platform->beginTransaction();
                try {
                    $platform->prepare('UPDATE accounts_users SET totp_secret = NULL, totp_enabled_at = NULL WHERE id = ?')
                        ->execute([currentUserId()]);
                    $platform->prepare('DELETE FROM totp_recovery_codes WHERE user_id = ?')->execute([currentUserId()]);
                    $platform->commit();
                } catch (Throwable $e) {
                    $platform->rollBack();
                    $totpErrors[] = t('msg.save_error') . $e->getMessage();
                }

                if (!$totpErrors) {
                    flashSet('success', t('settings.totp_disabled_success'));
                    header('Location: settings.php');
                    exit;
                }
            }
        }
    } elseif ($action === 'sign_out_everywhere') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            flashSet('danger', t('msg.invalid_request'));
            header('Location: settings.php');
            exit;
        }

        // Deliberately every device, including this one — the button says
        // "everywhere," and requiring a fresh login here too is the honest
        // reading of that, not a softer "everyone but me."
        platformDb()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([currentUserId()]);

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: ' . appUrl('login.php'));
        exit;
    } elseif ($action === 'change_password') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
            $errors[] = t('msg.invalid_request');
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!$errors) {
            $stmt = platformDb()->prepare('SELECT password_hash FROM accounts_users WHERE id = ?');
            $stmt->execute([currentUserId()]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($currentPassword, $hash)) {
                $errors[] = t('settings.password_incorrect');
            } elseif (mb_strlen($newPassword) < 8) {
                $errors[] = t('settings.password_too_short');
            } elseif ($newPassword !== $newPasswordConfirm) {
                $errors[] = t('settings.passwords_mismatch');
            }
        }

        if (!$errors) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = platformDb()->prepare('UPDATE accounts_users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$newHash, currentUserId()]);
            session_regenerate_id(true);
            flashSet('success', t('settings.password_changed_success'));
            header('Location: settings.php');
            exit;
        }
    }
}

$totpRowStmt = platformDb()->prepare('SELECT totp_enabled_at FROM accounts_users WHERE id = ? LIMIT 1');
$totpRowStmt->execute([currentUserId()]);
$totpEnabled = !empty($totpRowStmt->fetchColumn());
$totpPendingSecret = (string) ($_SESSION['totp_pending_secret'] ?? '');
$newRecoveryCodes = $_SESSION['totp_new_recovery_codes'] ?? null;
unset($_SESSION['totp_new_recovery_codes']);

$sessionsStmt = platformDb()->prepare(
    'SELECT id, token_hash, ip, user_agent, created_at, last_seen_at FROM sessions WHERE user_id = ? ORDER BY last_seen_at DESC'
);
$sessionsStmt->execute([currentUserId()]);
$activeSessions = $sessionsStmt->fetchAll();
$currentDeviceTokenHash = isset($_SESSION['device_token']) ? hash('sha256', (string) $_SESSION['device_token']) : '';

$csrf = csrfToken();
$pageTitle = t('nav.settings');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('nav.settings')) ?></h1>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.account_info_title')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(tOr('settings.field_email', 'Email')) ?></dt><dd class="col-7"><?= e($user['email'] ?? '') ?></dd>
                    <dt class="col-5"><?= e(t('settings.field_full_name')) ?></dt><dd class="col-7"><?= dashOrValue($user['full_name'] ?? null) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.backup_title')) ?></div>
            <div class="card-body">
                <p class="text-muted small"><?= e(t('settings.backup_description')) ?></p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="download_backup">
                    <button type="submit" class="btn btn-outline-primary"><?= e(t('settings.download_backup_button')) ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.about_title')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(t('settings.system_name')) ?></dt><dd class="col-7"><?= e(APP_NAME) ?></dd>
                    <dt class="col-5"><?= e(t('settings.version')) ?></dt><dd class="col-7">v<?= e(APP_VERSION) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.totp_title')) ?></div>
            <div class="card-body">
                <?php if ($newRecoveryCodes !== null): ?>
                    <div class="alert alert-success py-2"><?= e(t('settings.totp_enabled_success')) ?></div>
                    <h2 class="h6"><?= e(t('settings.totp_recovery_codes_title')) ?></h2>
                    <p class="text-muted small"><?= e(t('settings.totp_recovery_codes_intro')) ?></p>
                    <div class="row row-cols-2 g-2 mb-0">
                        <?php foreach ($newRecoveryCodes as $recoveryCode): ?>
                            <div class="col"><code><?= e($recoveryCode) ?></code></div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($totpEnabled): ?>
                    <p class="text-muted"><?= e(t('settings.totp_status_enabled')) ?></p>
                    <?php if ($totpErrors): ?>
                        <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($totpErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                    <?php endif; ?>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="disable_totp">
                        <div class="col-8">
                            <label class="form-label"><?= e(t('settings.current_password')) ?></label>
                            <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-outline-danger w-100"><?= e(t('settings.totp_disable_button')) ?></button>
                        </div>
                    </form>
                <?php elseif ($totpPendingSecret !== ''): ?>
                    <?php $otpauthUri = buildOtpauthUri(APP_NAME, (string) ($user['email'] ?? ''), $totpPendingSecret); ?>
                    <h2 class="h6"><?= e(t('settings.totp_setup_title')) ?></h2>
                    <p class="text-muted small"><?= e(t('settings.totp_setup_instructions')) ?></p>
                    <?php if ($totpErrors): ?>
                        <div class="alert alert-danger py-2"><ul class="mb-0"><?php foreach ($totpErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1"><?= e(t('settings.totp_secret_label')) ?></label>
                        <input type="text" class="form-control form-control-sm" readonly value="<?= e($totpPendingSecret) ?>" onclick="this.select()">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1"><?= e(t('settings.totp_uri_label')) ?></label>
                        <input type="text" class="form-control form-control-sm" readonly value="<?= e($otpauthUri) ?>" onclick="this.select()">
                    </div>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="col-md-6">
                            <label class="form-label"><?= e(t('settings.totp_code_label')) ?></label>
                            <input type="text" name="code" class="form-control" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="action" value="confirm_totp_enroll" class="btn btn-primary w-100"><?= e(t('settings.totp_confirm_button')) ?></button>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="action" value="cancel_totp_enroll" class="btn btn-outline-secondary w-100" formnovalidate><?= e(t('common.cancel')) ?></button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted"><?= e(t('settings.totp_intro')) ?></p>
                    <p class="text-muted small"><?= e(t('settings.totp_status_disabled')) ?></p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="start_totp_enroll">
                        <button type="submit" class="btn btn-primary"><?= e(t('settings.totp_enable_button')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><?= e(tOr('settings.sessions_title', 'Active devices')) ?></span>
                <?php if ($activeSessions): ?>
                    <form method="post" data-confirm="<?= e(tOr('settings.sessions_sign_out_confirm', 'Sign out of every device, including this one?')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="sign_out_everywhere">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><?= e(tOr('settings.sessions_sign_out_button', 'Sign out everywhere')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$activeSessions): ?>
                    <p class="text-muted mb-0"><?= e(tOr('settings.sessions_none', 'No active sessions.')) ?></p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= e(tOr('settings.sessions_th_device', 'Device')) ?></th>
                                    <th><?= e(tOr('settings.sessions_th_ip', 'IP address')) ?></th>
                                    <th><?= e(tOr('settings.sessions_th_last_seen', 'Last seen')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activeSessions as $s): ?>
                                <tr>
                                    <td class="text-truncate" style="max-width:16rem;" title="<?= e($s['user_agent'] ?? '') ?>">
                                        <?= dashOrValue($s['user_agent']) ?>
                                        <?php if ($s['token_hash'] === $currentDeviceTokenHash): ?>
                                            <span class="badge bg-secondary"><?= e(tOr('settings.sessions_this_device', 'This device')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= dashOrValue($s['ip']) ?></td>
                                    <td><?= e(formatDate($s['last_seen_at'], true)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('settings.change_password_title')) ?></div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('settings.current_password')) ?></label>
                        <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('settings.new_password')) ?></label>
                        <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('settings.confirm_new_password')) ?></label>
                        <input type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= e(t('settings.change_password_title')) ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
