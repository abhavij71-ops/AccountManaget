<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../includes/subscriptions.php';

requireAdminAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyAdminCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid request.');
    }
    $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($subscriptionId > 0 && $action === 'approve') {
        activateWorkspaceSubscription($subscriptionId);
    } elseif ($subscriptionId > 0 && $action === 'reject') {
        rejectWorkspaceSubscription($subscriptionId);
    }
    header('Location: ' . APP_BASE_URL . '/admin/subscriptions.php');
    exit;
}

$pending = listPendingManualSubscriptions();
$csrf = adminCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Pending payments</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Pending manual payments</h1>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Workspaces</a>
            <a href="users.php" class="btn btn-outline-secondary btn-sm">Pending users</a>
            <a href="settings.php" class="btn btn-outline-secondary btn-sm">Settings</a>
        </div>
    </div>

    <?php if (!$pending): ?>
        <p class="text-muted">Nothing waiting for approval.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Workspace</th>
                        <th>Plan</th>
                        <th>Reference</th>
                        <th>Requested</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $row): ?>
                    <tr>
                        <td><?= e($row['workspace_name']) ?></td>
                        <td><?= e($row['plan_name']) ?></td>
                        <td><?= e($row['payment_reference'] ?? '') !== '' ? e($row['payment_reference']) : '—' ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td class="text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="subscription_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-sm btn-success">Approve</button>
                            </form>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="subscription_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                            </form>
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
