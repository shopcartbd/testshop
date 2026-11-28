<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\AppException;
use AllStore\Models\OrderRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$number = (string)($_GET['number'] ?? '');
$order = OrderRepository::findByNumberForUser($number, (string)Auth::id());
if (Request::isPost() && Request::str('action','',20) === 'cancel') {
    Session::requireCsrf();
    try { OrderRepository::cancelByCustomer($number, (string)Auth::id()); Session::flash('success','Order cancelled.'); }
    catch (AppException $e) { Session::flash('error',$e->getMessage()); }
    Response::redirect('/account/orders/'.rawurlencode($number));
}
account_header('orders','Order '.$number,'Full details, status history and returns.');
if (!$order) { echo empty_state('lock','Order not found','This order does not belong to your account.','/account/orders','Back to orders'); account_footer(); return; }
$items = OrderRepository::items((string)$order['id']);
$showDigital = Helpers::canShowDigitalDownloads($order);
$history = OrderRepository::history((string)$order['id']);
$shipment = OrderRepository::shipment((string)$order['id']);
$invoice = OrderRepository::invoice((string)$order['id']);
$returns = OrderRepository::returnsForOrder((string)$order['id']);
echo '<div class="row-between wrap" style="margin-bottom:var(--sp-5)"><div class="row" style="gap:14px;align-items:center"><span class="text-subtle" style="font-size:12px">Status: '.status_badge((string)$order['status']).'</span><span class="text-subtle" style="font-size:12px">Payment: '.status_badge((string)$order['payment_status']).'</span></div><div class="row"><a class="btn btn-outline btn-sm" href="/account/tracking/'.rawurlencode($number).'">'.icon('truck',15).' Track order</a>'.($invoice?'<a class="btn btn-outline btn-sm" href="/account/orders/'.rawurlencode($number).'?invoice=1">'.icon('file',15).' Invoice</a>':'').'</div></div>';
account_card_start('Items');
foreach ($items as $it) {
    $d = OrderRepository::itemDetails($it);
    echo '<div class="row" style="padding:10px 0;border-bottom:1px solid var(--c-border);gap:12px;align-items:flex-start"><a href="'.Helpers::e($d['image_url']).'" target="_blank" rel="noopener noreferrer"><img src="'.Helpers::e($d['image_url']).'" alt="" style="width:56px;height:56px;border-radius:8px;object-fit:cover"></a><div class="grow"><strong style="font-size:13px">'.Helpers::e($it['name']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::e($it['sku']).' · Qty '.(int)$it['quantity'].'</div>';
    foreach ($d['options'] as $option) { echo '<div class="text-subtle" style="font-size:11px">'.Helpers::e($option['label']).': '.Helpers::e($option['value']).'</div>'; }
    if (!empty($d['bundle_items'])) { echo '<div class="text-subtle" style="font-size:11px">Bundle: '.Helpers::e(count($d['bundle_items'])).' included items</div>'; }
    if ($showDigital && !empty($d['download_url'])) { echo '<a class="btn btn-outline btn-sm" style="margin-top:6px" href="'.Helpers::e($d['download_url']).'" target="_blank" rel="noopener noreferrer" download>'.icon('download',13).' Download digital item</a>'; }
    echo '</div><strong style="font-size:13px">'.Helpers::money((int)$it['total_cents']).'</strong></div>';
}
echo '<div style="margin-top:var(--sp-4);max-width:320px;margin-left:auto"><div class="summary-line"><span>Subtotal</span><strong>'.Helpers::money((int)$order['subtotal_cents']).'</strong></div><div class="summary-line"><span>Discount</span><strong>'.((int)$order['discount_cents'] > 0 ? '−' : '').Helpers::money((int)$order['discount_cents']).'</strong></div><div class="summary-line"><span>Shipping</span><strong>'.Helpers::money((int)$order['shipping_cents']).'</strong></div><div class="summary-line"><span>Tax</span><strong>'.Helpers::money((int)$order['tax_cents']).'</strong></div><div class="summary-line total"><span>Total</span><strong>'.Helpers::money((int)$order['total_cents']).'</strong></div></div>';
account_card_end();
echo '<div class="form-grid" style="gap:16px">';
echo '<div class="card card-pad"><h3 style="font-size:var(--fs-md);margin-bottom:14px">Shipping address</h3><div class="text-muted" style="font-size:13px;line-height:1.7">';
$a = (array)Helpers::decode((string)$order['shipping_address'],[]);
foreach (['full_name','line1','line2','city','division','district','area','area_type','region','postal_code','country','phone'] as $k) { if (!empty($a[$k])) echo Helpers::e((string)$a[$k]).'<br>'; }
echo '</div>'.($shipment && $shipment['tracking_number'] ? '<div class="alert alert-info" style="margin-top:12px">'.icon('truck',16).'Tracking: <strong>'.Helpers::e($shipment['tracking_number']).'</strong></div>':'').'</div>';
echo '<div class="card card-pad"><h3 style="font-size:var(--fs-md);margin-bottom:14px">Status history</h3><div class="timeline">';
foreach ($history as $h) { echo '<div class="timeline-item done"><strong>'.Helpers::e(ucwords(str_replace('_',' ',(string)$h['to_status']))).'</strong><span>'.Helpers::dateNice($h['created_at']).($h['note']?' · '.Helpers::e($h['note']):'').'</span></div>'; }
echo '</div></div></div>';
if (OrderRepository::canCustomerCancel($order)) {
    echo '<div class="card card-pad" style="margin-top:var(--sp-5);border-color:var(--c-border)"><div class="row-between wrap" style="gap:12px"><div><strong style="font-size:13px">Still waiting for confirmation</strong><div class="text-subtle" style="font-size:12px;margin-top:2px">You can cancel this order until the shop confirms it. After that, please contact support.</div></div><form method="post" style="margin:0">'.Session::csrfField().'<input type="hidden" name="action" value="cancel"><button class="btn btn-danger btn-sm" type="submit" data-confirm="Cancel this order? Stock will be restored.">Cancel this order</button></form></div></div>';
} elseif (in_array((string)$order['status'], ['confirmed','processing','packed','shipped'], true)) {
    echo '<div class="alert alert-info" style="margin-top:var(--sp-5)">'.icon('check-circle',16).'<span>This order is already confirmed, so online cancellation is closed. Need help? <a href="/contact">Contact support</a> with order '.Helpers::e($number).'.</span></div>';
}
if ($order['status'] === 'delivered') {
    account_card_start('Request a return');
    echo '<form method="post" action="/account/returns"><input type="hidden" name="csrf_token" value="'.Helpers::e(Session::csrfToken()).'"><input type="hidden" name="order_number" value="'.Helpers::e($number).'"><div class="form-grid"><div class="field"><label for="order_item_id">Item</label><select class="select" id="order_item_id" name="order_item_id">';
    foreach ($items as $it) { echo '<option value="'.Helpers::e($it['id']).'">'.Helpers::e($it['name']).'</option>'; }
    echo '</select></div><div class="field"><label for="reason">Reason</label><select class="select" id="reason" name="reason"><option>Changed my mind</option><option>Item damaged</option><option>Wrong item received</option><option>Not as described</option><option>Other</option></select></div></div><button class="btn btn-primary btn-sm" type="submit">Submit return request</button></form>';
    account_card_end();
}
if ($returns !== []) { account_card_start('Return requests'); foreach ($returns as $r) { echo '<div class="order-row">'.status_badge((string)$r['status']).'<span>'.Helpers::e($r['reason']).'</span><small class="text-subtle">'.Helpers::dateNice($r['created_at']).'</small></div>'; } account_card_end(); }
account_footer();
