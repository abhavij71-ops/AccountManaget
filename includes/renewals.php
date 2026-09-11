<?php
declare(strict_types=1);

/**
 * Cost per Currency, kept in separate buckets — spec sec. 30 forbids summing
 * across currencies, and forbids silently combining Monthly and Yearly cost
 * without an explicit, disclosed rule. Grouping by (currency, billing_cycle)
 * and never combining rows satisfies both: nothing is ever added together
 * unless it is the exact same currency AND the exact same billing cycle.
 * Only currently-Active Paid subscriptions with a recorded price count —
 * Cancelled/Expired subscriptions are not an ongoing cost.
 */
function fetchCostsByCurrency(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT currency, billing_cycle, COUNT(*) AS account_count, SUM(price) AS total
        FROM subscriptions
        WHERE type = 'Paid' AND status = 'Active' AND price IS NOT NULL
              AND currency IS NOT NULL AND currency != ''
        GROUP BY currency, billing_cycle
        ORDER BY currency, billing_cycle");
    return $stmt->fetchAll();
}

/**
 * Renewal tracking (spec sec. 31): Overdue, Upcoming (within $upcomingDays),
 * and Auto-Renewing subscriptions. Only Active subscriptions with a real
 * recorded renewal_date are considered — dates are never guessed.
 */
function fetchRenewals(PDO $pdo, int $upcomingDays = 30): array
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
        WHERE sub.status = 'Active' AND sub.renewal_date IS NOT NULL AND a.is_archived = 0";

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
        ORDER BY sub.renewal_date IS NULL, sub.renewal_date ASC");
    $autoStmt->execute();

    return [
        'overdue' => $overdueStmt->fetchAll(),
        'upcoming' => $upcomingStmt->fetchAll(),
        'auto_renewing' => $autoStmt->fetchAll(),
        'upcoming_days' => $upcomingDays,
    ];
}
