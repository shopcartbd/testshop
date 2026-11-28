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
        $digitalFile = $_FILES['digital_file'] ?? null;
        if (is_array($digitalFile) && (int)($digitalFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $in['digital_file_url'] = UploadService::store($digitalFile, true, true);
        } else {
            $digital = trim(Request::str('digital_file_url','',1000));
            $in['digital_file_url'] = preg_match('#^supabase-private:[A-Za-z0-9/_\.\-]{1,300}$#', $digital)
                ? $digital : ($digital === '' ? '' : admin_safe_url($digital));
        }
        $in['product_kind'] = Request::str('product_kind','physical',40);
        $in['custom_options'] = Request::str('custom_options','',20000);
        $in['bundle_items'] = Request::str('bundle_items','',20000);
        $in['service_details'] = Request::str('service_details','',20000);
        $spec = Request::input('spec', []);
        $in['spec'] = is_array($spec) ? $spec : [];
        $images = preg_split('/\r\n|\r|\n/', Request::str('gallery_urls','',20000)) ?: [];
        $images = array_values(array_filter(array_map('trim',$images), static fn($u)=>$u!==''));
        // Multiple files chosen in the admin gallery picker become the first
        // images; the first uploaded file is the main product image.
        $uploaded = [];
        $multi = $_FILES['gallery_files'] ?? null;
        if (is_array($multi) && isset($multi['name']) && is_array($multi['name'])) {
            foreach (array_keys($multi['name']) as $i) {
                $file = [
                    'name'=>(string)($multi['name'][$i] ?? ''),
                    'type'=>(string)($multi['type'][$i] ?? ''),
                    'tmp_name'=>(string)($multi['tmp_name'][$i] ?? ''),
                    'error'=>(int)($multi['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size'=>(int)($multi['size'][$i] ?? 0),
                ];
                if ($file['error'] === UPLOAD_ERR_OK) { $uploaded[] = UploadService::store($file); }
            }
        }
        // Backward compatibility for the old single-image field.
        $legacy = $_FILES['image_file'] ?? null;
        if (is_array($legacy) && (int)($legacy['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            array_unshift($uploaded, UploadService::store($legacy));
        }
        $images = array_values(array_merge($uploaded, $images));
        foreach ($images as $k=>$u) { if (!preg_match('#^(/assets/|/storage/uploads/[A-Za-z0-9/_\.\-]+|https://)#',$u)) { unset($images[$k]); } }
        $in['images'] = array_values(array_slice($images, 0, 12));
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
$meta = $id !== '' ? CatalogRepository::productMeta($id) : ['kind'=>'physical','custom_options_text'=>'','digital_file_url'=>'','bundle_items_text'=>'','service_details'=>''];
$specDefs = Database::all("SELECT id,name,type,unit FROM attributes WHERE slug NOT LIKE 'system-%' ORDER BY name");
$specMap = [];
if ($id !== '') {
    foreach (Database::all("SELECT attribute_id,value_text FROM product_attribute_values WHERE product_id=:p AND value_id=''", ['p'=>$id]) as $row) {
        $specMap[(string)$row['attribute_id']] = (string)($row['value_text'] ?? '');
    }
}
$attrChoices = [];
foreach (Database::all('SELECT attribute_id,label FROM attribute_values ORDER BY sort_order,label') as $row) {
    $attrChoices[(string)$row['attribute_id']][] = (string)$row['label'];
}
$submittedSpec = Request::input('spec', []);
$submittedSpec = is_array($submittedSpec) ? $submittedSpec : [];
$metaText = static function(string $field, string $metaKey) use ($meta): string {
    return Helpers::e(Request::str($field, (string)($meta[$metaKey] ?? ''), 20000));
};
$kind = (string)Request::input('product_kind', (string)($meta['kind'] ?? 'physical'));
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
echo '<div class="field"><label for="product_type">Catalogue structure</label><select class="select" id="product_type" name="product_type">';
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
echo '<div class="field span-2"><label for="gallery_urls">Gallery URLs (optional, one per line)</label><textarea class="textarea" id="gallery_urls" name="gallery_urls" style="min-height:80px">'.Helpers::e(implode("\n",$gallery)).'</textarea><span class="field-hint">Existing gallery URLs stay here. You can also upload several images at once below.</span></div>';
$mediaStatus = UploadService::status();
$mediaHint = $mediaStatus['provider'] === 'cloudinary'
    ? 'Uploads pass through the server API to Cloudinary (' . $mediaStatus['cloudinary']['cloud_name'] . ' · /' . $mediaStatus['cloudinary']['folder'] . '). Only the secure URL is saved in the database.'
    : ($mediaStatus['ready']
        ? 'Cloudinary is not configured, so images are stored in ' . $mediaStatus['label'] . '. Add CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET in Vercel to use Cloudinary.'
        : 'Image storage is not configured. Add CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET in Vercel → Settings → Environment Variables.');
echo '<div class="field span-2"><label for="gallery_files">Upload product images (select multiple)</label>'
    . '<input class="input" type="file" id="gallery_files" name="gallery_files[]" multiple accept="image/jpeg,image/png,image/webp,image/gif"'
    . ' data-media-upload="1" data-media-endpoint="' . Helpers::e(Helpers::url('/api/admin/media/upload')) . '"'
    . ' data-media-target="#gallery_urls" data-media-preview="#gallery_upload_preview" data-media-csrf="' . Helpers::e(Session::csrfToken()) . '">'
    . '<span class="field-hint">JPEG, PNG, WebP or GIF · up to 12 images · 5 MB each · ' . Helpers::e($mediaHint) . '</span></div>';
echo '<div class="field span-2" id="gallery_upload_preview" data-media-preview-box hidden></div>';
if ($gallery !== []) {
    echo '<div class="field span-2"><label>Current gallery</label><div style="display:flex;flex-wrap:wrap;gap:8px">';
    foreach ($gallery as $g) { echo '<img src="'.Helpers::e($g).'" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--c-border)">'; }
    echo '</div></div>';
}
echo '<div class="field span-2"><label for="seo_title">SEO title</label><input class="input" id="seo_title" name="seo_title" value="'.$v('seo_title').'"></div>';
echo '<div class="field span-2"><label for="seo_description">SEO description</label><textarea class="textarea" id="seo_description" name="seo_description">'.$v('seo_description').'</textarea></div>';
echo '</div></div></div>';
echo '<div class="card card-pad" style="margin-top:16px"><h3 style="margin-bottom:12px">All product types & order details</h3><div class="form-grid">';
echo '<div class="field span-2"><label for="product_kind">What are you selling? *</label><select class="select" id="product_kind" name="product_kind">';
foreach ([
 'physical'=>'Physical product (courier delivery)',
 'digital'=>'Digital download / file',
 'service'=>'Service or booking',
 'subscription'=>'Subscription / membership',
 'custom'=>'Custom-made / personalised product',
 'bundle'=>'Bundle or kit',
 'variable'=>'Variable product (options and variants)',
] as $k=>$label) { echo '<option value="'.Helpers::e($k).'" '.($kind===$k?'selected':'').'>'.Helpers::e($label).'</option>'; }
echo '</select><span class="field-hint">No courier required · unlimited stock</span></div>';
echo '<div class="field span-2"><label for="custom_options">Customer order fields (one per line: Label|text/textarea/select|required|Choice 1,Choice 2)</label><textarea class="textarea" id="custom_options" name="custom_options" style="min-height:80px" placeholder="Name on card|text|required|">'.$metaText('custom_options','custom_options_text').'</textarea><span class="field-hint">Shown on product page · saved with orders</span></div>';
echo '<div class="field span-2"><label for="digital_file_url">Digital delivery file URL (https or /storage/uploads/...)</label><input class="input" type="url" id="digital_file_url" name="digital_file_url" maxlength="1000" value="'.$metaText('digital_file_url','digital_file_url').'"></div>';
echo '<div class="field span-2"><label for="digital_file">Or upload a private digital delivery file (PDF, ZIP, image or text)</label><input class="input" type="file" id="digital_file" name="digital_file" accept=".pdf,.zip,.png,.jpg,.jpeg,.webp,.gif,.txt"><span class="field-hint">Private download · available after payment</span></div>';
echo '<div class="field span-2"><label for="bundle_items">Bundle contents (one per line: SKU|Name|Qty)</label><textarea class="textarea" id="bundle_items" name="bundle_items" style="min-height:80px" placeholder="AS-0001|Aurora Headphones|1">'.$metaText('bundle_items','bundle_items_text').'</textarea><span class="field-hint">Saved with each order</span></div>';
echo '<div class="field span-2"><label for="service_details">Service / subscription details</label><textarea class="textarea" id="service_details" name="service_details" style="min-height:80px">'.$metaText('service_details','service_details').'</textarea></div>';
echo '</div><h4 style="margin:18px 0 10px">Specifications</h4><div class="form-grid">';
foreach ($specDefs as $a) {
    $aid = (string)$a['id'];
    $value = (string)($submittedSpec[$aid] ?? ($specMap[$aid] ?? ''));
    $listId = 'spec_values_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $aid);
    echo '<div class="field"><label for="spec_'.Helpers::e($aid).'">'.Helpers::e($a['name']).($a['unit'] !== '' ? ' ('.Helpers::e($a['unit']).')' : '').'</label>';
    echo '<input class="input" id="spec_'.Helpers::e($aid).'" name="spec['.Helpers::e($aid).']" list="'.Helpers::e($listId).'" maxlength="300" value="'.Helpers::e($value).'">';
    if (!empty($attrChoices[$aid])) {
        echo '<datalist id="'.Helpers::e($listId).'">';
        foreach ($attrChoices[$aid] as $choice) { echo '<option value="'.Helpers::e($choice).'"></option>'; }
        echo '</datalist>';
    }
    echo '</div>';
}
if ($specDefs === []) { echo '<div class="field span-2"><span class="field-hint">Create reusable specifications in <a class="text-brand" href="/admin/attributes">Attributes</a>.</span></div>'; }
echo '<div class="card card-pad" style="margin-top:16px"><h3 style="margin-bottom:12px">Merchandising</h3><div class="row wrap" style="gap:24px">';
foreach (['is_featured'=>'Featured','is_best_seller'=>'Best seller','is_new_arrival'=>'New arrival','is_trending'=>'Trending','return_eligible'=>'Return eligible'] as $k=>$label) { echo '<label class="checkbox"><input type="checkbox" name="'.$k.'" value="1" '.$chk($k,$k==='return_eligible'?1:0).'><span>'.$label.'</span></label>'; }
echo '</div></div><div class="row" style="margin-top:16px"><button class="btn btn-primary btn-lg" type="submit">'.icon('check',16).'Save product</button><a class="btn btn-outline btn-lg" href="/admin/products">Cancel</a>'.($id!==''?'<a class="btn btn-outline btn-lg" href="/admin/products/'.Helpers::e($id).'/variants">'.icon('sliders',16).'Manage variants</a>':'').'</div></form>';
admin_footer();
