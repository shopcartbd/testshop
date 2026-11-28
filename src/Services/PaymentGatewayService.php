<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Config;
use AllStore\Core\Helpers;

/**
 * Admin-managed payment gateway settings.
 *
 * Cash on Delivery works out of the box. Online gateways are configured from
 * /admin/payment-gateway and stay disabled until the merchant turns them on and
 * every required key is present — so the checkout never offers a payment method
 * that cannot actually take money. Secrets are stored in the settings table,
 * displayed masked and only overwritten when a new value is submitted.
 */
final class PaymentGatewayService
{
    public const KEY_PREFIX = 'payment_';

    /** provider => [label, required fields, live integration available] */
    public const PROVIDERS = [
        'bkash' => [
            'label' => 'bKash (Bangladesh)',
            'hint' => 'bKash Merchant / PGW API. App key, app secret and the merchant credentials issued by bKash.',
            'live' => false,
            'fields' => [
                'app_key' => ['App key', 'text', false],
                'app_secret' => ['App secret', 'secret', true],
                'username' => ['Merchant username', 'text', false],
                'password' => ['Merchant password', 'secret', true],
            ],
        ],
        'nagad' => [
            'label' => 'Nagad (Bangladesh)',
            'hint' => 'Nagad Merchant API. Merchant ID plus the RSA public/private key pair issued by Nagad.',
            'live' => false,
            'fields' => [
                'merchant_id' => ['Merchant ID', 'text', false],
                'merchant_number' => ['Merchant number', 'text', false],
                'public_key' => ['Public key', 'textarea', false],
                'private_key' => ['Private key', 'secret', true],
            ],
        ],
        'sslcommerz' => [
            'label' => 'SSLCommerz',
            'hint' => 'SSLCommerz store credentials. Supports cards, bKash, Nagad and bank payments through one gateway.',
            'live' => false,
            'fields' => [
                'store_id' => ['Store ID', 'text', false],
                'store_password' => ['Store password', 'secret', true],
            ],
        ],
        'stripe' => [
            'label' => 'Stripe (cards, international)',
            'hint' => 'Stripe Checkout. Payment is confirmed only by the signed webhook.',
            'live' => true,
            'fields' => [
                'publishable_key' => ['Publishable key', 'text', false],
                'secret_key' => ['Secret key', 'secret', true],
                'webhook_secret' => ['Webhook signing secret', 'secret', true],
            ],
        ],
        'manual' => [
            'label' => 'Manual / bank transfer (instructions only)',
            'hint' => 'Shows your bank or mobile-wallet instructions on the confirmation page; the order stays unpaid until you confirm it.',
            'live' => false,
            'fields' => [
                'instructions' => ['Payment instructions shown to the customer', 'textarea', false],
            ],
        ],
    ];

    public static function all(): array
    {
        $provider = self::normalizeProvider((string)Helpers::setting(self::KEY_PREFIX . 'provider', ''));
        $out = [
            'cod_enabled' => Helpers::setting('cod_enabled', '1') === '1',
            'online_enabled' => Helpers::setting(self::KEY_PREFIX . 'online_enabled', '0') === '1',
            'provider' => $provider,
            'sandbox' => Helpers::setting(self::KEY_PREFIX . 'sandbox', '1') === '1',
            'credentials' => [],
            'updated_at' => (string)Helpers::setting(self::KEY_PREFIX . 'updated_at', ''),
        ];
        foreach (array_keys(self::PROVIDERS) as $key) {
            foreach (self::PROVIDERS[$key]['fields'] as $field => [$label, $type]) {
                $out['credentials'][$key][$field] = (string)Helpers::setting(self::KEY_PREFIX . $key . '_' . $field, '');
            }
        }
        return $out;
    }

