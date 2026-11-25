<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\CustomerRepository;
api_require_login();
if(Request::method()==='POST'){
 api_csrf(); api_rate('account.profile',20,300);
 CustomerRepository::updateProfile((string)Auth::id(),Request::all());
}
elseif(Request::method()!=='GET'){ Response::fail('Method not allowed.',[],405); }
Response::ok('Profile loaded.',['user'=>api_safe_user(Auth::user() ?: [])]);
