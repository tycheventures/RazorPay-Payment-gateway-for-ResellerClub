# Razorpay Payment Gateway for ResellerClub

A ResellerClub **Custom Payment Gateway** that collects payment through **Razorpay Standard Checkout** (UPI, cards, netbanking, wallets).

Plain PHP 8.1+, no Composer. Needs the `curl`, `pdo_sqlite` and `json` extensions.

## How it works

```
ResellerClub ──GET (checksum)──▶ code/pay.php    ──creates Razorpay Order──▶ checkout.js popup
                                                                              │
ResellerClub ◀──POST transid/status/checksum── code/callback.php ◀───────────┘
                                                  (verifies signature + payment via API)

Razorpay ──webhook──▶ code/webhook.php   (records payments if the customer never comes back)
```

1. ResellerClub sends the customer to `pay.php` with the transaction details. The MD5 checksum is checked against your gateway key.
2. A Razorpay Order is created for `sellingcurrencyamount` and the checkout popup opens.
3. On success, `callback.php` checks the Razorpay signature, fetches the payment from the Razorpay API (order, amount and currency must match), captures it if it is only authorized, then posts `status=Y` back to ResellerClub. Cancel posts `status=N`.
4. Reloading or coming back to the page reuses the same order. A transaction that is already settled always returns its final result, so a customer is never charged twice for one transid.

## Setup

1. **Deploy.** Everything that runs on the server lives in `code/`. Upload its contents to the folder you want to serve it from, e.g. `https://pay.example.com/razorpay` (Apache or LiteSpeed). The bundled `.htaccess` files block web access to `src/`, `bin/`, `data/` and `config.php`.
2. **ResellerClub.** Control Panel → Settings → Finance & Billing → Payment Gateways → *Add Custom Payment Gateway*. Set the gateway URL to `https://pay.example.com/razorpay/pay.php`, choose who can see it, and give it a display name such as "Razorpay (UPI / Cards / Netbanking)". Keep the **key** it shows for the next step.
3. **Run the setup wizard.** Open `https://pay.example.com/razorpay/` and you'll be sent to `setup.php`.
   - **Setup code:** on first visit, the wizard writes a one-time code to `data/setup-code.txt`. Open that file in cPanel File Manager or over FTP and paste the code into the wizard. This proves you control the server, so nobody who finds the URL first can enter their own Razorpay keys.
   - **Fill in:** the ResellerClub key, your Razorpay live keys, or tick *Sandbox mode* and enter test keys and the customer or sub-reseller IDs allowed to test, branding (name, logo, colour, support email and phone), and the hostnames customers may be returned to. The branding appears on the payment page, every error page, the "Returning to store" page and the Razorpay popup.
   - **Before saving,** the wizard checks the PHP version and extensions, the writable folders, and the Razorpay keys (with a live API call). It then writes `config.php`, deletes the setup code, and locks itself.
   - **The final screen** shows the webhook URL and secret to paste into Razorpay, and checks that `config.php` is blocked from the web.
4. **Razorpay webhook.** Dashboard → Webhooks → Add the URL and secret from the wizard, with the events `payment.captured` and `order.paid`.
5. **Test** with `rzp_test_` keys: pay an invoice from a test customer account and check that it shows as paid in ResellerClub. Then switch to live keys by editing `config.php`.

**Reconfiguring:** edit `config.php` directly (see [`code/config.sample.php`](code/config.sample.php) for every option), or delete it and open the folder again. That issues a fresh setup code and reopens the wizard. While `config.php` is missing, customers see a "not available yet" page.

## Test and live mode

`config.php` has a `mode` setting:

| `mode` | Keys used | Who can pay |
|---|---|---|
| `live` | `razorpay_live_key_id` / `_secret` | everyone |
| `test` (sandbox) | `razorpay_test_key_id` / `_secret` | only the ResellerClub customer or sub-reseller IDs in `test_customer_ids`; everyone else sees "temporarily unavailable" |

Test mode shows a **TEST MODE — no real money will be charged** banner. Pay with Razorpay's test cards or the test UPI ID `success@razorpay`. The customer limit matters because your ResellerClub account is live: without it, anyone could "pay" with a public test card and get a real order marked as paid.

- **Choosing the mode:** the setup wizard asks for live keys, with an optional *Sandbox mode* checkbox that asks for test keys and the customer or sub-reseller IDs allowed to test. The check uses the `userid` ResellerClub sends, which is set for customers and sub-resellers alike.
- **Going live:** add the live keys and set `'mode' => 'live'`.
- **Mixing modes:** each transaction remembers which mode created it, so switching modes doesn't break payments already in progress.
- **Older configs** with a single `razorpay_key_id` keep working: `rzp_test_` keys mean test mode.
- **ResellerClub's test buttons:** *Test For Payment* and *Test For Add Fund* on the custom gateway screen send a transid containing "Test" (e.g. `Payment-Test-1`). These **always use the test keys**, even in live mode, and aren't limited by `test_customer_ids`. The transid is covered by ResellerClub's checksum, so customers can't trigger this. Without test keys configured, these show a "Test keys needed" message.

## Debug log

`data/debug.log` records every step of a payment, one line each, with a short request ID that ties together the lines from one request:
- the ResellerClub request fields and the checksum, return-host and mode decisions;
- whether an earlier attempt was reused or a new order created;
- every Razorpay API call (key ID, HTTP status, trimmed response; never the key secret);
- the callback's signature check and each confirmation attempt, with expected and actual order, amount and currency;
- webhook events.

