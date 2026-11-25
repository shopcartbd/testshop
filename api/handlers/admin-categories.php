<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\Database;
use AllStore\Models\CatalogRepository;
api_method('GET','POST'); api_require_admin('categories.manage');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.categories',40,300);
 $action=Request::str('action','save',20);
 $id=Request::id('id');
 if($action==='delete'){ if($id==='') Response::fail('Missing id.',[],422); CatalogRepository::deleteCategory($id); Logger::audit('api.admin.category.deleted','categories',$id); Response::ok('Category archived.'); }
 if($action!=='save') Response::fail('Unknown action.',[],422);
 $in=Request::all();
 $in['parent_id']=Request::id('parent_id') ?: null;
 $in['image_url']=api_public_url(Request::str('image_url','',500));
 $in['banner_url']=api_public_url(Request::str('banner_url','',500));
 $saved=CatalogRepository::saveCategory($in,$id!==''?$id:null);
 Database::delete('category_attributes','category_id=:c',['c'=>$saved]);
 $allowed=array_column(CatalogRepository::allAttributes(),'id');
 foreach(Request::ids('attribute_ids') as $aid) if(in_array($aid,$allowed,true)) Database::insert('category_attributes',['category_id'=>$saved,'attribute_id'=>$aid,'is_filterable'=>1,'sort_order'=>0]);
 Logger::audit('api.admin.category.saved','categories',$saved);
 Response::ok('Category saved.',['id'=>$saved],201);
}
$cats=array_map(static fn($c)=>api_pick($c,['id','parent_id','name','slug','image_url','icon','is_active','is_featured','sort_order','product_count']),CatalogRepository::allCategories());
Response::ok('Categories loaded.',['items'=>$cats,'attributes'=>CatalogRepository::allAttributes()]);
