<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
requireWriteAccess();

$entities = [
    'emails' => t('nav.emails'),
    'services' => t('nav.services'),
    'accounts' => t('nav.accounts'),
    'phones' => t('nav.phones'),
    'subscriptions' => t('export.entity_subscriptions'),
    'security' => 'Security Report',
];

$pageTitle = t('export.title');
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('export.title')) ?></h1>
    <a href="../../import-export.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
</div>

<div class="card am-card" style="max-width:480px;">
    <div class="card-body">
        <p class="text-muted small"><?= e(t('export.select_entity')) ?></p>
        <form method="get" action="download.php" class="row g-2 align-items-end">
            <div class="col">
                <label class="form-label"><?= e(t('export.field_entity_type')) ?></label>
                <select name="entity" class="form-select">
                    <?php foreach ($entities as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><?= e(t('export.download_button')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
