<?php
declare(strict_types=1);

require __DIR__ . '/ResellerClub.php';
require __DIR__ . '/Razorpay.php';
require __DIR__ . '/Store.php';

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Gateway not configured: copy config.sample.php to config.php.');
}
$config = require $configFile;

if (!is_dir($config['data_dir'])) {
    mkdir($config['data_dir'], 0770, true);
}

function gw_log(string $message, array $context = []): void
{
    global $config;
    $line = '[' . date('c') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    file_put_contents($config['data_dir'] . '/gateway.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function fail_page(string $title, string $message, int $code = 400): never
{
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 16px;color:#222">'
        . '<h2>' . h($title) . '</h2><p>' . h($message) . '</p></body>';
    exit;
}

$store = new Store($config['data_dir'] . '/transactions.sqlite');
$rc = new ResellerClub($config['rc_key']);
$razorpay = new Razorpay($config['razorpay_key_id'], $config['razorpay_key_secret']);
