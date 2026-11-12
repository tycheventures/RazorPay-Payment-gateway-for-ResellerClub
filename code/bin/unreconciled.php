<?php
/**
 * CLI: paid Razorpay payments that ResellerClub may not have recorded.
 *
 *   php bin/unreconciled.php                  list them
 *   php bin/unreconciled.php --confirm <transid>   mark one as checked in ResellerClub
 *
 * ResellerClub sends no acknowledgement, so the gateway only knows whether the signed
 * result page was shown to the customer's browser, not whether ResellerClub received it.
 *
 * Exit code: 1 when payments need action (result never shown), otherwise 0.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

if (($argv[1] ?? '') === '--confirm') {
    $transId = (string) ($argv[2] ?? '');
    if ($transId === '') {
        fwrite(STDERR, "Usage: php bin/unreconciled.php --confirm <transid>\n");
        exit(2);
    }
    $n = $store->confirm($transId);
    echo $n ? "Marked $n paid payment(s) for $transId as confirmed in ResellerClub.\n" : "No paid payment found for $transId.\n";
    exit($n ? 0 : 2);
}

$groups = $store->unreconciled();
$print = function (string $title, array $rows): void {
    echo $title, "\n";
    printf("  %-20s %-28s %-14s %-22s %-22s %s\n", 'Created', 'RC transid', 'Amount', 'Razorpay order', 'Razorpay payment', 'Mode');
    foreach ($rows as $r) {
        printf("  %-20s %-28s %-14s %-22s %-22s %s\n",
            substr($r['created_at'], 0, 19), $r['rc_transid'], $r['selling_amount'] . ' ' . $r['currency'],
            $r['razorpay_order_id'], $r['razorpay_payment_id'], $r['mode'] ?? '');
    }
    echo "\n";
};

if (!$groups['never_shown'] && !$groups['unconfirmed']) {
    echo "Nothing to reconcile.\n";
    exit(0);
}
if ($groups['never_shown']) {
    $print('ACTION NEEDED — paid, but the customer never reached the result page. Mark these paid in ResellerClub:', $groups['never_shown']);
}
if ($groups['unconfirmed']) {
    $print('CHECK — paid and the result page was shown, but ResellerClub receipt is unconfirmed. Spot-check in ResellerClub:', $groups['unconfirmed']);
}
echo "After checking a transaction in ResellerClub: php bin/unreconciled.php --confirm <transid>\n";
exit($groups['never_shown'] ? 1 : 0);
