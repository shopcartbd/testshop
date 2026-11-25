<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Response;
api_method('POST'); api_require_login(); api_csrf(); api_rate('auth.logout',20,300);
Auth::logout();
Response::ok('Signed out.');
