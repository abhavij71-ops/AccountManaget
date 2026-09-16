<?php
declare(strict_types=1);

/**
 * NOTE: email_address and phone_number are LEFT JOINed and so can both be
 * NULL — accounts.email_id has been nullable since v1.5.0's Identity Anchor
 * model (identity_type email/phone/username/other). Every consumer of this
 * row must not assume email_address is present; see accountDisplayIdentity()
 * for the safe way to get a human-readable label regardless of anchor type.
 */
function fetchAccountById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT a.*, s.service_name, e.email_address, p.phone_number
        FROM accounts a
        JOIN services s ON s.id = a.service_id
        LEFT JOIN emails e ON e.id = a.email_id
        LEFT JOIN phones p ON p.id = a.identity_phone_id
        WHERE a.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Best-effort human-readable label for an account: username if set, else
 * whatever fetchAccountById() resolved as its identity anchor (email_address
 * for identity_type 'email', phone_number for 'phone'), else a safe
 * id-based fallback for 'other' (which has no anchor field at all) or any
 * row missing the data its own identity_type promises.
 */
function accountDisplayIdentity(array $account): string
{
    if (!empty($account['username'])) {
        return $account['username'];
    }
    $anchor = match ($account['identity_type'] ?? 'email') {
        'phone' => $account['phone_number'] ?? null,
        'other' => null,
        default => $account['email_address'] ?? null,
    };
    return $anchor ?? ('#' . $account['id']);
}

function fetchAccountSecurity(PDO $pdo, int $accountId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM account_security WHERE account_id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetchAccountRecovery(PDO $pdo, int $accountId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM account_recovery WHERE account_id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetchSubscription(PDO $pdo, int $accountId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE account_id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetchPayment(PDO $pdo, int $accountId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE account_id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsertSubscription(PDO $pdo, int $accountId, array $data): void
{
    $exists = $pdo->prepare('SELECT id FROM subscriptions WHERE account_id = ? LIMIT 1');
    $exists->execute([$accountId]);
    $id = $exists->fetchColumn();

    $columns = [
        'type', 'plan', 'status', 'price', 'currency', 'billing_cycle',
        'start_date', 'renewal_date', 'auto_renewal',
    ];

    if ($id) {
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $stmt = $pdo->prepare("UPDATE subscriptions SET $sets WHERE account_id = :account_id");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    } else {
        $cols = implode(', ', ['account_id', ...$columns]);
        $placeholders = implode(', ', array_map(static fn ($c) => ":$c", ['account_id', ...$columns]));
        $stmt = $pdo->prepare("INSERT INTO subscriptions ($cols) VALUES ($placeholders)");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    }
}

function upsertPayment(PDO $pdo, int $accountId, array $data): void
{
    $exists = $pdo->prepare('SELECT id FROM payments WHERE account_id = ? LIMIT 1');
    $exists->execute([$accountId]);
    $id = $exists->fetchColumn();

    $columns = [
        'payment_required', 'payment_method', 'card_brand', 'last4',
        'payment_reference', 'auto_renewal',
    ];

    if ($id) {
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $stmt = $pdo->prepare("UPDATE payments SET $sets WHERE account_id = :account_id");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    } else {
        $cols = implode(', ', ['account_id', ...$columns]);
        $placeholders = implode(', ', array_map(static fn ($c) => ":$c", ['account_id', ...$columns]));
        $stmt = $pdo->prepare("INSERT INTO payments ($cols) VALUES ($placeholders)");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    }
}

function upsertAccountSecurity(PDO $pdo, int $accountId, array $data): void
{
    $exists = $pdo->prepare('SELECT id FROM account_security WHERE account_id = ? LIMIT 1');
    $exists->execute([$accountId]);
    $id = $exists->fetchColumn();

    $columns = [
        'twofa_status', 'twofa_method', 'passkey_status', 'security_key_status',
        'security_questions_status', 'last_security_check', 'credential_storage',
        'credential_reference',
    ];

    if ($id) {
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $stmt = $pdo->prepare("UPDATE account_security SET $sets WHERE account_id = :account_id");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    } else {
        $cols = implode(', ', ['account_id', ...$columns]);
        $placeholders = implode(', ', array_map(static fn ($c) => ":$c", ['account_id', ...$columns]));
        $stmt = $pdo->prepare("INSERT INTO account_security ($cols) VALUES ($placeholders)");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    }
}

function upsertAccountRecovery(PDO $pdo, int $accountId, array $data): void
{
    $exists = $pdo->prepare('SELECT id FROM account_recovery WHERE account_id = ? LIMIT 1');
    $exists->execute([$accountId]);
    $id = $exists->fetchColumn();

    $columns = [
        'status', 'recovery_email_id', 'recovery_phone_id', 'recovery_contact',
        'recovery_codes_status', 'recovery_codes_reference', 'backup_method',
        'last_recovery_verification', 'recovery_notes',
    ];

    if ($id) {
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $stmt = $pdo->prepare("UPDATE account_recovery SET $sets WHERE account_id = :account_id");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    } else {
        $cols = implode(', ', ['account_id', ...$columns]);
        $placeholders = implode(', ', array_map(static fn ($c) => ":$c", ['account_id', ...$columns]));
        $stmt = $pdo->prepare("INSERT INTO account_recovery ($cols) VALUES ($placeholders)");
        $stmt->execute(array_merge($data, ['account_id' => $accountId]));
    }
}

function calcAccountCompleteness(array $account, ?array $security, ?array $recovery): int
{
    $known = 0;
    $applicable = 0;

    foreach (['display_name', 'external_account_id', 'account_url', 'login_url', 'created_date', 'last_login', 'last_verified', 'notes'] as $field) {
        $applicable++;
        if (!empty($account[$field])) {
            $known++;
        }
    }

    $applicable++;
    if (($account['account_type'] ?? 'Not Set') !== 'Not Set') {
        $known++;
    }

    $applicable++;
    if (($account['status'] ?? 'Unknown') !== 'Unknown') {
        $known++;
    }

    foreach (['twofa_status', 'passkey_status', 'security_key_status', 'security_questions_status'] as $field) {
        $value = $security[$field] ?? 'Not Set';
        if ($value === 'Not Applicable') {
            continue;
        }
        $applicable++;
        if (!in_array($value, ['Not Set', 'Unknown'], true)) {
            $known++;
        }
    }
    foreach (['twofa_method', 'last_security_check', 'credential_storage', 'credential_reference'] as $field) {
        $applicable++;
        if (!empty($security[$field] ?? null)) {
            $known++;
        }
    }

    $recoveryStatus = $recovery['status'] ?? 'Not Set';
    if ($recoveryStatus !== 'Not Applicable') {
        $applicable++;
        if (!in_array($recoveryStatus, ['Not Set', 'Unknown'], true)) {
            $known++;
        }
    }
    $codesStatus = $recovery['recovery_codes_status'] ?? 'Not Set';
    if ($codesStatus !== 'Not Applicable') {
        $applicable++;
        if (!in_array($codesStatus, ['Not Set', 'Unknown'], true)) {
            $known++;
        }
    }
    foreach (['recovery_email_id', 'recovery_phone_id', 'recovery_contact', 'recovery_codes_reference', 'backup_method', 'last_recovery_verification', 'recovery_notes'] as $field) {
        $applicable++;
        if (!empty($recovery[$field] ?? null)) {
            $known++;
        }
    }

    if ($applicable === 0) {
        return 0;
    }

    return (int) round(($known / $applicable) * 100);
}
