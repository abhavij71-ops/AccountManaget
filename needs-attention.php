<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/needs-attention.php';

requireLogin();

$pdo = db();
$items = getNeedsAttentionItems($pdo);
$summary = needsAttentionSummary($items);

$levelFilter = (string) ($_GET['level'] ?? '');
if ($levelFilter !== '' && in_array($levelFilter, NEEDS_ATTENTION_LEVELS, true)) {
    $items = array_values(array_filter($items, static fn ($i) => $i['level'] === $levelFilter));
}

$pageTitle = t('nav.needs_attention');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('na.page_title')) ?></h1>

<div class="row g-3 mb-4">
    <div class="col-4">
        <a href="?level=Critical" class="text-decoration-none">
            <div class="card am-card text-center h-100 <?= $levelFilter === 'Critical' ? 'border-danger' : '' ?>">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('na.critical_card')) ?></div>
                    <div class="h3 mb-0 text-danger"><?= (int) $summary['Critical'] ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-4">
        <a href="?level=Warning" class="text-decoration-none">
            <div class="card am-card text-center h-100 <?= $levelFilter === 'Warning' ? 'border-warning' : '' ?>">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('na.warning_card')) ?></div>
                    <div class="h3 mb-0 text-warning"><?= (int) $summary['Warning'] ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-4">
        <a href="?level=Informational" class="text-decoration-none">
            <div class="card am-card text-center h-100 <?= $levelFilter === 'Informational' ? 'border-info' : '' ?>">
                <div class="card-body">
                    <div class="text-muted small mb-1"><?= e(t('na.level_informational')) ?></div>
                    <div class="h3 mb-0 text-info"><?= (int) $summary['Informational'] ?></div>
                </div>
            </div>
        </a>
    </div>
</div>

<?php if ($levelFilter !== ''): ?>
    <a href="needs-attention.php" class="btn btn-sm btn-outline-secondary mb-3"><?= e(t('na.clear_filter')) ?></a>
<?php endif; ?>

<?php if (!$items): ?>
    <div class="card am-card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0"><?= e(t('na.no_items')) ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="card am-card">
        <ul class="list-group list-group-flush">
            <?php foreach ($items as $item): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <?= renderNeedsAttentionLevelBadge($item['level']) ?>
                        <a href="<?= e(appUrl($item['url'])) ?>" class="fw-bold ms-2"><?= e($item['title']) ?></a>
                        <div class="text-muted small"><?= e($item['message']) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
