<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Config;
use AllStore\Core\Database;
use AllStore\Core\Response;
use AllStore\Services\MailService;
use AllStore\Services\PaymentService;
use AllStore\Services\UploadService;
api_method('GET');
$db=false; try { Database::pdo(); $db=(bool)Database::value('SELECT 1'); } catch (\Throwable) {}
$data=[
 'status'=>$db?'ok':'degraded','php'=>PHP_VERSION,'database'=>$db?Database::driver():'unavailable',
 'environment'=>(string)Config::get('app.env'),
 'integrations'=>['mail'=>MailService::configured(),'online_payments'=>PaymentService::stripeConfigured(),'storage'=>UploadService::configured()],
 'timestamp'=>gmdate('c'),
];
Response::json($db,'Health check completed.',$data,[],$db?200:503);
