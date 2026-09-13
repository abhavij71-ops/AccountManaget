<?php
declare(strict_types=1);

/**
 * Reusable "Renewals" widget (spec sec. 31: upcoming / overdue / auto-renew).
 * Self-contained — safe to `require` from any page regardless of its depth
 * (it never assumes a filesystem/URL relationship to the includer). Callers
 * may pre-set $renewalsData (from fetchRenewals()) to avoid a duplicate query,
 * and/or $renewalsWidgetDays to change the "upcoming" window (default 30).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../renewals.php';

if (!isset($renewalsData)) {
    $renewalsData = fetchRenewals(db(), $renewalsWidgetDays ?? 30);
}

if (!function_exists('renewalRowLabel')) {
    function renewalRowLabel(array $row): string
    {
        $who = $row['username'] ?: $row['display_name'] ?: $row['email_address'];
        return $row['service_name'] . ' — ' . $who;
    }

    function renewalRowCost(array $row): string
    {
        if ($row['price'] === null || $row['currency'] === null || $row['currency'] === '') {
            return '';
        }
        return e($row['currency']) . ' ' . e((string) $row['price']);
    }
}
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span>Overdue</span>
                <span class="badge badge-disabled"><?= count($renewalsData['overdue']) ?></span>
            </div>
            <div class="card-body">
                <?php if (!$renewalsData['overdue']): ?>
                    <p class="text-muted small mb-0"><?= e(t('widget.no_overdue')) ?></p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($renewalsData['overdue'] as $row): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <a href="<?= e(appUrl('modules/accounts/view.php?id=' . $row['account_id'])) ?>"><?= e(renewalRowLabel($row)) ?></a>
                                <div class="small text-muted">
                                    <?= e(t('widget.renewal_date_prefix')) ?><?= e($row['renewal_date']) ?>
                                    <?= renewalRowCost($row) !== '' ? ' — ' . renewalRowCost($row) : '' ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span><?= e(t('widget.upcoming_title', ['days' => (int) $renewalsData['upcoming_days']])) ?></span>
                <span class="badge badge-status-pending"><?= count($renewalsData['upcoming']) ?></span>
            </div>
            <div class="card-body">
                <?php if (!$renewalsData['upcoming']): ?>
                    <p class="text-muted small mb-0"><?= e(t('widget.no_upcoming')) ?></p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($renewalsData['upcoming'] as $row): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <a href="<?= e(appUrl('modules/accounts/view.php?id=' . $row['account_id'])) ?>"><?= e(renewalRowLabel($row)) ?></a>
                                <div class="small text-muted">
                                    <?= e(t('widget.renewal_date_prefix')) ?><?= e($row['renewal_date']) ?>
                                    <?= renewalRowCost($row) !== '' ? ' — ' . renewalRowCost($row) : '' ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card am-card h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span><?= e(t('widget.auto_renew_title')) ?></span>
                <span class="badge badge-enabled"><?= count($renewalsData['auto_renewing']) ?></span>
            </div>
            <div class="card-body">
                <?php if (!$renewalsData['auto_renewing']): ?>
                    <p class="text-muted small mb-0"><?= e(t('widget.no_auto_renewing')) ?></p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($renewalsData['auto_renewing'] as $row): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <a href="<?= e(appUrl('modules/accounts/view.php?id=' . $row['account_id'])) ?>"><?= e(renewalRowLabel($row)) ?></a>
                                <div class="small text-muted">
                                    <?= $row['renewal_date'] ? e(t('widget.renewal_date_prefix')) . e($row['renewal_date']) : dashOrValue(null) ?>
                                    <?= renewalRowCost($row) !== '' ? ' — ' . renewalRowCost($row) : '' ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
