<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\AppException;
use AllStore\Core\Logger;
use AllStore\Services\CartService;
use AllStore\Services\CheckoutService;
use AllStore\Services\ShippingService;
use AllStore\Services\PaymentService;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/components.php';

if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $result = CheckoutService::create(Request::all());
        $number = (string)$result['order']['order_number'];
        if (!Auth::check()) { Session::set('guest_order_number', $number); }
        if (!empty($result['payment_url'])) { Response::redirect($result['payment_url']); }
        Response::redirect('/order-confirmation/' . rawurlencode($number));
    } catch (AppException $e) {
        Session::flash('error', $e->getMessage());
        foreach ($e->errors() as $field=>$msg) { Session::flash('error', $msg); }
        Response::redirect('/checkout');
    } catch (\Throwable $e) {
        Logger::exception($e);
        Session::flash('error', 'We could not complete your order. Please try again.');
        Response::redirect('/checkout');
    }
}

$totals = CartService::totals();
if ($totals['items'] === []) { Response::redirect('/cart'); }
$idem = CheckoutService::idempotencyKey();
$u = Auth::user();
$addresses = Auth::id() ? CustomerRepository::addresses((string)Auth::id()) : [];
$default = $addresses[0] ?? null;
$country = strtoupper((string)(Session::get('checkout_country') ?: ($default['country'] ?? Helpers::setting('default_country','US'))));
$rates = ShippingService::rates($country, array_sum(array_map(static fn($i)=>$i['weight_grams']*$i['quantity'], $totals['items'])), max(0,$totals['subtotal_cents']-$totals['discount_cents']));
$methods = PaymentService::methods();
$addr = $default ? (array)Helpers::decode((string)($default['line1'] ?? ''), []) : [];
$page = ['title'=>'Checkout · AllStore','description'=>'Secure checkout.','noindex'=>true,'body_class'=>'page-checkout'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Cart','url'=>'/cart'],['name'=>'Checkout']]);
echo '<section class="section" style="padding-top:0"><div class="container"><h1 style="margin-bottom:var(--sp-6)">Checkout</h1>';
echo '<form method="post" action="/checkout" id="checkoutForm">'.Session::csrfField().'<input type="hidden" name="idem" value="'.Helpers::e($idem).'">';
echo '<div class="checkout-layout"><div>';
echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">1</span><h3>Customer information</h3></div><div class="form-grid">';
$nameV = Helpers::e((string)($u['full_name'] ?? $default['full_name'] ?? Request::str('full_name')));
$emailV = Helpers::e((string)($u['email'] ?? Request::str('email')));
$phoneV = Helpers::e((string)($u['phone'] ?? $default['phone'] ?? Request::str('phone')));
echo '<div class="field"><label for="full_name">Full name *</label><input class="input" id="full_name" name="full_name" required maxlength="120" value="'.$nameV.'"></div>';
echo '<div class="field"><label for="email">Email address *</label><input class="input" type="email" id="email" name="email" required maxlength="254" value="'.$emailV.'"></div>';
echo '<div class="field span-2"><label for="phone">Phone number *</label><input class="input" id="phone" name="phone" required maxlength="30" value="'.$phoneV.'"></div></div></div>';
echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">2</span><h3>Shipping address</h3></div><div class="form-grid">';
foreach ([
  ['line1','Street address *','text',($default['line1'] ?? ''),180],['line2','Apartment, suite (optional)','text',($default['line2'] ?? ''),180],
  ['city','City *','text',($default['city'] ?? ''),80],['region','State / Region','text',($default['region'] ?? ''),80],
  ['postal_code','Postal code','text',($default['postal_code'] ?? ''),20],
] as [$n,$l,$t,$v,$max]) {
    echo '<div class="field"><label for="'.$n.'">'.$l.'</label><input class="input" type="'.$t.'" id="'.$n.'" name="'.$n.'" maxlength="'.$max.'" value="'.Helpers::e(Request::str($n,(string)$v)).'"'.($n==='line1'||$n==='city'?' required':'').'></div>';
}
echo '<div class="field"><label for="country">Country *</label><select class="select" id="country" name="country">';
foreach (['US'=>'United States','CA'=>'Canada','GB'=>'United Kingdom','DE'=>'Germany','FR'=>'France','AU'=>'Australia','BD'=>'Bangladesh','TH'=>'Thailand','IN'=>'India','SG'=>'Singapore'] as $code=>$label) {
    echo '<option value="'.$code.'" '.($country===$code?'selected':'').'>'.Helpers::e($label).'</option>';
}
echo '</select></div></div></div>';
echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">3</span><h3>Shipping method</h3></div>';
foreach ($rates as $i=>$r) { echo '<label class="method-option"><input type="radio" name="shipping_method" value="'.Helpers::e($r['method']).'" '.($i===0?'checked':'').'><span><strong>'.Helpers::e($r['name']).' — '.($r['cost_cents']>0?Helpers::money($r['cost_cents']):'Free').'</strong><span>Estimated delivery '.$r['estimate'].'</span></span></label>'; }
echo '</div>';
echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">4</span><h3>Payment method</h3></div>';
foreach ($methods as $i=>$m) { echo '<label class="method-option"><input type="radio" name="payment_method" value="'.Helpers::e($m['id']).'" '.($i===0?'checked':'').'><span><strong>'.Helpers::e($m['name']).'</strong><span>'.Helpers::e($m['description']).'</span></span></label>'; }
if (!PaymentService::stripeConfigured()) { echo '<p class="field-hint">Online card payments activate automatically when PAYMENT_PROVIDER, PAYMENT_GATEWAY_SECRET and PAYMENT_WEBHOOK_SECRET are configured.</p>'; }
echo '</div>';
echo '<div class="card card-pad"><div class="step-head"><span class="step-num">5</span><h3>Order review</h3></div><div class="field"><label for="customer_note">Order note (optional)</label><textarea class="textarea" id="customer_note" name="customer_note" maxlength="1000" style="min-height:80px"></textarea></div>';
echo '<div class="alert alert-info">'.icon('info',16).'<span>By placing this order you agree to our <a href="/terms" style="text-decoration:underline">Terms</a> and <a href="/privacy-policy" style="text-decoration:underline">Privacy Policy</a>. Totals are verified again on the server.</span></div></div>';
echo '</div><aside class="cart-summary card card-pad"><h3 style="font-size:var(--fs-md);margin-bottom:var(--sp-4)">Your order</h3>';
foreach ($totals['items'] as $it) { echo '<div class="row" style="padding:8px 0;border-bottom:1px solid var(--c-border);gap:10px"><img src="'.Helpers::e($it['image_url']).'" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px"><div class="grow" style="font-size:12px"><div class="cell-strong">'.Helpers::e($it['name']).'</div><div class="text-subtle">Qty '.$it['quantity'].'</div></div><strong style="font-size:12px">'.Helpers::money($it['line_total_cents']).'</strong></div>'; }
echo '<div style="margin-top:var(--sp-4)"><div class="summary-line"><span>Subtotal</span><strong>'.Helpers::money($totals['subtotal_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Discount</span><strong>−'.Helpers::money($totals['discount_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Shipping</span><strong>'.Helpers::money($totals['shipping_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Tax</span><strong>'.Helpers::money($totals['tax_cents']).'</strong></div>';
echo '<div class="summary-line total"><span>Total</span><strong>'.Helpers::money($totals['total_cents']).'</strong></div></div>';
echo '<button class="btn btn-primary btn-block btn-lg" type="submit" style="margin-top:var(--sp-4)" data-confirm="Place this order now?">Place order '.icon('lock',16).'</button>';
echo '<p class="field-hint" style="margin-top:12px;line-height:1.6">Duplicate submissions are prevented with a server-side idempotency key. Stock is reserved atomically.</p>';
echo '</aside></div></form></div></section>';
require APP_ROOT . '/includes/footer.php';
