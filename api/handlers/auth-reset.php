<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
api_method('POST'); api_csrf(); api_rate('auth.reset',8,3600);
$token=Request::str('token','',200); $password=(string)Request::input('password','');
if($token===''){ Response::fail('A reset token is required.',['token'=>'Token is required.'],422); }
if(!Auth::resetPassword($token,$password)){ Response::fail('This reset link is invalid or has expired.',[],410); }
Response::ok('Password updated. Please sign in.');
