<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationChannel.php';
require_once __DIR__ . '/../mail.php';

/**
 * Thin adapter over queueMail() — mail is always queued for the SMTP
 * sender to pick up later, never sent synchronously, same as every other
 * caller of includes/mail.php.
 */
class EmailChannel implements NotificationChannel
{
    public function send(string $recipient, string $subject, string $message): bool
    {
        queueMail($recipient, $subject, $message);
        return true;
    }
}
