<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Models\CatalogRepository;
require_once APP_ROOT . '/includes/listing-page.php';
$slug = (string)($_GET['slug'] ?? '');
$brand = CatalogRepository::findBrand($slug);
if (!$brand) { http_response_code(404); require __DIR__ . '/404.php'; return; }
render_catalog_page([
    'title'=>$brand['name'].' · AllStore','description'=>$brand['description'],
    'heading'=>$brand['name'],'intro'=>$brand['description'] ?: 'Explore the complete '.$brand['name'].' collection.',
    'crumbs'=>[['name'=>'Brands','url'=>'/brands'],['name'=>$brand['name']]],
    'filters'=>['brand'=>$brand['slug']],
]);
