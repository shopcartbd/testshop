<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Helpers;
api_method('POST'); api_csrf(); api_rate('newsletter',8,3600);
$email=Request::email('email');
if($email===''){ Response::fail('Enter a valid email address.',['email'=>'A valid email is required.'],422); }
$row=Database::first('SELECT id,is_active FROM newsletter_subscribers WHERE email=:e',['e'=>$email]);
if($row){ Database::update('newsletter_subscribers',['is_active'=>1],'id=:i',['i'=>$row['id']]); }
else { Database::insert('newsletter_subscribers',['id'=>Helpers::uuid(),'email'=>$email,'is_active'=>1,'created_at'=>Helpers::now()]); }
Response::ok('Subscription saved. No external email provider is required.');
