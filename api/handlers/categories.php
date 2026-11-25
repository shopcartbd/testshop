<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Response;
use AllStore\Models\CatalogRepository;
api_method('GET'); api_rate('categories',120,60);
$clean=static function(array $c) use (&$clean): array {
 return ['id'=>$c['id'],'name'=>$c['name'],'slug'=>$c['slug'],'parent_id'=>$c['parent_id'] ?? null,
  'image_url'=>$c['image_url'] ?? '','icon'=>$c['icon'] ?? '','sort_order'=>(int)($c['sort_order'] ?? 0),
  'is_active'=>(int)($c['is_active'] ?? 1),'is_featured'=>(int)($c['is_featured'] ?? 0),
  'product_count'=>(int)($c['product_count'] ?? 0),'children'=>array_map($clean,(array)($c['children'] ?? []))];
};
Response::ok('Categories loaded.', ['tree'=>array_map($clean,CatalogRepository::tree()),'all'=>array_map($clean,CatalogRepository::allCategories())]);
