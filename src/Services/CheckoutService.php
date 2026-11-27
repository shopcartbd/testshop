<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Security;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Core\Config;
use AllStore\Models\CatalogRepository;
use AllStore\Models\CustomerRepository;

/**
 * Transaction-safe order creation. Prices, coupon, stock, shipping and tax are
 * recomputed here - no browser-submitted amount is ever used.
 */
final class CheckoutService
{
    public static function idempotencyKey(?string $submitted = null): string
    {
        $k = trim((string)($submitted ?? ''));
        if ($k !== '' && preg_match('/^[a-f0-9]{32,64}$/', $k)) {
            Session::set('checkout_idem', $k);
            return $k;
        }
        $k = (string)(Session::get('checkout_idem') ?? '');
        if (!preg_match('/^[a-f0-9]{32,64}$/', $k)) {
            $k = Helpers::token(24);
            Session::set('checkout_idem', $k);
        }
        return $k;
    }

    /** Start a fresh checkout attempt. A successful key stays valid so a repeated POST returns the same order. */
    public static function rotateIdempotencyKey(): string
    {
        $k = Helpers::token(24);
        Session::set('checkout_idem', $k);
        return $k;
    }

    public static function create(array $in): array
    {
        if (!Security::rateLimit('checkout:' . Request::ip(), 8, 600)) {
            throw new AppException('Too many checkout attempts. Please wait a few minutes.', 429);
        }
        $name = trim((string)($in['full_name'] ?? ''));
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $phone = preg_replace('/[^0-9+\-\s()]/', '', (string)($in['phone'] ?? '')) ?? '';
        $line1 = trim((string)($in['line1'] ?? ''));
        $line2 = trim((string)($in['line2'] ?? ''));
        $city = trim((string)($in['city'] ?? ''));
        $region = trim((string)($in['region'] ?? ''));
        $postal = trim((string)($in['postal_code'] ?? ''));
        $countryInput = (string)($in['country'] ?? '');
        $country = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $countryInput) ?: LocationService::defaultCountry(), 0, 2));
        $simpleBd = DeliveryZoneService::simpleCheckoutEnabled() && $country === 'BD';
        // The simplified BD form has no email field; derive a stable placeholder
        // from the phone so orders, invoices and duplicate detection still work.
        if ($simpleBd && $email === '') {
            $digits = preg_replace('/\D/', '', $phone) ?: '0000000';
            $email = 'bd' . substr($digits, -12) . '@phone.local';
        }
        $note = mb_substr(trim((string)($in['customer_note'] ?? '')), 0, 1000);
        $method = (string)($in['payment_method'] ?? 'cod');
        $shipMethod = (string)($in['shipping_method'] ?? 'standard');
        $idFromInput = static function (string $key) use ($in): string {
            $v = trim((string)($in[$key] ?? ''));
            return preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $v) ? $v : '';
        };
        $resolved = LocationService::resolve($country, $idFromInput('division_id'), $idFromInput('district_id'), $idFromInput('area_id'));
        $location = $resolved['location'];

        $errors = (array)($resolved['errors'] ?? []);
        if (mb_strlen($name) < 2) { $errors['full_name'] = 'Full name is required.'; }
        if (!$simpleBd && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'A valid email is required.'; }
        if (strlen(preg_replace('/\D/', '', $phone) ?? '') < 7) { $errors['phone'] = 'A valid phone number is required.'; }

        $idem = self::idempotencyKey(isset($in['idem']) ? (string)$in['idem'] : null);
        $existing = Database::first('SELECT * FROM orders WHERE idempotency_key=:k', ['k'=>$idem]);
        if ($existing) {
            $ownerId = (string)($existing['user_id'] ?? '');
            $mine = ($ownerId !== '' && Auth::id() !== null && $ownerId === (string)Auth::id())
                || ($ownerId === '' && strcasecmp((string)($existing['customer_email'] ?? ''), $email) === 0);
            if (!$mine) { throw new AppException('This checkout link has expired. Please refresh checkout and try again.', 409); }
            return ['order'=>$existing, 'payment_url'=>null, 'duplicate'=>true];
        }

        $cart = CartService::cart(false);
        if (!$cart) { throw new AppException('Your cart is empty.', 422); }
        $cartId = (string)$cart['id'];
        Session::set('checkout_country', $country);
        Session::set('checkout_shipping_method', $shipMethod);
        $location['digital_only'] = !CartService::needsShipping();
        $totals = CartService::totals($shipMethod, $country, $location);
        $items = $totals['items'];
        $needsShipping = (bool)($totals['requires_shipping'] ?? true);
        $location['digital_only'] = !$needsShipping;
        if ($items === []) { throw new AppException('Your cart is empty.', 422); }
        if ($totals['has_unavailable']) { throw new AppException('One or more items are unavailable. Please review your cart.', 409); }
        if ($totals['subtotal_cents'] <= 0) { throw new AppException('Your cart total is invalid.', 422); }
        if ($needsShipping && mb_strlen($line1) < 4) { $errors['line1'] = 'Street address is required.'; }
        if ($needsShipping && mb_strlen($city) < 2 && $location['area'] === '') { $errors['city'] = 'City or delivery area is required.'; }
        if ($needsShipping && $country === 'BD' && $location['area_id'] === '') {
            $errors['area_id'] = $simpleBd ? 'Select your division, district and thana for delivery.' : 'Select your division, district and upazila/thana for delivery.';
        }
        if ($errors) { throw new AppException('Please correct the highlighted fields.', 422, $errors); }
        if (!PaymentService::isAvailable($method)) { throw new AppException('The selected payment method is unavailable.', 422); }

        // Re-validate the coupon inside the transaction boundary as well.
        $coupon = CouponService::validate((string)CartService::couponCode(), $totals['subtotal_cents'], $items, Auth::id());
        $couponId = $coupon['ok'] ? (string)$coupon['coupon']['id'] : null;
        $couponCode = $coupon['ok'] ? (string)$coupon['coupon']['code'] : null;
        $discount = $coupon['ok'] ? (int)$coupon['discount_cents'] : 0;
        if (!$needsShipping) { $shipMethod = 'digital'; }
        $shipping = ShippingService::pick($shipMethod, $country,
            array_sum(array_map(static fn($i) => $i['weight_grams'] * $i['quantity'], $items)),
            max(0, $totals['subtotal_cents'] - $discount), $coupon['free_shipping'], $location);
        $shippingCents = (int)$shipping['cost_cents'];
        $tax = (int)$totals['tax_cents'];
        $grand = max(0, $totals['subtotal_cents'] - $discount) + $shippingCents + $tax;

        // The structured BD selection is stored with the order, while legacy
        // city/region columns remain populated for couriers, invoices and CSVs.
        if ($city === '' && $location['area'] !== '') { $city = $location['area']; }
        if ($region === '' && $location['division'] !== '') { $region = trim($location['division'] . ' / ' . $location['district']); }
        if (!$simpleBd && $line2 === '' && $location['district'] !== '') { $line2 = $location['district'] . ($location['area_type'] !== '' ? ' · ' . $location['area_type'] : ''); }
        $address = Helpers::json([
            'full_name'=>$name, 'line1'=>$line1, 'line2'=>$line2, 'city'=>$city,
            'region'=>$region, 'postal_code'=>$postal, 'country'=>$country, 'phone'=>$phone,
            'division'=>$location['division'], 'division_id'=>$location['division_id'],
            'district'=>$location['district'], 'district_id'=>$location['district_id'],
            'area'=>$location['area'], 'area_id'=>$location['area_id'], 'area_type'=>$location['area_type'],
        ]);
        $saveAddress = Auth::id() !== null && !empty($in['save_address']) && $needsShipping;
        $addressInput = ['label'=>'Checkout','full_name'=>$name,'phone'=>$phone,'line1'=>$line1,'line2'=>$line2,
            'city'=>$city,'region'=>$region,'postal_code'=>$postal,'country'=>$country,'is_default_shipping'=>1];
        $orderId = Helpers::uuid();
        $orderNumber = self::uniqueOrderNumber();

        try {
            $order = Database::tx(function () use ($orderId, $orderNumber, $idem, $name, $email, $phone, $address, $note,
                $method, $shipMethod, $items, $discount, $shippingCents, $tax, $grand, $couponId, $couponCode, $shipping, $cartId, $needsShipping): array {
                // 1. Atomic conditional stock decrement prevents overselling / races.
                foreach ($items as $it) {
                    $q = (int)$it['quantity'];
                    $affected = Database::exec("UPDATE products SET stock = stock - :q, sold_count = sold_count + :q, updated_at=:t
                        WHERE id=:id AND status='active' AND deleted_at IS NULL AND stock >= :q2",
                        ['q'=>$q,'q2'=>$q,'t'=>Helpers::now(),'id'=>$it['product_id']]);
                    if ($affected !== 1) { throw new AppException('An item just went out of stock. Please review your cart.', 409); }
                    $stockVariantId = Helpers::orderStockVariantId($it);
                    if ($stockVariantId !== '') {
                        $v = Database::exec('UPDATE product_variants SET stock = stock - :q, updated_at=:t WHERE id=:id AND stock >= :q2',
                            ['q'=>$q,'q2'=>$q,'t'=>Helpers::now(),'id'=>$stockVariantId]);
                        if ($v !== 1) { throw new AppException('The selected variant just went out of stock.', 409); }
                    }
                    $newStock = (int)Database::value('SELECT stock FROM products WHERE id=:i', ['i'=>$it['product_id']]);
                    Database::exec('UPDATE inventory SET quantity = quantity - :q, available = available - :q, updated_at=:t
                        WHERE product_id=:p AND variant_id=:v AND quantity >= :q2 AND available >= :q2',
                        ['q'=>$q,'q2'=>$q,'t'=>Helpers::now(),'p'=>$it['product_id'],'v'=>$stockVariantId]);
                    Database::insert('stock_movements', [
                        'id'=>Helpers::uuid(),'product_id'=>$it['product_id'],'variant_id'=>$stockVariantId,
                        'movement_type'=>'order','quantity_change'=>-$q,'quantity_after'=>$newStock,
                        'reference_type'=>'order','reference_id'=>$orderId,'note'=>'Order '.$orderNumber,
                        'created_by'=>Auth::id(),'created_at'=>Helpers::now(),
                    ]);
                    $p = Database::first('SELECT name, low_stock_threshold FROM products WHERE id=:i', ['i'=>$it['product_id']]);
                    if ($p && $newStock <= (int)$p['low_stock_threshold']) {
                        NotificationService::lowStock((string)$p['name'], (string)$it['product_id']);
                    }
                }
                // 2. Order header with server-calculated totals.
                $orderRow = [
                    'id'=>$orderId, 'order_number'=>$orderNumber, 'user_id'=>Auth::id(), 'guest_email'=>Auth::id() ? null : $email,
                    'status'=>'pending', 'payment_status'=>'pending', 'payment_method'=>$method,
                    'shipping_method'=>$shipping['method'], 'subtotal_cents'=>$totalsSub = array_sum(array_column($items,'line_total_cents')),
                    'discount_cents'=>$discount, 'shipping_cents'=>$shippingCents, 'tax_cents'=>$tax, 'total_cents'=>$grand,
                    'coupon_id'=>$couponId, 'coupon_code'=>$couponCode, 'currency'=>(string)Config::get('app.currency'),
                    'customer_email'=>$email, 'customer_phone'=>$phone, 'customer_name'=>$name,
                    'shipping_address'=>$address, 'billing_address'=>$address, 'customer_note'=>$note,
                    'idempotency_key'=>$idem, 'ip_address'=>Request::ip(), 'user_agent'=>Request::ua(),
                    'placed_at'=>Helpers::now(), 'created_at'=>Helpers::now(), 'updated_at'=>Helpers::now(),
                ];
                Database::insert('orders', $orderRow);
                // 3. Line items with proportionally allocated discount/tax.
                $subtotal = (int)$totalsSub;
                $allocatedDiscount = 0; $allocatedTax = 0; $n = count($items); $idx = 0;
                foreach ($items as $it) {
                    $idx++;
                    $lineDiscount = $idx === $n ? $discount - $allocatedDiscount : (int)round($discount * ($it['line_total_cents'] / max(1,$subtotal)));
                    $lineTax = (int)round(($it['line_total_cents'] - $lineDiscount) * ((float)$it['tax_rate'] / 100));
                    if ($idx === $n) { $lineTax = max(0, $tax - $allocatedTax); }
                    $allocatedDiscount += $lineDiscount; $allocatedTax += $lineTax;
                    $snapshot = (array)($it['options'] ?? []);
                    $meta = CatalogRepository::productMeta((string)$it['product_id']);
                    if ($meta['kind'] !== '') { $snapshot['__product_kind'] = $meta['kind']; }
                    if ($meta['digital_file_url'] !== '') { $snapshot['__download_url'] = $meta['digital_file_url']; }
                    if ($meta['bundle_items'] !== []) { $snapshot['__bundle_items'] = $meta['bundle_items']; }
                    Database::insert('order_items', [
                        'id'=>Helpers::uuid(),'order_id'=>$orderId,'product_id'=>$it['product_id'],'variant_id'=>$it['variant_id'],
                        'name'=>$it['name'],'sku'=>$it['sku'],'image_url'=>$it['image_url'],'options'=>Helpers::json($snapshot),
                        'unit_price_cents'=>$it['unit_price_cents'],'quantity'=>$it['quantity'],
                        'discount_cents'=>$lineDiscount,'tax_cents'=>$lineTax,
                        'total_cents'=>$it['line_total_cents'] - $lineDiscount + $lineTax,'created_at'=>Helpers::now(),
                    ]);
                }
                Database::insert('order_status_history', ['id'=>Helpers::uuid(),'order_id'=>$orderId,'from_status'=>null,
                    'to_status'=>'pending','note'=>'Order placed','changed_by'=>Auth::id(),'created_at'=>Helpers::now()]);
                Database::insert('payments', ['id'=>Helpers::uuid(),'order_id'=>$orderId,'provider'=>$method === 'stripe' ? 'stripe' : 'cod',
                    'method'=>$method,'status'=>'pending','amount_cents'=>$grand,'currency'=>(string)Config::get('app.currency'),
                    'created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
                if ($needsShipping) {
                    Database::insert('shipments', ['id'=>Helpers::uuid(),'order_id'=>$orderId,'status'=>'pending',
                        'estimated_delivery'=>gmdate('Y-m-d\TH:i:s\Z', time() + (int)$shipping['eta_max'] * 86400),
                        'created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
                }
                Database::insert('invoices', ['id'=>Helpers::uuid(),'order_id'=>$orderId,
                    'invoice_number'=>'INV-' . $orderNumber,'created_at'=>Helpers::now()]);
                if ($couponId) {
                    Database::insert('coupon_usage', ['id'=>Helpers::uuid(),'coupon_id'=>$couponId,'user_id'=>Auth::id(),
                        'order_id'=>$orderId,'discount_cents'=>$discount,'created_at'=>Helpers::now()]);
                    Database::exec('UPDATE coupons SET used_count = used_count + 1 WHERE id=:i', ['i'=>$couponId]);
                }
                Database::insert('notifications', ['id'=>Helpers::uuid(),'user_id'=>Auth::id(),'admin_only'=>0,
                    'type'=>'order_placed','title'=>'Order '.$orderNumber.' placed','body'=>'We received your order.',
                    'data'=>'{}','created_at'=>Helpers::now()]);
                Database::insert('notifications', ['id'=>Helpers::uuid(),'user_id'=>null,'admin_only'=>1,
                    'type'=>'new_order','title'=>'New order '.$orderNumber,'body'=>'A new order needs review.',
                    'data'=>'{}','created_at'=>Helpers::now()]);
                Database::delete('cart_items', 'cart_id=:c AND saved_for_later=0', ['c'=>$cartId]);
                Database::update('carts', ['status'=>'converted','coupon_code'=>null,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$cartId]);
                return $orderRow;
            });
        } catch (AppException $e) { throw $e; }
        catch (\Throwable $e) {
            Logger::exception($e);
            throw new AppException('We could not complete your order. No charge was made and stock was not changed. Please try again.', 500);
        }

        if ($saveAddress) {
            try {
                $addresses = CustomerRepository::addresses((string)Auth::id());
                if ($addresses === []) { CustomerRepository::saveAddress((string)Auth::id(), $addressInput); }
            } catch (\Throwable $e) { Logger::log('warning', 'checkout.address_save_failed'); }
        }

        // CheckoutService mutates cart tables directly inside its transaction.
        CartService::invalidate();
        // Keep the submitted key tied to this exact order; issue a fresh key for the next checkout.
        self::rotateIdempotencyKey();
        Logger::audit('order.created', 'orders', $orderId, ['number'=>$orderNumber,'total_cents'=>$grand]);
        $mail = MailService::send($email, 'Order ' . $orderNumber . ' received',
            "Hi {$name},\n\nWe received order {$orderNumber} for " . Helpers::money($grand) . ".\nPayment method: {$method}.\n\nThank you for shopping with " . (string)Config::get('app.name') . '.');
        $paymentUrl = null;
        if ($method === 'stripe') {
            try { $paymentUrl = PaymentService::createStripeSession($order, $items); }
            catch (\Throwable $e) {
                self::cancelFailedPayment($orderId, $items);
                self::rotateIdempotencyKey();
                Logger::log('error', 'checkout.payment_start_failed', ['order'=>$orderNumber]);
                throw new AppException('The payment provider could not be reached and the order was cancelled. Please try again or choose Cash on Delivery.', 502);
            }
        }
        return ['order'=>$order, 'payment_url'=>$paymentUrl, 'duplicate'=>false, 'email_status'=>$mail];
    }

    private static function cancelFailedPayment(string $orderId, array $items): void
    {
        try {
            Database::tx(function () use ($orderId, $items): void {
                foreach ($items as $it) {
                    Database::exec('UPDATE products SET stock = stock + :q, sold_count = CASE WHEN sold_count > :q2 THEN sold_count - :q3 ELSE 0 END WHERE id=:i',
                        ['q'=>$it['quantity'],'q2'=>$it['quantity'],'q3'=>$it['quantity'],'i'=>$it['product_id']]);
                    $stockVariantId = Helpers::orderStockVariantId($it);
                    if ($stockVariantId !== '') {
                        Database::exec('UPDATE product_variants SET stock = stock + :q WHERE id=:i', ['q'=>$it['quantity'],'i'=>$stockVariantId]);
                    }
                    Database::exec('UPDATE inventory SET quantity = quantity + :q, available = available + :q WHERE product_id=:p AND variant_id=:v',
                        ['q'=>$it['quantity'],'p'=>$it['product_id'],'v'=>$stockVariantId]);
                }
                Database::update('orders', ['status'=>'cancelled','payment_status'=>'failed','idempotency_key'=>null,
                    'cancelled_at'=>Helpers::now(),'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$orderId]);
                Database::update('payments', ['status'=>'failed','updated_at'=>Helpers::now()], 'order_id=:o', ['o'=>$orderId]);
            });
        } catch (\Throwable $e) { Logger::exception($e); }
    }

    private static function uniqueOrderNumber(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $n = Helpers::orderNumber();
            if (!(int)Database::value('SELECT COUNT(*) FROM orders WHERE order_number=:n', ['n'=>$n])) { return $n; }
        }
        return 'AS-' . gmdate('YmdHis') . '-' . strtoupper(substr(Helpers::token(8), 0, 12));
    }
}
