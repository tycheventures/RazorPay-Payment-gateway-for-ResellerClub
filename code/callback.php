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

$token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
$txn = $token !== '' ? $store->findByToken($token) : null;
if (!$txn) {
    fail_page('Unknown transaction', 'We could not find this payment. If you were charged, please contact us and we will sort it out.', 404);
}
$txnMode = gw_txn_mode($txn);
$GLOBALS['gw_test_banner'] = $txnMode === 'test';
if ($txnMode === 'test') {
    gw_debug_enable('test-mode transaction');
}
gw_debug('callback received', [
    'action' => $_POST['action'] ?? null,
    'attempt' => $_POST['attempt'] ?? null,
    'payment' => $_POST['razorpay_payment_id'] ?? null,
    'order' => $_POST['razorpay_order_id'] ?? null,
    'signature_present' => !empty($_POST['razorpay_signature']),
    'txn' => ['transid' => $txn['rc_transid'], 'status' => $txn['status'], 'order' => $txn['razorpay_order_id'], 'amount_minor' => $txn['amount_minor'], 'currency' => $txn['currency'], 'mode' => $txnMode],
]);

/** Post the final result to ResellerClub. A success is only signed inside the test/live boundary. */
$sendBack = function (array $txn, string $status) use ($rc, $store): never {
    if ($status === ResellerClub::STATUS_SUCCESS && !gw_may_send_success($txn)) {
        gw_log('Blocked success outside the test boundary', ['transid' => $txn['rc_transid'], 'mode' => gw_txn_mode($txn)]);
        fail_page('Payment not confirmed', 'This test payment cannot be confirmed for this order. Please contact us and quote reference #' . $txn['rc_transid'] . '.', 409);
    }
    gw_debug('returning to ResellerClub', ['transid' => $txn['rc_transid'], 'status' => $status, 'return_host' => parse_url($txn['redirect_url'], PHP_URL_HOST)]);
    if ($status === ResellerClub::STATUS_SUCCESS) {
        $store->markResultShown($txn['token']);
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
if ($txn['status'] === 'failed' || $txn['status'] === 'superseded') {
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

try {
    $razorpay = gw_razorpay($txnMode);
} catch (Throwable $e) {
    gw_log('Missing keys for transaction mode', ['transid' => $txn['rc_transid'], 'mode' => $txnMode]);
    fail_page('Payment not confirmed', 'We could not confirm this payment right now. If you were charged, please contact us and quote reference #' . $txn['rc_transid'] . '.', 503);
}

$paymentId = is_string($_POST['razorpay_payment_id'] ?? null) ? $_POST['razorpay_payment_id'] : '';
$orderId   = is_string($_POST['razorpay_order_id'] ?? null) ? $_POST['razorpay_order_id'] : '';
$signature = is_string($_POST['razorpay_signature'] ?? null) ? $_POST['razorpay_signature'] : '';

$sigOk = $paymentId !== '' && $orderId === $txn['razorpay_order_id'] && $razorpay->verifyPaymentSignature($orderId, $paymentId, $signature);
gw_debug('signature check', ['ok' => $sigOk, 'order_matches' => $orderId === $txn['razorpay_order_id'], 'payment' => $paymentId]);
if (!$sigOk) {
    gw_log('Signature verification failed', ['transid' => $txn['rc_transid'], 'payment' => substr($paymentId, 0, 40)]);
    fail_page('Payment not verified', 'We could not verify this payment. If you were charged, please contact us and quote reference #' . $txn['rc_transid'] . '.');
}

// Double-check with Razorpay's API rather than trusting the browser alone.
// Razorpay may still be auto-capturing, so re-check a few times before giving up.
$attempt = max(0, min(50, (int) ($_POST['attempt'] ?? 0)));
$lastStatus = null;
for ($i = 0; $i < 4; $i++) {
    if ($i > 0) {
        sleep(2);
    }
    try {
        $payment = $razorpay->fetchPayment($paymentId);
    } catch (Throwable $e) {
        gw_log('Payment fetch failed', ['transid' => $txn['rc_transid'], 'payment' => $paymentId, 'try' => $i, 'error' => $e->getMessage()]);
        continue;
    }
    $diff = gw_payment_mismatch($payment, $txn);
    gw_debug('payment fetched', ['try' => $i, 'status' => $payment['status'] ?? null, 'method' => $payment['method'] ?? null, 'amount_charged' => $payment['amount'] ?? null, 'fee' => $payment['fee'] ?? null, 'mismatch' => $diff]);
    if ($diff) {
        gw_log('Payment does not match order', ['transid' => $txn['rc_transid'], 'payment' => $paymentId, 'mismatch' => array_keys($diff), 'expected_vs_got' => $diff]);
        fail_page('Payment not verified', 'This payment does not match your order. If you were charged, please contact us and quote reference #' . $txn['rc_transid'] . '.');
    }
    $lastStatus = (string) ($payment['status'] ?? '');

    if ($lastStatus === 'captured') {
        $store->markPaid($token, $paymentId);
        $sendBack($store->findByToken($token), ResellerClub::STATUS_SUCCESS);
    }
    if ($lastStatus === 'failed') {
        gw_log('Payment failed at Razorpay', ['transid' => $txn['rc_transid'], 'payment' => $paymentId]);
        $store->transition($token, 'created', 'failed', ['razorpay_payment_id' => $paymentId]);
        $txn = $store->findByToken($token);
        $sendBack($txn, $txn['status'] === 'paid' ? ResellerClub::STATUS_SUCCESS : ResellerClub::STATUS_FAILED);
    }
    if ($lastStatus === 'authorized') {
        try {
            // Capture the authorised amount (includes Razorpay's fee when the customer pays it).
            $razorpay->capturePayment($paymentId, (int) $payment['amount'], (string) $payment['currency']);
        } catch (Throwable $e) {
            // Usually "already captured" because auto-capture got there first; the next fetch tells.
            gw_log('Capture call failed', ['transid' => $txn['rc_transid'], 'payment' => $paymentId, 'error' => $e->getMessage()]);
        }
    }
    // The webhook may have recorded the payment while we were waiting.
    if ($store->findByToken($token)['status'] === 'paid') {
        $sendBack($store->findByToken($token), ResellerClub::STATUS_SUCCESS);
    }
}

// Still not confirmed: keep the customer on a page that re-checks instead of a dead end.
gw_log('Payment not confirmed yet', ['transid' => $txn['rc_transid'], 'payment' => $paymentId, 'status' => $lastStatus, 'attempt' => $attempt]);
$autoRetry = $attempt < 10;
gw_page_open('Confirming your payment', 202);
echo '<h1>Confirming your payment…</h1>'
    . ($autoRetry ? '<div class="spinner"></div>' : '')
    . '<p>Your payment has been received by Razorpay and we are waiting for the final confirmation. '
    . ($autoRetry ? 'This page checks again automatically. Please don\'t close it.' : 'It is taking longer than usual. You can check again, or contact us and quote reference #' . h($txn['rc_transid']) . '.')
    . '</p><form method="post" action="callback.php" id="recheck">'
    . '<input type="hidden" name="token" value="' . h($token) . '">'
    . '<input type="hidden" name="razorpay_payment_id" value="' . h($paymentId) . '">'
    . '<input type="hidden" name="razorpay_order_id" value="' . h($orderId) . '">'
    . '<input type="hidden" name="razorpay_signature" value="' . h($signature) . '">'
    . '<input type="hidden" name="attempt" value="' . ($attempt + 1) . '">'
    . '<button class="pay" type="submit">Check again</button></form>'
    . '<p class="ref">Ref. #' . h($txn['rc_transid']) . '</p>';
gw_page_close($autoRetry ? '<script>setTimeout(function(){document.getElementById("recheck").submit();},5000);</script>' : '');
exit;
