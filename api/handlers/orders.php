<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\OrderRepository;
api_method('GET'); api_require_login(); api_rate('orders',90,60);
$r=OrderRepository::forUser((string)Auth::id(),['q'=>Request::str('q','',60),'status'=>Request::str('status','',30),'page'=>Request::int('page',1,1,10000)]);
Response::ok('Orders loaded.',['items'=>api_safe_orders($r['items']),'total'=>$r['total'],'page'=>$r['page'],'pages'=>$r['pages']]);
