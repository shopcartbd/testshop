<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Models\ReportRepository;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('dashboard.view');
$d = ReportRepository::dashboard(Request::int('days',30,7,365));
$m = $d['metrics'];
$chart = $d['sales_chart'];
$max = max(1, max(array_map('intval', array_values($chart))));
$w = 720; $h = 190; $n = count($chart); $pts=[]; $i=0;
foreach ($chart as $day=>$v) { $x = $n <= 1 ? 0 : round($i/($n-1)*$w,1); $y = round($h - ((int)$v/$max)*$h,1); $pts[] = $x.','.$y; $i++; }
$stat = static function(string $label,string $value,string $iconName): string {
    return '<div class="stat-card"><div class="stat-top"><span>'.Helpers::e($label).'</span>'.icon($iconName,18).'</div><div class="stat-value">'.Helpers::e($value).'</div></div>';
};
admin_header(['title'=>'Dashboard','subtitle'=>'Store performance at a glance','active'=>'dashboard']);
echo '<div class="row-between wrap" style="margin-bottom:16px"><div><h2>Overview</h2><p class="text-muted" style="font-size:13px">Live values from the database. Private admin pages are never cached.</p></div><form method="get" class="row" style="gap:8px"><select class="select" name="days" aria-label="Report period" style="width:auto"><option value="7"'.($d['days']===7?' selected':'').'>Last 7 days</option><option value="30"'.($d['days']===30?' selected':'').'>Last 30 days</option><option value="90"'.($d['days']===90?' selected':'').'>Last 90 days</option></select><button class="btn btn-outline btn-sm" type="submit">Apply</button></form></div>';
echo '<div class="stat-grid">'.$stat('Total revenue',Helpers::money((int)$m['total_revenue_cents']),'chart').$stat('Today',Helpers::money((int)$m['today_sales_cents']),'trending').$stat('Orders',(string)(int)$m['total_orders'],'cart').$stat('Pending',(string)(int)$m['pending_orders'],'clock').$stat('Customers',(string)(int)$m['total_customers'],'users').$stat('Active products',(string)(int)$m['total_products'],'box').$stat('Low stock',(string)(int)$m['low_stock'],'package').$stat('Open tickets',(string)(int)$m['open_tickets'],'message').'</div>';
echo '<div class="admin-grid-2"><div class="card card-pad"><div class="card-header" style="padding:0 0 14px"><h3>Sales trend</h3><span class="text-muted" style="font-size:12px">Last '.$d['days'].' days</span></div><svg viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="Sales trend chart" style="width:100%;height:210px;overflow:visible"><line x1="0" y1="'.$h.'" x2="'.$w.'" y2="'.$h.'" stroke="var(--c-border)" stroke-width="1"/><polyline fill="none" stroke="var(--c-brand)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" points="'.implode(' ', $pts).'"/></svg><div class="row-between text-subtle" style="font-size:11px"><span>'.Helpers::e((string)array_key_first($chart)).'</span><span>Peak '.Helpers::money($max).'</span><span>'.Helpers::e((string)array_key_last($chart)).'</span></div></div>';
echo '<div class="card card-pad"><h3 style="margin-bottom:14px">Top products</h3>';
foreach (array_slice($d['top_products'],0,6) as $p) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($p['name']).'</strong><div class="text-subtle" style="font-size:11px">'.(int)$p['units'].' units sold</div></div><strong style="font-size:13px">'.Helpers::money((int)$p['revenue']).'</strong></div>'; }
if ($d['top_products'] === []) echo '<p class="text-muted" style="font-size:13px">No sales in this period yet.</p>';
echo '</div></div>';
echo '<div class="card" style="margin-top:16px"><div class="card-header"><h3>Recent orders</h3><a class="section-link" href="/admin/orders">View all '.icon('arrow-right',14).'</a></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Payment</th><th>Total</th><th>Date</th></tr></thead><tbody>';
foreach ($d['recent_orders'] as $o) { echo '<tr><td class="cell-strong"><a href="/admin/orders/'.rawurlencode($o['order_number']).'">'.Helpers::e($o['order_number']).'</a></td><td>'.Helpers::e($o['customer_name']).'<div class="text-subtle" style="font-size:11px">'.Helpers::e($o['customer_email']).'</div></td><td>'.admin_status($o).'</td><td>'.admin_status($o,'payment_status').'</td><td>'.Helpers::money((int)$o['total_cents']).'</td><td>'.admin_date($o,'placed_at').'</td></tr>'; }
echo '</tbody></table></div></div>';
admin_footer();
