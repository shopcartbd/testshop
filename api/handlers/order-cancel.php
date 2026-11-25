<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Models\OrderRepository;
api_method('POST'); api_require_login(); api_csrf(); api_rate('order.cancel',12,600);
$key=Request::str('order_number','',80) ?: Request::str('id','',80);
$uid=(string)Auth::id();
$o=OrderRepository::findByNumberForUser($key,$uid) ?: Database::first('SELECT * FROM orders WHERE id=:i AND user_id=:u',['i'=>$key,'u'=>$uid]);
if(!$o){ Response::fail('Order not found.',[],404); }
OrderRepository::cancelByCustomer((string)$o['order_number'],$uid);
$updated=OrderRepository::findByNumberForUser((string)$o['order_number'],$uid);
Response::ok('Order cancelled.',['order'=>api_safe_order($updated ?: $o)]);
