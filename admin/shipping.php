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
Auth::requireAdmin('settings.manage');
$json=static function(string $v): string { $v=trim($v); if($v==='') return '[]'; $d=json_decode($v,true,32,JSON_THROW_ON_ERROR); if(!is_array($d)) throw new AppException('Enter a JSON array.',422); return Helpers::json(array_values($d)); };
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $action=Request::str('action','',30);
        if ($action==='zone') {
            $id=Request::id('zone_id'); $name=trim(Request::str('name','',120));
            if($name==='') throw new AppException('Zone name is required.',422);
            $data=['name'=>$name,'countries'=>$json(Request::str('countries','',2000)),'regions'=>$json(Request::str('regions','',2000)),'cities'=>$json(Request::str('cities','',2000)),'is_active'=>Request::bool('is_active')?1:0,'sort_order'=>Request::int('sort_order',0,0,1000)];
            if($id!=='') { Database::update('shipping_zones',$data,'id=:i',['i'=>$id]); $saved=$id; }
            else { $saved=Helpers::uuid(); $data['id']=$saved; $data['created_at']=Helpers::now(); Database::insert('shipping_zones',$data); }
            Logger::audit('admin.shipping.zone','shipping_zones',$saved); Session::flash('success','Shipping zone saved.'); Response::redirect('/admin/shipping');
        } elseif ($action==='rate') {
            $id=Request::id('rate_id'); $zone=Request::id('zone_id'); $name=trim(Request::str('name','',120)); $method=trim(Request::str('method','',40));
            if($zone==='' || $name==='' || $method==='') throw new AppException('Zone, name and method are required.',422);
            if(!Database::first('SELECT id FROM shipping_zones WHERE id=:i',['i'=>$zone])) throw new AppException('Zone not found.',404);
            $method=preg_replace('/[^a-z0-9_]/','',strtolower($method)) ?? '';
            if($method==='') throw new AppException('Method code may contain lowercase letters, numbers and underscores.',422);
            $data=['zone_id'=>$zone,'name'=>$name,'method'=>$method,'min_weight_grams'=>Request::int('min_weight_grams',0,0,10000000),'max_weight_grams'=>Request::int('max_weight_grams',0,0,10000000),'min_order_cents'=>(int)round(Request::float('min_order',0)*100),'cost_cents'=>(int)round(Request::float('cost',0)*100),'free_above_cents'=>(int)round(Request::float('free_above',0)*100),'estimated_days_min'=>Request::int('estimated_days_min',3,0,365),'estimated_days_max'=>Request::int('estimated_days_max',7,0,365),'is_active'=>Request::bool('is_active')?1:0];
            if((int)$data['estimated_days_max'] < (int)$data['estimated_days_min']) throw new AppException('Maximum delivery days cannot be lower than minimum.',422);
            if($id!=='') { Database::update('shipping_rates',$data,'id=:i',['i'=>$id]); $saved=$id; }
            else { $saved=Helpers::uuid(); $data['id']=$saved; $data['created_at']=Helpers::now(); Database::insert('shipping_rates',$data); }
            Logger::audit('admin.shipping.rate','shipping_rates',$saved); Session::flash('success','Shipping rate saved.'); Response::redirect('/admin/shipping');
        } elseif ($action==='toggle_rate') {
            $id=Request::id('rate_id'); $r=Database::first('SELECT is_active FROM shipping_rates WHERE id=:i',['i'=>$id]); if(!$r) throw new AppException('Rate not found.',404);
            Database::update('shipping_rates',['is_active'=>(int)$r['is_active']?0:1],'id=:i',['i'=>$id]); Session::flash('success','Rate status updated.'); Response::redirect('/admin/shipping');
        } elseif ($action==='delete_rate') {
            $id=Request::id('rate_id'); Database::delete('shipping_rates','id=:i',['i'=>$id]); Logger::audit('admin.shipping.rate_deleted','shipping_rates',$id); Session::flash('success','Rate removed.'); Response::redirect('/admin/shipping');
        }
    } catch(\JsonException $e) { Session::flash('error','Countries, regions and cities must be JSON arrays, for example ["US","CA"].'); Response::redirect('/admin/shipping'); }
      catch(AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/shipping'); }
      catch(\Throwable $e) { Logger::exception($e); Session::flash('error','Shipping settings could not be saved.'); Response::redirect('/admin/shipping'); }
}
$zones=Database::all('SELECT * FROM shipping_zones ORDER BY sort_order,name');
$rates=Database::all('SELECT sr.*, sz.name AS zone_name FROM shipping_rates sr LEFT JOIN shipping_zones sz ON sz.id=sr.zone_id ORDER BY sz.name,sr.cost_cents');
admin_header(['title'=>'Shipping','subtitle'=>'Zones, rates, free-shipping thresholds and delivery estimates','active'=>'shipping']);
echo '<div class="row-between" style="margin-bottom:14px"><strong>Bangladesh rates</strong><a class="btn btn-outline btn-sm" href="/admin/delivery-charges">Delivery charges</a></div>';
echo '<div class="admin-grid-2"><div class="card card-pad"><h3 style="margin-bottom:14px">Shipping zone</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="zone"><div class="form-grid"><div class="field span-2"><label>Name *</label><input class="input" name="name" required maxlength="120" placeholder="North America"></div><div class="field span-2"><label>Countries (JSON array)</label><input class="input" name="countries" value="[&quot;BD&quot;,&quot;*&quot;]" maxlength="2000"><span class="field-hint">Use ["*"] for all countries. Regions can list Bangladesh divisions/districts; cities can list upazilas or thanas. Manage names in <a class="text-brand" href="/admin/locations">Bangladesh areas</a>.</span></div><div class="field"><label>Regions (JSON array)</label><input class="input" name="regions" value="[]" maxlength="2000"><span class="field-hint">Example: ["ঢাকা","Dhaka"]</span></div><div class="field"><label>Cities / upazilas (JSON array)</label><input class="input" name="cities" value="[]" maxlength="2000"><span class="field-hint">Example: ["সাভার","Savar"]</span></div><div class="field"><label>Sort order</label><input class="input" type="number" name="sort_order" value="0"></div><div class="field"><label class="checkbox"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label></div></div><button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px">Add zone</button></form></div>';
echo '<div class="card card-pad"><h3 style="margin-bottom:14px">Shipping rate</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="rate"><div class="form-grid"><div class="field"><label>Zone *</label><select class="select" name="zone_id" required><option value="">Choose a zone</option>';
foreach($zones as $z) echo '<option value="'.Helpers::e($z['id']).'">'.Helpers::e($z['name']).'</option>';
echo '</select></div><div class="field"><label>Label *</label><input class="input" name="name" required value="Standard Delivery" maxlength="120"></div><div class="field"><label>Method code *</label><input class="input" name="method" required value="standard" maxlength="40"></div><div class="field"><label>Cost</label><input class="input" type="number" step="0.01" min="0" name="cost" value="7.99"></div><div class="field"><label>Min weight (g)</label><input class="input" type="number" name="min_weight_grams" value="0"></div><div class="field"><label>Max weight (g)</label><input class="input" type="number" name="max_weight_grams" value="0"></div><div class="field"><label>Min order</label><input class="input" type="number" step="0.01" name="min_order" value="0"></div><div class="field"><label>Free above</label><input class="input" type="number" step="0.01" name="free_above" value="0"></div><div class="field"><label>ETA min days</label><input class="input" type="number" name="estimated_days_min" value="3"></div><div class="field"><label>ETA max days</label><input class="input" type="number" name="estimated_days_max" value="7"></div><div class="field"><label class="checkbox"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label></div></div><button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px">Add rate</button></form></div></div>';
echo '<div class="card" style="margin-top:16px"><div class="card-header"><h3>Configured rates</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Zone</th><th>Label</th><th>Method</th><th>Cost</th><th>Free above</th><th>ETA</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
foreach($rates as $r) { echo '<tr><td>'.Helpers::e($r['zone_name'] ?? '—').'</td><td class="cell-strong">'.Helpers::e($r['name']).'</td><td>'.Helpers::e($r['method']).'</td><td>'.Helpers::money((int)$r['cost_cents']).'</td><td>'.((int)$r['free_above_cents']>0?Helpers::money((int)$r['free_above_cents']):'—').'</td><td>'.(int)$r['estimated_days_min'].'–'.(int)$r['estimated_days_max'].' days</td><td>'.admin_bool($r,'is_active').'</td><td class="row" style="gap:5px"><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="toggle_rate"><input type="hidden" name="rate_id" value="'.Helpers::e($r['id']).'"><button class="btn btn-outline btn-sm" type="submit">Toggle</button></form><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="delete_rate"><input type="hidden" name="rate_id" value="'.Helpers::e($r['id']).'"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this rate?">'.icon('trash',13).'</button></form></td></tr>'; }
echo '</tbody></table></div></div>';
admin_footer();
