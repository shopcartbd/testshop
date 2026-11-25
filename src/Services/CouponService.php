<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Security;

/** All coupon rules are evaluated server-side. Browser values are never trusted. */
final class CouponService
{
    public static function validate(string $code, int $subtotalCents, array $items = [], ?string $userId = null): array
    {
        $code = strtoupper(trim($code));
        $empty = ['ok'=>false,'message'=>'Coupon not applied.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null];
        if ($code === '' || !preg_match('/^[A-Z0-9\-]{2,32}$/', $code)) { return $empty; }
        if (!Security::rateLimit('coupon:' . Request::ip(), 20, 600)) {
            return ['ok'=>false,'message'=>'Too many coupon attempts. Please wait a moment.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null];
        }
        $c = Database::first('SELECT * FROM coupons WHERE code=:c AND is_active=1', ['c'=>$code]);
        if (!$c) { return ['ok'=>false,'message'=>'This coupon code is not valid.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null]; }
        $now = time();
        if ($c['starts_at'] && strtotime((string)$c['starts_at']) > $now) { return ['ok'=>false,'message'=>'This coupon is not active yet.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null]; }
        if ($c['expires_at'] && strtotime((string)$c['expires_at']) < $now) { return ['ok'=>false,'message'=>'This coupon has expired.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null]; }
        if ((int)$c['usage_limit'] > 0 && (int)$c['used_count'] >= (int)$c['usage_limit']) {
            return ['ok'=>false,'message'=>'This coupon has reached its usage limit.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null];
        }
        if ($subtotalCents < (int)$c['min_order_cents']) {
            return ['ok'=>false,'message'=>'Minimum order of ' . Helpers::money((int)$c['min_order_cents']) . ' required.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null];
        }
        $uid = $userId ?? Auth::id();
        if ((int)$c['per_user_limit'] > 0 && $uid) {
            $used = (int)Database::value('SELECT COUNT(*) FROM coupon_usage WHERE coupon_id=:c AND user_id=:u', ['c'=>$c['id'],'u'=>$uid]);
            if ($used >= (int)$c['per_user_limit']) { return ['ok'=>false,'message'=>'You have already used this coupon.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null]; }
        }
        if ((int)$c['first_order_only'] === 1 && $uid) {
            $orders = (int)Database::value("SELECT COUNT(*) FROM orders WHERE user_id=:u AND status<>'cancelled'", ['u'=>$uid]);
            if ($orders > 0) { return ['ok'=>false,'message'=>'This coupon is for first orders only.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null]; }
        }
        // Eligible subtotal honours category / product restrictions.
        $eligible = $subtotalCents;
        if ($items !== [] && ($c['category_id'] || $c['product_id'])) {
            $eligible = 0;
            foreach ($items as $it) {
                $pid = (string)($it['product_id'] ?? '');
                if ($c['product_id'] && $pid !== (string)$c['product_id']) { continue; }
                if ($c['category_id']) {
                    $cat = Database::value('SELECT category_id FROM products WHERE id=:i', ['i'=>$pid]);
                    $ids = \AllStore\Models\CatalogRepository::categoryIds((string)$c['category_id']);
                    if (!in_array((string)$cat, $ids, true)) { continue; }
                }
                $eligible += (int)($it['line_total_cents'] ?? 0);
            }
            if ($eligible <= 0) { return ['ok'=>false,'message'=>'This coupon does not apply to the items in your cart.','discount_cents'=>0,'free_shipping'=>false,'coupon'=>null]; }
        }
        $discount = 0;
        if ($c['type'] === 'percent') {
            $discount = (int)round($eligible * ((float)$c['percent'] / 100));
        } elseif ($c['type'] === 'fixed') {
            $discount = (int)$c['value_cents'];
        }
        if ((int)$c['max_discount_cents'] > 0) { $discount = min($discount, (int)$c['max_discount_cents']); }
        $discount = max(0, min($discount, $subtotalCents));
        $free = ((int)$c['free_shipping'] === 1 || $c['type'] === 'free_shipping');
        return ['ok'=>true,'message'=>'Coupon applied.','discount_cents'=>$discount,'free_shipping'=>$free,'coupon'=>$c];
    }
}
