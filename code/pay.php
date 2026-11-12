<?php
/**
 * Entry point: set this URL as the "Payment Gateway URL" in ResellerClub.
 * ResellerClub sends the customer here with the transaction details + checksum.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$p = [];
foreach (ResellerClub::INBOUND_FIELDS as $f) {
    $p[$f] = isset($_REQUEST[$f]) && is_string($_REQUEST[$f]) ? trim($_REQUEST[$f]) : null;
}

// Logged only if debug ends up on; checksum and full return URL are left out.
gw_debug('request from ResellerClub', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'fields' => array_diff_key($p, ['checksum' => 1, 'redirecturl' => 1]),
    'return_host' => parse_url((string) $p['redirecturl'], PHP_URL_HOST),
    'other_params' => array_values(array_diff(array_keys($_REQUEST), ResellerClub::INBOUND_FIELDS)),
]);

foreach (['paymenttypeid', 'transid', 'sellingcurrencyamount', 'accountingcurrencyamount', 'redirecturl', 'checksum'] as $required) {
    if ($p[$required] === null || $p[$required] === '') {
        gw_log('Missing parameter', ['field' => $required]);
        fail_page('Invalid payment request', 'Required information is missing. Please go back to the store and try again.');
    }
}
$p = array_map(fn($v) => $v ?? '', $p);

$checksumOk = $rc->verifyRequest($p);
gw_debug('checksum check', ['ok' => $checksumOk]);
if (!$checksumOk) {
    gw_log('Checksum mismatch', ['transid' => substr($p['transid'], 0, 64)]);
    fail_page('Invalid payment request', 'The payment request could not be verified. Please go back to the store and try again.');
}

// The return URL isn't covered by the checksum: https only, allowed host, canonical form.
$redirectHost = strtolower((string) parse_url($p['redirecturl'], PHP_URL_HOST));
$returnUrl = gw_canonical_return_url($p['redirecturl'], gw_return_hosts($config['allowed_redirect_hosts']));
gw_debug('return URL check', ['host' => $redirectHost, 'ok' => $returnUrl !== null]);
if ($returnUrl === null) {
    if ($redirectHost !== '' && gw_valid_hostname($redirectHost) && !gw_host_allowed($redirectHost, gw_return_hosts($config['allowed_redirect_hosts']))) {
        gw_log('Redirect host not allowed', ['transid' => $p['transid'], 'host' => $redirectHost]);
        fail_page('Invalid payment request', 'Unrecognised return address: ' . $redirectHost . '. '
            . 'Site owner: add this host to allowed_redirect_hosts in config.php if it belongs to your ResellerClub store.');
    }
    gw_log('Invalid redirect URL', ['transid' => $p['transid'], 'host' => $redirectHost]);
    fail_page('Invalid payment request', 'The return address from the store is not valid (it must be https). Please go back to the store and try again.');
}
$p['redirecturl'] = $returnUrl;

$currency = $config['currency'];
$amountMinor = gw_to_minor($p['sellingcurrencyamount'], $currency);
if ($amountMinor === null) {
    fail_page('Invalid payment request', 'Invalid amount.');
}

// ResellerClub's own "Test For Payment" / "Test For Add Fund" buttons: a checksummed transid
// containing "test" whose result goes to ResellerClub's test servlet. Always on test keys.
$isRcTest = gw_is_rc_test($p['transid'], $p['redirecturl']);
if ($isRcTest) {
    gw_debug_enable('ResellerClub test transaction (verified)');
}

// From here the request is verified, so a refused payment can still send the customer back:
// post the signed "not paid" result to ResellerClub, like the Cancel button does.
$returnToStore = function () use ($rc, $p): string {
    $html = '<form method="post" action="' . h($p['redirecturl']) . '">';
    foreach ($rc->buildResponse($p['transid'], $p['sellingcurrencyamount'], $p['accountingcurrencyamount'], ResellerClub::STATUS_FAILED) as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    return $html . '<button class="secondary" type="submit">Cancel and return to store</button></form>';
};
$unavailable = 'Online payment is temporarily unavailable. Please try again later or contact us to pay another way.';

// Refuse new payments if data/ (database, logs) can be downloaded from the web.
if (gw_data_exposed_cached($config['base_url'], $config['data_dir']) === true) {
    gw_log('Payments refused: data/ is readable over the web', ['transid' => $p['transid']]);
    fail_page('Payment temporarily unavailable', $unavailable, 503, $returnToStore());
}

// Which Razorpay keys to use. In sandbox (test) mode only listed ResellerClub accounts may pay,
// so nobody else can "pay" with public test cards against the live ResellerClub account.
$mode = $isRcTest ? 'test' : $config['mode'];
gw_debug('mode decision', ['config_mode' => $config['mode'], 'rc_test' => $isRcTest, 'mode' => $mode, 'userid' => $p['userid'], 'test_customer_ids' => $config['test_customer_ids']]);
if ($config['mode'] === 'test' && !$isRcTest && !in_array((string) $p['userid'], $config['test_customer_ids'], true)) {
    gw_log('Sandbox: customer not on test list', ['transid' => $p['transid'], 'userid' => $p['userid']]);
    fail_page('Payment temporarily unavailable', $unavailable, 503, $returnToStore());
}
try {
    $razorpay = gw_razorpay($mode);
} catch (Throwable $e) {
    gw_log('Missing keys for mode', ['transid' => $p['transid'], 'mode' => $mode, 'rc_test' => $isRcTest]);
    if ($isRcTest) {
        fail_page('Test keys needed', 'This is a ResellerClub test transaction, which runs on Razorpay test keys. '
            . 'Site owner: add razorpay_test_key_id and razorpay_test_key_secret (rzp_test_…) to config.php.', 503, $returnToStore());
    }
    fail_page('Payment temporarily unavailable', $unavailable, 503, $returnToStore());
}
if ($isRcTest) {
    gw_log('ResellerClub test transaction', ['transid' => $p['transid']]);
}
$GLOBALS['gw_test_banner'] = $mode === 'test';

/** Send the signed success for an attempt that is already paid (never charge twice). */
$replayPaid = function (array $paidTxn) use ($rc, $store): never {
    if (!gw_may_send_success($paidTxn)) {
        gw_log('Blocked success outside the test boundary', ['transid' => $paidTxn['rc_transid']]);
        fail_page('Payment not confirmed', 'This payment cannot be confirmed here. Please contact us and quote reference #' . $paidTxn['rc_transid'] . '.', 409);
    }
    $store->markResultShown($paidTxn['token']);
    gw_log('Replaying paid result', ['transid' => $paidTxn['rc_transid'], 'order' => $paidTxn['razorpay_order_id']]);
    $GLOBALS['gw_test_banner'] = gw_txn_mode($paidTxn) === 'test';
    ResellerClub::renderRedirect($paidTxn['redirect_url'], $rc->buildResponse($paidTxn['rc_transid'], $paidTxn['selling_amount'], $paidTxn['accounting_amount'], ResellerClub::STATUS_SUCCESS));
};

