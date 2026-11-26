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
use AllStore\Services\LocationService;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/components.php';

LocationService::ensureDefaults();
if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $result = CheckoutService::create(Request::all());
        $number = (string)$result['order']['order_number'];
        if (!Auth::check()) { Session::set('guest_order_number', $number); }
        Session::forget('checkout_old');
        if (!empty($result['payment_url'])) { Response::redirect($result['payment_url']); }
        Response::redirect('/order-confirmation/' . rawurlencode($number));
    } catch (AppException $e) {
        Session::set('checkout_old', Request::all());
        Session::flash('error', $e->getMessage());
        foreach ($e->errors() as $field=>$msg) { Session::flash('error', $msg); }
        Response::redirect('/checkout');
    } catch (\Throwable $e) {
        Session::set('checkout_old', Request::all());
        Logger::exception($e);
        Session::flash('error', 'We could not complete your order. Please try again.');
        Response::redirect('/checkout');
    }
}

$old = (array)Session::pull('checkout_old', []);
$input = static fn(string $k, string $d = ''): string => Helpers::e(Request::str($k, (string)($old[$k] ?? $d)));
$oldVal = static fn(string $k, string $d = ''): string => Helpers::e((string)($old[$k] ?? $d));
$totals = CartService::totals();
if ($totals['items'] === []) { Response::redirect('/cart'); }
$idem = CheckoutService::idempotencyKey();
$u = Auth::user();
$addresses = Auth::id() ? CustomerRepository::addresses((string)Auth::id()) : [];
$default = $addresses[0] ?? null;
$sessionCountry = strtoupper((string)(Session::get('checkout_country') ?? ''));
$addressCountry = strtoupper((string)($default['country'] ?? ''));
$country = strtoupper((string)($old['country'] ?? ($sessionCountry !== '' && $sessionCountry !== 'US' ? $sessionCountry : ($addressCountry !== '' && $addressCountry !== 'US' ? $addressCountry : LocationService::defaultCountry()))));
$resolved = LocationService::resolve($country, (string)($old['division_id'] ?? ''), (string)($old['district_id'] ?? ''), (string)($old['area_id'] ?? ''));
$location = $resolved['location'];
$location['digital_only'] = !CartService::needsShipping();
$totals = CartService::totals(null, $country, $location);
$needsShipping = (bool)($totals['requires_shipping'] ?? true);
$location['digital_only'] = !$needsShipping;
$weight = array_sum(array_map(static fn($i) => $i['weight_grams'] * $i['quantity'], $totals['items']));
$rates = ShippingService::rates($country, $weight, max(0, $totals['subtotal_cents'] - $totals['discount_cents']), $location);
$methods = PaymentService::methods();
$bd = LocationService::publicData();
$page = ['title'=>'Checkout · AllStore','description'=>'Secure, simple checkout with Bangladesh delivery areas.','noindex'=>true,'body_class'=>'page-checkout'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Cart','url'=>'/cart'],['name'=>'Checkout']]);
echo '<section class="section" style="padding-top:0"><div class="container"><h1 style="margin-bottom:var(--sp-6)">Checkout</h1>';
echo '<form method="post" action="/checkout" id="checkoutForm">'.Session::csrfField().'<input type="hidden" name="idem" value="'.Helpers::e($idem).'">';
echo '<div class="checkout-layout"><div>';
echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">1</span><h3>Customer information</h3></div><div class="form-grid">';
$nameV = Helpers::e((string)($u['full_name'] ?? $default['full_name'] ?? ($old['full_name'] ?? Request::str('full_name'))));
$emailV = Helpers::e((string)($u['email'] ?? ($old['email'] ?? Request::str('email'))));
$phoneV = Helpers::e((string)($u['phone'] ?? $default['phone'] ?? ($old['phone'] ?? Request::str('phone'))));
echo '<div class="field"><label for="full_name">Full name *</label><input class="input" id="full_name" name="full_name" autocomplete="name" required maxlength="120" value="'.$nameV.'"></div>';
echo '<div class="field"><label for="email">Email address *</label><input class="input" type="email" id="email" name="email" autocomplete="email" required maxlength="254" value="'.$emailV.'"></div>';
echo '<div class="field span-2"><label for="phone">Phone number *</label><input class="input" type="tel" inputmode="tel" id="phone" name="phone" autocomplete="tel" required maxlength="30" value="'.$phoneV.'"></div></div></div>';

echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">2</span><h3>'.($needsShipping?'Shipping address':'Billing address (optional for digital orders)').'</h3></div><div class="form-grid">';
foreach ([
  ['line1','Street address'.($needsShipping?' *':''),'text',($default['line1'] ?? ''),180,'address-line1',($needsShipping?' required':'')],
  ['line2','Apartment, suite (optional)','text',($default['line2'] ?? ''),180,'address-line2',''],
  ['city','City / Upazila'.($needsShipping?' *':''),'text',($default['city'] ?? ''),80,'address-level2',($needsShipping?' required':'')],
  ['region','Division / District (optional)','text',($default['region'] ?? ''),80,'address-level1',''],
  ['postal_code','Postal code','text',($default['postal_code'] ?? ''),20,'postal-code',''],
] as [$n,$l,$t,$v,$max,$auto,$required]) {
    echo '<div class="field"><label for="'.$n.'">'.$l.'</label><input class="input" type="'.$t.'" id="'.$n.'" name="'.$n.'" autocomplete="'.$auto.'" maxlength="'.$max.'" value="'.$input($n,(string)$v).'"'.$required.'></div>';
}
echo '<div class="field"><label for="country">Country *</label><select class="select" id="country" name="country" autocomplete="country">';
foreach (['BD'=>'Bangladesh','US'=>'United States','CA'=>'Canada','GB'=>'United Kingdom','DE'=>'Germany','FR'=>'France','AU'=>'Australia','TH'=>'Thailand','IN'=>'India','SG'=>'Singapore'] as $code=>$label) {
    echo '<option value="'.$code.'" '.($country===$code?'selected':'').'>'.Helpers::e($label).'</option>';
}
echo '</select></div>';
if (Auth::id() && $needsShipping) {
    echo '<div class="field span-2"><label class="checkbox"><input type="checkbox" name="save_address" value="1" '.(!empty($old['save_address'])?'checked':'').'><span>Save this address for faster checkout next time</span></label></div>';
}
echo '</div></div>';

echo '<div id="bdLocationFields" class="card card-pad" style="margin-bottom:var(--sp-5)"'.($country==='BD'?'':' hidden').'><div class="step-head"><span class="step-num">2</span><h3>Bangladesh delivery area</h3></div><div class="form-grid">';
echo '<div class="field"><label for="division_id">Division / বিভাগ *</label><select class="select" id="division_id" name="division_id" data-selected="'.$oldVal('division_id').'" autocomplete="address-level1"><option value="">Select division / বিভাগ</option>';
foreach ($bd['divisions'] as $d) { echo '<option value="'.Helpers::e($d['id']).'" '.($oldVal('division_id')===$d['id']?'selected':'').'>'.Helpers::e($d['name']).($d['en']!==''?' · '.Helpers::e($d['en']):'').'</option>'; }
echo '</select></div>';
echo '<div class="field"><label for="district_id">District / জেলা *</label><select class="select" id="district_id" name="district_id" data-selected="'.$oldVal('district_id').'"><option value="">Select district / জেলা</option></select></div>';
echo '<div class="field"><label for="area_id">Upazila / Thana / উপজেলা *</label><select class="select" id="area_id" name="area_id" data-selected="'.$oldVal('area_id').'"><option value="">Select upazila / থানা</option></select></div>';
echo '<div class="field span-2"><span class="field-hint">Choose division → district → upazila/thana. City and region fill automatically and delivery rates update without reloading. Admin can add or edit these areas.</span></div>';
echo '</div></div>';

echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">3</span><h3 id="checkoutShippingTitle">'.($needsShipping?'Shipping method':'Digital delivery — no courier needed').'</h3></div><div id="checkoutShippingMethods">';
if (!$needsShipping) {
    echo '<p class="text-muted" style="font-size:13px">This order contains only digital products. No delivery address or courier is needed.</p><input type="hidden" name="shipping_method" value="digital">';
} else {
    foreach ($rates as $i=>$r) {
        echo '<label class="method-option"><input type="radio" name="shipping_method" value="'.Helpers::e($r['method']).'" '.($i===0?'checked':'').'><span><strong>'.Helpers::e($r['name']).' — '.($r['cost_cents']>0?Helpers::money($r['cost_cents']):'Free').'</strong><span>'.Helpers::e($r['estimate'] ?? 'Delivery estimate will be confirmed with your order.').'</span></span></label>';
    }
}
echo '</div></div>';
echo '<div class="card card-pad" style="margin-bottom:var(--sp-5)"><div class="step-head"><span class="step-num">4</span><h3>Payment method</h3></div>';
foreach ($methods as $i=>$m) { echo '<label class="method-option"><input type="radio" name="payment_method" value="'.Helpers::e($m['id']).'" '.($i===0?'checked':'').'><span><strong>'.Helpers::e($m['name']).'</strong><span>'.Helpers::e($m['description']).'</span></span></label>'; }
if (!PaymentService::stripeConfigured()) { echo '<p class="field-hint">Online card payments activate automatically when PAYMENT_PROVIDER, PAYMENT_GATEWAY_SECRET and PAYMENT_WEBHOOK_SECRET are configured.</p>'; }
echo '</div>';
echo '<div class="card card-pad"><div class="step-head"><span class="step-num">5</span><h3>Order review</h3></div><div class="field"><label for="customer_note">Order note (optional)</label><textarea class="textarea" id="customer_note" name="customer_note" maxlength="1000" style="min-height:80px">'.$input('customer_note').'</textarea></div>';
echo '<div class="alert alert-info">'.icon('info',16).'<span>By placing this order you agree to our <a href="/terms" style="text-decoration:underline">Terms</a> and <a href="/privacy-policy" style="text-decoration:underline">Privacy Policy</a>. Totals are verified again on the server.</span></div></div>';
echo '</div><aside class="cart-summary card card-pad"><h3 style="font-size:var(--fs-md);margin-bottom:var(--sp-4)">Your order</h3>';
foreach ($totals['items'] as $it) {
    $img = Helpers::safeMediaUrl((string)($it['image_url'] ?? ''));
    if ($img === '') { $img = '/assets/img/products/headphones.jpg'; }
    $details = Helpers::orderItemDetails((array)($it['options'] ?? []));
    echo '<div class="row" style="padding:8px 0;border-bottom:1px solid var(--c-border);gap:10px"><img src="'.Helpers::e($img).'" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px"><div class="grow" style="font-size:12px"><div class="cell-strong">'.Helpers::e($it['name']).'</div><div class="text-subtle">Qty '.$it['quantity'].(!empty($it['variant_name'])?' · '.Helpers::e($it['variant_name']):'').'</div>';
    foreach ($details['options'] as $option) { echo '<div class="text-subtle">'.Helpers::e($option['label']).': '.Helpers::e($option['value']).'</div>'; }
    if (!empty($details['product_kind'])) { echo '<div class="text-subtle">Type: '.Helpers::e(ucwords(str_replace('_',' ', $details['product_kind']))).'</div>'; }
    echo '</div><strong style="font-size:12px">'.Helpers::money($it['line_total_cents']).'</strong></div>';
}
echo '<div style="margin-top:var(--sp-4)"><div class="summary-line"><span>Subtotal</span><strong data-checkout-total="subtotal">'.Helpers::money($totals['subtotal_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Discount</span><strong data-checkout-total="discount">−'.Helpers::money($totals['discount_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Shipping</span><strong data-checkout-total="shipping">'.Helpers::money($totals['shipping_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Tax</span><strong data-checkout-total="tax">'.Helpers::money($totals['tax_cents']).'</strong></div>';
echo '<div class="summary-line total"><span>Total</span><strong data-checkout-total="total">'.Helpers::money($totals['total_cents']).'</strong></div></div>';
echo '<button class="btn btn-primary btn-block btn-lg" type="submit" style="margin-top:var(--sp-4)" data-confirm="Place this order now?">Place order '.icon('lock',16).'</button>';
echo '<p class="field-hint" style="margin-top:12px;line-height:1.6">No account is required. Duplicate submissions are prevented with a server-side idempotency key, and stock is reserved atomically.</p>';
echo '</aside></div></form></div></section>';
echo '<script src="/assets/js/bd-locations.js" defer></script><script src="/assets/js/checkout.js" defer></script>';
require APP_ROOT . '/includes/footer.php';
