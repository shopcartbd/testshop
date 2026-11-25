<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Auth;
use AllStore\Models\InventoryRepository;
api_method('GET','POST'); api_require_admin('inventory.manage');
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.inventory',30,300);
 $pid=Request::id('product_id'); $type=Request::str('movement_type','adjustment',20);
 if($pid==='' || !in_array($type,['in','out','adjustment','damage'],true)) Response::fail('Choose a valid product and movement type.',[],422);
 $after=InventoryRepository::adjust($pid,Request::id('variant_id'),Request::int('delta',0,-1000000,1000000),$type,Request::str('note','',500),Auth::id());
 Response::ok('Stock movement recorded.',['product_id'=>$pid,'quantity_after'=>$after]);
}
$f=['q'=>Request::str('q','',80),'stock'=>Request::str('stock','',10),'page'=>Request::int('page',1,1,10000)];
$r=InventoryRepository::rows($f);
Response::ok('Inventory loaded.',[
 'items'=>array_map(static fn($x)=>api_pick($x,['id','name','sku','stock','reserved','damaged','low_stock_threshold','status']),$r['items']),
 'total'=>$r['total'],'page'=>$r['page'],'pages'=>$r['pages'],
 'movements'=>array_map(static fn($m)=>api_pick($m,['id','product_id','product_name','movement_type','quantity_change','quantity_after','note','created_at']),InventoryRepository::movements('',50)),
]);
