<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Auth;
use AllStore\Core\Response;
api_method('POST'); api_csrf(); api_rate('auth.login',10,900);
$r=Auth::attempt(Request::str('email','',254),(string)Request::input('password',''));
if(empty($r['ok'])){ Response::fail((string)$r['message'],[],401); }
Response::ok('Signed in successfully.',array_merge(['user'=>api_safe_user($r['user'])],api_counts()));
