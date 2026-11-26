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
foreach ($chart as $day=>$v) { $x = $n <= 1 ? 0 : round($i/($n-1)*$w,1); $y = round($h - ((int)$v/$max)*$h,1); $pts[]=$x.','.$y; $i++; }
$poly = implode(' ', $pts);
$area = $n > 1 ? '0,'.$h.' '.$poly.' '.$w.','.$h : '';
$kpi = static function(string $label,string $value,string $iconName,bool $accent=false): string {
    return '<div class="dash-kpi'.($accent?' accent':'').'"><div class="dash-kpi-top"><span>'.Helpers::e($label).'</span><span class="dash-icon">'.icon($iconName,18).'</span></div><strong>'.Helpers::e($value).'</strong></div>';
};
admin_header(['title'=>'Dashboard','active'=>'dashboard']);
?>
<div class="dash-head" style="justify-content:flex-end">
  <form method="get" class="dash-filter">
    <select class="select" name="days" aria-label="Report period">
      <option value="7"<?= $d['days']===7?' selected':'' ?>>7 days</option>
      <option value="30"<?= $d['days']===30?' selected':'' ?>>30 days</option>
      <option value="90"<?= $d['days']===90?' selected':'' ?>>90 days</option>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Apply</button>
  </form>
</div>
<div class="dash-kpi-grid">
  <?= $kpi('Revenue',Helpers::money((int)$m['total_revenue_cents']),'chart',true) ?>
  <?= $kpi('Today',Helpers::money((int)$m['today_sales_cents']),'trending') ?>
  <?= $kpi('Orders',(string)(int)$m['total_orders'],'cart') ?>
  <?= $kpi('Pending',(string)(int)$m['pending_orders'],'clock') ?>
  <?= $kpi('Customers',(string)(int)$m['total_customers'],'users') ?>
  <?= $kpi('Products',(string)(int)$m['total_products'],'box') ?>
  <?= $kpi('Low stock',(string)(int)$m['low_stock'],'package') ?>
  <?= $kpi('Tickets',(string)(int)$m['open_tickets'],'message') ?>
</div>
<div class="dash-panels">
  <section class="card dash-chart-card">
    <div class="dash-panel-head"><h3>Sales trend</h3><span><?= (int)$d['days'] ?> days</span></div>
    <svg viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="Sales trend" preserveAspectRatio="none">
      <defs><linearGradient id="dashFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#14624f" stop-opacity=".18"/><stop offset="1" stop-color="#14624f" stop-opacity="0"/></linearGradient></defs>
      <line x1="0" y1="<?= $h ?>" x2="<?= $w ?>" y2="<?= $h ?>" stroke="var(--c-border)" stroke-width="1"/>
      <?php if ($area !== ''): ?><polygon points="<?= Helpers::e($area) ?>" fill="url(#dashFill)"/><?php endif; ?>
      <polyline fill="none" stroke="var(--c-brand)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" points="<?= Helpers::e($poly) ?>"/>
    </svg>
    <div class="dash-chart-foot"><span><?= Helpers::e((string)array_key_first($chart)) ?></span><span><?= Helpers::e((string)array_key_last($chart)) ?></span></div>
  </section>
  <section class="card dash-list-card">
    <div class="dash-panel-head"><h3>Top products</h3><a class="section-link" href="/admin/reports">Reports <?= icon('arrow-right',14) ?></a></div>
    <div class="dash-list">
      <?php foreach (array_slice($d['top_products'],0,6) as $p): ?>
      <div class="dash-list-row"><div><strong><?= Helpers::e($p['name']) ?></strong><span><?= (int)$p['units'] ?> sold</span></div><strong><?= Helpers::money((int)$p['revenue']) ?></strong></div>
      <?php endforeach; ?>
      <?php if ($d['top_products'] === []): ?><div class="dash-empty">No sales yet</div><?php endif; ?>
    </div>
  </section>
</div>
<section class="card dash-orders">
  <div class="dash-panel-head"><h3>Recent orders</h3><a class="section-link" href="/admin/orders">View all <?= icon('arrow-right',14) ?></a></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Payment</th><th>Total</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($d['recent_orders'] as $o): ?>
    <tr><td class="cell-strong"><a href="/admin/orders/<?= rawurlencode($o['order_number']) ?>"><?= Helpers::e($o['order_number']) ?></a></td><td><?= Helpers::e($o['customer_name']) ?></td><td><?= admin_status($o) ?></td><td><?= admin_status($o,'payment_status') ?></td><td><?= Helpers::money((int)$o['total_cents']) ?></td><td><?= admin_date($o,'placed_at') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php admin_footer(); ?>
