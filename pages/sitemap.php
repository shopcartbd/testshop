<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Config;
use AllStore\Core\Database;
use AllStore\Core\Helpers;
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$base = rtrim((string)Config::get('app.url'), '/');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
$urls = ['/', '/shop', '/brands', '/deals', '/best-sellers', '/new-arrivals', '/trending', '/flash-sale', '/about', '/contact', '/faq', '/blog'];
foreach ($urls as $u) { echo '  <url><loc>' . Helpers::e($base . $u) . '</loc><changefreq>daily</changefreq><priority>0.7</priority></url>' . "\n"; }
foreach (Database::all("SELECT slug,updated_at FROM categories WHERE is_active=1 AND deleted_at IS NULL") as $r) {
    echo '  <url><loc>' . Helpers::e($base . '/category/' . $r['slug']) . '</loc><lastmod>' . substr((string)$r['updated_at'],0,10) . '</lastmod><priority>0.6</priority></url>' . "\n";
}
foreach (Database::all("SELECT slug,updated_at FROM products WHERE status='active' AND deleted_at IS NULL") as $r) {
    echo '  <url><loc>' . Helpers::e($base . '/product/' . $r['slug']) . '</loc><lastmod>' . substr((string)$r['updated_at'],0,10) . '</lastmod><priority>0.8</priority></url>' . "\n";
}
foreach (Database::all("SELECT slug FROM pages WHERE is_published=1") as $r) { echo '  <url><loc>' . Helpers::e($base . '/' . $r['slug']) . '</loc><priority>0.4</priority></url>' . "\n"; }
foreach (Database::all("SELECT slug FROM blogs WHERE is_published=1") as $r) { echo '  <url><loc>' . Helpers::e($base . '/blog/' . $r['slug']) . '</loc><priority>0.4</priority></url>' . "\n"; }
echo '</urlset>';
