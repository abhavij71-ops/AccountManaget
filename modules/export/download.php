<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/security-score.php';
require_once __DIR__ . '/../../includes/needs-attention.php';
require_once __DIR__ . '/../../includes/audit.php';

requireLogin();
requireWriteAccess();

$pdo = db();
$entity = (string) ($_GET['entity'] ?? '');

if (!in_array($entity, ['emails', 'services', 'accounts', 'phones', 'subscriptions', 'security'], true)) {
    flashSet('danger', t('import.invalid_entity_type'));
    header('Location: index.php');
    exit;
}

/** Renders a tri-state (Yes/No/Unknown) value the same way the UI's yesNoBadge() does, as plain text. */
$yesNo = static function (?int $value): string {
    if ($value === null) {
        return t('enum.Unknown');
    }
    return $value ? t('common.yes') : t('common.no');
};

/** Resolves an account row's identity value (email/phone/username) from its identity_type + joined columns. */
$identityOf = static function (array $r): string {
    return match ($r['identity_type'] ?? 'email') {
        'phone' => (string) ($r['phone_number'] ?? ''),
        'username' => (string) ($r['username'] ?? ''),
        'other' => '',
        default => (string) ($r['email_address'] ?? ''),
    };
};

/**
 * CSV formula-injection guard (VERIFIED: notes/names/usernames are
 * user-controlled and written unescaped; a cell starting with = + - @ tab
 * or CR is executed as a formula the moment Excel opens the file). A
 * leading single quote is Excel/Sheets' own long-standing "force text"
 * marker — it forces the cell to display as literal text instead of being
 * parsed as a formula, without altering the value itself when read back
 * programmatically (e.g. re-importing this same export).
 */
function csvSafe(string $v): string
{
    return $v !== '' && preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;
}

$filename = 'account-manager-' . $entity . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// Windows Excel only detects a UTF-8 CSV correctly when it starts with a BOM —
// without it, Persian/Arabic text is mangled (mojibake) when the file is opened.
fwrite($out, "\xEF\xBB\xBF");

// Every cell (header and data rows alike) is passed through csvSafe()
// before ever reaching fputcsv() — cheaper and safer than trying to
// classify which specific columns are "user-controlled" per entity.
$writeRow = static function (array $row) use ($out): void {
    fputcsv($out, array_map(static fn ($v) => csvSafe((string) ($v ?? '')), $row));
};

$exportedCount = 0;

