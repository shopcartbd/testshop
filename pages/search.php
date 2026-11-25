<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Request;
require_once APP_ROOT . '/includes/listing-page.php';
$q = Request::str('q','',120);
render_catalog_page([
    'title'=>($q !== '' ? 'Search: '.$q : 'Search') . ' · AllStore',
    'heading'=>$q !== '' ? 'Results for “'.$q.'”' : 'Search products',
    'intro'=>$q !== '' ? 'Matches across product names, SKUs, brands and categories.' : 'Search by name, SKU or brand.',
    'crumbs'=>[['name'=>'Search']], 'use_search'=>true, 'filters'=>[],
]);
