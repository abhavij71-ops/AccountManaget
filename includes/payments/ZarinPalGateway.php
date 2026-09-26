<?php
declare(strict_types=1);

require_once __DIR__ . '/../secrets.php';

/**
 * ZarinPal (https://www.zarinpal.com) payment gateway v4 API over a plain
 * cURL call — no SDK, matching the raw-cURL/raw-socket style already used
 * elsewhere in this app (Kavenegar SMS, the SMTP sender). The merchant ID
 * is never hardcoded — see includes/secrets.php's loadSecret().
 */
class ZarinPalGateway
{
    private const REQUEST_URL = 'https://api.zarinpal.com/pg/v4/payment/request.json';
    private const VERIFY_URL = 'https://api.zarinpal.com/pg/v4/payment/verify.json';
    private const STARTPAY_URL = 'https://www.zarinpal.com/pg/StartPay/';

    private readonly string $merchantId;

    public function __construct()
    {
        $this->merchantId = loadSecret('ZARINPAL_MERCHANT_ID');
    }

    /**
     * @return array{authority:string,pay_url:string}
     */
    public function requestPayment(int $amountRials, string $description, string $callbackUrl): array
    {
        if ($this->merchantId === '') {
            throw new RuntimeException('ZarinPal: no merchant ID configured.');
        }

        $response = $this->post(self::REQUEST_URL, [
            'merchant_id' => $this->merchantId,
            'amount' => $amountRials,
            'description' => $description,
            'callback_url' => $callbackUrl,
        ]);

        $code = (int) ($response['data']['code'] ?? 0);
        $authority = (string) ($response['data']['authority'] ?? '');
        if ($code !== 100 || $authority === '') {
            throw new RuntimeException('ZarinPal: payment request failed (code ' . $code . '): ' . $this->errorMessage($response));
        }

        return ['authority' => $authority, 'pay_url' => self::STARTPAY_URL . $authority];
    }

    /**
     * @return array{ref_id:string}
     */
    public function verifyPayment(int $amountRials, string $authority): array
    {
        if ($this->merchantId === '') {
            throw new RuntimeException('ZarinPal: no merchant ID configured.');
        }

        $response = $this->post(self::VERIFY_URL, [
            'merchant_id' => $this->merchantId,
            'amount' => $amountRials,
            'authority' => $authority,
        ]);

        $code = (int) ($response['data']['code'] ?? 0);
        // 100 = freshly verified, 101 = already verified on an earlier call
        // — ZarinPal treats both as a genuinely settled payment.
        if ($code !== 100 && $code !== 101) {
            throw new RuntimeException('ZarinPal: verification failed (code ' . $code . '): ' . $this->errorMessage($response));
        }

        return ['ref_id' => (string) ($response['data']['ref_id'] ?? '')];
    }

    private function post(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("ZarinPal: cURL error ({$errno}): {$error}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('ZarinPal: malformed response.');
        }
        return $decoded;
    }

    private function errorMessage(array $response): string
    {
        $errors = $response['errors'] ?? null;
        if (is_array($errors) && isset($errors['message'])) {
            return (string) $errors['message'];
        }
        return (string) ($response['data']['message'] ?? 'unknown error');
    }
}
