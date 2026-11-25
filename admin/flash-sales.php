<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('promotions.manage');
$dt=static function(string $v): ?string { $v=trim($v); if($v==='') return null; $t=strtotime($v); return $t ? gmdate('Y-m-d\TH:i:s\Z',$t) : throw new AppException('Enter a valid date.',422); };
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $action=Request::str('action','',30);
        if ($action==='save_sale') {
            $id=Request::id('sale_id'); $name=trim(Request::str('name','',120));
            if ($name==='') throw new AppException('Campaign name is required.',422);
            $data=['name'=>$name,'starts_at'=>$dt(Request::str('starts_at','',30)),'ends_at'=>$dt(Request::str('ends_at','',30)),'is_active'=>Request::bool('is_active')?1:0];
            if ($id!=='') { Database::update('flash_sales',$data,'id=:i',['i'=>$id]); $saved=$id; }
            else { $saved=Helpers::uuid(); $data['id']=$saved; $data['created_at']=Helpers::now(); Database::insert('flash_sales',$data); }
            Logger::audit('admin.flash_sale.saved','flash_sales',$saved); Session::flash('success','Flash sale saved.'); Response::redirect('/admin/flash-sales');
        } elseif ($action==='add_item') {
            $sale=Request::id('sale_id'); $pid=Request::id('product_id'); $pct=Request::float('discount_percent',0);
            if ($sale==='' || $pid==='' || $pct<=0 || $pct>90) throw new AppException('Choose a product and a discount between 0 and 90%.',422);
            $s=Database::first('SELECT id FROM flash_sales WHERE id=:i',['i'=>$sale]); $p=Database::first('SELECT id,price_cents FROM products WHERE id=:i AND deleted_at IS NULL',['i'=>$pid]);
            if (!$s || !$p) throw new AppException('Flash sale or product not found.',404);
            if (Database::first('SELECT id FROM flash_sale_items WHERE flash_sale_id=:s AND product_id=:p',['s'=>$sale,'p'=>$pid])) throw new AppException('This product is already in the sale.',409);
            $id=Helpers::uuid();
            Database::insert('flash_sale_items',['id'=>$id,'flash_sale_id'=>$sale,'product_id'=>$pid,'discount_percent'=>$pct,'sale_price_cents'=>(int)round((int)$p['price_cents']*(1-$pct/100)),'sort_order'=>Request::int('sort_order',0,0,1000)]);
            Logger::audit('admin.flash_sale.item_added','flash_sale_items',$id); Session::flash('success','Product added to flash sale.'); Response::redirect('/admin/flash-sales');
        } elseif ($action==='delete_item') {
            $id=Request::id('item_id'); if($id==='') throw new AppException('Missing item id.',422);
            Database::delete('flash_sale_items','id=:i',['i'=>$id]); Logger::audit('admin.flash_sale.item_deleted','flash_sale_items',$id); Session::flash('success','Item removed.'); Response::redirect('/admin/flash-sales');
        } elseif ($action==='toggle_sale') {
            $id=Request::id('sale_id'); $s=Database::first('SELECT is_active FROM flash_sales WHERE id=:i',['i'=>$id]);
            if(!$s) throw new AppException('Flash sale not found.',404);
            Database::update('flash_sales',['is_active'=>(int)$s['is_active']?0:1],'id=:i',['i'=>$id]); Session::flash('success','Campaign status updated.'); Response::redirect('/admin/flash-sales');
        }
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/flash-sales'); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Could not update the flash sale.'); Response::redirect('/admin/flash-sales'); }
}
$sales=Database::all('SELECT * FROM flash_sales ORDER BY created_at DESC');
$products=Database::all("SELECT id,name,price_cents FROM products WHERE deleted_at IS NULL AND status='active' ORDER BY name LIMIT 500");
$editId=Request::str('edit','',80); $edit=null; foreach($sales as $s) if($s['id']===$editId) $edit=$s;
admin_header(['title'=>'Flash sales','subtitle'=>'Scheduled campaigns with real database sale prices','active'=>'flash']);
echo '<div class="admin-split"><div class="card card-pad" style="align-self:start"><h3 style="margin-bottom:14px">'.($edit?'Edit':'Create').' flash sale</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="save_sale"><input type="hidden" name="sale_id" value="'.Helpers::e((string)($edit['id'] ?? '')).'"><div class="form-grid">';
echo '<div class="field span-2"><label for="name">Campaign name *</label><input class="input" id="name" name="name" required value="'.Helpers::e($edit['name'] ?? '').'" maxlength="120"></div>';
echo '<div class="field"><label for="starts_at">Starts at</label><input class="input" type="datetime-local" id="starts_at" name="starts_at" value="'.Helpers::e(substr((string)($edit['starts_at'] ?? ''),0,16)).'"></div>';
echo '<div class="field"><label for="ends_at">Ends at</label><input class="input" type="datetime-local" id="ends_at" name="ends_at" value="'.Helpers::e(substr((string)($edit['ends_at'] ?? ''),0,16)).'"></div>';
echo '<div class="field span-2"><label class="checkbox"><input type="checkbox" name="is_active" value="1" '.((int)($edit['is_active'] ?? 1)?'checked':'').'><span>Campaign active</span></label></div></div><button class="btn btn-primary" type="submit" style="margin-top:14px">Save campaign</button></form>';
echo '<hr><h4 style="margin-bottom:12px">Add a product</h4><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="add_item"><div class="form-grid"><div class="field span-2"><label>Sale</label><select class="select" name="sale_id" required><option value="">Choose a campaign</option>';
foreach($sales as $s) echo '<option value="'.Helpers::e($s['id']).'">'.Helpers::e($s['name']).'</option>';
echo '</select></div><div class="field span-2"><label>Product</label><select class="select" name="product_id" required><option value="">Choose a product</option>';
foreach($products as $p) echo '<option value="'.Helpers::e($p['id']).'">'.Helpers::e($p['name']).' — '.Helpers::money((int)$p['price_cents']).'</option>';
echo '</select></div><div class="field"><label>Discount %</label><input class="input" type="number" name="discount_percent" min="1" max="90" step="0.1" value="20" required></div><div class="field"><label>Sort order</label><input class="input" type="number" name="sort_order" value="0"></div></div><button class="btn btn-outline btn-sm" type="submit" style="margin-top:12px">Add product</button></form></div>';
echo '<div class="card"><div class="card-header"><h3>Campaigns</h3></div>';
foreach($sales as $s) {
    $items=Database::all('SELECT fi.*,p.name,p.price_cents FROM flash_sale_items fi LEFT JOIN products p ON p.id=fi.product_id WHERE fi.flash_sale_id=:s ORDER BY fi.sort_order',['s'=>$s['id']]);
    echo '<div style="padding:16px 20px;border-bottom:1px solid var(--c-border)"><div class="row-between wrap"><div><strong>'.Helpers::e($s['name']).'</strong><div class="text-subtle" style="font-size:11px">'.admin_date($s,'starts_at').' → '.admin_date($s,'ends_at').' · '.count($items).' products</div></div><div class="row" style="gap:6px">'.admin_bool($s,'is_active').'<a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($s['id']).'">Edit</a><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="toggle_sale"><input type="hidden" name="sale_id" value="'.Helpers::e($s['id']).'"><button class="btn btn-outline btn-sm" type="submit">Toggle</button></form></div></div>';
    foreach($items as $it) echo '<div class="order-row" style="padding:8px 0"><span>'.Helpers::e($it['name']).'</span><span>'.(float)$it['discount_percent'].'% off · '.Helpers::money((int)$it['sale_price_cents']).'</span><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="'.Helpers::e($it['id']).'"><button class="btn btn-danger btn-sm" type="submit">'.icon('trash',13).'</button></form></div>';
    echo '</div>';
}
echo '</div></div>';
admin_footer();
