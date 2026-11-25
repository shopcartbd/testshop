<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Response;
use AllStore\Models\CatalogRepository;
api_method('GET'); api_rate('brands',120,60);
$brands=array_map(static fn($b)=>['id'=>$b['id'],'name'=>$b['name'],'slug'=>$b['slug'],'logo_url'=>$b['logo_url'] ?? '','description'=>$b['description'] ?? '','is_active'=>(int)$b['is_active']],CatalogRepository::brands());
Response::ok('Brands loaded.',['brands'=>$brands]);
