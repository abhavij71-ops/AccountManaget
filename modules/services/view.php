<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/security-score.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) {
    flashSet('danger', t('services.not_found'));
    header('Location: index.php');
    exit;
}

$defaults = fetchServiceDefaults($pdo, $id) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: view.php?id=' . $id);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_tag') {
        $tagName = trim((string) ($_POST['tag_name'] ?? ''));
        if ($tagName !== '') {
            attachTag($pdo, 'service', $id, $tagName);
            log_history($pdo, 'service', $id, 'Tag Added', null, null, $tagName);
            flashSet('success', t('common.tag_added'));
        }
    } elseif ($action === 'remove_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
        $stmt->execute([$tagId]);
        $tagName = $stmt->fetchColumn();
        detachTag($pdo, 'service', $id, $tagId);
        if ($tagName) {
            log_history($pdo, 'service', $id, 'Tag Removed', null, (string) $tagName, null);
        }
        flashSet('success', t('common.tag_removed'));
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute(['service', $id]);
            flashSet('success', t('services.deleted_success', ['name' => $service['service_name']]));
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            flashSet('danger', t('services.delete_blocked'));
        }
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$tags = fetchEntityTags($pdo, 'service', $id);
$allTagNames = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare('SELECT * FROM history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute(['service', $id]);
$historyRows = $stmt->fetchAll();

$csrf = csrfToken();

// LEFT JOINs (not INNER) — an account's email_id/identity_phone_id can legitimately be
// null under the Identity Anchor model (phone/username/other anchors), and those
// accounts must still be listed here. Matches the pattern in search-api.php.
$stmt = $pdo->prepare("SELECT a.id, a.username, a.status, a.last_verified, a.identity_type, a.identity_phone_id,
        e.id AS email_id, e.email_address, p.phone_number,
        sub.plan, sub.type AS sub_type, acs.twofa_status
    FROM accounts a
    LEFT JOIN emails e ON e.id = a.email_id
    LEFT JOIN phones p ON p.id = a.identity_phone_id
    LEFT JOIN subscriptions sub ON sub.account_id = a.id
    LEFT JOIN account_security acs ON acs.account_id = a.id
    WHERE a.service_id = ?
    ORDER BY COALESCE(e.email_address, p.phone_number, a.username, '')");
$stmt->execute([$id]);
$accounts = $stmt->fetchAll();

$accountsCount = count($accounts);
$emailsCount = count(array_unique(array_filter(array_column($accounts, 'email_id'))));
$paidCount = count(array_filter($accounts, static fn ($a) => $a['sub_type'] === 'Paid'));
$issuesCount = count(array_filter($accounts, static fn ($a) => in_array($a['status'], ['Suspended', 'Disabled'], true) || $a['twofa_status'] === 'Disabled'));

$twofaBreakdown = tallySecurityStates($accounts, 'twofa_status');

$pageTitle = $service['service_name'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1"><?= e($service['service_name']) ?></h1>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?= $service['category'] === 'Not Set' ? renderBadge('Not Set') : '<span class="badge bg-light text-dark border">' . e($service['category']) . '</span>' ?>
            <?= renderBadge($service['status'], SERVICE_STATUSES) ?>
            <?php if ($service['website']): ?>
                <a href="<?= e($service['website']) ?>" target="_blank" rel="noopener" class="small"><?= e(t('services.website_link')) ?> &#8599;</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?= (int) $id ?>" class="btn btn-primary btn-sm"><?= e(t('common.edit')) ?></a>
        <form method="post" class="d-inline" data-confirm="<?= e(t('services.delete_confirm')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-outline-danger btn-sm"><?= e(t('common.delete')) ?></button>
        </form>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><?= e(t('services.account_count')) ?></div>
                <div class="h3 mb-0"><?= (int) $accountsCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><?= e(t('services.email_count')) ?></div>
                <div class="h3 mb-0"><?= (int) $emailsCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><?= e(t('services.paid_accounts')) ?></div>
                <div class="h3 mb-0"><?= (int) $paidCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card am-card text-center h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><?= e(t('services.issues_stat')) ?></div>
                <div class="h3 mb-0"><?= (int) $issuesCount ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('services.info_title')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(t('services.view_login_url')) ?></dt><dd class="col-7"><?= dashOrValue($service['login_url']) ?></dd>
                    <dt class="col-5"><?= e(t('services.view_purpose')) ?></dt><dd class="col-7"><?= dashOrValue($service['purpose']) ?></dd>
                </dl>
            </div>
        </div>
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('common.field_notes')) ?></div>
            <div class="card-body">
                <?= $service['notes'] ? nl2br(e($service['notes'])) : '<span class="text-muted fst-italic">' . e(t('common.no_notes')) . '</span>' ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('services.security_overview_title')) ?></div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <span class="badge badge-enabled"><?= e(t('enum.Enabled')) ?>: <?= (int) $twofaBreakdown['Enabled'] ?></span>
                <span class="badge badge-disabled"><?= e(t('enum.Disabled')) ?>: <?= (int) $twofaBreakdown['Disabled'] ?></span>
                <span class="badge badge-unknown"><?= e(t('enum.Unknown')) ?>: <?= (int) $twofaBreakdown['Unknown'] ?></span>
                <span class="badge badge-not-set"><?= e(t('enum.Not Set')) ?>: <?= (int) $twofaBreakdown['Not Set'] ?></span>
                <span class="badge badge-not-applicable"><?= e(t('enum.Not Applicable')) ?>: <?= (int) $twofaBreakdown['Not Applicable'] ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= e(t('services.view_defaults_title')) ?></span>
        <?php if (serviceHasDefaultsTemplate($defaults)): ?>
            <a href="apply-defaults.php?id=<?= (int) $id ?>" class="btn btn-sm btn-outline-primary"><?= e(t('services.apply_defaults_button')) ?></a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-5"><?= e(t('services.field_default_identity_type')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_identity_type']) ? e(t('accounts.identity_type_' . $defaults['default_identity_type'])) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_twofa_status')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_twofa_status']) ? renderBadge($defaults['default_twofa_status'], SECURITY_STATES) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_twofa_method')) ?></dt>
            <dd class="col-7"><?= dashOrValue($defaults['default_twofa_method'] ?? null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_passkey_status')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_passkey_status']) ? renderBadge($defaults['default_passkey_status'], SECURITY_STATES) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_security_questions_status')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_security_questions_status']) ? renderBadge($defaults['default_security_questions_status'], SECURITY_STATES) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_recovery_status')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_recovery_status']) ? renderBadge($defaults['default_recovery_status'], RECOVERY_STATUSES) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_recovery_follows_identity')) ?></dt>
            <dd class="col-7"><?= yesNoBadge((bool) ($defaults['recovery_follows_identity'] ?? 0)) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_subscription_type')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_subscription_type']) ? renderBadge($defaults['default_subscription_type'], SUBSCRIPTION_TYPES) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_subscription_status')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_subscription_status']) ? renderBadge($defaults['default_subscription_status'], SUBSCRIPTION_STATUSES) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_billing_cycle')) ?></dt>
            <dd class="col-7"><?= isset($defaults['default_billing_cycle']) ? renderBadge($defaults['default_billing_cycle'], BILLING_CYCLES) : dashOrValue(null) ?></dd>

            <dt class="col-5"><?= e(t('services.field_default_currency')) ?></dt>
            <dd class="col-7"><?= dashOrValue($defaults['default_currency'] ?? null) ?></dd>
        </dl>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('services.accounts_title')) ?></div>
    <div class="card-body">
        <?php if (!$accounts): ?>
            <p class="text-muted mb-0"><?= e(t('services.no_accounts')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th><?= e(t('accounts.th_identity')) ?></th><th><?= e(t('accounts.th_username')) ?></th><th><?= e(t('common.field_status')) ?></th><th><?= e(t('accounts.th_plan')) ?></th><th>2FA</th><th><?= e(t('common.field_last_verified')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($accounts as $acc): ?>
                        <tr>
                            <td>
                                <?php if ($acc['identity_type'] === 'phone'): ?>
                                    <?php if ($acc['identity_phone_id']): ?>
                                        <a href="../phones/view.php?id=<?= (int) $acc['identity_phone_id'] ?>"><?= e($acc['phone_number']) ?></a>
                                    <?php else: ?>
                                        <?= dashOrValue(null) ?>
                                    <?php endif; ?>
                                <?php elseif ($acc['identity_type'] === 'username'): ?>
                                    <?= dashOrValue($acc['username']) ?>
                                <?php elseif ($acc['identity_type'] === 'other'): ?>
                                    <span class="text-muted"><?= e(t('accounts.identity_type_other')) ?></span>
                                <?php elseif ($acc['email_id']): ?>
                                    <a href="../emails/view.php?id=<?= (int) $acc['email_id'] ?>"><?= e($acc['email_address']) ?></a>
                                <?php else: ?>
                                    <?= dashOrValue(null) ?>
                                <?php endif; ?>
                            </td>
                            <td><a href="../accounts/view.php?id=<?= (int) $acc['id'] ?>"><?= $acc['username'] ? e($acc['username']) : e(t('emails.view_account')) ?></a></td>
                            <td><?= renderBadge($acc['status'], ACCOUNT_STATUSES) ?></td>
                            <td><?= dashOrValue($acc['plan']) ?></td>
                            <td><?= renderBadge($acc['twofa_status'], SECURITY_STATES) ?></td>
                            <td><?= dashOrValue(formatDate($acc['last_verified'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('common.tags_title')) ?></div>
    <div class="card-body">
        <?php if (!$tags): ?>
            <p class="text-muted"><?= e(t('common.no_tags')) ?></p>
        <?php else: ?>
            <div class="d-flex gap-2 flex-wrap mb-2">
                <?php foreach ($tags as $tag): ?>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="remove_tag">
                        <input type="hidden" name="tag_id" value="<?= (int) $tag['id'] ?>">
                        <button type="submit" class="badge bg-light text-dark border" style="cursor:pointer;">
                            <?= e($tag['name']) ?> &times;
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="d-flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add_tag">
            <input type="text" name="tag_name" class="form-control form-control-sm" list="tag-list" placeholder="<?= e(t('common.tag_name_placeholder')) ?>">
            <datalist id="tag-list">
                <?php foreach ($allTagNames as $tn): ?><option value="<?= e($tn) ?>"><?php endforeach; ?>
            </datalist>
            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><?= e(t('common.add_tag')) ?></button>
        </form>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('common.history_title')) ?></div>
    <div class="card-body">
        <?php if (!$historyRows): ?>
            <p class="text-muted mb-0"><?= e(t('common.no_history')) ?></p>
        <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($historyRows as $h): ?>
                    <li class="mb-2 pb-2 border-bottom">
                        <div class="d-flex justify-content-between">
                            <strong><?= e(historyActionLabel($h['action'])) ?></strong>
                            <span class="text-muted small"><?= e(formatDate($h['created_at'], true)) ?></span>
                        </div>
                        <?php if ($h['field_name'] || $h['old_value'] !== null || $h['new_value'] !== null): ?>
                            <div class="small text-muted">
                                <?= $h['field_name'] ? e($h['field_name']) . ': ' : '' ?>
                                <?= e(t('common.history_from')) ?> <?= dashOrValue($h['old_value']) ?> <?= e(t('common.history_to')) ?> <?= dashOrValue($h['new_value']) ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
