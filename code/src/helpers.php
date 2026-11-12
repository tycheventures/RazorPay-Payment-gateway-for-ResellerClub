<?php
declare(strict_types=1);

/** Folder holding code/ (entry points, config.php). */
define('GW_CODE_DIR', dirname(__DIR__));
define('GW_CONFIG_FILE', GW_CODE_DIR . '/config.php');
define('GW_DEFAULT_DATA_DIR', GW_CODE_DIR . '/data');

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function gw_data_dir(): string
{
    return $GLOBALS['config']['data_dir'] ?? GW_DEFAULT_DATA_DIR;
}

/** Create a directory that must never be served over the web. */
function gw_protect_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
}

/**
 * Path of a runtime file in data/. Installs set up by the wizard get an
 * unguessable suffix (config 'data_key'), e.g. gateway-3f9c….log, so the files
 * can't be fetched by name even on a server that ignores .htaccess.
 */
function gw_data_file(string $name): string
{
    $key = (string) ($GLOBALS['config']['data_key'] ?? '');
    if ($key !== '' && preg_match('/^[a-f0-9]{16,64}$/', $key)) {
        $dot = strrpos($name, '.');
        $name = substr($name, 0, $dot) . '-' . $key . substr($name, $dot);
    }
    return gw_data_dir() . '/' . $name;
}

/** Append to a log file, keeping it under 5 MB (one previous file kept as .1). */
function gw_append_log(string $file, string $text): void
{
    if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    @file_put_contents($file, $text, FILE_APPEND | LOCK_EX);
}

function gw_log(string $message, array $context = []): void
{
    if (!is_dir(gw_data_dir())) {
        return;
    }
    $line = '[' . date('c') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    gw_append_log(gw_data_file('gateway.log'), $line . PHP_EOL);
}

/*
 * Step-by-step debug log (data/debug.log). Lines are buffered from the start of
 * the request and written once debug is switched on, so early steps are kept too.
 * On for test-mode transactions, and for everything when config 'debug' is true.
 */
function gw_debug(string $step, array $ctx = []): void
{
    $d = &$GLOBALS['gw_dbg'];
    $d ??= ['on' => false, 'buf' => [], 'rid' => bin2hex(random_bytes(3)), 'why' => ''];
    $line = '[' . date('c') . '] ' . $d['rid'] . ' ' . basename($_SERVER['SCRIPT_NAME'] ?? 'cli') . ' | ' . $step;
    if ($ctx) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    $d['buf'][] = $line;
    if ($d['on']) {
        gw_debug_flush();
    }
}

function gw_debug_enable(string $why): void
{
    $d = &$GLOBALS['gw_dbg'];
    $d ??= ['on' => false, 'buf' => [], 'rid' => bin2hex(random_bytes(3)), 'why' => ''];
    if ($d['on']) {
        return;
    }
    $d['on'] = true;
    $d['why'] = $why;
    array_unshift($d['buf'], '[' . date('c') . '] ' . $d['rid'] . ' ' . basename($_SERVER['SCRIPT_NAME'] ?? 'cli') . ' | debug on: ' . $why);
    gw_debug_flush();
    register_shutdown_function('gw_debug_flush');
}

function gw_debug_flush(): void
{
    $d = &$GLOBALS['gw_dbg'];
    if (empty($d['on']) || !$d['buf']) {
        return;
    }
    if (!is_dir(gw_data_dir())) {
        return;
    }
    gw_append_log(gw_data_file('debug.log'), implode(PHP_EOL, $d['buf']) . PHP_EOL);
    $d['buf'] = [];
}

/** Branding from config.php, with safe defaults when unconfigured or values are invalid. */
function gw_brand(): array
{
    $c = $GLOBALS['config'] ?? [];
    $color = (string) ($c['theme_color'] ?? '');
    $logo = (string) ($c['merchant_logo'] ?? '');
    $email = (string) ($c['support_email'] ?? '');
    return [
        'name'  => trim((string) ($c['merchant_name'] ?? '')) ?: 'Secure payment',
        'logo'  => (filter_var($logo, FILTER_VALIDATE_URL) && str_starts_with($logo, 'https://')) ? $logo : '',
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0b5fff',
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'phone' => trim((string) ($c['support_phone'] ?? '')),
    ];
}

