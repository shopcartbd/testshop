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
use AllStore\Models\CmsRepository;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('cms.manage');
$keys=['hero_slider'=>'Hero slider','categories'=>'Featured categories','flash_sale'=>'Flash sale','best_sellers'=>'Best sellers','trending'=>'Trending now','promo_banners'=>'Promotional banners','new_arrivals'=>'New arrivals','brands'=>'Brands','deals'=>'Deals','recommended'=>'Recommended products','recently_viewed'=>'Recently viewed','newsletter'=>'Newsletter','products'=>'Custom product list','featured'=>'Featured products'];
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $action=Request::str('action','',30); $id=Request::id('section_id');
        if ($action==='save') {
            $in=Request::all(); $config=trim((string)($in['config'] ?? '{}')); if($config==='') $config='{}'; json_decode($config,true,32,JSON_THROW_ON_ERROR);
            $in['config']=$config; $in['starts_at']=Request::str('starts_at','',30); $in['expires_at']=Request::str('expires_at','',30);
            $saved=CmsRepository::saveSection($in,$id!==''?$id:null);
            Logger::audit('admin.homepage.saved','homepage_sections',$saved); Session::flash('success','Homepage section saved.'); Response::redirect('/admin/homepage');
        }
        if ($action==='toggle') { $s=Database::first('SELECT is_enabled FROM homepage_sections WHERE id=:i',['i'=>$id]); if(!$s) throw new AppException('Section not found.',404); Database::update('homepage_sections',['is_enabled'=>(int)$s['is_enabled']?0:1,'updated_at'=>Helpers::now()],'id=:i',['i'=>$id]); Session::flash('success','Visibility updated.'); Response::redirect('/admin/homepage'); }
        if ($action==='delete') { Database::delete('homepage_sections','id=:i',['i'=>$id]); Logger::audit('admin.homepage.deleted','homepage_sections',$id); Session::flash('success','Section removed.'); Response::redirect('/admin/homepage'); }
        if ($action==='move') {
            $dir=Request::str('direction','',4); if(!in_array($dir,['up','down'],true)) throw new AppException('Invalid direction.',422);
            Database::tx(static function() use ($id,$dir): void {
                $all=Database::all('SELECT id,sort_order FROM homepage_sections ORDER BY sort_order, created_at');
                $i=null; foreach($all as $k=>$r) if($r['id']===$id) $i=$k;
                if($i===null) return;
                $j=$dir==='up'?$i-1:$i+1; if(!isset($all[$j])) return;
                $a=(int)$all[$i]['sort_order']; $b=(int)$all[$j]['sort_order'];
                if($a===$b) { $b=$dir==='up'?$a-1:$a+1; }
                Database::update('homepage_sections',['sort_order'=>$b,'updated_at'=>Helpers::now()],'id=:i',['i'=>$all[$i]['id']]);
                Database::update('homepage_sections',['sort_order'=>$a,'updated_at'=>Helpers::now()],'id=:i',['i'=>$all[$j]['id']]);
            });
            Logger::audit('admin.homepage.reordered','homepage_sections',$id); Session::flash('success','Sections reordered.'); Response::redirect('/admin/homepage');
        }
    } catch (\JsonException $e) { Session::flash('error','Section config must be valid JSON.'); Response::redirect('/admin/homepage'); }
      catch (AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/homepage'); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Could not update the section.'); Response::redirect('/admin/homepage'); }
}
$sections=CmsRepository::sections(false);
$edit=null; $eid=Request::str('edit','',80); foreach($sections as $s) if($s['id']===$eid) $edit=$s;
admin_header(['title'=>'Homepage builder','subtitle'=>'Reorder, schedule and configure storefront sections','active'=>'homepage']);
echo '<div class="admin-split"><div class="card card-pad" style="align-self:start"><h3 style="margin-bottom:14px">'.($edit?'Edit':'Add').' section</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="save"><input type="hidden" name="section_id" value="'.Helpers::e((string)($edit['id'] ?? '')).'"><div class="form-grid">';
echo '<div class="field"><label>Section type</label><select class="select" name="section_key">';
foreach($keys as $k=>$label) echo '<option value="'.$k.'" '.(($edit['section_key'] ?? '')===$k?'selected':'').'>'.Helpers::e($label).'</option>';
echo '</select></div><div class="field"><label>Sort order</label><input class="input" type="number" name="sort_order" value="'.(int)($edit['sort_order'] ?? 0).'"></div>';
echo '<div class="field span-2"><label>Title</label><input class="input" name="title" maxlength="160" value="'.Helpers::e($edit['title'] ?? '').'"></div>';
echo '<div class="field span-2"><label>Subtitle</label><input class="input" name="subtitle" maxlength="250" value="'.Helpers::e($edit['subtitle'] ?? '').'"></div>';
echo '<div class="field span-2"><label>Config JSON</label><textarea class="textarea" name="config" style="min-height:80px">'.Helpers::e($edit['config'] ?? '{"limit":8}').'</textarea><span class="field-hint">Examples: {"limit":8}, {"category":"electronics"}, {"product_ids":["…"]}.</span></div>';
echo '<div class="field"><label>Starts at</label><input class="input" type="datetime-local" name="starts_at" value="'.Helpers::e(substr((string)($edit['starts_at'] ?? ''),0,16)).'"></div>';
echo '<div class="field"><label>Expires at</label><input class="input" type="datetime-local" name="expires_at" value="'.Helpers::e(substr((string)($edit['expires_at'] ?? ''),0,16)).'"></div>';
echo '<div class="field span-2"><label class="checkbox"><input type="checkbox" name="is_enabled" value="1" '.((int)($edit['is_enabled'] ?? 1)?'checked':'').'><span>Enabled</span></label></div></div><button class="btn btn-primary" type="submit" style="margin-top:14px">Save section</button></form></div>';
echo '<div class="card"><div class="card-header"><h3>Section order</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Section</th><th>Schedule</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
foreach($sections as $s) {
    echo '<tr><td>'.(int)$s['sort_order'].'</td><td class="cell-strong">'.Helpers::e($keys[$s['section_key']] ?? $s['section_key']).'<div class="text-subtle" style="font-size:11px">'.Helpers::e($s['title'] ?: $s['section_key']).'</div></td><td>'.($s['starts_at']||$s['expires_at']?admin_date($s,'starts_at').' → '.admin_date($s,'expires_at'):'Always').'</td><td>'.admin_bool($s,'is_enabled','Enabled','Hidden').'</td><td class="row" style="gap:4px;flex-wrap:wrap"><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($s['id']).'">Edit</a>';
    foreach(['up'=>'↑','down'=>'↓'] as $dir=>$label) echo '<form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="move"><input type="hidden" name="direction" value="'.$dir.'"><input type="hidden" name="section_id" value="'.Helpers::e($s['id']).'"><button class="btn btn-outline btn-sm" type="submit" aria-label="Move '.$dir.'">'.$label.'</button></form>';
    echo '<form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="toggle"><input type="hidden" name="section_id" value="'.Helpers::e($s['id']).'"><button class="btn btn-outline btn-sm" type="submit">Toggle</button></form>';
    echo '<form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="delete"><input type="hidden" name="section_id" value="'.Helpers::e($s['id']).'"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this section?">'.icon('trash',13).'</button></form></td></tr>';
}
echo '</tbody></table></div></div></div>';
admin_footer();
