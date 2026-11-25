<?php
declare(strict_types=1);
namespace AllStore\Models;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\AppException;
use AllStore\Core\Auth;
use AllStore\Services\CartService;

final class CustomerRepository
{
    public static function addresses(string $userId): array
    {
        return Database::all('SELECT * FROM addresses WHERE user_id=:u ORDER BY is_default_shipping DESC, created_at ASC', ['u'=>$userId]);
    }
    public static function saveAddress(string $userId, array $in, ?string $id = null): string
    {
        $data = [
            'label'=>mb_substr(trim((string)($in['label'] ?? 'Home')),0,40),
            'full_name'=>mb_substr(trim((string)($in['full_name'] ?? '')),0,120),
            'phone'=>preg_replace('/[^0-9+\-\s()]/','',(string)($in['phone'] ?? '')) ?? '',
            'line1'=>mb_substr(trim((string)($in['line1'] ?? '')),0,180),
            'line2'=>mb_substr(trim((string)($in['line2'] ?? '')),0,180),
            'city'=>mb_substr(trim((string)($in['city'] ?? '')),0,80),
            'region'=>mb_substr(trim((string)($in['region'] ?? '')),0,80),
            'postal_code'=>mb_substr(trim((string)($in['postal_code'] ?? '')),0,20),
            'country'=>strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)($in['country'] ?? 'US')) ?: 'US',0,2)),
            'is_default_shipping'=>(int)($in['is_default_shipping'] ?? 0),
            'is_default_billing'=>(int)($in['is_default_billing'] ?? 0),
            'updated_at'=>Helpers::now(),
        ];
        if (mb_strlen($data['full_name']) < 2 || mb_strlen($data['line1']) < 4 || mb_strlen($data['city']) < 2 || strlen(preg_replace('/\D/','',$data['phone']) ?? '') < 7) {
            throw new AppException('Please complete all required address fields.', 422);
        }
        if ((int)$data['is_default_shipping'] === 1) {
            Database::exec('UPDATE addresses SET is_default_shipping=0 WHERE user_id=:u', ['u'=>$userId]);
        }
        if ($id) {
            $own = Database::first('SELECT id FROM addresses WHERE id=:i AND user_id=:u', ['i'=>$id,'u'=>$userId]);
            if (!$own) { throw new AppException('Address not found.', 404); }
            Database::update('addresses', $data, 'id=:i', ['i'=>$id]);
            return $id;
        }
        $data['id'] = $id = Helpers::uuid(); $data['user_id'] = $userId; $data['created_at'] = Helpers::now();
        Database::insert('addresses', $data);
        return $id;
    }
    public static function deleteAddress(string $userId, string $id): void
    {
        $n = Database::delete('addresses', 'id=:i AND user_id=:u', ['i'=>$id,'u'=>$userId]);
        if ($n === 0) { throw new AppException('Address not found.', 404); }
    }

    private static function ensureWishlist(string $userId): string
    {
        $w = Database::first('SELECT id FROM wishlists WHERE user_id=:u', ['u'=>$userId]);
        if ($w) { return (string)$w['id']; }
        $id = Helpers::uuid();
        Database::insert('wishlists', ['id'=>$id,'user_id'=>$userId,'created_at'=>Helpers::now()]);
        return $id;
    }
    public static function wishlistItems(string $userId): array
    {
        $wid = self::ensureWishlist($userId);
        return Database::all("SELECT wi.*, p.name,p.slug,p.price_cents,p.compare_cents,p.stock,
            (SELECT pi.url FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_url
            FROM wishlist_items wi JOIN products p ON p.id=wi.product_id
            WHERE wi.wishlist_id=:w AND p.deleted_at IS NULL ORDER BY wi.created_at DESC", ['w'=>$wid]);
    }
    public static function toggleWishlist(string $userId, string $productId): bool
    {
        $p = Database::first("SELECT id FROM products WHERE id=:i AND status='active' AND deleted_at IS NULL", ['i'=>$productId]);
        if (!$p) { throw new AppException('Product not found.', 404); }
        $wid = self::ensureWishlist($userId);
        $ex = Database::first('SELECT id FROM wishlist_items WHERE wishlist_id=:w AND product_id=:p AND variant_id=\'\'', ['w'=>$wid,'p'=>$productId]);
        if ($ex) { Database::delete('wishlist_items', 'id=:i', ['i'=>$ex['id']]); return false; }
        Database::insert('wishlist_items', ['id'=>Helpers::uuid(),'wishlist_id'=>$wid,'product_id'=>$productId,
            'variant_id'=>'','created_at'=>Helpers::now()]);
        return true;
    }
    public static function removeWishlist(string $userId, string $itemId): void
    {
        Database::delete('wishlist_items', 'id=:i AND wishlist_id IN (SELECT id FROM wishlists WHERE user_id=:u)', ['i'=>$itemId,'u'=>$userId]);
    }
    public static function wishlistCount(string $userId): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM wishlist_items WHERE wishlist_id IN (SELECT id FROM wishlists WHERE user_id=:u)', ['u'=>$userId]);
    }

    public static function notifications(string $userId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all("SELECT * FROM notifications WHERE user_id=:u ORDER BY created_at DESC LIMIT $limit", ['u'=>$userId]);
    }
    public static function markNotificationsRead(string $userId, ?string $id = null): void
    {
        if ($id) { Database::update('notifications', ['is_read'=>1], 'id=:i AND user_id=:u', ['i'=>$id,'u'=>$userId]); }
        else { Database::update('notifications', ['is_read'=>1], 'user_id=:u AND is_read=0', ['u'=>$userId]); }
    }
    public static function reviewsForUser(string $userId): array
    {
        return Database::all('SELECT r.*, p.name AS product_name, p.slug AS product_slug FROM reviews r JOIN products p ON p.id=r.product_id WHERE r.user_id=:u ORDER BY r.created_at DESC', ['u'=>$userId]);
    }
    public static function eligibleReviews(string $userId): array
    {
        return Database::all("SELECT DISTINCT oi.product_id, p.name, p.slug, o.order_number, oi.id AS order_item_id
            FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id
            WHERE o.user_id=:u AND o.status='delivered'
            AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.product_id=oi.product_id AND r.user_id=:u2)
            ORDER BY o.placed_at DESC LIMIT 30", ['u'=>$userId,'u2'=>$userId]);
    }
    public static function couponsForUser(string $userId): array
    {
        return Database::all("SELECT c.code, c.type, c.percent, c.value_cents, c.min_order_cents, c.expires_at,
            (SELECT COUNT(*) FROM coupon_usage cu WHERE cu.coupon_id=c.id AND cu.user_id=:u) AS used_by_you
            FROM coupons c WHERE c.is_active=1 AND (c.expires_at IS NULL OR c.expires_at > :n) ORDER BY c.expires_at ASC",
            ['u'=>$userId,'n'=>Helpers::now()]);
    }
    public static function returnsForUser(string $userId): array
    {
        return Database::all("SELECT rr.*, o.order_number, oi.name AS product_name FROM return_requests rr
            JOIN orders o ON o.id=rr.order_id JOIN order_items oi ON oi.id=rr.order_item_id
            WHERE rr.user_id=:u ORDER BY rr.created_at DESC", ['u'=>$userId]);
    }
    public static function refundsForUser(string $userId): array
    {
        return Database::all("SELECT f.*, o.order_number FROM refunds f JOIN orders o ON o.id=f.order_id
            WHERE o.user_id=:u ORDER BY f.created_at DESC", ['u'=>$userId]);
    }
    public static function updateProfile(string $userId, array $in): void
    {
        $name = mb_substr(trim((string)($in['full_name'] ?? '')),0,120);
        $phone = preg_replace('/[^0-9+\-\s()]/','',(string)($in['phone'] ?? '')) ?? '';
        if (mb_strlen($name) < 2) { throw new AppException('Please enter your full name.', 422); }
        Database::update('profiles', ['full_name'=>$name,'phone'=>$phone,
            'newsletter_opt_in'=>(int)($in['newsletter_opt_in'] ?? 0),'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$userId]);
    }
    public static function allCustomers(array $f = []): array
    {
        $w = ['p.deleted_at IS NULL']; $p = [];
        if (!empty($f['q'])) { $w[] = '(LOWER(p.email) LIKE :q OR LOWER(p.full_name) LIKE :q)'; $p['q'] = '%'.mb_strtolower(mb_substr((string)$f['q'],0,80)).'%'; }
        if (!empty($f['status'])) { $w[] = 'p.status=:s'; $p['s'] = (string)$f['status']; }
        $where = implode(' AND ',$w);
        $page = max(1,(int)($f['page'] ?? 1)); $per = 20; $off = ($page-1)*$per;
        $total = (int)Database::value("SELECT COUNT(*) FROM profiles p WHERE $where", $p);
        $items = Database::all("SELECT p.id,p.email,p.full_name,p.phone,p.status,p.created_at,p.last_login_at,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id=p.id) AS order_count,
            (SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.user_id=p.id AND o.status<>'cancelled') AS total_spent_cents
            FROM profiles p WHERE $where ORDER BY p.created_at DESC LIMIT $per OFFSET $off", $p);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$per))];
    }
    public static function customerDetails(string $id): ?array
    {
        return Database::first('SELECT p.*, r.name AS role_name FROM profiles p LEFT JOIN roles r ON r.id=p.role_id WHERE p.id=:i AND p.deleted_at IS NULL', ['i'=>$id]);
    }
    public static function exportCsv(): string
    {
        $rows = self::allCustomers(['page'=>1])['items'];
        $h = fopen('php://temp','w+');
        fputcsv($h, ['email','name','phone','status','orders','total_spent','joined']);
        foreach ($rows as $r) { fputcsv($h, [$r['email'],$r['full_name'],$r['phone'],$r['status'],$r['order_count'],number_format($r['total_spent_cents']/100,2,'.',''),$r['created_at']]); }
        rewind($h); $csv=(string)stream_get_contents($h); fclose($h);
        return $csv;
    }
}
