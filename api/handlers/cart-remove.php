<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\CartService;
api_method('POST'); api_csrf(); api_rate('cart.remove',90,300);
$id=Request::id('item_id');
if($id===''){ Response::fail('Choose a cart item.',['item_id'=>'Item id is required.'],422); }
CartService::remove($id);
api_cart_response('Item removed.');
