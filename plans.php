<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/plans.php';

requireLogin();

$plans = platformDb()->query(
    'SELECT code, name, max_members, max_accounts, monthly_price, yearly_price FROM plans ORDER BY monthly_price IS NULL, monthly_price ASC'
)->fetchAll();

$workspaceId = currentWorkspaceId();
$currentPlanCode = $workspaceId !== null ? (getWorkspacePlan($workspaceId)['code'] ?? null) : null;

$pageTitle = t('plans.title');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('plans.title')) ?></h1>

<div class="row g-3">
    <?php foreach ($plans as $plan): ?>
        <div class="col-md-4">
            <div class="card am-card h-100<?= $plan['code'] === $currentPlanCode ? ' border-primary' : '' ?>">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span><?= e($plan['name']) ?></span>
                    <?php if ($plan['code'] === $currentPlanCode): ?>
                        <span class="badge bg-primary"><?= e(t('plans.current_plan_badge')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="mb-3 fs-5">
                        <?= $plan['monthly_price'] !== null
                            ? e(number_format((float) $plan['monthly_price'], 2)) . ' / ' . e(t('plans.per_month'))
                            : e(t('plans.price_custom')) ?>
                    </p>
                    <ul class="list-unstyled mb-0 small">
                        <li class="mb-1">
                            <?= $plan['max_members'] !== null
                                ? e(t('plans.limit_members', ['count' => (int) $plan['max_members']]))
                                : e(t('plans.unlimited_members')) ?>
                        </li>
                        <li>
                            <?= $plan['max_accounts'] !== null
                                ? e(t('plans.limit_accounts_count', ['count' => (int) $plan['max_accounts']]))
                                : e(t('plans.unlimited_accounts')) ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
