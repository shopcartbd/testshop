<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\CartService;
api_method('POST'); api_csrf(); api_rate('cart.add',60,300);
$pid=Request::id('product_id'); $vid=Request::id('variant_id');
if($pid===''){ Response::fail('Choose a product.',['product_id'=>'Product id is required.'],422); }
CartService::add($pid,$vid,Request::int('quantity',1,1,99));
api_cart_response('Added to your cart.');
