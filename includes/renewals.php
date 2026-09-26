<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Cost per Currency, kept in separate buckets — spec sec. 30 forbids summing
 * across currencies, and forbids silently combining Monthly and Yearly cost
 * without an explicit, disclosed rule. Grouping by (currency, billing_cycle)
 * and never combining rows satisfies both: nothing is ever added together
 * unless it is the exact same currency AND the exact same billing cycle.
 * Only currently-Active Paid subscriptions with a recorded price count —
 * Cancelled/Expired subscriptions are not an ongoing cost.
 */
function fetchCostsByCurrency(PDO $pdo, bool $scoped = true): array
{
    // subscriptions has no owner_user_id of its own — scoped through the
    // account each one belongs to (join through accounts, scope on
    // accounts), same as every other subscriptions query in this file.
    // $scoped = false is for cron.php's unattended, session-less context
    // (see fetchRenewals() below) — never pass false from a page.
    $stmt = $pdo->query("SELECT sub.currency, sub.billing_cycle, COUNT(*) AS account_count, SUM(sub.price) AS total
        FROM subscriptions sub
        JOIN accounts a ON a.id = sub.account_id
        WHERE sub.type = 'Paid' AND sub.status = 'Active' AND sub.price IS NOT NULL
              AND sub.currency IS NOT NULL AND sub.currency != ''
              AND " . ($scoped ? visibilityScope('accounts', 'a') : '1=1') . "
        GROUP BY sub.currency, sub.billing_cycle
        ORDER BY sub.currency, sub.billing_cycle");
    return $stmt->fetchAll();
}

/**
 * Renewal tracking (spec sec. 31): Overdue, Upcoming (within $upcomingDays),
 * and Auto-Renewing subscriptions. Only Active subscriptions with a real
 * recorded renewal_date are considered — dates are never guessed.
 */
/**
 * $scoped defaults to true for every page (docs/PERMISSIONS.md — a member
 * or viewer must only see renewals for accounts they're allowed to see).
 * cron.php passes false: it runs with no logged-in user at all, so
 * currentRole()/currentUserId() (which visibilityScope() calls) have
 * nothing to resolve — VERIFIED to fatal with "Call to undefined function
 * currentRole()" before this parameter existed. The nightly reminder is
 * meant for the workspace owner anyway, who is allowed to see everything,
 * so skipping the scope there is correct, not just a crash workaround.
 */
function fetchRenewals(PDO $pdo, int $upcomingDays = 30, bool $scoped = true): array
{
    $today = date('Y-m-d');
    $upcomingUntil = date('Y-m-d', strtotime("+{$upcomingDays} days"));

    $columns = "sub.account_id, sub.type, sub.status, sub.plan, sub.price, sub.currency,
            sub.billing_cycle, sub.renewal_date, sub.auto_renewal,
            a.username, a.display_name, s.service_name, e.email_address";
    $base = "SELECT $columns
        FROM subscriptions sub
        JOIN accounts a ON a.id = sub.account_id
        JOIN services s ON s.id = a.service_id
        JOIN emails e ON e.id = a.email_id
        WHERE sub.status = 'Active' AND sub.renewal_date IS NOT NULL AND a.is_archived = 0
              AND " . ($scoped ? visibilityScope('accounts', 'a') : '1=1');

    $overdueStmt = $pdo->prepare($base . ' AND sub.renewal_date < :today ORDER BY sub.renewal_date ASC');
    $overdueStmt->execute(['today' => $today]);

    $upcomingStmt = $pdo->prepare($base . ' AND sub.renewal_date >= :today AND sub.renewal_date <= :until ORDER BY sub.renewal_date ASC');
    $upcomingStmt->execute(['today' => $today, 'until' => $upcomingUntil]);

    $autoStmt = $pdo->prepare("SELECT $columns
        FROM subscriptions sub
        JOIN accounts a ON a.id = sub.account_id
        JOIN services s ON s.id = a.service_id
        JOIN emails e ON e.id = a.email_id
        WHERE sub.auto_renewal = 1 AND sub.status = 'Active' AND a.is_archived = 0
              AND " . ($scoped ? visibilityScope('accounts', 'a') : '1=1') . "
        ORDER BY sub.renewal_date IS NULL, sub.renewal_date ASC");
    $autoStmt->execute();

    return [
        'overdue' => $overdueStmt->fetchAll(),
        'upcoming' => $upcomingStmt->fetchAll(),
        'auto_renewing' => $autoStmt->fetchAll(),
        'upcoming_days' => $upcomingDays,
    ];
}

/**
 * Possibly-unused paid subscriptions: Active Paid subscriptions on accounts
 * whose last_login is missing or older than $idleDays. Only Monthly-cycle
 * subscriptions count toward the "monthly cost total" per currency — spec
 * sec. 30 forbids summing across currencies or silently combining billing
 * cycles, so Yearly-cycle rows are listed but excluded from that total.
 */
function fetchPossiblyUnusedSubscriptions(PDO $pdo, int $idleDays = 90, bool $scoped = true): array
{
    // VERIFIED: this query had no visibilityScope() call at all, so the
    // "possibly unused" list showed a member/viewer the owner's private
    // paid accounts. $scoped = false remains available for a future
    // session-less caller the same as fetchRenewals()/fetchCostsByCurrency(),
    // but every page must keep the default true.
    $cutoff = dbNow("-{$idleDays} days");

    $stmt = $pdo->prepare(
        "SELECT sub.id AS subscription_id, sub.account_id, sub.price, sub.currency, sub.billing_cycle,
                a.username, a.display_name, a.last_login, a.visibility, a.owner_user_id, s.service_name, e.email_address
         FROM subscriptions sub
         JOIN accounts a ON a.id = sub.account_id
         JOIN services s ON s.id = a.service_id
         JOIN emails e ON e.id = a.email_id
         WHERE sub.type = 'Paid' AND sub.status = 'Active' AND a.is_archived = 0
               AND (a.last_login IS NULL OR a.last_login < :cutoff)
               AND " . ($scoped ? visibilityScope('accounts', 'a') : '1=1') . "
         ORDER BY a.last_login IS NOT NULL, a.last_login ASC"
    );
    $stmt->execute(['cutoff' => $cutoff]);
    $rows = $stmt->fetchAll();

    $monthlyTotals = [];
    foreach ($rows as $row) {
        if ($row['billing_cycle'] === 'Monthly' && $row['price'] !== null && $row['currency']) {
            $monthlyTotals[$row['currency']] = ($monthlyTotals[$row['currency']] ?? 0) + (float) $row['price'];
        }
    }
    ksort($monthlyTotals);

    return [
        'accounts' => $rows,
        'monthly_totals' => $monthlyTotals,
        'idle_days' => $idleDays,
    ];
}
