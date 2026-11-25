<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Response;
use AllStore\Services\CartService;
api_method('GET'); api_rate('cart',120,60);
$t=CartService::totals();
Response::ok('Cart loaded.',array_merge(api_counts(),['cart'=>api_safe_totals($t),'totals'=>api_formatted_totals($t)]));
