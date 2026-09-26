<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/security-score.php';
require_once __DIR__ . '/includes/renewals.php';
require_once __DIR__ . '/includes/needs-attention.php';

requireLogin();

$pdo = db();

// Every count/list on this page is scoped with visibilityScope() so what a
// member sees summarized here always matches what they can actually open —
// VERIFIED bug: an owner's private email was previously counted and listed
// for a member/viewer who could never open it.
$emailsCount = (int) $pdo->query('SELECT COUNT(*) FROM emails WHERE ' . visibilityScope('emails'))->fetchColumn();
$servicesCount = (int) $pdo->query('SELECT COUNT(*) FROM services WHERE ' . visibilityScope('services'))->fetchColumn();
$accountsCount = (int) $pdo->query('SELECT COUNT(*) FROM accounts WHERE is_archived = 0 AND ' . visibilityScope('accounts'))->fetchColumn();
// subscriptions has no owner_user_id of its own — scoped through the account
// each one belongs to, same as the export/renewals queries.
$paidAccountsCount = (int) $pdo->query("SELECT COUNT(*) FROM subscriptions sub
    JOIN accounts a ON a.id = sub.account_id
    WHERE sub.type = 'Paid' AND sub.status = 'Active' AND " . visibilityScope('accounts', 'a'))->fetchColumn();

$hiddenPrivateCount = array_sum(array_map('hiddenPrivateRecordsCount', VISIBILITY_SCOPED_TABLES));

$needsAttentionItems = getNeedsAttentionItems($pdo);
$needsAttentionSummary = needsAttentionSummary($needsAttentionItems);

$emailTwofaRows = $pdo->query('SELECT es.twofa_status FROM emails e
    LEFT JOIN email_security es ON es.email_id = e.id WHERE e.is_archived = 0 AND ' . visibilityScope('emails', 'e'))->fetchAll();
$emailTwofaTally = tallySecurityStates($emailTwofaRows, 'twofa_status');

$accountTwofaRows = $pdo->query('SELECT acs.twofa_status FROM accounts a
    LEFT JOIN account_security acs ON acs.account_id = a.id WHERE a.is_archived = 0 AND ' . visibilityScope('accounts', 'a'))->fetchAll();
$accountTwofaTally = tallySecurityStates($accountTwofaRows, 'twofa_status');

$renewalsWidgetDays = 30;
$renewalsData = fetchRenewals($pdo, $renewalsWidgetDays);

$costs = fetchCostsByCurrency($pdo);
$byCurrency = [];
foreach ($costs as $row) {
    $byCurrency[$row['currency']][] = $row;
}
ksort($byCurrency);

// history is polymorphic (entity_type + entity_id can point at any of the
// four scoped tables) so it can't take a single visibilityScope() call —
// each branch is scoped against the specific table its entity_type names,
// and the LIMIT applies AFTER that filtering so 15 rows always means 15
// VISIBLE rows, not 15 raw rows some of which then get hidden.
$recentHistory = $pdo->query("SELECT * FROM history h
    WHERE (h.entity_type = 'email' AND EXISTS (SELECT 1 FROM emails e WHERE e.id = h.entity_id AND (" . visibilityScope('emails', 'e') . ")))
       OR (h.entity_type = 'service' AND EXISTS (SELECT 1 FROM services s WHERE s.id = h.entity_id AND (" . visibilityScope('services', 's') . ")))
       OR (h.entity_type = 'account' AND EXISTS (SELECT 1 FROM accounts a WHERE a.id = h.entity_id AND (" . visibilityScope('accounts', 'a') . ")))
       OR (h.entity_type = 'phone' AND EXISTS (SELECT 1 FROM phones p WHERE p.id = h.entity_id AND (" . visibilityScope('phones', 'p') . ")))
    ORDER BY h.created_at DESC, h.id DESC LIMIT 15")->fetchAll();

$pageTitle = t('nav.dashboard');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('nav.dashboard')) ?></h1>

<?php if ($hiddenPrivateCount > 0): ?>
    <p class="text-muted small mb-3"><?= e(t('common.hidden_private_records_notice', ['count' => $hiddenPrivateCount])) ?></p>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <a href="modules/emails/index.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('emails.title')) ?></div>
                    <div class="h3 mb-0"><?= (int) $emailsCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="modules/services/index.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('services.title')) ?></div>
                    <div class="h3 mb-0"><?= (int) $servicesCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="modules/accounts/index.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('accounts.title')) ?></div>
                    <div class="h3 mb-0"><?= (int) $accountsCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="modules/accounts/costs.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('services.paid_accounts')) ?></div>
                    <div class="h3 mb-0"><?= (int) $paidAccountsCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <a href="needs-attention.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('nav.needs_attention')) ?></div>
                    <div class="h3 mb-0">
                        <span class="text-danger"><?= (int) $needsAttentionSummary['Critical'] ?></span>
                        <span class="text-muted small">/</span>
                        <span class="text-warning"><?= (int) $needsAttentionSummary['Warning'] ?></span>
                        <span class="text-muted small">/</span>
                        <span class="text-info"><?= (int) $needsAttentionSummary['Informational'] ?></span>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<h2 class="h5 mb-3"><?= e(t('dashboard.security_status_title')) ?></h2>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold"><?= e(t('dashboard.email_security_2fa_title')) ?></div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <span class="badge badge-enabled"><?= e(t('enum.Enabled')) ?>: <?= (int) $emailTwofaTally['Enabled'] ?></span>
                <span class="badge badge-disabled"><?= e(t('enum.Disabled')) ?>: <?= (int) $emailTwofaTally['Disabled'] ?></span>
                <span class="badge badge-unknown"><?= e(t('enum.Unknown')) ?>: <?= (int) $emailTwofaTally['Unknown'] ?></span>
                <span class="badge badge-not-set"><?= e(t('enum.Not Set')) ?>: <?= (int) $emailTwofaTally['Not Set'] ?></span>
                <span class="badge badge-not-applicable"><?= e(t('enum.Not Applicable')) ?>: <?= (int) $emailTwofaTally['Not Applicable'] ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold"><?= e(t('dashboard.account_security_2fa_title')) ?></div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <span class="badge badge-enabled"><?= e(t('enum.Enabled')) ?>: <?= (int) $accountTwofaTally['Enabled'] ?></span>
                <span class="badge badge-disabled"><?= e(t('enum.Disabled')) ?>: <?= (int) $accountTwofaTally['Disabled'] ?></span>
                <span class="badge badge-unknown"><?= e(t('enum.Unknown')) ?>: <?= (int) $accountTwofaTally['Unknown'] ?></span>
                <span class="badge badge-not-set"><?= e(t('enum.Not Set')) ?>: <?= (int) $accountTwofaTally['Not Set'] ?></span>
                <span class="badge badge-not-applicable"><?= e(t('enum.Not Applicable')) ?>: <?= (int) $accountTwofaTally['Not Applicable'] ?></span>
            </div>
        </div>
    </div>
</div>
<p class="text-muted small mb-4"><?= e(t('dashboard.security_independent_note')) ?></p>

<h2 class="h5 mb-3">Subscription</h2>
<div class="mb-2">
    <a href="modules/accounts/costs.php" class="small"><?= e(t('dashboard.view_full_costs_report')) ?> &rarr;</a>
</div>
<?php require __DIR__ . '/includes/partials/renewals-widget.php'; ?>

<div class="card am-card mt-3 mb-4">
    <div class="card-header bg-white fw-bold"><?= e(t('dashboard.active_subs_by_currency_title')) ?></div>
    <div class="card-body">
        <?php if (!$byCurrency): ?>
            <p class="text-muted mb-0"><?= e(t('accounts.no_paid_subscriptions')) ?></p>
        <?php else: ?>
            <div class="d-flex gap-4 flex-wrap">
                <?php foreach ($byCurrency as $currency => $rows): ?>
                    <div>
                        <div class="fw-bold mb-1"><?= e($currency) ?></div>
                        <?php foreach ($rows as $r): ?>
                            <div class="small text-muted">
                                <?= renderBadge($r['billing_cycle'], BILLING_CYCLES) ?>
                                <?= e($currency) ?> <?= e((string) $r['total']) ?>
                                (<?= (int) $r['account_count'] ?> <?= e(t('dashboard.account_count_suffix')) ?>)
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="text-muted small mt-2 mb-0"><?= e(t('dashboard.currencies_never_summed')) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <h2 class="h5 mb-3"><?= e(t('dashboard.recent_activity_title')) ?></h2>
        <div class="card am-card">
            <?php if (!$recentHistory): ?>
                <div class="card-body text-center py-4">
                    <p class="text-muted mb-0"><?= e(t('dashboard.no_recent_activity')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentHistory as $h): ?>
                        <?php $label = entityDisplayLabel($pdo, $h['entity_type'], (int) $h['entity_id']); ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <strong><?= e(historyActionLabel($h['action'])) ?></strong>
                                <?php if ($label !== null): ?>
                                    — <a href="<?= e(entityProfileUrl($h['entity_type'], (int) $h['entity_id'])) ?>"><?= e($label) ?></a>
                                <?php endif; ?>
                            </div>
                            <span class="text-muted small"><?= e(formatDate($h['created_at'], true)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <h2 class="h5 mb-3"><?= e(t('dashboard.quick_actions_title')) ?></h2>
        <div class="d-flex flex-column gap-2">
            <a href="modules/emails/add.php" class="btn btn-outline-primary text-start"><?= e(t('emails.add')) ?></a>
            <a href="modules/accounts/quick-add.php" class="btn btn-outline-primary text-start"><?= e(t('dashboard.quick_add_account')) ?></a>
            <a href="modules/services/add.php" class="btn btn-outline-primary text-start"><?= e(t('services.add')) ?></a>
            <a href="modules/phones/add.php" class="btn btn-outline-primary text-start"><?= e(t('phones.add')) ?></a>
            <a href="search.php" class="btn btn-outline-secondary text-start"><?= e(t('dashboard.global_search')) ?></a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
