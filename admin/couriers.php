<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'settings.manage','table'=>'couriers','title'=>'Couriers','subtitle'=>'Tracking URL templates and optional API endpoints','active'=>'couriers','singular'=>'courier','plural'=>'couriers',
 'toggle_column'=>'is_active','deletable'=>true,'search_columns'=>['name','code'],'order'=>'name ASC',
 'fields'=>[
  'name'=>['label'=>'Name','type'=>'text','required'=>true,'max'=>120],
  'code'=>['label'=>'Code','type'=>'text','required'=>true,'max'=>40],
  'tracking_url_template'=>['label'=>'Tracking URL template','type'=>'text','max'=>500,'help'=>'Use {tracking} as a placeholder.'],
  'api_base'=>['label'=>'API base URL','type'=>'text','max'=>500],
  'is_active'=>['label'=>'Active','type'=>'checkbox','hint'=>'Courier is active','default'=>1],
 ],
 'columns'=>['Name'=>fn($r)=>admin_col($r,'name'),'Code'=>fn($r)=>admin_col($r,'code'),'Tracking template'=>fn($r)=>admin_col($r,'tracking_url_template'),'Status'=>fn($r)=>admin_bool($r,'is_active')],
]);
