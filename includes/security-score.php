<?php
declare(strict_types=1);

/**
 * Weighted score of the Email's OWN security posture only (spec sec. 8) — never
 * influenced by the security state of Accounts that use this email.
 */
function calcEmailSecurityScore(?array $security): ?int
{
    if ($security === null) {
        return null;
    }

    $weights = [
        'twofa_status' => 40,
        'passkey_status' => 15,
        'security_key_status' => 15,
        'security_questions_status' => 10,
        'recovery_codes_status' => 10,
    ];

    $earned = 0;
    $possible = 0;

    foreach ($weights as $field => $weight) {
        $value = $security[$field] ?? 'Not Set';
        if ($value === 'Not Applicable') {
            continue;
        }
        $possible += $weight;
        if ($value === 'Enabled') {
            $earned += $weight;
        }
    }

    $possible += 5;
    if (!empty($security['backup_method'])) {
        $earned += 5;
    }

    $possible += 5;
    if (!empty($security['last_security_check'])) {
        $checkedAt = strtotime((string) $security['last_security_check']);
        if ($checkedAt !== false && $checkedAt >= strtotime('-180 days')) {
            $earned += 5;
        }
    }

    if ($possible === 0) {
        return null;
    }

    return (int) round(($earned / $possible) * 100);
}

/**
 * Count of confirmed (Disabled, not merely Unknown) security weaknesses on the
 * Email itself — a narrow, honest stand-in for the full Needs Attention engine
 * (spec sec. 36), which is out of scope until Accounts/Subscriptions exist.
 */
function countEmailSecurityIssues(?array $security): int
{
    if ($security === null) {
        return 0;
    }
    $fields = [
        'twofa_status', 'passkey_status', 'security_key_status',
        'security_questions_status', 'recovery_codes_status',
    ];
    $count = 0;
    foreach ($fields as $field) {
        if (($security[$field] ?? null) === 'Disabled') {
            $count++;
        }
    }
    return $count;
}

/**
 * Tallies a security-state column across a set of rows, keeping all five states
 * (spec sec. 9) as separate buckets — a row with no value at all counts as
 * "Not Set" (never entered), while an explicit 'Unknown' value counts as
 * "Unknown" (recorded but not known). The two must never be merged.
 */
function tallySecurityStates(array $rows, string $column): array
{
    $tally = [
        'Enabled' => 0,
        'Disabled' => 0,
        'Unknown' => 0,
        'Not Set' => 0,
        'Not Applicable' => 0,
    ];

    foreach ($rows as $row) {
        $value = $row[$column] ?? null;
        if ($value === null || $value === '') {
            $value = 'Not Set';
        } elseif (!array_key_exists($value, $tally)) {
            $value = 'Unknown';
        }
        $tally[$value]++;
    }

    return $tally;
}
