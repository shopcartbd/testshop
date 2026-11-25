<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'cms.manage','table'=>'pages','title'=>'Pages','subtitle'=>'About, policies, legal and footer content','active'=>'pages','singular'=>'page','plural'=>'pages',
 'toggle_column'=>'is_published','deletable'=>true,'slug_field'=>'slug','search_columns'=>['slug','title','body'],'order'=>'title ASC',
 'fields'=>[
  'slug'=>['label'=>'Slug','type'=>'slug','required'=>true,'max'=>80],
  'title'=>['label'=>'Title','type'=>'text','required'=>true,'max'=>160],
  'body'=>['label'=>'Body (plain text)','type'=>'textarea','span'=>true,'max'=>20000,'help'=>'Rich HTML is intentionally disabled; output is escaped.'],
  'seo_title'=>['label'=>'SEO title','type'=>'text','max'=>160],
  'seo_description'=>['label'=>'SEO description','type'=>'textarea','max'=>300],
  'is_published'=>['label'=>'Published','type'=>'checkbox','hint'=>'Visible on the storefront','default'=>1],
 ],
 'columns'=>['Title'=>fn($r)=>admin_col($r,'title'),'Slug'=>fn($r)=>admin_col($r,'slug'),'Status'=>fn($r)=>admin_bool($r,'is_published','Published','Draft'),'Updated'=>fn($r)=>admin_date($r,'updated_at')],
]);
