<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Models\OrderRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$number = (string)($_GET['number'] ?? '');
$order = OrderRepository::findByNumberForUser($number, (string)Auth::id());
account_header('tracking','Track order '.$number,'Follow your parcel from the warehouse to your door.');
if (!$order) { echo empty_state('lock','Order not found','This order does not belong to your account.','/account/orders','My orders'); account_footer(); return; }
$history = OrderRepository::history((string)$order['id']);
$shipment = OrderRepository::shipment((string)$order['id']);
$steps = ['pending'=>'Order placed','confirmed'=>'Confirmed','processing'=>'Processing','packed'=>'Packed','shipped'=>'Shipped','delivered'=>'Delivered'];
$currentIdx = array_search($order['status'], array_keys($steps), true);
account_card_start('Progress');
echo '<div class="timeline">';
$i = 0;
foreach ($steps as $key=>$label) {
    $done = $currentIdx !== false && $i <= $currentIdx;
    echo '<div class="timeline-item '.($done?'done':'').'"><strong>'.Helpers::e($label).'</strong><span>'.($done ? 'Completed' : 'Pending').'</span></div>';
    $i++;
}
echo '</div>';
account_card_end();
echo '<div class="form-grid"><div class="card card-pad"><h3 style="font-size:var(--fs-md);margin-bottom:12px">Shipment details</h3><p class="text-muted" style="font-size:13px;line-height:1.8">Courier: '.Helpers::e($shipment['courier_name'] ?? 'To be assigned').'<br>Tracking number: '.Helpers::e($shipment['tracking_number'] ?? 'Not available yet').'<br>Estimated delivery: '.Helpers::dateNice($shipment['estimated_delivery'] ?? null).'<br>Status: '.Helpers::e(ucfirst(str_replace('_',' ',(string)($shipment['status'] ?? 'pending')))).'</p></div>';
echo '<div class="card card-pad"><h3 style="font-size:var(--fs-md);margin-bottom:12px">Timeline</h3>';
foreach ($history as $h) { echo '<div class="order-row" style="padding:8px 0">'.status_badge((string)$h['to_status']).'<small class="text-subtle">'.Helpers::dateNice($h['created_at']).'</small></div>'; }
echo '</div></div>';
account_footer();
