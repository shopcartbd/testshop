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
use AllStore\Services\LocationService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('settings.manage');
LocationService::ensureDefaults();

$divisionIndex = static function (array $data, string $id): ?int {
    foreach ($data['divisions'] as $i => $d) { if ((string)$d['id'] === $id) { return $i; } }
    return null;
};
$districtIndex = static function (array $data, string $divisionId, string $id): ?array {
    foreach ($data['divisions'] as $di => $d) {
        if ((string)$d['id'] !== $divisionId) { continue; }
        foreach ($d['districts'] as $xi => $x) { if ((string)$x['id'] === $id) { return [$di,$xi]; } }
    }
    return null;
};
$areaIndex = static function (array $data, string $divisionId, string $districtId, string $id): ?array {
    foreach ($data['divisions'] as $di => $d) {
        if ((string)$d['id'] !== $divisionId) { continue; }
        foreach ($d['districts'] as $xi => $x) {
            if ((string)$x['id'] !== $districtId) { continue; }
            foreach ($x['areas'] as $ai => $a) { if ((string)$a['id'] === $id) { return [$di,$xi,$ai]; } }
        }
    }
    return null;
};
$findDistrictParent = static function (array $data, string $districtId): ?array {
    foreach ($data['divisions'] as $di => $d) {
        foreach ($d['districts'] as $xi => $x) { if ((string)$x['id'] === $districtId) { return [$di,$xi,(string)$d['id']]; } }
    }
    return null;
};

