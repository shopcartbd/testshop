<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Models\CustomerRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
$reviews = CustomerRepository::reviewsForUser($uid);
$eligible = CustomerRepository::eligibleReviews($uid);
account_header('reviews','My reviews','Share your experience and see moderation status.');
account_card_start('Your reviews');
if ($reviews === []) { echo '<p class="text-muted" style="font-size:13px">You have not written a review yet.</p>'; }
foreach ($reviews as $r) { echo '<div class="review-item"><div class="row-between"><strong style="font-size:13px">'.Helpers::e($r['product_name']).'</strong>'.status_badge((string)$r['status']).'</div>'.stars((float)$r['rating']).'<p class="text-muted" style="font-size:13px;margin-top:8px">'.Helpers::e($r['body']).'</p><small class="text-subtle">'.Helpers::dateNice($r['created_at']).'</small></div>'; }
account_card_end();
if ($eligible !== []) {
    account_card_start('Products you can review');
    foreach ($eligible as $e) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($e['name']).'</strong><div class="text-subtle" style="font-size:11px">Order '.Helpers::e($e['order_number']).'</div></div><a class="btn btn-outline btn-sm" href="'.Helpers::url('/product/'.$e['slug']).'#reviews">Write a review</a></div>'; }
    account_card_end();
}
account_footer();
