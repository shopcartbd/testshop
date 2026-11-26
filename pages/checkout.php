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
$zoneRate = $rates[0] ?? [];
$zoneLabel = (string)($zoneRate['zone_label'] ?? '');
$zoneCost = (string)($zoneRate['cost_label'] ?? Helpers::money(0));
$zoneText = (string)($zoneRate['zone_note'] ?? 'Delivery charge is calculated automatically from your address.');
$selectArea = (string)($zoneRate['method'] ?? '') === 'select_area';
$methods = PaymentService::methods();
$bd = LocationService::publicData();
$page = [
    'title'=>'Checkout · AllStore',
    'description'=>'Secure, simple checkout with Bangladesh delivery areas and automatic delivery charges.',
    'noindex'=>true,
    'body_class'=>'page-checkout',
    'extra_css'=>['/assets/css/checkout.css'],
];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Cart','url'=>'/cart'],['name'=>'Checkout']]);
echo '<section class="section" style="padding-top:0"><div class="container">';
echo '<div class="checkout-hero"><div><span class="eyebrow">Simple · secure · no account needed</span><h1>Checkout</h1><p>Complete your details below. Delivery charge updates automatically when you choose your area.</p></div><div class="checkout-trust"><span>'.icon('lock',14).' Secure order</span><span>'.icon('truck',14).' Bangladesh delivery</span><span>'.icon('check-circle',14).' Server-verified totals</span></div></div>';
echo '<form method="post" action="/checkout" id="checkoutForm">'.Session::csrfField().'<input type="hidden" name="idem" value="'.Helpers::e($idem).'">';
echo '<div class="checkout-layout"><div>';

echo '<div class="checkout-step"><div class="step-head"><span class="step-num">1</span><h3>Customer information</h3><span class="step-sub">Required for order updates</span></div><div class="form-grid">';
$nameV = Helpers::e((string)($u['full_name'] ?? $default['full_name'] ?? ($old['full_name'] ?? Request::str('full_name'))));
$emailV = Helpers::e((string)($u['email'] ?? ($old['email'] ?? Request::str('email'))));
$phoneV = Helpers::e((string)($u['phone'] ?? $default['phone'] ?? ($old['phone'] ?? Request::str('phone'))));
echo '<div class="field"><label for="full_name">Full name *</label><input class="input" id="full_name" name="full_name" autocomplete="name" required maxlength="120" value="'.$nameV.'"></div>';
echo '<div class="field"><label for="email">Email address *</label><input class="input" type="email" id="email" name="email" autocomplete="email" required maxlength="254" value="'.$emailV.'"></div>';
echo '<div class="field span-2"><label for="phone">Phone number *</label><input class="input" type="tel" inputmode="tel" id="phone" name="phone" autocomplete="tel" required maxlength="30" value="'.$phoneV.'"></div></div></div>';

echo '<div class="checkout-step"><div class="step-head"><span class="step-num">2</span><h3>'.($needsShipping?'Delivery address':'Billing address (optional for digital orders)').'</h3><span class="step-sub">'.($needsShipping?'Courier / contact details':'No courier needed').'</span></div><div class="form-grid">';
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

echo '<div id="bdLocationFields" class="checkout-step bd-location-card"'.($country==='BD'?'':' hidden').'><div class="step-head"><span class="step-num">2</span><h3>Bangladesh delivery area</h3><span class="step-sub">Auto delivery charge</span></div>';
echo '<div id="checkoutDeliveryZone" class="delivery-zone-note"'.($zoneLabel!==''?'':' hidden').'>'.Helpers::e($zoneLabel !== '' ? $zoneLabel.' · '.$zoneCost.' — '.$zoneText : '').'</div>';
echo '<div class="form-grid">';
echo '<div class="field"><label for="division_id">Division / বিভাগ *</label><select class="select" id="division_id" name="division_id" data-selected="'.$oldVal('division_id').'" autocomplete="address-level1"><option value="">Select division / বিভাগ</option>';
foreach ($bd['divisions'] as $d) { echo '<option value="'.Helpers::e($d['id']).'" '.($oldVal('division_id')===$d['id']?'selected':'').'>'.Helpers::e($d['name']).($d['en']!==''?' · '.Helpers::e($d['en']):'').'</option>'; }
echo '</select></div>';
echo '<div class="field"><label for="district_id">District / জেলা *</label><select class="select" id="district_id" name="district_id" data-selected="'.$oldVal('district_id').'"><option value="">Select district / জেলা</option></select></div>';
echo '<div class="field"><label for="area_id">Upazila / Thana / উপজেলা *</label><select class="select" id="area_id" name="area_id" data-selected="'.$oldVal('area_id').'"><option value="">Select upazila / থানা</option></select></div>';
echo '<div class="field span-2"><span class="field-hint">Choose division → district → upazila/thana. City and region fill automatically; the exact delivery charge is calculated on the server.</span></div>';
echo '</div></div>';

