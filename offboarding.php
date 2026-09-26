<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/modules/accounts/_lib.php';

requireRole('owner', 'admin');

$pdo = db();
$platform = platformDb();
$workspaceId = currentWorkspaceId();

$membersStmt = $platform->prepare(
    'SELECT m.user_id, u.email, u.full_name
     FROM memberships m JOIN accounts_users u ON u.id = m.user_id
     WHERE m.workspace_id = ? ORDER BY u.email COLLATE NOCASE'
);
$membersStmt->execute([$workspaceId]);
$members = $membersStmt->fetchAll();
$memberIds = array_column($members, 'user_id');

$selectedUserId = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
if ($selectedUserId !== 0 && !in_array($selectedUserId, $memberIds, true)) {
    // Not a member of this workspace — never trust the id blindly, just
    // treat it the same as nothing selected.
    $selectedUserId = 0;
}

$selectedMember = null;
foreach ($members as $m) {
    if ((int) $m['user_id'] === $selectedUserId) {
        $selectedMember = $m;
        break;
    }
}

$sections = ['account' => [], 'service' => [], 'subscription' => [], 'recovery' => []];
$currencyTotals = [];
$processId = null;
$startedAt = null;
$completedAt = null;
$doneKeys = [];

if ($selectedMember !== null) {
    // --- Section 1: accounts whose owner_user_id is them ---
    $stmt = $pdo->prepare(
        'SELECT a.*, s.service_name, e.email_address, p.phone_number
         FROM accounts a
         JOIN services s ON s.id = a.service_id
         LEFT JOIN emails e ON e.id = a.email_id
         LEFT JOIN phones p ON p.id = a.identity_phone_id
         WHERE a.owner_user_id = ?
         ORDER BY s.service_name'
    );
    $stmt->execute([$selectedUserId]);
    foreach ($stmt->fetchAll() as $row) {
        $sections['account'][] = [
            'item_key' => (string) $row['id'],
            'label' => $row['service_name'] . ' — ' . accountDisplayIdentity($row),
            'status' => $row['status'],
        ];
    }

    // Their "work email"/phone as tracked in this workspace: owner_user_id
    // is the authoritative signal where it's been recorded, but that's not
    // wired into account creation yet (see CHANGELOG "known limitations"),
    // so for email specifically we also match their platform login address
    // against emails.email_address for better coverage in the meantime.
    // There is no equivalent fallback signal for phones.
    $ownEmailsStmt = $pdo->prepare('SELECT id, email_address FROM emails WHERE owner_user_id = ? OR email_address = ? COLLATE NOCASE');
    $ownEmailsStmt->execute([$selectedUserId, $selectedMember['email']]);
    $ownEmailIds = array_column($ownEmailsStmt->fetchAll(), 'id');

    $ownPhonesStmt = $pdo->prepare('SELECT id FROM phones WHERE owner_user_id = ?');
    $ownPhonesStmt->execute([$selectedUserId]);
    $ownPhoneIds = array_column($ownPhonesStmt->fetchAll(), 'id');

    // --- Section 2: services their work email is registered on ---
    if ($ownEmailIds) {
        $placeholders = implode(',', array_fill(0, count($ownEmailIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT a.id, a.username, a.status, s.service_name
             FROM accounts a JOIN services s ON s.id = a.service_id
             WHERE a.email_id IN ($placeholders)
             ORDER BY s.service_name"
        );
        $stmt->execute($ownEmailIds);
        foreach ($stmt->fetchAll() as $row) {
            $sections['service'][] = [
                'item_key' => (string) $row['id'],
                'label' => $row['service_name'] . ($row['username'] ? ' (' . $row['username'] . ')' : ''),
                'status' => $row['status'],
            ];
        }
    }

    // --- Section 3: paid subscriptions tied to accounts they own, with cost totals per currency ---
    $stmt = $pdo->prepare(
        "SELECT sub.id, sub.price, sub.currency, sub.status, s.service_name
         FROM subscriptions sub
         JOIN accounts a ON a.id = sub.account_id
         JOIN services s ON s.id = a.service_id
         WHERE a.owner_user_id = ? AND sub.type = 'Paid'
         ORDER BY s.service_name"
    );
    $stmt->execute([$selectedUserId]);
    foreach ($stmt->fetchAll() as $row) {
        $priceLabel = $row['price'] !== null ? trim(($row['currency'] ?: '') . ' ' . number_format((float) $row['price'], 2)) : '';
        $sections['subscription'][] = [
            'item_key' => (string) $row['id'],
            'label' => $row['service_name'] . ($priceLabel !== '' ? ' — ' . $priceLabel : ''),
            'status' => $row['status'],
        ];
        if ($row['price'] !== null) {
            $currency = ($row['currency'] !== null && $row['currency'] !== '') ? $row['currency'] : tOr('offboarding.currency_unspecified', 'Unspecified');
            $currencyTotals[$currency] = ($currencyTotals[$currency] ?? 0) + (float) $row['price'];
        }
    }

    // --- Section 4 (critical): their email/phone registered as recovery for OTHER records ---
    if ($ownEmailIds) {
        $placeholders = implode(',', array_fill(0, count($ownEmailIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT e2.id, e2.email_address
             FROM email_security es JOIN emails e2 ON e2.id = es.email_id
             WHERE es.recovery_email_id IN ($placeholders) AND (e2.owner_user_id IS NULL OR e2.owner_user_id != ?)"
        );
        $stmt->execute([...$ownEmailIds, $selectedUserId]);
        foreach ($stmt->fetchAll() as $row) {
            $sections['recovery'][] = [
                'item_key' => 'email_recovery_email:' . $row['id'],
                'label' => t('offboarding.recovery_email_backs_email', ['email' => $row['email_address']]),
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT a.id, s.service_name
             FROM account_recovery ar JOIN accounts a ON a.id = ar.account_id JOIN services s ON s.id = a.service_id
             WHERE ar.recovery_email_id IN ($placeholders) AND (a.owner_user_id IS NULL OR a.owner_user_id != ?)"
        );
        $stmt->execute([...$ownEmailIds, $selectedUserId]);
        foreach ($stmt->fetchAll() as $row) {
            $sections['recovery'][] = [
                'item_key' => 'account_recovery_email:' . $row['id'],
                'label' => t('offboarding.recovery_email_backs_account', ['service' => $row['service_name']]),
            ];
        }
    }
    if ($ownPhoneIds) {
        $placeholders = implode(',', array_fill(0, count($ownPhoneIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT e2.id, e2.email_address
             FROM email_security es JOIN emails e2 ON e2.id = es.email_id
             WHERE es.recovery_phone_id IN ($placeholders) AND (e2.owner_user_id IS NULL OR e2.owner_user_id != ?)"
        );
        $stmt->execute([...$ownPhoneIds, $selectedUserId]);
        foreach ($stmt->fetchAll() as $row) {
            $sections['recovery'][] = [
                'item_key' => 'email_recovery_phone:' . $row['id'],
                'label' => t('offboarding.recovery_phone_backs_email', ['email' => $row['email_address']]),
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT a.id, s.service_name
             FROM account_recovery ar JOIN accounts a ON a.id = ar.account_id JOIN services s ON s.id = a.service_id
             WHERE ar.recovery_phone_id IN ($placeholders) AND (a.owner_user_id IS NULL OR a.owner_user_id != ?)"
        );
        $stmt->execute([...$ownPhoneIds, $selectedUserId]);
        foreach ($stmt->fetchAll() as $row) {
            $sections['recovery'][] = [
                'item_key' => 'account_recovery_phone:' . $row['id'],
                'label' => t('offboarding.recovery_phone_backs_account', ['service' => $row['service_name']]),
            ];
        }
    }

    // --- the process itself (start/end dates) ---
    $procStmt = $pdo->prepare('SELECT * FROM offboarding_processes WHERE user_id = ? ORDER BY started_at DESC LIMIT 1');
    $procStmt->execute([$selectedUserId]);
    $process = $procStmt->fetch();
    if (!$process) {
        $pdo->prepare('INSERT INTO offboarding_processes (user_id, started_by) VALUES (?, ?)')
            ->execute([$selectedUserId, currentUserId()]);
        $processId = (int) $pdo->lastInsertId();
        $startedAt = date('Y-m-d H:i:s');
    } else {
        $processId = (int) $process['id'];
        $startedAt = $process['started_at'];
        $completedAt = $process['completed_at'];
    }

    $doneStmt = $pdo->prepare('SELECT section, item_key FROM offboarding_tasks WHERE process_id = ? AND done_at IS NOT NULL');
    $doneStmt->execute([$processId]);
    foreach ($doneStmt->fetchAll() as $row) {
        $doneKeys[$row['section'] . '::' . $row['item_key']] = true;
    }
}

$totalItems = 0;
$doneItems = 0;
foreach ($sections as $sectionName => $items) {
    foreach ($items as $item) {
        $totalItems++;
        if (isset($doneKeys[$sectionName . '::' . $item['item_key']])) {
            $doneItems++;
        }
    }
}
$progressPercent = $totalItems > 0 ? (int) round(($doneItems / $totalItems) * 100) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedMember !== null) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: offboarding.php?user_id=' . $selectedUserId);
        exit;
    }

    if ((string) ($_POST['action'] ?? '') === 'toggle_task') {
        $section = (string) ($_POST['section'] ?? '');
        $itemKey = (string) ($_POST['item_key'] ?? '');
        $markDone = ($_POST['mark'] ?? '') === '1';

        // Only ever act on an item that's actually part of the checklist
        // just computed above — never trust the posted section/item_key
        // pair blindly.
        $isValidItem = isset($sections[$section]) && in_array($itemKey, array_column($sections[$section], 'item_key'), true);

        if ($isValidItem) {
            $existingStmt = $pdo->prepare('SELECT id FROM offboarding_tasks WHERE process_id = ? AND section = ? AND item_key = ?');
            $existingStmt->execute([$processId, $section, $itemKey]);
            $taskId = $existingStmt->fetchColumn();

            if ($markDone) {
                if ($taskId) {
                    $pdo->prepare("UPDATE offboarding_tasks SET done_at = datetime('now'), done_by = ? WHERE id = ?")
                        ->execute([currentUserId(), $taskId]);
                } else {
                    $pdo->prepare("INSERT INTO offboarding_tasks (process_id, section, item_key, done_at, done_by) VALUES (?, ?, ?, datetime('now'), ?)")
                        ->execute([$processId, $section, $itemKey, currentUserId()]);
                }
                $doneKeys[$section . '::' . $itemKey] = true;
            } elseif ($taskId) {
                $pdo->prepare('UPDATE offboarding_tasks SET done_at = NULL, done_by = NULL WHERE id = ?')->execute([$taskId]);
                unset($doneKeys[$section . '::' . $itemKey]);
            }

            $newDoneItems = 0;
            foreach ($sections as $sectionName => $items) {
                foreach ($items as $item) {
                    if (isset($doneKeys[$sectionName . '::' . $item['item_key']])) {
                        $newDoneItems++;
                    }
                }
            }
            if ($totalItems > 0 && $newDoneItems === $totalItems && $completedAt === null) {
                $pdo->prepare("UPDATE offboarding_processes SET completed_at = datetime('now') WHERE id = ?")->execute([$processId]);
            } elseif ($newDoneItems < $totalItems && $completedAt !== null) {
                $pdo->prepare('UPDATE offboarding_processes SET completed_at = NULL WHERE id = ?')->execute([$processId]);
            }
        }
    }

    header('Location: offboarding.php?user_id=' . $selectedUserId);
    exit;
}

$csrf = csrfToken();
$sectionMeta = [
    'account' => ['title' => t('offboarding.section_accounts_title'), 'empty' => t('offboarding.section_accounts_empty'), 'critical' => false, 'badgeMap' => ACCOUNT_STATUSES],
    'service' => ['title' => t('offboarding.section_services_title'), 'empty' => t('offboarding.section_services_empty'), 'critical' => false, 'badgeMap' => ACCOUNT_STATUSES],
    'subscription' => ['title' => t('offboarding.section_subscriptions_title'), 'empty' => t('offboarding.section_subscriptions_empty'), 'critical' => false, 'badgeMap' => SUBSCRIPTION_STATUSES],
    'recovery' => ['title' => t('offboarding.section_recovery_title'), 'empty' => t('offboarding.section_recovery_empty'), 'critical' => true, 'badgeMap' => []],
];
$pageTitle = t('offboarding.title');
require __DIR__ . '/includes/header.php';
?>
<style>
@media print {
    .am-sidebar,
    .am-topbar,
    .offcanvas,
    .no-print {
        display: none !important;
    }
    .am-content {
        margin: 0 !important;
        padding: 0 !important;
    }
    .card {
        border: 1px solid #999 !important;
        break-inside: avoid;
    }
    html[dir="rtl"] body {
        direction: rtl;
        text-align: right;
    }
    html[dir="ltr"] body {
        direction: ltr;
        text-align: left;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 no-print">
    <h1 class="h4 mb-0"><?= e(t('offboarding.title')) ?></h1>
    <?php if ($selectedMember !== null): ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><?= e(tOr('offboarding.print_view', 'Print view')) ?></button>
    <?php endif; ?>
</div>

<form method="get" class="row g-2 mb-4 no-print">
    <div class="col-md-6">
        <select name="user_id" class="form-select" onchange="this.form.submit()">
            <option value=""><?= e(t('offboarding.select_member_placeholder')) ?></option>
            <?php foreach ($members as $m): ?>
                <option value="<?= (int) $m['user_id'] ?>" <?= $selectedUserId === (int) $m['user_id'] ? 'selected' : '' ?>>
                    <?= e($m['full_name'] ?: $m['email']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if ($selectedMember === null): ?>
    <p class="text-muted"><?= e(t('offboarding.no_member_selected')) ?></p>
<?php else: ?>
    <div class="card am-card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div>
                    <strong><?= e($selectedMember['full_name'] ?: $selectedMember['email']) ?></strong>
                    <span class="text-muted small"> — <?= e($selectedMember['email']) ?></span>
                </div>
                <?php if ($completedAt): ?>
                    <span class="badge bg-success"><?= e(t('offboarding.status_completed')) ?></span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark"><?= e(t('offboarding.status_in_progress')) ?></span>
                <?php endif; ?>
            </div>
            <div class="progress mb-2" style="height:1.25rem;">
                <div class="progress-bar<?= $completedAt ? ' bg-success' : '' ?>" role="progressbar" style="width:<?= (int) $progressPercent ?>%;" aria-valuenow="<?= (int) $progressPercent ?>" aria-valuemin="0" aria-valuemax="100"><?= (int) $progressPercent ?>%</div>
            </div>
            <p class="text-muted small mb-0">
                <?= e(t('offboarding.started_label')) ?>: <?= e(formatDate((string) $startedAt, true)) ?>
                <?php if ($completedAt): ?>
                    &nbsp;·&nbsp;<?= e(t('offboarding.completed_label')) ?>: <?= e(formatDate($completedAt, true)) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php foreach ($sectionMeta as $sectionKey => $meta): ?>
        <div class="card am-card mb-3<?= $meta['critical'] ? ' border-danger' : '' ?>">
            <div class="card-header bg-white fw-bold d-flex align-items-center gap-2">
                <span><?= e($meta['title']) ?></span>
                <?php if ($meta['critical']): ?>
                    <span class="badge bg-danger"><?= e(t('offboarding.critical_badge')) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($sectionKey === 'recovery'): ?>
                    <p class="text-muted small"><?= e(t('offboarding.section_recovery_intro')) ?></p>
                <?php endif; ?>
                <?php if ($sectionKey === 'subscription' && $currencyTotals): ?>
                    <p class="mb-3">
                        <?php foreach ($currencyTotals as $currency => $total): ?>
                            <span class="badge bg-light text-dark border me-1"><?= e($currency) ?> <?= number_format($total, 2) ?></span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>

                <?php if (!$sections[$sectionKey]): ?>
                    <p class="text-muted mb-0"><?= e($meta['empty']) ?></p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($sections[$sectionKey] as $item): ?>
                            <?php $isDone = isset($doneKeys[$sectionKey . '::' . $item['item_key']]); ?>
                            <li class="list-group-item">
                                <form method="post" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="toggle_task">
                                    <input type="hidden" name="user_id" value="<?= (int) $selectedUserId ?>">
                                    <input type="hidden" name="section" value="<?= e($sectionKey) ?>">
                                    <input type="hidden" name="item_key" value="<?= e($item['item_key']) ?>">
                                    <input type="hidden" name="mark" value="<?= $isDone ? '0' : '1' ?>">
                                    <input type="checkbox" class="form-check-input flex-shrink-0" <?= $isDone ? 'checked' : '' ?> onchange="this.form.requestSubmit()">
                                    <span class="flex-grow-1<?= $isDone ? ' text-muted text-decoration-line-through' : '' ?>"><?= e($item['label']) ?></span>
                                    <?php if (!empty($item['status'])): ?>
                                        <?= renderBadge($item['status'], $meta['badgeMap']) ?>
                                    <?php endif; ?>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
