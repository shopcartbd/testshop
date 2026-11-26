<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Services\LocationService;
use AllStore\Services\DeliveryZoneService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('dashboard.view');
LocationService::ensureDefaults();
$geo = LocationService::counts();
$delivery = DeliveryZoneService::publicSummary();

admin_header(['title'=>'Setup guide','active'=>'setup-guide']);
echo '<div class="row-between wrap" style="margin-bottom:16px"><div><h2>Setup guide</h2><p class="text-muted" style="font-size:13px;margin-top:4px">Store setup in six steps</p></div><span class="badge badge-brand">'.($delivery['enabled']?'Delivery active':'Delivery off').'</span></div>';
echo '<div class="stat-grid" style="margin-bottom:16px">';
echo '<div class="stat-card"><div class="stat-label">Divisions</div><div class="stat-value">'.(int)$geo['divisions'].'</div></div>';
echo '<div class="stat-card"><div class="stat-label">Districts</div><div class="stat-value">'.(int)$geo['districts'].'</div></div>';
echo '<div class="stat-card"><div class="stat-label">Thanas</div><div class="stat-value">'.(int)$geo['areas'].'</div></div>';
echo '<div class="stat-card"><div class="stat-label">Zones</div><div class="stat-value">'.($delivery['enabled']?'3':'Off').'</div></div>';
echo '</div>';
$steps = [
    ['1','Bangladesh areas','Manage divisions, districts and thanas','/admin/locations','Areas','map'],
    ['2','Delivery charges','Set three zone rates, free-over and simple checkout','/admin/delivery-charges','Charges','banknote'],
    ['3','Products','Add physical, digital, custom or bundle products','/admin/products/new','Add product','box'],
    ['4','Orders','Review items, exact options and copy-ready addresses','/admin/orders','Orders','cart'],
    ['5','Shipping','Advanced carrier and country rules','/admin/shipping','Shipping','truck'],
    ['6','Settings','Store identity, currency and security','/admin/settings','Settings','settings'],
];
echo '<div class="setup-grid">';
foreach ($steps as [$n,$title,$text,$href,$cta,$ic]) {
    echo '<div class="setup-card"><span class="num">'.Helpers::e($n).'</span><h3>'.icon($ic,17).' '.Helpers::e($title).'</h3><p>'.Helpers::e($text).'</p><a class="btn btn-outline btn-sm" href="'.Helpers::url($href).'">'.Helpers::e($cta).' '.icon('arrow-right',13).'</a></div>';
}
echo '</div>';
echo '<div class="admin-grid-2" style="margin-top:18px"><div class="card card-pad"><h3 style="margin-bottom:12px">Current delivery</h3>';
if (!$delivery['enabled']) { echo '<div class="alert alert-warning">'.icon('alert',16).'<span>Zone rates are off.</span></div>'; }
else {
    echo '<table class="zone-preview"><thead><tr><th>Zone</th><th>Charge</th></tr></thead><tbody>';
    foreach ($delivery['zones'] as $z) { echo '<tr><td>'.Helpers::e($z['label']).'</td><td><strong>'.Helpers::e($z['charge_label']).'</strong></td></tr>'; }
    echo '</tbody></table>';
    echo '<div class="toggle-row" style="margin-top:12px"><span>Free over</span><span class="badge badge-neutral">'.Helpers::e($delivery['free_over_label']).((int)$delivery['free_over_cents']===0?' · off':'').'</span></div>';
    echo '<div class="toggle-row"><span>Simple checkout</span><span class="badge '.($delivery['simple_checkout']?'badge-brand':'badge-neutral').'">'.($delivery['simple_checkout']?'On':'Off').'</span></div>';
}
echo '</div><div class="card card-pad"><h3 style="margin-bottom:12px">Where to find things</h3>';
foreach ([
    'Overview'=>'Dashboard · Reports · Notifications',
    'Catalogue'=>'Products · Categories · Inventory',
    'Sales'=>'Orders · Customers · Coupons',
    'Content'=>'Homepage · Banners · Reviews',
    'Delivery'=>'Areas · Charges · Shipping · Couriers',
    'System'=>'Users · Roles · Settings · Setup guide',
] as $group=>$items) {
    echo '<div class="toggle-row"><span>'.Helpers::e($group).'</span><span class="badge badge-neutral">'.Helpers::e($items).'</span></div>';
}
echo '</div></div>';
admin_footer();
