<?php
/**
 * CLI: list payments captured by Razorpay whose customer never returned to ResellerClub.
 * Mark these invoices paid manually in the ResellerClub control panel.
 *
 *   php bin/unreconciled.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$rows = $store->unreconciled();
if (!$rows) {
    echo "All paid transactions were returned to ResellerClub.\n";
    exit(0);
}
printf("%-20s %-14s %-12s %-22s %-22s %s\n", 'Created', 'RC transid', 'Amount', 'Razorpay order', 'Razorpay payment', 'Invoices');
foreach ($rows as $r) {
    printf("%-20s %-14s %-12s %-22s %-22s %s\n",
        substr($r['created_at'], 0, 19), $r['rc_transid'], $r['selling_amount'] . ' ' . $r['currency'],
        $r['razorpay_order_id'], $r['razorpay_payment_id'], $r['rc_invoiceids']);
}
exit(1);
