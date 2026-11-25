<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
api_method('GET','POST'); api_require_admin('coupons.manage');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.coupons',30,300);
 $action=Request::str('action','save',20); $id=Request::id('id');
 if($action==='delete'){ if($id==='') Response::fail('Missing id.',[],422); Database::delete('coupons','id=:i',['i'=>$id]); Logger::audit('api.admin.coupon.deleted','coupons',$id); Response::ok('Coupon removed.'); }
 $code=strtoupper(trim(Request::str('code','',32))); $type=Request::str('type','percent',20);
 if(!preg_match('/^[A-Z0-9\-]{2,32}$/',$code) || !in_array($type,['percent','fixed','free_shipping'],true)) Response::fail('Enter a valid code and discount type.',[],422);
 if(Database::first('SELECT id FROM coupons WHERE code=:c AND id<>:i',['c'=>$code,'i'=>$id])) Response::fail('That code already exists.',[],409);
 $data=['code'=>$code,'type'=>$type,'percent'=>Request::float('percent',0),'value_cents'=>(int)round(Request::float('value',0)*100),
  'min_order_cents'=>(int)round(Request::float('min_order',0)*100),'max_discount_cents'=>(int)round(Request::float('max_discount',0)*100),
  'category_id'=>Request::id('category_id') ?: null,'product_id'=>Request::id('product_id') ?: null,
  'usage_limit'=>Request::int('usage_limit',0,0,1000000),'per_user_limit'=>Request::int('per_user_limit',0,0,1000000),
  'starts_at'=>Request::str('starts_at','',40) ?: null,'expires_at'=>Request::str('expires_at','',40) ?: null,
  'first_order_only'=>Request::bool('first_order_only')?1:0,'free_shipping'=>Request::bool('free_shipping')?1:0,
  'is_active'=>Request::bool('is_active')?1:0,'updated_at'=>Helpers::now()];
 if($id!==''){ Database::update('coupons',$data,'id=:i',['i'=>$id]); $saved=$id; }
 else { $saved=Helpers::uuid(); $data['id']=$saved; $data['used_count']=0; $data['created_at']=Helpers::now(); Database::insert('coupons',$data); }
 Logger::audit('api.admin.coupon.saved','coupons',$saved); Response::ok('Coupon saved.',['id'=>$saved],201);
}
$rows=Database::all('SELECT * FROM coupons ORDER BY created_at DESC LIMIT 200');
Response::ok('Coupons loaded.',['items'=>array_map(static fn($r)=>api_pick($r,['id','code','type','percent','value_cents','min_order_cents','max_discount_cents','usage_limit','used_count','starts_at','expires_at','is_active']),$rows)]);
