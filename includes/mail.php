<?php
declare(strict_types=1);

require_once __DIR__ . '/platform-db.php';
require_once __DIR__ . '/secrets.php';

/**
 * Writes a message to mail_queue and returns immediately — this is the only
 * way the rest of the app should ever "send" mail. Actual delivery happens
 * later, out of the request cycle, via sendQueuedMail().
 */
function queueMail(string $to, string $subject, string $bodyHtml): void
{
    platformDb()->prepare(
        'INSERT INTO mail_queue (to_address, subject, body_html) VALUES (?, ?, ?)'
    )->execute([$to, $subject, $bodyHtml]);
}

function getAppSetting(string $key, string $default = ''): string
{
    $stmt = platformDb()->prepare('SELECT value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setAppSetting(string $key, string $value): void
{
    platformDb()->prepare(
        'INSERT INTO app_settings (setting_key, value) VALUES (?, ?)
         ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value'
    )->execute([$key, $value]);
}

/**
 * AES-256-CBC with a random IV per call, key derived from MAIL_ENCRYPTION_KEY
 * (config.php, read from the environment — never hardcoded). Throws rather
 * than falling back to storing plain text if that key isn't configured.
 */
function encryptSecret(string $plaintext): string
{
    $key = mailEncryptionKey();
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = random_bytes($ivLength);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new RuntimeException('Failed to encrypt secret.');
    }
    return base64_encode($iv . $ciphertext);
}

function decryptSecret(string $encoded): string
{
    $key = mailEncryptionKey();
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= $ivLength) {
        throw new RuntimeException('Malformed encrypted secret.');
    }
    $iv = substr($raw, 0, $ivLength);
    $ciphertext = substr($raw, $ivLength);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) {
        throw new RuntimeException('Failed to decrypt secret.');
    }
    return $plaintext;
}

function mailEncryptionKey(): string
{
    $raw = loadSecret('MAIL_ENCRYPTION_KEY');
    if ($raw === '') {
        throw new RuntimeException('MAIL_ENCRYPTION_KEY is not configured; refusing to handle an SMTP password.');
    }
    return hash('sha256', $raw, true);
}

/**
 * @return array{smtp_host:string,smtp_port:string,smtp_username:string,smtp_encryption:string,smtp_from_address:string,smtp_from_name:string,smtp_password_encrypted:string}
 */
function getSmtpSettings(): array
{
    return [
        'smtp_host' => getAppSetting('smtp_host'),
        'smtp_port' => getAppSetting('smtp_port', '587'),
        'smtp_username' => getAppSetting('smtp_username'),
        'smtp_encryption' => getAppSetting('smtp_encryption', 'starttls'),
        'smtp_from_address' => getAppSetting('smtp_from_address'),
        'smtp_from_name' => getAppSetting('smtp_from_name'),
        'smtp_password_encrypted' => getAppSetting('smtp_password_encrypted'),
    ];
}

/**
 * $newPassword is null when the caller isn't changing it (leaves the
 * previously stored encrypted password untouched — a blank field on the
 * settings form must never wipe out a working password).
 */
function saveSmtpSettings(array $settings, ?string $newPassword): void
{
    foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'smtp_from_address', 'smtp_from_name'] as $key) {
        setAppSetting($key, (string) ($settings[$key] ?? ''));
    }
    if ($newPassword !== null && $newPassword !== '') {
        setAppSetting('smtp_password_encrypted', encryptSecret($newPassword));
    }
}

/**
 * Sends up to $limit pending mail_queue rows over a raw SMTP socket with
 * STARTTLS — deliberately not PHP's mail() (spec: it lands in spam on most
 * hosts because it shells out to a local MTA with no real envelope
 * control). Each row is attempted independently; a failure only affects
 * that row (attempts incremented, last_error recorded, retried later up to
 * a cap) and never stops the batch.
 *
 * @return array{sent:int,failed:int,skipped_reason:?string}
 */
