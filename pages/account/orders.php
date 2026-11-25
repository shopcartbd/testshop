<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Models\OrderRepository;
require_once APP_ROOT . '/includes/account-layout.php';
$res = OrderRepository::forUser((string)Auth::id(), ['status'=>Request::str('status','',30),'q'=>Request::str('q','',60),'page'=>Request::int('page',1,1,10000)]);
account_header('orders','My orders','View and track everything you have ordered.');
echo '<form class="admin-toolbar" method="get"><input class="input" type="search" name="q" value="'.Helpers::e(Request::str('q','',60)).'" placeholder="Search order number…"><select class="select" name="status"><option value="">All statuses</option>';
foreach (OrderRepository::STATUSES as $s) { echo '<option value="'.$s.'" '.(Request::str('status','',30)===$s?'selected':'').'>'.Helpers::e(ucwords(str_replace('_',' ',$s))).'</option>'; }
echo '</select><button class="btn btn-outline" type="submit">'.icon('search',15).'Search</button></form>';
account_card_start('Order history');
if ($res['items'] === []) { echo empty_state('package','No orders found','Orders you place will appear here.','/shop','Start shopping'); }
else {
    echo '<div class="table-wrap" style="border:0"><table class="data-table"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Items</th><th>Total</th><th></th></tr></thead><tbody>';
    foreach ($res['items'] as $o) {
        echo '<tr><td class="cell-strong">'.Helpers::e($o['order_number']).'</td><td>'.Helpers::dateNice($o['placed_at']).'</td><td>'.status_badge((string)$o['status']).'</td><td>'.(int)DatabaseCount($o['id']).'</td><td class="cell-strong">'.Helpers::money((int)$o['total_cents']).'</td><td><a class="btn btn-outline btn-sm" href="/account/orders/'.rawurlencode($o['order_number']).'">View</a></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:16px">'.(function($r){ $out=''; if($r['page']>1)$out.='<a class="btn btn-outline btn-sm" href="?page='.($r['page']-1).'">← Previous</a> '; if($r['page']<$r['pages'])$out.='<a class="btn btn-outline btn-sm" href="?page='.($r['page']+1).'">Next →</a>'; return $out; })($res).'</div>';
}
account_card_end();
account_footer();
function DatabaseCount(string $id): int { return (int)\AllStore\Core\Database::value('SELECT SUM(quantity) FROM order_items WHERE order_id=:o',['o'=>$id]); }
