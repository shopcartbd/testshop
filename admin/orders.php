<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Models\OrderRepository;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('orders.view');
if (Request::str('export','',10) === 'csv') {
    Auth::requireAdmin('orders.manage');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="allstore-orders.csv"'); Response::noCache();
    echo OrderRepository::exportCsv(['q'=>Request::str('q','',80),'status'=>Request::str('status','',30)]); exit;
}
if (Request::isPost()) {
    Session::requireCsrf(); Auth::requireAdmin('orders.manage');
    try {
        $id = Request::id('order_id'); $status = Request::str('status','',30);
        if ($id === '' || !in_array($status,OrderRepository::STATUSES,true)) { throw new AppException('Choose a valid order and status.',422); }
        OrderRepository::updateStatus($id,$status,Request::str('note','',500),Auth::id());
        Session::flash('success','Order status updated.');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Status update failed.'); }
    Response::redirect(Request::path());
}
$f=['q'=>Request::str('q','',80),'status'=>Request::str('status','',30),'payment_method'=>Request::str('payment_method','',30),'from'=>Request::str('from','',30),'to'=>Request::str('to','',30),'page'=>Request::int('page',1,1,10000)];
$res=OrderRepository::allForAdmin($f);
admin_header(['title'=>'Orders','subtitle'=>'Payment, fulfilment and auditable status history','active'=>'orders']);
echo '<div class="admin-page-head"><div><h2>Orders</h2><p>'.$res['total'].' orders match the current filters.</p></div><a class="btn btn-outline btn-sm" href="?export=csv&q='.rawurlencode($f['q']).'&status='.rawurlencode($f['status']).'">'.icon('download',15).'Export CSV</a></div>';
echo '<form class="admin-toolbar" method="get" style="flex-wrap:wrap"><input class="input" type="search" name="q" value="'.Helpers::e($f['q']).'" placeholder="Order number, customer or email…"><select class="select" name="status"><option value="">All statuses</option>';
foreach (OrderRepository::STATUSES as $s) echo '<option value="'.$s.'" '.($f['status']===$s?'selected':'').'>'.Helpers::e(ucwords(str_replace('_',' ',$s))).'</option>';
echo '</select><select class="select" name="payment_method"><option value="">All payment methods</option><option value="cod"'.($f['payment_method']==='cod'?' selected':'').'>Cash on Delivery</option><option value="stripe"'.($f['payment_method']==='stripe'?' selected':'').'>Stripe</option></select><input class="input" type="date" name="from" value="'.Helpers::e($f['from']).'"><input class="input" type="date" name="to" value="'.Helpers::e($f['to']).'"><button class="btn btn-outline" type="submit">'.icon('search',15).'Filter</button></form>';
echo '<div class="card"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Placed</th><th></th></tr></thead><tbody>';
foreach ($res['items'] as $o) {
    echo '<tr><td class="cell-strong"><a href="/admin/orders/'.rawurlencode($o['order_number']).'">'.Helpers::e($o['order_number']).'</a></td><td>'.Helpers::e($o['customer_name']).'<div class="text-subtle" style="font-size:11px">'.Helpers::e($o['customer_email']).'</div></td><td>'.(int)$o['item_count'].'</td><td>'.Helpers::money((int)$o['total_cents']).'</td><td>'.Helpers::e(ucfirst((string)$o['payment_method'])).'<div class="text-subtle" style="font-size:11px">'.admin_status($o,'payment_status').'</div></td><td>'.admin_status($o).'</td><td>'.admin_date($o,'placed_at').'</td><td><a class="btn btn-outline btn-sm" href="/admin/orders/'.rawurlencode($o['order_number']).'">Manage</a></td></tr>';
}
echo '</tbody></table></div></div>';
echo '<div class="row" style="margin-top:12px;gap:8px">'.($res['page']>1?'<a class="btn btn-outline btn-sm" href="?page='.($res['page']-1).'&q='.rawurlencode($f['q']).'&status='.rawurlencode($f['status']).'">← Previous</a>':'').($res['page']<$res['pages']?'<a class="btn btn-outline btn-sm" href="?page='.($res['page']+1).'&q='.rawurlencode($f['q']).'&status='.rawurlencode($f['status']).'">Next →</a>':'').'</div>';
admin_footer();
