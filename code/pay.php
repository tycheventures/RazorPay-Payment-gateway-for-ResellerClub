<?php
/**
 * Entry point: set this URL as the "Payment Gateway URL" in ResellerClub.
 * ResellerClub sends the customer here with the transaction details + checksum.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$p = [];
foreach (ResellerClub::INBOUND_FIELDS as $f) {
    $p[$f] = isset($_REQUEST[$f]) ? trim((string) $_REQUEST[$f]) : null;
}

foreach (['paymenttypeid', 'transid', 'sellingcurrencyamount', 'accountingcurrencyamount', 'redirecturl', 'checksum'] as $required) {
    if ($p[$required] === null || $p[$required] === '') {
        gw_log('Missing parameter', ['field' => $required]);
        fail_page('Invalid payment request', 'Required information is missing. Please go back to the store and try again.');
    }
}
$p = array_map(fn($v) => $v ?? '', $p);

if (!$rc->verifyRequest($p)) {
    gw_log('Checksum mismatch', ['transid' => $p['transid']]);
    fail_page('Invalid payment request', 'The payment request could not be verified. Please go back to the store and try again.');
}

$redirectHost = parse_url($p['redirecturl'], PHP_URL_HOST);
$scheme = parse_url($p['redirecturl'], PHP_URL_SCHEME);
if (!in_array($scheme, ['http', 'https'], true) || !$redirectHost) {
    gw_log('Invalid redirect URL', ['transid' => $p['transid']]);
    fail_page('Invalid payment request', 'The return address from the store is not valid. Please go back to the store and try again.');
}
if (!gw_host_allowed($redirectHost, $config['allowed_redirect_hosts'])) {
    gw_log('Redirect host not allowed', ['transid' => $p['transid'], 'host' => $redirectHost]);
    fail_page('Invalid payment request', 'Unrecognised return address: ' . strtolower($redirectHost) . '. '
        . 'Site owner: add this host to allowed_redirect_hosts in config.php if it belongs to your ResellerClub store.');
}

if (!is_numeric($p['sellingcurrencyamount']) || (float) $p['sellingcurrencyamount'] <= 0) {
    fail_page('Invalid payment request', 'Invalid amount.');
}
$amountMinor = (int) round((float) $p['sellingcurrencyamount'] * 100);
$currency = $config['currency'];

// Reuse an open attempt for the same transaction (page reloads, back button).
$txn = $store->findOpenByTransId($p['transid']);
if ($txn && ((int) $txn['amount_minor'] !== $amountMinor || $txn['redirect_url'] !== $p['redirecturl'])) {
    $txn = null;
}

if (!$txn) {
    $token = bin2hex(random_bytes(16));
    try {
        $order = $razorpay->createOrder($amountMinor, $currency, 'rc_' . $p['transid'], [
            'rc_transid'  => $p['transid'],
            'rc_userid'   => $p['userid'],
            'rc_invoices' => substr($p['invoiceids'], 0, 250),
            'gw_token'    => $token,
        ]);
    } catch (Throwable $e) {
        gw_log('Order creation failed', ['transid' => $p['transid'], 'error' => $e->getMessage()]);
        fail_page('Payment unavailable', 'We could not start the payment right now. Please try again in a few minutes.', 502);
    }
    $store->create([
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
        'razorpay_order_id'  => $order['id'],
    ]);
    gw_log('Order created', ['transid' => $p['transid'], 'order' => $order['id'], 'amount' => $amountMinor]);
    $txn = $store->findByToken($token);
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
$displayAmount = number_format($txn['amount_minor'] / 100, 2) . ' ' . $txn['currency'];

$reference = 'Ref. #' . $txn['rc_transid'];

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
