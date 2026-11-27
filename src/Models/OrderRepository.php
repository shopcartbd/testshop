<?php
declare(strict_types=1);
namespace AllStore\Models;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\AppException;
use AllStore\Core\Logger;
use AllStore\Services\NotificationService;
use AllStore\Services\UploadService;

final class OrderRepository
{
    public const STATUSES = ['pending','confirmed','processing','packed','shipped','delivered','cancelled','return_requested','returned','refunded'];
    /** Values allowed by the orders payment-status CHECK constraint; the legacy COD pending label is not a DB value. */
    public const PAYMENT_STATUSES = ['pending','authorized','paid','failed','refunded','partially_refunded'];

    public static function forUser(string $userId, array $f = []): array
    {
        $w = ['user_id=:u']; $p = ['u'=>$userId];
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) { $w[] = 'status=:s'; $p['s'] = $f['status']; }
        if (!empty($f['q'])) { $w[] = '(LOWER(order_number) LIKE :q OR LOWER(customer_name) LIKE :q)'; $p['q'] = '%' . mb_strtolower(mb_substr((string)$f['q'],0,60)) . '%'; }
        $page = max(1, (int)($f['page'] ?? 1)); $per = 12; $off = ($page-1)*$per;
        $where = implode(' AND ', $w);
        $total = (int)Database::value("SELECT COUNT(*) FROM orders WHERE $where", $p);
        $items = Database::all("SELECT * FROM orders WHERE $where ORDER BY placed_at DESC LIMIT $per OFFSET $off", $p);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$per))];
    }
    public static function findByNumber(string $number): ?array
    {
        return Database::first('SELECT * FROM orders WHERE order_number=:n', ['n'=>$number]);
    }
    /** Ownership is enforced in SQL - an IDOR attempt simply returns null. */
    public static function findByNumberForUser(string $number, string $userId): ?array
    {
        return Database::first('SELECT * FROM orders WHERE order_number=:n AND user_id=:u', ['n'=>$number,'u'=>$userId]);
    }
    public static function items(string $orderId): array
    {
        $rows = Database::all("SELECT oi.*, p.slug AS product_slug,
            COALESCE(NULLIF(oi.image_url,''),
                (SELECT pi.url FROM product_images pi WHERE pi.product_id=oi.product_id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1),
                '/assets/img/products/headphones.jpg') AS resolved_image_url
            FROM order_items oi
            LEFT JOIN products p ON p.id=oi.product_id AND p.deleted_at IS NULL
            WHERE oi.order_id=:o ORDER BY oi.id", ['o'=>$orderId]);
        // A JSON-encoded URL can survive older vendor/Database adapters; normalise
        // it here so <img src> never becomes a malformed, quoted relative URL.
        foreach ($rows as &$row) {
            $value = trim((string)($row['resolved_image_url'] ?? ''));
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $decoded = Helpers::decode($value, '');
                if (is_string($decoded)) { $value = trim($decoded); }
            }
            $row['resolved_image_url'] = Helpers::safeMediaUrl($value);
        }
        unset($row);
        return $rows;
    }

    public static function itemDetails(array $item): array
    {
        $raw = $item['options'] ?? null;
        if (is_string($raw)) { $raw = Helpers::decode($raw, []); }
        $details = Helpers::orderItemDetails(is_array($raw) ? $raw : []);
        $image = Helpers::safeMediaUrl((string)($item['resolved_image_url'] ?? $item['image_url'] ?? ''));
        if ($image !== '' && str_starts_with($image, 'supabase-private:')) { $image = UploadService::url($image); }
        $details['image_url'] = $image !== '' ? $image : '/assets/img/products/headphones.jpg';
        if (!empty($details['download_url']) && str_starts_with((string)$details['download_url'], 'supabase-private:')) {
            $details['download_url'] = UploadService::url((string)$details['download_url']);
        }
        $details['product_slug'] = (string)($item['product_slug'] ?? '');
        return $details;
    }

    public static function itemsCsv(array $items): string
    {
        $h = fopen('php://temp','w+');
        fputcsv($h, ['order_item_id','product','sku','quantity','unit_price','line_total','options','bundle_items','product_kind','image_url','digital_file_url']);
        foreach ($items as $item) {
            $d = self::itemDetails($item);
            $options = [];
            foreach ($d['options'] as $o) { $options[] = $o['label'] . ': ' . $o['value']; }
            $bundle = [];
            foreach ($d['bundle_items'] as $b) { $bundle[] = trim($b['sku'] . ' ' . $b['name'] . ' x' . $b['qty']); }
            fputcsv($h, [
                $item['id'] ?? '', $item['name'] ?? '', $item['sku'] ?? '', (int)($item['quantity'] ?? 0),
                number_format(((int)($item['unit_price_cents'] ?? 0))/100,2,'.',''),
                number_format(((int)($item['total_cents'] ?? 0))/100,2,'.',''),
                implode(' | ', $options), implode(' | ', $bundle), (string)($d['product_kind'] ?? ''),
                $d['image_url'], $d['download_url'],
            ]);
        }
        rewind($h); $csv = (string)stream_get_contents($h); fclose($h);
        return $csv;
    }
    public static function history(string $orderId): array { return Database::all('SELECT * FROM order_status_history WHERE order_id=:o ORDER BY created_at ASC', ['o'=>$orderId]); }
    public static function shipment(string $orderId): ?array { return Database::first('SELECT s.*, c.name AS courier_name, c.tracking_url_template FROM shipments s LEFT JOIN couriers c ON c.id=s.courier_id WHERE s.order_id=:o', ['o'=>$orderId]); }
    public static function payment(string $orderId): ?array { return Database::first('SELECT * FROM payments WHERE order_id=:o ORDER BY created_at DESC LIMIT 1', ['o'=>$orderId]); }
    public static function invoice(string $orderId): ?array { return Database::first('SELECT * FROM invoices WHERE order_id=:o', ['o'=>$orderId]); }
    public static function refunds(string $orderId): array { return Database::all('SELECT * FROM refunds WHERE order_id=:o ORDER BY created_at DESC', ['o'=>$orderId]); }
    public static function returnsForOrder(string $orderId): array { return Database::all('SELECT * FROM return_requests WHERE order_id=:o ORDER BY created_at DESC', ['o'=>$orderId]); }

    public static function allForAdmin(array $f = []): array
    {
        $w = ['1=1']; $p = [];
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) { $w[] = 'o.status=:s'; $p['s'] = $f['status']; }
        if (!empty($f['payment_method'])) { $w[] = 'o.payment_method=:pm'; $p['pm'] = mb_substr((string)$f['payment_method'],0,30); }
        if (!empty($f['from'])) { $w[] = 'o.placed_at >= :from'; $p['from'] = (string)$f['from']; }
        if (!empty($f['to'])) { $w[] = 'o.placed_at <= :to'; $p['to'] = (string)$f['to']; }
        if (!empty($f['q'])) {
            $w[] = '(LOWER(o.order_number) LIKE :q OR LOWER(o.customer_email) LIKE :q OR LOWER(o.customer_name) LIKE :q)';
            $p['q'] = '%' . mb_strtolower(mb_substr((string)$f['q'],0,80)) . '%';
        }
        $where = implode(' AND ', $w);
        $page = max(1, (int)($f['page'] ?? 1)); $per = 20; $off = ($page-1)*$per;
        $total = (int)Database::value("SELECT COUNT(*) FROM orders o WHERE $where", $p);
        $items = Database::all("SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) AS item_count,
            (SELECT oi2.name FROM order_items oi2 WHERE oi2.order_id=o.id ORDER BY oi2.id ASC LIMIT 1) AS first_item_name,
            (SELECT COALESCE(NULLIF(oi2.image_url,''),
                (SELECT pi.url FROM product_images pi WHERE pi.product_id=oi2.product_id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1))
                FROM order_items oi2 WHERE oi2.order_id=o.id ORDER BY oi2.id ASC LIMIT 1) AS first_item_image
            FROM orders o WHERE $where ORDER BY o.placed_at DESC LIMIT $per OFFSET $off", $p);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$per))];
    }

    /** Order workflow is independent of payment_status: COD orders are confirmed before cash is collected. */
    public const TRANSITIONS = [
        'pending'=>['confirmed','processing','cancelled'],
        'confirmed'=>['processing','packed','cancelled'],
        'processing'=>['packed','shipped','cancelled'],
        'packed'=>['shipped','cancelled'],
        'shipped'=>['delivered','return_requested'],
        'delivered'=>['return_requested','returned','refunded'],
        'return_requested'=>['returned','refunded','delivered'],
        'returned'=>['refunded'],
        'refunded'=>[], 'cancelled'=>[],
    ];

    /** Status choices shown to admins: current status plus its valid next steps. */
    public static function allowedTransitions(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    public static function statusChoices(string $currentStatus): array
    {
        return array_values(array_unique(array_merge([$currentStatus], self::allowedTransitions($currentStatus))));
    }

    public static function isTransitionAllowed(string $currentStatus, string $newStatus): bool
    {
        if ($currentStatus === $newStatus) { return true; }
        return in_array($newStatus, self::allowedTransitions($currentStatus), true);
    }

    public static function updateStatus(string $orderId, string $newStatus, string $note = '', ?string $actorId = null): void
    {
        if (!in_array($newStatus, self::STATUSES, true)) { throw new AppException('Invalid order status.', 422); }
        $o = Database::first('SELECT * FROM orders WHERE id=:i', ['i'=>$orderId]);
        if (!$o) { throw new AppException('Order not found.', 404); }
        // COD confirmation must work while payment remains pending; never gate this on paid.
        if (!self::isTransitionAllowed((string)$o['status'], $newStatus)) {
            throw new AppException('That status change is not allowed.', 422);
        }
        Database::tx(function () use ($o, $newStatus, $note, $actorId): void {
            Database::update('orders', ['status'=>$newStatus,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$o['id']]);
            Database::insert('order_status_history', ['id'=>Helpers::uuid(),'order_id'=>$o['id'],'from_status'=>$o['status'],
                'to_status'=>$newStatus,'note'=>mb_substr($note,0,500),'changed_by'=>$actorId,'created_at'=>Helpers::now()]);
            if ($newStatus === 'shipped') {
                Database::update('shipments', ['status'=>'in_transit','shipped_at'=>Helpers::now(),'updated_at'=>Helpers::now()], 'order_id=:o', ['o'=>$o['id']]);
            } elseif ($newStatus === 'delivered') {
                Database::update('orders', ['delivered_at'=>Helpers::now()], 'id=:i', ['i'=>$o['id']]);
                Database::update('shipments', ['status'=>'delivered','delivered_at'=>Helpers::now(),'updated_at'=>Helpers::now()], 'order_id=:o', ['o'=>$o['id']]);
            } elseif ($newStatus === 'cancelled') {
                self::restoreStock((string)$o['id']);
            }
            if ($newStatus === 'cancelled') { Database::update('orders', ['cancelled_at'=>Helpers::now()], 'id=:i', ['i'=>$o['id']]); }
        });
        NotificationService::orderStatus($o, $newStatus);
        Logger::audit('order.status_changed', 'orders', (string)$o['id'], ['from'=>$o['status'],'to'=>$newStatus]);
    }

    private static function restoreStock(string $orderId): void
    {
        foreach (self::items($orderId) as $it) {
            $q = (int)$it['quantity'];
            Database::exec('UPDATE products SET stock = stock + :q, sold_count = CASE WHEN sold_count > :q2 THEN sold_count - :q3 ELSE 0 END WHERE id=:i',
                ['q'=>$q,'q2'=>$q,'q3'=>$q,'i'=>$it['product_id']]);
            $stockVariantId = Helpers::orderStockVariantId($it);
            if ($stockVariantId !== '') {
                Database::exec('UPDATE product_variants SET stock = stock + :q WHERE id=:i', ['q'=>$q,'i'=>$stockVariantId]);
            }
            Database::exec('UPDATE inventory SET quantity = quantity + :q, available = available + :q WHERE product_id=:p AND variant_id=:v',
                ['q'=>$q,'p'=>$it['product_id'],'v'=>$stockVariantId]);
            Database::insert('stock_movements', ['id'=>Helpers::uuid(),'product_id'=>$it['product_id'],'variant_id'=>$stockVariantId,
                'movement_type'=>'cancel','quantity_change'=>$q,'quantity_after'=>(int)Database::value('SELECT stock FROM products WHERE id=:i',['i'=>$it['product_id']]),
                'reference_type'=>'order','reference_id'=>$orderId,'note'=>'Order cancelled','created_by'=>Auth::id(),'created_at'=>Helpers::now()]);
        }
    }

    public static function cancelByCustomer(string $orderNumber, string $userId): void
    {
        $o = self::findByNumberForUser($orderNumber, $userId);
        if (!$o) { throw new AppException('Order not found.', 404); }
        if (!in_array($o['status'], ['pending','confirmed','processing'], true)) {
            throw new AppException('This order can no longer be cancelled.', 422);
        }
        self::updateStatus((string)$o['id'], 'cancelled', 'Cancelled by customer', $userId);
    }

    public static function createReturn(string $userId, array $in): string
    {
        $number = (string)($in['order_number'] ?? '');
        $itemId = (string)($in['order_item_id'] ?? '');
        $o = self::findByNumberForUser($number, $userId);
        if (!$o) { throw new AppException('Order not found.', 404); }
        $item = Database::first('SELECT * FROM order_items WHERE id=:i AND order_id=:o', ['i'=>$itemId,'o'=>$o['id']]);
        if (!$item) { throw new AppException('Order item not found.', 404); }
        if (!in_array($o['status'], ['delivered','shipped'], true)) { throw new AppException('Only delivered orders can be returned.', 422); }
        if (Database::first("SELECT id FROM return_requests WHERE order_item_id=:i AND status<>'rejected'", ['i'=>$itemId])) {
            throw new AppException('A return request already exists for this item.', 409);
        }
        $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 120);
        if ($reason === '') { throw new AppException('Please choose a return reason.', 422); }
        $id = Helpers::uuid();
        Database::insert('return_requests', ['id'=>$id,'order_id'=>$o['id'],'order_item_id'=>$itemId,'user_id'=>$userId,
            'reason'=>$reason,'detail'=>mb_substr((string)($in['detail'] ?? ''),0,1000),
            'request_type'=>in_array($in['request_type'] ?? '', ['return','exchange'], true) ? $in['request_type'] : 'return',
            'status'=>'pending','created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
        NotificationService::create(null, 'return_request', 'New return request', 'Order ' . $o['order_number'], true);
        Logger::audit('return.requested', 'return_requests', $id);
        return $id;
    }

    public static function exportCsv(array $f = []): string
    {
        $rows = self::allForAdmin(array_merge($f, ['page'=>1]))['items'];
        $h = fopen('php://temp','w+');
        fputcsv($h, ['order_number','date','customer','email','status','payment','division','district','area','city','region','postal_code','country','subtotal','discount','shipping','tax','total']);
        foreach ($rows as $r) {
            $a = (array)Helpers::decode((string)($r['shipping_address'] ?? ''), []);
            fputcsv($h, [$r['order_number'],$r['placed_at'],$r['customer_name'],$r['customer_email'],$r['status'],$r['payment_method'],
                $a['division'] ?? '',$a['district'] ?? '',$a['area'] ?? '',$a['city'] ?? '',$a['region'] ?? '',
                $a['postal_code'] ?? '',$a['country'] ?? '',
                number_format($r['subtotal_cents']/100,2,'.',''),number_format($r['discount_cents']/100,2,'.',''),
                number_format($r['shipping_cents']/100,2,'.',''),number_format($r['tax_cents']/100,2,'.',''),
                number_format($r['total_cents']/100,2,'.','')]);
        }
        rewind($h); $csv = (string)stream_get_contents($h); fclose($h);
        return $csv;
    }
}