switch ($entity) {
    case 'emails':
        $rows = $pdo->query('SELECT email_address, display_name, provider, type, purpose, status,
                created_date, last_verified, is_archived, notes
            FROM emails WHERE ' . visibilityScope('emails') . ' ORDER BY email_address')->fetchAll();
        $exportedCount = count($rows);

        $writeRow([
            t('emails.th_address'), t('common.field_display_name'), t('emails.field_provider'), t('common.field_type'),
            t('emails.field_purpose'), t('common.field_status'), t('common.field_created_date'), t('common.field_last_verified'),
            t('accounts.archived_badge'), t('common.field_notes'),
        ]);
        foreach ($rows as $r) {
            $writeRow([
                $r['email_address'], $r['display_name'], $r['provider'], enumLabel($r['type'], EMAIL_TYPES),
                $r['purpose'], enumLabel($r['status'], EMAIL_STATUSES), $r['created_date'], $r['last_verified'],
                $yesNo((int) $r['is_archived']), $r['notes'],
            ]);
        }
        break;

    case 'services':
        $rows = $pdo->query('SELECT service_name, website, login_url, category, status, purpose, is_archived, notes
            FROM services WHERE ' . visibilityScope('services') . ' ORDER BY service_name')->fetchAll();
        $exportedCount = count($rows);

        $writeRow([
            t('services.th_name'), t('services.field_website'), t('services.field_login_url'), t('services.th_category'),
            t('common.field_status'), t('services.field_purpose'), t('accounts.archived_badge'), t('common.field_notes'),
        ]);
        foreach ($rows as $r) {
            $writeRow([
                $r['service_name'], $r['website'], $r['login_url'], $r['category'],
                enumLabel($r['status'], SERVICE_STATUSES), $r['purpose'], $yesNo((int) $r['is_archived']), $r['notes'],
            ]);
        }
        break;

    case 'phones':
        $rows = $pdo->query('SELECT phone_number, country, label, status, is_primary, is_archived, notes
            FROM phones WHERE ' . visibilityScope('phones') . ' ORDER BY phone_number')->fetchAll();
        $exportedCount = count($rows);

        $writeRow([
            t('phones.th_number'), t('phones.field_country'), t('phones.field_label'), t('common.field_status'),
            t('phones.field_is_primary'), t('accounts.archived_badge'), t('common.field_notes'),
        ]);
        foreach ($rows as $r) {
            $writeRow([
                $r['phone_number'], $r['country'], $r['label'], enumLabel($r['status'], PHONE_STATUSES),
                $yesNo((int) $r['is_primary']), $yesNo((int) $r['is_archived']), $r['notes'],
            ]);
        }
        break;

    case 'accounts':
        $rows = $pdo->query("SELECT a.identity_type, a.username, a.display_name, a.external_account_id, a.account_url, a.login_url,
                a.status, a.account_type, a.created_date, a.last_login, a.last_verified, a.is_archived, a.notes,
                s.service_name, e.email_address, p.phone_number
            FROM accounts a
            JOIN services s ON s.id = a.service_id
            LEFT JOIN emails e ON e.id = a.email_id
            LEFT JOIN phones p ON p.id = a.identity_phone_id
            WHERE " . visibilityScope('accounts', 'a') . "
            ORDER BY s.service_name")->fetchAll();
        $exportedCount = count($rows);

        $writeRow([
            t('accounts.th_service'), t('export.col_identity_type'), t('accounts.th_identity'), t('accounts.th_username'),
            t('common.field_display_name'), t('accounts.field_external_id'), t('accounts.field_account_url'), t('services.field_login_url'),
            t('common.field_status'), t('accounts.field_type_plain'), t('common.field_created_date'), t('accounts.field_last_login'),
            t('common.field_last_verified'), t('accounts.archived_badge'), t('common.field_notes'),
        ]);
        foreach ($rows as $r) {
            $identityTypeLabel = match ($r['identity_type']) {
                'phone' => t('accounts.identity_type_phone'),
                'username' => t('accounts.identity_type_username'),
                'other' => t('accounts.identity_type_other'),
                default => t('accounts.identity_type_email'),
            };
            $writeRow([
                $r['service_name'], $identityTypeLabel, $identityOf($r), $r['username'],
                $r['display_name'], $r['external_account_id'], $r['account_url'], $r['login_url'],
                enumLabel($r['status'], ACCOUNT_STATUSES), enumLabel($r['account_type'], ACCOUNT_TYPES),
                $r['created_date'], $r['last_login'], $r['last_verified'],
                $yesNo((int) $r['is_archived']), $r['notes'],
            ]);
        }
        break;

    case 'subscriptions':
        // subscriptions/payments have no owner_user_id of their own — scoped
        // via the accounts row each one belongs to (join through accounts,
        // scope on accounts, per the task).
        $rows = $pdo->query('SELECT s.service_name, a.identity_type, a.username, e.email_address, p.phone_number,
                sub.plan, sub.type AS sub_type, sub.status AS sub_status, sub.price, sub.currency, sub.billing_cycle,
                sub.start_date, sub.renewal_date, sub.auto_renewal,
                pay.payment_required, pay.payment_method, pay.card_brand, pay.last4, pay.payment_reference,
                pay.auto_renewal AS payment_auto_renewal
            FROM accounts a
            JOIN services s ON s.id = a.service_id
            LEFT JOIN emails e ON e.id = a.email_id
            LEFT JOIN phones p ON p.id = a.identity_phone_id
            LEFT JOIN subscriptions sub ON sub.account_id = a.id
            LEFT JOIN payments pay ON pay.account_id = a.id
            WHERE ' . visibilityScope('accounts', 'a') . '
            ORDER BY s.service_name')->fetchAll();
        $exportedCount = count($rows);

        $writeRow([
            t('accounts.th_service'), t('accounts.th_identity'), t('accounts.th_plan'), t('common.field_type'), t('common.field_status'),
            t('accounts.field_price'), t('accounts.field_currency'), t('accounts.th_billing_cycle'), t('accounts.field_start_date'),
            t('accounts.field_renewal_date'), t('accounts.field_auto_renewal'), t('accounts.field_payment_required'),
            t('accounts.field_payment_method'), t('accounts.field_card_brand'), t('accounts.field_last4'),
            t('accounts.field_payment_reference'), t('accounts.field_payment_auto_renewal'),
        ]);
        foreach ($rows as $r) {
            // Never export the full card number/CVV (never stored anyway) — mask even the last 4 digits
            // the same way the account profile UI does, rather than exporting them bare.
            $last4Masked = !empty($r['last4']) ? '•••• ' . $r['last4'] : '';

            $writeRow([
                $r['service_name'], $identityOf($r), $r['plan'],
                $r['sub_type'] !== null ? enumLabel($r['sub_type'], SUBSCRIPTION_TYPES) : '',
                $r['sub_status'] !== null ? enumLabel($r['sub_status'], SUBSCRIPTION_STATUSES) : '',
                $r['price'], $r['currency'],
                $r['billing_cycle'] !== null ? enumLabel($r['billing_cycle'], BILLING_CYCLES) : '',
                $r['start_date'], $r['renewal_date'],
                $yesNo(isset($r['auto_renewal']) ? (int) $r['auto_renewal'] : null),
                $yesNo(isset($r['payment_required']) ? (int) $r['payment_required'] : null),
                $r['payment_method'], $r['card_brand'], $last4Masked, $r['payment_reference'],
                $yesNo(isset($r['payment_auto_renewal']) ? (int) $r['payment_auto_renewal'] : null),
            ]);
        }
        break;

    case 'security':
        // Combined report: three sections in one CSV, each with its own header row and
        // separated by a blank line — email security scores, account 2FA status, and the
        // Needs Attention issue list, mirroring the underlying security-score.php /
        // needs-attention.php logic rather than duplicating it.
        $writeRow(['Email Security']);
        $writeRow([
            t('emails.th_address'), t('field.twofa'), t('field.passkey'), t('emails.view_security_key'),
            t('field.security_questions'), t('field.recovery_codes_status'), t('field.last_security_check'), t('common.security_score'),
        ]);
        $emailRows = $pdo->query('SELECT e.email_address, es.twofa_status, es.passkey_status, es.security_key_status,
                es.security_questions_status, es.recovery_codes_status, es.backup_method, es.last_security_check
            FROM emails e LEFT JOIN email_security es ON es.email_id = e.id
            WHERE ' . visibilityScope('emails', 'e') . '
            ORDER BY e.email_address')->fetchAll();
        foreach ($emailRows as $r) {
            $score = calcEmailSecurityScore($r['twofa_status'] !== null ? $r : null);
            $writeRow([
                $r['email_address'],
                enumLabel($r['twofa_status'] ?? 'Not Set', SECURITY_STATES),
                enumLabel($r['passkey_status'] ?? 'Not Set', SECURITY_STATES),
                enumLabel($r['security_key_status'] ?? 'Not Set', SECURITY_STATES),
                enumLabel($r['security_questions_status'] ?? 'Not Set', SECURITY_STATES),
                enumLabel($r['recovery_codes_status'] ?? 'Not Set', SECURITY_STATES),
                $r['last_security_check'],
                $score !== null ? $score : '',
            ]);
        }

        $writeRow([]);
        $writeRow(['Account 2FA Status']);
        $writeRow([t('accounts.th_service'), t('accounts.th_identity'), t('field.twofa')]);
        $accountRows = $pdo->query("SELECT s.service_name, a.identity_type, a.username, e.email_address, p.phone_number, acs.twofa_status
            FROM accounts a
            JOIN services s ON s.id = a.service_id
            LEFT JOIN emails e ON e.id = a.email_id
            LEFT JOIN phones p ON p.id = a.identity_phone_id
            LEFT JOIN account_security acs ON acs.account_id = a.id
            WHERE " . visibilityScope('accounts', 'a') . "
            ORDER BY s.service_name")->fetchAll();
        foreach ($accountRows as $r) {
            $writeRow([$r['service_name'], $identityOf($r), enumLabel($r['twofa_status'] ?? 'Not Set', SECURITY_STATES)]);
        }

        $writeRow([]);
        $writeRow(['Needs Attention']);
        $writeRow(['Level', t('common.field_type'), 'Title', 'Issue']);
        $needsAttentionItems = getNeedsAttentionItems($pdo);
        foreach ($needsAttentionItems as $item) {
            $writeRow([
                needsAttentionLevelLabel($item['level']),
                $item['entity_type'] === 'account' ? t('nav.accounts') : t('nav.emails'),
                $item['title'],
                $item['message'],
            ]);
        }

        $exportedCount = count($emailRows) + count($accountRows) + count($needsAttentionItems);
        break;
}

logAuditEvent('export', null, null, ['entity' => $entity, 'count' => $exportedCount]);

fclose($out);
exit;
