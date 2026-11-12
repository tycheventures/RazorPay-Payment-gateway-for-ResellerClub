<?php
declare(strict_types=1);

/**
 * First-run setup: setup-code handling, validation and writing config.php.
 * Kept free of HTML so it can be exercised from the CLI.
 */
final class Setup
{
    /** No 0/O/1/I/L to keep the code easy to copy. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const FIELDS = [
        'rc_key', 'razorpay_live_key_id', 'razorpay_live_key_secret',
        'sandbox', 'razorpay_test_key_id', 'razorpay_test_key_secret', 'test_customer_ids',
        'razorpay_webhook_secret',
        'currency', 'base_url', 'merchant_name', 'merchant_logo', 'theme_color',
        'support_email', 'support_phone', 'allowed_redirect_hosts',
    ];

    public function __construct(
        private string $configFile = GW_CONFIG_FILE,
        private string $dataDir = GW_DEFAULT_DATA_DIR,
    ) {
    }

    public function isConfigured(): bool
    {
        return is_file($this->configFile);
    }

    public function codeFile(): string
    {
        return $this->dataDir . '/setup-code.txt';
    }

    /** Create the setup code if there isn't one yet. */
    public function ensureCode(): void
    {
        gw_protect_dir($this->dataDir);
        if (is_file($this->codeFile())) {
            return;
        }
        $chars = '';
        for ($i = 0; $i < 25; $i++) {
            $chars .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $code = implode('-', str_split($chars, 5));
        file_put_contents($this->codeFile(), $code . PHP_EOL, LOCK_EX);
        @chmod($this->codeFile(), 0640);
    }

    public function checkCode(string $input): bool
    {
        if (!is_file($this->codeFile())) {
            return false;
        }
        $normalise = fn(string $s) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
        $expected = $normalise((string) file_get_contents($this->codeFile()));
        return $expected !== '' && hash_equals($expected, $normalise($input));
    }

    /** @return list<array{label:string, ok:bool, detail:string}> */
    public function environmentChecks(): array
    {
        $checks = [];
        $checks[] = ['label' => 'PHP 8.1 or newer', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => 'PHP ' . PHP_VERSION];
        foreach (['curl', 'pdo_sqlite', 'json'] as $ext) {
            $checks[] = ['label' => "PHP extension: $ext", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'loaded' : 'missing'];
        }
        $writable = is_dir($this->dataDir) && is_writable($this->dataDir);
        $checks[] = ['label' => 'data/ folder writable', 'ok' => $writable, 'detail' => $writable ? 'yes' : 'cannot write to ' . basename($this->dataDir) . '/'];
        $configDirWritable = is_writable(dirname($this->configFile));
        $checks[] = ['label' => 'Can create config.php', 'ok' => $configDirWritable, 'detail' => $configDirWritable ? 'yes' : 'folder is not writable by PHP'];
        return $checks;
    }

    public function environmentOk(): bool
    {
        foreach ($this->environmentChecks() as $c) {
            if (!$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate submitted values.
     *
     * @param callable(string $keyId, string $keySecret): ?string $checkKeys returns an error message or null
     * @return array{0: array<string,string>, 1: array<string,mixed>} [errors by field, clean config]
     */
    public function validate(array $in, callable $checkKeys): array
    {
        $v = [];
        foreach (self::FIELDS as $f) {
            $raw = $in[$f] ?? '';
            $v[$f] = trim(is_array($raw) ? implode("\n", array_map('strval', $raw)) : (string) $raw);
        }
        $e = [];

        if ($v['rc_key'] === '' || preg_match('/\s/', $v['rc_key'])) {
            $e['rc_key'] = 'Enter the key shown on the ResellerClub custom payment gateway screen.';
        }
        // Live keys are required unless sandbox mode is on; sandbox mode needs test keys.
        $sandbox = in_array($v['sandbox'], ['1', 'on', 'yes', 'true'], true);
        $pairs = [];
        $liveGiven = $v['razorpay_live_key_id'] !== '' || $v['razorpay_live_key_secret'] !== '';
        if (!$sandbox || $liveGiven) {
            $pairs[] = 'live';
        }
        if ($sandbox) {
            $pairs[] = 'test';
        }
        foreach ($pairs as $kind) {
            if (!preg_match("/^rzp_{$kind}_[A-Za-z0-9]+$/", $v["razorpay_{$kind}_key_id"])) {
                $e["razorpay_{$kind}_key_id"] = "Key ID should start with rzp_{$kind}_.";
            }
            if ($v["razorpay_{$kind}_key_secret"] === '') {
                $e["razorpay_{$kind}_key_secret"] = 'Enter the key secret.';
            }
        }
        $customerIds = array_values(array_unique(array_filter(preg_split('/[\s,]+/', $v['test_customer_ids']), 'strlen')));
        foreach ($customerIds as $id) {
            if (!preg_match('/^[0-9]{1,15}$/', $id)) {
                $e['test_customer_ids'] = "“{$id}” is not a ResellerClub customer ID (digits only).";
                break;
            }
        }
        if ($v['razorpay_webhook_secret'] === '') {
            $v['razorpay_webhook_secret'] = bin2hex(random_bytes(20));
        } elseif (strlen($v['razorpay_webhook_secret']) < 12) {
            $e['razorpay_webhook_secret'] = 'Use at least 12 characters, or leave it blank to generate one.';
        }
        $v['currency'] = strtoupper($v['currency'] ?: 'INR');
        if (!preg_match('/^[A-Z]{3}$/', $v['currency'])) {
            $e['currency'] = 'Use a 3-letter currency code such as INR.';
        }

        $v['base_url'] = rtrim($v['base_url'], '/');
        $scheme = parse_url($v['base_url'], PHP_URL_SCHEME);
        $host = (string) parse_url($v['base_url'], PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (!filter_var($v['base_url'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            $e['base_url'] = 'Enter the full URL of this folder, e.g. https://pay.example.com/razorpay.';
        } elseif ($scheme !== 'https' && !$local) {
            $e['base_url'] = 'Use https://, because payments must run over a secure connection.';
        }

        if ($v['merchant_name'] === '' || mb_strlen($v['merchant_name']) > 80) {
            $e['merchant_name'] = 'Enter your business name (up to 80 characters).';
        }
        if ($v['merchant_logo'] !== '' && (!filter_var($v['merchant_logo'], FILTER_VALIDATE_URL) || parse_url($v['merchant_logo'], PHP_URL_SCHEME) !== 'https')) {
            $e['merchant_logo'] = 'Use an https:// image URL, or leave it blank.';
        }
        $v['theme_color'] = $v['theme_color'] ?: '#0b5fff';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v['theme_color'])) {
            $e['theme_color'] = 'Use a colour like #0b5fff.';
        }

        if ($v['support_email'] !== '' && !filter_var($v['support_email'], FILTER_VALIDATE_EMAIL)) {
            $e['support_email'] = 'Enter a valid email address, or leave it blank.';
        }
        if ($v['support_phone'] !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{5,19}$/', $v['support_phone'])) {
            $e['support_phone'] = 'Enter a phone number like +91 98765 43210, or leave it blank.';
        }

        $hosts = array_values(array_unique(array_filter(array_map(
            fn($h) => strtolower(trim($h)),
            preg_split('/[\s,]+/', $v['allowed_redirect_hosts'])
        ))));
        foreach ($hosts as $h) {
            if (!preg_match('/^(?=.{1,253}$)(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $h)) {
                $e['allowed_redirect_hosts'] = "“{$h}” is not a valid hostname (no https:// or paths; *.example.com is allowed).";
                break;
            }
        }

        foreach ($pairs as $kind) {
            if (!isset($e["razorpay_{$kind}_key_id"]) && !isset($e["razorpay_{$kind}_key_secret"])) {
                $err = $checkKeys($v["razorpay_{$kind}_key_id"], $v["razorpay_{$kind}_key_secret"]);
                if ($err !== null) {
                    $e["razorpay_{$kind}_key_secret"] = $err;
                }
            }
        }

        $config = [
            'rc_key'                  => $v['rc_key'],
            'mode'                    => $sandbox ? 'test' : 'live',
            'razorpay_live_key_id'    => in_array('live', $pairs, true) ? $v['razorpay_live_key_id'] : '',
            'razorpay_live_key_secret' => in_array('live', $pairs, true) ? $v['razorpay_live_key_secret'] : '',
            'razorpay_test_key_id'    => $sandbox ? $v['razorpay_test_key_id'] : '',
            'razorpay_test_key_secret' => $sandbox ? $v['razorpay_test_key_secret'] : '',
            'test_customer_ids'       => $sandbox ? $customerIds : [],
            'razorpay_webhook_secret' => $v['razorpay_webhook_secret'],
            'currency'                => $v['currency'],
            'base_url'                => $v['base_url'],
            'merchant_name'           => $v['merchant_name'],
            'merchant_logo'           => $v['merchant_logo'],
            'theme_color'             => strtolower($v['theme_color']),
            'support_email'           => $v['support_email'],
            'support_phone'           => $v['support_phone'],
            'allowed_redirect_hosts'  => $hosts,
        ];
        return [$e, $config];
    }

    /** Write config.php without ever replacing an existing one, then retire the setup code. */
    public function writeConfig(array $config): void
    {
        $php = "<?php\n"
            . "/**\n * Written by the setup wizard on " . date('c') . ".\n"
            . " * To run the wizard again, delete this file and open setup.php.\n */\n"
            . "return [\n";
        foreach ($config as $k => $val) {
            $php .= '    ' . var_export($k, true) . ' => ' . $this->export($val) . ",\n";
        }
        $php .= "    'data_dir' => __DIR__ . '/data',\n];\n";

        $tmp = $this->configFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new RuntimeException('Could not write a temporary config file.');
        }
        @chmod($tmp, 0640);
        // link() fails if config.php already exists, so a second submission can't overwrite it.
        if (!@link($tmp, $this->configFile)) {
            if ($this->isConfigured()) {
                @unlink($tmp);
                throw new RuntimeException('config.php already exists.');
            }
            // Filesystems without hard links: fall back to an exclusive create.
            $fh = @fopen($this->configFile, 'x');
            if (!$fh) {
                @unlink($tmp);
                throw new RuntimeException('Could not create config.php.');
            }
            fwrite($fh, $php);
            fclose($fh);
            @chmod($this->configFile, 0640);
        }
        @unlink($tmp);
        @unlink($this->codeFile());
    }

    private function export(mixed $val): string
    {
        if (is_array($val)) {
            return '[' . implode(', ', array_map(fn($x) => var_export($x, true), $val)) . ']';
        }
        return var_export($val, true);
    }
}
