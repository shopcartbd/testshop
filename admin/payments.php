<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'orders.manage','table'=>'payments','title'=>'Payments','subtitle'=>'Provider records — success is only confirmed by verified webhooks or admin action','active'=>'payments','singular'=>'payment','plural'=>'payments',
 'deletable'=>false,'search_columns'=>['provider','method','status','transaction_ref'],'order'=>'created_at DESC',
 'sql'=>'SELECT p.*, o.order_number FROM payments p LEFT JOIN orders o ON o.id=p.order_id',
 'where'=>\AllStore\Core\Request::str('cod','',3) === '1' ? '' : "p.provider <> 'cod'",
 'before_table'=>static function (): string {
     $base = \AllStore\Core\Request::path();
     $show = \AllStore\Core\Request::str('cod','',3) === '1';
     $href = $show ? $base : $base.'?cod=1';
     return '<div class="alert alert-info" style="margin-bottom:14px">'.icon('info',16).'<span>Cash on Delivery orders are not listed here — the customer pays the courier and payment is settled on the order page. '
         .'<a href="'.\AllStore\Core\Helpers::e($href).'">'.($show ? 'Hide COD records' : 'Show COD records').'</a></span></div>';
 },
 'fields'=>[
  'order_id'=>['label'=>'Order','type'=>'reference','choices_sql'=>'SELECT id,order_number AS name FROM orders ORDER BY placed_at DESC LIMIT 300','empty_label'=>'Choose an order'],
  'provider'=>['label'=>'Provider','type'=>'select','choices'=>['cod'=>'Cash on Delivery','stripe'=>'Stripe','manual'=>'Manual']],
  'method'=>['label'=>'Method','type'=>'text','max'=>40],
  'status'=>['label'=>'Status','type'=>'select','choices'=>['pending'=>'Pending','authorized'=>'Authorized','paid'=>'Paid','failed'=>'Failed','refunded'=>'Refunded','partially_refunded'=>'Partially Refunded']],
  'amount_cents'=>['label'=>'Amount','type'=>'money','default'=>0],
  'currency'=>['label'=>'Currency','type'=>'text','max'=>3,'default'=>'USD'],
  'transaction_ref'=>['label'=>'Provider reference','type'=>'text','max'=>180],
  'payload'=>['label'=>'Provider payload','type'=>'json','span'=>true],
 ],
 'columns'=>['Order'=>fn($r)=>admin_col($r,'order_number'),'Provider'=>fn($r)=>admin_col($r,'provider'),'Status'=>fn($r)=>admin_status($r),'Amount'=>fn($r)=>admin_money($r,'amount_cents'),'Reference'=>fn($r)=>admin_col($r,'transaction_ref'),'Date'=>fn($r)=>admin_date($r,'created_at')],
]);
