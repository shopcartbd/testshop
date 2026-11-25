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
use AllStore\Services\UploadService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('products.manage');
$id = Request::str('id','',80);
$p = $id !== '' ? CatalogRepository::findById($id,true) : null;
if ($id !== '' && !$p) { http_response_code(404); throw new AppException('Product not found.',404); }
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $in = Request::all();
        $video = trim((string)($in['video_url'] ?? ''));
        $in['video_url'] = $video === '' ? '' : admin_safe_url($video);
        $images = preg_split('/\r\n|\r|\n/', Request::str('gallery_urls','',20000)) ?: [];
        $images = array_values(array_filter(array_map('trim',$images), static fn($u)=>$u!==''));
        $upload = $_FILES['image_file'] ?? null;
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { array_unshift($images, UploadService::store($upload)); }
        foreach ($images as $k=>$u) { if (!preg_match('#^(/assets/|/storage/uploads/[A-Za-z0-9/_\.\-]+|https://)#',$u)) { unset($images[$k]); } }
        $in['images'] = array_values($images);
        $saved = CatalogRepository::saveProduct($in, $id !== '' ? $id : null);
        Logger::audit($id!==''?'admin.product.updated':'admin.product.created','products',$saved);
        Session::flash('success','Product saved.');
        Response::redirect('/admin/products');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect(Request::path().($id!==''?'?id='.rawurlencode($id):'')); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Product could not be saved.'); Response::redirect(Request::path().($id!==''?'?id='.rawurlencode($id):'')); }
}
$p = $p ?: [];
$cats = Database::all('SELECT id,name FROM categories WHERE deleted_at IS NULL ORDER BY name');
$brands = Database::all('SELECT id,name FROM brands WHERE is_active=1 ORDER BY name');
$gallery = $id !== '' ? array_column(CatalogRepository::images($id),'url') : [];
$v = static function(string $k, string $d='', int $max=2000) use ($p): string { return Helpers::e(Request::str($k, (string)($p[$k] ?? $d), $max)); };
$money = static function(string $k, string $col) use ($p): string { return Helpers::e(Request::str($k, number_format(((int)($p[$col] ?? 0))/100,2,'.',''))); };
$chk = static function(string $k, int $d=0) use ($p): string { return (Request::isPost()?Request::bool($k):(int)($p[$k] ?? $d)) ? 'checked' : ''; };
$sel = static function(string $k, string $val) use ($p): string { return (string)Request::input($k,(string)($p[$k] ?? ''))===$val ? 'selected' : ''; };
admin_header(['title'=>$id!==''?'Edit product':'New product','subtitle'=>'Complete product record, media and merchandising flags','active'=>'products']);
echo '<form method="post" enctype="multipart/form-data">'.Session::csrfField().'<div class="admin-grid-2"><div class="card card-pad"><h3 style="margin-bottom:16px">Basics</h3><div class="form-grid">';
echo '<div class="field span-2"><label for="name">Product name *</label><input class="input" id="name" name="name" required maxlength="180" value="'.$v('name').'"></div>';
echo '<div class="field"><label for="slug">URL slug</label><input class="input" id="slug" name="slug" maxlength="180" value="'.$v('slug').'"><span class="field-hint">Leave blank to generate from the name.</span></div>';
echo '<div class="field"><label for="sku">SKU</label><input class="input" id="sku" name="sku" maxlength="64" value="'.$v('sku').'"></div>';
echo '<div class="field"><label for="barcode">Barcode</label><input class="input" id="barcode" name="barcode" maxlength="64" value="'.$v('barcode').'"></div>';
echo '<div class="field"><label for="product_type">Product type</label><select class="select" id="product_type" name="product_type">';
foreach (['simple'=>'Simple','variable'=>'Variable','bundle'=>'Bundle','digital'=>'Digital'] as $k=>$label) { echo '<option value="'.$k.'" '.$sel('product_type',$k).'>'.$label.'</option>'; }
echo '</select></div>';
echo '<div class="field"><label for="category_id">Category</label><select class="select" id="category_id" name="category_id"><option value="">— None —</option>';
foreach ($cats as $c) echo '<option value="'.Helpers::e($c['id']).'" '.$sel('category_id',(string)$c['id']).'>'.Helpers::e($c['name']).'</option>';
echo '</select></div>';
echo '<div class="field"><label for="brand_id">Brand</label><select class="select" id="brand_id" name="brand_id"><option value="">— None —</option>';
foreach ($brands as $b) echo '<option value="'.Helpers::e($b['id']).'" '.$sel('brand_id',(string)$b['id']).'>'.Helpers::e($b['name']).'</option>';
echo '</select></div>';
echo '<div class="field"><label for="status">Status</label><select class="select" id="status" name="status">';
foreach (['draft'=>'Draft','active'=>'Active','inactive'=>'Inactive','archived'=>'Archived'] as $k=>$label) { echo '<option value="'.$k.'" '.$sel('status',$k).'>'.$label.'</option>'; }
echo '</select></div>';
echo '<div class="field span-2"><label for="short_description">Short description</label><textarea class="textarea" id="short_description" name="short_description" maxlength="500">'.$v('short_description').'</textarea></div>';
echo '<div class="field span-2"><label for="full_description">Full description</label><textarea class="textarea" id="full_description" name="full_description" style="min-height:150px">'.$v('full_description','',20000).'</textarea></div>';
echo '</div></div><div class="card card-pad"><h3 style="margin-bottom:16px">Pricing, stock & media</h3><div class="form-grid">';
echo '<div class="field"><label for="price">Price *</label><input class="input" type="number" step="0.01" min="0" id="price" name="price" value="'.$money('price','price_cents').'" required></div>';
echo '<div class="field"><label for="compare_price">Compare-at price</label><input class="input" type="number" step="0.01" min="0" id="compare_price" name="compare_price" value="'.$money('compare_price','compare_cents').'"></div>';
echo '<div class="field"><label for="cost">Unit cost</label><input class="input" type="number" step="0.01" min="0" id="cost" name="cost" value="'.$money('cost','cost_cents').'"></div>';
echo '<div class="field"><label for="tax_rate">Tax rate %</label><input class="input" type="number" step="0.001" min="0" max="100" id="tax_rate" name="tax_rate" value="'.$v('tax_rate','0').'"></div>';
echo '<div class="field"><label for="stock">Stock</label><input class="input" type="number" step="1" min="0" id="stock" name="stock" value="'.$v('stock','0').'"></div>';
echo '<div class="field"><label for="low_stock_threshold">Low-stock threshold</label><input class="input" type="number" step="1" min="0" id="low_stock_threshold" name="low_stock_threshold" value="'.$v('low_stock_threshold','5').'"></div>';
echo '<div class="field"><label for="weight_grams">Weight (g)</label><input class="input" type="number" step="1" min="0" id="weight_grams" name="weight_grams" value="'.$v('weight_grams','0').'"></div>';
echo '<div class="field"><label for="return_days">Return window (days)</label><input class="input" type="number" step="1" min="0" id="return_days" name="return_days" value="'.$v('return_days','14').'"></div>';
echo '<div class="field"><label for="dimensions">Dimensions</label><input class="input" id="dimensions" name="dimensions" value="'.$v('dimensions').'"></div>';
echo '<div class="field"><label for="warranty">Warranty</label><input class="input" id="warranty" name="warranty" value="'.$v('warranty').'"></div>';
echo '<div class="field"><label for="supplier_name">Supplier</label><input class="input" id="supplier_name" name="supplier_name" value="'.$v('supplier_name').'"></div>';
echo '<div class="field"><label for="video_url">Video URL (https)</label><input class="input" type="url" id="video_url" name="video_url" value="'.$v('video_url').'"></div>';
echo '<div class="field span-2"><label for="gallery_urls">Gallery URLs (one per line)</label><textarea class="textarea" id="gallery_urls" name="gallery_urls" style="min-height:80px">'.Helpers::e(implode("\n",$gallery)).'</textarea></div>';
echo '<div class="field span-2"><label for="image_file">Upload an image and add it to the gallery</label><input class="input" type="file" id="image_file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif"><span class="field-hint">JPEG, PNG, WebP or GIF up to 5 MB. Stored in Supabase Storage when configured.</span></div>';
echo '<div class="field span-2"><label for="seo_title">SEO title</label><input class="input" id="seo_title" name="seo_title" value="'.$v('seo_title').'"></div>';
echo '<div class="field span-2"><label for="seo_description">SEO description</label><textarea class="textarea" id="seo_description" name="seo_description">'.$v('seo_description').'</textarea></div>';
echo '</div></div></div>';
echo '<div class="card card-pad" style="margin-top:16px"><h3 style="margin-bottom:12px">Merchandising</h3><div class="row wrap" style="gap:24px">';
foreach (['is_featured'=>'Featured','is_best_seller'=>'Best seller','is_new_arrival'=>'New arrival','is_trending'=>'Trending','return_eligible'=>'Return eligible'] as $k=>$label) { echo '<label class="checkbox"><input type="checkbox" name="'.$k.'" value="1" '.$chk($k,$k==='return_eligible'?1:0).'><span>'.$label.'</span></label>'; }
echo '</div></div><div class="row" style="margin-top:16px"><button class="btn btn-primary btn-lg" type="submit">'.icon('check',16).'Save product</button><a class="btn btn-outline btn-lg" href="/admin/products">Cancel</a>'.($id!==''?'<a class="btn btn-outline btn-lg" href="/admin/products/'.Helpers::e($id).'/variants">'.icon('sliders',16).'Manage variants</a>':'').'</div></form>';
admin_footer();