**When it's on:**
- **Test-mode transactions** (sandbox mode and ResellerClub's test buttons) are always logged.
- **Live transactions** are logged only while `'debug' => true` is set in `config.php` (a checkbox in the wizard). That log then holds payment details, so switch it off when you're done.

Only the known ResellerClub fields are logged: the customer's name, email and address are left out. The file is blocked from the web and rotates at 5 MB (`debug.log.1`). Read it in cPanel File Manager or over FTP. The short `gateway.log` stays as before.

## Reconciliation

ResellerClub only finds out about a payment when the customer's browser returns to it. If someone pays and then closes the tab, Razorpay still has the money and the webhook records it, but ResellerClub doesn't. List those cases with:

```bash
php code/bin/unreconciled.php
```

Then mark those invoices paid in the ResellerClub control panel. The script exits with code 1 when it finds any, so you can run it from cron and get an email.

## Releases

Every merge to `main` runs [`.github/workflows/release.yml`](.github/workflows/release.yml). It tags the next version and publishes a GitHub release with `razorpay-resellerclub-gateway-vX.Y.Z.zip`, which unzips to a `razorpay/` folder ready to upload.

- The default is a patch bump (`v1.0.0` → `v1.0.1`).
- Add the `release:minor` or `release:major` label to the PR before merging to bump those instead.
- The zip holds `code/` filtered by `.distignore`, so it never contains `config.php`, `data/` or docs.

## Deployment

After each successful release, [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) uploads the `.distignore`-filtered `code/` folder to your server over **explicit FTPS**: FTP on port 21 with TLS forced for both login and data. Uploads never delete files on the server, so `config.php` and `data/` there are left alone.

If the FTP account is locked to the gateway folder (e.g. `public_html/razorpay`), the remote path is `/`.

| Name | Type | Value |
|---|---|---|
| `FTP_HOST` | secret | FTP server hostname. On shared hosting, use the server's own hostname from cPanel (e.g. `server123.examplehost.com`), not `ftp.yourdomain.com` (see below) |
| `FTP_PORT` | variable | `21` |
| `FTP_REMOTE_PATH` | variable | `/` (the FTP account's root) |
| `FTP_SSL_VERIFY` | variable | optional, default `true`; set to `false` only as a last resort, since it skips checking the server's identity |
| `FTP_USERNAME` | secret | FTP user |
| `FTP_PASSWORD` | secret | FTP password (`gh secret set FTP_PASSWORD`) |
| `DEPLOY_ENABLED` | variable | `true` to switch automatic deploys on |

**First deploy**
1. Actions → the *Deploy* workflow → *Run workflow* with "deploy" unticked. This only checks the login.
2. Run `gh variable set DEPLOY_ENABLED --body true`, then run the workflow again with "deploy" ticked, or merge any PR.
3. On the server, create `config.php` from `config.sample.php` (see Setup).

Until `DEPLOY_ENABLED` is `true`, merges still create releases but deploy nothing.

**Certificate error on shared hosting.** If the connection check fails with `certificate common name doesn't match requested host name`, the server is presenting the hosting company's shared certificate (e.g. `*.examplehost.com`) rather than one for your domain. Set `FTP_HOST` to the server hostname shown in cPanel, which that certificate covers, so verification stays on. Check which names the certificate covers with:

```bash
openssl s_client -starttls ftp -connect ftp.yourdomain.com:21 </dev/null 2>/dev/null | openssl x509 -noout -subject -ext subjectAltName
```

## Files

| Path | Purpose |
|---|---|
| `code/index.php` | Sends the owner to the setup wizard until configured; 404 afterwards |
| `code/setup.php` | First-run setup wizard, locked once `config.php` exists |
| `code/pay.php` | Entry point ResellerClub redirects to; creates the order and shows checkout |
| `code/callback.php` | Verifies the payment and posts the result back to ResellerClub |
| `code/webhook.php` | Razorpay webhook receiver |
| `code/src/ResellerClub.php` | ResellerClub checksum + response protocol |
| `code/src/Razorpay.php` | Minimal Razorpay REST client + signature checks |
| `code/src/Setup.php` | Setup code, validation and safe `config.php` writing |
| `code/src/helpers.php` | Shared helpers (escaping, logging, error pages) |
| `code/src/Store.php` | SQLite transaction store |
| `code/bin/unreconciled.php` | Lists paid-but-not-returned transactions |
| `code/data/` | SQLite DB and `gateway.log`, created on first run (git-ignored, web-blocked) |
| `code/.htaccess` | Blocks config, logs, dotfiles and private folders |

## Notes

- Amounts are charged in `currency` (default `INR`) using ResellerClub's `sellingcurrencyamount`. Set your ResellerClub selling currency to match.
- Payments are auto-captured (`payment_capture: 1`). The callback also captures any payment that is still only authorized.
- If your Razorpay account passes its fee to the customer (customer fee bearer / convenience fee), the customer is charged the invoice amount plus Razorpay's fee. The gateway accepts that, using the `fee` Razorpay reports. You're settled the invoice amount, and ResellerClub is told the invoice amount.
- Logs: `code/data/gateway.log`.
