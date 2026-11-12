<?php
/**
 * Customer returns here from the Razorpay popup (success) or the Cancel button.
 * Verifies the payment with Razorpay, then posts the result back to ResellerClub.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_page('Invalid request', 'This page can only be reached from the payment screen.', 405);
}

$token = (string) ($_POST['token'] ?? '');
$txn = $token !== '' ? $store->findByToken($token) : null;
if (!$txn) {
    fail_page('Unknown transaction', 'We could not find this payment. If you were charged, please contact us and we will sort it out.', 404);
}

/** Post the final result to ResellerClub and mark it delivered. */
$sendBack = function (array $txn, string $status) use ($rc, $store): never {
    if ($status === ResellerClub::STATUS_SUCCESS) {
        $store->update($txn['token'], ['rc_notified' => 1]);
    }
    gw_log('Returning to ResellerClub', ['transid' => $txn['rc_transid'], 'status' => $status]);
    ResellerClub::renderRedirect(
        $txn['redirect_url'],
        $rc->buildResponse($txn['rc_transid'], $txn['selling_amount'], $txn['accounting_amount'], $status)
    );
};

// Already settled (e.g. webhook got there first, or the customer refreshed).
if ($txn['status'] === 'paid') {
    $sendBack($txn, ResellerClub::STATUS_SUCCESS);
}
if ($txn['status'] === 'failed') {
    $sendBack($txn, ResellerClub::STATUS_FAILED);
}

if (($_POST['action'] ?? '') === 'cancel') {
    if ($store->transition($token, 'created', 'failed')) {
        $sendBack($store->findByToken($token), ResellerClub::STATUS_FAILED);
    }
    // Lost the race to a successful payment — report the real state.
    $txn = $store->findByToken($token);
    $sendBack($txn, $txn['status'] === 'paid' ? ResellerClub::STATUS_SUCCESS : ResellerClub::STATUS_FAILED);
}

$paymentId = (string) ($_POST['razorpay_payment_id'] ?? '');
$orderId   = (string) ($_POST['razorpay_order_id'] ?? '');
$signature = (string) ($_POST['razorpay_signature'] ?? '');

if ($paymentId === '' || $orderId !== $txn['razorpay_order_id']
    || !$razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
    gw_log('Signature verification failed', ['transid' => $txn['rc_transid'], 'order' => $orderId, 'payment' => $paymentId]);
    fail_page('Payment not verified', 'We could not verify this payment. If you were charged, please contact us and quote reference #' . $txn['rc_transid'] . '.');
}

// Double-check with Razorpay's API rather than trusting the browser alone.
try {
    $payment = $razorpay->fetchPayment($paymentId);
    if (($payment['order_id'] ?? '') !== $txn['razorpay_order_id']
        || (int) $payment['amount'] !== (int) $txn['amount_minor']
        || strtoupper($payment['currency']) !== strtoupper($txn['currency'])) {
        throw new RuntimeException('Payment does not match order');
    }
    if ($payment['status'] === 'authorized') {
        $payment = $razorpay->capturePayment($paymentId, (int) $txn['amount_minor'], $txn['currency']);
    }
} catch (Throwable $e) {
    gw_log('Payment check failed', ['transid' => $txn['rc_transid'], 'payment' => $paymentId, 'error' => $e->getMessage()]);
    fail_page('Payment not confirmed', 'We could not confirm your payment yet. If you were charged, it will be matched up — please contact us and quote reference #' . $txn['rc_transid'] . '.', 502);
}

if (($payment['status'] ?? '') === 'captured') {
    $store->transition($token, 'created', 'paid', ['razorpay_payment_id' => $paymentId]);
    $sendBack($store->findByToken($token), ResellerClub::STATUS_SUCCESS);
}

gw_log('Payment not captured', ['transid' => $txn['rc_transid'], 'payment' => $paymentId, 'status' => $payment['status'] ?? null]);
$store->transition($token, 'created', 'failed', ['razorpay_payment_id' => $paymentId]);
$txn = $store->findByToken($token);
$sendBack($txn, $txn['status'] === 'paid' ? ResellerClub::STATUS_SUCCESS : ResellerClub::STATUS_FAILED);