function sendQueuedMail(int $limit = 20): array
{
    $smtp = getSmtpSettings();
    if ($smtp['smtp_host'] === '' || $smtp['smtp_from_address'] === '') {
        return ['sent' => 0, 'failed' => 0, 'skipped_reason' => 'SMTP is not configured yet.'];
    }

    $pdo = platformDb();
    $stmt = $pdo->prepare("SELECT * FROM mail_queue WHERE status = 'pending' AND attempts < 5 ORDER BY id ASC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $sent = 0;
    $failed = 0;
    foreach ($stmt->fetchAll() as $row) {
        try {
            smtpSendMessage($smtp, (string) $row['to_address'], (string) $row['subject'], (string) $row['body_html']);
            $pdo->prepare("UPDATE mail_queue SET status = 'sent', sent_at = datetime('now') WHERE id = ?")
                ->execute([$row['id']]);
            $sent++;
        } catch (Throwable $e) {
            $attempts = (int) $row['attempts'] + 1;
            $status = $attempts >= 5 ? 'failed' : 'pending';
            $pdo->prepare('UPDATE mail_queue SET attempts = ?, status = ?, last_error = ? WHERE id = ?')
                ->execute([$attempts, $status, $e->getMessage(), $row['id']]);
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped_reason' => null];
}

/**
 * One SMTP conversation over a plain fsockopen() socket: connect, EHLO,
 * STARTTLS + re-EHLO, AUTH LOGIN, MAIL FROM/RCPT TO/DATA, QUIT. Throws
 * RuntimeException on the first unexpected reply code.
 */
function smtpSendMessage(array $smtp, string $to, string $subject, string $bodyHtml): void
{
    $host = (string) $smtp['smtp_host'];
    $port = (int) $smtp['smtp_port'];
    $username = (string) $smtp['smtp_username'];
    $encryption = (string) $smtp['smtp_encryption'];
    $fromAddress = (string) $smtp['smtp_from_address'];
    $fromName = (string) $smtp['smtp_from_name'];
    $password = $smtp['smtp_password_encrypted'] !== '' ? decryptSecret($smtp['smtp_password_encrypted']) : '';

    $socket = @fsockopen($host, $port, $errno, $errstr, 15);
    if ($socket === false) {
        throw new RuntimeException("SMTP: could not connect to {$host}:{$port} ({$errstr})");
    }
    stream_set_timeout($socket, 15);

    try {
        smtpReadResponse($socket); // 220 greeting

        $helo = $_SERVER['SERVER_NAME'] ?? 'localhost';
        smtpCommand($socket, "EHLO {$helo}", 250);

        if ($encryption === 'starttls') {
            smtpCommand($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP: STARTTLS negotiation failed.');
            }
            // The TLS session resets everything the server told us in the
            // plaintext EHLO — it must be repeated once encrypted.
            smtpCommand($socket, "EHLO {$helo}", 250);
        }

        if ($username !== '') {
            smtpCommand($socket, 'AUTH LOGIN', 334);
            // Logged as fixed labels, never the raw command — that command
            // IS the base64'd username/password, and failures get stored in
            // mail_queue.last_error, which is not itself encrypted.
            smtpCommand($socket, base64_encode($username), 334, 'AUTH LOGIN (username)');
            smtpCommand($socket, base64_encode($password), 235, 'AUTH LOGIN (password)');
        }

        smtpCommand($socket, "MAIL FROM:<{$fromAddress}>", 250);
        smtpCommand($socket, "RCPT TO:<{$to}>", [250, 251]);
        smtpCommand($socket, 'DATA', 354);

        $fromHeader = $fromName !== '' ? "{$fromName} <{$fromAddress}>" : $fromAddress;
        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . mailEncodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Date: ' . date('r'),
        ];
        // Dot-stuff any body line that starts with a lone "." — SMTP's DATA
        // terminator is a line containing just ".", so an unescaped one
        // would truncate the message right there.
        $escapedBody = preg_replace('/^\./m', '..', $bodyHtml);
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody;

        fwrite($socket, $message . "\r\n.\r\n");
        [$code, $response] = smtpReadResponse($socket);
        if ($code !== 250) {
            throw new RuntimeException("SMTP: message rejected: {$response}");
        }

        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}

function mailEncodeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * @return array{0:int,1:string} the final reply code and the full (possibly
 *     multi-line) response text
 */
function smtpReadResponse($socket): array
{
    $code = null;
    $lines = [];
    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = $line;
        $code = (int) substr($line, 0, 3);
        // A multi-line SMTP reply uses "-" after the code on every line but
        // the last, e.g. "250-SIZE" then "250 HELP".
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    if ($code === null) {
        throw new RuntimeException('SMTP: connection closed unexpectedly.');
    }
    return [$code, implode('', $lines)];
}

function smtpCommand($socket, string $command, int|array $expectedCodes, ?string $logAs = null): string
{
    fwrite($socket, $command . "\r\n");
    [$code, $response] = smtpReadResponse($socket);
    $ok = is_array($expectedCodes) ? in_array($code, $expectedCodes, true) : $code === $expectedCodes;
    if (!$ok) {
        throw new RuntimeException('SMTP command failed (' . ($logAs ?? $command) . "): {$response}");
    }
    return $response;
}
