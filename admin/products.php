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
use AllStore\Models\CatalogRepository;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('products.view');
if (Request::str('export','',10) === 'csv') {
    Auth::requireAdmin('products.manage');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="allstore-products.csv"'); Response::noCache();
    echo CatalogRepository::exportCsv(); exit;
}
if (Request::isPost()) {
    Session::requireCsrf();
    Auth::requireAdmin('products.manage');
    try {
        $action = Request::str('action','',30);
        if ($action === 'bulk') {
            $ids = Request::ids('ids');
            $op = Request::str('op','',30);
            if (!in_array($op,['delete','duplicate','status','category','price_adjust'],true) || $ids === []) { throw new AppException('Choose rows and a valid bulk action.',422); }
            $n = CatalogRepository::bulk($op,$ids,['status'=>Request::str('status','draft',20),'category_id'=>Request::id('category_id'),'percent'=>Request::float('percent',0)]);
            Logger::audit('admin.products.bulk','products',null,['operation'=>$op,'count'=>$n]);
            Session::flash('success',$n.' product(s) updated.');
        } elseif ($action === 'import') {
            $f = $_FILES['csv_file'] ?? null;
            if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($f['size'] ?? 0) < 1 || (int)($f['size'] ?? 0) > 2097152 || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
                throw new AppException('Upload a CSV file up to 2 MB.',422);
            }
            $csv = (string)file_get_contents((string)$f['tmp_name']);
            if (!mb_check_encoding($csv,'UTF-8') || str_contains($csv,"\0")) { throw new AppException('The CSV must be valid UTF-8 text.',422); }
            $n = CatalogRepository::importCsv($csv);
            Logger::audit('admin.products.import','products',null,['count'=>$n]);
            Session::flash('success',$n.' product row(s) imported.');
        }
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','The operation could not be completed.'); }
    Response::redirect(Request::path());
}
$q = Request::str('q','',80); $status = Request::str('status','',20); $page = Request::int('page',1,1,10000); $per=20; $off=($page-1)*$per;
$where=['p.deleted_at IS NULL']; $params=[];
if ($q!=='') { $where[]='(LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q)'; $params['q']='%'.mb_strtolower($q).'%'; }
if (in_array($status,['draft','active','inactive','archived'],true)) { $where[]='p.status=:st'; $params['st']=$status; }
$rows=Database::all("SELECT p.*, c.name AS category_name, b.name AS brand_name, (SELECT pi.url FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_url FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE ".implode(' AND ',$where)." ORDER BY p.created_at DESC LIMIT $per OFFSET $off",$params);
$categories=Database::all('SELECT id,name FROM categories WHERE deleted_at IS NULL ORDER BY name');
admin_header(['title'=>'Products','subtitle'=>'Catalogue, pricing, inventory flags and bulk tools','active'=>'products']);
echo '<div class="admin-page-head"><div class="row"><a class="btn btn-outline btn-sm" href="?export=csv">'.icon('download',15).'Export CSV</a><a class="btn btn-primary" href="/admin/products/new">'.icon('plus',16).'Add product</a></div></div>';
echo '<div class="card card-pad" style="margin-bottom:16px"><form class="admin-toolbar" method="get"><input class="input" type="search" name="q" value="'.Helpers::e($q).'" placeholder="Search name or SKU…"><select class="select" name="status"><option value="">All statuses</option>';
foreach (['draft','active','inactive','archived'] as $s) { echo '<option value="'.$s.'" '.($status===$s?'selected':'').'>'.ucfirst($s).'</option>'; }
echo '</select><button class="btn btn-outline" type="submit">'.icon('search',15).'Search</button></form>';
echo '<details><summary style="cursor:pointer;font-size:13px;font-weight:600">Import products from CSV</summary><form method="post" enctype="multipart/form-data" style="margin-top:14px">'.Session::csrfField().'<input type="hidden" name="action" value="import"><div class="row wrap"><input class="input" type="file" name="csv_file" accept="text/csv,.csv" required style="max-width:300px"><button class="btn btn-outline btn-sm" type="submit">Import</button></div><span class="field-hint">Columns: sku, name, slug, price, compare_price, stock, category, brand, product_kind, product_type, digital_file_url, custom_options, bundle_items, service_details. Max 500 rows / 2 MB.</span></form></details></div>';
echo '<form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="bulk"><div class="admin-toolbar" style="flex-wrap:wrap"><select class="select" name="op" style="width:auto"><option value="status">Change status</option><option value="category">Change category</option><option value="price_adjust">Adjust price %</option><option value="duplicate">Duplicate</option><option value="delete">Archive / delete</option></select><select class="select" name="status" style="width:auto"><option value="draft">Draft</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select><select class="select" name="category_id" style="width:auto"><option value="">Choose category</option>';
foreach ($categories as $c) echo '<option value="'.Helpers::e($c['id']).'">'.Helpers::e($c['name']).'</option>';
echo '</select><input class="input" type="number" name="percent" value="0" step="0.01" style="width:110px" aria-label="Price adjustment percent"><button class="btn btn-outline btn-sm" type="submit" data-confirm="Apply the selected bulk action?">Apply to selected</button></div>';
echo '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><input type="checkbox" data-check-all aria-label="Select all products"></th><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr><td><input type="checkbox" name="ids[]" value="'.Helpers::e($r['id']).'" aria-label="Select '.Helpers::e($r['name']).'"></td><td><div class="row" style="gap:9px"><img src="'.Helpers::e($r['image_url'] ?: '/assets/img/products/headphones.jpg').'" alt="" style="width:38px;height:38px;border-radius:8px;object-fit:cover"><div><div class="cell-strong">'.Helpers::e($r['name']).'</div><div class="text-subtle" style="font-size:11px">'.Helpers::e($r['brand_name'] ?? '').'</div></div></div></td><td>'.Helpers::e($r['sku']).'</td><td>'.Helpers::e($r['category_name'] ?? 'Uncategorised').'</td><td>'.Helpers::money((int)$r['price_cents']).'</td><td>'.(int)$r['stock'].'</td><td>'.admin_status($r).'</td><td class="row" style="gap:4px"><a class="btn btn-sm btn-outline" href="/admin/products/'.Helpers::e($r['id']).'">Edit</a><a class="btn btn-sm btn-outline" href="/admin/products/'.Helpers::e($r['id']).'/variants">Variants</a></td></tr>';
}
echo '</tbody></table></div></form>';
echo '<div class="row" style="margin-top:12px;gap:8px">'.($page>1?'<a class="btn btn-outline btn-sm" href="?page='.($page-1).'&q='.rawurlencode($q).'&status='.rawurlencode($status).'">← Previous</a>':'').(count($rows)===$per?'<a class="btn btn-outline btn-sm" href="?page='.($page+1).'&q='.rawurlencode($q).'&status='.rawurlencode($status).'">Next →</a>':'').'</div>';
admin_footer();
