<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Auth;
use AllStore\Models\OrderRepository;
api_method('GET','POST'); api_require_admin(Request::method()==='POST'?'orders.manage':'orders.view');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.orders',40,300);
 $id=Request::id('order_id'); $status=Request::str('status','',30);
 if($id==='' || !in_array($status,OrderRepository::STATUSES,true)) Response::fail('Choose a valid order and status.',[],422);
 OrderRepository::updateStatus($id,$status,Request::str('note','',500),Auth::id());
 Response::ok('Order status updated.');
}
$f=['q'=>Request::str('q','',80),'status'=>Request::str('status','',30),'payment_method'=>Request::str('payment_method','',30),'page'=>Request::int('page',1,1,10000)];
$r=OrderRepository::allForAdmin($f);
Response::ok('Orders loaded.',['items'=>api_safe_orders($r['items']),'total'=>$r['total'],'page'=>$r['page'],'pages'=>$r['pages']]);