// A real transaction that is already paid: return the existing result instead of a new order.
if (!$isRcTest && ($paid = $store->findPaidByTransId($p['transid'])) && (int) $paid['amount_minor'] === $amountMinor) {
    $replayPaid($paid);
}

// Reuse the open attempt for this transaction (page reloads, back button) when it still fits.
$txn = $store->findOpenByTransId($p['transid']);
gw_debug('open attempt lookup', ['found' => $txn ? ['token' => substr($txn['token'], 0, 8) . '…', 'order' => $txn['razorpay_order_id'], 'amount_minor' => $txn['amount_minor'], 'mode' => $txn['mode'] ?? null, 'created' => $txn['created_at']] : null, 'amount_minor_now' => $amountMinor]);
$supersede = null;
if ($txn) {
    if ($isRcTest) {
        $supersede = 'ResellerClub test transaction (always a fresh order)';
    } elseif ((int) $txn['amount_minor'] !== $amountMinor || $txn['redirect_url'] !== $p['redirecturl'] || gw_txn_mode($txn) !== $mode) {
        $supersede = 'amount, return URL or mode differs';
    } elseif (empty($txn['razorpay_order_id'])) {
        $supersede = null; // another request is creating its order right now; handled below
    } else {
        try {
            $orderStatus = (string) ($razorpay->fetchOrder($txn['razorpay_order_id'])['status'] ?? '');
        } catch (Throwable $e) {
            $orderStatus = 'unknown';
        }
        gw_debug('open attempt order status', ['order' => $txn['razorpay_order_id'], 'status' => $orderStatus]);
        if ($orderStatus === 'paid') {
            // Paid earlier but the customer never made it back: report it, don't charge again.
            $store->markPaid($txn['token'], (string) ($txn['razorpay_payment_id'] ?? ''));
            gw_log('Open attempt already paid at Razorpay', ['transid' => $txn['rc_transid'], 'order' => $txn['razorpay_order_id']]);
            $replayPaid($store->findByToken($txn['token']));
        }
        if (!in_array($orderStatus, ['created', 'attempted'], true)) {
            $supersede = "order status '$orderStatus'";
        }
    }
    if ($supersede !== null) {
        gw_debug('superseding open attempt', ['reason' => $supersede]);
        $store->transition($txn['token'], 'created', 'superseded');
        $txn = null;
    }
}

