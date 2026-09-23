<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/security-score.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM phones WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$phone = $stmt->fetch();

if (!$phone || !canSeeRecord($phone['visibility'] ?? null, isset($phone['owner_user_id']) ? (int) $phone['owner_user_id'] : null)) {
    notFoundResponse(t('phones.not_found'));
}

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
            attachTag($pdo, 'phone', $id, $tagName);
            log_history($pdo, 'phone', $id, 'Tag Added', null, null, $tagName);
            flashSet('success', t('common.tag_added'));
        }
    } elseif ($action === 'remove_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
        $stmt->execute([$tagId]);
        $tagName = $stmt->fetchColumn();
        detachTag($pdo, 'phone', $id, $tagId);
        if ($tagName) {
            log_history($pdo, 'phone', $id, 'Tag Removed', null, (string) $tagName, null);
        }
        flashSet('success', t('common.tag_removed'));
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM phones WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute(['phone', $id]);
        flashSet('success', t('phones.deleted_success', ['name' => $phone['phone_number']]));
        header('Location: index.php');
        exit;
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM phone_security WHERE phone_id = ?');
$stmt->execute([$id]);
$phoneSecurity = $stmt->fetch() ?: null;
$phoneSecurityScore = calcPhoneSecurityScore($phoneSecurity);

$tags = fetchEntityTags($pdo, 'phone', $id);
$allTagNames = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare('SELECT * FROM history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute(['phone', $id]);
$historyRows = $stmt->fetchAll();

$csrf = csrfToken();

$stmt = $pdo->prepare('SELECT e.id, e.email_address FROM emails e
    JOIN phone_email pe ON pe.email_id = e.id WHERE pe.phone_id = ? ORDER BY e.email_address');
$stmt->execute([$id]);
$linkedEmails = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT e.id, e.email_address FROM emails e
    JOIN email_security es ON es.email_id = e.id WHERE es.recovery_phone_id = ? ORDER BY e.email_address");
$stmt->execute([$id]);
$recoveryForEmails = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT a.id, a.username, a.status, s.id AS service_id, s.service_name FROM accounts a
    JOIN services s ON s.id = a.service_id
    JOIN phone_account pa ON pa.account_id = a.id WHERE pa.phone_id = ? ORDER BY s.service_name');
$stmt->execute([$id]);
$linkedAccounts = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT a.id, a.username, a.status, s.id AS service_id, s.service_name FROM accounts a
    JOIN services s ON s.id = a.service_id
    WHERE a.identity_phone_id = ? ORDER BY s.service_name');
$stmt->execute([$id]);
$identityAccounts = $stmt->fetchAll();

$pageTitle = $phone['phone_number'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1"><?= e($phone['phone_number']) ?></h1>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?= renderBadge($phone['status'], PHONE_STATUSES) ?>
            <?php if ((int) $phone['is_primary'] === 1): ?>
                <span class="badge badge-enabled"><?= e(t('phones.primary_badge')) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?= (int) $id ?>" class="btn btn-primary btn-sm"><?= e(t('common.edit')) ?></a>
        <form method="post" class="d-inline" data-confirm="<?= e(t('phones.delete_confirm')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-outline-danger btn-sm"><?= e(t('common.delete')) ?></button>
        </form>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('common.basic_info')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(t('phones.field_country')) ?></dt><dd class="col-7"><?= dashOrValue($phone['country']) ?></dd>
                    <dt class="col-5"><?= e(t('phones.view_label')) ?></dt><dd class="col-7"><?= dashOrValue($phone['label']) ?></dd>
                </dl>
            </div>
        </div>
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><?= e(t('phones.security_heading')) ?></span>
                <?php if ($phoneSecurityScore !== null): ?>
                    <span class="text-muted small"><?= e(t('common.security_score')) ?>: <?= (int) $phoneSecurityScore ?>%</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6"><?= e(t('phones.field_sim_pin_status')) ?></dt><dd class="col-6"><?= renderBadge($phoneSecurity['sim_pin_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6"><?= e(t('phones.field_port_out_lock')) ?></dt><dd class="col-6"><?= renderBadge($phoneSecurity['port_out_lock'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6"><?= e(t('phones.field_carrier')) ?></dt><dd class="col-6"><?= dashOrValue($phoneSecurity['carrier'] ?? null) ?></dd>
                    <dt class="col-6"><?= e(t('phones.field_esim')) ?></dt>
                    <dd class="col-6"><?php
                        $esim = $phoneSecurity['esim'] ?? null;
                        echo yesNoBadge($esim === null ? null : (bool) $esim);
                    ?></dd>
                    <dt class="col-6"><?= e(t('field.last_security_check')) ?></dt><dd class="col-6"><?= dashOrValue($phoneSecurity['last_security_check'] ?? null) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('common.field_notes')) ?></div>
            <div class="card-body">
                <?= $phone['notes'] ? nl2br(e($phone['notes'])) : '<span class="text-muted fst-italic">' . e(t('common.no_notes')) . '</span>' ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('phones.linked_emails_title')) ?></div>
            <div class="card-body">
                <?php if (!$linkedEmails): ?>
                    <p class="text-muted mb-0"><?= e(t('phones.no_linked_emails')) ?></p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($linkedEmails as $em): ?>
                            <li><a href="../emails/view.php?id=<?= (int) $em['id'] ?>"><?= e($em['email_address']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('phones.recovery_for_title')) ?></div>
            <div class="card-body">
                <?php if (!$recoveryForEmails): ?>
                    <p class="text-muted mb-0"><?= e(t('phones.no_recovery_for')) ?></p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($recoveryForEmails as $em): ?>
                            <li><a href="../emails/view.php?id=<?= (int) $em['id'] ?>"><?= e($em['email_address']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('phones.linked_accounts_title')) ?></div>
    <div class="card-body">
        <?php if (!$linkedAccounts): ?>
            <p class="text-muted mb-0"><?= e(t('phones.no_linked_accounts')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th><?= e(t('accounts.th_service')) ?></th><th><?= e(t('accounts.th_username')) ?></th><th><?= e(t('common.field_status')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($linkedAccounts as $acc): ?>
                        <tr>
                            <td><a href="../services/view.php?id=<?= (int) $acc['service_id'] ?>"><?= e($acc['service_name']) ?></a></td>
                            <td><a href="../accounts/view.php?id=<?= (int) $acc['id'] ?>"><?= $acc['username'] ? e($acc['username']) : e(t('emails.view_account')) ?></a></td>
                            <td><?= renderBadge($acc['status'], ACCOUNT_STATUSES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('phones.identity_accounts_title')) ?></div>
    <div class="card-body">
        <?php if (!$identityAccounts): ?>
            <p class="text-muted mb-0"><?= e(t('phones.no_identity_accounts')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th><?= e(t('accounts.th_service')) ?></th><th><?= e(t('accounts.th_username')) ?></th><th><?= e(t('common.field_status')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($identityAccounts as $acc): ?>
                        <tr>
                            <td><a href="../services/view.php?id=<?= (int) $acc['service_id'] ?>"><?= e($acc['service_name']) ?></a></td>
                            <td><a href="../accounts/view.php?id=<?= (int) $acc['id'] ?>"><?= $acc['username'] ? e($acc['username']) : e(t('emails.view_account')) ?></a></td>
                            <td><?= renderBadge($acc['status'], ACCOUNT_STATUSES) ?></td>
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
