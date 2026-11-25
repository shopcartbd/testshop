<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
api_method('POST'); api_require_login(); api_csrf(); api_rate('account.password',8,600);
Auth::changePassword((string)Request::input('current_password',''),(string)Request::input('new_password',''));
Response::ok('Password changed.');
