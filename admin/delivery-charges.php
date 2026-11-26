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
            Session::flash('success','Delivery charges restored to the recommended defaults.');
        } else {
            DeliveryZoneService::save(Request::all());
            Logger::audit('admin.delivery_zones.saved','settings',DeliveryZoneService::KEY);
            Session::flash('success','Delivery charges saved. Checkout now uses the new rates.');
        }
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Delivery charges could not be saved.'); }
    Response::redirect('/admin/delivery-charges');
}

$config = DeliveryZoneService::all();
$summary = DeliveryZoneService::publicSummary();
$counts = DeliveryZoneService::previewCounts();
$csrf = Session::csrfField();
$money = static fn(int $cents): string => number_format($cents / 100, 2, '.', '');
$lines = static fn(array $rows): string => Helpers::e(implode("\n", $rows));

admin_header(['title'=>'Delivery charges','subtitle'=>'Bangladesh zone rates · automatically applied at checkout','active'=>'delivery-charges']);
echo '<div class="setup-hero"><span class="eyebrow" style="color:#8ed6b8">Bangladesh delivery</span><h2>ঢাকা · সাব-এরিয়া · ঢাকার বাহিরে</h2><p>Set three clear charges here. When a customer selects division → district → upazila/thana at checkout, the server classifies the address and shows the exact charge automatically. No new database table is needed.</p></div>';

echo '<div class="stat-grid" style="margin-bottom:16px">';
echo '<div class="stat-card"><div class="stat-label">Status</div><div class="stat-value" style="font-size:18px">'.((int)$config['enabled']===1?'Active':'Off').'</div></div>';
foreach ($summary['zones'] as $z) {
    echo '<div class="stat-card"><div class="stat-label">'.Helpers::e($z['label']).'</div><div class="stat-value">'.Helpers::e($z['charge_label']).'</div></div>';
}
echo '</div>';

echo '<form method="post">'.$csrf.'<input type="hidden" name="action" value="save"><input type="hidden" name="version" value="'.(int)$config['version'].'">';
echo '<div class="card card-pad" style="margin-bottom:16px"><div class="row-between" style="margin-bottom:14px"><div><h3 style="margin-bottom:4px">Zone charges</h3><span class="field-hint">Amounts are in the store currency. Enter 0 for free delivery in that zone.</span></div><label class="checkbox"><input type="checkbox" name="enabled" value="1" '.((int)$config['enabled']===1?'checked':'').'><span>Enable automatic zone rates</span></label></div>';
echo '<div class="delivery-rate-grid">';
foreach ([
    'dhaka_city'=>['charge_dhaka_city','ঢাকা সিটি / Dhaka city','Inside Dhaka city'],
    'dhaka_sub_area'=>['charge_dhaka_sub_area','ঢাকা সাব-এরিয়া / Dhaka sub-area','Savar, Keraniganj, nearby districts'],
    'outside_dhaka'=>['charge_outside_dhaka','ঢাকার বাহিরে / Outside Dhaka','Rest of Bangladesh'],
] as $key=>[$field,$label,$hint]) {
    echo '<div class="delivery-rate-card"><div class="zone-title">'.icon('truck',17).Helpers::e($label).'</div><div class="field"><label for="'.$field.'">Delivery charge</label><div class="money-input"><span>'.Helpers::e(Helpers::currencySymbol()).'</span><input class="input" type="number" min="0" step="0.01" id="'.$field.'" name="'.$field.'" value="'.Helpers::e($money((int)$config['rates'][$key])).'" required></div></div><span class="field-hint">'.Helpers::e($hint).'</span></div>';
}
echo '</div>';
echo '<div class="form-grid" style="margin-top:16px"><div class="field span-2"><label for="free_over">Free delivery over (0 disables)</label><input class="input" type="number" min="0" step="0.01" id="free_over" name="free_over" value="'.Helpers::e($money((int)$config['free_over'])).'"><span class="field-hint">'.icon('info',13).' Applied after discount, before tax. Current: '.Helpers::e($summary['free_over_label']).'.</span></div></div>';
echo '</div>';

echo '<div class="admin-grid-2"><div class="card card-pad"><h3 style="margin-bottom:6px">Area classification</h3><span class="field-hint">One name, English name, district, upazila or thana per line. Classification is scoped to Dhaka division: city thanas win first, then sub-area hints; everything else in Dhaka division is sub-area, and all other divisions are “Outside Dhaka”. Same-named upazilas in other divisions are never charged as Dhaka city. Add city thanas in <a class="text-brand" href="/admin/locations">Bangladesh areas</a> to use the city rate.</span><div class="form-grid" style="margin-top:14px">';
echo '<div class="field span-2"><label for="city_matches">Dhaka city matches</label><textarea class="textarea" id="city_matches" name="city_matches" rows="4" style="min-height:90px">'.$lines((array)$config['city_matches']).'</textarea></div>';
echo '<div class="field span-2"><label for="sub_area_matches">Dhaka sub-area matches</label><textarea class="textarea" id="sub_area_matches" name="sub_area_matches" rows="6" style="min-height:120px">'.$lines((array)$config['sub_area_matches']).'</textarea></div>';
echo '<div class="field span-2"><label for="outside_matches">Force “Outside Dhaka” matches (optional)</label><textarea class="textarea" id="outside_matches" name="outside_matches" rows="3" style="min-height:70px">'.$lines((array)$config['outside_matches']).'</textarea></div>';
echo '</div></div>';
echo '<div class="card card-pad"><h3 style="margin-bottom:12px">Live hierarchy preview</h3><p class="field-hint" style="margin-bottom:12px">Based on the current <a class="text-brand" href="/admin/locations">Bangladesh areas</a> hierarchy: '.(int)$counts['total'].' active delivery areas.</p><table class="zone-preview"><thead><tr><th>Zone</th><th>Rate</th><th>Areas</th></tr></thead><tbody>';
foreach ($summary['zones'] as $z) {
    echo '<tr><td><span class="zone-badge">'.Helpers::e($z['label']).'</span></td><td><strong>'.Helpers::e($z['charge_label']).'</strong></td><td class="count">'.(int)($counts[$z['key']] ?? 0).'</td></tr>';
}
echo '</tbody></table><div class="alert alert-info" style="margin-top:14px">'.icon('info',16).'<span>Checkout always revalidates the selected area and recalculates the price on the server; the browser cannot change the charge.</span></div></div></div>';
echo '<div class="row" style="margin-top:16px;gap:8px"><button class="btn btn-primary btn-lg" type="submit">'.icon('check',16).'Save delivery charges</button><a class="btn btn-outline btn-lg" href="/checkout" target="_blank" rel="noopener">Preview checkout '.icon('external',15).'</a></div></form>';
echo '<form method="post" style="margin-top:10px">'.$csrf.'<input type="hidden" name="action" value="reset"><button class="btn btn-ghost btn-sm" type="submit" data-confirm="Restore recommended delivery charges?">Restore recommended defaults</button></form>';
admin_footer();
