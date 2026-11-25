<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\CatalogRepository;
api_method('GET'); api_rate('product',120,60);
$key = Request::str('id','',150);
if ($key === '') { Response::fail('Product id or slug is required.',[],422); }
$p = CatalogRepository::findById($key) ?: CatalogRepository::findBySlug($key);
if (!$p) { Response::fail('Product not found.',[],404); }
$id=(string)$p['id'];
$images=array_map(static fn($r)=>['url'=>$r['url'],'alt'=>$r['alt'] ?? '','sort_order'=>(int)($r['sort_order'] ?? 0),'is_primary'=>(int)($r['is_primary'] ?? 0)],CatalogRepository::images($id));
$variants=array_map(static fn($r)=>['id'=>$r['id'],'sku'=>$r['sku'],'name'=>$r['name'],'price_cents'=>(int)$r['price_cents'],'compare_cents'=>(int)$r['compare_cents'],'stock'=>(int)$r['stock'],'image_url'=>$r['image_url'] ?? '','options'=>$r['options'] ?? '{}'],CatalogRepository::variants($id));
$reviews=array_map(static fn($r)=>['id'=>$r['id'],'rating'=>(int)$r['rating'],'title'=>$r['title'],'body'=>$r['body'],'full_name'=>$r['full_name'] ?? 'Customer','is_verified'=>(int)($r['is_verified'] ?? 0),'admin_reply'=>$r['admin_reply'] ?? null,'created_at'=>$r['created_at']],CatalogRepository::reviews($id,12));
Response::ok('Product loaded.', [
 'product'=>api_safe_product($p),'images'=>$images,'variants'=>$variants,
 'attributes'=>CatalogRepository::productAttributes($id),
 'reviews'=>$reviews,'rating'=>CatalogRepository::ratingSummary($id),
 'related'=>api_safe_products(CatalogRepository::related($id,(string)($p['category_id'] ?? ''),8)),
]);
