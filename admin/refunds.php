<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'orders.manage','table'=>'refunds','title'=>'Refunds','subtitle'=>'Refund records linked to orders and returns','active'=>'refunds','singular'=>'refund','plural'=>'refunds',
 'deletable'=>false,'search_columns'=>['status','provider_ref','note'],'order'=>'created_at DESC',
 'fields'=>[
  'order_id'=>['label'=>'Order','type'=>'reference','choices_sql'=>'SELECT id,order_number AS name FROM orders ORDER BY placed_at DESC LIMIT 300','empty_label'=>'Choose an order'],
  'return_request_id'=>['label'=>'Return request','type'=>'reference','choices_sql'=>'SELECT id,reason AS name FROM return_requests ORDER BY created_at DESC LIMIT 300','empty_label'=>'No linked return'],
  'amount_cents'=>['label'=>'Amount','type'=>'money','default'=>0],
  'status'=>['label'=>'Status','type'=>'select','choices'=>['pending'=>'Pending','processing'=>'Processing','completed'=>'Completed','failed'=>'Failed']],
  'provider_ref'=>['label'=>'Provider reference','type'=>'text','max'=>180],
  'note'=>['label'=>'Note','type'=>'textarea','span'=>true,'max'=>500],
 ],
 'columns'=>['Amount'=>fn($r)=>admin_money($r,'amount_cents'),'Status'=>fn($r)=>admin_status($r),'Reference'=>fn($r)=>admin_col($r,'provider_ref'),'Created'=>fn($r)=>admin_date($r,'created_at')],
]);