if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $action = Request::str('action','',30);
        $data = LocationService::all();
        $savedId = '';
        if ($action === 'reset') {
            LocationService::reset();
            Logger::audit('admin.locations.reset','settings','bd_locations_json');
            Session::flash('success','Bangladesh areas restored to the bundled 8 divisions, 64 districts and 495 upazilas.');
        } elseif ($action === 'save_division') {
            $name = trim(Request::str('name','',120)); $en = trim(Request::str('en','',120));
            if ($name === '') { throw new AppException('Division name is required.',422); }
            $id = Request::id('division_id');
            $i = $id !== '' ? $divisionIndex($data,$id) : null;
            if ($id !== '' && $i === null) { throw new AppException('Division not found.',404); }
            if ($i === null) {
                $savedId = LocationService::newId('division',$en !== '' ? $en : $name);
                $data['divisions'][] = ['id'=>$savedId,'name'=>$name,'en'=>$en,'is_active'=>1,'districts'=>[]];
            } else {
                $savedId = $id;
                $data['divisions'][$i]['name'] = $name;
                $data['divisions'][$i]['en'] = $en;
            }
            $data = LocationService::save($data);
            Logger::audit('admin.locations.division_saved','settings',$savedId);
            Session::flash('success','Division saved.');
        } elseif ($action === 'save_district') {
            $divisionId = Request::id('division_id'); $name = trim(Request::str('name','',120)); $en = trim(Request::str('en','',120));
            $di = $divisionIndex($data,$divisionId);
            if ($name === '' || $di === null) { throw new AppException('Choose a division and enter a district name.',422); }
            $id = Request::id('district_id');
            $pos = $id !== '' ? $districtIndex($data,$divisionId,$id) : null;
            if ($id !== '' && $pos === null) { throw new AppException('District not found.',404); }
            if ($pos === null) {
                $savedId = LocationService::newId('district',$en !== '' ? $en : $name,$divisionId);
                $data['divisions'][$di]['districts'][] = ['id'=>$savedId,'name'=>$name,'en'=>$en,'is_active'=>1,'areas'=>[]];
            } else {
                $savedId = $id;
                $data['divisions'][$pos[0]]['districts'][$pos[1]]['name'] = $name;
                $data['divisions'][$pos[0]]['districts'][$pos[1]]['en'] = $en;
            }
            $data = LocationService::save($data);
            Logger::audit('admin.locations.district_saved','settings',$savedId);
            Session::flash('success','District saved.');
        } elseif ($action === 'save_area') {
            $districtId = Request::id('district_id'); $name = trim(Request::str('name','',120)); $en = trim(Request::str('en','',120));
            $type = strtolower(Request::str('type','upazila',20));
            if (!in_array($type,['upazila','thana'],true)) { $type = 'upazila'; }
            $parent = $findDistrictParent($data,$districtId);
            if ($name === '' || $parent === null) { throw new AppException('Choose a district and enter an upazila/thana name.',422); }
            [$di,$xi,$divisionId] = $parent;
            $id = Request::id('area_id');
            $pos = $id !== '' ? $areaIndex($data,$divisionId,$districtId,$id) : null;
            if ($id !== '' && $pos === null) { throw new AppException('Upazila/thana not found.',404); }
            if ($pos === null) {
                $savedId = LocationService::newId($type,$en !== '' ? $en : $name,$districtId);
                $data['divisions'][$di]['districts'][$xi]['areas'][] = ['id'=>$savedId,'name'=>$name,'en'=>$en,'type'=>$type,'is_active'=>1];
            } else {
                $savedId = $id;
                $data['divisions'][$pos[0]]['districts'][$pos[1]]['areas'][$pos[2]]['name'] = $name;
                $data['divisions'][$pos[0]]['districts'][$pos[1]]['areas'][$pos[2]]['en'] = $en;
                $data['divisions'][$pos[0]]['districts'][$pos[1]]['areas'][$pos[2]]['type'] = $type;
            }
            $data = LocationService::save($data);
            Logger::audit('admin.locations.area_saved','settings',$savedId);
            Session::flash('success','Upazila / thana saved.');
        } elseif (in_array($action,['toggle_division','delete_division','toggle_district','delete_district','toggle_area','delete_area'],true)) {
            $level = match (true) {
                str_contains($action,'division') => 'division', str_contains($action,'district') => 'district', default => 'area',
            };
            $divisionId = Request::id('division_id');
            $districtId = Request::id('district_id');
            $areaId = Request::id('area_id');
            $di = $divisionIndex($data,$divisionId);
            if ($di === null) { throw new AppException('Division not found.',404); }
            $target = null;
            if ($level === 'division') { $target =& $data['divisions'][$di]; $savedId = $divisionId; }
            elseif ($level === 'district') {
                $pos = $districtIndex($data,$divisionId,$districtId);
                if ($pos === null) { throw new AppException('District not found.',404); }
                $target =& $data['divisions'][$pos[0]]['districts'][$pos[1]]; $savedId = $districtId;
            } else {
                $pos = $areaIndex($data,$divisionId,$districtId,$areaId);
                if ($pos === null) { throw new AppException('Upazila/thana not found.',404); }
                $target =& $data['divisions'][$pos[0]]['districts'][$pos[1]]['areas'][$pos[2]]; $savedId = $areaId;
            }
            if (str_starts_with($action,'toggle')) {
                $target['is_active'] = !empty($target['is_active']) ? 0 : 1;
                $data = LocationService::save($data);
                Logger::audit('admin.locations.toggled','settings',$savedId);
                Session::flash('success','Area status updated.');
            } else {
                if ($level === 'division') { array_splice($data['divisions'],$di,1); }
                elseif ($level === 'district') { array_splice($data['divisions'][$pos[0]]['districts'],$pos[1],1); }
                else { array_splice($data['divisions'][$pos[0]]['districts'][$pos[1]]['areas'],$pos[2],1); }
                $data = LocationService::save($data);
                Logger::audit('admin.locations.deleted','settings',$savedId);
                Session::flash('success','Area removed.');
            }
            unset($target);
        } else { throw new AppException('Unknown location action.',422); }
        Response::redirect('/admin/locations');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); Response::redirect('/admin/locations'); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Bangladesh areas could not be saved.'); Response::redirect('/admin/locations'); }
}

$data = LocationService::all();
$counts = LocationService::counts($data);
$editLevel = Request::str('edit_level','',20);
$editDivision = Request::str('division_id','',80);
$editDistrict = Request::str('district_id','',80);
$editArea = Request::str('area_id','',80);
$edit = ['name'=>'','en'=>'','type'=>'upazila'];
if ($editLevel === 'division' && $editDivision !== '') {
    $i = $divisionIndex($data,$editDivision);
    if ($i !== null) { $edit = $data['divisions'][$i]; }
} elseif ($editLevel === 'district') {
    $pos = $districtIndex($data,$editDivision,$editDistrict);
    if ($pos !== null) { $edit = $data['divisions'][$pos[0]]['districts'][$pos[1]]; }
} elseif ($editLevel === 'area') {
    $parent = $findDistrictParent($data,$editDistrict);
    $divisionId = $parent[2] ?? $editDivision;
    $pos = $areaIndex($data,$divisionId,$editDistrict,$editArea);
    if ($pos !== null) { $edit = $data['divisions'][$pos[0]]['districts'][$pos[1]]['areas'][$pos[2]]; }
}
$val = static fn(string $k,string $d='') => Helpers::e((string)($edit[$k] ?? $d));
$csrf = Session::csrfField();

