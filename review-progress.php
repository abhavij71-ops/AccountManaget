<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireRole('owner', 'admin');

$pdo = db();
$platform = platformDb();
$workspaceId = currentWorkspaceId();

$campaignsStmt = $pdo->query('SELECT * FROM review_campaigns ORDER BY id DESC');
$campaigns = $campaignsStmt->fetchAll();

$selectedCampaignId = (int) ($_GET['campaign_id'] ?? 0);
$selectedCampaign = null;
foreach ($campaigns as $c) {
    if ((int) $c['id'] === $selectedCampaignId) {
        $selectedCampaign = $c;
        break;
    }
}
if ($selectedCampaign === null) {
    $selectedCampaign = $campaigns[0] ?? null;
}

$rows = [];
if ($selectedCampaign) {
    $membersStmt = $platform->prepare(
        'SELECT m.user_id, u.email, u.full_name
         FROM memberships m JOIN accounts_users u ON u.id = m.user_id
         WHERE m.workspace_id = ? ORDER BY u.email COLLATE NOCASE'
    );
    $membersStmt->execute([$workspaceId]);
    $members = $membersStmt->fetchAll();

    $statsStmt = $pdo->prepare(
        "SELECT responsible_user_id, COUNT(*) AS total,
                SUM(CASE WHEN response_status != 'pending' THEN 1 ELSE 0 END) AS answered
         FROM review_items WHERE campaign_id = ?
         GROUP BY responsible_user_id"
    );
    $statsStmt->execute([(int) $selectedCampaign['id']]);
    $statsByUser = [];
    foreach ($statsStmt->fetchAll() as $row) {
        $statsByUser[(int) $row['responsible_user_id']] = $row;
    }

    foreach ($members as $m) {
        $stat = $statsByUser[(int) $m['user_id']] ?? ['total' => 0, 'answered' => 0];
        $total = (int) $stat['total'];
        $answered = (int) $stat['answered'];
        $rows[] = [
            'name' => $m['full_name'] ?: $m['email'],
            'total' => $total,
            'answered' => $answered,
            'percent' => $total > 0 ? (int) round(($answered / $total) * 100) : null,
        ];
    }
}

$pageTitle = t('review.progress_title');
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0"><?= e(t('review.progress_title')) ?></h1>
    <a href="review.php" class="btn btn-outline-secondary btn-sm"><?= e(t('review.back_to_review')) ?></a>
</div>

<?php if (!$campaigns): ?>
    <p class="text-muted"><?= e(t('review.no_campaign')) ?></p>
<?php else: ?>
    <form method="get" class="row g-2 mb-4">
        <div class="col-md-6">
            <select name="campaign_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($campaigns as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $selectedCampaign && (int) $selectedCampaign['id'] === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['title']) ?> (<?= e(formatDate($c['start_date'])) ?> – <?= e(formatDate($c['deadline'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <div class="card am-card">
        <div class="card-header bg-white fw-bold"><?= e($selectedCampaign['title']) ?></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th><?= e(t('review.th_member')) ?></th>
                        <th class="text-end"><?= e(t('review.th_assigned')) ?></th>
                        <th class="text-end"><?= e(t('review.th_answered')) ?></th>
                        <th style="width:220px;"><?= e(t('review.th_percent')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td class="text-end"><?= (int) $row['total'] ?></td>
                        <td class="text-end"><?= (int) $row['answered'] ?></td>
                        <td>
                            <?php if ($row['percent'] === null): ?>
                                <span class="text-muted small"><?= e(t('review.no_items_assigned')) ?></span>
                            <?php else: ?>
                                <div class="progress" style="height:1.25rem;">
                                    <div class="progress-bar<?= $row['percent'] >= 100 ? ' bg-success' : '' ?>" role="progressbar" style="width:<?= (int) $row['percent'] ?>%;" aria-valuenow="<?= (int) $row['percent'] ?>" aria-valuemin="0" aria-valuemax="100"><?= (int) $row['percent'] ?>%</div>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
