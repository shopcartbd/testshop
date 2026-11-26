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
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('attributes.manage');
$id = Request::str('edit','',80);
$row = $id !== '' ? Database::first('SELECT * FROM attributes WHERE id=:i',['i'=>$id]) : null;
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        if (Request::str('op','save',20) === 'delete') {
            $did = Request::id('id'); if ($did==='') throw new AppException('Missing attribute id.',422);
            $reserved = Database::first('SELECT slug FROM attributes WHERE id=:i',['i'=>$did]);
            if ($reserved && str_starts_with((string)$reserved['slug'],'system-')) throw new AppException('Internal product data cannot be deleted.',422);
            Database::delete('attributes','id=:i',['i'=>$did]); Logger::audit('admin.attribute.deleted','attributes',$did); Session::flash('success','Attribute deleted.'); Response::redirect('/admin/attributes');
        }
        $in = Request::all();
        $saved = CatalogRepository::saveAttribute($in, Request::id('id') ?: null);
        Logger::audit('admin.attribute.saved','attributes',$saved); Session::flash('success','Attribute saved.'); Response::redirect('/admin/attributes');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/attributes'); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Attribute could not be saved.'); Response::redirect('/admin/attributes'); }
}
$attrs = CatalogRepository::allAttributes();
$v = static fn(string $k,string $d='') => Helpers::e((string)($row[$k] ?? $d));
admin_header(['title'=>'Attributes','subtitle'=>'Dynamic product specifications and filter values','active'=>'attributes']);
echo '<div class="admin-split"><div class="card card-pad" style="align-self:start"><h3 style="margin-bottom:14px">'.($row?'Edit':'Add').' attribute</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($id).'"><input type="hidden" name="op" value="save"><div class="form-grid">';
echo '<div class="field"><label for="name">Name *</label><input class="input" id="name" name="name" required value="'.$v('name').'"></div>';
echo '<div class="field"><label for="slug">Slug</label><input class="input" id="slug" name="slug" value="'.$v('slug').'"></div>';
echo '<div class="field"><label for="type">Type</label><select class="select" id="type" name="type">';
foreach (['text','number','boolean','dropdown','multiselect','color','measurement'] as $t) echo '<option value="'.$t.'" '.(($row['type'] ?? 'dropdown')===$t?'selected':'').'>'.Helpers::e(ucfirst($t)).'</option>';
echo '</select></div><div class="field"><label for="unit">Unit</label><input class="input" id="unit" name="unit" value="'.$v('unit').'"></div>';
echo '<div class="field"><label for="sort_order">Sort order</label><input class="input" type="number" id="sort_order" name="sort_order" value="'.$v('sort_order','0').'"></div>';
echo '<div class="field"><label class="checkbox"><input type="checkbox" name="is_filterable" value="1" '.((int)($row['is_filterable'] ?? 1)?'checked':'').'><span>Use as a storefront filter</span></label></div>';
echo '<div class="field span-2"><label for="values">Dropdown values (one per line: value|Label)</label><textarea class="textarea" id="values" name="values" style="min-height:110px" placeholder="graphite|Graphite"></textarea><span class="field-hint">Existing values are upserted. Assign attributes to categories in the Categories screen.</span></div>';
echo '</div><button class="btn btn-primary" type="submit" style="margin-top:14px">Save attribute</button></form></div>';
echo '<div class="card"><div class="card-header"><h3>Attribute definitions</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Name</th><th>Slug</th><th>Type</th><th>Values</th><th>Filterable</th><th>Actions</th></tr></thead><tbody>';
foreach ($attrs as $a) { echo '<tr><td class="cell-strong">'.Helpers::e($a['name']).'</td><td>'.Helpers::e($a['slug']).'</td><td>'.Helpers::e($a['type']).'</td><td>'.(int)($a['value_count'] ?? 0).'</td><td>'.admin_bool($a,'is_filterable','Yes','No').'</td><td class="row" style="gap:5px"><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($a['id']).'">Edit</a><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($a['id']).'"><input type="hidden" name="op" value="delete"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this attribute and its values?">'.icon('trash',13).'</button></form></td></tr>'; }
echo '</tbody></table></div></div></div>';
admin_footer();
