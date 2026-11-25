<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\CatalogRepository;
api_method('GET'); api_rate('products',120,60);
$f = [
 'q'=>Request::str('q','',120), 'category'=>Request::slug('category'), 'brand'=>Request::slug('brand'),
 'min_price'=>Request::int('min_price',0,0,1000000000), 'max_price'=>Request::int('max_price',0,0,1000000000),
 'rating'=>Request::int('rating',0,0,5), 'in_stock'=>Request::bool('in_stock'), 'on_sale'=>Request::bool('on_sale'),
 'featured'=>Request::bool('featured'), 'best_seller'=>Request::bool('best_seller'), 'new_arrival'=>Request::bool('new_arrival'),
 'trending'=>Request::bool('trending'), 'flash'=>Request::bool('flash'), 'sort'=>Request::str('sort','recommended',30),
 'page'=>Request::int('page',1,1,10000), 'per_page'=>Request::int('per_page',12,1,60),
];
$attrs = Request::input('attributes',[]);
$f['attributes'] = is_array($attrs) ? $attrs : [];
$r = CatalogRepository::listing($f);
Response::ok('Products loaded.', [
 'items'=>api_safe_products($r['items']), 'total'=>$r['total'], 'page'=>$r['page'],
 'per_page'=>$r['per_page'], 'pages'=>$r['pages'], 'filters'=>$f,
]);
