<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/plans.php';

requireRole('owner', 'admin');

$platform = platformDb();
$workspaceId = currentWorkspaceId();
$actorRole = currentRole();
$actorUserId = currentUserId();

$workspaceStmt = $platform->prepare('SELECT name FROM workspaces WHERE id = ? LIMIT 1');
$workspaceStmt->execute([$workspaceId]);
$workspaceName = (string) $workspaceStmt->fetchColumn();

/**
 * Roles the current actor is allowed to assign — an admin can staff a
 * workspace but not grant ownership; only an owner can create another owner.
 */
function assignableRoles(string $actorRole): array
{
    return $actorRole === 'owner' ? ['owner', 'admin', 'member', 'viewer'] : ['admin', 'member', 'viewer'];
}

$assignableRoles = assignableRoles($actorRole);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flashSet('danger', t('msg.invalid_request'));
        header('Location: members.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'invite') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = (string) ($_POST['role'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashSet('danger', t('members.invite_email_invalid'));
        } elseif (!in_array($role, $assignableRoles, true)) {
            flashSet('danger', t('members.invite_role_invalid'));
        } elseif (!checkPlanLimit('members', $workspaceId)) {
            flashSet('danger', t('plans.limit_members_reached'));
        } else {
            $memberCheck = $platform->prepare(
                'SELECT 1 FROM memberships m JOIN accounts_users u ON u.id = m.user_id
                 WHERE m.workspace_id = ? AND u.email = ? COLLATE NOCASE LIMIT 1'
            );
            $memberCheck->execute([$workspaceId, $email]);

            if ($memberCheck->fetchColumn()) {
                flashSet('danger', t('members.invite_already_member', ['email' => $email]));
            } else {
                // A fresh invite replaces any earlier pending one for the same
                // email — this doubles as "resend" with a new token/expiry.
                $platform->prepare('DELETE FROM invitations WHERE workspace_id = ? AND email = ? COLLATE NOCASE AND accepted_at IS NULL')
                    ->execute([$workspaceId, $email]);

                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                $platform->prepare(
                    'INSERT INTO invitations (workspace_id, email, role, token, expires_at, invited_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$workspaceId, $email, $role, $token, $expiresAt, $actorUserId]);

                flashSet('success', t('members.invite_sent_success', ['email' => $email]));
            }
        }
    } elseif ($action === 'change_role') {
        $membershipId = (int) ($_POST['membership_id'] ?? 0);
        $newRole = (string) ($_POST['role'] ?? '');

        $stmt = $platform->prepare('SELECT * FROM memberships WHERE id = ? AND workspace_id = ? LIMIT 1');
        $stmt->execute([$membershipId, $workspaceId]);
        $membership = $stmt->fetch();

        if (!$membership || !in_array($newRole, $assignableRoles, true)) {
            flashSet('danger', t('members.invite_role_invalid'));
        } elseif ((int) $membership['user_id'] === $actorUserId) {
            flashSet('danger', t('members.cannot_change_self'));
        } elseif ($membership['role'] === 'owner' && $actorRole !== 'owner') {
            // Admins can't touch an owner's role at all, not even to another non-owner role.
            flashSet('danger', t('members.invite_role_invalid'));
        } elseif ($membership['role'] === 'owner' && $newRole !== 'owner') {
            $ownerCountStmt = $platform->prepare("SELECT COUNT(*) FROM memberships WHERE workspace_id = ? AND role = 'owner'");
            $ownerCountStmt->execute([$workspaceId]);
            if ((int) $ownerCountStmt->fetchColumn() <= 1) {
                flashSet('danger', t('members.last_owner_error'));
            } else {
                $platform->prepare('UPDATE memberships SET role = ? WHERE id = ?')->execute([$newRole, $membershipId]);
                flashSet('success', t('members.role_change_success'));
            }
        } else {
            $platform->prepare('UPDATE memberships SET role = ? WHERE id = ?')->execute([$newRole, $membershipId]);
            flashSet('success', t('members.role_change_success'));
        }
    } elseif ($action === 'revoke_invite') {
        $invitationId = (int) ($_POST['invitation_id'] ?? 0);
        $platform->prepare('DELETE FROM invitations WHERE id = ? AND workspace_id = ? AND accepted_at IS NULL')
            ->execute([$invitationId, $workspaceId]);
        flashSet('success', t('members.revoke_success'));
    }

    header('Location: members.php');
    exit;
}

$membersStmt = $platform->prepare(
    'SELECT m.id AS membership_id, m.role, m.user_id, u.email, u.full_name
     FROM memberships m JOIN accounts_users u ON u.id = m.user_id
     WHERE m.workspace_id = ?
     ORDER BY u.email COLLATE NOCASE'
);
$membersStmt->execute([$workspaceId]);
$members = $membersStmt->fetchAll();

