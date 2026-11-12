<?php
declare(strict_types=1);

require __DIR__ . '/helpers.php';
require __DIR__ . '/ResellerClub.php';
require __DIR__ . '/Razorpay.php';
require __DIR__ . '/Store.php';

if (!is_file(GW_CONFIG_FILE)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Gateway not configured: open setup.php in a browser to run the setup wizard.\n");
        exit(2);
    }
    header('Retry-After: 3600');
    fail_page(
        'Payment page not available yet',
        'This payment page has not been set up yet. Please try again later or contact the store. '
        . '(Site owner: open setup.php in this folder to run the setup wizard.)',
        503
    );
}
$config = gw_normalize_config(require GW_CONFIG_FILE);

gw_protect_dir($config['data_dir']);
if ($config['debug']) {
    gw_debug_enable("config 'debug' is on");
} elseif ($config['mode'] === 'test') {
    gw_debug_enable('sandbox (test) mode');
}

$store = new Store($config['data_dir'] . '/transactions.sqlite');
$rc = new ResellerClub($config['rc_key']);
