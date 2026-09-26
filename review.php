<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

$pdo = db();
$cutoff = date('Y-m-d', strtotime('-180 days'));
$actorRole = currentRole();
$actorUserId = currentUserId();
$isAdmin = in_array($actorRole, ['owner', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: review.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_campaign' && $isAdmin) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $startDate = (string) ($_POST['start_date'] ?? '');
        $deadline = (string) ($_POST['deadline'] ?? '');

        if ($title === '' || $startDate === '' || $deadline === '') {
            flashSet('danger', t('review.campaign_fields_required'));
        } elseif (strtotime($deadline) < strtotime($startDate)) {
            flashSet('danger', t('review.campaign_deadline_before_start'));
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO review_campaigns (title, start_date, deadline, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$title, $startDate, $deadline, $actorUserId]);
                $campaignId = (int) $pdo->lastInsertId();

                // Same due-for-review rule review.php always used, now
                // snapshotted per-account into review_items and handed to
                // whoever owns each account — accounts nobody owns have no
                // one to hand them to, so they're left out of the campaign.
                $dueStmt = $pdo->prepare(
                    "SELECT id, owner_user_id FROM accounts
                     WHERE is_archived = 0 AND status NOT IN ('Closed', 'Abandoned')
                       AND owner_user_id IS NOT NULL
                       AND (last_verified IS NULL OR last_verified < :cutoff)"
                );
                $dueStmt->execute(['cutoff' => $cutoff]);

                $insertItem = $pdo->prepare(
                    'INSERT INTO review_items (campaign_id, account_id, responsible_user_id) VALUES (?, ?, ?)'
                );
                foreach ($dueStmt->fetchAll() as $row) {
                    $insertItem->execute([$campaignId, $row['id'], $row['owner_user_id']]);
                }

                $pdo->commit();
                flashSet('success', t('review.campaign_created_success'));
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        header('Location: review.php');
        exit;
    }

    if (in_array($action, ['confirm', 'changed', 'gone'], true)) {
        $itemId = (int) ($_POST['item_id'] ?? 0);

        // Never trust the posted item id blindly — it must belong to this
        // user and still be pending.
        $itemStmt = $pdo->prepare(
            "SELECT ri.id, ri.account_id, a.last_verified, a.status
             FROM review_items ri JOIN accounts a ON a.id = ri.account_id
             WHERE ri.id = ? AND ri.responsible_user_id = ? AND ri.response_status = 'pending'"
        );
        $itemStmt->execute([$itemId, $actorUserId]);
        $item = $itemStmt->fetch();

        if ($item) {
            if ($action === 'confirm') {
                $today = date('Y-m-d');
                $pdo->prepare('UPDATE accounts SET last_verified = ? WHERE id = ?')->execute([$today, $item['account_id']]);
                log_history($pdo, 'account', $item['account_id'], 'Account Updated', 'last_verified', $item['last_verified'], $today);
                $pdo->prepare("UPDATE review_items SET response_status = 'confirmed', responded_at = datetime('now') WHERE id = ?")->execute([$itemId]);
                flashSet('success', t('review.confirmed_success'));
            } elseif ($action === 'gone') {
                $pdo->prepare("UPDATE accounts SET status = 'Closed' WHERE id = ?")->execute([$item['account_id']]);
                log_history($pdo, 'account', $item['account_id'], 'Status Changed', 'status', $item['status'], 'Closed');
                $pdo->prepare("UPDATE review_items SET response_status = 'gone', responded_at = datetime('now') WHERE id = ?")->execute([$itemId]);
                flashSet('success', t('review.closed_success'));
            } else {
                $pdo->prepare("UPDATE review_items SET response_status = 'changed', responded_at = datetime('now') WHERE id = ?")->execute([$itemId]);
                header('Location: modules/accounts/edit.php?id=' . $item['account_id']);
                exit;
            }
        }

        header('Location: review.php');
        exit;
    }
}

$campaignsStmt = $pdo->query(
    "SELECT c.*, COUNT(ri.id) AS item_count
     FROM review_campaigns c
     LEFT JOIN review_items ri ON ri.campaign_id = c.id
     GROUP BY c.id
     ORDER BY c.id DESC"
);
$campaigns = $campaignsStmt->fetchAll();
$currentCampaign = $campaigns[0] ?? null;

