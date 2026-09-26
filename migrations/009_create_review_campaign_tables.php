<?php
declare(strict_types=1);

/**
 * Team review campaigns — turns review.php from single-user mode (step
 * 9.4.5) into a team campaign. An admin creates a review_campaigns row and
 * a snapshot of review_items is generated at creation time: one row per
 * account then due for re-verification, assigned to that account's
 * owner_user_id. created_by/responsible_user_id reference accounts_users.id
 * in the central platform database — same cross-database-reference pattern
 * already used by owner_user_id on emails/services/accounts/phones and by
 * offboarding_processes.user_id — no (and can't be a) foreign key tying
 * them together. account_id is a real (same-database) foreign key since
 * accounts lives in this same workspace database.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS review_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        start_date TEXT NOT NULL,
        deadline TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS review_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL REFERENCES review_campaigns(id) ON DELETE CASCADE,
        account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
        responsible_user_id INTEGER NOT NULL,
        response_status TEXT NOT NULL DEFAULT \'pending\' CHECK (response_status IN (\'pending\',\'confirmed\',\'changed\',\'gone\')),
        responded_at TEXT,
        UNIQUE (campaign_id, account_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_review_items_campaign ON review_items(campaign_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_review_items_responsible ON review_items(campaign_id, responsible_user_id)');
};
