# Razorpay Payment Gateway for ResellerClub

A ResellerClub **Custom Payment Gateway** that collects payment through **Razorpay Standard Checkout** (UPI, cards, netbanking, wallets).

Plain PHP 8.1+, no Composer. Needs the `curl`, `pdo_sqlite` and `json` extensions.

## How it works

```
ResellerClub ──GET (checksum)──▶ public/pay.php ──creates Razorpay Order──▶ checkout.js popup
                                                                              │
ResellerClub ◀──POST transid/status/checksum── public/callback.php ◀─────────┘
                                                  (verifies signature + payment via API)

Razorpay ──webhook──▶ public/webhook.php   (records payments if the customer never comes back)
```

1. ResellerClub sends the customer to `pay.php` with the transaction details. The MD5 checksum is checked against your gateway key.
2. A Razorpay Order is created for `sellingcurrencyamount` and the checkout popup opens.
3. On success, `callback.php` checks the Razorpay signature, fetches the payment from the Razorpay API (order, amount and currency must match), captures it if it is only authorized, then posts `status=Y` back to ResellerClub. Cancel posts `status=N`.
4. Reloading or coming back to the page reuses the same order. A transaction that is already settled always returns its final result, so a customer is never charged twice for one transid.

## Setup

1. **Deploy.** Upload the repo to your server and point a (sub)domain's document root at `public/`, e.g. `https://pay.yourdomain.com`. `config.php`, `src/`, `bin/` and `data/` must sit **outside** the web root.
2. **Configure.** Run `cp config.sample.php config.php` and fill it in:
   - `rc_key`: the key from ResellerClub's custom gateway screen (step 3).
   - `razorpay_key_id` / `razorpay_key_secret`: from Razorpay Dashboard → API Keys.
   - `razorpay_webhook_secret`: the secret you choose in step 4.
   - `base_url`: e.g. `https://pay.yourdomain.com`.
   - `allowed_redirect_hosts`: your SuperSite / control panel hostnames (recommended).
   - Make sure the web server user can write to `data_dir`.
3. **ResellerClub.** Control Panel → Settings → Finance & Billing → Payment Gateways → *Add Custom Payment Gateway*:
   - Gateway URL: `https://pay.yourdomain.com/pay.php`
   - Copy the generated **key** into `rc_key`.
   - Choose who can see it (customers / sub-resellers) and give it a display name such as "Razorpay (UPI / Cards / Netbanking)".
4. **Razorpay webhook.** Dashboard → Webhooks → Add:
   - URL: `https://pay.yourdomain.com/webhook.php`
   - Secret: the same value as `razorpay_webhook_secret`
   - Events: `payment.captured`, `order.paid`
5. **Test** with `rzp_test_` keys: pay an invoice from a test customer account and check that it is marked paid in ResellerClub. Then switch to live keys.

## Reconciliation

ResellerClub only finds out about a payment when the customer's browser returns to it. If someone pays and then closes the tab, Razorpay still has the money and the webhook records it, but ResellerClub doesn't. List those cases with:

```bash
php bin/unreconciled.php
```

Then mark those invoices paid in the ResellerClub control panel. The script exits with code 1 when it finds any, so you can run it from cron and get an email.

## Files

| Path | Purpose |
|---|---|
| `public/pay.php` | Entry point ResellerClub redirects to; creates the order and shows checkout |
| `public/callback.php` | Verifies the payment and posts the result back to ResellerClub |
| `public/webhook.php` | Razorpay webhook receiver |
| `src/ResellerClub.php` | ResellerClub checksum + response protocol |
| `src/Razorpay.php` | Minimal Razorpay REST client + signature checks |
| `src/Store.php` | SQLite transaction store |
| `bin/unreconciled.php` | Lists paid-but-not-returned transactions |
| `data/` | SQLite DB and `gateway.log` (git-ignored) |

## Notes

- Amounts are charged in `currency` (default `INR`) using ResellerClub's `sellingcurrencyamount`. Set your ResellerClub selling currency to match.
- Payments are auto-captured (`payment_capture: 1`). The callback also captures any payment that is still only authorized.
- Logs: `data/gateway.log`.
