<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';

/**
 * A durable, append-only trail of sensitive account/workspace operations —
 * deliberately separate from the per-workspace log_history() used by
 * modules/accounts/*.php, which lives inside the workspace's own SQLite
 * file and would be destroyed right along with it by a workspace deletion.
 */
function logAuditEvent(?int $workspaceId, ?int $userId, string $action, array $details = []): void
{
    platformDb()->prepare('INSERT INTO audit_log (workspace_id, user_id, action, details) VALUES (?, ?, ?, ?)')
        ->execute([$workspaceId, $userId, $action, $details ? json_encode($details) : null]);
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
