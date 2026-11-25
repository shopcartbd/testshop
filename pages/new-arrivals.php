<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/listing-page.php';
render_catalog_page([
    'title'=>'New arrivals · AllStore','heading'=>'New arrivals','intro'=>'Freshly added to the collection.',
    'banner'=>'','crumbs'=>[['name'=>'New arrivals']],
    'filters'=>['new_arrival'=>1,'sort'=>'newest'],
]);
