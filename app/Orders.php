<?php

declare(strict_types=1);:

final class Orders
{
    private const MAX_ITEMS = 30;
    private const MAX_QTY = 20;

    public static function shippingMethods(): array
    {
        return Database::connection()->query("SELECT sm.id,sm.name,sm.method_code,sm.base_fee,sm.free_shipping_threshold,sm.per_kg_fee,sm.estimated_days_min,sm.estimated_days_max FROM app.shipping_methods sm LEFT JOIN app.shipping_zones z ON z.id=sm.zone_id WHERE sm.is_active AND (sm.zone_id IS NULL OR (z.is_active AND z.country_codes @> ARRAY['BD']::text[])) ORDER BY sm.base_fee,sm.name")->fetchAll();
    }

    public static function place(array $cart, ?array $account, array $input, string $idempotencyKey): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) throw new InvalidArgumentException('Checkout session expired. আবার checkout করুন।');
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($account['display_name'] ?? $input['name'] ?? '')) ?? '');
        $email = strtolower(trim((string) ($account['email'] ?? $input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? $account['phone'] ?? ''));
        $address = [
            'recipient_name' => $name,
            'phone' => $phone,
            'line1' => trim((string) ($input['line1'] ?? '')),
            'line2' => trim((string) ($input['line2'] ?? '')),
            'locality' => trim((string) ($input['locality'] ?? '')),
            'region' => trim((string) ($input['region'] ?? '')),
            'postal_code' => trim((string) ($input['postal_code'] ?? '')),
            'country_code' => strtoupper(trim((string) ($input['country_code'] ?? 'BD'))),
        ];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new InvalidArgumentException('প্রাপকের নাম দিন।');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) throw new InvalidArgumentException('সঠিক ইমেইল ঠিকানা দিন।');
        if (mb_strlen($phone) < 5 || mb_strlen($phone) > 32) throw new InvalidArgumentException('সঠিক ফোন নম্বর দিন।');
        if (mb_strlen($address['line1']) < 5 || mb_strlen($address['line1']) > 240) throw new InvalidArgumentException('সম্পূর্ণ ঠিকানা দিন।');
        if (mb_strlen($address['locality']) < 2 || mb_strlen($address['locality']) > 120) throw new InvalidArgumentException('এলাকা/শহরের নাম দিন।');
        if (!preg_match('/^[A-Z]{2}$/', $address['country_code'])) throw new InvalidArgumentException('দেশের কোড সঠিক নয়।');
        if (mb_strlen((string) ($input['customer_note'] ?? '')) > 1000) throw new InvalidArgumentException('নোট ১,০০০ অক্ষরের মধ্যে লিখুন।');
        if (count($cart) > self::MAX_ITEMS) throw new DomainException('কার্টে পণ্যের সংখ্যা সীমার বেশি।');
        $methodId = trim((string) ($input['shipping_method_id'] ?? ''));
        if (!preg_match('/^[a-f0-9-]{36}$/i', $methodId)) throw new InvalidArgumentException('একটি ডেলিভারি পদ্ধতি বেছে নিন।');
        $couponCode = strtoupper(trim((string) ($input['coupon_code'] ?? '')));
        if (strlen($couponCode) > 64 || ($couponCode !== '' && !preg_match('/^[A-Z0-9_-]{3,64}$/', $couponCode))) throw new InvalidArgumentException('কুপন কোড সঠিক নয়।');

        $quantities = [];
        foreach ($cart as $id => $quantity) {
            $id = (string) $id;
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            if (!preg_match('/^[a-f0-9-]{36}$/i', $id) || !$quantity || $quantity < 1 || $quantity > self::MAX_QTY) throw new DomainException('কার্টে কোনো পণ্যের পরিমাণ সঠিক নয়।');
            $quantities[$id] = $quantity;
        }
        ksort($quantities, SORT_STRING);

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $retryLock = $db->prepare('SELECT pg_advisory_xact_lock(hashtext(:key))');
            $retryLock->execute(['key' => $idempotencyKey]);
            $existing = $db->prepare('SELECT id,order_number,status,grand_total,currency,customer_account_id,customer_email,customer_name FROM app.orders WHERE idempotency_key=:key LIMIT 1');
            $existing->execute(['key' => $idempotencyKey]);
            $existingOrder = $existing->fetch();
            if ($existingOrder) {
                if ($account && $existingOrder['customer_account_id'] !== $account['id']) throw new DomainException('এই checkout সেশনটি অন্য account-এর।');
                if (!$account && strtolower((string) $existingOrder['customer_email']) !== $email) throw new DomainException('এই checkout সেশনটি অন্য ক্রেতার।');
                $db->commit();
                $existingOrder['idempotent_replay'] = true;
                return $existingOrder;
            }
            if ($cart === []) throw new DomainException('আপনার কার্টে কোনো পণ্য নেই।');

            $productStmt = $db->prepare("SELECT id,name,sku,base_price,currency,stock_quantity,weight_grams,tax_rate,return_eligible,category_id,product_type FROM app.products WHERE id=:id AND status='active' AND deleted_at IS NULL FOR UPDATE");
            $products = [];
            $subtotal = 0.0;
            $tax = 0.0;
            $eligible = 0.0;
            $weight = 0;
            foreach ($quantities as $id => $quantity) {
                $productStmt->execute(['id' => $id]);
                $product = $productStmt->fetch();
                if (!$product) throw new DomainException('কার্টে থাকা একটি পণ্য আর উপলভ্য নেই।');
                if ($product['product_type'] !== 'simple') throw new DomainException('কার্টে থাকা একটি পণ্যের variant/bundle checkout এখনো supported নয়।');
                $available = (int) $product['stock_quantity'];
                if ($quantity > $available) throw new DomainException('“' . $product['name'] . '” পণ্যের পর্যাপ্ত মজুত নেই।');
                if (strtoupper((string) $product['currency']) !== 'BDT') throw new DomainException('এই মুহূর্তে শুধু BDT মুদ্রার পণ্য checkout করা যায়।');
                $unit = (float) $product['base_price'];
                $line = round($unit * $quantity, 2);
                $subtotal += $line;
                $weight += (int) ($product['weight_grams'] ?? 0) * $quantity;
                $product['quantity'] = $quantity;
                $product['unit_price'] = $unit;
                $product['line_total'] = $line;
                $products[] = $product;
            }
            if ($subtotal <= 0 || $subtotal > 9999999999.99) throw new DomainException('অর্ডারের মোট পরিমাণ গ্রহণযোগ্য নয়।');
            $eligible = $subtotal;

            $methodStmt = $db->prepare("SELECT sm.* FROM app.shipping_methods sm LEFT JOIN app.shipping_zones z ON z.id=sm.zone_id WHERE sm.id=:id AND sm.is_active AND (sm.zone_id IS NULL OR (z.is_active AND z.country_codes @> ARRAY[:country]::text[] AND (cardinality(z.regions)=0 OR z.regions @> ARRAY[:region]::text[]))) FOR SHARE OF sm");
            $methodStmt->execute(['id' => $methodId, 'country' => $address['country_code'], 'region' => $address['region']]);
            $method = $methodStmt->fetch();
            if (!$method) throw new DomainException('এই ডেলিভারি পদ্ধতি আর উপলভ্য নয়।');
            $shipping = (float) $method['base_fee'] + ceil($weight / 1000) * (float) $method['per_kg_fee'];
            if ($method['free_shipping_threshold'] !== null && $subtotal >= (float) $method['free_shipping_threshold']) $shipping = 0.0;

            $discount = 0.0;
            $coupon = null;
            $productFilter = [];
            $categoryFilter = [];
            if ($couponCode !== '') {
                $couponStmt = $db->prepare('SELECT * FROM app.coupons WHERE code=:code AND is_active FOR UPDATE');
                $couponStmt->execute(['code' => $couponCode]);
                $coupon = $couponStmt->fetch();
                if (!$coupon || ($coupon['starts_at'] && strtotime((string) $coupon['starts_at']) > time()) || ($coupon['ends_at'] && strtotime((string) $coupon['ends_at']) <= time())) throw new DomainException('কুপনটি অবৈধ বা মেয়াদোত্তীর্ণ।');
                if ($subtotal < (float) $coupon['minimum_order_amount']) throw new DomainException('এই কুপনের জন্য ন্যূনতম অর্ডার মূল্য পূরণ হয়নি।');
                if ($coupon['currency'] !== null && strtoupper((string) $coupon['currency']) !== 'BDT') throw new DomainException('এই কুপন BDT অর্ডারে ব্যবহার করা যাবে না।');
                if (!empty($coupon['first_order_only'])) {
                    $first = $db->prepare($account
                        ? "SELECT count(*) FROM app.orders WHERE customer_account_id=:buyer AND status<>'cancelled'"
                        : "SELECT count(*) FROM app.orders WHERE lower(customer_email)=:buyer AND status<>'cancelled'");
                    $first->execute(['buyer' => $account['id'] ?? $email]);
                    if ((int) $first->fetchColumn() > 0) throw new DomainException('এই coupon শুধু প্রথম অর্ডারে ব্যবহার করা যায়।');
                }
                if ($coupon['max_redemptions'] !== null) {
                    $countStmt = $db->prepare('SELECT count(*) FROM app.coupon_usage WHERE coupon_id=:coupon_id');
                    $countStmt->execute(['coupon_id' => $coupon['id']]);
                    if ((int) $countStmt->fetchColumn() >= (int) $coupon['max_redemptions']) throw new DomainException('কুপনের ব্যবহারসীমা শেষ হয়েছে।');
                }
                if ($coupon['max_redemptions_per_user'] !== null) {
                    $sql = $account
                        ? 'SELECT count(*) FROM app.coupon_usage WHERE coupon_id=:coupon_id AND customer_account_id=:account_id'
                        : 'SELECT count(*) FROM app.coupon_usage WHERE coupon_id=:coupon_id AND lower(guest_email)=:email';
                    $userStmt = $db->prepare($sql);
                    $userParams = ['coupon_id' => $coupon['id']];
                    $userParams[$account ? 'account_id' : 'email'] = $account ? $account['id'] : $email;
                    $userStmt->execute($userParams);
                    if ((int) $userStmt->fetchColumn() >= (int) $coupon['max_redemptions_per_user']) throw new DomainException('এই account-এ কুপন ব্যবহারের সীমা পূর্ণ হয়েছে।');
                }
                $productFilter = json_decode((string) $coupon['applies_to_products'], true) ?: [];
                $categoryFilter = json_decode((string) $coupon['applies_to_categories'], true) ?: [];
                $eligible = 0.0;
                foreach ($products as $p) {
                    $allowedProduct = $productFilter === [] || in_array((string) $p['id'], $productFilter, true);
                    $allowedCategory = $categoryFilter === [] || in_array((string) $p['category_id'], $categoryFilter, true);
                    if ($allowedProduct && $allowedCategory) $eligible += $p['line_total'];
                }
                if ($eligible <= 0) throw new DomainException('এই কুপনটি কার্টের পণ্যে প্রযোজ্য নয়।');
                if ($coupon['discount_type'] === 'percent') $discount = $eligible * (float) $coupon['discount_value'] / 100;
                elseif ($coupon['discount_type'] === 'fixed') $discount = (float) $coupon['discount_value'];
                elseif ($coupon['discount_type'] === 'free_shipping') $shipping = 0.0;
                if ($coupon['maximum_discount'] !== null) $discount = min($discount, (float) $coupon['maximum_discount']);
                $discount = min(round($discount, 2), $eligible);
            }
            // Allocate item discounts proportionally across eligible lines and tax only the resulting net line value.
            $tax = 0.0;
            $discountAllocated = 0.0;
            $eligibleIndexes = [];
            foreach ($products as $index => $p) {
                $allowedProduct = $productFilter === [] || in_array((string) $p['id'], $productFilter, true);
                $allowedCategory = $categoryFilter === [] || in_array((string) $p['category_id'], $categoryFilter, true);
                if ($coupon === null || ($allowedProduct && $allowedCategory)) $eligibleIndexes[] = $index;
            }
            foreach ($products as $index => $p) {
                $lineDiscount = 0.0;
                if (in_array($index, $eligibleIndexes, true) && $eligible > 0 && $discount > 0) {
                    $share = round($discount * (float) $p['line_total'] / $eligible, 2);
                    $lineDiscount = min((float) $p['line_total'], $share, max(0.0, round($discount - $discountAllocated, 2)));
                    $discountAllocated += $lineDiscount;
                }
                $tax += round(max(0.0, (float) $p['line_total'] - $lineDiscount) * (float) $p['tax_rate'] / 100, 2);
            }
            $total = round($subtotal - $discount + $shipping + $tax, 2);
            if ($total <= 0 || $total > 9999999999.99) throw new DomainException('অর্ডারের মোট পরিমাণ গ্রহণযোগ্য নয়।');

            $orderNumber = 'AS-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insertOrder = $db->prepare("INSERT INTO app.orders (order_number,profile_id,customer_account_id,customer_name,customer_email,customer_phone,shipping_address_snapshot,shipping_method_id,shipping_method_name,status,currency,subtotal,discount_total,shipping_total,tax_total,grand_total,customer_note,payment_method,payment_status,coupon_code,idempotency_key,weight_grams,placed_at) VALUES (:number,NULL,:account_id,:name,:email,:phone,:address,:method_id,:method_name,'pending','BDT',:subtotal,:discount,:shipping,:tax,:total,:note,'cod','pending',:coupon,:key,:weight,now()) RETURNING id,order_number,status,grand_total,currency");
            $insertOrder->execute([
                'number' => $orderNumber, 'account_id' => $account['id'] ?? null, 'name' => $name, 'email' => $email,
                'phone' => $phone, 'address' => json_encode($address, JSON_THROW_ON_ERROR), 'method_id' => $method['id'],
                'method_name' => $method['name'], 'subtotal' => number_format($subtotal,2,'.',''), 'discount' => number_format($discount,2,'.',''),
                'shipping' => number_format($shipping,2,'.',''), 'tax' => number_format($tax,2,'.',''), 'total' => number_format($total,2,'.',''),
                'note' => trim((string) ($input['customer_note'] ?? '')) ?: null, 'coupon' => $coupon['code'] ?? null, 'key' => $idempotencyKey, 'weight' => $weight,
            ]);
            $order = $insertOrder->fetch();
            $itemStmt = $db->prepare('INSERT INTO app.order_items(order_id,product_id,product_name,sku,quantity,unit_price,line_total) VALUES(:order_id,:product_id,:name,:sku,:quantity,:price,:line_total)');
            $stockStmt = $db->prepare('UPDATE app.products SET stock_quantity=stock_quantity-:quantity WHERE id=:id AND stock_quantity>=:quantity_check RETURNING stock_quantity');
            $movementStmt = $db->prepare("INSERT INTO app.stock_movements(product_id,movement_type,quantity_delta,quantity_after,reason,order_id,actor_account_id) VALUES(:product_id,'order',:delta,:after,:reason,:order_id,:actor)");
            foreach ($products as $p) {
                $itemStmt->execute(['order_id' => $order['id'], 'product_id' => $p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'quantity' => $p['quantity'], 'price' => number_format($p['unit_price'],2,'.',''), 'line_total' => number_format($p['line_total'],2,'.','')]);
                $stockStmt->execute(['quantity' => $p['quantity'], 'id' => $p['id'], 'quantity_check' => $p['quantity']]);
                $remaining = $stockStmt->fetchColumn();
                if ($remaining === false) throw new DomainException('মজুত পরিবর্তিত হয়েছে। আবার checkout করুন।');
                $movementStmt->execute(['product_id' => $p['id'], 'delta' => -$p['quantity'], 'after' => $remaining, 'reason' => 'Customer order ' . $orderNumber, 'order_id' => $order['id'], 'actor' => $account['id'] ?? null]);
            }
            $history = $db->prepare("INSERT INTO app.order_status_history(order_id,status,note,actor_account_id) VALUES(:id,'pending','অর্ডার গ্রহণ করা হয়েছে',:actor)");
            $history->execute(['id' => $order['id'], 'actor' => $account['id'] ?? null]);
            $payment = $db->prepare("INSERT INTO app.payments(order_id,provider,amount,currency,status) VALUES(:id,'cod',:amount,'BDT','pending')");
            $payment->execute(['id' => $order['id'], 'amount' => number_format($total,2,'.','')]);
            $shipment = $db->prepare("INSERT INTO app.shipments(order_id,status) VALUES(:id,'pending')");
            $shipment->execute(['id' => $order['id']]);
            if ($coupon) {
                $usage = $db->prepare('INSERT INTO app.coupon_usage(coupon_id,profile_id,customer_account_id,guest_email,order_id,discount_amount) VALUES(:coupon,NULL,:account,:email,:order_id,:discount)');
                $usage->execute(['coupon' => $coupon['id'], 'account' => $account['id'] ?? null, 'email' => $account ? null : $email, 'order_id' => $order['id'], 'discount' => number_format($discount,2,'.','')]);
            }
            $db->commit();
            $order['customer_email'] = $email;
            $order['customer_name'] = $name;
            $order['idempotent_replay'] = false;
            return $order;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public static function forCustomer(array $account): array
    {
        $stmt = Database::connection()->prepare('SELECT id,order_number,status,currency,grand_total,payment_status,placed_at FROM app.orders WHERE customer_account_id=:account ORDER BY placed_at DESC LIMIT 50');
        $stmt->execute(['account' => $account['id']]);
        return $stmt->fetchAll();
    }

    public static function customerOrder(string $id, array $account): ?array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) return null;
        $stmt = Database::connection()->prepare('SELECT id,order_number,status,currency,subtotal,discount_total,shipping_total,tax_total,grand_total,customer_note,customer_phone,shipping_address_snapshot,shipping_method_name,payment_method,payment_status,placed_at FROM app.orders WHERE id=:id AND customer_account_id=:account LIMIT 1');
        $stmt->execute(['id' => $id, 'account' => $account['id']]);
        $order = $stmt->fetch();
        if (!$order) return null;
        $items = Database::connection()->prepare('SELECT id,product_id,product_name,sku,quantity,unit_price,line_total FROM app.order_items WHERE order_id=:id ORDER BY created_at');
        $items->execute(['id' => $id]);
        $order['items'] = $items->fetchAll();
        $history = Database::connection()->prepare('SELECT status,note,created_at FROM app.order_status_history WHERE order_id=:id ORDER BY created_at');
        $history->execute(['id' => $id]);
        $order['history'] = $history->fetchAll();
        $shipment = Database::connection()->prepare('SELECT carrier,tracking_number,tracking_url,status,shipped_at,delivered_at FROM app.shipments WHERE order_id=:id LIMIT 1');
        $shipment->execute(['id' => $id]);
        $order['shipment'] = $shipment->fetch() ?: null;
        return $order;
    }

    public static function adminRecent(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return Database::connection()->query('SELECT id,order_number,customer_name,customer_email,status,payment_status,grand_total,currency,placed_at FROM app.orders ORDER BY placed_at DESC LIMIT ' . $limit)->fetchAll();
    }

    public static function changeStatus(string $orderId, string $status, string $note, array $actor): void
    {
        $valid = ['confirmed','processing','packed','shipped','delivered','cancelled'];
        if (!in_array($status, $valid, true)) throw new InvalidArgumentException('অর্ডারের অবস্থা সঠিক নয়।');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id,status FROM app.orders WHERE id=:id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
            if (!$order) throw new DomainException('অর্ডারটি পাওয়া যায়নি।');
            if ($order['status'] === $status) { $db->commit(); return; }
            $allowed = [
                'pending' => ['confirmed','cancelled'],
                'confirmed' => ['processing','cancelled'],
                'processing' => ['packed'],
                'packed' => ['shipped'],
                'shipped' => ['delivered'],
                'delivered' => [],
                'cancelled' => [],
                'returned' => [],
                'refunded' => [],
            ];
            if (!in_array($status, $allowed[$order['status']] ?? [], true)) throw new DomainException('অর্ডারের বর্তমান অবস্থা থেকে এই পরিবর্তনটি অনুমোদিত নয়।');
            if ($status === 'cancelled') {
                $items = $db->prepare('SELECT product_id,quantity FROM app.order_items WHERE order_id=:id AND product_id IS NOT NULL');
                $items->execute(['id' => $orderId]);
                $restock = $db->prepare('UPDATE app.products SET stock_quantity=stock_quantity+:quantity WHERE id=:id RETURNING stock_quantity');
                $move = $db->prepare("INSERT INTO app.stock_movements(product_id,movement_type,quantity_delta,quantity_after,reason,order_id,actor_account_id) VALUES(:product,'return',:qty,:after,:reason,:order,:actor)");
                foreach ($items->fetchAll() as $item) {
                    $restock->execute(['quantity' => $item['quantity'], 'id' => $item['product_id']]);
                    $after = $restock->fetchColumn();
                    if ($after !== false) $move->execute(['product' => $item['product_id'], 'qty' => $item['quantity'], 'after' => $after, 'reason' => 'Restock after cancellation', 'order' => $orderId, 'actor' => $actor['id']]);
                }
            }
            $update = $db->prepare('UPDATE app.orders SET status=:status WHERE id=:id');
            $update->execute(['status' => $status, 'id' => $orderId]);
            $history = $db->prepare('INSERT INTO app.order_status_history(order_id,status,note,actor_account_id) VALUES(:id,:status,:note,:actor)');
            $history->execute(['id' => $orderId, 'status' => $status, 'note' => trim($note) ?: null, 'actor' => $actor['id']]);
            $audit = $db->prepare('INSERT INTO app.audit_logs(actor_profile_id,actor_account_id,action,entity_type,entity_id,details) VALUES(NULL,:actor,\'order.status\',\'order\',:id,:details)');
            $audit->execute(['actor' => $actor['id'], 'id' => $orderId, 'details' => json_encode(['from' => $order['status'], 'to' => $status], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public static function updateShipment(string $orderId, string $carrier, string $tracking, string $url, array $actor): void
    {
        $carrier = trim($carrier);
        $tracking = trim($tracking);
        if (mb_strlen($carrier) > 120 || mb_strlen($tracking) > 120) throw new InvalidArgumentException('কুরিয়ার তথ্য অনেক বড়।');
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https')) throw new InvalidArgumentException('ট্র্যাকিং লিংকটি HTTPS হতে হবে।');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $orderStmt = $db->prepare('SELECT status FROM app.orders WHERE id=:id FOR UPDATE');
            $orderStmt->execute(['id' => $orderId]);
            $orderStatus = $orderStmt->fetchColumn();
            if ($orderStatus === false) throw new DomainException('অর্ডারটি পাওয়া যায়নি।');
            if ($tracking !== '' && !in_array($orderStatus, ['confirmed','processing','packed','shipped'], true)) throw new DomainException('এই অর্ডারের বর্তমান অবস্থায় tracking যোগ করা যাবে না।');
            $stmt = $db->prepare("UPDATE app.shipments SET carrier=:carrier,tracking_number=:tracking,tracking_url=:url,status=CASE WHEN :has_tracking THEN 'shipped' ELSE status END,shipped_at=CASE WHEN :has_shipped THEN COALESCE(shipped_at,now()) ELSE shipped_at END WHERE order_id=:order_id");
            $stmt->execute(['carrier' => $carrier ?: null, 'tracking' => $tracking ?: null, 'url' => $url ?: null, 'has_tracking' => $tracking !== '' ? 'true' : 'false', 'has_shipped' => $tracking !== '' ? 'true' : 'false', 'order_id' => $orderId]);
            if ($stmt->rowCount() < 1) throw new DomainException('অর্ডার/শিপমেন্ট পাওয়া যায়নি।');
            if ($tracking !== '' && $orderStatus !== 'shipped') {
                $db->prepare("UPDATE app.orders SET status='shipped' WHERE id=:id")->execute(['id' => $orderId]);
                $history = $db->prepare("INSERT INTO app.order_status_history(order_id,status,note,actor_account_id) VALUES(:id,'shipped','Courier information added',:actor)");
                $history->execute(['id' => $orderId, 'actor' => $actor['id']]);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
