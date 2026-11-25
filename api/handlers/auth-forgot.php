<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Config;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Services\MailService;
api_method('POST'); api_csrf(); api_rate('auth.forgot',5,3600);
$email=Request::email('email');
if($email===''){ Response::fail('Enter a valid email address.',['email'=>'A valid email is required.'],422); }
$token=Auth::requestPasswordReset($email);
$delivery='not_applicable';
if($token!==null){
 if(!MailService::configured()){
  $delivery='email_not_configured';
 } else {
  $link=rtrim((string)Config::get('app.url'),'/').'/reset-password?token='.rawurlencode($token);
  $mail=MailService::send($email,'Reset your AllStore password',"Use this single-use link within one hour to choose a new password:\n".$link."\n\nIf you did not request this, ignore this message.");
  $delivery=!empty($mail['ok'])?'sent':'provider_failed';
 }
}
Response::ok('If an account exists for that address, a one-time reset link has been issued.',[
 'delivery'=>$delivery,
 'notice'=>$delivery==='email_not_configured'?'Email delivery is not configured; the store administrator must configure MAIL_* variables.':($delivery==='provider_failed'?'The mail provider could not deliver the link. Contact support.':null),
]);
