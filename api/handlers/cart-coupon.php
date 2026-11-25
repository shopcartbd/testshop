<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\CartService;
api_method('POST'); api_csrf(); api_rate('cart.coupon',25,600);
$code=strtoupper(trim(Request::str('code','',32)));
if(Request::bool('remove') || $code==='') { CartService::clearCoupon(); api_cart_response('Coupon removed.'); }
CartService::applyCoupon($code);
api_cart_response('Coupon applied.');