    public static function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        return isset(self::PROVIDERS[$provider]) ? $provider : '';
    }

    /** A provider can take money only when it is enabled and fully configured. */
    public static function isLive(string $provider): bool
    {
        $provider = self::normalizeProvider($provider);
        if ($provider === '') { return false; }
        $config = self::all();
        if (!$config['online_enabled'] || $config['provider'] !== $provider) { return false; }
        foreach (self::PROVIDERS[$provider]['fields'] as $field => [$label, $type]) {
            if ($type === 'secret' && trim((string)($config['credentials'][$provider][$field] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    public static function activeProvider(): string
    {
        return self::isLive((string)self::all()['provider']) ? (string)self::all()['provider'] : '';
    }

    public static function label(string $provider): string
    {
        $provider = self::normalizeProvider($provider);
        return $provider === '' ? '' : (string)self::PROVIDERS[$provider]['label'];
    }

    /** Checkout-visible online payment method rows (never COD). */
    public static function onlineMethods(): array
    {
        $provider = self::activeProvider();
        if ($provider === '') { return []; }
        if (!(bool)self::PROVIDERS[$provider]['live']) { return []; }
        return [['id' => $provider, 'name' => self::label($provider), 'description' => 'Secure online payment.']];
    }

    public static function credentials(string $provider): array
    {
        $provider = self::normalizeProvider($provider);
        if ($provider === '') { return []; }
        $config = self::all();
        return (array)($config['credentials'][$provider] ?? []);
    }

    public static function sandbox(): bool
    {
        return (bool)self::all()['sandbox'];
    }

    /** Never render a stored secret; show only its shape. */
    public static function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') { return ''; }
        $tail = mb_substr($value, -4);
        return str_repeat('•', 10) . $tail;
    }

    /**
     * Save the gateway form. Blank secret inputs keep the stored secret; the
     * clear_<provider>_<field> checkbox removes it explicitly.
     */
    public static function save(array $in): array
    {
        $provider = self::normalizeProvider((string)($in['provider'] ?? ''));
        $online = !empty($in['online_enabled']);
        if ($online && $provider === '') { $provider = ''; $online = false; }
        Helpers::setSetting('cod_enabled', !empty($in['cod_enabled']) ? '1' : '0', 'payments');
        Helpers::setSetting(self::KEY_PREFIX . 'provider', $provider, 'payments');
        Helpers::setSetting(self::KEY_PREFIX . 'online_enabled', $online ? '1' : '0', 'payments');
        Helpers::setSetting(self::KEY_PREFIX . 'sandbox', !empty($in['sandbox']) ? '1' : '0', 'payments');
        foreach (self::PROVIDERS as $key => $meta) {
            foreach ($meta['fields'] as $field => [$label, $type]) {
                $settingKey = self::KEY_PREFIX . $key . '_' . $field;
                $raw = trim((string)($in['cred_' . $key . '_' . $field] ?? ''));
                if (!empty($in['clear_' . $key . '_' . $field])) {
                    Helpers::setSetting($settingKey, '', 'payments');
                    continue;
                }
                if ($raw !== '') {
                    Helpers::setSetting($settingKey, mb_substr($raw, 0, 4000), 'payments');
                }
            }
        }
        Helpers::setSetting(self::KEY_PREFIX . 'updated_at', Helpers::now(), 'payments');
        return self::all();
    }

    /** Status rows for the admin overview. */
    public static function status(): array
    {
        $config = self::all();
        $rows = [];
        foreach (self::PROVIDERS as $key => $meta) {
            $missing = [];
            foreach ($meta['fields'] as $field => [$label, $type]) {
                if ($type === 'secret' && trim((string)($config['credentials'][$key][$field] ?? '')) === '') { $missing[] = $label; }
            }
            $rows[] = [
                'provider' => $key,
                'label' => $meta['label'],
                'selected' => $config['provider'] === $key,
                'live' => self::isLive($key),
                'integration' => (bool)$meta['live'],
                'missing' => $missing,
            ];
        }
        return $rows;
    }

    /** True when the store can take online payments right now. */
    public static function onlineReady(): bool
    {
        return self::onlineMethods() !== [];
    }

    /** Stripe settings merged over the deployment config (env) values. */
    public static function stripeConfig(): array
    {
        $creds = self::credentials('stripe');
        return [
            'secret' => trim((string)($creds['secret_key'] ?? '')) !== '' ? trim((string)$creds['secret_key']) : (string)Config::get('payments.secret'),
            'webhook_secret' => trim((string)($creds['webhook_secret'] ?? '')) !== '' ? trim((string)$creds['webhook_secret']) : (string)Config::get('payments.webhook_secret'),
            'publishable' => trim((string)($creds['publishable_key'] ?? '')),
        ];
    }
}
