<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Models\OrderRepository;
api_method('GET'); api_require_login(); api_rate('order',90,60);
$key=Request::str('id','',80);
if($key===''){ Response::fail('Order number is required.',[],422); }
$uid=(string)Auth::id();
$o=OrderRepository::findByNumberForUser($key,$uid) ?: Database::first('SELECT * FROM orders WHERE id=:i AND user_id=:u',['i'=>$key,'u'=>$uid]);
if(!$o){ Response::fail('Order not found.',[],404); }
$id=(string)$o['id'];
$items=array_map(static fn($r)=>api_pick($r,['id','name','sku','image_url','unit_price_cents','quantity','discount_cents','tax_cents','total_cents','options']),OrderRepository::items($id));
$history=array_map(static fn($r)=>api_pick($r,['id','from_status','to_status','note','created_at']),OrderRepository::history($id));
$shipment=OrderRepository::shipment($id); $payment=OrderRepository::payment($id); $invoice=OrderRepository::invoice($id);
Response::ok('Order loaded.',[
 'order'=>api_safe_order($o),'items'=>$items,'history'=>$history,
 'shipment'=>$shipment?api_pick($shipment,['id','tracking_number','status','shipped_at','estimated_delivery','delivered_at','courier_name','tracking_url_template']):null,
 'payment'=>$payment?api_pick($payment,['id','provider','method','status','amount_cents','currency','transaction_ref','created_at']):null,
 'invoice'=>$invoice?api_pick($invoice,['id','invoice_number','file_url','created_at']):null,
 'returns'=>array_map(static fn($r)=>api_pick($r,['id','reason','status','created_at']),OrderRepository::returnsForOrder($id)),
 'refunds'=>array_map(static fn($r)=>api_pick($r,['id','amount_cents','status','created_at']),OrderRepository::refunds($id)),
]);
