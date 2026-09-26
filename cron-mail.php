<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/mail.php';

// Same shared-secret pattern as cron-backup.php (see config.php) — an empty
// token means the token was never configured, so every request is refused
// rather than falling back to some guessable default.
if (CRON_MAIL_TOKEN === '' || !hash_equals(CRON_MAIL_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: application/json');
echo json_encode(sendQueuedMail());
