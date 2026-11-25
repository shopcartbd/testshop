<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/listing-page.php';
render_catalog_page([
    'title'=>'Shop all products · AllStore','heading'=>'Shop all products',
    'intro'=>'Browse the complete collection across every category. Filter by price, brand, rating and availability.',
    'crumbs'=>[['name'=>'Shop']], 'filters'=>[],
]);
