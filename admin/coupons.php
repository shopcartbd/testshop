<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'coupons.manage','table'=>'coupons','title'=>'Coupons','subtitle'=>'Server-validated discounts with usage limits and date windows','active'=>'coupons','singular'=>'coupon','plural'=>'coupons',
 'toggle_column'=>'is_active','deletable'=>true,'search_columns'=>['code'],'order'=>'created_at DESC',
 'before_save'=>static function(array $d): array { if (isset($d['code'])) { $d['code'] = strtoupper((string)$d['code']); } return $d; },
 'fields'=>[
  'code'=>['label'=>'Code','type'=>'text','required'=>true,'max'=>32],
  'type'=>['label'=>'Discount type','type'=>'select','choices'=>['percent'=>'Percentage','fixed'=>'Fixed amount','free_shipping'=>'Free shipping']],
  'percent'=>['label'=>'Percent off','type'=>'number','default'=>0],
  'value_cents'=>['label'=>'Fixed amount off','type'=>'money','default'=>0],
  'min_order_cents'=>['label'=>'Minimum order','type'=>'money','default'=>0],
  'max_discount_cents'=>['label'=>'Maximum discount','type'=>'money','default'=>0],
  'category_id'=>['label'=>'Limit to category','type'=>'reference','choices_sql'=>'SELECT id,name FROM categories WHERE deleted_at IS NULL ORDER BY name','empty_label'=>'All categories'],
  'product_id'=>['label'=>'Limit to product','type'=>'reference','choices_sql'=>"SELECT id,name FROM products WHERE deleted_at IS NULL ORDER BY name LIMIT 300",'empty_label'=>'All products'],
  'usage_limit'=>['label'=>'Total usage limit','type'=>'number','integer'=>true,'default'=>0],
  'per_user_limit'=>['label'=>'Per-user limit','type'=>'number','integer'=>true,'default'=>0],
  'starts_at'=>['label'=>'Starts at','type'=>'datetime'],
  'expires_at'=>['label'=>'Expires at','type'=>'datetime'],
  'first_order_only'=>['label'=>'First order','type'=>'checkbox','hint'=>'Only a customer’s first order'],
  'free_shipping'=>['label'=>'Free shipping','type'=>'checkbox','hint'=>'Waive shipping cost'],
  'is_active'=>['label'=>'Active','type'=>'checkbox','hint'=>'Coupon can be applied','default'=>1],
 ],
 'columns'=>[
  'Code'=>fn($r)=>admin_col($r,'code'),'Type'=>fn($r)=>admin_col($r,'type'),
  'Value'=>fn($r)=>$r['type']==='percent'?Helpers::e((string)$r['percent'].'%'):admin_money($r,'value_cents'),
  'Used'=>fn($r)=>Helpers::e((string)$r['used_count'].' / '.($r['usage_limit'] ?: '∞')),
  'Expires'=>fn($r)=>admin_date($r,'expires_at'),'Status'=>fn($r)=>admin_bool($r,'is_active'),
 ],
]);
