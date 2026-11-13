# Razorpay Payment Gateway for ResellerClub

[![Release](https://github.com/tycheventures/RazorPay-Payment-gateway-for-ResellerClub/actions/workflows/release.yml/badge.svg)](https://github.com/tycheventures/RazorPay-Payment-gateway-for-ResellerClub/actions/workflows/release.yml)

A ResellerClub **Custom Payment Gateway** that takes payments through **Razorpay Standard Checkout** (UPI, cards, netbanking, wallets) in a popup, then sends the customer back to ResellerClub with a signed result.

Plain PHP, no Composer, no framework. Upload one folder, run the setup wizard, and you're done.

## Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Quick start](#quick-start)
4. [Test and live mode](#test-and-live-mode)
5. [Configuration reference](#configuration-reference)
6. [How a payment flows](#how-a-payment-flows)
7. [Troubleshooting](#troubleshooting)
8. [Security](#security)
9. [For maintainers](#for-maintainers)

## Features

- **Setup wizard:** a first-run web form instead of editing PHP. It checks your Razorpay keys live, then locks itself.
- **Popup checkout, no lost sessions:** no PHP sessions are used. A per-transaction token survives the trip to Razorpay and back.
- **Branded pages:** your logo (square or horizontal), brand colour and support contact on every page the customer sees.
- **Prefilled checkout:** the customer's name, email and mobile from ResellerClub are filled in for them.
- **Test and live mode:** sandbox mode limited to chosen test accounts. ResellerClub's own *Test* buttons always use your test keys.
- **Reliable confirmation:**
  - re-checks with Razorpay instead of failing on timing issues;
  - a webhook catches payments when the customer closes the tab;
  - an order is never charged twice.
- **Either fee setting works:** payments are accepted whether you or the customer pays Razorpay's fee, and you can switch that any time.
- **Debug log:** a step-by-step trace for test payments, optional for live ones.

## Requirements

- **PHP 8.1+** with the `curl`, `pdo_sqlite` and `json` extensions.
- **Web server:** Apache or LiteSpeed (uses the bundled `.htaccess` files), **or** nginx with the [rules below](#security).
- **HTTPS** on the domain that serves the gateway.
- **ResellerClub:** a reseller account, to add a custom payment gateway.
- **Razorpay:** an account with API keys. Test keys are enough to start.

## Quick start

### 1. Upload

Everything that runs on the server is in the [`code/`](code/) folder. Download the latest release zip from the [Releases page](../../releases). It unzips to a `razorpay/` folder. Upload it so it's served at, for example, `https://pay.example.com/razorpay/`.

### 2. Create the gateway in ResellerClub

1. ResellerClub Control Panel → **Settings → Finance & Billing → Payment Gateways → Add Custom Payment Gateway**.
2. **Gateway URL:** `https://pay.example.com/razorpay/pay.php`.
3. Choose a display name (e.g. *Razorpay (UPI / Cards / Netbanking)*) and who can use it, then save.
4. Copy the **key** ResellerClub shows. You'll need it in the next step.

The wizard repeats these steps, with the exact gateway URL to copy.

### 3. Run the setup wizard

1. Open `https://pay.example.com/razorpay/`. You'll be sent to `setup.php`.
2. The wizard writes a one-time **setup code** to `data/setup-code.txt`. Open that file in cPanel File Manager (or over FTP) and paste the code into the wizard. This proves you control the server.
3. Fill in:
   - the ResellerClub key;
   - your Razorpay **live** keys, **or** tick **Sandbox mode** and enter **test** keys plus the test account IDs;
   - branding: name, logo URL, colour, support email and phone;
   - any extra hosts customers may return to (usually none, since ResellerClub's own hosts are built in).
4. Before saving, the wizard checks your PHP setup, that `data/` can't be downloaded over the web, and your Razorpay keys (live API call). It then writes `config.php`, deletes the setup code and locks itself.

### 4. Add the Razorpay webhook

The wizard's final screen shows the webhook URL and secret. In the Razorpay Dashboard → **Account & Settings → Webhooks**, add:

- **URL:** `https://pay.example.com/razorpay/webhook.php`
- **Secret:** the one shown by the wizard (also in `config.php`)
- **Events:** `payment.captured`, `order.paid`

Razorpay keeps **separate webhook lists for Test mode and Live mode**: add the same URL and secret in both.

### 5. Test

Use ResellerClub's **Test For Payment** button on your gateway's settings screen, and pay with a Razorpay test card (`4111 1111 1111 1111`, any future expiry, any CVV) or the test UPI ID `success@razorpay`. You should see "Payment received" and land on ResellerClub's test result page.

**Changing settings later:** edit `config.php` (every option is described in [`code/config.sample.php`](code/config.sample.php)). Or delete it and open the folder again for a fresh wizard with a new setup code.

## Test and live mode

`config.php` has a `mode` setting:

| `mode` | Keys used | Who can pay |
|---|---|---|
| `live` | `razorpay_live_key_id` / `_secret` | everyone |
| `test` (sandbox) | `razorpay_test_key_id` / `_secret` | only accounts in `test_customer_ids`; everyone else sees *"Online payment is temporarily unavailable"* with a button back to the store |

Test-mode pages show a **TEST MODE — no real money will be charged** banner.

**Why sandbox mode limits who can pay:** your ResellerClub account is live. Without the list, anyone could "pay" with a public test card and have a real order marked paid. List only **dedicated test accounts**: customers or sub-resellers, using the ID shown on the account in ResellerClub.

**ResellerClub's test buttons:** *Test For Payment* and *Test For Add Fund* send a transaction ID containing "Test" (e.g. `Payment-Test-1`) and a return address on ResellerClub's **test servlet** (`…resellerclub.com/servlet/TestCustomPayment…`).
- Only when **both** are true do they **always use the test keys**, even in live mode, ignoring `test_customer_ids`.
- The transaction ID is covered by ResellerClub's checksum, and results for these go only to ResellerClub's test endpoint, so a real invoice can't be paid with test money.
- If no test keys are configured, these show "Test keys needed".

**Going live:** add your `rzp_live_` keys, set `'mode' => 'live'`, and make sure the webhook exists in Razorpay's **Live mode** too.

**Older configs** with a single `razorpay_key_id` / `razorpay_key_secret` still work: `rzp_test_` keys mean test mode, `rzp_live_` keys mean live.

## Configuration reference

All settings live in `config.php`. The wizard writes it, and you can edit it by hand.

| Setting | Default | What it does |
|---|---|---|
| `rc_key` | — | Key from your ResellerClub custom gateway. Used to verify requests and sign results. |
| `mode` | `live` | `live` or `test` (sandbox). See [Test and live mode](#test-and-live-mode). |
| `razorpay_live_key_id` / `_secret` | — | Razorpay live API keys (`rzp_live_…`). |
| `razorpay_test_key_id` / `_secret` | — | Razorpay test API keys (`rzp_test_…`). Needed for sandbox mode and ResellerClub's test buttons. |
| `test_customer_ids` | `[]` | ResellerClub customer or sub-reseller IDs allowed to pay in sandbox mode. |
| `razorpay_webhook_secret` | generated by the wizard | Secret for `webhook.php`. The webhook refuses to run while it's empty or a placeholder such as `CHANGE_ME`. |
| `razorpay_test_webhook_secret` | none | Optional separate secret for Razorpay's Test-mode webhook. Empty means test mode uses the secret above. |
| `currency` | `INR` | Must match your ResellerClub selling currency. |
| `base_url` | detected by the wizard | Public URL of the gateway folder, no trailing slash. |
| `merchant_name` | — | Business name on the pages and in the Razorpay popup. |
| `merchant_logo` | none | `https://` image URL. A horizontal wordmark replaces the name text; a square logo sits beside it. Also used as the favicon. |
| `theme_color` | `#0b5fff` | Brand colour for buttons, accents and the popup. |
| `support_email` / `support_phone` | none | Shown as "Need help? Contact …" on every page. |
| `allowed_redirect_hosts` | `[]` | Extra hosts customers may be returned to (`*.example.com` allowed). ResellerClub's `*.resellerclub.com` and `*.foundationapi.com` are always allowed. |
| `debug` | `false` | `true` also logs **live** transactions in `data/debug.log`. Test transactions are always logged. |
| `data_dir` | `data/` next to `config.php` | Where the SQLite database and logs live. Can point outside the web root. |
| `data_key` | set by the wizard | Random suffix on the database and log file names (e.g. `gateway-<key>.log`), so they can't be fetched by name. Changing it starts a new database. |

## How a payment flows

```
Customer clicks "Pay" in ResellerClub
        │  GET pay.php  (transaction details + MD5 checksum)
        ▼
pay.php ── verify checksum & return host ── pick test/live keys ── create Razorpay order
        │  branded page opens the Razorpay popup (prefilled name, email, mobile)
        ▼
Customer pays in the popup
        │  POST callback.php  (payment id + Razorpay signature)
        ▼
callback.php ── verify signature ── fetch payment from Razorpay API ── capture if needed
        │  auto-submits the signed result (status Y / N) to ResellerClub
        ▼
ResellerClub marks the invoice paid (Y) or not (N)

Razorpay ──webhook──▶ webhook.php   records the payment even if the customer never came back
```

**What happens when…**

| Situation | Result |
|---|---|
| Customer refreshes or comes back to the payment page | The same order is reused while Razorpay still shows it unpaid. If it's already paid, they're returned to ResellerClub as **paid**, never charged twice. |
| The same payment link is opened in several tabs at once | Only one Razorpay order is ever created per transaction. The other tabs reuse it. |
| Customer clicks **Cancel and return to store** | Returned to ResellerClub as **not paid**. |
| Razorpay is still finalising the payment | A "Confirming your payment…" page re-checks every 5 seconds, and returns the customer as soon as the payment is confirmed. |
| Customer closes the tab after paying | The webhook records the payment. ResellerClub isn't told, so see [Reconciliation](#reconciliation). |
| Customer pays Razorpay's fee | Charged amount = invoice + fee. Accepted, and ResellerClub is told the invoice amount. |

## Troubleshooting

### Debug log

`data/debug.log` records every step of a payment, one line each, tied together by a short request ID:
- the request from ResellerClub and the checksum, return-host and mode decisions;
- whether an order was reused or created;
- every Razorpay API call and reply;
- the signature check and each confirmation attempt (expected and actual order, amount and currency);
- the result sent back, and webhook events.

**What's logged and what isn't:**
- **Never logged:** key secrets, checksums, Razorpay signatures, full return URLs (only the host), and customer names, emails, phones and addresses.
- **Razorpay replies** are reduced to non-personal fields (IDs, status, amounts, fee, error codes).

**When it's on:**
- **Test-mode transactions** are always logged: sandbox mode, and ResellerClub test buttons once their request is verified.
- **Live transactions** are logged only while `'debug' => true`. That log then contains payment details, so switch it off when you're done.

The file is blocked from the web and rotates at 5 MB. The shorter `data/gateway.log` keeps one line per important event.

### Error pages

| Page | Why | Fix |
|---|---|---|
| **Payment page not available yet** | `config.php` doesn't exist | Run the setup wizard |
| **Invalid payment request** (could not be verified) | Checksum mismatch | Check that `rc_key` matches the key in ResellerClub |
| **Unrecognised return address: *host*** | The return host isn't allowed | Add that host to `allowed_redirect_hosts` if it's yours |
| **Payment temporarily unavailable** | Sandbox mode and the account isn't in `test_customer_ids`; keys for the current mode are missing; or `data/` is readable over the web | Add the account ID, add the keys, or block `data/` (see [Security](#security)) |
| **Test keys needed** | A ResellerClub test button was used without test keys | Add `razorpay_test_key_id` / `_secret` |
| **Payment unavailable** | Razorpay order creation failed | Check the keys and `debug.log` |
| **Payment not verified** | Bad signature, or the payment doesn't match the order | `gateway.log` names the mismatched field |
| **Payment not confirmed** (test payment) | A test-mode payment for an order that's no longer allowed to use test mode (e.g. sandbox mode switched off meanwhile) | Expected: test money can't settle a live order |
| **Invalid payment request** (must be https) | ResellerClub's return URL isn't `https`, or has a username or odd port | Check the return URL ResellerClub sends |
| **Server not protected** (wizard) | `data/` can be downloaded | Block `data/`, `src/` and `bin/`, then reload |

### Reconciliation

ResellerClub never confirms back to the gateway. The gateway can tell whether the customer **reached the "Payment received → returning to store" page**, but not whether ResellerClub actually recorded the payment. The reconciliation report lists paid **live** payments that may need a look. Test-mode payments are never listed.

On the server, the script is in the folder you uploaded (not `code/`). With the example layout from the Quick start:

```bash
/usr/local/bin/php /home/USER/public_html/razorpay/bin/unreconciled.php
```

Replace `/home/USER/public_html/razorpay` with your gateway folder's real path. Running `pwd` inside it over SSH shows it.

**Reading the report**

| Section | What it means | What to do |
|---|---|---|
| **ACTION NEEDED** | The money was captured, but the customer **never** reached the result page (closed the tab, lost connection). ResellerClub doesn't know about the payment. | Add the payment in ResellerClub by hand, e.g. mark the invoice paid or add the funds, then confirm it (below). |
| **CHECK** | The result page **was** shown, so ResellerClub almost certainly recorded it. It's only listed because nobody has confirmed it. | Optionally check it in ResellerClub, then confirm it so it stops appearing. |

Each row shows the date, the ResellerClub transaction ID, the amount, and the Razorpay order and payment IDs, so you can find the payment in both dashboards.

**Confirming a transaction** once you've checked it in ResellerClub:

```bash
/usr/local/bin/php /home/USER/public_html/razorpay/bin/unreconciled.php --confirm <transid>
```

It prints `Marked 1 paid payment(s) … as confirmed`. Running it twice is harmless.

The script exits with code **1** when something is under ACTION NEEDED, and **0** otherwise (including when only CHECK rows exist).

#### Daily check by cron (recommended once you take live payments)

1. **Find the right PHP.** Cron runs with a minimal environment, so always use the full path to a PHP 8.1+ binary rather than plain `php`. Over SSH, check:
   ```bash
   /usr/local/bin/php -v
   ```
   If that's missing or older than 8.1, cPanel servers usually also have `/opt/cpanel/ea-php81/root/usr/bin/php` (or `ea-php82`, `ea-php83`).
2. **Use a command that's silent unless something needs action.** cPanel emails *any* output a cron job prints. This prints, and therefore emails, only when there are ACTION NEEDED payments:
   ```bash
   out=$(/usr/local/bin/php /home/USER/public_html/razorpay/bin/unreconciled.php) || echo "$out"
   ```
   Run it once over SSH first. No output means all is well.
3. **Add it in cPanel → Cron Jobs:**
   - **Cron Email** (top of the page): the address that should receive alerts. Click *Update Email*. If it's left empty, cPanel uses the account's contact email.
   - **Add New Cron Job:** *Common Settings* → *Once Per Day* (or minute `0`, hour `9`), with the command from step 2.

**What you'll get:** no email on normal days. When a customer pays and doesn't make it back to ResellerClub, you get an email listing that payment the next morning. CHECK rows never trigger an email: run the report by hand now and then (e.g. weekly) to spot-check and confirm them.

## Security

- **Request integrity:**
  - **From ResellerClub:** requests are verified with ResellerClub's MD5 checksum.
  - **From the checkout:** results are verified with Razorpay's HMAC-SHA256 signature **and** a server-side API fetch of the payment (order, amount, currency).
  - **From the webhook:** checked with HMAC-SHA256.
- **Return URL:** this isn't covered by ResellerClub's checksum, so it must be `https`, with no username or non-standard port, on an allowed host. It's then rebuilt in canonical form before use. ResellerClub's own hosts are built in, and an empty list never means "allow any".
- **Settlement:** a payment is only marked paid after Razorpay's API confirms it (captured, right order, amount and currency), using the keys of the order's own mode. That includes payments that arrive through the webhook. Only one open order can exist per transaction.
- **Test/live boundary:** a success result for a test-mode payment is only ever signed for ResellerClub's test servlet or a listed sandbox account.
- **Test mode can't be forced:** test-key routing uses the checksummed transaction ID, and sandbox mode uses the checksummed customer ID. Customer name, email and phone are used only to prefill the popup.
- **Private files:** `config.php`, `src/`, `bin/` and `data/` are blocked by `.htaccess`. The wizard and the payment page actively check that `data/` can't be downloaded, and refuse to run if it can.
- **Wizard:** needs a one-time code from a randomly named file in `data/`, checks `data/` over the web (pinned to this server's own address) before creating it, never overwrites an existing `config.php`, and returns 403 once configured.
- **Logs:** never contain key secrets, checksums, signatures, full return URLs or customer contact details. Razorpay replies are reduced to non-personal fields. Both logs rotate at 5 MB, and detailed logging can only be switched on by configuration or a verified test transaction.

**Servers that ignore `.htaccess`** (nginx, or Apache with `AllowOverride None`) need their own rules. For nginx:

```nginx
location ~ /(data|src|bin)/ { deny all; }
location ~ /config(\.sample)?\.php$ { deny all; }
```

Alternatively, set `data_dir` to a folder outside the web root.

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## For maintainers

### Workflow

Every change follows: **issue → branch from the issue → commits → pull request → squash merge into `main`**. `main` is protected: changes go through pull requests, and force-pushes and deletion are blocked.

### Releases

[`.github/workflows/release.yml`](.github/workflows/release.yml) runs when a merge to `main` changes `code/` or `.distignore`.
- **What it does:** tags the next version and publishes a GitHub release with `razorpay-resellerclub-gateway-vX.Y.Z.zip`, which unzips to `razorpay/`.
- **Version numbers:** patch bump by default. Add the `release:minor` or `release:major` label to the PR to bump those instead.
- **Docs-only and workflow-only merges** make no release.

### Deployment

[`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) uploads the `.distignore`-filtered `code/` folder over **explicit FTPS** (TLS forced) after each successful release.
- It never deletes files on the server, so `config.php` and `data/` are left alone.
- It only uploads while `DEPLOY_ENABLED` is `true`.
- Only `.php` and `.htaccess` files may be packaged or uploaded (no symlinks), and the FTP settings are validated before use.
- A manual *Run workflow* checks the login, and uploads only if "deploy" is ticked.

| Setting | Type | Notes |
|---|---|---|
| `FTP_HOST` | secret | On shared hosting, use the server's own hostname from cPanel if `ftp.yourdomain.com` fails certificate verification |
| `FTP_USERNAME` / `FTP_PASSWORD` | secret | FTP account, ideally limited to the gateway folder |
| `FTP_PORT` | variable | Default `21` |
| `FTP_REMOTE_PATH` | variable | Default `/` (the FTP account's root) |
| `FTP_SSL_VERIFY` | variable | Default `true`; `false` only as a last resort |
| `DEPLOY_ENABLED` | variable | `true` to switch automatic deploys on |

The `production` environment only accepts deployments from `main`. GitHub Actions are pinned to commit SHAs and kept up to date by Dependabot.

### Local testing

```bash
cp code/config.sample.php code/config.php   # fill in test values
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8099 -t code
```

- **Mock Razorpay:** set `GW_RAZORPAY_API=http://127.0.0.1:<port>` to point the Razorpay client at a mock API. It's only honoured under PHP's built-in server or the CLI, never on a production web server.
- **Why several workers:** the payment page and wizard make HTTP requests to themselves (the `data/` check), which a single-worker `php -S` can't answer. PHP's built-in server ignores `.htaccess`, so the `data/` check reports it as exposed: that's expected locally.

### Files

| Path | Purpose |
|---|---|
| `code/index.php` | Sends the owner to the wizard until configured; 404 afterwards |
| `code/setup.php` | First-run setup wizard (page) |
| `code/pay.php` | Entry point from ResellerClub: verifies the request, creates the order, shows checkout |
| `code/callback.php` | Verifies and confirms the payment, posts the signed result to ResellerClub |
| `code/webhook.php` | Razorpay webhook receiver |
| `code/config.sample.php` | Every setting, documented |
| `code/src/Setup.php` | Wizard logic: setup code, validation, safe `config.php` writing (not web-accessible) |
| `code/src/Razorpay.php` | Minimal Razorpay REST client and signature checks |
| `code/src/ResellerClub.php` | ResellerClub checksum and response protocol |
| `code/src/Store.php` | SQLite transaction store |
| `code/src/helpers.php` | Layout, branding, logging, mode and host rules |
| `code/bin/unreconciled.php` | Reconciliation report and `--confirm` (see [Reconciliation](#reconciliation)) |
| `code/data/` | Database and logs, created on first run (web-blocked, git-ignored) |

## License

[GPL-3.0](LICENSE)
