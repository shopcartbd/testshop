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
Auth::requireAdmin('categories.manage');
$id = Request::str('edit','',80);
$cat = $id !== '' ? Database::first('SELECT * FROM categories WHERE id=:i AND deleted_at IS NULL',['i'=>$id]) : null;
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $op = Request::str('op','save',20);
        $catId = Request::id('id');
        if ($op === 'delete') {
            if ($catId === '') throw new AppException('Missing category id.',422);
            CatalogRepository::deleteCategory($catId); Logger::audit('admin.category.deleted','categories',$catId); Session::flash('success','Category archived.'); Response::redirect('/admin/categories');
        }
        $in = Request::all();
        $upload = $_FILES['image_file'] ?? null;
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) { $in['image_url'] = UploadService::store($upload); }
        elseif (trim((string)($in['image_url'] ?? '')) !== '') { $in['image_url'] = admin_safe_url((string)$in['image_url']); }
        if (trim((string)($in['banner_url'] ?? '')) !== '') { $in['banner_url'] = admin_safe_url((string)$in['banner_url']); }
        $saved = CatalogRepository::saveCategory($in,$catId !== '' ? $catId : null);
        Database::delete('category_attributes','category_id=:c',['c'=>$saved]);
        $attributes = CatalogRepository::allAttributes();
        $allowed = array_column($attributes,'id');
        foreach (Request::ids('attribute_ids') as $ai) { if (in_array($ai,$allowed,true)) { Database::insert('category_attributes',['category_id'=>$saved,'attribute_id'=>$ai,'is_filterable'=>1,'sort_order'=>0]); } }
        Logger::audit('admin.category.saved','categories',$saved); Session::flash('success','Category saved.'); Response::redirect('/admin/categories');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/categories'); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Category could not be saved.'); Response::redirect('/admin/categories'); }
}
$cats = CatalogRepository::allCategories(); $attrs = CatalogRepository::allAttributes();
$selected = $cat ? array_column(Database::all('SELECT attribute_id FROM category_attributes WHERE category_id=:c',['c'=>$cat['id']]),'attribute_id') : [];
$v = static fn(string $k,string $d='') => Helpers::e((string)($cat[$k] ?? $d));
admin_header(['title'=>'Categories','subtitle'=>'Nested taxonomy with category-specific filter attributes','active'=>'categories']);
echo '<div class="admin-split"><div class="card card-pad" style="align-self:start"><h3 style="margin-bottom:14px">'.($cat?'Edit':'Add').' category</h3><form method="post" enctype="multipart/form-data">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($id).'"><input type="hidden" name="op" value="save"><div class="form-grid">';
echo '<div class="field span-2"><label for="name">Name *</label><input class="input" id="name" name="name" required value="'.$v('name').'"></div>';
echo '<div class="field"><label for="slug">Slug</label><input class="input" id="slug" name="slug" value="'.$v('slug').'"></div>';
echo '<div class="field"><label for="parent_id">Parent</label><select class="select" id="parent_id" name="parent_id"><option value="">— Top level —</option>';
foreach ($cats as $c) { if ($cat && $c['id']===$cat['id']) continue; echo '<option value="'.Helpers::e($c['id']).'" '.(($cat['parent_id'] ?? '')===$c['id']?'selected':'').'>'.Helpers::e(str_repeat('— ',(int)($c['depth'] ?? 0)).$c['name']).'</option>'; }
echo '</select></div>';
echo '<div class="field"><label for="icon">Icon name</label><input class="input" id="icon" name="icon" value="'.$v('icon','box').'"></div>';
echo '<div class="field"><label for="sort_order">Sort order</label><input class="input" type="number" id="sort_order" name="sort_order" value="'.$v('sort_order','0').'"></div>';
echo '<div class="field span-2"><label for="description">Description</label><textarea class="textarea" id="description" name="description">'.$v('description').'</textarea></div>';
echo '<div class="field"><label for="image_url">Image URL</label><input class="input" type="url" id="image_url" name="image_url" value="'.$v('image_url').'"><input class="input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" style="margin-top:8px"></div>';
echo '<div class="field"><label for="banner_url">Banner URL</label><input class="input" type="url" id="banner_url" name="banner_url" value="'.$v('banner_url').'"></div>';
echo '<div class="field"><label for="seo_title">SEO title</label><input class="input" id="seo_title" name="seo_title" value="'.$v('seo_title').'"></div>';
echo '<div class="field"><label for="seo_description">SEO description</label><input class="input" id="seo_description" name="seo_description" value="'.$v('seo_description').'"></div>';
echo '<div class="field span-2"><label>Filter attributes assigned to this category</label><div class="row wrap" style="gap:14px">';
foreach ($attrs as $a) echo '<label class="checkbox"><input type="checkbox" name="attribute_ids[]" value="'.Helpers::e($a['id']).'" '.(in_array($a['id'],$selected,true)?'checked':'').'><span>'.Helpers::e($a['name']).'</span></label>';
echo '</div><span class="field-hint">These attributes appear as dynamic storefront filters for products in this category.</span></div>';
foreach (['is_active'=>'Active','is_featured'=>'Featured'] as $k=>$l) echo '<div class="field"><label class="checkbox"><input type="checkbox" name="'.$k.'" value="1" '.((int)($cat[$k] ?? 1)?'checked':'').'><span>'.$l.'</span></label></div>';
echo '</div><button class="btn btn-primary" type="submit" style="margin-top:14px">Save category</button></form></div>';
echo '<div class="card"><div class="card-header"><h3>Category tree</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Name</th><th>Slug</th><th>Parent</th><th>Products</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
$byId=[]; foreach ($cats as $c) $byId[$c['id']]=$c['name'];
foreach ($cats as $c) { echo '<tr><td class="cell-strong">'.Helpers::e(str_repeat('— ',(int)($c['depth'] ?? 0)).$c['name']).'</td><td>'.Helpers::e($c['slug']).'</td><td>'.Helpers::e($byId[$c['parent_id'] ?? ''] ?? '—').'</td><td>'.(int)($c['product_count'] ?? 0).'</td><td>'.admin_bool($c,'is_active').'</td><td class="row" style="gap:5px"><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($c['id']).'">Edit</a><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($c['id']).'"><input type="hidden" name="op" value="delete"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Archive this category?">'.icon('trash',13).'</button></form></td></tr>'; }
echo '</tbody></table></div></div></div>';
admin_footer();