echo '<div class="checkout-step"><div class="step-head"><span class="step-num">3</span><h3 id="checkoutShippingTitle">'.($needsShipping?'Shipping method':'Digital delivery — no courier needed').'</h3><span class="step-sub">'.($needsShipping?'Auto-calculated':'Instant').'</span></div><div id="checkoutShippingMethods">';
if (!$needsShipping) {
    echo '<div class="delivery-empty">This order contains only digital products. No delivery address or courier is needed.</div><input type="hidden" name="shipping_method" value="digital">';
} elseif ($selectArea) {
    echo '<div class="delivery-empty">'.Helpers::e((string)($zoneRate['estimate'] ?? 'Choose your delivery area to see the exact charge.')).'</div><input type="hidden" name="shipping_method" value="select_area">';
} else {
    foreach ($rates as $i=>$r) {
        echo '<label class="method-option"><input type="radio" name="shipping_method" value="'.Helpers::e($r['method']).'" '.($i===0?'checked':'').'><span><strong>'.Helpers::e($r['name']).' — '.Helpers::e((string)($r['cost_label'] ?? Helpers::money((int)$r['cost_cents']))).'</strong><span>'.Helpers::e($r['estimate'] ?? 'Delivery estimate will be confirmed with your order.').'</span></span></label>';
    }
}
echo '</div></div>';
echo '<div class="checkout-step"><div class="step-head"><span class="step-num">4</span><h3>Payment method</h3><span class="step-sub">Choose how to pay</span></div>';
foreach ($methods as $i=>$m) { echo '<label class="method-option"><input type="radio" name="payment_method" value="'.Helpers::e($m['id']).'" '.($i===0?'checked':'').'><span><strong>'.Helpers::e($m['name']).'</strong><span>'.Helpers::e($m['description']).'</span></span></label>'; }
if (!PaymentService::stripeConfigured()) { echo '<p class="field-hint">Online card payments activate automatically when PAYMENT_PROVIDER, PAYMENT_GATEWAY_SECRET and PAYMENT_WEBHOOK_SECRET are configured.</p>'; }
echo '</div>';
echo '<div class="checkout-step"><div class="step-head"><span class="step-num">5</span><h3>Order review</h3><span class="step-sub">Final check</span></div><div class="field"><label for="customer_note">Order note (optional)</label><textarea class="textarea" id="customer_note" name="customer_note" maxlength="1000" style="min-height:80px">'.$input('customer_note').'</textarea></div>';
echo '<div class="alert alert-info">'.icon('info',16).'<span>By placing this order you agree to our <a href="/terms" style="text-decoration:underline">Terms</a> and <a href="/privacy-policy" style="text-decoration:underline">Privacy Policy</a>. Totals are verified again on the server.</span></div></div>';
echo '</div><aside class="cart-summary card card-pad checkout-summary"><h3>Your order</h3>';
foreach ($totals['items'] as $it) {
    $img = Helpers::safeMediaUrl((string)($it['image_url'] ?? ''));
    if ($img === '') { $img = '/assets/img/products/headphones.jpg'; }
    $details = Helpers::orderItemDetails((array)($it['options'] ?? []));
    echo '<div class="checkout-item"><img src="'.Helpers::e($img).'" alt=""><div><div class="cell-strong">'.Helpers::e($it['name']).'</div><div class="text-subtle">Qty '.$it['quantity'].(!empty($it['variant_name'])?' · '.Helpers::e($it['variant_name']):'').'</div>';
    foreach ($details['options'] as $option) { echo '<div class="text-subtle">'.Helpers::e($option['label']).': '.Helpers::e($option['value']).'</div>'; }
    if (!empty($details['product_kind'])) { echo '<div class="text-subtle">Type: '.Helpers::e(ucwords(str_replace('_',' ', $details['product_kind']))).'</div>'; }
    echo '</div><strong>'.Helpers::money($it['line_total_cents']).'</strong></div>';
}
echo '<div style="margin-top:var(--sp-4)"><div class="summary-line"><span>Subtotal</span><strong data-checkout-total="subtotal">'.Helpers::money($totals['subtotal_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Discount</span><strong data-checkout-total="discount">'.((int)$totals['discount_cents'] > 0 ? '−' : '').Helpers::money($totals['discount_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Delivery</span><strong data-checkout-total="shipping">'.Helpers::money($totals['shipping_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Tax</span><strong data-checkout-total="tax">'.Helpers::money($totals['tax_cents']).'</strong></div>';
echo '<div class="summary-line total"><span>Total</span><strong data-checkout-total="total">'.Helpers::money($totals['total_cents']).'</strong></div></div>';
echo '<button class="btn btn-primary btn-block btn-lg" type="submit" style="margin-top:var(--sp-4)" data-confirm="Place this order now?">Place order '.icon('lock',16).'</button>';
echo '<div class="checkout-secure">'.icon('shield',14).' Server-verified totals · duplicate submissions prevented</div>';
echo '<p class="field-hint" style="margin-top:12px;line-height:1.6">No account is required. Duplicate submissions are prevented with a server-side idempotency key, and stock is reserved atomically.</p>';
echo '</aside></div></form></div></section>';
echo '<script src="/assets/js/bd-locations.js" defer></script><script src="/assets/js/checkout.js" defer></script>';
require APP_ROOT . '/includes/footer.php';
