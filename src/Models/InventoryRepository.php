<?php
declare(strict_types=1);
namespace AllStore\Models;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\AppException;
use AllStore\Core\Logger;
use AllStore\Services\NotificationService;

final class InventoryRepository
{
    public static function rows(array $f = []): array
    {
        $w = ['p.deleted_at IS NULL']; $p = [];
        if (!empty($f['q'])) { $w[] = '(LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q)'; $p['q'] = '%'.mb_strtolower(mb_substr((string)$f['q'],0,80)).'%'; }
        if (($f['stock'] ?? '') === 'low') { $w[] = 'p.stock > 0 AND p.stock <= p.low_stock_threshold'; }
        if (($f['stock'] ?? '') === 'out') { $w[] = 'p.stock <= 0'; }
        $where = implode(' AND ',$w);
        $page = max(1,(int)($f['page'] ?? 1)); $per=25; $off=($page-1)*$per;
        $total = (int)Database::value("SELECT COUNT(*) FROM products p WHERE $where", $p);
        $items = Database::all("SELECT p.id,p.name,p.sku,p.stock,p.reserved_stock,p.damaged_stock,p.low_stock_threshold,p.status,
            COALESCE((SELECT SUM(i.reserved) FROM inventory i WHERE i.product_id=p.id),0) AS reserved,
            COALESCE((SELECT SUM(i.damaged) FROM inventory i WHERE i.product_id=p.id),0) AS damaged
            FROM products p WHERE $where ORDER BY p.stock ASC, p.name ASC LIMIT $per OFFSET $off", $p);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$per))];
    }
    public static function movements(string $productId = '', int $limit = 50): array
    {
        $limit = max(1, min(300, $limit));
        $w = $productId !== '' ? 'WHERE m.product_id=:p' : '';
        $p = $productId !== '' ? ['p'=>$productId] : [];
        return Database::all("SELECT m.*, pr.name AS product_name FROM stock_movements m LEFT JOIN products pr ON pr.id=m.product_id
            $w ORDER BY m.created_at DESC LIMIT $limit", $p);
    }
    /** Atomic, auditable stock change. Never allows negative inventory. */
    public static function adjust(string $productId, string $variantId, int $delta, string $type, string $note, ?string $actorId): int
    {
        if ($delta === 0) { throw new AppException('Enter a non-zero quantity.', 422); }
        if (!in_array($type, ['in','out','adjustment','damage'], true)) { throw new AppException('Invalid movement type.', 422); }
        return Database::tx(function () use ($productId, $variantId, $delta, $type, $note, $actorId): int {
            $affected = Database::exec('UPDATE products SET stock = stock + :d, updated_at=:t WHERE id=:i AND stock + :d2 >= 0',
                ['d'=>$delta,'d2'=>$delta,'t'=>Helpers::now(),'i'=>$productId]);
            if ($affected !== 1) { throw new AppException('Stock cannot become negative.', 409); }
            if ($variantId !== '') {
                $v = Database::exec('UPDATE product_variants SET stock = stock + :d WHERE id=:i AND stock + :d2 >= 0',
                    ['d'=>$delta,'d2'=>$delta,'i'=>$variantId]);
                if ($v !== 1) { throw new AppException('Variant stock cannot become negative.', 409); }
            }
            Database::exec('UPDATE inventory SET quantity = quantity + :d, available = available + :d, updated_at=:t
                WHERE product_id=:p AND variant_id=:v AND quantity + :d2 >= 0',
                ['d'=>$delta,'d2'=>$delta,'t'=>Helpers::now(),'p'=>$productId,'v'=>$variantId]);
            $after = (int)Database::value('SELECT stock FROM products WHERE id=:i', ['i'=>$productId]);
            Database::insert('stock_movements', ['id'=>Helpers::uuid(),'product_id'=>$productId,'variant_id'=>$variantId,
                'warehouse_id'=>Database::value('SELECT id FROM warehouses WHERE is_default=1'),
                'movement_type'=>$type,'quantity_change'=>$delta,'quantity_after'=>$after,
                'reference_type'=>'manual','note'=>mb_substr($note,0,500),'created_by'=>$actorId ?? Auth::id(),
                'created_at'=>Helpers::now()]);
            $p = Database::first('SELECT name, low_stock_threshold FROM products WHERE id=:i', ['i'=>$productId]);
            if ($p && $after <= (int)$p['low_stock_threshold']) { NotificationService::lowStock((string)$p['name'], $productId); }
            Logger::audit('inventory.adjusted', 'products', $productId, ['delta'=>$delta,'type'=>$type]);
            return $after;
        });
    }
    public static function warehouses(): array { return Database::all('SELECT * FROM warehouses ORDER BY is_default DESC, name'); }
    public static function saveWarehouse(array $in, ?string $id = null): string
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') { throw new AppException('Warehouse name is required.', 422); }
        $data = ['name'=>$name,'code'=>strtoupper(Helpers::slugify((string)($in['code'] ?? $name))),
            'address'=>(string)($in['address'] ?? ''),'is_active'=>(int)($in['is_active'] ?? 1)];
        if ($id) { Database::update('warehouses', $data, 'id=:i', ['i'=>$id]); return $id; }
        $data['id'] = $id = Helpers::uuid(); $data['created_at'] = Helpers::now();
        Database::insert('warehouses', $data);
        return $id;
    }
}
