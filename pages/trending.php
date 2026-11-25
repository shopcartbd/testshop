<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/listing-page.php';
render_catalog_page([
    'title'=>'Trending now · AllStore','heading'=>'Trending now','intro'=>'What everyone is looking at this week.',
    'banner'=>'','crumbs'=>[['name'=>'Trending now']],
    'filters'=>['trending'=>1],
]);
