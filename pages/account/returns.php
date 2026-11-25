<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\AppException;
use AllStore\Models\OrderRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$uid = (string)Auth::id();
if (Request::isPost()) {
    Session::requireCsrf();
    try { OrderRepository::createReturn($uid, Request::all()); Session::flash('success','Return request submitted.'); }
    catch (AppException $e) { Session::flash('error',$e->getMessage()); }
    Response::redirect('/account/returns');
}
$returns = \AllStore\Models\CustomerRepository::returnsForUser($uid);
$eligible = \AllStore\Core\Database::all("SELECT o.order_number, oi.id AS order_item_id, oi.name FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.user_id=:u AND o.status='delivered' ORDER BY o.placed_at DESC LIMIT 30", ['u'=>$uid]);
account_header('returns','Return requests','Request a return or exchange for a delivered order.');
account_card_start('New return request');
echo '<form method="post">'.Session::csrfField().'<div class="form-grid"><div class="field"><label for="order_number">Order</label><select class="select" id="order_number" name="order_number" required><option value="">Choose an order</option>';
foreach ($eligible as $e) { echo '<option value="'.Helpers::e($e['order_number']).'" data-item="'.Helpers::e($e['order_item_id']).'">'.Helpers::e($e['order_number']).' — '.Helpers::e($e['name']).'</option>'; }
echo '</select></div><div class="field"><label for="order_item_id">Item ID</label><input class="input" id="order_item_id" name="order_item_id" required placeholder="Paste the item ID from the order details"></div>';
echo '<div class="field"><label for="reason">Reason</label><select class="select" id="reason" name="reason"><option>Changed my mind</option><option>Item damaged</option><option>Wrong item received</option><option>Not as described</option><option>Other</option></select></div>';
echo '<div class="field"><label for="request_type">Request type</label><select class="select" id="request_type" name="request_type"><option value="return">Refund</option><option value="exchange">Exchange</option></select></div>';
echo '<div class="field span-2"><label for="detail">Details</label><textarea class="textarea" id="detail" name="detail" maxlength="1000" style="min-height:80px"></textarea></div></div><button class="btn btn-primary" type="submit">Submit request</button></form>';
echo '<p class="field-hint" style="margin-top:10px">You can also start a return directly from an order details page, which fills in the item for you.</p>';
account_card_end();
account_card_start('Your requests');
if ($returns === []) echo '<p class="text-muted" style="font-size:13px">No return requests yet.</p>';
foreach ($returns as $r) { echo '<div class="order-row"><div class="grow"><strong style="font-size:13px">'.Helpers::e($r['product_name']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::e($r['order_number']).' · '.Helpers::e($r['reason']).'</div></div>'.status_badge((string)$r['status']).'<small class="text-subtle">'.Helpers::dateNice($r['created_at']).'</small></div>'; }
account_card_end();
account_footer();
