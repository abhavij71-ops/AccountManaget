<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/needs-attention.php';

requireLogin();

$pdo = db();
$items = getNeedsAttentionItems($pdo);
// The summary (and its counts-at-top cards below) is always computed from
// the FULL, unfiltered/unpaginated list — it must reflect everything, not
// just whatever level/page happens to be showing right now.
$summary = needsAttentionSummary($items);

$levelFilter = (string) ($_GET['level'] ?? '');
if ($levelFilter !== '' && in_array($levelFilter, NEEDS_ATTENTION_LEVELS, true)) {
    $items = array_values(array_filter($items, static fn ($i) => $i['level'] === $levelFilter));
}

// 50/page by default (MEASURED: an unpaginated 3,000-account workspace
// rendered a 7.7 MB page) — via the same pagination helper every list page
// already uses, just seeded to 50 here instead of that helper's own
// smaller default, since a 25-item default page would still be far too
// small a slice of a multi-thousand-item report.
$totalCount = count($items);
$perPage = resolvePerPage($_GET['per_page'] ?? '50');
$page = resolvePage($_GET['page'] ?? null);
[$page, $limit, $offset] = paginationBounds($totalCount, $page, $perPage);
$pageItems = $limit === null ? $items : array_slice($items, $offset, $limit);

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
            <?php $lastLevel = null; ?>
            <?php foreach ($pageItems as $item): ?>
                <?php if ($item['level'] !== $lastLevel): ?>
                    <?php $lastLevel = $item['level']; ?>
                    <li class="list-group-item bg-light fw-bold d-flex align-items-center gap-2">
                        <?= renderNeedsAttentionLevelBadge($lastLevel) ?>
                        <span><?= e(needsAttentionLevelLabel($lastLevel)) ?> (<?= (int) $summary[$lastLevel] ?>)</span>
                    </li>
                <?php endif; ?>
                <li class="list-group-item d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <a href="<?= e(appUrl($item['url'])) ?>" class="fw-bold"><?= e($item['title']) ?></a>
                        <div class="text-muted small"><?= e($item['message']) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?= renderPagination($totalCount, $page, $perPage) ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
