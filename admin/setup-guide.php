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

admin_header(['title'=>'Setup guide','subtitle'=>'A simple map of the admin panel','active'=>'setup-guide']);
echo '<div class="setup-hero"><span class="eyebrow" style="color:#8ed6b8">Start here</span><h2>Everything in one clear order</h2><p>Follow these six steps to configure the store. The left menu is grouped by purpose: Overview, Catalogue, Sales, Content, Delivery and System. Each item below opens the exact screen you need.</p></div>';
echo '<div class="stat-grid" style="margin-bottom:16px">';
echo '<div class="stat-card"><div class="stat-label">Divisions</div><div class="stat-value">'.(int)$geo['divisions'].'</div></div>';
echo '<div class="stat-card"><div class="stat-label">Districts</div><div class="stat-value">'.(int)$geo['districts'].'</div></div>';
echo '<div class="stat-card"><div class="stat-label">Upazilas / thanas</div><div class="stat-value">'.(int)$geo['areas'].'</div></div>';
echo '<div class="stat-card"><div class="stat-label">Delivery zones</div><div class="stat-value">'.($delivery['enabled']?'3':'Off').'</div></div>';
echo '</div>';

$steps = [
    ['1','Bangladesh areas','Add or edit divisions, districts, upazilas and city thanas. Customers see these as a cascading selector at checkout and in their address book.','/admin/locations','Manage areas','map'],
    ['2','Delivery charges','Set ঢাকা / সাব-এরিয়া / ঢাকার বাহিরে charges. The exact charge is calculated automatically from the selected area.','/admin/delivery-charges','Set charges','banknote'],
    ['3','Products of any kind','Add physical, digital, service, subscription, custom, bundle or variable products. Custom fields, images, bundle contents and digital files are saved with each order.','/admin/products/new','Add product','box'],
    ['4','Orders & what was ordered','Open an order to see product images, exact options, bundle contents, digital links, a copy-ready address and an item CSV.','/admin/orders','Review orders','cart'],
    ['5','Shipping zones (advanced)','For non-Bangladesh or carrier-specific rules, use classic shipping zones. Bangladesh zone rates take priority for BD checkout.','/admin/shipping','Shipping zones','truck'],
    ['6','Store settings','Store identity, currency, payment/COD flags, announcement and security. Delivery defaults are also stored safely in Settings.','/admin/settings','Open settings','settings'],
];
echo '<div class="setup-grid">';
foreach ($steps as [$n,$title,$text,$href,$cta,$ic]) {
    echo '<div class="setup-card"><span class="num">'.Helpers::e($n).'</span><h3>'.icon($ic,17).' '.Helpers::e($title).'</h3><p>'.Helpers::e($text).'</p><a class="btn btn-outline btn-sm" href="'.Helpers::url($href).'">'.Helpers::e($cta).' '.icon('arrow-right',13).'</a></div>';
}
echo '</div>';

echo '<div class="admin-grid-2" style="margin-top:18px"><div class="card card-pad"><h3 style="margin-bottom:12px">Current Bangladesh delivery</h3>';
if (!$delivery['enabled']) { echo '<div class="alert alert-warning">'.icon('alert',16).'<span>Automatic zone rates are currently off. Enable them in Delivery charges.</span></div>'; }
else {
    echo '<table class="zone-preview"><thead><tr><th>Zone</th><th>Charge</th></tr></thead><tbody>';
    foreach ($delivery['zones'] as $z) { echo '<tr><td>'.Helpers::e($z['label']).'</td><td><strong>'.Helpers::e($z['charge_label']).'</strong></td></tr>'; }
    echo '</tbody></table>';
    echo '<p class="field-hint" style="margin-top:10px">Free delivery over '.Helpers::e($delivery['free_over_label']).((int)$delivery['free_over_cents']===0?' (disabled)':'').'.</p>';
}
echo '</div><div class="card card-pad"><h3 style="margin-bottom:12px">Admin panel at a glance</h3><div class="toggle-row"><span>Overview</span><span class="badge badge-neutral">Dashboard · Setup guide · Reports</span></div><div class="toggle-row"><span>Catalogue</span><span class="badge badge-neutral">Products · Categories · Attributes</span></div><div class="toggle-row"><span>Sales</span><span class="badge badge-neutral">Orders · Customers · Coupons</span></div><div class="toggle-row"><span>Content</span><span class="badge badge-neutral">Homepage · Blog · Reviews</span></div><div class="toggle-row"><span>Delivery</span><span class="badge badge-brand">Areas · Charges · Zones · Couriers</span></div><div class="toggle-row"><span>System</span><span class="badge badge-neutral">Users · Roles · Settings · Security</span></div><div class="alert alert-info" style="margin-top:14px">'.icon('info',16).'<span>Every screen uses the same cards, buttons and status badges so it is easy to understand what to do next.</span></div></div></div>';
admin_footer();
