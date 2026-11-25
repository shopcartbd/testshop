<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Database;
use AllStore\Core\Helpers;
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'inventory.manage','table'=>'warehouses','title'=>'Warehouses','subtitle'=>'Future-ready multi-location stock locations','active'=>'warehouses','singular'=>'warehouse','plural'=>'warehouses',
 'toggle_column'=>'is_active','deletable'=>false,'search_columns'=>['name','code','address'],'order'=>'is_default DESC, name ASC',
 'before_save'=>static function(array $data): array {
    if (!empty($data['is_default'])) { Database::exec('UPDATE warehouses SET is_default=0'); }
    return $data;
 },
 'fields'=>[
   'name'=>['label'=>'Warehouse name','type'=>'text','required'=>true,'max'=>120],
   'code'=>['label'=>'Code','type'=>'text','required'=>true,'max'=>40,'help'=>'Uppercase letters, numbers and hyphens are recommended.'],
   'address'=>['label'=>'Address','type'=>'textarea','span'=>true,'max'=>500],
   'is_default'=>['label'=>'Default','type'=>'checkbox','hint'=>'Use for new stock movements'],
   'is_active'=>['label'=>'Active','type'=>'checkbox','hint'=>'Warehouse is active','default'=>1],
 ],
 'columns'=>[
   'Name'=>fn($r)=>admin_col($r,'name'),'Code'=>fn($r)=>admin_col($r,'code'),
   'Default'=>fn($r)=>admin_bool($r,'is_default','Yes','No'),'Status'=>fn($r)=>admin_bool($r,'is_active'),
   'Created'=>fn($r)=>admin_date($r,'created_at'),
 ],
]);
