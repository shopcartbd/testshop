<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'brands.manage','table'=>'brands','title'=>'Brands','subtitle'=>'Dynamic brand pages, logos and merchandising','active'=>'brands','singular'=>'brand','plural'=>'brands',
 'slug_field'=>'slug','toggle_column'=>'is_active','deletable'=>true,'soft_delete'=>false,
 'search_columns'=>['name','slug','description'],'order'=>'sort_order ASC, name ASC',
 'fields'=>[
   'name'=>['label'=>'Brand name','type'=>'text','required'=>true,'max'=>120],
   'slug'=>['label'=>'URL slug','type'=>'slug','slug_from'=>'name','max'=>120],
   'logo_url'=>['label'=>'Logo','type'=>'image','max'=>500],
   'description'=>['label'=>'Description','type'=>'textarea','span'=>true,'max'=>2000],
   'website'=>['label'=>'Website','type'=>'text','max'=>300],
   'sort_order'=>['label'=>'Sort order','type'=>'number','integer'=>true,'default'=>0],
   'is_active'=>['label'=>'Visible','type'=>'checkbox','hint'=>'Brand is active','default'=>1],
 ],
 'columns'=>[
   'Brand'=>fn($r)=>admin_col($r,'name'), 'Slug'=>fn($r)=>admin_col($r,'slug'),
   'Products'=>fn($r)=>Helpers::e((string)\AllStore\Core\Database::value('SELECT COUNT(*) FROM products WHERE brand_id=:b AND deleted_at IS NULL',['b'=>$r['id']])),
   'Order'=>fn($r)=>admin_col($r,'sort_order'), 'Status'=>fn($r)=>admin_bool($r,'is_active'),
 ],
]);
