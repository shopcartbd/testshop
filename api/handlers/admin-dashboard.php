<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Models\ReportRepository;
api_method('GET'); api_require_admin('dashboard.view'); api_rate('admin.dashboard',90,60);
$r=ReportRepository::dashboard(Request::int('days',30,7,365));
Response::ok('Dashboard loaded.',[
 'metrics'=>$r['metrics'],'sales_chart'=>$r['sales_chart'],'orders_chart'=>$r['orders_chart'],
 'top_products'=>$r['top_products'],'sales_by_category'=>$r['sales_by_category'],
 'sales_by_payment'=>$r['sales_by_payment'],'recent_orders'=>api_safe_orders($r['recent_orders']),
 'search_stats'=>$r['search_stats'],'days'=>$r['days'],
]);
