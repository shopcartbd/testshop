<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Auth;
use AllStore\Models\OrderRepository;
api_method('POST'); api_require_admin('orders.manage'); api_csrf(); api_rate('admin.order.status',40,300);
$id=Request::id('order_id'); $status=Request::str('status','',30);
if($id==='' || !in_array($status,OrderRepository::STATUSES,true)) Response::fail('Choose a valid order and status.',[],422);
OrderRepository::updateStatus($id,$status,Request::str('note','',500),Auth::id());
Response::ok('Order status updated.');