$invitesStmt = $platform->prepare(
    'SELECT * FROM invitations WHERE workspace_id = ? AND accepted_at IS NULL ORDER BY id DESC'
);
$invitesStmt->execute([$workspaceId]);
$pendingInvites = $invitesStmt->fetchAll();

// Absolute, not appUrl()'s root-relative path — this is meant to be copied
// out of the browser (chat, email) where a relative path is meaningless.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$absoluteBase = $scheme . '://' . $host;

$memberLimitReached = !checkPlanLimit('members', $workspaceId);

$csrf = csrfToken();
$pageTitle = t('members.title');
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1"><?= e(t('members.title')) ?></h1>
        <p class="text-muted mb-0"><?= e(t('members.subtitle', ['workspace' => $workspaceName])) ?></p>
    </div>
</div>

<div class="card am-card mb-4">
    <div class="card-header bg-white fw-bold"><?= e(t('members.current_members_title')) ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= e(t('members.th_email')) ?></th>
                    <th><?= e(t('common.field_display_name')) ?></th>
                    <th><?= e(t('members.th_role')) ?></th>
                    <th class="text-end"><?= e(t('members.th_actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <?php
                $isSelf = (int) $m['user_id'] === $actorUserId;
                $canManage = !$isSelf && ($actorRole === 'owner' || $m['role'] !== 'owner');
                ?>
                <tr>
                    <td><?= e($m['email']) ?><?= $isSelf ? ' <span class="badge bg-secondary">' . e(tOr('common.you', 'You')) . '</span>' : '' ?></td>
                    <td><?= dashOrValue($m['full_name']) ?></td>
                    <td>
                        <?php if ($canManage): ?>
                            <form method="post" class="d-flex gap-1">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="membership_id" value="<?= (int) $m['membership_id'] ?>">
                                <select name="role" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                                    <?php foreach ($assignableRoles as $r): ?>
                                        <option value="<?= e($r) ?>" <?= $m['role'] === $r ? 'selected' : '' ?>><?= e(tOr('role.' . $r, ucfirst($r))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php else: ?>
                            <?= e(tOr('role.' . $m['role'], ucfirst($m['role']))) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($canManage): ?>
                            <a href="remove-member.php?membership_id=<?= (int) $m['membership_id'] ?>" class="btn btn-sm btn-outline-danger"><?= e(t('members.remove_button')) ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card am-card mb-4">
    <div class="card-header bg-white fw-bold"><?= e(t('members.invite_title')) ?></div>
    <div class="card-body">
        <?php if ($memberLimitReached): ?>
            <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><?= e(t('plans.limit_members_reached')) ?></span>
                <a href="<?= e(appUrl('plans.php')) ?>" class="btn btn-sm btn-primary"><?= e(t('plans.upgrade_button')) ?></a>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="invite">
            <div class="col-md-6">
                <label class="form-label"><?= e(t('members.field_email')) ?></label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= e(t('members.field_role')) ?></label>
                <select name="role" class="form-select">
                    <?php foreach ($assignableRoles as $r): ?>
                        <option value="<?= e($r) ?>" <?= $r === 'member' ? 'selected' : '' ?>><?= e(tOr('role.' . $r, ucfirst($r))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><?= e(t('members.invite_button')) ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card am-card mb-3">
    <div class="card-header bg-white fw-bold"><?= e(t('members.pending_invitations_title')) ?></div>
    <div class="card-body">
        <?php if (!$pendingInvites): ?>
            <p class="text-muted mb-0"><?= e(t('members.no_pending_invitations')) ?></p>
        <?php else: ?>
            <?php foreach ($pendingInvites as $inv): ?>
                <?php
                $expired = strtotime($inv['expires_at']) <= time();
                $link = $absoluteBase . appUrl('accept-invite.php?token=' . $inv['token']);
                ?>
                <div class="mb-3 pb-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
                        <div>
                            <strong><?= e($inv['email']) ?></strong>
                            <span class="badge bg-light text-dark border"><?= e(tOr('role.' . $inv['role'], ucfirst($inv['role']))) ?></span>
                            <?php if ($expired): ?>
                                <span class="badge bg-secondary"><?= e(t('members.invite_expired_badge')) ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="post" data-confirm="<?= e(t('members.revoke_confirm')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="revoke_invite">
                            <input type="hidden" name="invitation_id" value="<?= (int) $inv['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><?= e(t('members.revoke_button')) ?></button>
                        </form>
                    </div>
                    <label class="form-label small text-muted mb-1"><?= e(t('members.invite_link_label')) ?></label>
                    <input type="text" class="form-control form-control-sm" readonly value="<?= e($link) ?>" onclick="this.select()">
                    <p class="text-muted small mb-0 mt-1"><?= e(t('members.invite_expires_label')) ?>: <?= e(formatDate($inv['expires_at'], true)) ?></p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
