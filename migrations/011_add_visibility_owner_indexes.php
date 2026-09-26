<?php
declare(strict_types=1);

/**
 * Indexes (visibility, owner_user_id) on emails/services/phones/accounts —
 * visibilityScope() (includes/helpers.php) filters on exactly this pair on
 * every list/search/export query, so a large workspace was doing a full
 * table scan per visibility-scoped query with no index to use at all.
 *
 * Safe to run any time after migrations/007_add_visibility_columns.php: by
 * migration ordering, 007 has always already added both columns to every
 * one of these four tables by the time this one runs, so there's no need
 * to re-check column existence here — only whether the index itself
 * already exists (CREATE INDEX IF NOT EXISTS), same as every other index
 * in this codebase.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_emails_visibility_owner ON emails(visibility, owner_user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_services_visibility_owner ON services(visibility, owner_user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_phones_visibility_owner ON phones(visibility, owner_user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accounts_visibility_owner ON accounts(visibility, owner_user_id)');
};
