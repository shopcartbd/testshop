<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Response;
use AllStore\Services\LocationService;
api_method('GET'); api_rate('locations', 120, 60);
$data = LocationService::publicData();
$data['counts'] = LocationService::counts();
Response::ok('Bangladesh delivery areas loaded.', $data);
