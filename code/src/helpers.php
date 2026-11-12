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

function fail_page(string $title, string $message, int $code = 400): never
{
    http_response_code($code);
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 16px;color:#222">'
        . '<h2>' . h($title) . '</h2><p>' . h($message) . '</p></body>';
    exit;
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
