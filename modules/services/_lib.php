<?php
declare(strict_types=1);

function fetchServiceDefaults(PDO $pdo, int $serviceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM service_defaults WHERE service_id = ? LIMIT 1');
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * True when the service has at least one usable template value configured —
 * used to decide whether "Apply defaults to existing accounts" has anything
 * to offer at all, since an empty template has nothing to apply.
 */
function serviceHasDefaultsTemplate(array $defaults): bool
{
    if (!empty($defaults['recovery_follows_identity'])) {
        return true;
    }
    foreach ($defaults as $key => $value) {
        if (str_starts_with($key, 'default_') && $value !== null && $value !== '') {
            return true;
        }
    }
    return false;
}

function upsertServiceDefaults(PDO $pdo, int $serviceId, array $data): void
{
    $exists = $pdo->prepare('SELECT id FROM service_defaults WHERE service_id = ? LIMIT 1');
    $exists->execute([$serviceId]);
    $id = $exists->fetchColumn();

    $columns = [
        'default_identity_type', 'default_twofa_status', 'default_twofa_method',
        'default_passkey_status', 'default_security_questions_status', 'default_recovery_status',
        'recovery_follows_identity', 'default_subscription_type', 'default_subscription_status',
        'default_billing_cycle', 'default_currency',
    ];

    if ($id) {
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $stmt = $pdo->prepare("UPDATE service_defaults SET $sets WHERE service_id = :service_id");
        $stmt->execute(array_merge($data, ['service_id' => $serviceId]));
    } else {
        $cols = implode(', ', ['service_id', ...$columns]);
        $placeholders = implode(', ', array_map(static fn ($c) => ":$c", ['service_id', ...$columns]));
        $stmt = $pdo->prepare("INSERT INTO service_defaults ($cols) VALUES ($placeholders)");
        $stmt->execute(array_merge($data, ['service_id' => $serviceId]));
    }
}
