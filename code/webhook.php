<?php
/**
 * Razorpay webhook (events: payment.captured, order.paid).
 *
 * ResellerClub has no server-to-server API for custom gateways — it only learns about
 * a payment when the customer's browser posts back to redirecturl. This webhook records
 * payments whose customer closed the tab before returning, so you can reconcile them
 * (see bin/unreconciled.php) and mark those invoices paid in ResellerClub manually.
 *
 * A valid signature alone is not trusted to settle: the payment is fetched from
 * Razorpay's API with the keys of the order's own mode and checked before marking paid.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$body = file_get_contents('php://input', false, null, 0, 1024 * 1024) ?: '';
$signature = is_string($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? null) ? $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] : '';

$event = json_decode($body, true);
$type = is_array($event) ? (string) ($event['event'] ?? '') : '';
$payment = is_array($event) ? ($event['payload']['payment']['entity'] ?? null) : null;
$orderId = is_array($payment) && is_string($payment['order_id'] ?? null) ? $payment['order_id'] : '';
$txn = $orderId !== '' ? $store->findByOrderId($orderId) : null;

// Verify with the secret for the order's mode (an optional separate test secret is supported).
$mode = $txn ? gw_txn_mode($txn) : $config['mode'];
$secret = gw_webhook_secret($mode);
if ($secret === '') {
    gw_log('Webhook refused: webhook secret missing or still a placeholder', ['mode' => $mode]);
    http_response_code(503);
    exit('webhook not configured');
}
if (!Razorpay::verifyWebhookSignature($body, $signature, $secret)) {
    gw_log('Webhook signature invalid', ['mode' => $mode]);
    http_response_code(400);
    exit('invalid signature');
}

if ($txn && gw_txn_mode($txn) === 'test') {
    gw_debug_enable('webhook for a test-mode transaction');
}
gw_debug('webhook received', ['event' => $type, 'payment' => $payment['id'] ?? null, 'order' => $orderId, 'status' => $payment['status'] ?? null, 'txn' => $txn ? ['transid' => $txn['rc_transid'], 'status' => $txn['status']] : null]);

if (in_array($type, ['payment.captured', 'order.paid'], true) && is_array($payment) && is_string($payment['id'] ?? null)) {
    if (!$txn) {
        gw_log('Webhook for unknown order', ['order' => $orderId]);
    } elseif ($txn['status'] !== 'paid') {
        // Settle only on what Razorpay's API reports, using this order's own keys.
        try {
            $verified = gw_razorpay(gw_txn_mode($txn))->fetchPayment($payment['id']);
        } catch (Throwable $e) {
            gw_log('Webhook payment check failed', ['transid' => $txn['rc_transid'], 'payment' => $payment['id'], 'error' => $e->getMessage()]);
            http_response_code(503); // Razorpay retries the webhook later
            exit('retry');
        }
        $diff = gw_payment_mismatch($verified, $txn);
        gw_debug('webhook payment verified', ['status' => $verified['status'] ?? null, 'mismatch' => $diff]);
        if ($diff) {
            gw_log('Webhook payment does not match order', ['transid' => $txn['rc_transid'], 'payment' => $payment['id'], 'mismatch' => array_keys($diff), 'expected_vs_got' => $diff]);
        } elseif (($verified['status'] ?? '') === 'captured' && $store->markPaid($txn['token'], (string) $verified['id'])) {
            gw_log('Webhook marked paid', ['transid' => $txn['rc_transid'], 'payment' => $verified['id']]);
        }
    }
}

http_response_code(200);
echo 'ok';
