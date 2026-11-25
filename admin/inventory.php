<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Core\Database;
use AllStore\Models\InventoryRepository;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('inventory.manage');
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $type = Request::str('movement_type','adjustment',20);
        if (!in_array($type,['in','out','adjustment','damage'],true)) { throw new AppException('Choose a valid movement type.',422); }
        $pid = Request::id('product_id');
        if ($pid === '') { throw new AppException('Choose a product.',422); }
        $after = InventoryRepository::adjust($pid, Request::id('variant_id'), Request::int('delta',0,-1000000,1000000), $type, Request::str('note','',500), Auth::id());
        Session::flash('success','Stock updated. New quantity: '.$after.'.');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Stock update failed.'); }
    Response::redirect('/admin/inventory');
}
$rows = InventoryRepository::rows(['q'=>Request::str('q','',80),'stock'=>Request::str('stock','',10),'page'=>Request::int('page',1,1,10000)]);
$movements = InventoryRepository::movements('',30);
$products = Database::all("SELECT id,name,sku FROM products WHERE deleted_at IS NULL ORDER BY name LIMIT 500");
admin_header(['title'=>'Inventory','subtitle'=>'Atomic stock adjustments and a complete movement audit trail','active'=>'inventory']);
echo '<div class="card card-pad" style="margin-bottom:16px"><h3 style="margin-bottom:14px">Adjust stock</h3><form method="post">'.Session::csrfField().'<div class="form-grid">';
echo '<div class="field span-2"><label for="product_id">Product *</label><select class="select" id="product_id" name="product_id" required><option value="">Choose a product</option>';
foreach ($products as $p) echo '<option value="'.Helpers::e($p['id']).'">'.Helpers::e($p['name']).' ('.Helpers::e($p['sku']).')</option>';
echo '</select></div><div class="field"><label for="variant_id">Variant ID (optional)</label><input class="input" id="variant_id" name="variant_id" maxlength="64"></div>';
echo '<div class="field"><label for="delta">Quantity change *</label><input class="input" type="number" step="1" id="delta" name="delta" required placeholder="Use negative for outbound"></div>';
echo '<div class="field"><label for="movement_type">Movement type</label><select class="select" id="movement_type" name="movement_type"><option value="in">Stock in</option><option value="out">Stock out</option><option value="adjustment" selected>Adjustment</option><option value="damage">Damage</option></select></div>';
echo '<div class="field span-2"><label for="note">Note</label><input class="input" id="note" name="note" maxlength="500"></div>';
echo '</div><button class="btn btn-primary" type="submit" style="margin-top:14px">'.icon('package',16).'Apply stock movement</button></form></div>';
echo '<form class="admin-toolbar" method="get"><input class="input" type="search" name="q" value="'.Helpers::e(Request::str('q','',80)).'" placeholder="Search products or SKU…"><select class="select" name="stock"><option value="">All stock levels</option><option value="low"'.(Request::str('stock','',10)==='low'?' selected':'').'>Low stock</option><option value="out"'.(Request::str('stock','',10)==='out'?' selected':'').'>Out of stock</option></select><button class="btn btn-outline" type="submit">'.icon('search',15).'Search</button></form>';
echo '<div class="card"><div class="card-header"><h3>Stock levels</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Product</th><th>SKU</th><th>Available</th><th>Reserved</th><th>Damaged</th><th>Threshold</th><th>Status</th></tr></thead><tbody>';
foreach ($rows['items'] as $r) { echo '<tr><td class="cell-strong">'.Helpers::e($r['name']).'</td><td>'.Helpers::e($r['sku']).'</td><td><strong>'.(int)$r['stock'].'</strong></td><td>'.(int)($r['reserved'] ?? 0).'</td><td>'.(int)($r['damaged'] ?? 0).'</td><td>'.(int)$r['low_stock_threshold'].'</td><td>'.((int)$r['stock'] <= 0 ? '<span class="badge badge-danger">Out of stock</span>' : ((int)$r['stock'] <= (int)$r['low_stock_threshold'] ? '<span class="badge badge-warning">Low stock</span>' : '<span class="badge badge-success">In stock</span>')).'</td></tr>'; }
echo '</tbody></table></div></div>';
echo '<div class="card" style="margin-top:16px"><div class="card-header"><h3>Recent stock movements</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Change</th><th>After</th><th>Note</th></tr></thead><tbody>';
foreach ($movements as $m) { echo '<tr><td>'.admin_date($m,'created_at').'</td><td>'.Helpers::e($m['product_name'] ?? '—').'</td><td>'.Helpers::e($m['movement_type']).'</td><td>'.(int)$m['quantity_change'].'</td><td>'.(int)$m['quantity_after'].'</td><td>'.Helpers::e($m['note'] ?? '').'</td></tr>'; }
echo '</tbody></table></div></div>';
admin_footer();
