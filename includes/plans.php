<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';

/**
 * @return array{code:string,name:string,max_members:?int,max_accounts:?int,monthly_price:?float,yearly_price:?float}|null
 */
function getWorkspacePlan(int $workspaceId): ?array
{
    $stmt = platformDb()->prepare(
        'SELECT p.code, p.name, p.max_members, p.max_accounts, p.monthly_price, p.yearly_price
         FROM workspaces w JOIN plans p ON p.code = w.plan_code
         WHERE w.id = ? LIMIT 1'
    );
    $stmt->execute([$workspaceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * True when the workspace still has room for one more of $resource
 * ('members' or 'accounts') under its plan — false means the cap has
 * already been hit and the caller must refuse the action outright, not
 * just warn about it (spec: "The limit must be enforced in code, not just
 * stated on a pricing page"). A NULL limit on the plan means unlimited.
 * Fails open (returns true) when the workspace or its plan can't be
 * resolved — that is a data problem, not something to block a user's
 * action over.
 *
 * $workspacePdo lets a caller that already holds an open connection to
 * this workspace's own database (e.g. modules/accounts/add.php's $pdo)
 * pass it straight through, instead of a second connection being opened
 * to the same SQLite file just for this count.
 */
function checkPlanLimit(string $resource, ?int $workspaceId = null, ?PDO $workspacePdo = null): bool
{
    $workspaceId ??= currentWorkspaceId();
    if ($workspaceId === null) {
        return true;
    }

    $plan = getWorkspacePlan($workspaceId);
    if ($plan === null) {
        return true;
    }

    if ($resource === 'members') {
        if ($plan['max_members'] === null) {
            return true;
        }
        $platform = platformDb();
        $memberStmt = $platform->prepare('SELECT COUNT(*) FROM memberships WHERE workspace_id = ?');
        $memberStmt->execute([$workspaceId]);
        // Pending invitations count toward the cap too — otherwise it could
        // be bypassed by sending invites that only "count" once accepted.
        $inviteStmt = $platform->prepare('SELECT COUNT(*) FROM invitations WHERE workspace_id = ? AND accepted_at IS NULL');
        $inviteStmt->execute([$workspaceId]);
        $current = (int) $memberStmt->fetchColumn() + (int) $inviteStmt->fetchColumn();
        return $current < (int) $plan['max_members'];
    }

    if ($resource === 'accounts') {
        if ($plan['max_accounts'] === null) {
            return true;
        }
        $pdo = $workspacePdo;
        if ($pdo === null) {
            $path = DATA_DIR . '/workspaces/' . $workspaceId . '.sqlite';
            if (!file_exists($path)) {
                return true;
            }
            $pdo = new PDO('sqlite:' . $path);
        }
        $current = (int) $pdo->query('SELECT COUNT(*) FROM accounts WHERE is_archived = 0')->fetchColumn();
        return $current < (int) $plan['max_accounts'];
    }

    throw new InvalidArgumentException("checkPlanLimit: unknown resource \"{$resource}\".");
}
