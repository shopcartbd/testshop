<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Config;
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /account/\nDisallow: /cart\nDisallow: /checkout\nDisallow: /api/\nDisallow: /storage/\nDisallow: /src/\nDisallow: /database/\nDisallow: /scripts/\nDisallow: /tests/\n";
echo 'Sitemap: ' . rtrim((string)Config::get('app.url'), '/') . "/sitemap.xml\n";
