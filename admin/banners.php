<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'cms.manage','table'=>'banners','title'=>'Banners','subtitle'=>'Scheduled promotional placements with image uploads','active'=>'banners','singular'=>'banner','plural'=>'banners',
 'toggle_column'=>'is_active','deletable'=>true,'search_columns'=>['title','placement'],'order'=>'sort_order ASC, created_at DESC',
 'fields'=>[
  'title'=>['label'=>'Title','type'=>'text','required'=>true,'max'=>160],
  'subtitle'=>['label'=>'Subtitle','type'=>'text','max'=>250],
  'image_url'=>['label'=>'Desktop image','type'=>'image','max'=>500,'span'=>true],
  'mobile_image_url'=>['label'=>'Mobile image','type'=>'image','max'=>500,'span'=>true],
  'link_url'=>['label'=>'Link URL','type'=>'text','max'=>500],
  'placement'=>['label'=>'Placement','type'=>'select','choices'=>['hero'=>'Hero slider','promo'=>'Promotional banner grid','home_mid'=>'Homepage middle','home_wide'=>'Homepage wide','sidebar'=>'Sidebar','category'=>'Category']],
  'sort_order'=>['label'=>'Sort order','type'=>'number','integer'=>true,'default'=>0],
  'starts_at'=>['label'=>'Starts at','type'=>'datetime'],
  'expires_at'=>['label'=>'Expires at','type'=>'datetime'],
  'is_active'=>['label'=>'Active','type'=>'checkbox','hint'=>'Banner is visible','default'=>1],
 ],
 'columns'=>['Title'=>fn($r)=>admin_col($r,'title'),'Placement'=>fn($r)=>admin_col($r,'placement'),'Order'=>fn($r)=>admin_col($r,'sort_order'),'Expires'=>fn($r)=>admin_date($r,'expires_at'),'Status'=>fn($r)=>admin_bool($r,'is_active')],
]);
