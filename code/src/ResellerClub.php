<?php
declare(strict_types=1);

/**
 * ResellerClub custom payment gateway protocol.
 *
 * Inbound (ResellerClub → gateway, GET): paymenttypeid, transid, userid, usertype,
 * transactiontype, invoiceids, debitnoteids, description, sellingcurrencyamount,
 * accountingcurrencyamount, redirecturl, checksum.
 *
 * Outbound (gateway → ResellerClub redirecturl, POST): transid, status (Y|N|P), rkey,
 * checksum, sellingamount, accountingamount.
 */
final class ResellerClub
{
    public const STATUS_SUCCESS = 'Y';
    public const STATUS_FAILED  = 'N';
    public const STATUS_PENDING = 'P';

    public const INBOUND_FIELDS = [
        'paymenttypeid', 'transid', 'userid', 'usertype', 'transactiontype',
        'invoiceids', 'debitnoteids', 'description', 'sellingcurrencyamount',
        'accountingcurrencyamount', 'redirecturl', 'checksum',
    ];

    public function __construct(private string $key)
    {
    }

    /** Verify the checksum ResellerClub sends with the payment request. */
    public function verifyRequest(array $p): bool
    {
        $str = implode('|', [
            $p['paymenttypeid'], $p['transid'], $p['userid'], $p['usertype'],
            $p['transactiontype'], $p['invoiceids'], $p['debitnoteids'], $p['description'],
            $p['sellingcurrencyamount'], $p['accountingcurrencyamount'], $this->key,
        ]);
        return hash_equals(md5($str), strtolower((string) $p['checksum']));
    }

    /** Checksum for the response posted back to ResellerClub. */
    public function responseChecksum(string $transId, string $sellingAmount, string $accountingAmount, string $status, string $rkey): string
    {
        return md5("$transId|$sellingAmount|$accountingAmount|$status|$rkey|{$this->key}");
    }

    /** Fields to POST to redirecturl. */
    public function buildResponse(string $transId, string $sellingAmount, string $accountingAmount, string $status): array
    {
        $rkey = (string) random_int(100000000, 999999999);
        return [
            'transid'          => $transId,
            'status'           => $status,
            'rkey'             => $rkey,
            'checksum'         => $this->responseChecksum($transId, $sellingAmount, $accountingAmount, $status, $rkey),
            'sellingamount'    => $sellingAmount,
            'accountingamount' => $accountingAmount,
        ];
    }

    /** Render an auto-submitting form that posts the result back to ResellerClub. */
    public static function renderRedirect(string $redirectUrl, array $fields): never
    {
        $ok = ($fields['status'] ?? '') === self::STATUS_SUCCESS;
        gw_page_open($ok ? 'Payment received' : 'Returning to store');
        echo '<h1>' . ($ok ? 'Payment received' : 'Payment not completed') . '</h1>'
            . '<div class="spinner"></div>'
            . '<p class="muted">Returning you to the store… Please don\'t close this window or press Back.</p>'
            . '<form method="post" action="' . h($redirectUrl) . '" id="rc-return">';
        foreach ($fields as $k => $v) {
            echo '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
        }
        echo '<noscript><button class="pay" type="submit">Continue</button></noscript></form>';
        gw_page_close('<script>document.getElementById("rc-return").submit();</script>');
        exit;
    }
}
