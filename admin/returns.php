<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'orders.manage','table'=>'return_requests','title'=>'Return requests','subtitle'=>'Customer return and exchange workflow','active'=>'returns','singular'=>'return request','plural'=>'return requests',
 'deletable'=>false,'search_columns'=>['reason','status','request_type'],'order'=>'created_at DESC',
 'sql'=>'SELECT rr.*, o.order_number, oi.name AS product_name FROM return_requests rr LEFT JOIN orders o ON o.id=rr.order_id LEFT JOIN order_items oi ON oi.id=rr.order_item_id',
 'fields'=>[
  'order_id'=>['label'=>'Order','type'=>'reference','choices_sql'=>'SELECT id,order_number AS name FROM orders ORDER BY placed_at DESC LIMIT 300','empty_label'=>'Choose an order'],
  'order_item_id'=>['label'=>'Order item','type'=>'reference','choices_sql'=>'SELECT id,name FROM order_items ORDER BY created_at DESC LIMIT 500','empty_label'=>'Choose an item'],
  'reason'=>['label'=>'Reason','type'=>'text','required'=>true,'max'=>180],
  'detail'=>['label'=>'Details','type'=>'textarea','span'=>true,'max'=>1000],
  'request_type'=>['label'=>'Request type','type'=>'select','choices'=>['return'=>'Return / refund','exchange'=>'Exchange']],
  'status'=>['label'=>'Status','type'=>'select','choices'=>['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','completed'=>'Completed']],
  'admin_note'=>['label'=>'Admin note','type'=>'textarea','span'=>true,'max'=>1000],
  'customer_note'=>['label'=>'Customer note','type'=>'textarea','span'=>true,'max'=>1000],
 ],
 'columns'=>['Order'=>fn($r)=>admin_col($r,'order_number'),'Product'=>fn($r)=>admin_col($r,'product_name'),'Reason'=>fn($r)=>admin_col($r,'reason'),'Status'=>fn($r)=>admin_status($r),'Created'=>fn($r)=>admin_date($r,'created_at')],
]);
