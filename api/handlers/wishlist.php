<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Response;
use AllStore\Models\CustomerRepository;
api_method('GET'); api_require_login(); api_rate('wishlist',90,60);
$items=array_map(static fn($w)=>api_pick($w,['id','product_id','variant_id','name','slug','price_cents','image_url','created_at']),CustomerRepository::wishlistItems((string)Auth::id()));
Response::ok('Wishlist loaded.',['items'=>$items,'count'=>count($items)]);
