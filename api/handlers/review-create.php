<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\ReviewService;
api_method('POST'); api_require_login(); api_csrf(); api_rate('review.create',8,3600);
$id=ReviewService::submit((string)Auth::id(),Request::all());
Response::ok('Thank you — your review is awaiting moderation.',['id'=>$id,'status'=>'pending'],201);
