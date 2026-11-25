<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Models\ReportRepository;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('reports.view');
$d = ReportRepository::dashboard(Request::int('days',30,7,365));
if (Request::str('export','',10) === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="allstore-sales-report.csv"'); \AllStore\Core\Response::noCache();
    $h=fopen('php://output','w'); fputcsv($h,['date','sales_cents','orders']);
    foreach ($d['sales_chart'] as $day=>$v) { fputcsv($h,[$day,$v,$d['orders_chart'][$day] ?? 0]); } fclose($h); exit;
}
admin_header(['title'=>'Reports','subtitle'=>'Sales, catalogue and search analytics','active'=>'reports']);
echo '<div class="row-between wrap" style="margin-bottom:16px"><div><h2>Analytics</h2><p class="text-muted" style="font-size:13px">Aggregated from orders, products and search logs.</p></div><div class="row"><form method="get"><select class="select" name="days" aria-label="Report period"><option value="7"'.($d['days']===7?' selected':'').'>7 days</option><option value="30"'.($d['days']===30?' selected':'').'>30 days</option><option value="90"'.($d['days']===90?' selected':'').'>90 days</option></select><button class="btn btn-outline btn-sm" type="submit">Apply</button></form><a class="btn btn-outline btn-sm" href="?days='.$d['days'].'&export=csv">'.icon('download',15).'Export CSV</a></div></div>';
echo '<div class="stat-grid">';
foreach (['Today sales'=>'today_sales_cents','Week sales'=>'week_sales_cents','Month sales'=>'month_sales_cents','Average order'=>'average_order_value_cents','Completed orders'=>'completed_orders','Cancelled orders'=>'cancelled_orders','Out of stock'=>'out_of_stock','Open returns'=>'open_returns'] as $label=>$key) {
    $v = str_ends_with((string)$key,'_cents') ? Helpers::money((int)$d['metrics'][$key]) : (string)(int)$d['metrics'][$key];
    echo '<div class="stat-card"><div class="stat-label">'.Helpers::e($label).'</div><div class="stat-value">'.Helpers::e($v).'</div></div>';
}
echo '</div>';
echo '<div class="admin-grid-2" style="margin-top:16px"><div class="card card-pad"><h3 style="margin-bottom:14px">Revenue by category</h3>';
foreach ($d['sales_by_category'] as $r) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($r['name']).'</strong><div class="text-subtle" style="font-size:11px">'.(int)$r['units'].' units</div></div><strong>'.Helpers::money((int)$r['revenue']).'</strong></div>'; }
echo '</div><div class="card card-pad"><h3 style="margin-bottom:14px">Payment mix</h3>';
foreach ($d['sales_by_payment'] as $r) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e(ucfirst((string)$r['name'])).'</strong><div class="text-subtle" style="font-size:11px">'.(int)$r['orders'].' orders</div></div><strong>'.Helpers::money((int)$r['revenue']).'</strong></div>'; }
echo '</div></div>';
echo '<div class="card card-pad" style="margin-top:16px"><h3 style="margin-bottom:14px">Popular searches</h3><div class="admin-table-wrap" style="border:0"><table class="admin-table"><thead><tr><th>Query</th><th>Searches</th><th>Average results</th></tr></thead><tbody>';
foreach ($d['search_stats'] as $s) { echo '<tr><td>'.Helpers::e($s['query']).'</td><td>'.(int)$s['searches'].'</td><td>'.number_format((float)$s['avg_results'],1).'</td></tr>'; }
echo '</tbody></table></div></div>';
admin_footer();
