<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once APP_ROOT . '/includes/listing-page.php';
render_catalog_page([
    'title'=>'Best sellers · AllStore','heading'=>'Best sellers','intro'=>'The products our customers keep coming back for.',
    'banner'=>'','crumbs'=>[['name'=>'Best sellers']],
    'filters'=>['best_seller'=>1,'sort'=>'best_selling'],
]);
