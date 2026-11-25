<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Database;
use AllStore\Models\OrderRepository;
use AllStore\Models\CustomerRepository;
use AllStore\Services\NotificationService;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
$orders = OrderRepository::forUser($uid, ['page'=>1]);
$stats = [
    'Orders' => (int)Database::value('SELECT COUNT(*) FROM orders WHERE user_id=:u',['u'=>$uid]),
    'Delivered' => (int)Database::value("SELECT COUNT(*) FROM orders WHERE user_id=:u AND status='delivered'",['u'=>$uid]),
    'Wishlist' => CustomerRepository::wishlistCount($uid),
    'Notifications' => NotificationService::unreadCount($uid),
];
account_header('overview','Account overview','A quick look at your activity.');
echo '<div class="stat-grid" style="margin-bottom:var(--sp-5)">';
foreach ($stats as $label=>$value) { echo '<div class="stat-card"><div class="stat-label">'.Helpers::e($label).'</div><div class="stat-value">'.(int)$value.'</div></div>'; }
echo '</div>';
account_card_start('Recent orders','<a class="section-link" href="/account/orders">View all '.icon('arrow-right',14).'</a>');
if ($orders['items'] === []) { echo empty_state('package','No orders yet','When you place an order it will appear here.','/shop','Start shopping'); }
else {
    foreach (array_slice($orders['items'],0,5) as $o) {
        echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($o['order_number']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::dateNice($o['placed_at']).'</div></div>'.status_badge((string)$o['status']).'<strong>'.Helpers::money((int)$o['total_cents']).'</strong><a class="btn btn-outline btn-sm" href="/account/orders/'.rawurlencode($o['order_number']).'">Details</a></div>';
    }
}
account_card_end();
account_card_start('Quick links');
echo '<div class="row wrap" style="gap:9px"><a class="btn btn-outline btn-sm" href="/account/profile">Edit profile</a><a class="btn btn-outline btn-sm" href="/account/addresses">Address book</a><a class="btn btn-outline btn-sm" href="/account/wishlist">Wishlist</a><a class="btn btn-outline btn-sm" href="/account/tickets">Support</a><a class="btn btn-outline btn-sm" href="/account/change-password">Change password</a></div>';
account_card_end();
account_footer();
