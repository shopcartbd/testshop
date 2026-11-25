<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
require_once APP_ROOT . '/includes/admin-crud.php';
admin_crud_page([
 'permission'=>'reviews.moderate','table'=>'reviews','title'=>'Reviews','subtitle'=>'Moderation and store replies','active'=>'reviews','singular'=>'review','plural'=>'reviews',
 'deletable'=>true,'search_columns'=>['title','body','status'],'order'=>'created_at DESC',
 'sql'=>'SELECT r.*, p.name AS product_name, u.full_name AS customer_name FROM reviews r LEFT JOIN products p ON p.id=r.product_id LEFT JOIN profiles u ON u.id=r.user_id',
 'fields'=>[
  'rating'=>['label'=>'Rating','type'=>'select','choices'=>['5'=>'5 stars','4'=>'4 stars','3'=>'3 stars','2'=>'2 stars','1'=>'1 star']],
  'title'=>['label'=>'Title','type'=>'text','max'=>120],
  'body'=>['label'=>'Review','type'=>'textarea','span'=>true,'max'=>2000],
  'status'=>['label'=>'Status','type'=>'select','choices'=>['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected']],
  'admin_reply'=>['label'=>'Store reply','type'=>'textarea','span'=>true,'max'=>1000],
  'is_verified'=>['label'=>'Verified purchase','type'=>'checkbox','hint'=>'Verified'],
 ],
 'columns'=>['Product'=>fn($r)=>admin_col($r,'product_name'),'Customer'=>fn($r)=>admin_col($r,'customer_name'),'Rating'=>fn($r)=>Helpers::e((string)$r['rating'].' / 5'),'Status'=>fn($r)=>admin_status($r),'Created'=>fn($r)=>admin_date($r,'created_at')],
]);
