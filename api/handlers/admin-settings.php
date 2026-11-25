<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
api_method('GET','POST'); api_require_admin('settings.manage');
$keys=['store_tagline','announcement_text','announcement_enabled','footer_about','newsletter_heading','newsletter_text','contact_email','contact_phone','store_address','social_instagram','social_x','social_facebook','default_country','default_shipping_cents','free_shipping_threshold_cents','cod_enabled'];
if(Request::method()==='POST'){
 api_csrf(); api_rate('admin.settings',20,300);
 foreach($keys as $key){
  if(!Request::has($key)) continue;
  $v=Request::str($key,'',2000);
  if(str_ends_with($key,'_cents') && (!is_numeric($v) || (int)$v<0)) Response::fail('Numeric settings must be non-negative.',[$key=>'Invalid number.'],422);
  if($key==='contact_email' && $v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL)) Response::fail('Enter a valid contact email.',[$key=>'Invalid email.'],422);
  if(str_starts_with($key,'social_') && $v!=='' && $v!=='#' && api_public_url($v)==='') Response::fail('Social links must be HTTPS URLs or #.',[$key=>'Invalid URL.'],422);
  if($key==='default_country') $v=strtoupper(substr(preg_replace('/[^A-Za-z]/','',$v) ?: 'US',0,2));
  Helpers::setSetting($key,$v,'general');
 }
 Logger::audit('api.admin.settings.updated','settings'); Response::ok('Settings saved.');
}
$out=[]; foreach($keys as $key) $out[$key]=Helpers::setting($key,'');
Response::ok('Settings loaded.',['settings'=>$out,'note'=>'Environment variables and secrets are never exposed through this endpoint.']);
