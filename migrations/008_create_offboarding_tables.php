<?php
declare(strict_types=1);

/**
 * Offboarding checklist support (docs/ROADMAP-SAAS.md Phase 14). Two tables:
 *
 * - offboarding_processes: one row per offboarding run for a departing
 *   member — start/end dates for offboarding.php's progress display.
 *   user_id/started_by reference accounts_users.id in the central platform
 *   database, the same cross-database-reference pattern already used by
 *   owner_user_id on emails/services/accounts/phones — no (and can't be a)
 *   foreign key tying them together.
 * - offboarding_tasks: one row per checked-off checklist item, scoped to a
 *   process via a real (same-database) foreign key. item_key is a stable,
 *   caller-defined identifier (e.g. an account id) — the checklist itself
 *   is always recomputed live from current data; this table only tracks
 *   which of those live items have been marked done.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS offboarding_processes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        started_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        started_by INTEGER,
        completed_at TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_offboarding_processes_user ON offboarding_processes(user_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS offboarding_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        process_id INTEGER NOT NULL REFERENCES offboarding_processes(id) ON DELETE CASCADE,
        section TEXT NOT NULL CHECK (section IN (\'account\',\'service\',\'subscription\',\'recovery\')),
        item_key TEXT NOT NULL,
        done_at TEXT,
        done_by INTEGER,
        UNIQUE (process_id, section, item_key)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_offboarding_tasks_process ON offboarding_tasks(process_id)');
};
