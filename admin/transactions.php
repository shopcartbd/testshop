<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'orders.manage','table'=>'transactions','title'=>'Transactions','subtitle'=>'Charges, refunds and adjustments tied to orders','active'=>'transactions','singular'=>'transaction','plural'=>'transactions',
 'deletable'=>false,'search_columns'=>['type','status','reference'],'order'=>'created_at DESC',
 'fields'=>[
  'order_id'=>['label'=>'Order','type'=>'reference','choices_sql'=>'SELECT id,order_number AS name FROM orders ORDER BY placed_at DESC LIMIT 300','empty_label'=>'Choose an order'],
  'payment_id'=>['label'=>'Payment','type'=>'reference','choices_sql'=>'SELECT id,transaction_ref AS name FROM payments ORDER BY created_at DESC LIMIT 300','empty_label'=>'No linked payment'],
  'type'=>['label'=>'Type','type'=>'select','choices'=>['charge'=>'Charge','refund'=>'Refund','adjustment'=>'Adjustment']],
  'amount_cents'=>['label'=>'Amount','type'=>'money','default'=>0],
  'status'=>['label'=>'Status','type'=>'select','choices'=>['pending'=>'Pending','completed'=>'Completed','failed'=>'Failed']],
  'reference'=>['label'=>'Reference','type'=>'text','max'=>180],
 ],
 'columns'=>['Type'=>fn($r)=>admin_col($r,'type'),'Status'=>fn($r)=>admin_status($r),'Amount'=>fn($r)=>admin_money($r,'amount_cents'),'Reference'=>fn($r)=>admin_col($r,'reference'),'Date'=>fn($r)=>admin_date($r,'created_at')],
]);
