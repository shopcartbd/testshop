<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\AppException;
use AllStore\Models\CustomerRepository;
use AllStore\Services\LocationService;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
LocationService::ensureDefaults();
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        if (Request::str('action','',20) === 'delete') {
            CustomerRepository::deleteAddress($uid, Request::id('id'));
            Session::flash('success','Address removed.');
        } else {
            $in = Request::all();
            $country = strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)($in['country'] ?? 'BD')) ?: 'BD',0,2));
            $in['country'] = $country;
            if ($country === 'BD') {
                $idFrom = static function (string $key) use ($in): string {
                    $v = trim((string)($in[$key] ?? ''));
                    return preg_match('/^[A-Za-z0-9_\-]{1,64}$/',$v) ? $v : '';
                };
                $resolved = LocationService::resolve($country, $idFrom('division_id'), $idFrom('district_id'), $idFrom('area_id'));
                if (!empty($resolved['errors']) || $resolved['location']['area_id'] === '') {
                    throw new AppException('Select your division, district and upazila/thana.', 422);
                }
                $loc = $resolved['location'];
                if (trim((string)($in['city'] ?? '')) === '') { $in['city'] = $loc['area']; }
                if (trim((string)($in['region'] ?? '')) === '') { $in['region'] = trim($loc['division'].' / '.$loc['district']); }
                $extra = trim($loc['district'].' · '.$loc['area'].($loc['area_type'] !== '' ? ' ('.$loc['area_type'].')' : ''));
                $in['line2'] = trim(trim((string)($in['line2'] ?? '')).' · '.$extra, ' ·');
            }
            CustomerRepository::saveAddress($uid, $in, Request::id('id') ?: null);
            Session::flash('success','Address saved.');
        }
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
    Response::redirect('/account/addresses');
}
$addresses = CustomerRepository::addresses($uid);
$edit = null;
if (($id = Request::id('edit')) !== '') { foreach ($addresses as $a) { if ($a['id'] === $id) { $edit = $a; } } }
$old = Request::all();
$country = strtoupper((string)($edit['country'] ?? ($old['country'] ?? LocationService::defaultCountry())));
$bd = LocationService::publicData();
account_header('addresses','Address book','Manage where your orders are delivered.');
echo '<div class="row wrap" style="gap:14px;align-items:flex-start">';
foreach ($addresses as $a) {
    echo '<div class="card card-pad" style="width:min(100%,320px)"><div class="row-between"><strong>'.Helpers::e($a['label']).'</strong>'.((int)$a['is_default_shipping']?'<span class="badge badge-brand">Default</span>':'').'</div>';
    echo '<div class="text-muted" style="font-size:13px;margin-top:10px;line-height:1.6">'.Helpers::e($a['full_name']).'<br>'.Helpers::e($a['line1']).($a['line2']?'<br>'.Helpers::e($a['line2']):'').'<br>'.Helpers::e($a['city']).($a['region']?', '.Helpers::e($a['region']):'').' '.Helpers::e($a['postal_code']).'<br>'.Helpers::e($a['country']).'<br>'.Helpers::e($a['phone']).'</div>';
    echo '<div class="row" style="margin-top:14px"><a class="btn btn-outline btn-sm" href="?edit='.Helpers::e($a['id']).'">Edit</a><form method="post" style="display:inline">'.Session::csrfField().'<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.Helpers::e($a['id']).'"><button class="btn btn-ghost btn-sm text-danger" data-confirm="Delete this address?" type="submit">Delete</button></form></div></div>';
}
echo '</div>';
if ($addresses === []) echo empty_state('map-pin','No addresses saved','Add an address to check out faster next time.');
account_card_start($edit ? 'Edit address' : 'Add a new address');
$v = static fn(string $k, string $d=''): string => Helpers::e((string)($edit[$k] ?? ($old[$k] ?? $d)));
echo '<form method="post">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e((string)($edit['id'] ?? '')).'"><div class="form-grid">';
echo '<div class="field"><label for="label">Label</label><input class="input" id="label" name="label" value="'.$v('label','Home').'" maxlength="40"></div>';
echo '<div class="field"><label for="full_name">Full name</label><input class="input" id="full_name" name="full_name" required value="'.$v('full_name').'" maxlength="120"></div>';
echo '<div class="field"><label for="phone">Phone</label><input class="input" id="phone" name="phone" required value="'.$v('phone').'" maxlength="30"></div>';
echo '<div class="field"><label for="country">Country</label><select class="select" id="country" name="country">';
foreach (['BD'=>'Bangladesh','US'=>'United States','CA'=>'Canada','GB'=>'United Kingdom','DE'=>'Germany','FR'=>'France','AU'=>'Australia','TH'=>'Thailand','IN'=>'India','SG'=>'Singapore'] as $code=>$name) {
    echo '<option value="'.$code.'" '.($country===$code?'selected':'').'>'.Helpers::e($name).'</option>';
}
echo '</select></div>';
echo '<div class="field span-2"><label for="line1">Street address</label><input class="input" id="line1" name="line1" required value="'.$v('line1').'" maxlength="180"></div>';
echo '<div class="field span-2"><label for="line2">Apartment / suite</label><input class="input" id="line2" name="line2" value="'.$v('line2').'" maxlength="180"></div>';
echo '<div class="field"><label for="city">City / Upazila</label><input class="input" id="city" name="city" required value="'.$v('city').'" maxlength="80"></div>';
echo '<div class="field"><label for="region">Division / District</label><input class="input" id="region" name="region" value="'.$v('region').'" maxlength="80"></div>';
echo '<div class="field"><label for="postal_code">Postal code</label><input class="input" id="postal_code" name="postal_code" value="'.$v('postal_code').'" maxlength="20"></div>';
echo '</div>';
echo '<div id="bdLocationFields" class="form-grid" style="margin-top:14px"'.($country==='BD'?'':' hidden').'><div class="field span-2"><h4 style="margin-bottom:8px">Bangladesh delivery area</h4><span class="field-hint">Select division → district → upazila/thana. City and region fill automatically.</span></div>';
echo '<div class="field"><label for="division_id">Division / বিভাগ</label><select class="select" id="division_id" name="division_id" data-selected="'.$v('division_id').'"><option value="">Select division / বিভাগ</option>';
foreach ($bd['divisions'] as $d) { echo '<option value="'.Helpers::e($d['id']).'" '.($v('division_id')===$d['id']?'selected':'').'>'.Helpers::e($d['name']).($d['en']!==''?' · '.Helpers::e($d['en']):'').'</option>'; }
echo '</select></div>';
echo '<div class="field"><label for="district_id">District / জেলা</label><select class="select" id="district_id" name="district_id" data-selected="'.$v('district_id').'"><option value="">Select district / জেলা</option></select></div>';
echo '<div class="field"><label for="area_id">Upazila / Thana / উপজেলা</label><select class="select" id="area_id" name="area_id" data-selected="'.$v('area_id').'"><option value="">Select upazila / থানা</option></select></div></div>';
echo '<label class="checkbox" style="margin-bottom:var(--sp-4)"><input type="checkbox" name="is_default_shipping" value="1" '.((int)($edit['is_default_shipping'] ?? 0)?'checked':'').'><span>Use as default shipping address</span></label>';
echo '<button class="btn btn-primary" type="submit">Save address</button></form>';
account_card_end();
echo '<script src="/assets/js/bd-locations.js" defer></script>';
account_footer();