admin_header(['title'=>'Bangladesh delivery areas','active'=>'locations']);
echo '<div class="row-between wrap" style="margin-bottom:14px"><strong>Divisions · districts · upazilas / thanas</strong><a class="btn btn-outline btn-sm" href="/admin/delivery-charges">Delivery charges '.icon('banknote',14).'</a></div>';
echo '<div class="stat-grid" style="margin-bottom:16px">';
foreach (['Divisions'=>$counts['divisions'],'Districts'=>$counts['districts'],'Upazilas / thanas'=>$counts['areas'],'Active areas'=>$counts['active_areas']] as $label=>$n) {
    echo '<div class="stat-card"><div class="stat-label">'.Helpers::e($label).'</div><div class="stat-value">'.(int)$n.'</div></div>';
}
echo '</div>';

echo '<div class="admin-grid-2"><div class="card card-pad"><h3 style="margin-bottom:12px">Add / edit division</h3><form method="post">'.$csrf.'<input type="hidden" name="action" value="save_division"><input type="hidden" name="division_id" value="'.($editLevel==='division'?Helpers::e($editDivision):'').'"><div class="form-grid"><div class="field"><label>বাংলা নাম *</label><input class="input" name="name" required maxlength="120" value="'.($editLevel==='division'?$val('name'):'').'"></div><div class="field"><label>English name</label><input class="input" name="en" maxlength="120" value="'.($editLevel==='division'?$val('en'):'').'"></div></div><button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px">Save division</button></form></div>';
echo '<div class="card card-pad"><h3 style="margin-bottom:12px">Add / edit district</h3><form method="post">'.$csrf.'<input type="hidden" name="action" value="save_district"><input type="hidden" name="district_id" value="'.($editLevel==='district'?Helpers::e($editDistrict):'').'"><div class="form-grid"><div class="field span-2"><label>Division *</label><select class="select" name="division_id" required><option value="">Choose a division</option>';
foreach ($data['divisions'] as $d) { echo '<option value="'.Helpers::e($d['id']).'" '.($editDivision===$d['id']?'selected':'').'>'.Helpers::e($d['name']).($d['en']!==''?' · '.Helpers::e($d['en']):'').'</option>'; }
echo '</select></div><div class="field"><label>বাংলা নাম *</label><input class="input" name="name" required maxlength="120" value="'.($editLevel==='district'?$val('name'):'').'"></div><div class="field"><label>English name</label><input class="input" name="en" maxlength="120" value="'.($editLevel==='district'?$val('en'):'').'"></div></div><button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px">Save district</button></form></div></div>';

echo '<div class="card card-pad" style="margin-top:16px"><h3 style="margin-bottom:12px">Add / edit upazila or thana</h3><form method="post">'.$csrf.'<input type="hidden" name="action" value="save_area"><input type="hidden" name="division_id" value="'.($editLevel==='area'?Helpers::e($editDivision):'').'"><input type="hidden" name="area_id" value="'.($editLevel==='area'?Helpers::e($editArea):'').'"><div class="form-grid"><div class="field span-2"><label>District *</label><select class="select" name="district_id" required><option value="">Choose a district</option>';
foreach ($data['divisions'] as $d) {
    echo '<optgroup label="'.Helpers::e($d['name']).'">';
    foreach ($d['districts'] as $x) { echo '<option value="'.Helpers::e($x['id']).'" '.($editDistrict===$x['id']?'selected':'').'>'.Helpers::e($x['name']).($x['en']!==''?' · '.Helpers::e($x['en']):'').'</option>'; }
    echo '</optgroup>';
}
echo '</select></div><div class="field"><label>বাংলা নাম *</label><input class="input" name="name" required maxlength="120" value="'.($editLevel==='area'?$val('name'):'').'"></div><div class="field"><label>English name</label><input class="input" name="en" maxlength="120" value="'.($editLevel==='area'?$val('en'):'').'"></div><div class="field"><label>Type</label><select class="select" name="type"><option value="upazila" '.($val('type','upazila')==='upazila'?'selected':'').'>Upazila / উপজেলা</option><option value="thana" '.($val('type')==='thana'?'selected':'').'>Thana / থানা</option></select></div></div><button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px">Save upazila / thana</button></form></div>';

