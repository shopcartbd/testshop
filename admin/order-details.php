<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Models\OrderRepository;
use AllStore\Services\OrderMediaService;
use AllStore\Services\DeliveryZoneService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('orders.manage');
$number = Request::str('number','',80);
$order = $number !== '' ? OrderRepository::findByNumber($number) : null;
if (!$order) { http_response_code(404); throw new AppException('Order not found.',404); }
$items = OrderRepository::items((string)$order['id']);
$statusChoices = OrderRepository::statusChoices((string)$order['status']);
$isCodOrder = strtolower((string)($order['payment_method'] ?? '')) === 'cod';
if (Request::str('download_item','',80) !== '') {
    OrderMediaService::downloadOrderItem((string)$order['id'], Request::str('download_item','',80), $number);
}
if (Request::str('export_items','',10) === 'csv') {
    Response::noCache();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="order-'.rawurlencode($number).'-items.csv"');
    echo OrderRepository::itemsCsv($items);
    exit;
}
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $action = Request::str('action','',30);
        if ($action === 'status') {
            $status = Request::str('status','',30);
            if (!in_array($status,$statusChoices,true)) throw new AppException('Choose a valid next status for this order.',422);
            OrderRepository::updateStatus((string)$order['id'],$status,Request::str('note','',500),Auth::id());
            Session::flash('success', ($isCodOrder && $status === 'confirmed' && (string)$order['payment_status'] !== 'paid')
                ? 'COD order confirmed without marking payment paid.'
                : 'Order status updated.');
        } elseif ($action === 'payment') {
            $ps = Request::str('payment_status','',20);
            if (!in_array($ps,OrderRepository::PAYMENT_STATUSES,true)) throw new AppException('Invalid payment status.',422);
            Database::tx(static function() use ($order,$ps): void {
                Database::update('orders',['payment_status'=>$ps,'updated_at'=>Helpers::now()],'id=:i',['i'=>$order['id']]);
                Database::update('payments',['status'=>$ps,'updated_at'=>Helpers::now()],'order_id=:o',['o'=>$order['id']]);
            });
            Logger::audit('order.payment_status_updated','orders',(string)$order['id'],['status'=>$ps]); Session::flash('success','Payment status updated.');
        } elseif ($action === 'shipment') {
            $tracking = trim(Request::str('tracking_number','',100));
            $courier = Request::id('courier_id'); $est = trim(Request::str('estimated_delivery','',30));
            $data = ['tracking_number'=>$tracking,'courier_id'=>$courier ?: null,'status'=>Request::str('shipment_status','pending',30),'updated_at'=>Helpers::now()];
            if ($est !== '') { $ts=strtotime($est); if (!$ts) throw new AppException('Enter a valid delivery date.',422); $data['estimated_delivery']=gmdate('Y-m-d\TH:i:s\Z',$ts); }
            $n = Database::update('shipments',$data,'order_id=:o',['o'=>$order['id']]);
            if ($n === 0) { $data['id']=Helpers::uuid(); $data['order_id']=$order['id']; $data['created_at']=Helpers::now(); Database::insert('shipments',$data); }
            Logger::audit('order.shipment_updated','shipments',(string)$order['id']); Session::flash('success','Shipment details saved.');
        }
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Update failed.'); }
    Response::redirect('/admin/orders/'.rawurlencode($number));
}
$history=OrderRepository::history((string)$order['id']);
$shipment=OrderRepository::shipment((string)$order['id']); $payment=OrderRepository::payment((string)$order['id']);
$invoice=OrderRepository::invoice((string)$order['id']); $returns=OrderRepository::returnsForOrder((string)$order['id']); $refunds=OrderRepository::refunds((string)$order['id']);
$couriers=Database::all('SELECT id,name FROM couriers WHERE is_active=1 ORDER BY name');
$addr=(array)Helpers::decode((string)$order['shipping_address'],[]);
$addressLocation=DeliveryZoneService::locationFromAddress($addr);
$zoneInfo=null;
if (strtoupper((string)($addr['country'] ?? '')) === 'BD' && (int)$order['shipping_cents'] >= 0) {
    $zoneInfo=DeliveryZoneService::classify($addressLocation);
}
$copyLines=[
    'Order: '.(string)$number,
    'Customer: '.(string)($order['customer_name'] ?? ''),
    'Phone: '.(string)($order['customer_phone'] ?? ($addr['phone'] ?? '')),
];
$realEmail=(string)($order['customer_email'] ?? '');
if ($realEmail !== '' && !str_ends_with($realEmail,'@phone.local')) { $copyLines[]='Email: '.$realEmail; }
$addressParts=array_values(array_filter([
    trim((string)($addr['line1'] ?? '')), trim((string)($addr['line2'] ?? '')),
    trim((string)($addr['area'] ?? '')), trim((string)($addr['district'] ?? '')),
    trim((string)($addr['division'] ?? '')), trim((string)($addr['postal_code'] ?? '')),
    strtoupper((string)($addr['country'] ?? '')),
], static fn($v)=>$v!==''));
if ($addressParts !== []) { $copyLines[]='Address: '.implode(', ',array_unique($addressParts)); }
if ($zoneInfo) { $copyLines[]='Delivery zone: '.$zoneInfo['label']; }
$copyLines[]='Delivery charge: '.Helpers::money((int)$order['shipping_cents']);
if (!empty($order['customer_note'])) { $copyLines[]='Note: '.(string)$order['customer_note']; }
$copyText=implode("\n",$copyLines);
admin_header(['title'=>'Order '.$number,'subtitle'=>'Fulfilment, payment and customer record','active'=>'orders']);
echo '<div class="row-between wrap" style="margin-bottom:16px"><div><h2>'.Helpers::e($number).'</h2><p class="text-muted" style="font-size:13px">Placed '.Helpers::e(Helpers::dateNice($order['placed_at'])).' · '.admin_status($order).' '.admin_status($order,'payment_status').'</p></div><a class="btn btn-outline btn-sm" href="/admin/orders">← All orders</a></div>';
echo '<div class="stat-grid" style="margin-bottom:16px"><div class="stat-card"><div class="stat-label">Total</div><div class="stat-value">'.Helpers::money((int)$order['total_cents']).'</div></div><div class="stat-card"><div class="stat-label">Items</div><div class="stat-value">'.count($items).'</div></div><div class="stat-card"><div class="stat-label">Customer</div><div class="stat-value" style="font-size:18px">'.Helpers::e($order['customer_name']).'</div></div><div class="stat-card"><div class="stat-label">Payment</div><div class="stat-value" style="font-size:18px">'.Helpers::e(ucfirst((string)$order['payment_method'])).'</div></div></div>';
echo '<div class="admin-grid-2"><div class="card card-pad"><div class="row-between" style="margin-bottom:12px"><h3>Items</h3><a class="btn btn-outline btn-sm" href="?export_items=csv">'.icon('download',13).' Download item list</a></div>';
foreach ($items as $it) {
    $d = OrderRepository::itemDetails($it);
    echo '<div class="order-row" style="align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid var(--c-border)">';
    echo '<a href="'.Helpers::e($d['image_url']).'" target="_blank" rel="noopener noreferrer"><img src="'.Helpers::e($d['image_url']).'" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover;border:1px solid var(--c-border)"></a>';
    echo '<div class="grow"><strong style="font-size:13px">'.Helpers::e($it['name']).'</strong>';
    echo '<div class="text-subtle" style="font-size:11px">SKU '.Helpers::e($it['sku']).' · Qty '.(int)$it['quantity'].' · '.Helpers::money((int)$it['unit_price_cents']).' each</div>';
    if (!empty($d['product_kind'])) { echo '<div class="text-subtle" style="font-size:11px">Type: '.Helpers::e(ucwords(str_replace('_',' ', $d['product_kind']))).'</div>'; }
    foreach ($d['options'] as $option) { echo '<div class="text-subtle" style="font-size:11px">'.Helpers::e($option['label']).': '.Helpers::e($option['value']).'</div>'; }
    if (!empty($d['bundle_items'])) {
        echo '<div class="text-subtle" style="font-size:11px">Bundle: ';
        $bundleText = [];
        foreach ($d['bundle_items'] as $b) { $bundleText[] = trim($b['sku'].' '.$b['name'].' × '.$b['qty']); }
        echo Helpers::e(implode(' · ', $bundleText)).'</div>';
    }
    echo '<div class="row wrap" style="gap:5px;margin-top:7px">';
    echo '<a class="btn btn-outline btn-sm" href="'.Helpers::e($d['image_url']).'" target="_blank" rel="noopener noreferrer">View image</a>';
    echo '<a class="btn btn-primary btn-sm" href="?download_item='.rawurlencode((string)$it['id']).'">'.icon('download',13).' Download image</a>';
    if (!empty($d['download_url'])) { echo '<a class="btn btn-outline btn-sm" href="'.Helpers::e($d['download_url']).'" target="_blank" rel="noopener noreferrer" download>Digital file</a>'; }
    if (!empty($d['product_slug'])) { echo '<a class="btn btn-ghost btn-sm" href="/product/'.rawurlencode($d['product_slug']).'" target="_blank" rel="noopener noreferrer">Live product</a>'; }
    echo '</div></div><strong>'.Helpers::money((int)$it['total_cents']).'</strong></div>';
}
echo '<div style="margin-top:14px"><div class="summary-line"><span>Subtotal</span><strong>'.Helpers::money((int)$order['subtotal_cents']).'</strong></div><div class="summary-line"><span>Discount</span><strong>'.((int)$order['discount_cents'] > 0 ? '−' : '').Helpers::money((int)$order['discount_cents']).'</strong></div><div class="summary-line"><span>Shipping</span><strong>'.Helpers::money((int)$order['shipping_cents']).'</strong></div><div class="summary-line"><span>Tax</span><strong>'.Helpers::money((int)$order['tax_cents']).'</strong></div><div class="summary-line total"><span>Total</span><strong>'.Helpers::money((int)$order['total_cents']).'</strong></div></div></div>';
echo '<div class="card card-pad"><div class="row-between" style="margin-bottom:12px"><h3>Customer & delivery</h3><button type="button" class="address-copy-btn" data-copy-address="'.Helpers::e($copyText).'">'.icon('copy',14).' Copy address</button></div>';
echo '<div class="address-card"><div class="address-card-head"><h4>'.icon('map-pin',15).' Shipping address</h4>'.($zoneInfo ? '<span class="zone-badge">'.Helpers::e($zoneInfo['label']).'</span>' : '').'</div>';
echo '<div class="address-clean"><div class="address-person"><span>Contact</span><strong>'.Helpers::e((string)($addr['full_name'] ?? $order['customer_name'] ?? '')).'</strong><div>'.Helpers::e((string)($addr['phone'] ?? $order['customer_phone'] ?? '')).'</div>';
if ($realEmail !== '' && !str_ends_with($realEmail,'@phone.local')) { echo '<div>'.Helpers::e($realEmail).'</div>'; }
echo '</div><div class="address-lines"><span>Delivery address</span>';
$addressLines=[];
foreach (['line1','line2'] as $k) { $v=trim((string)($addr[$k] ?? '')); if ($v!=='') { $addressLines[]=$v; } }
$areaParts=array_values(array_filter([trim((string)($addr['area'] ?? '')),trim((string)($addr['district'] ?? '')),trim((string)($addr['division'] ?? ''))],static fn($v)=>$v!==''));
if ($areaParts !== []) { $addressLines[]=implode(', ',array_unique($areaParts)); }
$tailParts=array_values(array_filter([trim((string)($addr['postal_code'] ?? '')),strtoupper(trim((string)($addr['country'] ?? '')))],static fn($v)=>$v!==''));
if ($tailParts !== []) { $addressLines[]=implode(', ',$tailParts); }
if ($addressLines === []) { $addressLines[]='Digital order — no courier address'; }
foreach ($addressLines as $line) { echo '<div>'.Helpers::e($line).'</div>'; }
echo '</div></div>';
echo '<div class="address-facts">';
foreach (['District'=>(string)($addr['district'] ?? ''),'Thana'=>(string)($addr['area'] ?? ''),'Division'=>(string)($addr['division'] ?? '')] as $label=>$value) {
    if (trim($value)==='') { continue; }
    echo '<div><span>'.Helpers::e($label).'</span><strong>'.Helpers::e($value).'</strong></div>';
}
if ($zoneInfo) {
    echo '<div><span>Delivery zone</span><strong>'.Helpers::e($zoneInfo['label']).'</strong></div>';
    echo '<div><span>Charge</span><strong>'.Helpers::money((int)$order['shipping_cents']).'</strong></div>';
    echo '<div class="address-zone">'.icon('truck',15).'<span>Current zone rate '.Helpers::money((int)$zoneInfo['charge_cents']).'</span></div>';
}
echo '</div>';
echo '<details class="address-copy-details"><summary>Courier copy</summary><pre class="address-copy-preview">'.Helpers::e($copyText).'</pre></details></div>';
if ($order['customer_note']) echo '<div class="alert alert-info" style="margin-top:12px">'.icon('message',16).'<span>'.Helpers::e($order['customer_note']).'</span></div>';
$statusHint = $isCodOrder
    ? 'COD orders can be confirmed while payment is Pending. Mark Paid only after cash is collected. / COD অর্ডার পেমেন্ট Pending থাকতেই Confirmed করা যাবে; নগদ সংগ্রহের আগে Paid করবেন না।'
    : 'Only valid next statuses are shown. Payment status is separate from order status. / শুধু বৈধ পরবর্তী status দেখানো হয়; পেমেন্ট status আলাদা।';