/** "Contact us at …" sentence, or '' when no support contact is configured. */
function gw_support_html(): string
{
    $b = gw_brand();
    $parts = [];
    if ($b['email'] !== '') {
        $parts[] = '<a href="mailto:' . h($b['email']) . '">' . h($b['email']) . '</a>';
    }
    if ($b['phone'] !== '') {
        $parts[] = '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $b['phone'])) . '">' . h($b['phone']) . '</a>';
    }
    return $parts ? 'Need help? Contact ' . implode(' or ', $parts) . '.' : '';
}

/** Start a branded page. Pair with gw_page_close(). */
function gw_page_open(string $title, int $code = 200, string $extraHead = ''): void
{
    $b = gw_brand();
    http_response_code($code);
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . ' – ' . h($b['name']) . '</title>'
        . ($b['logo'] !== '' ? '<link rel="icon" href="' . h($b['logo']) . '">' : '')
        . '<style>'
        . ':root{--brand:' . $b['color'] . ';--text:#1d1f23;--muted:#5f6368;--bg:#f5f6f8}'
        . '*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);margin:0;line-height:1.45}'
        . '.wrap{max-width:560px;margin:7vh auto;padding:0 16px}'
        . '.brand{display:flex;align-items:center;justify-content:center;gap:14px;margin-bottom:24px;min-height:64px}'
        . '.brand img{height:64px;width:auto;max-width:min(320px,100%);object-fit:contain}'
        . '.brand.square img{width:64px;border-radius:14px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.08)}'
        . '.brand span{font-weight:700;font-size:26px;letter-spacing:-.01em}'
        . '.brand.wide span{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'
        . '.card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.08);border-top:5px solid var(--brand)}'
        . '@media(max-width:480px){.card{padding:24px 20px}.brand img{height:52px}.brand span{font-size:22px}}'
        . 'h1{font-size:24px;margin:0 0 12px}p{font-size:16px}.muted{color:var(--muted);font-size:14px}'
        . '.amount{font-size:32px;font-weight:600;margin:16px 0 4px}.ref{font-size:13px;color:var(--muted);margin-bottom:20px}'
        . 'button{width:100%;padding:14px;border:0;border-radius:8px;font-size:16px;cursor:pointer}'
        . '.pay{background:var(--brand);color:#fff}.cancel{background:none;color:var(--muted);margin-top:8px;font-size:14px}'
        . '.secondary{background:#fff;color:var(--text);border:1px solid #c9ccd1;margin-top:8px;font-size:15px}.secondary:hover{border-color:var(--brand);color:var(--brand)}'
        . '.support{font-size:14px;margin:24px 0 0;padding-top:16px;border-top:1px solid #eceef1}.support a{color:var(--brand);white-space:nowrap}'
        . 'footer{text-align:center;font-size:12px;color:var(--muted);margin-top:16px}'
        . '.spinner{width:28px;height:28px;border:3px solid #e3e5e8;border-top-color:var(--brand);border-radius:50%;animation:s 1s linear infinite;margin:8px auto 16px}'
        . '@keyframes s{to{transform:rotate(360deg)}}'
        . '.testmode{background:#fef7e0;color:#8a5a00;border:1px dashed #e0b84c;border-radius:8px;padding:8px 12px;font-size:13px;font-weight:600;text-align:center;margin-bottom:16px}'
        . '</style>' . $extraHead . '</head><body><div class="wrap">'
        // A wide logo (wordmark) already shows the name, so the text name is then kept for screen readers only.
        . '<div class="brand' . ($b['logo'] !== '' ? ' wide' : '') . '" id="brand">'
        . ($b['logo'] !== '' ? '<img src="' . h($b['logo']) . '" alt="" onload="if(this.naturalWidth<this.naturalHeight*1.6){document.getElementById(\'brand\').className=\'brand square\'}" onerror="this.remove();document.getElementById(\'brand\').className=\'brand\'">' : '')
        . '<span>' . h($b['name']) . '</span></div>'
        . '<div class="card">'
        . (!empty($GLOBALS['gw_test_banner']) ? '<div class="testmode">TEST MODE — no real money will be charged</div>' : '');
}

function gw_page_close(string $scripts = ''): void
{
    $support = gw_support_html();
    echo ($support !== '' ? '<p class="support muted">' . $support . '</p>' : '')
        . '</div><footer>🔒 Payments secured by Razorpay</footer></div>' . $scripts . '</body></html>';
}

function fail_page(string $title, string $message, int $code = 400, string $actionsHtml = ''): never
{
    gw_page_open($title, $code);
    echo '<h1>' . h($title) . '</h1><p>' . h($message) . '</p>' . $actionsHtml;
    gw_page_close();
    exit;
}

