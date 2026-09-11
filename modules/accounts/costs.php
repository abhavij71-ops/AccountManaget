<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/renewals.php';

requireLogin();

$pdo = db();
$costs = fetchCostsByCurrency($pdo);

$byCurrency = [];
foreach ($costs as $row) {
    $byCurrency[$row['currency']][] = $row;
}
ksort($byCurrency);

$renewalsWidgetDays = 30;
$renewalsData = fetchRenewals($pdo, $renewalsWidgetDays);

$pageTitle = 'هزینه‌ها و تمدیدها';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">هزینه‌ها و تمدیدها</h1>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">بازگشت به فهرست اکانت‌ها</a>
</div>

<div class="card am-card mb-4">
    <div class="card-header bg-white fw-bold">هزینه Subscriptionهای فعال (Paid) به تفکیک ارز</div>
    <div class="card-body">
        <p class="text-muted small">
            هزینه‌های ارزهای مختلف هرگز با یکدیگر جمع نمی‌شوند. دوره‌های صورتحساب متفاوت (ماهانه/سالانه) نیز جداگانه نمایش داده می‌شوند تا با هم ترکیب نشوند.
        </p>
        <?php if (!$byCurrency): ?>
            <p class="text-muted mb-0">هیچ Subscription فعال و پولی با قیمت ثبت‌شده‌ای وجود ندارد.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($byCurrency as $currency => $rows): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-bold mb-2"><?= e($currency) ?></div>
                            <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr><th>دوره صورتحساب</th><th class="text-end">تعداد</th><th class="text-end">مجموع</th></tr>
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

<h2 class="h5 mb-3">تمدیدها (Renewals)</h2>
<?php require __DIR__ . '/../../includes/partials/renewals-widget.php'; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
