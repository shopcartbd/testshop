<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Models\CatalogRepository;
require_once APP_ROOT . '/includes/listing-page.php';
$slug = (string)($_GET['slug'] ?? '');
$cat = CatalogRepository::findCategory($slug);
if (!$cat) { http_response_code(404); require __DIR__ . '/404.php'; return; }
$chain = CatalogRepository::breadcrumbs((string)$cat['id']);
$crumbs = [];
foreach ($chain as $i=>$c) { $crumbs[] = ['name'=>$c['name'], 'url'=> $i === count($chain)-1 ? '' : '/category/'.$c['slug']]; }
render_catalog_page([
    'title'=>$cat['name'].' · AllStore','description'=>$cat['description'],
    'heading'=>$cat['name'],'intro'=>$cat['description'],'crumbs'=>$crumbs,
    'category_id'=>$cat['id'],'filters'=>['category'=>$cat['id']],
]);
