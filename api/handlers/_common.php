<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }

use AllStore\Core\Auth;
use AllStore\Core\Config;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Security;
use AllStore\Core\Session;
use AllStore\Core\AppException;

/** Method allowlist with a JSON 405 error (never an HTML redirect for APIs). */
function api_method(string ...$allowed): void
{
    if (!in_array(Request::method(), $allowed, true)) {
        Response::fail('Method not allowed.', [], 405);
    }
}
function api_csrf(): void
{
    if (!Session::verifyCsrf(Request::csrf())) {
        Response::fail('Your session expired. Please refresh the page and try again.', ['csrf'=>'Invalid or missing CSRF token.'], 419);
    }
}
function api_rate(string $name, int $max = 30, int $window = 300): void
{
    $bucket = 'api:' . $name . ':' . Request::ip();
    if (!Security::rateLimit($bucket, $max, $window)) {
        Response::fail('Too many requests. Please wait a moment and try again.', [], 429);
    }
}
function api_require_login(): void
{
    if (!Auth::check()) { Response::fail('Authentication required.', [], 401); }
}
function api_require_admin(?string $permission = null): void
{
    if (!Auth::isAdmin()) { Response::fail('Administrator access required.', [], 401); }
    if ($permission !== null && !Auth::can($permission)) { Response::fail('You do not have permission to perform this action.', [], 403); }
}
function api_text(string $key, string $default = '', int $max = 2000): string
{
    return Security::plain(Request::str($key, $default, $max), $max);
}
function api_public_url(string $v): string
{
    $v = trim($v);
    if ($v === '') { return ''; }
    if (str_starts_with($v, '/') && !str_starts_with($v, '//')) { return $v; }
    return preg_match('#^https://[a-z0-9.\-]+/#i', $v) === 1 ? $v : '';
}
function api_pick(array $row, array $keys): array
{
    $out = [];
    foreach ($keys as $k) { if (array_key_exists($k, $row)) { $out[$k] = $row[$k]; } }
    return $out;
}
function api_safe_product(array $r): array
{
    return api_pick($r, [
        'id','name','slug','sku','barcode','category_id','category_name','category_slug',
        'brand_name','brand_slug','short_description','full_description','product_type',
        'price_cents','compare_cents','tax_rate','stock','low_stock_threshold','weight_grams',
        'dimensions','warranty','return_eligible','return_days','status','is_featured',
        'is_best_seller','is_new_arrival','is_trending','is_digital','rating_avg','rating_count',
        'sold_count','seo_title','seo_description','video_url','image_url','created_at','updated_at'
    ]);
}
function api_safe_products(array $rows): array
{
    return array_map('api_safe_product', $rows);
}
function api_safe_user(array $r): array
{
    return api_pick($r, ['id','email','full_name','phone','avatar_url','role_slug','role_name','status','newsletter_opt_in','last_login_at','created_at']);
}
function api_safe_order(array $r): array
{
    return api_pick($r, [
        'id','order_number','status','payment_status','payment_method','shipping_method',
        'subtotal_cents','discount_cents','shipping_cents','tax_cents','total_cents',
        'coupon_code','currency','customer_email','customer_phone','customer_name',
        'shipping_address','billing_address','customer_note','placed_at','created_at',
        'updated_at','delivered_at','cancelled_at','item_count'
    ]);
}
function api_safe_orders(array $rows): array { return array_map('api_safe_order', $rows); }
function api_safe_cart_item(array $r): array
{
    return api_pick($r, [
        'id','product_id','variant_id','name','slug','sku','image_url','unit_price_cents',
        'compare_cents','quantity','line_total_cents','stock','available','variant_name',
        'options','weight_grams','tax_rate'
    ]);
}
function api_safe_totals(array $t): array
{
    $out = $t;
    $out['items'] = array_map('api_safe_cart_item', (array)($t['items'] ?? []));
    if (isset($out['cart']) && is_array($out['cart'])) {
        $out['cart'] = api_pick($out['cart'], ['id','coupon_code','status']);
    }
    return $out;
}
function api_no_secrets(mixed $v): string
{
    return Security::redact(Helpers::json($v));
}

function api_counts(): array
{
    $wishlist = 0;
    if (Auth::check()) {
        try { $wishlist = \AllStore\Models\CustomerRepository::wishlistCount((string)Auth::id()); } catch (\Throwable) { $wishlist = 0; }
    }
    return ['cart_count'=>\AllStore\Services\CartService::count(), 'wishlist_count'=>$wishlist];
}
function api_formatted_totals(array $t): array
{
    return [
        'subtotal'=>Helpers::money((int)($t['subtotal_cents'] ?? 0)),
        'discount'=>'−'.Helpers::money((int)($t['discount_cents'] ?? 0)),
        'shipping'=>Helpers::money((int)($t['shipping_cents'] ?? 0)),
        'tax'=>Helpers::money((int)($t['tax_cents'] ?? 0)),
        'total'=>Helpers::money((int)($t['total_cents'] ?? 0)),
    ];
}
function api_cart_response(string $message, array $extra = []): never
{
    $totals = \AllStore\Services\CartService::totals();
    $line = null;
    foreach ($totals['items'] as $it) {
        if (isset($extra['item_id']) && (string)$it['id'] === (string)$extra['item_id']) { $line = Helpers::money((int)$it['line_total_cents']); }
    }
    Response::ok($message, array_merge(api_counts(), [
        'totals'=>api_formatted_totals($totals),
        'cart'=>api_safe_totals($totals),
        'line_total'=>$line,
    ], $extra));
}
