<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'users.manage','table'=>'permissions','title'=>'Permissions','subtitle'=>'Deny-by-default capability slugs used by RBAC','active'=>'permissions','singular'=>'permission','plural'=>'permissions',
 'deletable'=>false,'search_columns'=>['name','slug','module'],'order'=>'module ASC, slug ASC',
 'fields'=>[
  'name'=>['label'=>'Name','type'=>'text','required'=>true,'max'=>100],
  'slug'=>['label'=>'Slug','type'=>'slug','required'=>true,'max'=>100],
  'module'=>['label'=>'Module','type'=>'text','required'=>true,'max'=>60],
 ],
 'columns'=>['Name'=>fn($r)=>admin_col($r,'name'),'Slug'=>fn($r)=>admin_col($r,'slug'),'Module'=>fn($r)=>admin_col($r,'module')],
]);
