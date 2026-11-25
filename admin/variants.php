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
use AllStore\Models\CatalogRepository;
use AllStore\Services\UploadService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('products.manage');
$productId = Request::str('id','',80);
$product = $productId !== '' ? CatalogRepository::findById($productId,true) : null;
if (!$product) { http_response_code(404); throw new AppException('Product not found.',404); }
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $op = Request::str('op','save',20);
        $variantId = Request::id('variant_id');
        if ($op === 'delete') {
            if ($variantId === '') { throw new AppException('Variant id is required.',422); }
            $n = Database::delete('product_variants','id=:i AND product_id=:p',['i'=>$variantId,'p'=>$productId]);
            if ($n !== 1) { throw new AppException('Variant not found.',404); }
            Logger::audit('admin.variant.deleted','product_variants',$variantId);
            Session::flash('success','Variant removed.');
            Response::redirect('/admin/products/'.$productId.'/variants');
        }
        $sku = trim(Request::str('sku','',64));
        $name = trim(Request::str('name','',120));
        $options = trim(Request::str('options','{}',2000));
        if ($sku === '' || $name === '') { throw new AppException('Variant name and SKU are required.',422); }
        if ($options === '') { $options = '{}'; }
        json_decode($options,true,32,JSON_THROW_ON_ERROR);
        $price = (int)round(Request::float('price',0)*100);
        $compare = (int)round(Request::float('compare_price',0)*100);
        $stock = Request::int('stock',0,0,1000000);
        if ($price < 0 || $compare < 0) { throw new AppException('Prices cannot be negative.',422); }
        $image = trim(Request::str('image_url','',500));
        $upload = $_FILES['image_file'] ?? null;
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $image = UploadService::store($upload); }
        elseif ($image !== '') { $image = admin_safe_url($image); }
        $dup = Database::first('SELECT id FROM product_variants WHERE sku=:s AND id<>:i',['s'=>$sku,'i'=>$variantId]);
        if ($dup) { throw new AppException('That variant SKU is already in use.',409); }
        $data = ['sku'=>$sku,'name'=>$name,'options'=>$options,'price_cents'=>$price,'compare_cents'=>$compare,'stock'=>$stock,'image_url'=>$image,'is_active'=>Request::bool('is_active')?1:0,'updated_at'=>Helpers::now()];
        if ($variantId !== '') {
            $n = Database::update('product_variants',$data,'id=:i AND product_id=:p',['i'=>$variantId,'p'=>$productId]);
            if ($n !== 1) { throw new AppException('Variant not found.',404); }
            $savedId = $variantId;
        } else {
            $savedId = Helpers::uuid();
            $data['id']=$savedId; $data['product_id']=$productId; $data['barcode']=trim(Request::str('barcode','',64)); $data['created_at']=Helpers::now();
            Database::insert('product_variants',$data);
            $wh = Database::first('SELECT id FROM warehouses WHERE is_default=1') ?: Database::first('SELECT id FROM warehouses LIMIT 1');
            if ($wh) { Database::insert('inventory',['id'=>Helpers::uuid(),'product_id'=>$productId,'variant_id'=>$savedId,'warehouse_id'=>$wh['id'],'quantity'=>$stock,'available'=>$stock,'updated_at'=>Helpers::now()]); }
        }
        Logger::audit('admin.variant.saved','product_variants',$savedId);
        Session::flash('success','Variant saved.');
        Response::redirect('/admin/products/'.$productId.'/variants');
    } catch (\JsonException $e) { Session::flash('error','Options must be valid JSON.'); Response::redirect('/admin/products/'.$productId.'/variants'); }
      catch (AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/products/'.$productId.'/variants'); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Variant could not be saved.'); Response::redirect('/admin/products/'.$productId.'/variants'); }
}
$variants = Database::all('SELECT * FROM product_variants WHERE product_id=:p ORDER BY created_at DESC',['p'=>$productId]);
$edit = null; $eid = Request::str('edit','',80);
if ($eid !== '') { foreach ($variants as $v) { if ($v['id']===$eid) $edit=$v; } }
$fv = static fn(string $k,string $d='') => Helpers::e((string)($edit[$k] ?? $d));
admin_header(['title'=>'Product variants','subtitle'=>Helpers::e($product['name']),'active'=>'products']);
echo '<div class="admin-page-head"><div><h2>Variants</h2><p>Options, independent SKUs, prices and stock levels.</p></div><a class="btn btn-outline" href="/admin/products/'.Helpers::e($productId).'">← Back to product</a></div>';
echo '<div class="admin-split"><div class="card card-pad" style="align-self:start"><h3 style="margin-bottom:14px">'.($edit?'Edit':'Add').' variant</h3><form method="post" enctype="multipart/form-data">'.Session::csrfField().'<input type="hidden" name="variant_id" value="'.Helpers::e((string)($edit['id'] ?? '')).'"><input type="hidden" name="op" value="save"><div class="form-grid">';
echo '<div class="field"><label for="name">Option name *</label><input class="input" id="name" name="name" required value="'.$fv('name').'" placeholder="Graphite / 256 GB"></div>';
echo '<div class="field"><label for="sku">SKU *</label><input class="input" id="sku" name="sku" required value="'.$fv('sku').'"></div>';
echo '<div class="field"><label for="barcode">Barcode</label><input class="input" id="barcode" name="barcode" value="'.$fv('barcode').'"></div>';
echo '<div class="field"><label for="price">Price</label><input class="input" type="number" step="0.01" min="0" id="price" name="price" value="'.Helpers::e(number_format(((int)($edit['price_cents'] ?? 0))/100,2,'.','')).'"></div>';
echo '<div class="field"><label for="compare_price">Compare-at</label><input class="input" type="number" step="0.01" min="0" id="compare_price" name="compare_price" value="'.Helpers::e(number_format(((int)($edit['compare_cents'] ?? 0))/100,2,'.','')).'"></div>';
echo '<div class="field"><label for="stock">Stock</label><input class="input" type="number" min="0" id="stock" name="stock" value="'.(int)($edit['stock'] ?? 0).'"></div>';
echo '<div class="field span-2"><label for="options">Options JSON</label><textarea class="textarea" id="options" name="options" style="min-height:70px">'.$fv('options','{}').'</textarea></div>';
echo '<div class="field span-2"><label for="image_url">Image URL</label><input class="input" type="url" id="image_url" name="image_url" value="'.$fv('image_url').'"><input class="input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" style="margin-top:8px"></div>';
echo '<div class="field span-2"><label class="checkbox"><input type="checkbox" name="is_active" value="1" '.((int)($edit['is_active'] ?? 1)?'checked':'').'><span>Active</span></label></div></div>';
echo '<button class="btn btn-primary" type="submit" style="margin-top:14px">Save variant</button>'.($edit?'<a class="btn btn-outline" href="/admin/products/'.Helpers::e($productId).'/variants">Cancel</a>':'').'</form></div>';
echo '<div class="card"><div class="card-header"><h3>Existing variants ('.count($variants).')</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Option</th><th>SKU</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
foreach ($variants as $v) { echo '<tr><td class="cell-strong">'.Helpers::e($v['name']).'</td><td>'.Helpers::e($v['sku']).'</td><td>'.Helpers::money((int)$v['price_cents']).'</td><td>'.(int)$v['stock'].'</td><td>'.admin_bool($v,'is_active','Active','Inactive').'</td><td class="row" style="gap:5px"><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($v['id']).'">Edit</a><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="variant_id" value="'.Helpers::e($v['id']).'"><input type="hidden" name="op" value="delete"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this variant?">'.icon('trash',13).'</button></form></td></tr>'; }
echo '</tbody></table></div></div></div>';
admin_footer();
