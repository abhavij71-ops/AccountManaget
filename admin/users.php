<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../includes/platform-db.php';
require_once __DIR__ . '/../includes/registration.php';
require_once __DIR__ . '/../includes/audit.php';

requireAdminAuth();

$approveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyAdminCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid request.');
    }

    if ((string) ($_POST['action'] ?? '') === 'approve') {
        $verificationId = (int) ($_POST['verification_id'] ?? 0);
        // No expiry check here on purpose (findPendingEmailVerificationById(),
        // not findValidEmailVerification()) — this button exists exactly for
        // the case where the token already expired because the automated
        // email never arrived.
        $verification = $verificationId > 0 ? findPendingEmailVerificationById($verificationId) : null;

        if ($verification === null) {
            $approveError = 'That signup request could not be found — it may already have been approved.';
        } else {
            try {
                completeEmailVerification($verification);
                logAuditEvent(
                    'user.approved',
                    'user',
                    (int) $verification['user_id'],
                    ['actor' => 'platform-admin', 'workspace_name' => $verification['workspace_name']],
                    userId: null
                );
            } catch (Throwable $e) {
                $approveError = 'Approval failed: ' . $e->getMessage();
            }
        }
    }

    if ($approveError === '') {
        header('Location: ' . APP_BASE_URL . '/admin/users.php');
        exit;
    }
}

$platform = platformDb();
$pending = $platform->query(
    "SELECT u.id AS user_id, u.email, u.full_name, u.created_at AS signed_up_at,
            ev.id AS verification_id, ev.workspace_name, ev.expires_at
     FROM accounts_users u
     LEFT JOIN email_verifications ev ON ev.user_id = u.id AND ev.verified_at IS NULL
     WHERE u.is_active = 0
     ORDER BY u.created_at ASC"
)->fetchAll();

$csrf = adminCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Pending users</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Pending users</h1>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Workspaces</a>
            <a href="settings.php" class="btn btn-outline-secondary btn-sm">Settings</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Sign out</a>
        </div>
    </div>

    <p class="text-muted small">
        Signed up but not yet email-verified. Approving here activates the account and creates its
        workspace immediately, exactly as clicking the verification link would — use this when a
        broken or unconfigured SMTP setup means that link never arrived.
    </p>

    <?php if ($approveError !== ''): ?>
        <div class="alert alert-danger py-2"><?= e($approveError) ?></div>
    <?php endif; ?>

    <?php if (!$pending): ?>
        <p class="text-muted">Nothing pending.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Requested workspace</th>
                        <th>Signed up</th>
                        <th>Verification expires</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $row): ?>
                    <tr>
                        <td><?= e($row['email']) ?></td>
                        <td><?= e($row['full_name'] ?? '') !== '' ? e($row['full_name']) : '—' ?></td>
                        <td><?= e($row['workspace_name'] ?? '—') ?></td>
                        <td><?= e($row['signed_up_at']) ?></td>
                        <td><?= e($row['expires_at'] ?? '—') ?></td>
                        <td class="text-end">
                            <?php if ($row['verification_id']): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="verification_id" value="<?= (int) $row['verification_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small">no pending verification row</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
