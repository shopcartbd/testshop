<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/listing-page.php';
render_catalog_page([
    'title'=>'Deals & offers · AllStore','heading'=>'Deals & offers','intro'=>'Selected pieces at a better price, while they last.',
    'banner'=>'/assets/img/promo-wide.jpg','crumbs'=>[['name'=>'Deals & offers']],
    'filters'=>['on_sale'=>1,'sort'=>'discount'],
]);
