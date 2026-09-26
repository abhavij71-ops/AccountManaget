<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/renewals.php';

requireLogin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: costs.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $accountId = (int) ($_POST['account_id'] ?? 0);

    if (($action === 'mark_still_using' || $action === 'mark_cancelled') && $accountId > 0) {
        $accountStmt = $pdo->prepare('SELECT id, visibility, owner_user_id FROM accounts WHERE id = ?');
        $accountStmt->execute([$accountId]);
        $account = $accountStmt->fetch();

        // Same 404-for-hidden, 403-for-visible-but-not-editable gate as
        // modules/accounts/view.php's own POST actions. VERIFIED: a viewer
        // could mark the owner's private paid account Cancelled, or bump
        // its last_login, because this handler previously checked only CSRF.
        if (!$account || !canSeeRecord($account['visibility'] ?? null, isset($account['owner_user_id']) ? (int) $account['owner_user_id'] : null)) {
            notFoundResponse(t('accounts.not_found'));
        }
        requireEditRecord($account);

        if ($action === 'mark_still_using') {
            $pdo->prepare("UPDATE accounts SET last_login = datetime('now') WHERE id = ?")->execute([$accountId]);
        } else {
            $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
            $pdo->prepare("UPDATE subscriptions SET status = 'Cancelled' WHERE id = ? AND account_id = ?")
                ->execute([$subscriptionId, $accountId]);
        }
    }

    header('Location: costs.php');
    exit;
}

$csrf = csrfToken();
$costs = fetchCostsByCurrency($pdo);

$byCurrency = [];
foreach ($costs as $row) {
    $byCurrency[$row['currency']][] = $row;
}
ksort($byCurrency);

$renewalsWidgetDays = 30;
$renewalsData = fetchRenewals($pdo, $renewalsWidgetDays);
$possiblyUnused = fetchPossiblyUnusedSubscriptions($pdo);

$pageTitle = t('accounts.costs');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0"><?= e(t('accounts.costs')) ?></h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('accounts.back_to_accounts_list')) ?></a>
</div>

<div class="card am-card mb-4">
    <div class="card-header bg-white fw-bold"><?= e(t('accounts.paid_subs_by_currency_title')) ?></div>
    <div class="card-body">
        <p class="text-muted small">
            <?= e(t('accounts.currency_disclaimer2')) ?>
        </p>
        <?php if (!$byCurrency): ?>
            <p class="text-muted mb-0"><?= e(t('accounts.no_paid_subscriptions')) ?></p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($byCurrency as $currency => $rows): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-bold mb-2"><?= e($currency) ?></div>
                            <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr><th><?= e(t('accounts.th_billing_cycle')) ?></th><th class="text-end"><?= e(t('accounts.th_count')) ?></th><th class="text-end"><?= e(t('accounts.th_total')) ?></th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?= renderBadge($r['billing_cycle'], BILLING_CYCLES) ?></td>
                                        <td class="text-end"><?= (int) $r['account_count'] ?></td>
                                        <td class="text-end"><?= e($currency) ?> <?= e((string) $r['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card am-card mb-4">
    <div class="card-header bg-white fw-bold"><?= e(t('accounts.possibly_unused_title')) ?></div>
    <div class="card-body">
        <p class="text-muted small"><?= e(t('accounts.possibly_unused_intro')) ?></p>
        <?php if (!$possiblyUnused['accounts']): ?>
            <p class="text-muted mb-0"><?= e(t('accounts.possibly_unused_empty')) ?></p>
        <?php else: ?>
            <?php if ($possiblyUnused['monthly_totals']): ?>
                <p class="mb-3">
                    <?php foreach ($possiblyUnused['monthly_totals'] as $currency => $total): ?>
                        <span class="badge bg-light text-dark border me-1"><?= e($currency) ?> <?= number_format($total, 2) ?> / <?= e(t('accounts.monthly_abbrev')) ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= e(t('accounts.th_account')) ?></th>
                            <th><?= e(t('accounts.th_last_login')) ?></th>
                            <th class="text-end"><?= e(t('accounts.th_price')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($possiblyUnused['accounts'] as $row): ?>
                        <tr>
                            <td>
                                <?= e($row['service_name']) ?>
                                <span class="text-muted small">— <?= e($row['username'] ?: ($row['display_name'] ?: $row['email_address'])) ?></span>
                            </td>
                            <td>
                                <?php if ($row['last_login']): ?>
                                    <?= e(formatDate($row['last_login'], true)) ?>
                                <?php else: ?>
                                    <span class="text-muted"><?= e(t('accounts.never_logged_in')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($row['price'] !== null): ?>
                                    <?= e($row['currency'] ?: '') ?> <?= number_format((float) $row['price'], 2) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if (canEditRecord($row['visibility'] ?? null, isset($row['owner_user_id']) ? (int) $row['owner_user_id'] : null)): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="mark_still_using">
                                        <input type="hidden" name="account_id" value="<?= (int) $row['account_id'] ?>">
                                        <button type="submit" class="btn btn-outline-success btn-sm"><?= e(t('accounts.still_using_it')) ?></button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="mark_cancelled">
                                        <input type="hidden" name="account_id" value="<?= (int) $row['account_id'] ?>">
                                        <input type="hidden" name="subscription_id" value="<?= (int) $row['subscription_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><?= e(t('accounts.i_cancelled_it')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<h2 class="h5 mb-3"><?= e(t('accounts.renewals_title')) ?></h2>
<?php require __DIR__ . '/../../includes/partials/renewals-widget.php'; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
