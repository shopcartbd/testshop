<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Config;
use AllStore\Core\Http;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
use AllStore\Core\AppException;

/**
 * Cash on Delivery is available out of the box. Stripe Checkout is a real,
 * documented REST integration that activates ONLY when credentials are present.
 * Payment success is never assumed: the order is marked paid exclusively by a
 * verified Stripe webhook (or explicit admin confirmation for COD).
 */
final class PaymentService
{
    /**
     * Checkout payment options. Cash on Delivery is controlled from
     * /admin/payment-gateway; an online gateway appears only when the merchant
     * enabled it there and every credential is present, so a customer can never
     * pick a method that cannot take the money.
     */
    public static function methods(): array
    {
        $out = [];
        if (Helpers::setting('cod_enabled', '1') === '1') {
            $out[] = ['id'=>'cod','name'=>'Cash on Delivery','description'=>'Pay when your order arrives.'];
        }
        foreach (PaymentGatewayService::onlineMethods() as $method) {
            $out[] = $method;
        }
        // Legacy env/config Stripe deployment keeps working without admin setup.
        if (self::stripeConfigured()) {
            $has = false;
            foreach ($out as $m) { if ($m['id'] === 'stripe') { $has = true; } }
            if (!$has) {
                $out[] = ['id'=>'stripe','name'=>'Card / Online Payment','description'=>'Secure checkout with Stripe.'];
            }
        }
        return $out;
    }

    public static function stripeConfigured(): bool
    {
        $stripe = PaymentGatewayService::stripeConfig();
        return trim((string)$stripe['secret']) !== '' && trim((string)$stripe['webhook_secret']) !== '';
    }
    public static function isAvailable(string $method): bool
    {
        foreach (self::methods() as $m) { if ($m['id'] === $method) { return true; } }
        return false;
    }

    /** Create a real Stripe Checkout Session. Throws on any failure. */
    public static function createStripeSession(array $order, array $items): string
    {
        if (!self::stripeConfigured()) { throw new AppException('Online payments are not configured.', 503); }
        $base = (string)Config::get('app.url');
        $form = [
            'mode' => 'payment',
            'success_url' => $base . '/order-confirmation/' . rawurlencode($order['order_number']) . '?payment=pending',
            'cancel_url' => $base . '/checkout?payment=cancelled',
            'client_reference_id' => $order['order_number'],
            'customer_email' => $order['customer_email'],
            'payment_intent_data[metadata][order_number]' => $order['order_number'],
        ];
        $i = 0;
        foreach ($items as $it) {
            $form['line_items[' . $i . '][quantity]'] = (string)$it['quantity'];
            $form['line_items[' . $i . '][price_data][currency]'] = strtolower((string)Config::get('app.currency'));
            $form['line_items[' . $i . '][price_data][unit_amount]'] = (string)$it['unit_price_cents'];
            $form['line_items[' . $i . '][price_data][product_data][name]'] = mb_substr((string)$it['name'], 0, 120);
            $i++;
        }
        $adjustment = max(0, (int)$order['shipping_cents'] + (int)$order['tax_cents'] - (int)$order['discount_cents']);
        if ($adjustment > 0) {
            $form['line_items[' . $i . '][quantity]'] = '1';
            $form['line_items[' . $i . '][price_data][currency]'] = strtolower((string)Config::get('app.currency'));
            $form['line_items[' . $i . '][price_data][unit_amount]'] = (string)$adjustment;
            $form['line_items[' . $i . '][price_data][product_data][name]'] = 'Shipping, tax & discounts';
        }
        [$status, $body, $err] = Http::request('POST', 'https://api.stripe.com/v1/checkout/sessions', [
            'Authorization: Bearer ' . (string)PaymentGatewayService::stripeConfig()['secret'],
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['url']) || empty($data['id'])) {
            Logger::log('error', 'payment.stripe.session_failed', ['status'=>$status, 'detail'=>mb_substr($body,0,200)]);
            throw new AppException('We could not start the secure payment session. Please try Cash on Delivery or contact support.', 502);
        }
        \AllStore\Core\Database::update('payments', ['transaction_ref'=>$data['id'],'status'=>'pending','updated_at'=>Helpers::now()],
            'order_id=:o', ['o'=>$order['id']]);
        return (string)$data['url'];
    }

    /** Verify Stripe-Signature (t=...,v1=...). Returns decoded event or null. */
    public static function verifyWebhook(string $payload, string $header): ?array
    {
        $secret = (string)PaymentGatewayService::stripeConfig()['webhook_secret'];
        if ($secret === '' || $header === '') { return null; }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int)($parts['t'][0] ?? 0);
        if ($t === 0 || abs(time() - $t) > 300) { return null; }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($parts['v1'] ?? [] as $sig) {
            if (hash_equals($expected, (string)$sig)) {
                $event = json_decode($payload, true);
                return is_array($event) ? $event : null;
            }
        }
        Logger::log('warning', 'payment.webhook.invalid_signature');
        return null;
    }
}
