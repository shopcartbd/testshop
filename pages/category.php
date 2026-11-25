<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Models\CatalogRepository;
require_once APP_ROOT . '/includes/listing-page.php';
$slug = (string)($_GET['slug'] ?? '');
$cat = CatalogRepository::findCategory($slug);
if (!$cat) { http_response_code(404); require __DIR__ . '/404.php'; return; }
$parents = CatalogRepository::breadcrumbs((string)$cat['id']);
$crumbs = [];
foreach ($parents as $i=>$p) { $crumbs[] = ['name'=>$p['name'], 'url'=> $i === count($parents)-1 ? '' : '/category/'.$p['slug']]; }
render_catalog_page([
    'title'=>$cat['seo_title'] ?: $cat['name'].' · AllStore',
    'description'=>$cat['seo_description'] ?: $cat['description'],
    'heading'=>$cat['name'], 'intro'=>$cat['description'],
    'banner'=>$cat['banner_url'] ?: '', 'image'=>$cat['image_url'] ?: '/assets/img/hero-marketplace.jpg',
    'crumbs'=>$crumbs, 'category_id'=>$cat['id'], 'filters'=>['category'=>$cat['id']],
]);
