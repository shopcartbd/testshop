<?php
declare(strict_types=1);
/**
 * PHP built-in server router (local development and fallback hosts).
 * Only public assets and public uploads are served as static files; all other
 * requests go through the front controller. Source, configuration, migrations,
 * documentation, test files, local databases and secrets are never exposed.
 */
$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$root = __DIR__;
$full = realpath($root . $path);
$publicUpload = str_starts_with($path, '/storage/uploads/')
    && !str_contains($path, '..')
    && !str_ends_with($path, '.php');
$publicStatic = preg_match('#^/assets/(?:css|js|img)/#', $path) === 1 || $publicUpload;
if ($path !== '/' && $publicStatic && $full !== false
    && str_starts_with($full, $root . DIRECTORY_SEPARATOR)
    && is_file($full) && !str_ends_with($full, '.php')
    && !str_contains($full, '/.git')
    && (!str_contains($full, '/storage/') || $publicUpload)) {
    return false;
}
if (preg_match('#/(?:src|database|scripts|tests|docs|includes|storage|api/handlers|\.git)/#', $path)
    || preg_match('#^/(?:\.env|\.git|\.dockerignore|\.gitignore|composer|package|Dockerfile|Caddyfile|vercel|README)#i', $path)
    || preg_match('#\.(?:md|sql|log|ini|sqlite|zip|sh|yml|yaml|toml|json)$#i', $path)) {
    http_response_code(404);
    exit('Not found');
}
require $root . '/includes/bootstrap.php';
AllStore\Core\Router::dispatch();
