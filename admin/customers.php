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
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('customers.view');
if (Request::str('export','',10) === 'csv') {
    Auth::requireAdmin('customers.manage');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="allstore-customers.csv"'); Response::noCache();
    echo CustomerRepository::exportCsv(); exit;
}
if (Request::isPost()) {
    Session::requireCsrf(); Auth::requireAdmin('customers.manage');
    try {
        $id=Request::id('id'); $status=Request::str('status','',20);
        if ($id==='' || !in_array($status,['active','suspended'],true)) throw new AppException('Choose a valid customer and status.',422);
        $n=Database::update('profiles',['status'=>$status,'updated_at'=>Helpers::now()],'id=:i',['i'=>$id]);
        if ($n!==1) throw new AppException('Customer not found.',404);
        if ($status==='suspended') Database::delete('sessions','user_id=:u',['u'=>$id]);
        Logger::audit('admin.customer.status','profiles',$id,['status'=>$status]); Session::flash('success','Customer status updated.');
    } catch (AppException $e) { Session::flash('error',$e->getMessage()); }
      catch (\Throwable $e) { Logger::exception($e); Session::flash('error','Update failed.'); }
    Response::redirect(Request::path());
}
$f=['q'=>Request::str('q','',80),'status'=>Request::str('status','',20),'page'=>Request::int('page',1,1,10000)];
$res=CustomerRepository::allCustomers($f);
admin_header(['title'=>'Customers','subtitle'=>'Accounts, lifetime value and status control','active'=>'customers']);
echo '<div class="admin-page-head"><div><h2>Customers</h2><p>'.$res['total'].' accounts. Password hashes are never displayed or exported.</p></div><a class="btn btn-outline btn-sm" href="?export=csv">'.icon('download',15).'Export CSV</a></div>';
echo '<form class="admin-toolbar" method="get"><input class="input" type="search" name="q" value="'.Helpers::e($f['q']).'" placeholder="Name or email…"><select class="select" name="status"><option value="">All statuses</option><option value="active"'.($f['status']==='active'?' selected':'').'>Active</option><option value="suspended"'.($f['status']==='suspended'?' selected':'').'>Suspended</option></select><button class="btn btn-outline" type="submit">'.icon('search',15).'Search</button></form>';
echo '<div class="card"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer</th><th>Phone</th><th>Orders</th><th>Lifetime value</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead><tbody>';
foreach ($res['items'] as $c) {
    echo '<tr><td class="cell-strong"><a href="/admin/customers/'.Helpers::e($c['id']).'">'.Helpers::e($c['full_name']).'</a><div class="text-subtle" style="font-size:11px">'.Helpers::e($c['email']).'</div></td><td>'.Helpers::e($c['phone']).'</td><td>'.(int)$c['order_count'].'</td><td>'.Helpers::money((int)$c['total_spent_cents']).'</td><td>'.admin_status($c).'</td><td>'.admin_date($c,'created_at').'</td><td><form method="post" class="row" style="gap:5px">'.Session::csrfField().'<input type="hidden" name="id" value="'.Helpers::e($c['id']).'"><select class="select" name="status" style="width:auto;font-size:12px;padding:4px 26px 4px 8px;min-height:32px"><option value="active"'.($c['status']==='active'?' selected':'').'>Active</option><option value="suspended"'.($c['status']==='suspended'?' selected':'').'>Suspended</option></select><button class="btn btn-outline btn-sm" type="submit">Update</button></form></td></tr>';
}
echo '</tbody></table></div></div>';
echo '<div class="row" style="margin-top:12px;gap:8px">'.($res['page']>1?'<a class="btn btn-outline btn-sm" href="?page='.($res['page']-1).'&q='.rawurlencode($f['q']).'">← Previous</a>':'').($res['page']<$res['pages']?'<a class="btn btn-outline btn-sm" href="?page='.($res['page']+1).'&q='.rawurlencode($f['q']).'">Next →</a>':'').'</div>';
admin_footer();
