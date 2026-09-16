<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/_lib.php';

requireLogin();

header('Content-Type: application/json');

$serviceId = (int) ($_GET['service_id'] ?? 0);
if ($serviceId <= 0) {
    http_response_code(400);
    echo json_encode(['defaults' => null]);
    exit;
}

$defaults = fetchServiceDefaults(db(), $serviceId);

echo json_encode(['defaults' => $defaults ? [
    'default_identity_type' => $defaults['default_identity_type'],
    'default_twofa_status' => $defaults['default_twofa_status'],
    'default_twofa_method' => $defaults['default_twofa_method'],
    'default_passkey_status' => $defaults['default_passkey_status'],
    'default_security_questions_status' => $defaults['default_security_questions_status'],
    'default_recovery_status' => $defaults['default_recovery_status'],
    'recovery_follows_identity' => (int) $defaults['recovery_follows_identity'],
    'default_subscription_type' => $defaults['default_subscription_type'],
    'default_subscription_status' => $defaults['default_subscription_status'],
    'default_billing_cycle' => $defaults['default_billing_cycle'],
    'default_currency' => $defaults['default_currency'],
] : null]);
