<?php
/**
 * First-run setup wizard. Only works while config.php does not exist, and only
 * saves when the setup code from data/setup-code.txt is entered.
 */
declare(strict_types=1);
require __DIR__ . '/src/helpers.php';
require __DIR__ . '/src/Razorpay.php';
require __DIR__ . '/src/Setup.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$setup = new Setup();

if ($setup->isConfigured()) {
    fail_page('Setup already completed', 'This gateway is already configured. To run setup again, delete config.php on the server and reload this page.', 403);
}

$setupCodeError = null;
try {
    $setup->ensureCode();
} catch (Throwable $e) {
    $setupCodeError = 'Could not create the setup code in the data/ folder. Make sure PHP can write to this folder.';
}

$checks = $setup->environmentChecks();
$envOk = $setup->environmentOk();

$values = [
    'base_url'      => gw_detect_base_url(),
    'currency'      => 'INR',
    'theme_color'   => '#0b5fff',
];
$errors = [];
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = array_map(fn($v) => is_string($v) ? $v : '', $_POST);
    $codeOk = $setup->checkCode($posted['setup_code'] ?? '');
    // Refill everything that was typed, secrets included: they only go back to the browser that just sent them.
    foreach (array_merge(['setup_code'], Setup::FIELDS) as $f) {
        $values[$f] = $posted[$f] ?? '';
    }

    if (!$codeOk) {
        usleep(800000);
        $errors['setup_code'] = 'That setup code is not correct. Copy it from data/setup-code.txt on the server.';
        gw_log('Setup: wrong setup code', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    } elseif (!$envOk) {
        $errors['_form'] = 'Fix the server checks marked ✗ above, then submit again.';
    } else {
        [$errors, $config] = $setup->validate($posted, function (string $id, string $secret): ?string {
            try {
                (new Razorpay($id, $secret))->verifyCredentials();
                return null;
            } catch (Throwable $e) {
                return 'Razorpay did not accept these keys (' . $e->getMessage() . ').';
            }
        });
        if (!$errors) {
            try {
                $setup->writeConfig($config);
                gw_log('Setup completed', ['mode' => $config['mode']]);
                $done = $config;
            } catch (Throwable $e) {
                $errors['_form'] = 'Could not save config.php: ' . $e->getMessage();
            }
        }
    }
}

/** After saving: confirm config.php is not readable over the web. */
function config_exposure_check(string $baseUrl): array
{
    $ch = curl_init($baseUrl . '/config.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => false]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code === 0) {
        return [null, 'Could not reach ' . $baseUrl . '/config.php from the server. Open it in your browser and check that it shows "Forbidden".'];
    }
    if ($code === 403 || $code === 404) {
        return [true, "config.php is blocked from the web (HTTP $code)."];
    }
    return [false, "config.php answered HTTP $code. It must return 403: check that .htaccess files are allowed (AllowOverride) on this server."];
}

