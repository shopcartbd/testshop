<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\AppException;
use AllStore\Models\CatalogRepository;

/** Prices, stock, discounts, tax and totals are ALWAYS recomputed from the DB. */
final class CartService
{
    /** Request-scope caches; remote PostgreSQL round trips are expensive. */
    private static ?array $cartCache = null;
    private static bool $cartLoaded = false;
    private static array $itemsCache = [];

    private static function invalidateItems(): void { self::$itemsCache = []; }

    /** Forget cached rows after an external mutation (e.g. checkout). */
    public static function invalidate(): void
    {
        self::$cartCache = null;
        self::$cartLoaded = false;
        self::$itemsCache = [];
    }

    private static function touchCart(array $fields = []): void
    {
        if (self::$cartCache === null) { self::invalidateItems(); return; }
        $fields['updated_at'] = Helpers::now();
        Database::update('carts', $fields, 'id=:i', ['i'=>self::$cartCache['id']]);
        foreach ($fields as $k => $v) { self::$cartCache[$k] = $v; }
        self::invalidateItems();
    }

    public static function cart(bool $create = true): ?array
    {
        if (self::$cartLoaded && (!$create || self::$cartCache !== null)) {
            return self::$cartCache;
        }
        $uid = Auth::id();
        $key = Session::id();
        $row = $uid
            ? Database::first("SELECT * FROM carts WHERE user_id=:u AND status='open' ORDER BY created_at ASC LIMIT 1", ['u'=>$uid])
            : Database::first("SELECT * FROM carts WHERE session_key=:s AND user_id IS NULL AND status='open' ORDER BY created_at ASC LIMIT 1", ['s'=>$key]);
        if (!$row && $create) {
            $now = Helpers::now();
            $row = ['id'=>Helpers::uuid(),'session_key'=>$key,'user_id'=>$uid,'coupon_code'=>null,
                'status'=>'open','created_at'=>$now,'updated_at'=>$now];
            Database::insert('carts', $row);
        }
        self::$cartCache = $row;
        self::$cartLoaded = true;
        return $row;
    }

