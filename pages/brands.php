<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Models\CatalogRepository;
require_once APP_ROOT . '/includes/components.php';
$brands = CatalogRepository::brands();
$page = ['title'=>'Brands · AllStore','description'=>'Discover the makers and brands behind the AllStore collection.','body_class'=>'page-brands'];
require APP_ROOT . '/includes/header.php';
echo breadcrumbs([['name'=>'Brands']]);
echo '<section class="page-hero"><div class="container"><span class="eyebrow">Curated makers</span><h1>Featured brands</h1><p>Independent studios and established names, chosen for the way they make things.</p></div></section>';
echo '<section class="section"><div class="container"><div class="category-grid">';
foreach ($brands as $b) {
    echo '<a class="category-card" href="'.Helpers::url('/brand/'.$b['slug']).'"><img src="'.Helpers::e($b['logo_url'] ?: '/assets/img/promo-wide.jpg').'" alt="">'
        .'<span class="overlay"><span><strong>'.Helpers::e($b['name']).'</strong><small style="display:block;color:#dfe7e3;margin-top:5px">'.(int)($b['product_count'] ?? 0).' products</small></span></span></a>';
}
echo '</div></div></section>';
require APP_ROOT . '/includes/footer.php';
