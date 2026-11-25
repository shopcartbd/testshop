<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\SearchService;
api_method('GET');
$d=SearchService::suggest(Request::str('q','',60));
$d['products']=api_safe_products((array)($d['products'] ?? []));
Response::ok('Suggestions loaded.',$d);
