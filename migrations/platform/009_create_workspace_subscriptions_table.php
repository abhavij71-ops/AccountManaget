<?php
declare(strict_types=1);

/**
 * Billing (ZarinPal + manual-approval) and platform-admin workspace control.
 *
 * workspace_subscriptions is a history of checkout attempts/requests, not
 * just "the current plan" — a workspace can accumulate many rows over time
 * (a rejected manual request, a cancelled ZarinPal attempt, the eventual
 * approved one); "the" active plan is simply the most recent row with
 * status = 'active'. Activating one (includes/subscriptions.php's
 * activateWorkspaceSubscription()) is what actually assigns plan_code onto
 * the workspaces row itself, which is what checkPlanLimit() reads.
 *
 * workspaces.is_suspended backs the admin/ panel's manual suspend/activate
 * control — a defensive PRAGMA-checked ADD COLUMN, same pattern already
 * used for plan_code in migrations/platform/008_create_plans_table.php.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
        plan_code TEXT NOT NULL REFERENCES plans(code),
        start_date TEXT,
        end_date TEXT,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'active', 'rejected', 'expired', 'cancelled')),
        payment_method TEXT NOT NULL CHECK (payment_method IN ('zarinpal', 'manual')),
        payment_reference TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_workspace_subscriptions_workspace ON workspace_subscriptions(workspace_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_workspace_subscriptions_status ON workspace_subscriptions(status)');

    $existingColumns = $pdo->query('PRAGMA table_info(workspaces)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_suspended', $existingColumns, true)) {
        $pdo->exec('ALTER TABLE workspaces ADD COLUMN is_suspended INTEGER NOT NULL DEFAULT 0');
    }
};
