<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireRole('owner', 'admin');

$platform = platformDb();
$workspaceId = currentWorkspaceId();
$actorRole = currentRole();
$actorUserId = currentUserId();

$membershipId = (int) ($_GET['membership_id'] ?? $_POST['membership_id'] ?? 0);

$stmt = $platform->prepare(
    'SELECT m.id AS membership_id, m.role, m.user_id, u.email, u.full_name
     FROM memberships m JOIN accounts_users u ON u.id = m.user_id
     WHERE m.id = ? AND m.workspace_id = ? LIMIT 1'
);
$stmt->execute([$membershipId, $workspaceId]);
$departing = $stmt->fetch();

// Same guards members.php's own list applies to decide whether to show a
// "Remove" link at all — re-checked here since this page is reachable
// directly by URL, not just via that link.
if (!$departing) {
    flashSet('danger', t('members.invite_role_invalid'));
    header('Location: members.php');
    exit;
}
if ((int) $departing['user_id'] === $actorUserId) {
    flashSet('danger', t('members.cannot_remove_self'));
    header('Location: members.php');
    exit;
}
if ($departing['role'] === 'owner' && $actorRole !== 'owner') {
    flashSet('danger', t('members.invite_role_invalid'));
    header('Location: members.php');
    exit;
}
if ($departing['role'] === 'owner') {
    $ownerCountStmt = $platform->prepare("SELECT COUNT(*) FROM memberships WHERE workspace_id = ? AND role = 'owner'");
    $ownerCountStmt->execute([$workspaceId]);
    if ((int) $ownerCountStmt->fetchColumn() <= 1) {
        flashSet('danger', t('members.last_owner_error'));
        header('Location: members.php');
        exit;
    }
}

$departingUserId = (int) $departing['user_id'];
$pdo = db();

// Counted regardless of visibility ('private' and 'workspace' alike) — this
// is about who created the record, for disposing of it, not about who may
// currently see it.
$recordCounts = [];
$totalRecords = 0;
foreach (VISIBILITY_SCOPED_TABLES as $table) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE owner_user_id = ?");
    $countStmt->execute([$departingUserId]);
    $count = (int) $countStmt->fetchColumn();
    $recordCounts[$table] = $count;
    $totalRecords += $count;
}

$otherMembersStmt = $platform->prepare(
    'SELECT m.user_id, u.email FROM memberships m JOIN accounts_users u ON u.id = m.user_id
     WHERE m.workspace_id = ? AND m.user_id != ? ORDER BY u.email COLLATE NOCASE'
);
$otherMembersStmt->execute([$workspaceId, $departingUserId]);
$otherMembers = $otherMembersStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = t('msg.invalid_request');
    }

    $disposition = (string) ($_POST['disposition'] ?? '');
    if (!$errors && !in_array($disposition, ['transfer', 'archive', 'delete'], true)) {
        $errors[] = t('members.remove_disposition_required');
    }

    $targetUserId = 0;
    if (!$errors && $disposition === 'transfer') {
        $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
        if (!in_array($targetUserId, array_column($otherMembers, 'user_id'), true)) {
            $errors[] = t('members.remove_transfer_target_required');
        }
    }

    if (!$errors && $disposition === 'delete') {
        $confirmText = trim((string) ($_POST['confirm_identifier'] ?? ''));
        if (strcasecmp($confirmText, (string) $departing['email']) !== 0) {
            $errors[] = t('members.remove_delete_confirm_mismatch');
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($disposition === 'transfer') {
                foreach (VISIBILITY_SCOPED_TABLES as $table) {
                    $pdo->prepare("UPDATE {$table} SET owner_user_id = ? WHERE owner_user_id = ?")
                        ->execute([$targetUserId, $departingUserId]);
                }
            } elseif ($disposition === 'archive') {
                foreach (VISIBILITY_SCOPED_TABLES as $table) {
                    $pdo->prepare("UPDATE {$table} SET is_archived = 1 WHERE owner_user_id = ?")
                        ->execute([$departingUserId]);
                }
            } else {
                // Accounts first: it's what the other three tables are referenced
                // BY (email_id/service_id/identity_phone_id, all ON DELETE
                // RESTRICT), so deleting owned accounts first avoids a spurious
                // RESTRICT failure when an owned account was the only thing still
                // pointing at an owned email/service/phone. account_security/
                // account_recovery/phone_account/subscriptions/payments/
                // custom_fields all cascade from accounts automatically — only
                // taggables (polymorphic, no real FK) needs an explicit cleanup,
                // same as every other single-record delete in this app.
                $accountIdsStmt = $pdo->prepare('SELECT id FROM accounts WHERE owner_user_id = ?');
                $accountIdsStmt->execute([$departingUserId]);
                foreach ($accountIdsStmt->fetchAll(PDO::FETCH_COLUMN) as $accountId) {
                    $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$accountId]);
                    $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute(['account', $accountId]);
                }

                $entityTypeByTable = ['emails' => 'email', 'services' => 'service', 'phones' => 'phone'];
                foreach ($entityTypeByTable as $table => $entityType) {
                    $idsStmt = $pdo->prepare("SELECT id FROM {$table} WHERE owner_user_id = ?");
                    $idsStmt->execute([$departingUserId]);
                    foreach ($idsStmt->fetchAll(PDO::FETCH_COLUMN) as $recordId) {
                        $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$recordId]);
                        $pdo->prepare('DELETE FROM taggables WHERE entity_type = ? AND entity_id = ?')->execute([$entityType, $recordId]);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = str_contains($e->getMessage(), 'FOREIGN KEY') || str_contains($e->getMessage(), 'RESTRICT')
                ? t('members.remove_delete_blocked')
                : t('msg.save_error') . $e->getMessage();
        }

        if (!$errors) {
            // The workspace-database transaction above is already committed —
            // this membership row is the only thing left, in the separate
            // platform database. If this one statement somehow fails, the safe
            // recovery is just retrying the removal: re-running any of the three
            // dispositions again is a harmless no-op, since there's nothing left
            // for it to act on.
            $platform->prepare('DELETE FROM memberships WHERE id = ?')->execute([$membershipId]);
            flashSet('success', t('members.remove_success'));
            header('Location: members.php');
            exit;
        }
    }
}

