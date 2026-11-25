<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Models\CustomerRepository;
use AllStore\Models\OrderRepository;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('customers.view');
$id=Request::str('id','',80);
$customer=$id!=='' ? CustomerRepository::customerDetails($id) : null;
if (!$customer) { http_response_code(404); throw new AppException('Customer not found.',404); }
if (Request::isPost()) {
    Session::requireCsrf(); Auth::requireAdmin('customers.manage');
    try {
        $status=Request::str('status','',20); $notes=Request::str('notes','',4000);
        if (!in_array($status,['active','suspended'],true)) throw new AppException('Invalid status.',422);
        Database::update('profiles',['status'=>$status,'notes'=>$notes,'updated_at'=>Helpers::now()],'id=:i',['i'=>$id]);
        Logger::audit('admin.customer.updated','profiles',$id); Session::flash('success','Customer updated.');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
    Response::redirect('/admin/customers/'.$id);
}
$orders=Database::all('SELECT * FROM orders WHERE user_id=:u ORDER BY placed_at DESC LIMIT 20',['u'=>$id]);
$addresses=CustomerRepository::addresses($id);
admin_header(['title'=>'Customer details','subtitle'=>Helpers::e($customer['full_name']),'active'=>'customers']);
echo '<div class="row-between wrap" style="margin-bottom:16px"><div><h2>'.Helpers::e($customer['full_name']).'</h2><p class="text-muted" style="font-size:13px">'.Helpers::e($customer['email']).' · Joined '.Helpers::e(Helpers::dateNice($customer['created_at'])).'</p></div><a class="btn btn-outline btn-sm" href="/admin/customers">← All customers</a></div>';
echo '<div class="admin-grid-2"><div class="card card-pad"><h3 style="margin-bottom:12px">Account</h3><p style="font-size:13px;line-height:1.8">Role: '.Helpers::e($customer['role_name'] ?? 'Customer').'<br>Phone: '.Helpers::e($customer['phone'] ?: '—').'<br>Status: '.admin_status($customer).'<br>Last login: '.Helpers::e(Helpers::dateNice($customer['last_login_at'])).'</p><hr><form method="post">'.Session::csrfField().'<div class="field"><label>Status</label><select class="select" name="status"><option value="active"'.($customer['status']==='active'?' selected':'').'>Active</option><option value="suspended"'.($customer['status']==='suspended'?' selected':'').'>Suspended</option></select></div><div class="field"><label>Private admin notes</label><textarea class="textarea" name="notes" maxlength="4000">'.Helpers::e($customer['notes'] ?? '').'</textarea></div><button class="btn btn-primary btn-sm" type="submit">Save account</button></form></div>';
echo '<div class="card card-pad"><h3 style="margin-bottom:12px">Addresses</h3>';
foreach ($addresses as $a) echo '<div class="order-row"><div><strong style="font-size:13px">'.Helpers::e($a['label']).'</strong><div class="text-subtle" style="font-size:11px">'.Helpers::e($a['line1']).', '.Helpers::e($a['city']).' '.Helpers::e($a['country']).'</div></div>'.((int)$a['is_default_shipping']?'<span class="badge badge-brand">Default</span>':'').'</div>';
if ($addresses===[]) echo '<p class="text-muted" style="font-size:13px">No saved addresses.</p>';
echo '</div></div>';
echo '<div class="card" style="margin-top:16px"><div class="card-header"><h3>Recent orders</h3></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Date</th><th></th></tr></thead><tbody>';
foreach ($orders as $o) echo '<tr><td class="cell-strong">'.Helpers::e($o['order_number']).'</td><td>'.admin_status($o).'</td><td>'.Helpers::money((int)$o['total_cents']).'</td><td>'.admin_date($o,'placed_at').'</td><td><a class="btn btn-outline btn-sm" href="/admin/orders/'.rawurlencode($o['order_number']).'">Manage</a></td></tr>';
echo '</tbody></table></div></div>';
admin_footer();
