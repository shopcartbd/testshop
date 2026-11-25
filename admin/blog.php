<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'cms.manage','table'=>'blogs','title'=>'Journal','subtitle'=>'Editorial posts with SEO and scheduling','active'=>'blog','singular'=>'article','plural'=>'articles',
 'toggle_column'=>'is_published','deletable'=>true,'slug_field'=>'slug','search_columns'=>['slug','title','excerpt','body'],'order'=>'COALESCE(published_at, created_at) DESC',
 'fields'=>[
  'slug'=>['label'=>'Slug','type'=>'slug','required'=>true,'max'=>100],
  'title'=>['label'=>'Title','type'=>'text','required'=>true,'max'=>180],
  'excerpt'=>['label'=>'Excerpt','type'=>'textarea','span'=>true,'max'=>500],
  'body'=>['label'=>'Body','type'=>'textarea','span'=>true,'max'=>20000],
  'image_url'=>['label'=>'Cover image','type'=>'image','max'=>500,'span'=>true],
  'author'=>['label'=>'Author','type'=>'text','max'=>100],
  'published_at'=>['label'=>'Published at','type'=>'datetime'],
  'seo_title'=>['label'=>'SEO title','type'=>'text','max'=>160],
  'seo_description'=>['label'=>'SEO description','type'=>'textarea','max'=>300],
  'is_published'=>['label'=>'Published','type'=>'checkbox','hint'=>'Visible in the Journal','default'=>1],
 ],
 'columns'=>['Title'=>fn($r)=>admin_col($r,'title'),'Slug'=>fn($r)=>admin_col($r,'slug'),'Author'=>fn($r)=>admin_col($r,'author'),'Published'=>fn($r)=>admin_date($r,'published_at'),'Status'=>fn($r)=>admin_bool($r,'is_published','Published','Draft')],
]);