echo '<div class="card" style="margin-top:16px"><div class="card-header row-between"><h3>Delivery hierarchy</h3><form method="post" style="display:inline">'.$csrf.'<input type="hidden" name="action" value="reset"><button class="btn btn-outline btn-sm" type="submit" data-confirm="Restore the bundled Bangladesh hierarchy? Your admin-added areas will be replaced.">Restore bundled data</button></form></div><div style="padding:16px">';
foreach ($data['divisions'] as $d) {
    echo '<details style="border-bottom:1px solid var(--c-border);padding:10px 0" '.((int)$d['is_active']?'open':'').'><summary style="cursor:pointer;font-weight:600">'.Helpers::e($d['name']).($d['en']!==''?' · '.Helpers::e($d['en']):'').' <span class="text-subtle" style="font-size:12px">('.count($d['districts']).' districts)</span> '.((int)$d['is_active']?'':'<span class="badge badge-warning">Inactive</span>').'</summary>';
    echo '<div class="row wrap" style="gap:6px;margin:10px 0"><a class="btn btn-outline btn-sm" href="?edit_level=division&division_id='.rawurlencode($d['id']).'">Edit division</a><form method="post" style="display:inline">'.$csrf.'<input type="hidden" name="action" value="toggle_division"><input type="hidden" name="division_id" value="'.Helpers::e($d['id']).'"><button class="btn btn-outline btn-sm" type="submit">'.((int)$d['is_active']?'Deactivate':'Activate').'</button></form><form method="post" style="display:inline">'.$csrf.'<input type="hidden" name="action" value="delete_division"><input type="hidden" name="division_id" value="'.Helpers::e($d['id']).'"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this division and everything under it?">Delete</button></form></div>';
    foreach ($d['districts'] as $x) {
        echo '<div style="margin:8px 0 8px 18px;padding:8px 12px;border-left:2px solid var(--c-border)"><strong>'.Helpers::e($x['name']).'</strong>'.($x['en']!==''?' <span class="text-subtle">'.Helpers::e($x['en']).'</span>':'').' <span class="text-subtle" style="font-size:12px">('.count($x['areas']).' areas)</span> '.((int)$x['is_active']?'':'<span class="badge badge-warning">Inactive</span>').' ';
        echo '<a class="btn btn-outline btn-sm" href="?edit_level=district&division_id='.rawurlencode($d['id']).'&district_id='.rawurlencode($x['id']).'">Edit</a> ';
        echo '<form method="post" style="display:inline">'.$csrf.'<input type="hidden" name="action" value="toggle_district"><input type="hidden" name="division_id" value="'.Helpers::e($d['id']).'"><input type="hidden" name="district_id" value="'.Helpers::e($x['id']).'"><button class="btn btn-outline btn-sm" type="submit">'.((int)$x['is_active']?'Off':'On').'</button></form> ';
        echo '<form method="post" style="display:inline">'.$csrf.'<input type="hidden" name="action" value="delete_district"><input type="hidden" name="division_id" value="'.Helpers::e($d['id']).'"><input type="hidden" name="district_id" value="'.Helpers::e($x['id']).'"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this district and its areas?">Delete</button></form>';
        echo '<div class="row wrap" style="gap:5px;margin-top:8px">';
        foreach ($x['areas'] as $a) {
            echo '<span class="badge '.(empty($a['is_active'])?'badge-warning':'badge-success').'" style="font-weight:400">'.Helpers::e($a['name']).' <small>'.Helpers::e(ucfirst($a['type'])).'</small> '
                . '<a href="?edit_level=area&division_id='.rawurlencode($d['id']).'&district_id='.rawurlencode($x['id']).'&area_id='.rawurlencode($a['id']).'">Edit</a> '
                . '<form method="post" style="display:inline">'.$csrf.'<input type="hidden" name="action" value="toggle_area"><input type="hidden" name="division_id" value="'.Helpers::e($d['id']).'"><input type="hidden" name="district_id" value="'.Helpers::e($x['id']).'"><input type="hidden" name="area_id" value="'.Helpers::e($a['id']).'"><button type="submit" style="border:0;background:none;padding:0;color:inherit;cursor:pointer;font:inherit" title="Toggle active">◌</button></form> '
                . '<form method="post" style="display:inline">'.$csrf.'<input type="hidden" name="action" value="delete_area"><input type="hidden" name="division_id" value="'.Helpers::e($d['id']).'"><input type="hidden" name="district_id" value="'.Helpers::e($x['id']).'"><input type="hidden" name="area_id" value="'.Helpers::e($a['id']).'"><button type="submit" style="border:0;background:none;padding:0;color:inherit;cursor:pointer;font:inherit" data-confirm="Delete this area?" title="Delete">×</button></form>'
                . '</span>';
        }
        echo '</div></div>';
    }
    echo '</details>';
}
echo '</div></div>';
echo '<p class="field-hint" style="margin-top:14px">Open Admin Data · CC-BY-4.0 · <a class="text-brand" href="https://github.com/open-admin-data/bangladesh-administrative-divisions" target="_blank" rel="noopener">Source</a></p>';
admin_footer();
