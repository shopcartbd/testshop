<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\Database;
use AllStore\Core\AppException;
use AllStore\Core\Helpers;
use AllStore\Models\CatalogRepository;
api_method('GET','POST'); api_require_admin(Request::method()==='POST'?'products.manage':'products.view');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.products',40,300);
 $action=Request::str('action','save',30);
 if($action==='bulk'){
  $ids=Request::ids('ids'); $op=Request::str('op','',30);
  if($ids===[] || !in_array($op,['delete','duplicate','status','category','price_adjust'],true)) Response::fail('Choose rows and a valid operation.',[],422);
  $n=CatalogRepository::bulk($op,$ids,['status'=>Request::str('status','draft',20),'category_id'=>Request::id('category_id'),'percent'=>Request::float('percent',0)]);
  Logger::audit('api.admin.products.bulk','products',null,['operation'=>$op,'count'=>$n]);
  Response::ok($n.' product(s) updated.',['updated'=>$n]);
 }
 if($action==='save'){
  $id=Request::id('id');
  $images=Request::input('images',[]);
  if(!is_array($images)) $images=[];
  $images=array_values(array_filter(array_map(static fn($u)=>is_string($u)?api_public_url($u):'',array_slice($images,0,12))));
  $in=Request::all(); $in['images']=$images;
  $in['category_id']=Request::id('category_id') ?: null;
  $in['brand_id']=Request::id('brand_id') ?: null;
  $in['status']=in_array(Request::str('status','draft',20),['draft','active','inactive','archived'],true)?Request::str('status','draft',20):'draft';
  $in['product_type']=in_array(Request::str('product_type','simple',20),['simple','variable','bundle','digital'],true)?Request::str('product_type','simple',20):'simple';
  $in['video_url']=api_public_url(Request::str('video_url','',500));
  $saved=CatalogRepository::saveProduct($in,$id!==''?$id:null);
  Logger::audit('api.admin.product.saved','products',$saved);
  Response::ok('Product saved.',['id'=>$saved],201);
 }
 Response::fail('Unknown admin product action.',[],422);
}
api_rate('admin.products.read',120,60);
$q=Request::str('q','',80); $status=Request::str('status','',20); $where=['p.deleted_at IS NULL']; $params=[];
if($q!==''){ $where[]='(LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q)'; $params['q']='%'.mb_strtolower($q).'%'; }
if(in_array($status,['draft','active','inactive','archived'],true)){ $where[]='p.status=:s'; $params['s']=$status; }
$page=Request::int('page',1,1,10000); $per=Request::int('per_page',20,1,60); $off=($page-1)*$per;
$rows=Database::all("SELECT p.*,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug,\n (SELECT pi.url FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC,pi.sort_order ASC LIMIT 1) AS image_url\n FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE ".implode(' AND ',$where)." ORDER BY p.created_at DESC LIMIT $per OFFSET $off",$params);
$total=(int)Database::value('SELECT COUNT(*) FROM products p WHERE '.implode(' AND ',$where),$params);
Response::ok('Products loaded.',['items'=>api_safe_products($rows),'total'=>$total,'page'=>$page,'per_page'=>$per,'pages'=>max(1,(int)ceil($total/$per))]);
