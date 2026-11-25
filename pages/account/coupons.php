<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$coupons = CustomerRepository::couponsForUser((string)Auth::id());
account_header('coupons','Coupons','Offers available to your account.');
account_card_start('Available offers');
if ($coupons === []) { echo empty_state('tag','No active coupons','New offers will appear here when they are published.'); }
foreach ($coupons as $c) {
    $label = $c['type'] === 'percent' ? ((float)$c['percent']).'% off' : ($c['type'] === 'free_shipping' ? 'Free shipping' : Helpers::money((int)$c['value_cents']).' off');
    echo '<div class="order-row"><span class="badge badge-brand" style="font-size:14px;padding:8px 14px">'.Helpers::e($c['code']).'</span><div class="grow"><strong>'.Helpers::e($label).'</strong><div class="text-subtle" style="font-size:11px">Min. order '.Helpers::money((int)$c['min_order_cents']).' · Used by you '.(int)$c['used_by_you'].' time(s)</div></div><small class="text-subtle">Valid until '.Helpers::dateNice($c['expires_at']).'</small><a class="btn btn-outline btn-sm" href="/cart">Use in cart</a></div>';
}
account_card_end();
account_footer();
