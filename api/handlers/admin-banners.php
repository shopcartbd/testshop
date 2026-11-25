<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
api_method('GET','POST'); api_require_admin('cms.manage');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.banners',30,300);
 $action=Request::str('action','save',20); $id=Request::id('id');
 if($action==='delete'){ if($id==='') Response::fail('Missing id.',[],422); Database::delete('banners','id=:i',['i'=>$id]); Logger::audit('api.admin.banner.deleted','banners',$id); Response::ok('Banner removed.'); }
 $image=api_public_url(Request::str('image_url','',500)); $mobile=api_public_url(Request::str('mobile_image_url','',500));
 $placement=Request::str('placement','hero',30);
 if($image==='' || Request::str('title','',160)==='' || !in_array($placement,['hero','promo','home_mid','home_wide','sidebar','category'],true)) Response::fail('Title, placement and a safe image URL are required.',[],422);
 $data=['title'=>api_text('title','',160),'subtitle'=>api_text('subtitle','',250),'image_url'=>$image,'mobile_image_url'=>$mobile ?: $image,
  'link_url'=>api_public_url(Request::str('link_url','',500)) ?: '/shop','placement'=>$placement,
  'sort_order'=>Request::int('sort_order',0,0,10000),'starts_at'=>Request::str('starts_at','',40) ?: null,
  'expires_at'=>Request::str('expires_at','',40) ?: null,'is_active'=>Request::bool('is_active')?1:0,'updated_at'=>Helpers::now()];
 if($id!==''){ Database::update('banners',$data,'id=:i',['i'=>$id]); $saved=$id; }
 else { $saved=Helpers::uuid(); $data['id']=$saved; $data['created_at']=Helpers::now(); Database::insert('banners',$data); }
 Logger::audit('api.admin.banner.saved','banners',$saved); Response::ok('Banner saved.',['id'=>$saved],201);
}
$rows=Database::all('SELECT * FROM banners ORDER BY sort_order,created_at DESC LIMIT 200');
Response::ok('Banners loaded.',['items'=>array_map(static fn($r)=>api_pick($r,['id','title','subtitle','image_url','mobile_image_url','link_url','placement','sort_order','starts_at','expires_at','is_active']),$rows)]);
