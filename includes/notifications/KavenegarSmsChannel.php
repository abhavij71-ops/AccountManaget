<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationChannel.php';

/**
 * Kavenegar (https://kavenegar.com) REST API over a plain cURL call — no
 * SDK, no Composer, matching how includes/mail.php's SMTP sender is a raw
 * socket rather than a library. Iranian/Iraqi markets: SMS reaches users
 * far more reliably than email there, which is why this exists at all.
 *
 * The API key and sender line are account-wide settings (app_settings:
 * sms_api_key_encrypted / sms_sender_number, configured on settings.php),
 * not per-user — one Kavenegar account serves the whole install. The key
 * is encrypted at rest with the same encryptSecret()/decryptSecret() pair
 * includes/mail.php uses for the SMTP password.
 */
class KavenegarSmsChannel implements NotificationChannel
{
    public function __construct(private readonly string $apiKey, private readonly string $senderNumber)
    {
    }

    public function send(string $recipient, string $subject, string $message): bool
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Kavenegar: no API key configured.');
        }

        // Kavenegar has no separate subject field — fold it into the body
        // instead of silently dropping it.
        $text = $subject !== '' ? $subject . "\n" . $message : $message;

        $fields = ['receptor' => $recipient, 'message' => $text];
        if ($this->senderNumber !== '') {
            $fields['sender'] = $this->senderNumber;
        }

        $ch = curl_init('https://api.kavenegar.com/v1/' . rawurlencode($this->apiKey) . '/sms/send.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Kavenegar: cURL error ({$errno}): {$error}");
        }

        $decoded = json_decode((string) $response, true);
        // Kavenegar's own success code lives in the JSON body (200), not
        // just the HTTP status line — a 200 HTTP response can still carry a
        // failed return.status.
        $apiStatus = (int) ($decoded['return']['status'] ?? 0);
        if ($httpCode !== 200 || $apiStatus !== 200) {
            $apiMessage = (string) ($decoded['return']['message'] ?? $response);
            throw new RuntimeException("Kavenegar: send failed (HTTP {$httpCode}, status {$apiStatus}): {$apiMessage}");
        }

        return true;
    }
}
