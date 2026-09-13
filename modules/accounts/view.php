<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$account = $id ? fetchAccountById($pdo, $id) : null;

if (!$account) {
    flashSet('danger', t('accounts.not_found'));
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: view.php?id=' . $id);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'verify') {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare('UPDATE accounts SET last_verified = ? WHERE id = ?');
        $stmt->execute([$today, $id]);
        log_history($pdo, 'account', $id, 'Account Updated', 'last_verified', $account['last_verified'], $today);
        flashSet('success', t('accounts.verified_success'));
    } elseif ($action === 'toggle_archive') {
        $newValue = ((int) $account['is_archived']) === 1 ? 0 : 1;
        $stmt = $pdo->prepare('UPDATE accounts SET is_archived = ? WHERE id = ?');
        $stmt->execute([$newValue, $id]);
        log_history($pdo, 'account', $id, $newValue === 1 ? 'Account Archived' : 'Account Unarchived');
        flashSet('success', $newValue === 1 ? t('accounts.archived_success') : t('accounts.unarchived_success'));
    } elseif ($action === 'add_custom_field') {
        $key = trim((string) ($_POST['field_key'] ?? ''));
        $value = trim((string) ($_POST['field_value'] ?? ''));
        if ($key === '') {
            flashSet('danger', t('accounts.custom_field_key_required'));
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO custom_fields (account_id, field_key, field_value) VALUES (?, ?, ?)');
                $stmt->execute([$id, $key, $value !== '' ? $value : null]);
                log_history($pdo, 'account', $id, 'Custom Field Added', $key, null, $value);
                flashSet('success', t('accounts.custom_field_added'));
            } catch (Throwable $e) {
                flashSet('danger', str_contains($e->getMessage(), 'UNIQUE') ? t('accounts.custom_field_duplicate') : t('accounts.custom_field_add_error'));
            }
        }
    } elseif ($action === 'remove_custom_field') {
        $fieldId = (int) ($_POST['field_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT field_key FROM custom_fields WHERE id = ? AND account_id = ?');
        $stmt->execute([$fieldId, $id]);
        $key = $stmt->fetchColumn();
        if ($key) {
            $stmt = $pdo->prepare('DELETE FROM custom_fields WHERE id = ? AND account_id = ?');
            $stmt->execute([$fieldId, $id]);
            log_history($pdo, 'account', $id, 'Custom Field Removed', (string) $key);
            flashSet('success', t('accounts.custom_field_removed'));
        }
    } elseif ($action === 'add_tag') {
        $tagName = trim((string) ($_POST['tag_name'] ?? ''));
        if ($tagName !== '') {
            attachTag($pdo, 'account', $id, $tagName);
            log_history($pdo, 'account', $id, 'Tag Added', null, null, $tagName);
            flashSet('success', t('common.tag_added'));
        }
    } elseif ($action === 'remove_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
        $stmt->execute([$tagId]);
        $tagName = $stmt->fetchColumn();
        detachTag($pdo, 'account', $id, $tagId);
        if ($tagName) {
            log_history($pdo, 'account', $id, 'Tag Removed', null, (string) $tagName, null);
        }
        flashSet('success', t('common.tag_removed'));
    } elseif ($action === 'link_phone') {
        $phoneId = (int) ($_POST['phone_id'] ?? 0);
        if ($phoneId > 0) {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO phone_account (phone_id, account_id) VALUES (?, ?)');
            $stmt->execute([$phoneId, $id]);
            $ph = $pdo->prepare('SELECT phone_number FROM phones WHERE id = ?');
            $ph->execute([$phoneId]);
            log_history($pdo, 'account', $id, 'Phone Linked', null, null, (string) $ph->fetchColumn());
            flashSet('success', t('common.phone_linked'));
        }
    } elseif ($action === 'unlink_phone') {
        $phoneId = (int) ($_POST['phone_id'] ?? 0);
        $ph = $pdo->prepare('SELECT phone_number FROM phones WHERE id = ?');
        $ph->execute([$phoneId]);
        $phoneNumber = $ph->fetchColumn();
        $stmt = $pdo->prepare('DELETE FROM phone_account WHERE phone_id = ? AND account_id = ?');
        $stmt->execute([$phoneId, $id]);
        if ($phoneNumber) {
            log_history($pdo, 'account', $id, 'Phone Unlinked', null, (string) $phoneNumber, null);
        }
        flashSet('success', t('common.phone_unlinked'));
    } elseif ($action === 'delete') {
        $label = $account['service_name'] . ' — ' . ($account['username'] ?: $account['email_address']);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute(['account', $id]);
        flashSet('success', t('accounts.deleted_success', ['name' => $label]));
        header('Location: index.php');
        exit;
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$account = fetchAccountById($pdo, $id);
$security = fetchAccountSecurity($pdo, $id);
$recovery = fetchAccountRecovery($pdo, $id);
$completeness = calcAccountCompleteness($account, $security, $recovery);

$recoveryEmail = null;
if (!empty($recovery['recovery_email_id'])) {
    $stmt = $pdo->prepare('SELECT id, email_address FROM emails WHERE id = ?');
    $stmt->execute([$recovery['recovery_email_id']]);
    $recoveryEmail = $stmt->fetch() ?: null;
}
$recoveryPhone = null;
if (!empty($recovery['recovery_phone_id'])) {
    $stmt = $pdo->prepare('SELECT id, phone_number FROM phones WHERE id = ?');
    $stmt->execute([$recovery['recovery_phone_id']]);
    $recoveryPhone = $stmt->fetch() ?: null;
}

$subscription = fetchSubscription($pdo, $id);
$payment = fetchPayment($pdo, $id);
$subType = $subscription['type'] ?? 'Unknown';
$isFreeSubscription = $subType === 'Free';

$stmt = $pdo->prepare('SELECT * FROM custom_fields WHERE account_id = ? ORDER BY field_key');
$stmt->execute([$id]);
$customFields = $stmt->fetchAll();

$tags = fetchEntityTags($pdo, 'account', $id);
$allTagNames = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare('SELECT p.id, p.phone_number, p.label FROM phones p
    JOIN phone_account pa ON pa.phone_id = p.id WHERE pa.account_id = ? ORDER BY p.phone_number');
$stmt->execute([$id]);
$linkedPhones = $stmt->fetchAll();
$linkedPhoneIds = array_column($linkedPhones, 'id');

$availablePhones = $pdo->query('SELECT id, phone_number, label FROM phones ORDER BY phone_number')->fetchAll();
$availablePhones = array_filter($availablePhones, static fn ($p) => !in_array((int) $p['id'], $linkedPhoneIds, true));

$stmt = $pdo->prepare('SELECT * FROM history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
$stmt->execute(['account', $id]);
$historyRows = $stmt->fetchAll();

$openLoginUrl = $account['login_url'] ?: $account['account_url'];
$credentialLooksLikeUrl = $security && !empty($security['credential_reference']) && preg_match('/^https?:\/\//i', (string) $security['credential_reference']);

$csrf = csrfToken();
$pageTitle = $account['service_name'] . ' — ' . ($account['username'] ?: $account['email_address']);
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">
            <a href="../services/view.php?id=<?= (int) $account['service_id'] ?>" class="text-decoration-none"><?= e($account['service_name']) ?></a>
            <span class="text-muted">—</span>
            <?= e($account['username'] ?: $account['email_address']) ?>
            <?php if ((int) $account['is_archived'] === 1): ?>
                <span class="badge bg-secondary"><?= e(t('accounts.archived_short_badge')) ?></span>
            <?php endif; ?>
        </h1>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <?= renderBadge($account['status'], ACCOUNT_STATUSES) ?>
            <?= renderBadge($account['account_type'], ACCOUNT_TYPES) ?>
            <a href="../emails/view.php?id=<?= (int) $account['email_id'] ?>" class="small"><?= e($account['email_address']) ?></a>
        </div>
    </div>
    <div class="text-end">
        <div class="text-muted small mb-1"><?= e(t('common.profile_completeness')) ?>: <?= (int) $completeness ?>%</div>
        <div class="text-muted small"><?= e(t('common.field_last_verified')) ?>: <?= dashOrValue($account['last_verified']) ?></div>
    </div>
</div>

<div class="d-flex gap-2 flex-wrap mb-4">
    <a href="edit.php?id=<?= (int) $id ?>" class="btn btn-primary btn-sm"><?= e(t('common.edit')) ?></a>
    <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="verify">
        <button type="submit" class="btn btn-outline-success btn-sm"><?= e(t('accounts.verify_button')) ?></button>
    </form>
    <?php if ($openLoginUrl): ?>
        <a href="<?= e($openLoginUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><?= e(t('accounts.open_login_page')) ?></a>
    <?php endif; ?>
    <?php if ($credentialLooksLikeUrl): ?>
        <a href="<?= e($security['credential_reference']) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><?= e(t('accounts.open_credential_reference')) ?></a>
    <?php endif; ?>
    <form method="post" class="d-inline" data-confirm="<?= (int) $account['is_archived'] === 1 ? e(t('accounts.unarchive_confirm')) : e(t('accounts.archive_confirm')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="toggle_archive">
        <button type="submit" class="btn btn-outline-warning btn-sm">
            <?= (int) $account['is_archived'] === 1 ? e(t('accounts.unarchive_button')) : e(t('accounts.archive_button')) ?>
        </button>
    </form>
    <form method="post" class="d-inline" data-confirm="<?= e(t('accounts.delete_confirm')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-outline-danger btn-sm"><?= e(t('common.delete')) ?></button>
    </form>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.back_to_list')) ?></a>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('common.basic_info')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(t('common.field_display_name')) ?></dt><dd class="col-7"><?= dashOrValue($account['display_name']) ?></dd>
                    <dt class="col-5"><?= e(t('accounts.view_external_id')) ?></dt><dd class="col-7"><?= dashOrValue($account['external_account_id']) ?></dd>
                    <dt class="col-5"><?= e(t('accounts.view_account_url')) ?></dt><dd class="col-7"><?= $account['account_url'] ? '<a href="' . e($account['account_url']) . '" target="_blank" rel="noopener">' . e($account['account_url']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-5"><?= e(t('services.view_login_url')) ?></dt><dd class="col-7"><?= $account['login_url'] ? '<a href="' . e($account['login_url']) . '" target="_blank" rel="noopener">' . e($account['login_url']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-5"><?= e(t('common.field_created_date')) ?></dt><dd class="col-7"><?= dashOrValue($account['created_date']) ?></dd>
                    <dt class="col-5"><?= e(t('accounts.field_last_login')) ?></dt><dd class="col-7"><?= dashOrValue($account['last_login']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('accounts.security_heading_short')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6"><?= e(t('field.twofa')) ?></dt><dd class="col-6"><?= renderBadge($security['twofa_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6"><?= e(t('field.twofa_method')) ?></dt><dd class="col-6"><?= dashOrValue($security['twofa_method'] ?? null) ?></dd>
                    <dt class="col-6"><?= e(t('field.passkey')) ?></dt><dd class="col-6"><?= renderBadge($security['passkey_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6"><?= e(t('emails.view_security_key')) ?></dt><dd class="col-6"><?= renderBadge($security['security_key_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6"><?= e(t('field.security_questions')) ?></dt><dd class="col-6"><?= renderBadge($security['security_questions_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6"><?= e(t('field.last_security_check')) ?></dt><dd class="col-6"><?= dashOrValue($security['last_security_check'] ?? null) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('accounts.credential_ref_section_title')) ?></div>
            <div class="card-body">
                <p class="text-muted small"><?= e(t('accounts.credential_view_disclaimer')) ?></p>
                <dl class="row mb-0">
                    <dt class="col-5"><?= e(t('accounts.view_credential_storage')) ?></dt><dd class="col-7"><?= dashOrValue($security['credential_storage'] ?? null) ?></dd>
                    <dt class="col-5"><?= e(t('accounts.view_credential_reference')) ?></dt><dd class="col-7"><?= dashOrValue($security['credential_reference'] ?? null) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('common.field_notes')) ?></div>
            <div class="card-body">
                <?= $account['notes'] ? nl2br(e($account['notes'])) : '<span class="text-muted fst-italic">' . e(t('common.no_notes')) . '</span>' ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('section.recovery')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6"><?= e(t('accounts.field_recovery_status')) ?></dt><dd class="col-6"><?= renderBadge($recovery['status'] ?? null, RECOVERY_STATUSES) ?></dd>
                    <dt class="col-6"><?= e(t('field.recovery_email')) ?></dt>
                    <dd class="col-6"><?= $recoveryEmail ? '<a href="../emails/view.php?id=' . (int) $recoveryEmail['id'] . '">' . e($recoveryEmail['email_address']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-6"><?= e(t('emails.recovery_phone_label')) ?></dt>
                    <dd class="col-6"><?= $recoveryPhone ? '<a href="../phones/view.php?id=' . (int) $recoveryPhone['id'] . '">' . e($recoveryPhone['phone_number']) . '</a>' : dashOrValue(null) ?></dd>
                    <dt class="col-6"><?= e(t('accounts.view_recovery_contact')) ?></dt><dd class="col-6"><?= dashOrValue($recovery['recovery_contact'] ?? null) ?></dd>
                    <dt class="col-6"><?= e(t('field.recovery_codes_status')) ?></dt><dd class="col-6"><?= renderBadge($recovery['recovery_codes_status'] ?? null, SECURITY_STATES) ?></dd>
                    <dt class="col-6"><?= e(t('field.recovery_codes_reference')) ?></dt><dd class="col-6"><?= dashOrValue($recovery['recovery_codes_reference'] ?? null) ?></dd>
                    <dt class="col-6"><?= e(t('accounts.view_backup_method')) ?></dt><dd class="col-6"><?= dashOrValue($recovery['backup_method'] ?? null) ?></dd>
                    <dt class="col-6"><?= e(t('field.last_recovery_verification')) ?></dt><dd class="col-6"><?= dashOrValue($recovery['last_recovery_verification'] ?? null) ?></dd>
                </dl>
                <?php if (!empty($recovery['recovery_notes'])): ?>
                    <hr>
                    <div class="small"><?= nl2br(e($recovery['recovery_notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold"><?= e(t('common.linked_phones_title')) ?></div>
            <div class="card-body">
                <?php if (!$linkedPhones): ?>
                    <p class="text-muted"><?= e(t('accounts.no_linked_phones')) ?></p>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($linkedPhones as $ph): ?>
                            <li class="d-flex justify-content-between align-items-center mb-1">
                                <a href="../phones/view.php?id=<?= (int) $ph['id'] ?>"><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' — ' . e($ph['label']) : '' ?></a>
                                <form method="post" class="d-inline" data-confirm="<?= e(t('accounts.unlink_phone_confirm')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="unlink_phone">
                                    <input type="hidden" name="phone_id" value="<?= (int) $ph['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= e(t('common.unlink')) ?></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($availablePhones): ?>
                    <form method="post" class="d-flex gap-2 mt-2">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="link_phone">
                        <select name="phone_id" class="form-select form-select-sm">
                            <?php foreach ($availablePhones as $ph): ?>
                                <option value="<?= (int) $ph['id'] ?>"><?= e($ph['phone_number']) ?><?= $ph['label'] ? ' (' . e($ph['label']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><?= e(t('common.link')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>Subscription</span>
                <?= renderBadge($subType, SUBSCRIPTION_TYPES) ?>
            </div>
            <div class="card-body">
                <?php if ($isFreeSubscription): ?>
                    <p class="mb-0 text-muted">
                        <?= e(t('accounts.free_account_note')) ?>
                        <?php if (!empty($subscription['plan'])): ?> <?= e(t('accounts.plan_inline_label')) ?>: <?= e($subscription['plan']) ?><?php endif; ?>
                    </p>
                <?php else: ?>
                    <dl class="row mb-0">
                        <dt class="col-5"><?= e(t('common.field_status')) ?></dt><dd class="col-7"><?= renderBadge($subscription['status'] ?? null, SUBSCRIPTION_STATUSES) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.plan_inline_label')) ?></dt><dd class="col-7"><?= dashOrValue($subscription['plan'] ?? null) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.field_price')) ?></dt>
                        <dd class="col-7">
                            <?php if (isset($subscription['price']) && $subscription['price'] !== null): ?>
                                <?= e((string) ($subscription['currency'] ?? '')) ?> <?= e((string) $subscription['price']) ?>
                                / <?= renderBadge($subscription['billing_cycle'] ?? null, BILLING_CYCLES) ?>
                            <?php else: ?>
                                <?= dashOrValue(null) ?>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-5"><?= e(t('accounts.field_start_date')) ?></dt><dd class="col-7"><?= dashOrValue($subscription['start_date'] ?? null) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.view_renewal_date')) ?></dt><dd class="col-7"><?= dashOrValue($subscription['renewal_date'] ?? null) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.field_auto_renewal')) ?></dt>
                        <dd class="col-7">
                            <?php
                            $subAuto = $subscription['auto_renewal'] ?? null;
                            echo yesNoBadge($subAuto === null ? null : (bool) $subAuto);
                            ?>
                        </dd>
                    </dl>
                    <?php if ($subType === 'Trial' && (!empty($subscription['start_date']) || !empty($subscription['renewal_date']))): ?>
                        <div class="alert alert-info mt-3 mb-0 py-2 small">
                            <?= e(t('accounts.trial_period_note', ['start' => $subscription['start_date'] ?? '—', 'end' => $subscription['renewal_date'] ?? '—'])) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card am-card mb-3">
            <div class="card-header bg-white fw-bold">Payment</div>
            <div class="card-body">
                <?php if ($isFreeSubscription): ?>
                    <p class="text-muted small mb-0 fst-italic"><?= e(t('accounts.free_subscription_no_payment')) ?></p>
                <?php else: ?>
                    <p class="text-muted small"><?= e(t('accounts.card_never_stored_note')) ?></p>
                    <dl class="row mb-0">
                        <dt class="col-5"><?= e(t('accounts.payment_required_question')) ?></dt>
                        <dd class="col-7"><?= yesNoBadge(((int) ($payment['payment_required'] ?? 0)) === 1) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.field_payment_method')) ?></dt><dd class="col-7"><?= dashOrValue($payment['payment_method'] ?? null) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.field_card_brand')) ?></dt><dd class="col-7"><?= dashOrValue($payment['card_brand'] ?? null) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.view_card_number')) ?></dt><dd class="col-7"><?= !empty($payment['last4']) ? '•••• ' . e($payment['last4']) : dashOrValue(null) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.view_payment_reference')) ?></dt><dd class="col-7"><?= dashOrValue($payment['payment_reference'] ?? null) ?></dd>
                        <dt class="col-5"><?= e(t('accounts.field_payment_auto_renewal')) ?></dt>
                        <dd class="col-7">
                            <?php
                            $payAuto = $payment['auto_renewal'] ?? null;
                            echo yesNoBadge($payAuto === null ? null : (bool) $payAuto);
                            ?>
                        </dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('accounts.custom_fields_title')) ?></div>
    <div class="card-body">
        <?php if (!$customFields): ?>
            <p class="text-muted"><?= e(t('accounts.no_custom_fields')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($customFields as $cf): ?>
                        <tr>
                            <td class="fw-bold text-nowrap"><?= e($cf['field_key']) ?></td>
                            <td><?= dashOrValue($cf['field_value']) ?></td>
                            <td class="text-end text-nowrap">
                                <form method="post" data-confirm="<?= e(t('accounts.custom_field_delete_confirm')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="remove_custom_field">
                                    <input type="hidden" name="field_id" value="<?= (int) $cf['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= e(t('common.delete')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-2 mt-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="add_custom_field">
            <div class="col-md-4">
                <input type="text" name="field_key" class="form-control form-control-sm" placeholder="<?= e(t('accounts.custom_field_key_placeholder')) ?>">
            </div>
            <div class="col-md-6">
                <input type="text" name="field_value" class="form-control form-control-sm" placeholder="<?= e(t('accounts.custom_field_value_placeholder')) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100"><?= e(t('common.add')) ?></button>
            </div>
        </form>
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
                            <span class="text-muted small"><?= e($h['created_at']) ?></span>
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