$field = function (string $name, string $label, string $help = '', string $type = 'text', array $attrs = []) use (&$values, &$errors): string {
    $id = 'f_' . $name;
    $attr = '';
    foreach ($attrs as $k => $v) {
        $attr .= ' ' . $k . '="' . h($v) . '"';
    }
    $input = $type === 'textarea'
        ? '<textarea id="' . $id . '" name="' . $name . '" rows="3"' . $attr . '>' . h($values[$name] ?? '') . '</textarea>'
        : '<input id="' . $id . '" name="' . $name . '" type="' . $type . '" value="' . h($values[$name] ?? '') . '"' . $attr . '>';
    return '<div class="field' . (isset($errors[$name]) ? ' has-error' : '') . '">'
        . '<label for="' . $id . '">' . h($label) . (isset($attrs['required']) || isset($attrs['data-req']) ? ' <span class="req" aria-hidden="true"' . (isset($attrs['required']) ? '' : ' style="display:none"') . '>*</span>' : '') . '</label>' . $input
        . (isset($errors[$name]) ? '<div class="error">' . h($errors[$name]) . '</div>' : '')
        . ($help !== '' ? '<div class="help">' . $help . '</div>' : '')
        . '</div>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Gateway setup</title>
<style>
  :root{--accent:#0b5fff;--text:#1d1f23;--muted:#5f6368;--line:#e3e5e8;--bg:#f5f6f8;--ok:#137333;--bad:#c5221f}
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);margin:0;line-height:1.45}
  main{max-width:640px;margin:40px auto;padding:0 16px}
  .card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:0 0 12px}
  .muted{color:var(--muted);font-size:14px}
  .field{margin-bottom:14px} label{display:block;font-weight:600;font-size:14px;margin-bottom:4px}
  input,textarea{width:100%;padding:10px;border:1px solid #c9ccd1;border-radius:8px;font:inherit}
  input:focus,textarea:focus{outline:2px solid var(--accent);border-color:transparent}
  .help{font-size:13px;color:var(--muted);margin-top:4px} .help code,.muted code{background:#f1f3f4;padding:1px 4px;border-radius:4px}
  .has-error input,.has-error textarea,input:user-invalid,textarea:user-invalid{border-color:var(--bad)} .error{color:var(--bad);font-size:13px;margin-top:4px}
  .req{color:var(--bad)} .reqnote{font-size:13px;color:var(--muted);margin:0 0 12px}
  .banner{padding:12px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .banner.bad{background:#fce8e6;color:var(--bad)} .banner.ok{background:#e6f4ea;color:var(--ok)} .banner.warn{background:#fef7e0;color:#8a5a00}
  ul.checks{list-style:none;padding:0;margin:0} ul.checks li{padding:4px 0;font-size:14px}
  .y{color:var(--ok)} .n{color:var(--bad)}
  button{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:12px 18px;font-size:16px;cursor:pointer;width:100%}
  .copy{display:flex;gap:8px;align-items:center;margin:6px 0 14px} .copy input{font-family:ui-monospace,monospace;font-size:13px}
  .copy button{width:auto;padding:9px 12px;font-size:14px}
  .check label{display:flex;gap:8px;align-items:center;font-weight:600} .check input{width:auto}
  ol.steps{margin:0 0 16px;padding-left:20px;font-size:14px} ol.steps li{margin-bottom:8px} ol.steps .copy{margin:6px 0 0}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:12px} @media(max-width:560px){.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
<?php if ($done): ?>
  <?php [$exposed, $exposureMsg] = config_exposure_check($done['base_url']); ?>
  <div class="card">
    <h1>Setup complete</h1>
    <p class="muted">config.php has been saved and this wizard is now locked. Mode:
      <strong><?= $done['mode'] === 'test' ? 'SANDBOX (TEST)' : 'LIVE' ?></strong>.</p>
    <?php if ($done['mode'] === 'test'): ?>
      <div class="banner warn">Sandbox mode: payments use your Razorpay test keys and no real money moves.
        <?= $done['test_customer_ids'] ? 'Only these ResellerClub customer IDs can pay: ' . h(implode(', ', $done['test_customer_ids'])) . '.' : 'No test customer IDs are set, so only ResellerClub\'s own test buttons can pay.' ?>
        Everyone else sees "temporarily unavailable". To go live, add your live keys to config.php and set <code>'mode' => 'live'</code>.</div>
    <?php endif; ?>
    <div class="banner <?= $exposed === true ? 'ok' : ($exposed === false ? 'bad' : 'warn') ?>"><?= h($exposureMsg) ?></div>
    <?php if (!$done['allowed_redirect_hosts']): ?>
      <div class="banner warn">No allowed return hosts set, so any return address is accepted. Add your storefront hostnames to config.php when you can.</div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>1. ResellerClub</h2>
    <p class="muted">Double-check that your custom gateway in ResellerClub (Settings → Finance &amp; Billing → Payment Gateways) uses this gateway URL:</p>
    <div class="copy"><input readonly value="<?= h($done['base_url']) ?>/pay.php"><button type="button" onclick="cp(this)">Copy</button></div>
  </div>
  <div class="card">
    <h2>2. Razorpay webhook</h2>
    <p class="muted">Razorpay Dashboard → Account &amp; Settings → Webhooks → Add new webhook.</p>
    <label>Webhook URL</label>
    <div class="copy"><input readonly value="<?= h($done['base_url']) ?>/webhook.php"><button type="button" onclick="cp(this)">Copy</button></div>
    <label>Secret</label>
    <div class="copy"><input readonly value="<?= h($done['razorpay_webhook_secret']) ?>"><button type="button" onclick="cp(this)">Copy</button></div>
    <p class="muted">Active events: <code>payment.captured</code> and <code>order.paid</code>. Razorpay keeps separate webhooks for Test mode and Live mode: add this same URL and secret in each mode you use. The secret is also stored in config.php.</p>
  </div>
  <div class="card">
    <h2>3. Test</h2>
    <p class="muted">Pay a small invoice from a test customer account and check that it shows as paid in ResellerClub. When you switch to live keys, edit them in config.php.</p>
  </div>
<?php else: ?>
  <div class="card">
    <h1>Razorpay gateway setup</h1>
    <p class="muted">Fill this in once. After saving, this page locks itself.</p>
  </div>

  <div class="card">
    <h2>Server checks</h2>
    <ul class="checks">
      <?php foreach ($checks as $c): ?>
        <li><span class="<?= $c['ok'] ? 'y' : 'n' ?>"><?= $c['ok'] ? '✓' : '✗' ?></span> <?= h($c['label']) ?> <span class="muted">— <?= h($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <form method="post" autocomplete="off">
    <p class="reqnote"><span class="req">*</span> Required</p>
    <?php if ($setupCodeError): ?><div class="banner bad"><?= h($setupCodeError) ?></div><?php endif; ?>
    <?php if (isset($errors['_form'])): ?><div class="banner bad"><?= h($errors['_form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['_form'])): ?><div class="banner bad">Please fix the highlighted fields.</div><?php endif; ?>

    <div class="card">
      <h2>Prove you own this server</h2>
      <?= $field('setup_code', 'Setup code', 'In cPanel File Manager (or over FTP), open <code>data/setup-code.txt</code> inside this folder and paste the code here.', 'text', ['placeholder' => 'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX', 'spellcheck' => 'false', 'required' => 'required', 'autocapitalize' => 'characters', 'pattern' => '[A-Za-z0-9 \\-]{25,40}', 'title' => '25 letters and digits, e.g. ABCDE-FGHJK-MNPQR-STUVW-XYZ23']) ?>
    </div>

    <div class="card">
      <h2>ResellerClub</h2>
      <p class="muted">Create the custom payment gateway in ResellerClub first. It gives you the key to enter below.</p>
      <ol class="steps">
        <li>Log in to your ResellerClub <strong>Control Panel</strong> and go to <strong>Settings → Finance &amp; Billing → Payment Gateways</strong>.</li>
        <li>Click <strong>Add Custom Payment Gateway</strong>, or edit your existing one.</li>
        <li>Set the <strong>Gateway URL</strong> to:
          <div class="copy"><input id="gw-url" readonly value="<?= h(rtrim($values['base_url'] ?? '', '/')) ?>/pay.php"><button type="button" onclick="cp(this)">Copy</button></div>
        </li>
        <li>Give it a display name your customers will see, e.g. <em>Razorpay (UPI / Cards / Netbanking)</em>, choose who can use it, and save.</li>
        <li>ResellerClub then shows a <strong>key</strong> for this gateway. Copy it and paste it here:</li>
      </ol>
      <?= $field('rc_key', 'Gateway key', 'The key from step 5. It ties ResellerClub to this gateway, so keep it private.', 'password', ['required' => 'required', 'pattern' => '\\S+', 'title' => 'The key has no spaces']) ?>
    </div>

    <div class="card">
      <h2>Razorpay</h2>
      <p class="muted">Razorpay Dashboard → Account &amp; Settings → API Keys. Each key pair is checked with Razorpay before saving.</p>
      <?php $sandboxOn = in_array($values['sandbox'] ?? '', ['1', 'on'], true); ?>
      <div id="live-keys">
        <?= $field('razorpay_live_key_id', 'Live key ID', 'Generated in <strong>Live mode</strong>. Optional in sandbox mode: you can add it to config.php later.', 'text', ['placeholder' => 'rzp_live_…', 'spellcheck' => 'false', 'pattern' => 'rzp_live_[A-Za-z0-9]+', 'title' => 'Starts with rzp_live_', 'data-req' => '1'] + ($sandboxOn ? [] : ['required' => 'required'])) ?>
        <?= $field('razorpay_live_key_secret', 'Live key secret', '', 'password', ['data-req' => '1'] + ($sandboxOn ? [] : ['required' => 'required'])) ?>
      </div>
      <div class="field check">
        <label><input type="checkbox" name="sandbox" value="1" id="f_sandbox"<?= $sandboxOn ? ' checked' : '' ?>> Sandbox mode (test without real money)</label>
        <div class="help">Uses your Razorpay <strong>test</strong> keys, and only the ResellerClub customer IDs you list can pay. Everyone else sees "temporarily unavailable", so nobody can get a free order with public test cards.</div>
      </div>
      <div id="sandbox-fields"<?= $sandboxOn ? '' : ' hidden' ?>>
        <?= $field('razorpay_test_key_id', 'Test key ID', 'Generated in <strong>Test mode</strong>.', 'text', ['placeholder' => 'rzp_test_…', 'spellcheck' => 'false', 'pattern' => 'rzp_test_[A-Za-z0-9]+', 'title' => 'Starts with rzp_test_', 'data-req' => '1'] + ($sandboxOn ? ['required' => 'required'] : [])) ?>
        <?= $field('razorpay_test_key_secret', 'Test key secret', '', 'password', ['data-req' => '1'] + ($sandboxOn ? ['required' => 'required'] : [])) ?>
        <?= $field('test_customer_ids', 'Test customer IDs', 'ResellerClub customer IDs allowed to pay while in sandbox mode, separated by commas, e.g. <code>490208</code>. Find them in ResellerClub under Customers.', 'text', ['placeholder' => '490208, 123456', 'pattern' => '[0-9, ]*', 'title' => 'Digits separated by commas']) ?>
      </div>
      <?= $field('razorpay_webhook_secret', 'Webhook secret', 'Leave blank to generate one. You\'ll paste it into Razorpay on the next screen.', 'password', ['minlength' => '12', 'title' => 'At least 12 characters, or leave blank']) ?>
      <div class="row">
        <?= $field('currency', 'Currency', 'Must match your ResellerClub selling currency.', 'text', ['maxlength' => '3', 'required' => 'required', 'pattern' => '[A-Za-z]{3}', 'title' => '3-letter code, e.g. INR']) ?>
        <?= $field('base_url', 'Public URL of this folder', 'Detected automatically. Must be https://.', 'url', ['required' => 'required']) ?>
      </div>
    </div>

    <div class="card">
      <h2>Branding</h2>
      <?= $field('merchant_name', 'Business name', 'Shown on the payment page and in the Razorpay popup.', 'text', ['required' => 'required', 'maxlength' => '80']) ?>
      <?= $field('merchant_logo', 'Logo URL (optional)', 'Square image on https://, at least 256×256. Also used as the browser-tab icon.', 'url', ['placeholder' => 'https://…/logo.png', 'pattern' => 'https://.+', 'title' => 'Must start with https://']) ?>
      <?= $field('theme_color', 'Brand colour', 'Hex colour for buttons and the popup.', 'text', ['placeholder' => '#0b5fff', 'required' => 'required', 'pattern' => '#[0-9A-Fa-f]{6}', 'title' => 'Hex colour like #0b5fff']) ?>
      <div class="row">
        <?= $field('support_email', 'Support email (optional)', 'Shown to customers on the payment and error pages.', 'email', ['placeholder' => 'support@yourbrand.com']) ?>
        <?= $field('support_phone', 'Support phone (optional)', 'Shown alongside the email.', 'tel', ['placeholder' => '+91 98765 43210', 'pattern' => '\\+?[0-9][0-9 \\(\\)\\-]{5,19}', 'title' => 'Digits, spaces, + ( ) - only']) ?>
      </div>
    </div>

    <div class="card">
      <h2>Security</h2>
      <?= $field('allowed_redirect_hosts', 'Allowed return hosts (recommended)', 'Hostnames ResellerClub may send customers back to after paying, one per line: your SuperSite and control panel domains, e.g. <code>www.yourbrand.com</code>, or <code>*.yourbrand.com</code> for every subdomain. ResellerClub\'s return address can be on a different host from your storefront. If a payment shows “Unrecognised return address”, the page names the host to add here or in config.php.', 'textarea') ?>
    </div>

    <button type="submit"<?= $envOk ? '' : ' disabled' ?>>Save configuration</button>
  </form>
<?php endif; ?>
</main>
<script>
  function cp(b){var i=b.previousElementSibling;i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);b.textContent='Copied';setTimeout(function(){b.textContent='Copy'},1500)}
  (function(){
    var base=document.getElementById('f_base_url'), gw=document.getElementById('gw-url');
    if(!base||!gw)return;
    base.addEventListener('input',function(){gw.value=base.value.trim().replace(/\/+$/,'')+'/pay.php'});
  })();
  (function(){
    var box=document.getElementById('f_sandbox'), sb=document.getElementById('sandbox-fields');
    if(!box||!sb)return;
    function sync(){
      var on=box.checked; sb.hidden=!on;
      function req(id,v){var el=document.getElementById(id); el.required=v; var star=el.parentNode.querySelector('label .req'); if(star)star.style.display=v?'':'none';}
      req('f_razorpay_test_key_id',on); req('f_razorpay_test_key_secret',on);
      req('f_razorpay_live_key_id',!on); req('f_razorpay_live_key_secret',!on);
    }
    box.addEventListener('change',sync); sync();
  })();
</script>
</body>
</html>
