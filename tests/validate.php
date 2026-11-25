<?php
declare(strict_types=1);
/**
 * Static project validation: syntax-level references, route maps, and handler
 * method calls. Runtime behaviour is covered separately by tests/smoke.py.
 * Usage: php tests/validate.php
 *
 * Static-only: uses tests/static_bootstrap.php, so no .env or database is needed.
 */
require_once __DIR__ . '/static_bootstrap.php';

use AllStore\Core\ApiRoutes;
use AllStore\Core\Router;

$root = dirname(__DIR__);
$errors = [];
$checks = 0;

// Route -> handler and route -> page coverage.
$map = ApiRoutes::map();
$handlers = [];
foreach ($map as $pattern => $handler) {
    $checks++;
    if (isset($handlers[$pattern])) { $errors[] = "Duplicate API route: $pattern"; }
    $handlers[$pattern] = $handler;
    if (!is_file($root . '/api/handlers/' . $handler)) { $errors[] = "Missing API handler for $pattern: $handler"; }
}
$files = glob($root . '/api/handlers/*.php') ?: [];
foreach ($files as $file) {
    $name = basename($file);
    if ($name !== '_common.php' && !in_array($name, array_values($map), true)) {
        $errors[] = "Unmapped API handler: $name";
    }
}
foreach (Router::routes() as $path => $file) {
    $checks++;
    if (!is_file($root . '/' . $file)) { $errors[] = "Missing page route for $path: $file"; }
}

// Build an alias => fully-qualified class map from every handler's use statements.
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    $classes = [];
    if (preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*(?:as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m', $src, $uses, PREG_SET_ORDER)) {
        foreach ($uses as $u) {
            $fqcn = ltrim($u[1], '\\');
            $alias = $u[2] ?? substr($fqcn, strrpos($fqcn, '\\') === false ? 0 : strrpos($fqcn, '\\') + 1);
            $classes[$alias] = $fqcn;
        }
    }
    if (preg_match_all('/(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $calls, PREG_SET_ORDER)) {
        foreach ($calls as $c) {
            $raw = ltrim($c[1], '\\');
            $method = $c[2];
            $short = substr($raw, strrpos($raw, '\\') === false ? 0 : strrpos($raw, '\\') + 1);
            if (in_array($raw, ['self', 'static', 'parent'], true)) { continue; }
            $fqcn = str_contains($raw, '\\') ? $raw : ($classes[$raw] ?? null);
            if ($fqcn === null) {
                foreach (['Core', 'Models', 'Services'] as $ns) {
                    $candidate = 'AllStore\\' . $ns . '\\' . $raw;
                    if (class_exists($candidate)) { $fqcn = $candidate; break; }
                }
            }
            if ($fqcn === null || !class_exists($fqcn)) { continue; }
            $checks++;
            if (!method_exists($fqcn, $method)) {
                $errors[] = basename($file) . ": $fqcn::$method() does not exist";
                continue;
            }
            try {
                $ref = new ReflectionMethod($fqcn, $method);
                if (!$ref->isStatic()) { $errors[] = basename($file) . ": $fqcn::$method() is not static"; }
            } catch (Throwable) {}
        }
    }
}

echo 'Static checks: ' . $checks . "\n";
echo 'API routes: ' . count($map) . ', page routes: ' . count(Router::routes()) . ', handler files: ' . count($files) . "\n";
if ($errors) {
    fwrite(STDERR, "Validation failures:\n - " . implode("\n - ", array_values(array_unique($errors))) . "\n");
    exit(1);
}
echo "Validation passed.\n";
