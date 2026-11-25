<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\CustomerRepository;
use AllStore\Services\CartService;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
if (Request::isPost()) {
    Session::requireCsrf();
    $itemId = Request::id('item_id');
    if (Request::str('action','',20) === 'move') {
        foreach (CustomerRepository::wishlistItems($uid) as $w) {
            if ($w['id'] === $itemId) {
                try { CartService::add((string)$w['product_id'], '', 1); CustomerRepository::removeWishlist($uid, $itemId); Session::flash('success','Moved to your cart.'); }
                catch (\AllStore\Core\AppException $e) { Session::flash('error',$e->getMessage()); }
            }
        }
    } else { CustomerRepository::removeWishlist($uid, $itemId); Session::flash('success','Removed from wishlist.'); }
    Response::redirect('/account/wishlist');
}
$items = CustomerRepository::wishlistItems($uid);
account_header('wishlist','Wishlist','Everything you saved for later.');
if ($items === []) { echo empty_state('heart','Your wishlist is empty','Tap the heart on any product to keep it here.','/shop','Explore products'); account_footer(); return; }
echo '<div class="product-grid grid-3">';
foreach ($items as $w) {
    echo '<article class="product-card"><div class="product-media"><a href="'.Helpers::url('/product/'.$w['slug']).'"><img src="'.Helpers::e($w['image_url'] ?: '/assets/img/products/headphones.jpg').'" alt="'.Helpers::e($w['name']).'" loading="lazy"></a></div><div class="product-body"><a class="product-name" href="'.Helpers::url('/product/'.$w['slug']).'">'.Helpers::e($w['name']).'</a><div class="product-price"><span class="now">'.Helpers::money((int)$w['price_cents']).'</span></div><div class="row" style="margin-top:10px;gap:6px"><form method="post" style="flex:1">'.Session::csrfField().'<input type="hidden" name="action" value="move"><input type="hidden" name="item_id" value="'.Helpers::e($w['id']).'"><button class="btn btn-primary btn-sm btn-block" type="submit">Move to cart</button></form><form method="post">'.Session::csrfField().'<input type="hidden" name="action" value="remove"><input type="hidden" name="item_id" value="'.Helpers::e($w['id']).'"><button class="btn btn-outline btn-sm" type="submit" aria-label="Remove">'.icon('trash',14).'</button></form></div></div></article>';
}
echo '</div>';
account_footer();
