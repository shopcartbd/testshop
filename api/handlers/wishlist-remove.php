<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\CustomerRepository;
api_method('POST'); api_require_login(); api_csrf(); api_rate('wishlist.remove',60,300);
$id=Request::id('item_id');
if($id===''){ Response::fail('Choose a wishlist item.',['item_id'=>'Item id is required.'],422); }
CustomerRepository::removeWishlist((string)Auth::id(),$id);
Response::ok('Removed from your wishlist.',api_counts());
