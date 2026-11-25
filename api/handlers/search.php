<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\SearchService;
api_method('GET'); api_rate('search',90,60);
$f=['q'=>Request::str('q','',120),'category'=>Request::slug('category'),'brand'=>Request::slug('brand'),
 'sort'=>Request::str('sort','recommended',30),'page'=>Request::int('page',1,1,10000),'per_page'=>Request::int('per_page',12,1,60),
 'min_price'=>Request::int('min_price',0,0,1000000000),'max_price'=>Request::int('max_price',0,0,1000000000),
 'in_stock'=>Request::bool('in_stock'),'on_sale'=>Request::bool('on_sale')];
$r=SearchService::search($f);
Response::ok('Search completed.',['items'=>api_safe_products($r['items']),'total'=>$r['total'],'page'=>$r['page'],'per_page'=>$r['per_page'],'pages'=>$r['pages'],'did_you_mean'=>!empty($r['did_you_mean']),'query'=>$f['q']]);
