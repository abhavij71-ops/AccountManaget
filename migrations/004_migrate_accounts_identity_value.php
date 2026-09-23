<?php
declare(strict_types=1);

/**
 * Adds identity_value (free-text label for identity_type='other') for
 * databases created before this change. Plain ADD COLUMN — no rename, no
 * rebuild, no CHECK change, no backfill needed since it's nullable.
 *
 * Deliberately NOT enforced by a CHECK: an 'other'-anchored account with no
 * recorded value is a legitimate state in this product's five-state model
 * (missing knowledge), not an error to forbid at the schema level — PHP-side
 * validation already treats it as optional, and needs-attention.php surfaces
 * it as an Informational nudge instead.
 */
return function (PDO $pdo): void {
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='accounts'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = $pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('identity_value', $columns, true)) {
        return;
    }

    $pdo->exec('ALTER TABLE accounts ADD COLUMN identity_value TEXT');
};