echo '<hr><h4 style="margin-bottom:10px">Status transition / COD confirmation</h4><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="status"><div class="form-grid"><div class="field"><label>New status</label><select class="select" name="status">';
foreach ($statusChoices as $s) {
    $label = ucwords(str_replace('_',' ',$s));
    if ($isCodOrder && $s === 'confirmed' && $order['status'] === 'pending') { $label .= ' — confirm COD order'; }
    echo '<option value="'.$s.'" '.(($order['status']===$s)?'selected':'').'>'.Helpers::e($label).'</option>';
}
echo '</select></div><div class="field"><label>Note</label><input class="input" name="note" maxlength="500"></div></div><button class="btn btn-primary btn-sm" type="submit">Update status</button><p class="field-hint" style="margin-top:8px">'.Helpers::e($statusHint).'</p></form></div></div>';
echo '<div class="admin-grid-2" style="margin-top:16px"><div class="card card-pad"><h3 style="margin-bottom:12px">Payment status & shipment</h3><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="payment"><div class="row"><select class="select" name="payment_status">';
foreach (OrderRepository::PAYMENT_STATUSES as $s) echo '<option value="'.$s.'" '.(($order['payment_status']===$s)?'selected':'').'>'.Helpers::e(ucwords(str_replace('_',' ',$s))).'</option>';
echo '</select><button class="btn btn-outline btn-sm" type="submit">Update payment status</button></div><p class="field-hint" style="margin-top:8px">Use Status transition above to confirm a COD order; this form only changes payment status.</p></form>';
echo '<form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="shipment"><div class="form-grid"><div class="field"><label>Courier</label><select class="select" name="courier_id"><option value="">— None —</option>';
foreach ($couriers as $c) echo '<option value="'.Helpers::e($c['id']).'" '.((($shipment['courier_id'] ?? '')===$c['id'])?'selected':'').'>'.Helpers::e($c['name']).'</option>';
echo '</select></div><div class="field"><label>Tracking number</label><input class="input" name="tracking_number" value="'.Helpers::e($shipment['tracking_number'] ?? '').'" maxlength="100"></div><div class="field"><label>Estimated delivery</label><input class="input" type="date" name="estimated_delivery" value="'.Helpers::e(substr((string)($shipment['estimated_delivery'] ?? ''),0,10)).'"></div><div class="field"><label>Shipment status</label><select class="select" name="shipment_status">';
foreach (['pending','ready','in_transit','delivered','failed'] as $s) echo '<option value="'.$s.'" '.((($shipment['status'] ?? '')===$s)?'selected':'').'>'.Helpers::e(ucwords(str_replace('_',' ',$s))).'</option>';
echo '</select></div></div><button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px">Save shipment</button></form>';
if ($invoice) echo '<p class="field-hint" style="margin-top:12px">Invoice: '.Helpers::e($invoice['invoice_number']).($invoice['file_url']?' · <a class="text-brand" href="'.Helpers::e($invoice['file_url']).'">File</a>':'').'</p>';
echo '</div><div class="card card-pad"><h3 style="margin-bottom:12px">Status history</h3><div class="timeline">';
foreach ($history as $h) echo '<div class="timeline-item done"><strong>'.Helpers::e(ucwords(str_replace('_',' ',(string)$h['to_status']))).'</strong><span>'.Helpers::dateNice($h['created_at']).($h['note']?' · '.Helpers::e($h['note']):'').'</span></div>';
echo '</div><hr><h4 style="margin-bottom:10px">Returns & refunds</h4>';
foreach ($returns as $r) echo '<div class="order-row">'.admin_status($r).'<span>'.Helpers::e($r['reason']).'</span></div>';
foreach ($refunds as $r) echo '<div class="order-row">'.admin_status($r).'<strong>'.Helpers::money((int)$r['amount_cents']).'</strong></div>';
if ($returns===[] && $refunds===[]) echo '<p class="text-muted" style="font-size:13px">No returns or refunds for this order.</p>';
echo '</div></div>';
admin_footer();
