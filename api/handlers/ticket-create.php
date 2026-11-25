<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Database;
use AllStore\Services\SupportService;
api_method('POST'); api_require_login(); api_csrf(); api_rate('ticket.create',8,3600);
$u=Auth::user() ?: [];
$number=SupportService::createTicket((string)Auth::id(),(string)($u['email'] ?? ''),Request::all(),$_FILES['attachment'] ?? null);
$t=Database::first('SELECT ticket_number,status,subject FROM support_tickets WHERE ticket_number=:n',['n'=>$number]);
Response::ok('Support ticket created.',['ticket'=>api_pick($t ?: [],['ticket_number','status','subject'])],201);
