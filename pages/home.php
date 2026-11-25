<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Services\HomepageService;
use AllStore\Core\Config;
require_once APP_ROOT . '/includes/home-sections.php';
$sections = HomepageService::resolve();
$page = [
    'title' => (string)Config::get('app.name') . ' — ' . Helpers::setting('store_tagline','Everything you need, thoughtfully curated'),
    'description' => Helpers::setting('store_tagline','A modern marketplace for considered everyday goods.'),
    'body_class' => 'page-home',
];
require APP_ROOT . '/includes/header.php';
foreach ($sections as $s) { echo render_home_section($s); }
require APP_ROOT . '/includes/footer.php';
