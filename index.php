<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/security-score.php';
require_once __DIR__ . '/includes/renewals.php';
require_once __DIR__ . '/includes/needs-attention.php';

requireLogin();

$pdo = db();

$emailsCount = (int) $pdo->query('SELECT COUNT(*) FROM emails')->fetchColumn();
$servicesCount = (int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
$accountsCount = (int) $pdo->query('SELECT COUNT(*) FROM accounts WHERE is_archived = 0')->fetchColumn();
$paidAccountsCount = (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE type = 'Paid' AND status = 'Active'")->fetchColumn();

$needsAttentionItems = getNeedsAttentionItems($pdo);
$needsAttentionSummary = needsAttentionSummary($needsAttentionItems);

$emailTwofaRows = $pdo->query('SELECT es.twofa_status FROM emails e
    LEFT JOIN email_security es ON es.email_id = e.id WHERE e.is_archived = 0')->fetchAll();
$emailTwofaTally = tallySecurityStates($emailTwofaRows, 'twofa_status');

$accountTwofaRows = $pdo->query('SELECT acs.twofa_status FROM accounts a
    LEFT JOIN account_security acs ON acs.account_id = a.id WHERE a.is_archived = 0')->fetchAll();
$accountTwofaTally = tallySecurityStates($accountTwofaRows, 'twofa_status');

$renewalsWidgetDays = 30;
$renewalsData = fetchRenewals($pdo, $renewalsWidgetDays);

$costs = fetchCostsByCurrency($pdo);
$byCurrency = [];
foreach ($costs as $row) {
    $byCurrency[$row['currency']][] = $row;
}
ksort($byCurrency);

$recentHistory = $pdo->query('SELECT * FROM history ORDER BY created_at DESC, id DESC LIMIT 15')->fetchAll();

$pageTitle = 'داشبورد';
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4">داشبورد</h1>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <a href="modules/emails/index.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">ایمیل‌ها</div>
                    <div class="h3 mb-0"><?= (int) $emailsCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="modules/services/index.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">سرویس‌ها</div>
                    <div class="h3 mb-0"><?= (int) $servicesCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="modules/accounts/index.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">اکانت‌ها</div>
                    <div class="h3 mb-0"><?= (int) $accountsCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="modules/accounts/costs.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">اکانت‌های Paid</div>
                    <div class="h3 mb-0"><?= (int) $paidAccountsCount ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <a href="needs-attention.php" class="text-decoration-none">
            <div class="card am-card text-center h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">نیازمند بررسی</div>
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

<h2 class="h5 mb-3">وضعیت امنیتی</h2>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold">امنیت ایمیل‌ها (2FA)</div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <span class="badge badge-enabled">فعال: <?= (int) $emailTwofaTally['Enabled'] ?></span>
                <span class="badge badge-disabled">غیرفعال: <?= (int) $emailTwofaTally['Disabled'] ?></span>
                <span class="badge badge-unknown">نامشخص: <?= (int) $emailTwofaTally['Unknown'] ?></span>
                <span class="badge badge-not-set">تنظیم‌نشده: <?= (int) $emailTwofaTally['Not Set'] ?></span>
                <span class="badge badge-not-applicable">غیرقابل‌اعمال: <?= (int) $emailTwofaTally['Not Applicable'] ?></span>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold">امنیت اکانت‌ها (2FA)</div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <span class="badge badge-enabled">فعال: <?= (int) $accountTwofaTally['Enabled'] ?></span>
                <span class="badge badge-disabled">غیرفعال: <?= (int) $accountTwofaTally['Disabled'] ?></span>
                <span class="badge badge-unknown">نامشخص: <?= (int) $accountTwofaTally['Unknown'] ?></span>
                <span class="badge badge-not-set">تنظیم‌نشده: <?= (int) $accountTwofaTally['Not Set'] ?></span>
                <span class="badge badge-not-applicable">غیرقابل‌اعمال: <?= (int) $accountTwofaTally['Not Applicable'] ?></span>
            </div>
        </div>
    </div>
</div>
<p class="text-muted small mb-4">امنیت ایمیل و امنیت اکانت‌ها همیشه به‌صورت مستقل از هم گزارش می‌شوند.</p>

<h2 class="h5 mb-3">Subscription</h2>
<div class="mb-2">
    <a href="modules/accounts/costs.php" class="small">مشاهده گزارش کامل هزینه‌ها و تمدیدها &rarr;</a>
</div>
<?php require __DIR__ . '/includes/partials/renewals-widget.php'; ?>

<div class="card am-card mt-3 mb-4">
    <div class="card-header bg-white fw-bold">هزینه Subscriptionهای فعال به تفکیک ارز</div>
    <div class="card-body">
        <?php if (!$byCurrency): ?>
            <p class="text-muted mb-0">هیچ Subscription فعال و پولی با قیمت ثبت‌شده‌ای وجود ندارد.</p>
        <?php else: ?>
            <div class="d-flex gap-4 flex-wrap">
                <?php foreach ($byCurrency as $currency => $rows): ?>
                    <div>
                        <div class="fw-bold mb-1"><?= e($currency) ?></div>
                        <?php foreach ($rows as $r): ?>
                            <div class="small text-muted">
                                <?= renderBadge($r['billing_cycle'], BILLING_CYCLES) ?>
                                <?= e($currency) ?> <?= e((string) $r['total']) ?>
                                (<?= (int) $r['account_count'] ?> اکانت)
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="text-muted small mt-2 mb-0">ارزهای مختلف هرگز با هم جمع نمی‌شوند.</p>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <h2 class="h5 mb-3">فعالیت‌های اخیر</h2>
        <div class="card am-card">
            <?php if (!$recentHistory): ?>
                <div class="card-body text-center py-4">
                    <p class="text-muted mb-0">هنوز فعالیتی ثبت نشده است.</p>
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
                            <span class="text-muted small"><?= e($h['created_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <h2 class="h5 mb-3">اقدامات سریع</h2>
        <div class="d-flex flex-column gap-2">
            <a href="modules/emails/add.php" class="btn btn-outline-primary text-start">+ افزودن ایمیل</a>
            <a href="modules/accounts/quick-add.php" class="btn btn-outline-primary text-start">+ افزودن اکانت</a>
            <a href="modules/services/add.php" class="btn btn-outline-primary text-start">+ افزودن سرویس</a>
            <a href="modules/phones/add.php" class="btn btn-outline-primary text-start">+ افزودن شماره تلفن</a>
            <a href="search.php" class="btn btn-outline-secondary text-start">جستجوی سراسری</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
