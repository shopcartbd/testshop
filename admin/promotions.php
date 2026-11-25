<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'promotions.manage','table'=>'promotions','title'=>'Promotions','subtitle'=>'Configurable campaign rules and scheduling','active'=>'promotions','singular'=>'promotion','plural'=>'promotions',
 'toggle_column'=>'is_active','deletable'=>true,'search_columns'=>['name','type'],'order'=>'created_at DESC',
 'fields'=>[
  'name'=>['label'=>'Name','type'=>'text','required'=>true,'max'=>120],
  'type'=>['label'=>'Type','type'=>'select','choices'=>['percentage'=>'Percentage','fixed'=>'Fixed amount','bundle'=>'Bundle','buy_x_get_y'=>'Buy X get Y']],
  'value'=>['label'=>'Value','type'=>'number','default'=>0],
  'config'=>['label'=>'Rules JSON','type'=>'json','span'=>true],
  'starts_at'=>['label'=>'Starts at','type'=>'datetime'],
  'ends_at'=>['label'=>'Ends at','type'=>'datetime'],
  'is_active'=>['label'=>'Active','type'=>'checkbox','hint'=>'Campaign is active','default'=>1],
 ],
 'columns'=>['Name'=>fn($r)=>admin_col($r,'name'),'Type'=>fn($r)=>admin_col($r,'type'),'Value'=>fn($r)=>admin_col($r,'value'),'Ends'=>fn($r)=>admin_date($r,'ends_at'),'Status'=>fn($r)=>admin_bool($r,'is_active')],
]);
