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

1. **Deploy.** Everything that runs on the server lives in `code/`. Upload its contents to the folder served at `https://pay.fenixhost.in/razorpay` (Apache or LiteSpeed). The bundled `.htaccess` files block web access to `src/`, `bin/`, `data/` and `config.php`. Check that `https://pay.fenixhost.in/razorpay/config.sample.php` returns 403.
2. **Configure.** On the server, inside that folder, run `cp config.sample.php config.php` and fill it in:
   - `rc_key`: the key from ResellerClub's custom gateway screen (step 3).
   - `razorpay_key_id` / `razorpay_key_secret`: from Razorpay Dashboard → API Keys.
   - `razorpay_webhook_secret`: the secret you choose in step 4.
   - `base_url`: `https://pay.fenixhost.in/razorpay`.
   - `allowed_redirect_hosts`: your SuperSite / control panel hostnames (recommended).
   - Make sure the web server user can write to `data_dir`.
3. **ResellerClub.** Control Panel → Settings → Finance & Billing → Payment Gateways → *Add Custom Payment Gateway*:
   - Gateway URL: `https://pay.fenixhost.in/razorpay/pay.php`
   - Copy the generated **key** into `rc_key`.
   - Choose who can see it (customers / sub-resellers) and give it a display name such as "Razorpay (UPI / Cards / Netbanking)".
4. **Razorpay webhook.** Dashboard → Webhooks → Add:
   - URL: `https://pay.fenixhost.in/razorpay/webhook.php`
   - Secret: the same value as `razorpay_webhook_secret`
   - Events: `payment.captured`, `order.paid`
5. **Test** with `rzp_test_` keys: pay an invoice from a test customer account and check that it is marked paid in ResellerClub. Then switch to live keys.

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

After each successful release, [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) uploads the `.distignore`-filtered `code/` folder to `https://pay.fenixhost.in/razorpay` over SFTP. Uploads never delete files on the server, so `config.php` and `data/` there are left alone.

**One-time setup** (repo → Settings → Secrets and variables → Actions, or with `gh`):

| Name | Type | Example |
|---|---|---|
| `SFTP_HOST` | variable | `pay.fenixhost.in` |
| `SFTP_PORT` | variable | `22` (the default if unset) |
| `SFTP_REMOTE_PATH` | variable | absolute path of the `razorpay` folder, e.g. `/home/USER/pay.fenixhost.in/razorpay` |
| `SFTP_USERNAME` | secret | SFTP user |
| `SFTP_PASSWORD` | secret | SFTP password |
| `DEPLOY_ENABLED` | variable | `true` to switch automatic deploys on |

```bash
gh variable set SFTP_HOST --body "pay.fenixhost.in"
gh variable set SFTP_REMOTE_PATH --body "/home/USER/pay.fenixhost.in/razorpay"
gh secret set SFTP_USERNAME
gh secret set SFTP_PASSWORD
```

**First deploy**
1. Actions → *Deploy to pay.fenixhost.in* → *Run workflow* with "deploy" unticked. This only checks the login and the destination's parent folder.
2. Run `gh variable set DEPLOY_ENABLED --body true`, then run the workflow again with "deploy" ticked, or merge any PR.
3. On the server, create `config.php` from `config.sample.php` (see Setup).

Until `DEPLOY_ENABLED` is `true`, merges still create releases but deploy nothing.

## Files

| Path | Purpose |
|---|---|
| `code/pay.php` | Entry point ResellerClub redirects to; creates the order and shows checkout |
| `code/callback.php` | Verifies the payment and posts the result back to ResellerClub |
| `code/webhook.php` | Razorpay webhook receiver |
| `code/src/ResellerClub.php` | ResellerClub checksum + response protocol |
| `code/src/Razorpay.php` | Minimal Razorpay REST client + signature checks |
| `code/src/Store.php` | SQLite transaction store |
| `code/bin/unreconciled.php` | Lists paid-but-not-returned transactions |
| `code/data/` | SQLite DB and `gateway.log`, created on first run (git-ignored, web-blocked) |
| `code/.htaccess` | Blocks config, logs, dotfiles and private folders |

## Notes

- Amounts are charged in `currency` (default `INR`) using ResellerClub's `sellingcurrencyamount`. Set your ResellerClub selling currency to match.
- Payments are auto-captured (`payment_capture: 1`). The callback also captures any payment that is still only authorized.
- Logs: `code/data/gateway.log`.
