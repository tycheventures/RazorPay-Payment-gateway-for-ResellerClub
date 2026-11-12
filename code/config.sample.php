<?php
/**
 * Copy this file to config.php and fill in your values.
 * config.php is git-ignored — never commit real keys.
 */
return [
    // ResellerClub: Control Panel → Settings → Finance & Billing → Payment Gateway →
    // "Add Custom Payment Gateway". The "Key" shown there goes here.
    'rc_key' => 'YOUR_RESELLERCLUB_GATEWAY_KEY',

    // Razorpay: Dashboard → Account & Settings → API Keys.
    // Use rzp_test_... keys until you've tested end to end.
    'razorpay_key_id'     => 'rzp_test_XXXXXXXXXXXXXX',
    'razorpay_key_secret' => 'XXXXXXXXXXXXXXXXXXXXXXXX',

    // Razorpay: Dashboard → Webhooks → secret you set for this gateway's webhook URL.
    'razorpay_webhook_secret' => 'CHANGE_ME',

    // Currency your ResellerClub "selling currency" is in. Razorpay charges in this currency.
    'currency' => 'INR',

    // Public URL of the code/ folder on the server, no trailing slash.
    'base_url' => 'https://pay.example.com/razorpay',

    // Branding shown in the Razorpay popup.
    'merchant_name' => 'Your Hosting Company',
    'merchant_logo' => '', // optional absolute https URL to a square logo
    'theme_color'   => '#0b5fff',

    // Writable directory for the SQLite database and logs. Protected by .htaccess; you may also point this outside the web root.
    'data_dir' => __DIR__ . '/data',

    // Only accept redirect URLs on these hosts (ResellerClub / your SuperSite domains).
    // Prevents the gateway being used as an open redirect. Leave empty to allow any.
    'allowed_redirect_hosts' => [
        // 'www.yourbrand.com',
        // 'manage.yourbrand.com',
    ],
];
