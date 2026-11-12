<?php
/**
 * Copy this file to config.php and fill in your values.
 * config.php is git-ignored — never commit real keys.
 */
return [
    // ResellerClub: Control Panel → Settings → Finance & Billing → Payment Gateway →
    // "Add Custom Payment Gateway". The "Key" shown there goes here.
    'rc_key' => 'YOUR_RESELLERCLUB_GATEWAY_KEY',

    // 'live' charges real money with the live keys.
    // 'test' (sandbox) uses the test keys, and only test_customer_ids can pay;
    // everyone else sees "temporarily unavailable".
    'mode' => 'test',

    // Razorpay: Dashboard → Account & Settings → API Keys (generate in Live mode and in Test mode).
    'razorpay_live_key_id'     => '', // rzp_live_...
    'razorpay_live_key_secret' => '',
    'razorpay_test_key_id'     => 'rzp_test_XXXXXXXXXXXXXX',
    'razorpay_test_key_secret' => 'XXXXXXXXXXXXXXXXXXXXXXXX',

    // ResellerClub customer or sub-reseller IDs allowed to pay while 'mode' is 'test'.
    'test_customer_ids' => [
        // '123456',
    ],

    // Step-by-step log in data/debug.log. Test-mode transactions are always logged;
    // true logs live transactions too (includes customer details — switch off when done).
    'debug' => false,

    // Razorpay: Dashboard → Webhooks → secret you set for this gateway's webhook URL.
    // Test mode and Live mode have separate webhooks: use the same URL and secret in both.
    'razorpay_webhook_secret' => 'CHANGE_ME',

    // Currency your ResellerClub "selling currency" is in. Razorpay charges in this currency.
    'currency' => 'INR',

    // Public URL of the code/ folder on the server, no trailing slash.
    'base_url' => 'https://pay.example.com/razorpay',

    // Branding: payment page, error pages and the Razorpay popup.
    'merchant_name' => 'Your Hosting Company',
    'merchant_logo' => '', // optional https URL to a square logo (also the favicon)
    'theme_color'   => '#0b5fff',

    // Support contact shown on the payment and error pages (optional).
    'support_email' => '', // e.g. support@yourbrand.com
    'support_phone' => '', // e.g. +91 98765 43210

    // Writable directory for the SQLite database and logs. Protected by .htaccess; you may also point this outside the web root.
    'data_dir' => __DIR__ . '/data',

    // Only accept redirect URLs on these hosts (where ResellerClub sends customers back).
    // Exact hostnames, or '*.example.com' for any subdomain. Prevents the gateway being used
    // as an open redirect. A rejected host is named on the error page.
    // ResellerClub's own hosts (*.resellerclub.com, *.foundationapi.com) are always allowed;
    // leave the list empty to allow only those.
    'allowed_redirect_hosts' => [
        // 'www.yourbrand.com',
        // 'manage.yourbrand.com',
    ],
];
