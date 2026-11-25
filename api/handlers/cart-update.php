<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\CartService;
api_method('POST'); api_csrf(); api_rate('cart.update',90,300);
$id=Request::id('item_id');
if($id===''){ Response::fail('Choose a cart item.',['item_id'=>'Item id is required.'],422); }
if(Request::has('saved')) { CartService::saveForLater($id,Request::bool('saved')); api_cart_response(Request::bool('saved')?'Saved for later.':'Moved to your cart.',['item_id'=>$id]); }
CartService::update($id,Request::int('quantity',1,0,99));
api_cart_response('Cart updated.',['item_id'=>$id]);
