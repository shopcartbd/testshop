<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Services\CheckoutService;
api_method('POST'); api_csrf(); api_rate('orders.create',10,600);
$r=CheckoutService::create(Request::all());
$o=$r['order'];
if(!Auth::check()){ Session::set('guest_order_number',(string)$o['order_number']); }
Response::ok('Order placed.',[
 'order'=>api_safe_order($o),'order_number'=>$o['order_number'],'status'=>$o['status'],
 'payment_status'=>$o['payment_status'],'payment_url'=>$r['payment_url'] ?? null,
 'duplicate'=>!empty($r['duplicate']),
],!empty($r['duplicate'])?200:201);
