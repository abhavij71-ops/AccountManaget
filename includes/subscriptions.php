<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';

/**
 * One row per checkout attempt/manual request — see the schema comment in
 * migrations/platform/009_create_workspace_subscriptions_table.php for why
 * this is a history, not a single current-plan record.
 */
function createWorkspaceSubscriptionRequest(int $workspaceId, string $planCode, string $paymentMethod, ?string $paymentReference, string $status = 'pending'): int
{
    $platform = platformDb();
    $platform->prepare(
        'INSERT INTO workspace_subscriptions (workspace_id, plan_code, payment_method, payment_reference, status) VALUES (?, ?, ?, ?, ?)'
    )->execute([$workspaceId, $planCode, $paymentMethod, $paymentReference, $status]);
    return (int) $platform->lastInsertId();
}

function markSubscriptionPaymentReference(int $subscriptionId, string $reference): void
{
    platformDb()->prepare("UPDATE workspace_subscriptions SET payment_reference = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$reference, $subscriptionId]);
}

/**
 * Approves a subscription request: flips its own status to 'active' with a
 * one-year start/end window, and assigns its plan onto the workspace row
 * itself so checkPlanLimit() (includes/plans.php) picks it up immediately.
 * Used both by the ZarinPal callback (on a verified payment) and by
 * admin/subscriptions.php (on a manual-payment approval) — the two payment
 * methods differ only in how this function gets reached, never in what it
 * does once called.
 */
function activateWorkspaceSubscription(int $subscriptionId): void
{
    $platform = platformDb();
    $stmt = $platform->prepare('SELECT workspace_id, plan_code FROM workspace_subscriptions WHERE id = ?');
    $stmt->execute([$subscriptionId]);
    $subscription = $stmt->fetch();
    if ($subscription === false) {
        throw new RuntimeException("Subscription {$subscriptionId} not found.");
    }

    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+1 year'));

    $platform->beginTransaction();
    try {
        $platform->prepare(
            "UPDATE workspace_subscriptions SET status = 'active', start_date = ?, end_date = ?, updated_at = datetime('now') WHERE id = ?"
        )->execute([$startDate, $endDate, $subscriptionId]);
        $platform->prepare('UPDATE workspaces SET plan_code = ? WHERE id = ?')
            ->execute([$subscription['plan_code'], $subscription['workspace_id']]);
        $platform->commit();
    } catch (Throwable $e) {
        $platform->rollBack();
        throw $e;
    }
}

function rejectWorkspaceSubscription(int $subscriptionId): void
{
    platformDb()->prepare("UPDATE workspace_subscriptions SET status = 'rejected', updated_at = datetime('now') WHERE id = ?")
        ->execute([$subscriptionId]);
}

/**
 * @return array<int,array<string,mixed>>
 */
function listPendingManualSubscriptions(): array
{
    return platformDb()->query(
        "SELECT ws.*, w.name AS workspace_name, p.name AS plan_name
         FROM workspace_subscriptions ws
         JOIN workspaces w ON w.id = ws.workspace_id
         JOIN plans p ON p.code = ws.plan_code
         WHERE ws.status = 'pending' AND ws.payment_method = 'manual'
         ORDER BY ws.created_at ASC"
    )->fetchAll();
}
