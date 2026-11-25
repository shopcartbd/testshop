<?php
declare(strict_types=1);
/**
 * Vercel community-runtime single entrypoint AND local API dispatcher.
 * Each endpoint lives in its own file under api/handlers/.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Logger;

$path = Request::path();
$original = (string)($_SERVER['HTTP_X_VERCEL_ORIGINAL_PATH'] ?? $path);
if (str_starts_with($original, '/api/')) { $path = $original; }
$route = trim(preg_replace('#^/api/#', '', $path) ?: '', '/');
$route = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', (string)$route) ?: '';
if ($route === '' || $route === 'index.php') {
    Response::ok('AllStore API', [
        'version' => '1.0',
        'endpoints' => array_keys(AllStore\Core\ApiRoutes::map()),
        'documentation' => 'docs/API_DOCUMENTATION.md',
    ]);
}
$map = AllStore\Core\ApiRoutes::map();
$file = $map[$route] ?? null;
if ($file === null) {
    foreach ($map as $pattern => $target) {
        if (!str_contains($pattern, '{')) { continue; }
        $re = '#^' . preg_replace('/\{[a-z_]+\}/', '([a-zA-Z0-9_\-]+)', $pattern) . '$#';
        if (preg_match($re, $route, $m)) {
            preg_match_all('/\{([a-z_]+)\}/', $pattern, $names);
            foreach ($names[1] as $i => $n) { $_GET[$n] = $m[$i + 1] ?? ''; }
            $file = $target;
            break;
        }
    }
}
if ($file === null) { Response::fail('Endpoint not found.', [], 404); }
$handler = dirname(__DIR__) . '/api/handlers/' . $file;
if (!is_file($handler)) { Logger::log('error','api.missing_handler',['route'=>$route]); Response::fail('Endpoint unavailable.', [], 500); }
require $handler;
