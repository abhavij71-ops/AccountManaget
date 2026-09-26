<?php
declare(strict_types=1);

/**
 * Subscription plans, so limits can be enforced in code instead of only
 * stated on a pricing page (see includes/plans.php's checkPlanLimit()).
 * workspaces.plan_code defaults every existing and new workspace to
 * 'free' — there is no billing/upgrade flow yet, so 'free' is the only
 * plan that can be assigned without a human decision behind it.
 *
 * max_members/max_accounts of NULL means unlimited, not zero.
 */
return function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        max_members INTEGER,
        max_accounts INTEGER,
        monthly_price REAL,
        yearly_price REAL,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');

    $pdo->prepare(
        'INSERT OR IGNORE INTO plans (code, name, max_members, max_accounts, monthly_price, yearly_price) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute(['free', 'Free', 3, 25, 0, 0]);
    $pdo->prepare(
        'INSERT OR IGNORE INTO plans (code, name, max_members, max_accounts, monthly_price, yearly_price) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute(['pro', 'Pro', null, null, 9, 90]);

    $existingColumns = $pdo->query('PRAGMA table_info(workspaces)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('plan_code', $existingColumns, true)) {
        $pdo->exec("ALTER TABLE workspaces ADD COLUMN plan_code TEXT NOT NULL DEFAULT 'free'");
    }
};
