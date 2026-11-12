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

function gw_log(string $message, array $context = []): void
{
    $dir = gw_data_dir();
    if (!is_dir($dir)) {
        return;
    }
    $line = '[' . date('c') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($dir . '/gateway.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
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
        . '.wrap{max-width:440px;margin:8vh auto;padding:0 16px}'
        . '.brand{display:flex;align-items:center;gap:12px;margin-bottom:16px}'
        . '.brand img{width:44px;height:44px;border-radius:10px;object-fit:contain;background:#fff}'
        . '.brand span{font-weight:600;font-size:18px}'
        . '.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.08);border-top:4px solid var(--brand)}'
        . 'h1{font-size:20px;margin:0 0 8px}.muted{color:var(--muted);font-size:14px}'
        . '.amount{font-size:32px;font-weight:600;margin:16px 0 4px}.ref{font-size:13px;color:var(--muted);margin-bottom:20px}'
        . 'button{width:100%;padding:14px;border:0;border-radius:8px;font-size:16px;cursor:pointer}'
        . '.pay{background:var(--brand);color:#fff}.cancel{background:none;color:var(--muted);margin-top:8px;font-size:14px}'
        . '.support{font-size:14px;margin-top:16px}.support a{color:var(--brand);white-space:nowrap}'
        . 'footer{text-align:center;font-size:12px;color:var(--muted);margin-top:16px}'
        . '.spinner{width:28px;height:28px;border:3px solid #e3e5e8;border-top-color:var(--brand);border-radius:50%;animation:s 1s linear infinite;margin:8px auto 16px}'
        . '@keyframes s{to{transform:rotate(360deg)}}'
        . '</style>' . $extraHead . '</head><body><div class="wrap">'
        . '<div class="brand">' . ($b['logo'] !== '' ? '<img src="' . h($b['logo']) . '" alt="">' : '') . '<span>' . h($b['name']) . '</span></div>'
        . '<div class="card">';
}

function gw_page_close(string $scripts = ''): void
{
    $support = gw_support_html();
    echo ($support !== '' ? '<p class="support muted">' . $support . '</p>' : '')
        . '</div><footer>🔒 Payments secured by Razorpay</footer></div>' . $scripts . '</body></html>';
}

function fail_page(string $title, string $message, int $code = 400): never
{
    gw_page_open($title, $code);
    echo '<h1>' . h($title) . '</h1><p>' . h($message) . '</p>';
    gw_page_close();
    exit;
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

/** Public URL of the code/ folder, worked out from the current request. */
function gw_detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
