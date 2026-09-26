<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../includes/platform-db.php';
require_once __DIR__ . '/../includes/audit.php';

requireAdminAuth();

$platform = platformDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyAdminCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid request.');
    }
    $workspaceId = (int) ($_POST['workspace_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($workspaceId > 0 && in_array($action, ['suspend', 'activate'], true)) {
        $platform->prepare('UPDATE workspaces SET is_suspended = ? WHERE id = ?')
            ->execute([$action === 'suspend' ? 1 : 0, $workspaceId]);
        logAuditEvent(
            $action === 'suspend' ? 'workspace.suspended' : 'workspace.unsuspended',
            'workspace',
            $workspaceId,
            ['actor' => 'platform-admin'],
            userId: null,
            workspaceId: $workspaceId
        );
    }
    header('Location: ' . APP_BASE_URL . '/admin/index.php');
    exit;
}

$workspaces = $platform->query('SELECT id, name, plan_code, is_suspended FROM workspaces ORDER BY name COLLATE NOCASE')->fetchAll();

$memberCounts = [];
foreach ($platform->query('SELECT workspace_id, COUNT(*) AS c FROM memberships GROUP BY workspace_id')->fetchAll() as $row) {
    $memberCounts[(int) $row['workspace_id']] = (int) $row['c'];
}

// Most recent subscription row per workspace stands in for "current status"
// — see the schema comment in the workspace_subscriptions migration.
$subscriptionStatus = [];
foreach ($platform->query(
    'SELECT workspace_id, status FROM workspace_subscriptions
     WHERE id IN (SELECT MAX(id) FROM workspace_subscriptions GROUP BY workspace_id)'
)->fetchAll() as $row) {
    $subscriptionStatus[(int) $row['workspace_id']] = $row['status'];
}

$missingSecrets = missingSecrets();
$dataExposure = checkDataDirExposure();
$csrf = adminCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Workspaces</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/app.css">
</head>
<body>
<div class="container py-4">
    <?php if ($dataExposure === 'exposed'): ?>
        <div class="alert alert-danger">
            <strong>Critical: data/ is publicly downloadable.</strong> The exposure self-check just fetched its own
            probe token back over HTTP — the web server is not blocking direct requests to <code>data/</code>
            (every workspace's SQLite file, including password hashes and TOTP secrets, is reachable). Fix this
            immediately: confirm <code>.htaccess</code> is honored on Apache, add the Nginx <code>deny</code> rule,
            or move <code>DATA_DIR</code> outside the web root. See <code>docs/INSTALLATION.md</code>.
        </div>
    <?php elseif ($dataExposure === 'unknown'): ?>
        <div class="alert alert-warning">
            Could not verify whether <code>data/</code> is protected — the self-check's own loopback request
            failed (common on shared hosting that blocks it). This is <strong>not</strong> a confirmation of
            safety. Verify manually: opening <code>data/database.sqlite</code> directly in a browser must return
            403 Forbidden.
        </div>
    <?php endif; ?>

    <?php if ($missingSecrets): ?>
        <div class="alert alert-warning">
            <strong>Missing configuration:</strong> the following secrets are not set —
            <?= e(implode(', ', $missingSecrets)) ?>. See <code>docs/SECRETS.md</code> for how to set each one.
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Workspaces</h1>
        <div class="d-flex gap-2">
            <a href="users.php" class="btn btn-outline-secondary btn-sm">Pending users</a>
            <a href="subscriptions.php" class="btn btn-outline-secondary btn-sm">Pending payments</a>
            <a href="settings.php" class="btn btn-outline-secondary btn-sm">Settings</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Sign out</a>
        </div>
    </div>

    <?php if (!$workspaces): ?>
        <p class="text-muted">No workspaces yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Workspace</th>
                        <th>Plan</th>
                        <th>Subscription</th>
                        <th class="text-end">Members</th>
                        <th class="text-end">Database size</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($workspaces as $w): ?>
                    <?php
                    $workspaceIdInt = (int) $w['id'];
                    $dbPath = workspaceDatabasePath($workspaceIdInt);
                    $dbSize = file_exists($dbPath) ? (int) filesize($dbPath) : 0;
                    $isSuspended = (int) $w['is_suspended'] === 1;
                    ?>
                    <tr>
                        <td><?= e($w['name']) ?></td>
                        <td><?= e($w['plan_code']) ?></td>
                        <td><?= e($subscriptionStatus[$workspaceIdInt] ?? '—') ?></td>
                        <td class="text-end"><?= (int) ($memberCounts[$workspaceIdInt] ?? 0) ?></td>
                        <td class="text-end"><?= e(formatBytes($dbSize)) ?></td>
                        <td><?= $isSuspended ? '<span class="badge bg-danger">Suspended</span>' : '<span class="badge bg-success">Active</span>' ?></td>
                        <td class="text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="workspace_id" value="<?= $workspaceIdInt ?>">
                                <input type="hidden" name="action" value="<?= $isSuspended ? 'activate' : 'suspend' ?>">
                                <button type="submit" class="btn btn-sm <?= $isSuspended ? 'btn-outline-success' : 'btn-outline-danger' ?>">
                                    <?= $isSuspended ? 'Activate' : 'Suspend' ?>
                                </button>
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
