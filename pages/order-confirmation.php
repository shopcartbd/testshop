<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Models\OrderRepository;
use AllStore\Services\MailService;
require_once APP_ROOT . '/includes/components.php';
$number = (string)($_GET['number'] ?? '');
$order = OrderRepository::findByNumber($number);
$allowed = false;
if ($order) {
    if (Auth::check()) { $allowed = (string)($order['user_id'] ?? '') === (string)Auth::id(); }
    else { $allowed = (string)Session::get('guest_order_number','') === $number && $order['user_id'] === null; }
}
$page = ['title'=>'Order confirmed · AllStore','description'=>'Your order has been received.','noindex'=>true,'body_class'=>'page-confirmation'];
require APP_ROOT . '/includes/header.php';
if (!$allowed || !$order) {
    echo '<section class="section"><div class="container">'.empty_state('lock','Order not available','This order is not linked to your session. Sign in to view your orders.','/account/orders','My orders').'</div></section>';
} else {
    $items = OrderRepository::items((string)$order['id']);
    $showDigital = Helpers::canShowDigitalDownloads($order);
    $payment = OrderRepository::payment((string)$order['id']);
    echo '<section class="section"><div class="container-narrow"><div class="text-center" style="margin-bottom:var(--sp-8)"><div class="icon-circle" style="width:76px;height:76px;margin:0 auto 18px;border-radius:50%;background:var(--c-success-soft);color:var(--c-success);display:grid;place-items:center">'.icon('check',38).'</div>';
    echo '<span class="eyebrow">Thank you for your order</span><h1 style="margin-top:10px">Order '.Helpers::e($order['order_number']).' is confirmed</h1>';
    echo '<p class="text-muted" style="margin-top:12px">We have received your order'.($order['payment_method']==='cod'?' and will collect payment on delivery.':($payment && $payment['status']==='paid'?' and your payment is confirmed.':'. Complete the secure payment to confirm it.')).'</p></div>';
    echo '<div class="card card-pad"><div class="row-between wrap" style="margin-bottom:var(--sp-5)"><div><strong>Order number</strong><div class="text-muted" style="font-size:13px">'.Helpers::e($order['order_number']).'</div></div><div><strong>Placed</strong><div class="text-muted" style="font-size:13px">'.Helpers::dateNice($order['placed_at']).'</div></div><div><strong>Status</strong><div>'.status_badge((string)$order['status']).'</div></div></div>';
    foreach ($items as $it) {
        $d = OrderRepository::itemDetails($it);
        echo '<div class="row" style="padding:10px 0;border-top:1px solid var(--c-border);gap:12px;align-items:flex-start"><a href="'.Helpers::e($d['image_url']).'" target="_blank" rel="noopener noreferrer"><img src="'.Helpers::e($d['image_url']).'" alt="" style="width:52px;height:52px;border-radius:8px;object-fit:cover"></a><div class="grow"><strong style="font-size:13px">'.Helpers::e($it['name']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::e($it['sku']).' · Qty '.(int)$it['quantity'].'</div>';
        foreach ($d['options'] as $option) { echo '<div class="text-subtle" style="font-size:11px">'.Helpers::e($option['label']).': '.Helpers::e($option['value']).'</div>'; }
        if ($showDigital && !empty($d['download_url'])) { echo '<a class="btn btn-outline btn-sm" style="margin-top:6px" href="'.Helpers::e($d['download_url']).'" target="_blank" rel="noopener noreferrer" download>'.icon('download',13).' Download digital item</a>'; }
        echo '</div><strong style="font-size:13px">'.Helpers::money((int)$it['total_cents']).'</strong></div>';
    }
    echo '<div style="margin-top:var(--sp-4)"><div class="summary-line"><span>Subtotal</span><strong>'.Helpers::money((int)$order['subtotal_cents']).'</strong></div><div class="summary-line"><span>Discount</span><strong>'.((int)$order['discount_cents'] > 0 ? '−' : '').Helpers::money((int)$order['discount_cents']).'</strong></div><div class="summary-line"><span>Shipping</span><strong>'.Helpers::money((int)$order['shipping_cents']).'</strong></div><div class="summary-line"><span>Tax</span><strong>'.Helpers::money((int)$order['tax_cents']).'</strong></div><div class="summary-line total"><span>Total paid</span><strong>'.Helpers::money((int)$order['total_cents']).'</strong></div></div></div>';
    echo '<div class="row" style="justify-content:center;gap:10px;margin-top:var(--sp-6);flex-wrap:wrap"><a class="btn btn-primary" href="'.(Auth::check()?'/account/orders/'.rawurlencode($order['order_number']):'/').'">'.(Auth::check()?'View order details':'Continue shopping').'</a><a class="btn btn-outline" href="/shop">Keep shopping</a></div>';
    if (!MailService::configured()) { echo '<p class="field-hint text-center" style="margin-top:var(--sp-5)">Email confirmation is disabled because no mail provider is configured. Your order is still saved and visible in your account.</p>'; }
    echo '</div></section>';
}
require APP_ROOT . '/includes/footer.php';
