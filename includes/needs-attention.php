<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/accounts/_lib.php';
require_once __DIR__ . '/../modules/emails/_lib.php';

const NEEDS_ATTENTION_LEVELS = ['Critical', 'Warning', 'Informational'];

const NEEDS_ATTENTION_LEVEL_LABELS = [
    'Critical' => 'بحرانی',
    'Warning' => 'هشدار',
    'Informational' => 'اطلاع‌رسانی',
];

function needsAttentionLevelBadgeClass(string $level): string
{
    return match ($level) {
        'Critical' => 'badge-disabled',
        'Warning' => 'badge-status-suspended',
        default => 'badge-category',
    };
}

function renderNeedsAttentionLevelBadge(string $level): string
{
    return '<span class="badge ' . needsAttentionLevelBadgeClass($level) . '">' . e(NEEDS_ATTENTION_LEVEL_LABELS[$level] ?? $level) . '</span>';
}

/**
 * Rules follow spec sec. 36 literally:
 *  - Critical: 2FA disabled; recovery info does not exist at all; an important
 *    security status (2FA) is Unknown.
 *  - Warning: recovery not verified; info is stale; renewal is near or overdue;
 *    subscription has a problem (Expired/Paused).
 *  - Informational: profile incomplete; secondary fields Unknown; due for a
 *    periodic security review.
 * Never invents a problem for an account that isn't actually active — callers
 * are expected to have already excluded Closed/Abandoned/Archived accounts.
 */
function evaluateAccountIssues(array $account, ?array $security, ?array $recovery, ?array $subscription, int $completeness): array
{
    $issues = [];

    $twofa = $security['twofa_status'] ?? 'Not Set';
    if ($twofa === 'Disabled') {
        $issues[] = ['level' => 'Critical', 'message' => 'تأیید دومرحله‌ای (2FA) غیرفعال است.'];
    } elseif ($twofa === 'Unknown') {
        $issues[] = ['level' => 'Critical', 'message' => 'وضعیت تأیید دومرحله‌ای (2FA) نامشخص است.'];
    }

    $recoveryStatus = $recovery['status'] ?? 'Not Set';
    if ($recoveryStatus === 'Not Set') {
        $issues[] = ['level' => 'Critical', 'message' => 'اطلاعات بازیابی (Recovery) برای این اکانت ثبت نشده است.'];
    } elseif ($recoveryStatus === 'Not Verified') {
        $issues[] = ['level' => 'Warning', 'message' => 'اطلاعات بازیابی (Recovery) هنوز تأیید نشده است.'];
    }

    if (empty($account['last_verified'])) {
        $issues[] = ['level' => 'Warning', 'message' => 'این اکانت هرگز تأیید نشده است.'];
    } else {
        $verifiedAt = strtotime((string) $account['last_verified']);
        if ($verifiedAt !== false && $verifiedAt < strtotime('-180 days')) {
            $issues[] = ['level' => 'Warning', 'message' => 'آخرین تأیید این اکانت بیش از ۶ ماه پیش بوده است.'];
        }
    }

    if ($subscription && ($subscription['status'] ?? null) === 'Active' && !empty($subscription['renewal_date'])) {
        $renewalAt = strtotime((string) $subscription['renewal_date']);
        if ($renewalAt !== false) {
            if ($renewalAt < strtotime('today')) {
                $issues[] = ['level' => 'Warning', 'message' => 'تاریخ تمدید Subscription گذشته است (Overdue).'];
            } elseif ($renewalAt <= strtotime('+30 days')) {
                $issues[] = ['level' => 'Warning', 'message' => 'تاریخ تمدید Subscription نزدیک است.'];
            }
        }
    }
    if ($subscription && in_array($subscription['status'] ?? null, ['Expired', 'Paused'], true)) {
        $label = SUBSCRIPTION_STATUSES[$subscription['status']] ?? $subscription['status'];
        $issues[] = ['level' => 'Warning', 'message' => 'وضعیت Subscription این اکانت مشکل دارد (' . $label . ').'];
    }

    if ($completeness < 50) {
        $issues[] = ['level' => 'Informational', 'message' => 'اطلاعات این اکانت ناقص است (' . $completeness . '% تکمیل).'];
    }

    $secondaryUnknown = false;
    foreach (['passkey_status', 'security_key_status', 'security_questions_status'] as $f) {
        if (($security[$f] ?? 'Not Set') === 'Unknown') {
            $secondaryUnknown = true;
            break;
        }
    }
    if (($recovery['recovery_codes_status'] ?? 'Not Set') === 'Unknown') {
        $secondaryUnknown = true;
    }
    if ($secondaryUnknown) {
        $issues[] = ['level' => 'Informational', 'message' => 'برخی از وضعیت‌های امنیتی این اکانت نامشخص (Unknown) است.'];
    }

    $checkedAt = $security['last_security_check'] ?? null;
    $checkedAtTs = $checkedAt ? strtotime((string) $checkedAt) : false;
    if (!$checkedAt || $checkedAtTs === false || $checkedAtTs < strtotime('-180 days')) {
        $issues[] = ['level' => 'Informational', 'message' => 'مدتی است بررسی امنیتی برای این اکانت انجام نشده است.'];
    }

    return $issues;
}