/**
 * Fill in mode/key settings. Older configs with a single razorpay_key_id/secret
 * keep working: the key prefix decides whether they are test or live keys.
 */
function gw_normalize_config(array $c): array
{
    if (!empty($c['razorpay_key_id']) && empty($c['razorpay_live_key_id']) && empty($c['razorpay_test_key_id'])) {
        $kind = str_starts_with((string) $c['razorpay_key_id'], 'rzp_test_') ? 'test' : 'live';
        $c["razorpay_{$kind}_key_id"] = $c['razorpay_key_id'];
        $c["razorpay_{$kind}_key_secret"] = $c['razorpay_key_secret'] ?? '';
        $c['mode'] = $c['mode'] ?? $kind;
    }
    $c += [
        'mode' => 'live',
        'razorpay_live_key_id' => '', 'razorpay_live_key_secret' => '',
        'razorpay_test_key_id' => '', 'razorpay_test_key_secret' => '',
        'test_customer_ids' => [],
        'debug' => false,
        'data_key' => '',
        'razorpay_webhook_secret' => '',
        'razorpay_test_webhook_secret' => '',
    ];
    $c['debug'] = (bool) $c['debug'];
    $c['mode'] = $c['mode'] === 'test' ? 'test' : 'live';
    $c['test_customer_ids'] = array_values(array_filter(array_map('strval', (array) $c['test_customer_ids']), 'strlen'));
    return $c;
}

/** Key pair for a mode, or null when missing or of the wrong kind (rzp_test_ vs rzp_live_). */
function gw_mode_keys(string $mode): ?array
{
    $c = $GLOBALS['config'];
    $id = (string) ($c["razorpay_{$mode}_key_id"] ?? '');
    $secret = (string) ($c["razorpay_{$mode}_key_secret"] ?? '');
    if ($id === '' || $secret === '' || !str_starts_with($id, "rzp_{$mode}_")) {
        return null;
    }
    return [$id, $secret];
}

/** Razorpay client for a mode; throws if that mode has no usable keys. */
function gw_razorpay(string $mode): Razorpay
{
    $keys = gw_mode_keys($mode);
    if (!$keys) {
        throw new RuntimeException("No Razorpay {$mode} keys configured");
    }
    return new Razorpay($keys[0], $keys[1]);
}

/** Mode a stored transaction was created in (rows from before modes existed use the config mode). */
function gw_txn_mode(array $txn): string
{
    return in_array($txn['mode'] ?? null, ['test', 'live'], true) ? $txn['mode'] : $GLOBALS['config']['mode'];
}

/**
 * Amount the payment covers for the order, in paise. When the customer pays
 * Razorpay's fee (convenience fee / customer fee bearer), Razorpay reports
 * amount = order amount + fee, so the fee from the API response is taken off.
 */
function gw_payment_amount_matches(array $payment, int $expected): bool
{
    $amount = (int) ($payment['amount'] ?? 0);
    $fee = (int) ($payment['fee'] ?? 0);
    return $amount === $expected || ($fee > 0 && $amount - $fee === $expected);
}

/**
 * Is data/ readable over the web? Writes a random probe file into $dataDir and
 * fetches it through $baseUrl/data/. Returns true (exposed), false (blocked),
 * or null when the server could not reach itself (inconclusive).
 */
function gw_data_exposed(string $baseUrl, string $dataDir, ?string $pinIp = null): ?bool
{
    if (realpath($dataDir) !== realpath(GW_DEFAULT_DATA_DIR)) {
        return false; // data_dir moved outside the web-served folder
    }
    $name = 'probe-' . bin2hex(random_bytes(6)) . '.txt';
    $token = bin2hex(random_bytes(16));
    if (@file_put_contents($dataDir . '/' . $name, $token) === false) {
        return null;
    }
    $url = rtrim($baseUrl, '/') . '/data/' . $name;
    $host = (string) parse_url($url, PHP_URL_HOST);
    $scheme = (string) parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true) || !gw_valid_hostname($host)) {
        @unlink($dataDir . '/' . $name);
        return null;
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 4096,
    ];
    // Pin the connection to this server's own address, so a forged Host header
    // can't point the probe at some other machine.
    if ($pinIp !== null && filter_var($pinIp, FILTER_VALIDATE_IP)) {
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($pinIp, ':') ? '[' . $pinIp . ']' : $pinIp)];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($dataDir . '/' . $name);
    if ($body === false || $code === 0) {
        return null;
    }
    if ($code === 200 && trim((string) $body) === $token) {
        return true;
    }
    return in_array($code, [401, 403, 404], true) ? false : null;
}

