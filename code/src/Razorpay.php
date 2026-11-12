<?php
declare(strict_types=1);

/** Minimal Razorpay REST client (Orders + Payments) — no Composer needed. */
final class Razorpay
{
    private const API = 'https://api.razorpay.com/v1';

    public function __construct(private string $keyId, private string $keySecret)
    {
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function createOrder(int $amountMinor, string $currency, string $receipt, array $notes = []): array
    {
        return $this->request('POST', '/orders', [
            'amount'          => $amountMinor,
            'currency'        => $currency,
            'receipt'         => substr($receipt, 0, 40),
            'notes'           => $notes,
            'payment_capture' => 1,
        ]);
    }

    public function fetchOrder(string $orderId): array
    {
        return $this->request('GET', '/orders/' . rawurlencode($orderId));
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->request('GET', '/payments/' . rawurlencode($paymentId));
    }

    public function capturePayment(string $paymentId, int $amountMinor, string $currency): array
    {
        return $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/capture', [
            'amount'   => $amountMinor,
            'currency' => $currency,
        ]);
    }

    /** Signature returned to checkout.js handler: HMAC_SHA256(order_id|payment_id, key_secret). */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        return hash_equals($expected, $signature);
    }

    /** Webhook signature: HMAC_SHA256(raw_body, webhook_secret). */
    public static function verifyWebhookSignature(string $body, string $signature, string $secret): bool
    {
        return hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Razorpay request failed: ' . $err);
        }
        $data = json_decode($raw, true);
        if ($code >= 400 || !is_array($data)) {
            $msg = $data['error']['description'] ?? ('HTTP ' . $code);
            throw new RuntimeException('Razorpay API error: ' . $msg);
        }
        return $data;
    }
}
