<?php
declare(strict_types=1);

/**
 * TOTP (RFC 6238) over HOTP (RFC 4226), implemented directly — this project
 * has no Composer, so no external library. SHA-1, 6 digits, 30-second step,
 * matching every mainstream authenticator app's defaults.
 */

const TOTP_PERIOD_SECONDS = 30;
const TOTP_DIGITS = 6;
const TOTP_VERIFY_WINDOW_STEPS = 1; // accepts the previous/current/next 30s step, for clock skew

/**
 * RFC 4648 Base32 encode, padded to a multiple of 8 chars with '='. This is
 * the encoding otpauth:// secrets and every authenticator app expect.
 */
function base32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $output .= $alphabet[bindec($chunk)];
    }

    $padLength = (8 - (strlen($output) % 8)) % 8;
    return $output . str_repeat('=', $padLength);
}

/**
 * Inverse of base32Encode(). Tolerant of lowercase input and missing/extra
 * padding — trailing bits shorter than a full byte are discarded, per spec.
 */
function base32Decode(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = preg_replace('/[^A-Z2-7]/', '', strtoupper(trim($base32)));

    $bits = '';
    foreach (str_split($clean) as $char) {
        $value = strpos($alphabet, $char);
        if ($value === false) {
            continue;
        }
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }

    $bytes = '';
    foreach (str_split($bits, 8) as $byteBits) {
        if (strlen($byteBits) < 8) {
            break;
        }
        $bytes .= chr(bindec($byteBits));
    }
    return $bytes;
}

/**
 * A fresh 160-bit (20-byte) shared secret, Base32-encoded — the standard
 * secret length for SHA-1 TOTP.
 */
function generateTotpSecret(): string
{
    return base32Encode(random_bytes(20));
}

/**
 * HOTP per RFC 4226: HMAC-SHA1 of the 8-byte big-endian counter, dynamic
 * truncation, mod 10^digits, zero-padded.
 */
function hotp(string $secretBase32, int $counter, int $digits = TOTP_DIGITS): string
{
    $key = base32Decode($secretBase32);
    // 'N*' with two values packs two 32-bit big-endian words = 8 bytes; the
    // high word is always 0 here since $counter (unix time / 30) won't reach
    // 2^32 for millennia.
    $counterBytes = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $counterBytes, $key, true);

    $offset = ord($hash[19]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    $otp = $binary % (10 ** $digits);
    return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
}

/**
 * The TOTP code for a given secret at a given time — RFC 6238's HOTP with
 * counter = floor(timestamp / period).
 */
function totpAt(string $secretBase32, int $timestamp, int $period = TOTP_PERIOD_SECONDS): string
{
    return hotp($secretBase32, intdiv($timestamp, $period));
}

/**
 * Verifies a submitted 6-digit code against the current time step and
 * TOTP_VERIFY_WINDOW_STEPS steps on either side (clock skew tolerance).
 * hash_equals() throughout so a mistyped digit can't be distinguished by
 * timing from a correct one.
 */
function verifyTotp(string $secretBase32, string $code): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $currentCounter = intdiv(time(), TOTP_PERIOD_SECONDS);
    for ($i = -TOTP_VERIFY_WINDOW_STEPS; $i <= TOTP_VERIFY_WINDOW_STEPS; $i++) {
        if (hash_equals(hotp($secretBase32, $currentCounter + $i), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * The otpauth:// URI most authenticator apps can import directly (by QR or,
 * in some apps, a pasted link) — shown as text per this task's explicit
 * alternative to building a QR encoder from scratch. A hand-rolled QR
 * encoder (Reed-Solomon ECC, module placement, format/version info) is easy
 * to get subtly wrong in ways only a real scanner would catch, which isn't
 * verifiable in this environment — the secret and this URI are both
 * plain, universally-supported fallbacks every authenticator app accepts
 * for manual entry.
 */
function buildOtpauthUri(string $issuer, string $accountLabel, string $secretBase32): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
    $query = http_build_query([
        'secret' => $secretBase32,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => TOTP_DIGITS,
        'period' => TOTP_PERIOD_SECONDS,
    ]);
    return 'otpauth://totp/' . $label . '?' . $query;
}

/**
 * Ten single-use recovery codes as plain text — the only time they ever
 * exist in that form. Callers must hash each with hashRecoveryCode() before
 * storing and show the plain values to the user exactly once.
 */
function generateRecoveryCodes(int $count = 10): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = bin2hex(random_bytes(5)); // 10 hex chars = 40 bits, plenty for a single-use code
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return $codes;
}

/**
 * Recovery codes are hashed with the same password_hash()/PASSWORD_DEFAULT
 * used for account passwords elsewhere in this app — deliberately not a
 * fast general-purpose hash, since a leaked recovery-code table shouldn't
 * be trivially crackable either.
 */
function hashRecoveryCode(string $code): string
{
    return password_hash(strtolower(trim($code)), PASSWORD_DEFAULT);
}

function verifyRecoveryCodeHash(string $code, string $hash): bool
{
    return password_verify(strtolower(trim($code)), $hash);
}