/**
 * Cached version for the payment path: re-checked every 24h when blocked,
 * every 10 min when exposed or inconclusive.
 */
function gw_data_exposed_cached(string $baseUrl, string $dataDir): ?bool
{
    $file = gw_data_file('protection-check.json');
    $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (is_array($cached) && array_key_exists('exposed', $cached)) {
        $ttl = $cached['exposed'] === false ? 86400 : 600;
        if (time() - (int) ($cached['at'] ?? 0) < $ttl) {
            return $cached['exposed'];
        }
    }
    $exposed = gw_data_exposed($baseUrl, $dataDir);
    @file_put_contents($file, json_encode(['at' => time(), 'exposed' => $exposed]), LOCK_EX);
    return $exposed;
}

/** ResellerClub's own return hosts (test servlet, live payment pages), always allowed. */
const GW_RC_RETURN_HOSTS = ['*.resellerclub.com', '*.foundationapi.com'];

/** Return-host allow-list: ResellerClub's own hosts plus the configured ones (never "allow any"). */
function gw_return_hosts(array $configured): array
{
    return array_merge(GW_RC_RETURN_HOSTS, $configured);
}

/**
 * Razorpay checkout prefill from the customer details ResellerClub sends
 * (name, emailAddr, telNoCc, telNo). These fields are NOT covered by the
 * checksum, so they are only ever used to save the customer typing.
 */
function gw_checkout_prefill(array $req): array
{
    $val = function (string $k) use ($req): string {
        $v = trim(is_string($req[$k] ?? null) ? $req[$k] : '');
        return strtolower($v) === 'null' ? '' : $v;
    };
    $prefill = [];
    $name = preg_replace('/\s+/', ' ', $val('name'));
    if ($name !== '') {
        $prefill['name'] = mb_substr($name, 0, 100);
    }
    $email = $val('emailAddr');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $prefill['email'] = $email;
    }
    $cc = preg_replace('/\D/', '', $val('telNoCc'));
    $tel = preg_replace('/\D/', '', $val('telNo'));
    if ($tel !== '') {
        $contact = $cc !== '' ? $cc . $tel : $tel;
        if (strlen($contact) >= 6 && strlen($contact) <= 15) {
            $prefill['contact'] = ($cc !== '' ? '+' : '') . $contact;
        }
    }
    return $prefill;
}

/**
 * Is $host on the allow-list? Entries are exact hostnames, or "*.example.com"
 * for any subdomain of example.com. An empty list allows every host.
 */
function gw_host_allowed(string $host, array $allowed): bool
{
    if (!$allowed) {
        return true;
    }
    $host = strtolower(rtrim($host, '.'));
    foreach ($allowed as $entry) {
        $entry = strtolower(trim((string) $entry));
        if ($entry === $host) {
            return true;
        }
        if (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1)) && strlen($host) > strlen($entry) - 1) {
            return true;
        }
    }
    return false;
}

function gw_valid_hostname(string $host): bool
{
    return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host);
}

/**
 * Return URL from ResellerClub, reduced to a canonical https URL on an allowed
 * host, or null. It isn't covered by the checksum, so it is held to strict rules:
 * https only, no user:password, no non-standard port.
 */
function gw_canonical_return_url(string $url, array $allowed): ?string
{
    $u = parse_url($url);
    if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])
        || isset($u['user']) || isset($u['pass']) || (isset($u['port']) && (int) $u['port'] !== 443)) {
        return null;
    }
    $host = strtolower($u['host']);
    if (!gw_valid_hostname($host) || !gw_host_allowed($host, $allowed)) {
        return null;
    }
    return 'https://' . $host . ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
}

/**
 * ResellerClub's built-in "Test For Payment" / "Test For Add Fund" request:
 * a transid containing "test" (checksummed) AND the result going to
 * ResellerClub's test servlet, so a real invoice can never be routed here.
 */
function gw_is_rc_test(string $transid, string $redirectUrl): bool
{
    $host = strtolower((string) parse_url($redirectUrl, PHP_URL_HOST));
    $path = (string) parse_url($redirectUrl, PHP_URL_PATH);
    return stripos($transid, 'test') !== false
        && gw_host_allowed($host, ['*.resellerclub.com'])
        && stripos($path, '/servlet/TestCustomPayment') === 0;
}