if (!$txn) {
    // Claim the single open-attempt slot first, so concurrent requests can't both create orders.
    $token = bin2hex(random_bytes(16));
    $reserved = $store->reserve([
        'token'              => $token,
        'rc_transid'         => $p['transid'],
        'rc_userid'          => $p['userid'],
        'rc_usertype'        => $p['usertype'],
        'rc_transactiontype' => $p['transactiontype'],
        'rc_invoiceids'      => $p['invoiceids'],
        'rc_description'     => $p['description'],
        'selling_amount'     => $p['sellingcurrencyamount'],
        'accounting_amount'  => $p['accountingcurrencyamount'],
        'redirect_url'       => $p['redirecturl'],
        'amount_minor'       => $amountMinor,
        'currency'           => $currency,
        'mode'               => $mode,
    ]);
    if ($reserved) {
        try {
            $order = $razorpay->createOrder($amountMinor, $currency, 'rc_' . $p['transid'], [
                'rc_transid'  => $p['transid'],
                'rc_userid'   => $p['userid'],
                'rc_invoices' => substr($p['invoiceids'], 0, 250),
            ]);
        } catch (Throwable $e) {
            $store->transition($token, 'created', 'failed');
            gw_log('Order creation failed', ['transid' => $p['transid'], 'error' => $e->getMessage()]);
            fail_page('Payment unavailable', 'We could not start the payment right now. Please try again in a few minutes.', 502, $returnToStore());
        }
        $store->update($token, ['razorpay_order_id' => $order['id']]);
        gw_log('Order created', ['transid' => $p['transid'], 'order' => $order['id'], 'amount' => $amountMinor, 'mode' => $mode]);
        $txn = $store->findByToken($token);
        gw_debug('new attempt stored', ['token' => substr($token, 0, 8) . '…', 'order' => $order['id'], 'amount_minor' => $amountMinor, 'currency' => $currency, 'mode' => $mode]);
    }
}

// Lost the race to a concurrent request: wait briefly for its order, then reuse it.
for ($i = 0; (!$txn || empty($txn['razorpay_order_id'])) && $i < 10; $i++) {
    usleep(300000);
    $txn = $store->findOpenByTransId($p['transid']);
}
if (!$txn || empty($txn['razorpay_order_id'])) {
    gw_log('Concurrent attempt did not finish', ['transid' => $p['transid']]);
    fail_page('Payment unavailable', 'This payment is already being started in another window. Please try again in a moment.', 409, $returnToStore());
}

$callbackUrl = $config['base_url'] . '/callback.php';
$checkout = [
    'key'         => $razorpay->keyId(),
    'amount'      => (int) $txn['amount_minor'],
    'currency'    => $txn['currency'],
    'order_id'    => $txn['razorpay_order_id'],
    'name'        => gw_brand()['name'],
    'description' => $txn['rc_description'] ?: ('Transaction #' . $txn['rc_transid']),
    'notes'       => ['rc_transid' => $txn['rc_transid']],
    'theme'       => ['color' => gw_brand()['color']],
];
if (gw_brand()['logo'] !== '') {
    $checkout['image'] = gw_brand()['logo'];
}
// Save the customer retyping their details in the popup (still editable there).
$prefill = gw_checkout_prefill($_REQUEST);
if ($prefill) {
    $checkout['prefill'] = $prefill;
}
$displayAmount = gw_format_minor((int) $txn['amount_minor'], $txn['currency']);

$reference = 'Ref. #' . $txn['rc_transid'];

gw_debug('payment page shown', ['order' => $checkout['order_id'], 'amount' => $checkout['amount'], 'currency' => $checkout['currency'], 'key' => $checkout['key'], 'prefilled' => array_keys($prefill)]);
gw_page_open('Pay ' . $displayAmount);
?>
  <h1><?= h($checkout['description']) ?></h1>
  <div class="amount"><?= h($displayAmount) ?></div>
  <div class="ref"><?= h($reference) ?></div>
  <button class="pay" id="pay">Pay now</button>
  <form method="post" action="<?= h($callbackUrl) ?>" id="cancel-form">
    <input type="hidden" name="token" value="<?= h($txn['token']) ?>">
    <input type="hidden" name="action" value="cancel">
    <button class="cancel" type="submit">Cancel and return to store</button>
  </form>
  <form method="post" action="<?= h($callbackUrl) ?>" id="result-form" hidden>
    <input type="hidden" name="token" value="<?= h($txn['token']) ?>">
    <input type="hidden" name="razorpay_payment_id">
    <input type="hidden" name="razorpay_order_id">
    <input type="hidden" name="razorpay_signature">
  </form>
<?php
gw_page_close('<script src="https://checkout.razorpay.com/v1/checkout.js"></script><script>
  var opts = ' . json_encode($checkout, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
  opts.handler = function (r) {
    var f = document.getElementById("result-form");
    f.razorpay_payment_id.value = r.razorpay_payment_id;
    f.razorpay_order_id.value = r.razorpay_order_id;
    f.razorpay_signature.value = r.razorpay_signature;
    f.submit();
  };
  var rzp = new Razorpay(opts);
  document.getElementById("pay").onclick = function () { rzp.open(); };
  rzp.open();
</script>');
