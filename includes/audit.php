<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';

/**
 * A durable, append-only trail of sensitive account/workspace operations —
 * deliberately separate from the per-workspace log_history() used by
 * modules/accounts/*.php, which lives inside the workspace's own SQLite
 * file and would be destroyed right along with it by a workspace deletion.
 *
 * The single merged replacement for what used to be two separate,
 * differently-shaped functions: this file's own logAuditEvent()
 * (explicit workspace/user, a free-form $details array, no entity/IP) and
 * includes/helpers.php's auditLog() (session-derived user/workspace,
 * entity_type/entity_id, IP capture, no $details). $userId/$workspaceId
 * default to the current session but can be overridden explicitly — every
 * existing call site needs that override at least once (settings.php logs
 * a user/workspace that may already be mid-deletion, sometimes gone from
 * the session already by the time this runs).
 *
 * Returns without writing anything if no user can be attributed at all
 * (no explicit $userId and no logged-in session) — matching auditLog()'s
 * old behavior: an audit row nobody can be attributed to isn't useful.
 */
function logAuditEvent(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $details = [],
    ?int $userId = null,
    ?int $workspaceId = null
): void {
    $userId ??= currentUserId();
    if ($userId === null) {
        return;
    }
    $workspaceId ??= currentWorkspaceId();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    platformDb()->prepare(
        'INSERT INTO audit_log (user_id, workspace_id, action, entity_type, entity_id, ip, details) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $workspaceId, $action, $entityType, $entityId, $ip, $details ? json_encode($details) : null]);
}

/**
 * @return string[] workspace names where $userId is currently the only
 *     owner — non-empty means account deletion must be blocked until each
 *     one is transferred or deleted first.
 */
function findSoleOwnershipBlockers(PDO $platform, int $userId): array
{
    $stmt = $platform->prepare(
        "SELECT w.name FROM memberships m
         JOIN workspaces w ON w.id = m.workspace_id
         WHERE m.user_id = ? AND m.role = 'owner'
           AND (SELECT COUNT(*) FROM memberships m2 WHERE m2.workspace_id = m.workspace_id AND m2.role = 'owner') <= 1
         ORDER BY w.name COLLATE NOCASE"
    );
    $stmt->execute([$userId]);
    return array_column($stmt->fetchAll(), 'name');
}
