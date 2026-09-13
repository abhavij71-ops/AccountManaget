<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireLogin();

$pageTitle = t('nav.import_export');
require __DIR__ . '/includes/header.php';
?>
<h1 class="h4 mb-4"><?= e(t('nav.import_export')) ?></h1>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold">Import</div>
            <div class="card-body">
                <p class="text-muted small">
                    <?= e(t('ie.import_description')) ?>
                </p>
                <a href="modules/import/index.php" class="btn btn-primary"><?= e(t('ie.start_import')) ?></a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold">Export</div>
            <div class="card-body">
                <p class="text-muted small mb-0"><?= e(t('ie.export_description')) ?></p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
