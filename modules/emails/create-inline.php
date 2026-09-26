<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

header('Content-Type: application/json; charset=UTF-8');

if (!canWrite()) {
    http_response_code(403);
    echo json_encode(['error' => 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.']);
    exit;
}

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

$emailAddress = trim((string) ($_POST['email_address'] ?? ''));

if ($emailAddress === '' || !filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => t('emails.email_invalid')]);
    exit;
}

$pdo = db();

try {
    $stmt = $pdo->prepare('INSERT INTO emails (email_address, owner_user_id) VALUES (?, ?)');
    $stmt->execute([$emailAddress, currentUserId()]);
    $newId = (int) $pdo->lastInsertId();
    log_history($pdo, 'email', $newId, 'Email Created');

    echo json_encode(['id' => $newId, 'address' => $emailAddress]);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        http_response_code(409);
        echo json_encode(['error' => t('emails.duplicate_address')]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => t('emails.create_error') . $e->getMessage()]);
    }
}
