<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Session;
use AllStore\Services\CartService;
use AllStore\Services\ShippingService;
require_once APP_ROOT . '/includes/components.php';
$totals = CartService::totals();
$items = $totals['items'];
$saved = CartService::items(true);
$page = ['title'=>'Your cart · AllStore','description'=>'Review your cart before checkout.','noindex'=>true,'body_class'=>'page-cart'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Shopping cart']]);
echo '<section class="section" style="padding-top:0"><div class="container"><h1 style="margin-bottom:var(--sp-6)">Shopping cart</h1>';
if ($items === []) {
    echo empty_state('cart','Your cart is empty','Discover something worth keeping from the AllStore collection.','/shop','Start shopping');
} else {
echo '<div class="cart-layout"><div><div class="card card-pad">';
foreach ($items as $it) {
    echo '<div class="cart-item"><a href="'.Helpers::url('/product/'.$it['slug']).'"><img src="'.Helpers::e($it['image_url']).'" alt="'.Helpers::e($it['name']).'" loading="lazy"></a>';
    echo '<div><a class="row-title" style="font-weight:600" href="'.Helpers::url('/product/'.$it['slug']).'">'.Helpers::e($it['name']).'</a>';
    echo '<div class="text-muted" style="font-size:12px;margin-top:4px">'.Helpers::e($it['sku']).($it['variant_name'] ? ' · '.Helpers::e($it['variant_name']) : '').'</div>';
    if (!$it['available']) { echo '<div class="badge badge-danger" style="margin-top:8px">Unavailable — remove to checkout</div>'; }
    echo '<div class="row" style="margin-top:12px;gap:14px;flex-wrap:wrap"><div class="qty-control" style="height:36px"><button type="button" data-cart-step="-1" data-cart-item="'.Helpers::e($it['id']).'" aria-label="Decrease">'.icon('minus',14).'</button><input type="number" value="'.$it['quantity'].'" min="1" max="99" aria-label="Quantity for '.Helpers::e($it['name']).'"><button type="button" data-cart-step="1" data-cart-item="'.Helpers::e($it['id']).'" aria-label="Increase">'.icon('plus',14).'</button></div>';
    echo '<button type="button" class="btn btn-ghost btn-sm" data-save-later="'.Helpers::e($it['id']).'" data-saved="1">Save for later</button>';
    echo '<button type="button" class="btn btn-ghost btn-sm text-danger" data-cart-remove="'.Helpers::e($it['id']).'">'.icon('trash',14).' Remove</button></div></div>';
    echo '<div class="text-right"><strong data-line-total="'.Helpers::e($it['id']).'">'.Helpers::money($it['line_total_cents']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::money($it['unit_price_cents']).' each</div></div></div>';
}
echo '</div>';
if ($saved !== []) {
    echo '<div class="card card-pad" style="margin-top:var(--sp-5)"><h3 style="font-size:var(--fs-md);margin-bottom:var(--sp-3)">Saved for later ('.count($saved).')</h3>';
    foreach ($saved as $it) {
        echo '<div class="row-between" style="padding:9px 0;border-bottom:1px solid var(--c-border)"><span>'.Helpers::e($it['name']).'</span><span class="row"><strong>'.Helpers::money($it['unit_price_cents']).'</strong><button type="button" class="btn btn-outline btn-sm" data-save-later="'.Helpers::e($it['id']).'" data-saved="0">Move to cart</button><button type="button" class="btn btn-ghost btn-sm" data-cart-remove="'.Helpers::e($it['id']).'" aria-label="Remove">'.icon('trash',14).'</button></span></div>';
    }
    echo '</div>';
}
echo '</div><aside class="cart-summary card card-pad"><h3 style="font-size:var(--fs-md);margin-bottom:var(--sp-4)">Order summary</h3>';
echo '<form method="post" data-ajax-form="/api/cart/coupon" style="margin-bottom:var(--sp-4)">'.Session::csrfField().'<div class="input-group"><input class="input" name="code" placeholder="Coupon code" value="'.Helpers::e((string)$totals['coupon']).'" aria-label="Coupon code" maxlength="32"><button class="btn btn-outline" type="submit">Apply</button></div><div class="field-error" data-form-error></div></form>';
if ($totals['coupon']) { echo '<div class="alert alert-success">'.icon('check-circle',16).' Coupon <strong>'.Helpers::e($totals['coupon']).'</strong> applied. <a href="#" data-ajax-form="/api/cart/coupon" style="text-decoration:underline">remove</a></div>'; }
echo '<div class="summary-line"><span>Subtotal ('.$totals['units'].' items)</span><strong data-summary="subtotal">'.Helpers::money($totals['subtotal_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Discount</span><strong data-summary="discount">−'.Helpers::money($totals['discount_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Shipping ('.Helpers::e($totals['shipping']['name']).')</span><strong data-summary="shipping">'.Helpers::money($totals['shipping_cents']).'</strong></div>';
echo '<div class="summary-line"><span>Tax</span><strong data-summary="tax">'.Helpers::money($totals['tax_cents']).'</strong></div>';
echo '<div class="summary-line total"><span>Total</span><strong data-summary="total">'.Helpers::money($totals['total_cents']).'</strong></div>';
if ($totals['has_unavailable']) { echo alert_box('error','Remove unavailable items before continuing to checkout.'); }
echo '<a class="btn btn-primary btn-block btn-lg" href="/checkout" style="margin-top:var(--sp-4)" '.($totals['has_unavailable']?'aria-disabled="true"':'').'>Continue to checkout '.icon('arrow-right',16).'</a>';
echo '<a class="btn btn-ghost btn-block btn-sm" href="/shop" style="margin-top:8px">Continue shopping</a>';
echo '<div class="text-muted" style="font-size:11px;margin-top:var(--sp-4);line-height:1.6">'.icon('shield',14).' Prices, stock, coupon and totals are recalculated securely on the server at checkout.</div>';
echo '</aside></div>';
}
echo '</div></section>';
require APP_ROOT . '/includes/footer.php';
