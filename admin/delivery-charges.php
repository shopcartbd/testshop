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
use AllStore\Services\DeliveryZoneService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('settings.manage');
LocationService::ensureDefaults();

if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $action = Request::str('action','save',20);
        if ($action === 'reset') {
            DeliveryZoneService::reset();
            Logger::audit('admin.delivery_zones.reset','settings',DeliveryZoneService::KEY);
            Session::flash('success','Defaults restored.');
        } else {
            DeliveryZoneService::save(Request::all());
            Logger::audit('admin.delivery_zones.saved','settings',DeliveryZoneService::KEY);
            Session::flash('success','Saved.');
        }
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Could not save.'); }
    Response::redirect('/admin/delivery-charges');
}

$config = DeliveryZoneService::all();
$summary = DeliveryZoneService::publicSummary();
$freeOverIgnored = (bool)($summary['free_over_ignored'] ?? false);
$counts = DeliveryZoneService::previewCounts();
$csrf = Session::csrfField();
$money = static fn(int $cents): string => number_format($cents / 100, 2, '.', '');
$lines = static fn(array $rows): string => Helpers::e(implode("\n", $rows));

admin_header(['title'=>'Delivery charges','active'=>'delivery-charges']);
echo '<div class="row-between wrap" style="margin-bottom:16px"><h2>Bangladesh delivery</h2><span class="badge '.((int)$config['enabled']===1?'badge-brand':'badge-neutral').'">'.((int)$config['enabled']===1?'Active':'Off').'</span></div>';
if ($freeOverIgnored) {
    echo '<div class="alert alert-warning" style="margin-bottom:16px">'.icon('alert',16).' <strong>Free delivery value ignored:</strong> it is saved as '.Helpers::e(Helpers::money((int)$summary['free_over_saved_cents'])).', which is below the cheapest zone charge ('.Helpers::e(Helpers::money((int)$summary['minimum_charge_cents'])).'), so customers pay the normal zone charge. Set a realistic threshold or 0 to turn free delivery off.</div>';
}
echo '<form method="post">'.$csrf.'<input type="hidden" name="action" value="save"><input type="hidden" name="version" value="'.(int)$config['version'].'">';
echo '<div class="card card-pad" style="margin-bottom:16px"><div class="row-between wrap" style="margin-bottom:14px"><h3>Zone charges</h3><label class="checkbox"><input type="checkbox" name="enabled" value="1" '.((int)$config['enabled']===1?'checked':'').'><span>Enable</span></label></div>';
echo '<div class="delivery-rate-grid">';
foreach ([
    'dhaka_city'=>['charge_dhaka_city','ঢাকা সিটি / Dhaka city'],
    'dhaka_sub_area'=>['charge_dhaka_sub_area','ঢাকা সাব-এরিয়া / Dhaka sub-area'],
    'outside_dhaka'=>['charge_outside_dhaka','ঢাকার বাহিরে / Outside Dhaka'],
] as $key=>[$field,$label]) {
    echo '<div class="delivery-rate-card"><div class="zone-title">'.icon('truck',17).Helpers::e($label).'</div><div class="field"><label for="'.$field.'">Charge</label><div class="money-input"><span>'.Helpers::e(Helpers::currencySymbol()).'</span><input class="input" type="number" min="0" step="0.01" id="'.$field.'" name="'.$field.'" value="'.Helpers::e($money((int)$config['rates'][$key])).'" required></div></div></div>';
}
echo '</div>';
echo '<div class="form-grid" style="margin-top:16px"><div class="field"><label for="free_over">Free delivery over</label><input class="input" type="number" min="0" step="0.01" id="free_over" name="free_over" value="'.Helpers::e($money((int)$config['free_over'])).'"><p class="field-hint">0 (or empty) = off. It must be at least the cheapest zone charge ('.Helpers::e(Helpers::money((int)$summary['minimum_charge_cents'])).'), otherwise every order would qualify for free delivery and the value is ignored.</p></div>';
echo '<div class="field"><label>Checkout</label><label class="checkbox" style="min-height:42px"><input type="checkbox" name="simple_bd_checkout" value="1" '.((int)$config['simple_bd_checkout']===1?'checked':'').'><span>Simple Bangladesh checkout</span></label></div></div>';
echo '<p class="field-hint" style="margin-top:6px">Name · phone · district · thana · full address only when the store default is Bangladesh.</p>';
echo '</div>';

echo '<details class="admin-advanced"><summary>Area matching &amp; preview</summary><div class="admin-grid-2" style="margin-top:14px"><div class="card card-pad"><h3 style="margin-bottom:12px">Area classification</h3><div class="form-grid">';
echo '<div class="field span-2"><label for="city_matches">Dhaka city matches</label><textarea class="textarea" id="city_matches" name="city_matches" rows="4">'.$lines((array)$config['city_matches']).'</textarea></div>';
echo '<div class="field span-2"><label for="sub_area_matches">Dhaka sub-area matches</label><textarea class="textarea" id="sub_area_matches" name="sub_area_matches" rows="5">'.$lines((array)$config['sub_area_matches']).'</textarea></div>';
echo '<div class="field span-2"><label for="outside_matches">Force outside Dhaka</label><textarea class="textarea" id="outside_matches" name="outside_matches" rows="3">'.$lines((array)$config['outside_matches']).'</textarea></div>';
echo '</div></div>';
echo '<div class="card card-pad"><h3 style="margin-bottom:12px">Live hierarchy preview</h3><table class="zone-preview"><thead><tr><th>Zone</th><th>Rate</th><th>Areas</th></tr></thead><tbody>';
foreach ($summary['zones'] as $z) {
    echo '<tr><td><span class="zone-badge">'.Helpers::e($z['label']).'</span></td><td><strong>'.Helpers::e($z['charge_label']).'</strong></td><td class="count">'.(int)($counts[$z['key']] ?? 0).'</td></tr>';
}
echo '</tbody></table></div></div></details>';
echo '<div class="row wrap" style="margin-top:16px;gap:8px"><button class="btn btn-primary btn-lg" type="submit">'.icon('check',16).'Save</button><a class="btn btn-outline btn-lg" href="/checkout" target="_blank" rel="noopener">Preview '.icon('external',15).'</a><a class="btn btn-ghost" href="/admin/setup-guide">Setup guide</a></div></form>';
echo '<form method="post" style="margin-top:10px">'.$csrf.'<input type="hidden" name="action" value="reset"><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Restore defaults?">Restore defaults</button></form>';
admin_footer();