$queue = [];
$totalAssigned = 0;
$answeredCount = 0;
if ($currentCampaign) {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total, SUM(CASE WHEN response_status != 'pending' THEN 1 ELSE 0 END) AS answered
         FROM review_items WHERE campaign_id = ? AND responsible_user_id = ?"
    );
    $countStmt->execute([$currentCampaign['id'], $actorUserId]);
    $counts = $countStmt->fetch();
    $totalAssigned = (int) ($counts['total'] ?? 0);
    $answeredCount = (int) ($counts['answered'] ?? 0);

    $queueStmt = $pdo->prepare(
        "SELECT ri.id AS item_id, a.username, a.display_name, a.status, a.last_verified, a.identity_type,
                s.service_name, e.email_address, p.phone_number
         FROM review_items ri
         JOIN accounts a ON a.id = ri.account_id
         JOIN services s ON s.id = a.service_id
         LEFT JOIN emails e ON e.id = a.email_id
         LEFT JOIN phones p ON p.id = a.identity_phone_id
         WHERE ri.campaign_id = ? AND ri.responsible_user_id = ? AND ri.response_status = 'pending'
         ORDER BY ri.id ASC"
    );
    $queueStmt->execute([$currentCampaign['id'], $actorUserId]);
    $queue = $queueStmt->fetchAll();
}

$current = $queue[0] ?? null;

$csrf = csrfToken();
$pageTitle = t('review.title');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('review.title')) ?></h1>

<?php if ($isAdmin): ?>
    <div class="card am-card mb-4">
        <div class="card-header bg-white fw-bold"><?= e(t('review.campaign_create_title')) ?></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="create_campaign">
                <div class="col-md-5">
                    <label class="form-label"><?= e(t('review.field_campaign_title')) ?></label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= e(t('review.field_start_date')) ?></label>
                    <input type="date" name="start_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= e(t('review.field_deadline')) ?></label>
                    <input type="date" name="deadline" class="form-control" required>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100"><?= e(t('review.campaign_create_button')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($campaigns): ?>
        <div class="card am-card mb-4">
            <div class="card-header bg-white fw-bold"><?= e(t('review.campaigns_title')) ?></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= e(t('review.th_campaign_title')) ?></th>
                            <th><?= e(t('review.field_start_date')) ?></th>
                            <th><?= e(t('review.field_deadline')) ?></th>
                            <th class="text-end"><?= e(t('review.th_item_count')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($campaigns as $c): ?>
                        <tr>
                            <td><?= e($c['title']) ?></td>
                            <td><?= e(formatDate($c['start_date'])) ?></td>
                            <td><?= e(formatDate($c['deadline'])) ?></td>
                            <td class="text-end"><?= (int) $c['item_count'] ?></td>
                            <td class="text-end"><a href="review-progress.php?campaign_id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= e(t('review.view_progress_button')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$currentCampaign): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0"><?= e(t('review.no_campaign')) ?></p>
        </div>
    </div>
<?php elseif (!$current): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0"><?= e(t('review.all_caught_up')) ?></p>
        </div>
    </div>
<?php else: ?>
    <p class="text-muted"><?= e(t('review.progress', ['current' => $answeredCount + 1, 'total' => $totalAssigned])) ?></p>

    <?php
    $identityValue = match ($current['identity_type']) {
        'phone' => (string) ($current['phone_number'] ?? ''),
        'username' => (string) ($current['username'] ?? ''),
        'other' => '',
        default => (string) ($current['email_address'] ?? ''),
    };
    ?>
    <div class="card am-card" style="max-width:520px;">
        <div class="card-header bg-white fw-bold"><?= e($current['service_name']) ?></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-5"><?= e(t('accounts.th_identity')) ?></dt><dd class="col-7"><?= dashOrValue($identityValue) ?></dd>
                <dt class="col-5"><?= e(t('common.field_status')) ?></dt><dd class="col-7"><?= renderBadge($current['status'], ACCOUNT_STATUSES) ?></dd>
                <dt class="col-5"><?= e(t('common.field_last_verified')) ?></dt><dd class="col-7"><?= dashOrValue(formatDate($current['last_verified'])) ?></dd>
            </dl>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap gap-2">
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="item_id" value="<?= (int) $current['item_id'] ?>">
                <button type="submit" class="btn btn-success"><?= e(t('review.confirmed_button')) ?></button>
            </form>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="changed">
                <input type="hidden" name="item_id" value="<?= (int) $current['item_id'] ?>">
                <button type="submit" class="btn btn-outline-primary"><?= e(t('review.changed_button')) ?></button>
            </form>
            <form method="post" class="d-inline" data-confirm="<?= e(t('review.gone_confirm')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="gone">
                <input type="hidden" name="item_id" value="<?= (int) $current['item_id'] ?>">
                <button type="submit" class="btn btn-outline-danger"><?= e(t('review.gone_button')) ?></button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
