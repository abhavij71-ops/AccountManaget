<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

$pdo = db();
$cutoff = date('Y-m-d', strtotime('-180 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: review.php');
        exit;
    }

    $accountId = (int) ($_POST['account_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    $stmt = $pdo->prepare('SELECT last_verified, status FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $current = $stmt->fetch();

    if ($current) {
        if ($action === 'confirm') {
            $today = date('Y-m-d');
            $pdo->prepare('UPDATE accounts SET last_verified = ? WHERE id = ?')->execute([$today, $accountId]);
            log_history($pdo, 'account', $accountId, 'Account Updated', 'last_verified', $current['last_verified'], $today);
            flashSet('success', t('review.confirmed_success'));
        } elseif ($action === 'gone') {
            $pdo->prepare("UPDATE accounts SET status = 'Closed' WHERE id = ?")->execute([$accountId]);
            log_history($pdo, 'account', $accountId, 'Status Changed', 'status', $current['status'], 'Closed');
            flashSet('success', t('review.closed_success'));
        }
    }

    header('Location: review.php');
    exit;
}

$stmt = $pdo->prepare("SELECT a.id, a.username, a.display_name, a.status, a.last_verified, a.identity_type,
        s.service_name, e.email_address, p.phone_number
    FROM accounts a
    JOIN services s ON s.id = a.service_id
    LEFT JOIN emails e ON e.id = a.email_id
    LEFT JOIN phones p ON p.id = a.identity_phone_id
    WHERE a.is_archived = 0 AND a.status NOT IN ('Closed', 'Abandoned')
      AND (a.last_verified IS NULL OR a.last_verified < :cutoff)
    ORDER BY a.last_verified IS NOT NULL, a.last_verified ASC");
$stmt->execute(['cutoff' => $cutoff]);
$queue = $stmt->fetchAll();

$totalCount = count($queue);
$current = $queue[0] ?? null;

$csrf = csrfToken();
$pageTitle = t('review.title');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('review.title')) ?></h1>

<?php if (!$current): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0"><?= e(t('review.all_caught_up')) ?></p>
        </div>
    </div>
<?php else: ?>
    <p class="text-muted"><?= e(t('review.progress', ['current' => 1, 'total' => $totalCount])) ?></p>

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
                <input type="hidden" name="account_id" value="<?= (int) $current['id'] ?>">
                <button type="submit" class="btn btn-success"><?= e(t('review.confirmed_button')) ?></button>
            </form>
            <a href="modules/accounts/edit.php?id=<?= (int) $current['id'] ?>" class="btn btn-outline-primary"><?= e(t('review.changed_button')) ?></a>
            <form method="post" class="d-inline" data-confirm="<?= e(t('review.gone_confirm')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="gone">
                <input type="hidden" name="account_id" value="<?= (int) $current['id'] ?>">
                <button type="submit" class="btn btn-outline-danger"><?= e(t('review.gone_button')) ?></button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
