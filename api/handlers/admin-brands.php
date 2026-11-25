<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\Database;
use AllStore\Models\CatalogRepository;
api_method('GET','POST'); api_require_admin('brands.manage');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.brands',40,300);
 $id=Request::id('id');
 if(Request::str('action','save',20)==='delete'){
  if($id==='') Response::fail('Missing id.',[],422);
  Database::delete('brands','id=:i',['i'=>$id]); Logger::audit('api.admin.brand.deleted','brands',$id); Response::ok('Brand removed.');
 }
 $in=Request::all();
 $in['logo_url']=api_public_url(Request::str('logo_url','',500));
 $in['website']=api_public_url(Request::str('website','',500));
 $saved=CatalogRepository::saveBrand($in,$id!==''?$id:null);
 Logger::audit('api.admin.brand.saved','brands',$saved); Response::ok('Brand saved.',['id'=>$saved],201);
}
Response::ok('Brands loaded.',['items'=>array_map(static fn($b)=>api_pick($b,['id','name','slug','logo_url','description','website','is_active','sort_order']),CatalogRepository::brands(false))]);