/**
 * May a success (Y) be sent to ResellerClub for this transaction? Live
 * transactions always; test-mode ones only for ResellerClub's test servlet or
 * a listed sandbox account while sandbox mode is on. Checked wherever a Y is signed.
 */
function gw_may_send_success(array $txn): bool
{
    if (gw_txn_mode($txn) === 'live') {
        return true;
    }
    if (gw_is_rc_test((string) $txn['rc_transid'], (string) $txn['redirect_url'])) {
        return true;
    }
    $c = $GLOBALS['config'];
    return $c['mode'] === 'test' && in_array((string) ($txn['rc_userid'] ?? ''), $c['test_customer_ids'], true);
}

/** Fields of a Razorpay payment that don't match the stored transaction (empty = match). */
function gw_payment_mismatch(array $payment, array $txn): array
{
    $diff = [];
    if (($payment['order_id'] ?? null) !== $txn['razorpay_order_id']) {
        $diff['order'] = [$txn['razorpay_order_id'], $payment['order_id'] ?? null];
    }
    if (!gw_payment_amount_matches($payment, (int) $txn['amount_minor'])) {
        $diff['amount'] = [(int) $txn['amount_minor'], (int) ($payment['amount'] ?? 0)];
    }
    if (strtoupper((string) ($payment['currency'] ?? '')) !== strtoupper((string) $txn['currency'])) {
        $diff['currency'] = [strtoupper((string) $txn['currency']), strtoupper((string) ($payment['currency'] ?? ''))];
    }
    return $diff;
}

/** Webhook secret for a mode; a separate test secret is optional. */
function gw_webhook_secret(string $mode): string
{
    $c = $GLOBALS['config'];
    $secret = $mode === 'test' && $c['razorpay_test_webhook_secret'] !== '' ? $c['razorpay_test_webhook_secret'] : $c['razorpay_webhook_secret'];
    return gw_secret_usable($secret) ? $secret : '';
}

/** Rejects empty, short and well-known placeholder secrets. */
function gw_secret_usable(string $secret): bool
{
    return strlen($secret) >= 12 && !in_array(strtolower($secret), ['change_me', 'changeme', 'xxxxxxxxxxxx', 'your_secret_here'], true)
        && !preg_match('/^(x+|change_?me.*)$/i', $secret);
}

/** Keep only non-personal fields of a Razorpay API response for the debug log. */
function gw_redact_api(mixed $data): mixed
{
    if (!is_array($data)) {
        return $data;
    }
    $keep = ['id', 'entity', 'status', 'amount', 'amount_paid', 'amount_due', 'currency', 'order_id', 'method',
        'captured', 'fee', 'tax', 'attempts', 'receipt', 'error_code', 'error_description', 'error_reason', 'error', 'description', 'code'];
    $out = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $keep, true)) {
            $out[$k] = is_array($v) ? gw_redact_api($v) : $v;
        }
    }
    return $out;
}

/** Minor-unit exponent per ISO 4217 (most currencies use 2). */
function gw_currency_exponent(string $currency): int
{
    $c = strtoupper($currency);
    if (in_array($c, ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true)) {
        return 0;
    }
    return in_array($c, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], true) ? 3 : 2;
}

/** Decimal amount string -> integer minor units, without float rounding; null if invalid. */
function gw_to_minor(string $amount, string $currency): ?int
{
    if (!preg_match('/^(\d{1,12})(?:\.(\d+))?$/', trim($amount), $m)) {
        return null;
    }
    $exp = gw_currency_exponent($currency);
    $frac = $m[2] ?? '';
    if (strlen(rtrim($frac, '0')) > $exp) {
        return null; // more precision than the currency has
    }
    $minor = (int) ($m[1] . str_pad(substr($frac, 0, $exp), $exp, '0'));
    return $minor > 0 ? $minor : null;
}

function gw_format_minor(int $minor, string $currency): string
{
    $exp = gw_currency_exponent($currency);
    return number_format($minor / (10 ** $exp), $exp) . ' ' . strtoupper($currency);
}

/** Public URL of the code/ folder, worked out from the current request. */
function gw_detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    // Host header is client-controlled: accept only "hostname" or "hostname:port".
    $host = is_string($_SERVER['HTTP_HOST'] ?? null) ? strtolower($_SERVER['HTTP_HOST']) : '';
    if (!preg_match('/^([a-z0-9.-]{1,253})(:\d{1,5})?$/', $host, $m) || !gw_valid_hostname($m[1])) {
        $host = 'localhost';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