    public static function mergeGuestCart(string $userId): void
    {
        self::invalidate();
        $guest = Database::first("SELECT * FROM carts WHERE session_key=:s AND user_id IS NULL AND status='open'", ['s'=>Session::id()]);
        if (!$guest) { return; }
        $mine = Database::first("SELECT * FROM carts WHERE user_id=:u AND status='open'", ['u'=>$userId]);
        if (!$mine) {
            Database::update('carts', ['user_id'=>$userId,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$guest['id']]);
            return;
        }
        if ($mine['id'] === $guest['id']) { return; }
        foreach (Database::all('SELECT * FROM cart_items WHERE cart_id=:c', ['c'=>$guest['id']]) as $item) {
            $existing = Database::first('SELECT * FROM cart_items WHERE cart_id=:c AND product_id=:p AND variant_id=:v AND saved_for_later=:s',
                ['c'=>$mine['id'],'p'=>$item['product_id'],'v'=>$item['variant_id'],'s'=>$item['saved_for_later']]);
            if ($existing) {
                Database::update('cart_items', ['quantity'=>(int)$existing['quantity'] + (int)$item['quantity'],'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$existing['id']]);
            } else {
                Database::insert('cart_items', ['id'=>Helpers::uuid(),'cart_id'=>$mine['id'],'product_id'=>$item['product_id'],
                    'variant_id'=>$item['variant_id'],'quantity'=>$item['quantity'],'saved_for_later'=>$item['saved_for_later'],
                    'created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
            }
        }
        Database::delete('cart_items', 'cart_id=:c', ['c'=>$guest['id']]);
        Database::delete('carts', 'id=:i', ['i'=>$guest['id']]);
        self::invalidate();
    }

    public static function items(bool $saved = false): array
    {
        $cacheKey = $saved ? 'saved' : 'active';
        if (array_key_exists($cacheKey, self::$itemsCache)) { return self::$itemsCache[$cacheKey]; }
        $cart = self::cart(false);
        if (!$cart) { return self::$itemsCache[$cacheKey] = []; }
        $rows = Database::all("SELECT ci.*, p.name, p.slug, p.sku, p.price_cents, p.compare_cents, p.stock,
            p.status AS product_status, p.weight_grams, p.tax_rate, p.return_eligible,
            p.is_digital, p.product_type,
            v.name AS variant_name, v.sku AS variant_sku, v.price_cents AS variant_price, v.stock AS variant_stock, v.options AS variant_options,
            (SELECT pi.url FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_url
            FROM cart_items ci JOIN products p ON p.id=ci.product_id
            LEFT JOIN product_variants v ON v.id=ci.variant_id
            WHERE ci.cart_id=:c AND ci.saved_for_later=:s ORDER BY ci.created_at ASC", ['c'=>$cart['id'],'s'=>$saved ? 1 : 0]);
        $out = [];
        foreach ($rows as $r) {
            $hasVariant = (string)$r['variant_id'] !== '' && $r['variant_price'] !== null;
            $unit = (int)($hasVariant ? $r['variant_price'] : $r['price_cents']);
            $max = (int)($hasVariant ? $r['variant_stock'] : $r['stock']);
            $qty = min(max(1, (int)$r['quantity']), 99);
            $opts = (array)Helpers::decode($r['variant_options'] ?? null, []);
            $stockVariantId = Helpers::orderStockVariantId(['variant_id'=>(string)$r['variant_id'],'options'=>$opts]);
            if ($stockVariantId !== '' && $stockVariantId !== (string)$r['variant_id']) {
                $baseStock = Database::value('SELECT stock FROM product_variants WHERE id=:i', ['i'=>$stockVariantId]);
                if ($baseStock !== null && $baseStock !== false) { $max = (int)$baseStock; }
            }
            $available = $r['product_status'] === 'active' && $max > 0 && $qty <= $max;
            $requiresShipping = (int)($r['is_digital'] ?? 0) !== 1;
            $out[] = [
                'id'=>$r['id'], 'product_id'=>$r['product_id'], 'variant_id'=>(string)$r['variant_id'],
                'name'=>$r['name'], 'slug'=>$r['slug'], 'sku'=>(string)($hasVariant ? $r['variant_sku'] : $r['sku']),
                'image_url'=>$r['image_url'] ?: '/assets/img/products/headphones.jpg',
                'variant_name'=>$r['variant_name'] ?? '', 'options'=>$opts,
                'unit_price_cents'=>$unit, 'quantity'=>$qty, 'max_stock'=>$max,
                'line_total_cents'=>$unit * $qty, 'available'=>$available,
                'weight_grams'=>(int)$r['weight_grams'], 'tax_rate'=>(float)$r['tax_rate'],
                'saved_for_later'=>(int)$r['saved_for_later'], 'product_type'=>(string)($r['product_type'] ?? 'simple'),
                'is_digital'=>(int)($r['is_digital'] ?? 0), 'requires_shipping'=>$requiresShipping,
                'stock_variant_id'=>$stockVariantId,
            ];
        }
        return self::$itemsCache[$cacheKey] = $out;
    }

    public static function count(): int
    {
        $n = 0;
        foreach (self::items() as $i) { $n += $i['quantity']; }
        return $n;
    }

    public static function subtotal(): int
    {
        $s = 0;
        foreach (self::items() as $i) { if ($i['available']) { $s += $i['line_total_cents']; } }
        return $s;
    }

    public static function add(string $productId, string $variantId = '', int $qty = 1, array $customOptions = []): array
    {
        $qty = max(1, min(99, $qty));
        $p = Database::first("SELECT * FROM products WHERE id=:i AND status='active' AND deleted_at IS NULL", ['i'=>$productId]);
        if (!$p) { throw new AppException('This product is no longer available.', 404); }
        $stock = (int)$p['stock'];
        $price = (int)$p['price_cents'];
        $baseVariant = null;
        if ($variantId !== '') {
            $v = Database::first('SELECT * FROM product_variants WHERE id=:i AND product_id=:p AND is_active=1', ['i'=>$variantId,'p'=>$productId]);
            if (!$v) { throw new AppException('The selected variant is not available.', 404); }
            $baseVariant = $v;
            $stock = (int)$v['stock']; $price = (int)$v['price_cents'];
        }
        if ($stock < 1) { throw new AppException('This item is currently out of stock.', 409); }
        $definitions = CatalogRepository::customOptions($productId);
        if ($customOptions !== [] && $definitions === []) {
            throw new AppException('This product does not accept custom options.', 422);
        }
        $customOptions = $definitions === [] ? [] : CatalogRepository::validateCustomOptions($definitions, $customOptions);
        if ($customOptions !== []) { $variantId = self::customVariant($p, $baseVariant, $variantId, $price, $customOptions); }
        $cart = self::cart();
        $existing = Database::first('SELECT * FROM cart_items WHERE cart_id=:c AND product_id=:p AND variant_id=:v AND saved_for_later=0',
            ['c'=>$cart['id'],'p'=>$productId,'v'=>$variantId]);
        $newQty = min(99, $qty + (int)($existing['quantity'] ?? 0));
        if ($newQty > $stock) { throw new AppException('Only ' . $stock . ' units are available.', 409); }
        if ($existing) {
            Database::update('cart_items', ['quantity'=>$newQty,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$existing['id']]);
        } else {
            Database::insert('cart_items', ['id'=>Helpers::uuid(),'cart_id'=>$cart['id'],'product_id'=>$productId,
                'variant_id'=>$variantId,'quantity'=>$newQty,'saved_for_later'=>0,
                'created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
        }
        self::touchCart();
        return ['count'=>self::count(), 'price_cents'=>$price];
    }

    /** Persist a configured custom product as a deterministic active variant. */
    private static function customVariant(array $p, ?array $baseVariant, string $baseVariantId, int $price, array $customOptions): string
    {
        $productId = (string)$p['id'];
        $meta = CatalogRepository::productMeta($productId);
        $snapshot = $customOptions;
        if ($baseVariant !== null) {
            foreach ((array)Helpers::decode((string)($baseVariant['options'] ?? '{}'), []) as $k => $v) {
                if (!str_starts_with((string)$k, '__')) { $snapshot[(string)$k] = $v; }
            }
            $snapshot['__base_variant_id'] = $baseVariantId;
        }
        $snapshot['__product_kind'] = $meta['kind'];
        if ($meta['digital_file_url'] !== '') { $snapshot['__download_url'] = $meta['digital_file_url']; }
        if ($meta['bundle_items'] !== []) { $snapshot['__bundle_items'] = $meta['bundle_items']; }
        $json = Helpers::json($snapshot);
        $hash = hash('sha256', $productId . '|' . $baseVariantId . '|' . $json);
        $id = 'custom-' . substr($hash, 0, 32);
        $sku = 'CUSTOM-' . strtoupper(substr($hash, 0, 16));
        $labels = [];
        foreach ($customOptions as $row) { $labels[] = (string)($row['label'] ?? '') . ': ' . (string)($row['value'] ?? ''); }
        $name = mb_substr(($baseVariant['name'] ?? $p['name']) . ($labels !== [] ? ' — ' . implode(', ', $labels) : ' — Custom'), 0, 120);
        $image = (string)($baseVariant['image_url'] ?? '');
        if ($image === '') {
            $image = (string)Database::value('SELECT url FROM product_images WHERE product_id=:p ORDER BY is_primary DESC, sort_order ASC LIMIT 1', ['p'=>$productId]);
        }
        $row = Database::first('SELECT * FROM product_variants WHERE sku=:s', ['s'=>$sku]);
        if ($row && (string)$row['product_id'] === $productId) { return (string)$row['id']; }
        $data = [
            'id'=>$id, 'product_id'=>$productId, 'sku'=>$sku, 'barcode'=>'', 'name'=>$name,
            'options'=>$json, 'price_cents'=>$price, 'compare_cents'=>(int)($p['compare_cents'] ?? 0),
            'stock'=>999999, 'image_url'=>$image, 'is_active'=>1,
            'created_at'=>Helpers::now(), 'updated_at'=>Helpers::now(),
        ];
        $exists = Database::first('SELECT id FROM product_variants WHERE id=:i', ['i'=>$id]);
        if ($exists) { Database::update('product_variants', ['sku'=>$sku,'name'=>$name,'options'=>$json,'price_cents'=>$price,'image_url'=>$image,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$id]); }
        else { Database::insert('product_variants', $data); }
        return $id;
    }

    public static function needsShipping(): bool
    {
        $items = self::items();
        if ($items === []) { return true; }
        foreach ($items as $i) { if (!empty($i['requires_shipping'])) { return true; } }
        return false;
    }

    private static function ownedItem(string $itemId): array
    {
        $cart = self::cart(false);
        if (!$cart) { throw new AppException('Your cart is empty.', 404); }
        $item = Database::first('SELECT * FROM cart_items WHERE id=:i AND cart_id=:c', ['i'=>$itemId,'c'=>$cart['id']]);
        if (!$item) { throw new AppException('Cart item not found.', 404); }
        return $item;
    }

    public static function update(string $itemId, int $qty): void
    {
        $item = self::ownedItem($itemId);
        if ($qty <= 0) { self::remove($itemId); return; }
        $qty = min(99, $qty);
        $stock = (int)Database::value('SELECT stock FROM products WHERE id=:i', ['i'=>$item['product_id']]);
        if ($item['variant_id'] !== '') {
            $stock = (int)Database::value('SELECT stock FROM product_variants WHERE id=:i', ['i'=>$item['variant_id']]);
        }
        if ($qty > $stock) { throw new AppException('Only ' . $stock . ' units are available.', 409); }
        Database::update('cart_items', ['quantity'=>$qty,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$itemId]);
        self::touchCart();
    }

    public static function remove(string $itemId): void
    {
        self::ownedItem($itemId);
        Database::delete('cart_items', 'id=:i', ['i'=>$itemId]);
        self::touchCart();
    }

    public static function saveForLater(string $itemId, bool $save): void
    {
        self::ownedItem($itemId);
        Database::update('cart_items', ['saved_for_later'=>$save ? 1 : 0,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$itemId]);
        self::touchCart();
    }

    public static function applyCoupon(string $code): array
    {
        $items = self::items();
        $res = CouponService::validate($code, self::subtotal(), $items);
        if (!$res['ok']) {
            self::clearCoupon();
            throw new AppException($res['message'], 422);
        }
        self::cart();
        self::touchCart(['coupon_code'=>strtoupper(trim($code))]);
        return $res;
    }
    public static function clearCoupon(): void
    {
        $cart = self::cart(false);
        if ($cart) { self::touchCart(['coupon_code'=>null]); }
    }
    public static function couponCode(): string
    {
        $cart = self::cart(false);
        return strtoupper((string)($cart['coupon_code'] ?? ''));
    }

    public static function totals(?string $shippingMethod = null, ?string $country = null, array $location = []): array
    {
        $items = self::items();
        $subtotal = 0; $weight = 0; $tax = 0; $unavailable = false;
        foreach ($items as $i) {
            if (!$i['available']) { $unavailable = true; continue; }
            $subtotal += $i['line_total_cents'];
            $weight += $i['weight_grams'] * $i['quantity'];
        }
        $couponResult = CouponService::validate(self::couponCode(), $subtotal, $items);
        $discount = $couponResult['ok'] ? (int)$couponResult['discount_cents'] : 0;
        $country ??= strtoupper((string)(Session::get('checkout_country') ?: \AllStore\Services\LocationService::defaultCountry()));
        $requiresShipping = self::needsShipping();
        $location = is_array($location) ? $location : [];
        $location['digital_only'] = !$requiresShipping;
        $shipping = ShippingService::pick($shippingMethod ?: (string)(Session::get('checkout_shipping_method') ?: 'standard'),
            $country, $weight, max(0, $subtotal - $discount), $couponResult['free_shipping'], $location);
        $afterDiscount = max(0, $subtotal - $discount);
        if ($afterDiscount > 0) {
            foreach ($items as $i) {
                if (!$i['available']) { continue; }
                $line = (int)round($i['line_total_cents'] * ($afterDiscount / $subtotal));
                $tax += (int)round($line * ((float)$i['tax_rate'] / 100));
            }
        }
        return [
            'items'=>$items, 'item_count'=>count($items), 'units'=>array_sum(array_column($items,'quantity')),
            'subtotal_cents'=>$subtotal, 'discount_cents'=>$discount, 'shipping_cents'=>(int)$shipping['cost_cents'],
            'tax_cents'=>$tax, 'total_cents'=>$afterDiscount + (int)$shipping['cost_cents'] + $tax,
            'coupon'=>$couponResult['ok'] ? $couponResult['coupon']['code'] : null,
            'coupon_valid'=>$couponResult['ok'], 'coupon_message'=>$couponResult['message'],
            'free_shipping'=>$couponResult['free_shipping'], 'shipping'=>$shipping,
            'country'=>$country, 'has_unavailable'=>$unavailable,
            'requires_shipping'=>$requiresShipping, 'location'=>$location,
        ];
    }

    public static function clear(): void
    {
        $cart = self::cart(false);
        if ($cart) {
            Database::delete('cart_items', 'cart_id=:c AND saved_for_later=0', ['c'=>$cart['id']]);
            self::touchCart(['coupon_code'=>null,'status'=>'converted']);
        }
    }
}
