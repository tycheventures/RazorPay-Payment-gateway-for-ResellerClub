<?php
/**
 * Razorpay webhook (events: payment.captured, order.paid).
 *
 * ResellerClub has no server-to-server API for custom gateways — it only learns about
 * a payment when the customer's browser posts back to redirecturl. This webhook records
 * payments whose customer closed the tab before returning, so you can reconcile them
 * (see bin/unreconciled.php) and mark those invoices paid in ResellerClub manually.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$body = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!Razorpay::verifyWebhookSignature($body, $signature, $config['razorpay_webhook_secret'])) {
    gw_log('Webhook signature invalid');
    http_response_code(400);
    exit('invalid signature');
}

$event = json_decode($body, true);
$type = $event['event'] ?? '';
$payment = $event['payload']['payment']['entity'] ?? null;
$hookTxn = !empty($payment['order_id']) ? $store->findByOrderId($payment['order_id']) : null;
if ($hookTxn && gw_txn_mode($hookTxn) === 'test') {
    gw_debug_enable('webhook for a test-mode transaction');
}
gw_debug('webhook received', ['event' => $type, 'payment' => $payment['id'] ?? null, 'order' => $payment['order_id'] ?? null, 'status' => $payment['status'] ?? null, 'amount' => $payment['amount'] ?? null, 'txn' => $hookTxn ? ['transid' => $hookTxn['rc_transid'], 'status' => $hookTxn['status']] : null]);

if (in_array($type, ['payment.captured', 'order.paid'], true) && $payment && !empty($payment['order_id'])) {
    $txn = $store->findByOrderId($payment['order_id']);
    if (!$txn) {
        gw_log('Webhook for unknown order', ['order' => $payment['order_id']]);
    } elseif (!gw_payment_amount_matches($payment, (int) $txn['amount_minor'])) {
        gw_log('Webhook amount mismatch', ['transid' => $txn['rc_transid'], 'order' => $payment['order_id'], 'expected' => (int) $txn['amount_minor'], 'amount' => $payment['amount'] ?? null, 'fee' => $payment['fee'] ?? null]);
    } elseif ($payment['status'] === 'captured') {
        // created → paid, or failed → paid if the customer hit Cancel after paying.
        if ($store->transition($txn['token'], 'created', 'paid', ['razorpay_payment_id' => $payment['id']])
            || $store->transition($txn['token'], 'failed', 'paid', ['razorpay_payment_id' => $payment['id']])) {
            gw_log('Webhook marked paid', ['transid' => $txn['rc_transid'], 'payment' => $payment['id']]);
        }
    }
}

http_response_code(200);
echo 'ok';