function evaluateEmailIssues(array $email, ?array $security, int $completeness): array
{
    $issues = [];

    $twofa = $security['twofa_status'] ?? 'Not Set';
    if ($twofa === 'Disabled') {
        $issues[] = ['level' => 'Critical', 'message' => 'تأیید دومرحله‌ای (2FA) این ایمیل غیرفعال است.'];
    } elseif ($twofa === 'Unknown') {
        $issues[] = ['level' => 'Critical', 'message' => 'وضعیت تأیید دومرحله‌ای (2FA) این ایمیل نامشخص است.'];
    }

    $recoveryCodes = $security['recovery_codes_status'] ?? 'Not Set';
    if ($recoveryCodes === 'Not Set' && empty($security['recovery_email_id']) && empty($security['recovery_phone_id'])) {
        $issues[] = ['level' => 'Critical', 'message' => 'اطلاعات بازیابی برای این ایمیل ثبت نشده است.'];
    }

    if (empty($email['last_verified'])) {
        $issues[] = ['level' => 'Warning', 'message' => 'این ایمیل هرگز تأیید نشده است.'];
    } else {
        $verifiedAt = strtotime((string) $email['last_verified']);
        if ($verifiedAt !== false && $verifiedAt < strtotime('-180 days')) {
            $issues[] = ['level' => 'Warning', 'message' => 'آخرین تأیید این ایمیل بیش از ۶ ماه پیش بوده است.'];
        }
    }

    if ($completeness < 50) {
        $issues[] = ['level' => 'Informational', 'message' => 'پروفایل این ایمیل ناقص است (' . $completeness . '% تکمیل).'];
    }

    foreach (['passkey_status', 'security_key_status', 'security_questions_status'] as $f) {
        if (($security[$f] ?? 'Not Set') === 'Unknown') {
            $issues[] = ['level' => 'Informational', 'message' => 'برخی از وضعیت‌های امنیتی این ایمیل نامشخص (Unknown) است.'];
            break;
        }
    }

    return $issues;
}

/**
 * Flat, sorted (Critical first) list of every open item across Emails and
 * Accounts. Closed/Abandoned/Archived entities are excluded entirely — spec
 * sec. 36 explicitly forbids flagging them as active problems without reason.
 */
function getNeedsAttentionItems(PDO $pdo): array
{
    $items = [];

    $accountIds = $pdo->query("SELECT id FROM accounts WHERE is_archived = 0 AND status NOT IN ('Closed', 'Abandoned')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($accountIds as $accountId) {
        $account = fetchAccountById($pdo, (int) $accountId);
        if (!$account) {
            continue;
        }
        $security = fetchAccountSecurity($pdo, (int) $accountId);
        $recovery = fetchAccountRecovery($pdo, (int) $accountId);
        $subscription = fetchSubscription($pdo, (int) $accountId);
        $completeness = calcAccountCompleteness($account, $security, $recovery);
        $title = $account['service_name'] . ' — ' . ($account['username'] ?: $account['email_address']);
        foreach (evaluateAccountIssues($account, $security, $recovery, $subscription, $completeness) as $issue) {
            $items[] = $issue + [
                'entity_type' => 'account',
                'entity_id' => (int) $accountId,
                'title' => $title,
                'url' => 'modules/accounts/view.php?id=' . $accountId,
            ];
        }
    }

    $emailIds = $pdo->query("SELECT id FROM emails WHERE is_archived = 0 AND status NOT IN ('Disabled', 'Abandoned')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($emailIds as $emailId) {
        $email = fetchEmailById($pdo, (int) $emailId);
        if (!$email) {
            continue;
        }
        $security = fetchEmailSecurity($pdo, (int) $emailId);
        $completeness = calcEmailCompleteness($email, $security);
        foreach (evaluateEmailIssues($email, $security, $completeness) as $issue) {
            $items[] = $issue + [
                'entity_type' => 'email',
                'entity_id' => (int) $emailId,
                'title' => $email['email_address'],
                'url' => 'modules/emails/view.php?id=' . $emailId,
            ];
        }
    }

    $order = array_flip(NEEDS_ATTENTION_LEVELS);
    usort($items, static fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

    return $items;
}

function needsAttentionSummary(array $items): array
{
    $summary = ['Critical' => 0, 'Warning' => 0, 'Informational' => 0, 'total' => count($items)];
    foreach ($items as $item) {
        $summary[$item['level']]++;
    }
    return $summary;
}
