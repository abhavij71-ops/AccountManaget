<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/notifications/NotificationChannel.php';
require_once __DIR__ . '/notifications/EmailChannel.php';
require_once __DIR__ . '/notifications/KavenegarSmsChannel.php';

// Severities allowed to go out over SMS — spec: "Only Critical alerts and
// upcoming renewals go out by SMS, not everything." Anything else (the
// 'normal' default) is email-only regardless of the user's channel choice.
const SMS_ELIGIBLE_SEVERITIES = ['critical', 'renewal'];

function ensureNotificationPreferencesTable(PDO $platform): void
{
    $platform->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
        user_id INTEGER PRIMARY KEY REFERENCES accounts_users(id) ON DELETE CASCADE,
        channel TEXT NOT NULL DEFAULT 'email' CHECK (channel IN ('email', 'sms', 'both')),
        phone_number TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
}

/**
 * @return array{channel:string,phone_number:string} defaults to 'email'
 *     with no phone number when the user has never set a preference —
 *     the same delivery behavior the app already had before this feature.
 */
function getNotificationPreference(int $userId): array
{
    $platform = platformDb();
    ensureNotificationPreferencesTable($platform);
    $stmt = $platform->prepare('SELECT channel, phone_number FROM notification_preferences WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'channel' => $row['channel'] ?? 'email',
        'phone_number' => (string) ($row['phone_number'] ?? ''),
    ];
}

function saveNotificationPreference(int $userId, string $channel, string $phoneNumber): void
{
    $platform = platformDb();
    ensureNotificationPreferencesTable($platform);
    $platform->prepare(
        "INSERT INTO notification_preferences (user_id, channel, phone_number, updated_at) VALUES (?, ?, ?, datetime('now'))
         ON CONFLICT(user_id) DO UPDATE SET channel = excluded.channel, phone_number = excluded.phone_number, updated_at = excluded.updated_at"
    )->execute([$userId, $channel, $phoneNumber]);
}

/**
 * Builds the SMS channel from the account-wide Kavenegar settings, or null
 * if no API key has been configured yet on settings.php.
 */
function buildSmsChannel(): ?NotificationChannel
{
    $apiKeyEncrypted = getAppSetting('sms_api_key_encrypted');
    if ($apiKeyEncrypted === '') {
        return null;
    }
    return new KavenegarSmsChannel(decryptSecret($apiKeyEncrypted), getAppSetting('sms_sender_number'));
}

/**
 * Delivers one notification to one user according to their saved channel
 * preference, gating SMS on $severity (see SMS_ELIGIBLE_SEVERITIES above).
 * Email has no such gate — it goes out whenever the preference includes it,
 * for every severity.
 *
 * @param string $severity 'critical', 'renewal', or 'normal' (default)
 * @return array<string,bool> which channels were attempted and whether
 *     each one succeeded — callers that don't care can ignore the result
 */
function notifyUser(int $userId, string $email, string $subject, string $message, string $severity = 'normal'): array
{
    $preference = getNotificationPreference($userId);
    $channel = $preference['channel'];
    $results = [];

    if ($channel === 'email' || $channel === 'both') {
        $results['email'] = (new EmailChannel())->send($email, $subject, $message);
    }

    $wantsSms = $channel === 'sms' || $channel === 'both';
    if ($wantsSms && in_array($severity, SMS_ELIGIBLE_SEVERITIES, true)) {
        if ($preference['phone_number'] === '') {
            $results['sms'] = false;
        } else {
            try {
                $sms = buildSmsChannel();
                $results['sms'] = $sms !== null && $sms->send($preference['phone_number'], $subject, $message);
            } catch (Throwable $e) {
                error_log('Account Manager: SMS notification failed for user ' . $userId . ': ' . $e->getMessage());
                $results['sms'] = false;
            }
        }
    }

    return $results;
}
