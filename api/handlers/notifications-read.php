<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\CustomerRepository;
api_method('POST'); api_require_login(); api_csrf(); api_rate('notifications.read',60,300);
CustomerRepository::markNotificationsRead((string)Auth::id(),Request::id('id') ?: null);
Response::ok('Notifications updated.');
