<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\CustomerRepository;
api_method('POST'); api_require_login(); api_csrf(); api_rate('wishlist.add',60,300);
$pid=Request::id('product_id');
if($pid===''){ Response::fail('Choose a product.',['product_id'=>'Product id is required.'],422); }
$added=CustomerRepository::toggleWishlist((string)Auth::id(),$pid);
Response::ok($added?'Saved to your wishlist.':'Removed from your wishlist.',array_merge(['added'=>$added],api_counts()));