$csrf = csrfToken();
$pageTitle = t('members.remove_title', ['email' => $departing['email']]);
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?= e(t('members.remove_title', ['email' => $departing['email']])) ?></h1>
    <a href="members.php" class="btn btn-outline-secondary btn-sm"><?= e(t('common.cancel')) ?></a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('members.remove_record_summary', ['count' => $totalRecords])) ?></div>
    <?php if ($totalRecords > 0): ?>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-6"><?= e(t('emails.title')) ?></dt><dd class="col-6"><?= (int) $recordCounts['emails'] ?></dd>
                <dt class="col-6"><?= e(t('services.title')) ?></dt><dd class="col-6"><?= (int) $recordCounts['services'] ?></dd>
                <dt class="col-6"><?= e(t('accounts.title')) ?></dt><dd class="col-6"><?= (int) $recordCounts['accounts'] ?></dd>
                <dt class="col-6"><?= e(t('phones.title')) ?></dt><dd class="col-6"><?= (int) $recordCounts['phones'] ?></dd>
            </dl>
        </div>
    <?php endif; ?>
</div>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="membership_id" value="<?= (int) $membershipId ?>">

    <div class="card am-card mb-3">
        <div class="card-header bg-white fw-bold"><?= e(t('members.remove_disposition_legend')) ?></div>
        <div class="card-body">
            <div class="form-check mb-2">
                <input type="radio" name="disposition" value="transfer" id="disposition_transfer" class="form-check-input">
                <label for="disposition_transfer" class="form-check-label"><?= e(t('members.remove_option_transfer')) ?></label>
                <select name="target_user_id" id="target_user_id" class="form-select form-select-sm mt-1" style="max-width:22rem;" disabled>
                    <option value=""><?= e(t('common.select_placeholder')) ?></option>
                    <?php foreach ($otherMembers as $om): ?>
                        <option value="<?= (int) $om['user_id'] ?>"><?= e($om['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-check mb-2">
                <input type="radio" name="disposition" value="archive" id="disposition_archive" class="form-check-input">
                <label for="disposition_archive" class="form-check-label"><?= e(t('members.remove_option_archive')) ?></label>
            </div>

            <div class="form-check">
                <input type="radio" name="disposition" value="delete" id="disposition_delete" class="form-check-input">
                <label for="disposition_delete" class="form-check-label text-danger"><?= e(t('members.remove_option_delete')) ?></label>
                <input type="text" name="confirm_identifier" id="confirm_identifier" class="form-control form-control-sm mt-1" style="max-width:22rem;" placeholder="<?= e(t('members.remove_delete_confirm_label', ['email' => $departing['email']])) ?>" disabled autocomplete="off">
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-danger"><?= e(t('members.remove_submit_button')) ?></button>
    <a href="members.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
</form>

<script>
(function () {
    var radios = document.querySelectorAll('input[name="disposition"]');
    var targetSelect = document.getElementById('target_user_id');
    var confirmInput = document.getElementById('confirm_identifier');
    function update() {
        var chosen = document.querySelector('input[name="disposition"]:checked');
        var value = chosen ? chosen.value : '';
        targetSelect.disabled = value !== 'transfer';
        confirmInput.disabled = value !== 'delete';
    }
    radios.forEach(function (r) { r.addEventListener('change', update); });
    update();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
