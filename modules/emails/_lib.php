<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security-score.php';

function fetchEmailById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM emails WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetchEmailSecurity(PDO $pdo, int $emailId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM email_security WHERE email_id = ? LIMIT 1');
    $stmt->execute([$emailId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsertEmailSecurity(PDO $pdo, int $emailId, array $data): void
{
    $exists = $pdo->prepare('SELECT id FROM email_security WHERE email_id = ? LIMIT 1');
    $exists->execute([$emailId]);
    $id = $exists->fetchColumn();

    $columns = [
        'twofa_status', 'twofa_method', 'passkey_status', 'security_key_status',
        'security_questions_status', 'last_security_check', 'recovery_email_id',
        'recovery_phone_id', 'recovery_codes_status', 'recovery_codes_reference',
        'backup_method', 'last_recovery_verification',
    ];

    if ($id) {
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $stmt = $pdo->prepare("UPDATE email_security SET $sets WHERE email_id = :email_id");
        $stmt->execute([...$data, 'email_id' => $emailId]);
    } else {
        $cols = implode(', ', ['email_id', ...$columns]);
        $placeholders = implode(', ', array_map(static fn ($c) => ":$c", ['email_id', ...$columns]));
        $stmt = $pdo->prepare("INSERT INTO email_security ($cols) VALUES ($placeholders)");
        $stmt->execute([...$data, 'email_id' => $emailId]);
    }
}

function calcEmailCompleteness(array $email, ?array $security): int
{
    $known = 0;
    $applicable = 0;

    foreach (['display_name', 'provider', 'purpose', 'created_date', 'last_verified', 'notes'] as $field) {
        $applicable++;
        if (!empty($email[$field])) {
            $known++;
        }
    }

    $applicable++;
    if (($email['type'] ?? 'Not Set') !== 'Not Set') {
        $known++;
    }

    $applicable++;
    if (($email['status'] ?? 'Unknown') !== 'Unknown') {
        $known++;
    }

    $securityFields = [
        'twofa_status', 'passkey_status', 'security_key_status',
        'security_questions_status', 'recovery_codes_status',
    ];
    foreach ($securityFields as $field) {
        $value = $security[$field] ?? 'Not Set';
        if ($value === 'Not Applicable') {
            continue;
        }
        $applicable++;
        if (!in_array($value, ['Not Set', 'Unknown'], true)) {
            $known++;
        }
    }

    foreach (['backup_method', 'last_security_check'] as $field) {
        $applicable++;
        if (!empty($security[$field] ?? null)) {
            $known++;
        }
    }

    if ($applicable === 0) {
        return 0;
    }

    return (int) round(($known / $applicable) * 100);
}

