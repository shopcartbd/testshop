<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Auth;
use AllStore\Core\Response;
api_method('POST'); api_csrf(); api_rate('auth.register',5,3600);
Auth::register(Request::all());
$r=Auth::attempt(Request::str('email','',254),(string)Request::input('password',''));
if(empty($r['ok'])){ Response::fail('Your account was created, but automatic sign-in failed. Please sign in.',[],202); }
Response::ok('Welcome to AllStore.',['user'=>api_safe_user($r['user'])],201);
