<?php
declare(strict_types=1);

/**
 * audit_log already exists — migrations/platform/005_add_sessions_and_audit_log.php
 * creates it with user_id, workspace_id, action, entity_type, entity_id,
 * ip, created_at. This migration used to `CREATE TABLE IF NOT EXISTS` a
 * second, incompatible definition of the same table name (adding
 * `details` but dropping entity_type/entity_id/ip) — a guaranteed no-op
 * against a table that already exists, so `details` never actually got
 * added and logAuditEvent() (includes/audit.php) failed on every call with
 * "table audit_log has no column named details".
 *
 * One schema now: everything 005 created, plus this one guarded
 * ALTER TABLE for the column 005 didn't have.
 */
return function (PDO $pdo): void {
    $columns = $pdo->query('PRAGMA table_info(audit_log)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('details', $columns, true)) {
        $pdo->exec('ALTER TABLE audit_log ADD COLUMN details TEXT');
    }
};
