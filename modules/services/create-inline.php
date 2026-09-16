<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => t('msg.invalid_request')]);
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => t('msg.invalid_request')]);
    exit;
}

$serviceName = trim((string) ($_POST['service_name'] ?? ''));

if ($serviceName === '') {
    http_response_code(422);
    echo json_encode(['error' => t('services.name_required')]);
    exit;
}

$pdo = db();

try {
    $stmt = $pdo->prepare('INSERT INTO services (service_name) VALUES (?)');
    $stmt->execute([$serviceName]);
    $newId = (int) $pdo->lastInsertId();
    log_history($pdo, 'service', $newId, 'Service Created');

    echo json_encode(['id' => $newId, 'name' => $serviceName]);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        http_response_code(409);
        echo json_encode(['error' => t('services.duplicate_name')]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => t('services.create_error') . $e->getMessage()]);
    }
}
