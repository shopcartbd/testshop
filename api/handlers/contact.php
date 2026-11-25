<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\SupportService;
api_method('POST'); api_csrf(); api_rate('contact',6,3600);
SupportService::contactMessage(Request::all());
Response::ok('Thank you — your message has been received.');
