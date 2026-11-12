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
$txnMode = gw_txn_mode($txn);
$GLOBALS['gw_test_banner'] = $txnMode === 'test';

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

try {
    $razorpay = gw_razorpay($txnMode);
} catch (Throwable $e) {
    gw_log('Missing keys for transaction mode', ['transid' => $txn['rc_transid'], 'mode' => $txnMode]);
    fail_page('Payment not confirmed', 'We could not confirm this payment right now. If you were charged, please contact us and quote reference #' . $txn['rc_transid'] . '.', 503);
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
    if (($payment['order_id'] ?? '') !== $txn['razorpay_order_id']
        || (int) ($payment['amount'] ?? 0) !== (int) $txn['amount_minor']
        || strtoupper((string) ($payment['currency'] ?? '')) !== strtoupper($txn['currency'])) {
        gw_log('Payment does not match order', ['transid' => $txn['rc_transid'], 'payment' => $paymentId, 'order' => $payment['order_id'] ?? null, 'amount' => $payment['amount'] ?? null]);
        fail_page('Payment not verified', 'This payment does not match your order. If you were charged, please contact us and quote reference #' . $txn['rc_transid'] . '.');
    }
    $lastStatus = (string) ($payment['status'] ?? '');

    if ($lastStatus === 'captured') {
        $store->transition($token, 'created', 'paid', ['razorpay_payment_id' => $paymentId]);
        $store->transition($token, 'failed', 'paid', ['razorpay_payment_id' => $paymentId]);
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
            $razorpay->capturePayment($paymentId, (int) $txn['amount_minor'], $txn['currency']);
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
