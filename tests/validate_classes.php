<?php
declare(strict_types=1);
/**
 * Detect unresolved short class names used in static calls (e.g. Helpers::e)
 * in PHP files. Catches missing `use AllStore\Core\Helpers;` imports that
 * syntax linting alone cannot see.
 * Usage: php tests/validate_classes.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$root = dirname(__DIR__);
$errors = [];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() === 'php' && !str_contains($path, '/vendor/')) { $files[] = $path; }
}
sort($files);

foreach ($files as $path) {
    $tokens = token_get_all((string)file_get_contents($path));
    $namespace = '';
    $aliases = [];
    $declared = [];
    for ($k = 0, $count = count($tokens); $k < $count; $k++) {
        if (!is_array($tokens[$k]) || $tokens[$k][0] !== T_CLASS) { continue; }
        $z = $k + 1;
        while ($z < $count && is_array($tokens[$z]) && $tokens[$z][0] === T_WHITESPACE) { $z++; }
        if (isset($tokens[$z]) && is_array($tokens[$z]) && $tokens[$z][0] === T_STRING) { $declared[$tokens[$z][1]] = true; }
    }
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) { continue; }
        if ($t[0] === T_NAMESPACE) {
            $name = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $u = $tokens[$j];
                if (is_array($u) && in_array($u[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) { $name .= $u[1]; }
                elseif ($u === ';') { break; }
            }
            $namespace = trim($name, '\\');
            continue;
        }
        if ($t[0] === T_USE) {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
            if (($tokens[$j] ?? null) === '(') { continue; } // closure `use (...)`
            $fqcn = '';
            for (; $j < $n; $j++) {
                $u = $tokens[$j];
                if (is_array($u) && in_array($u[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) { $fqcn .= $u[1]; }
                elseif (is_array($u) && $u[0] === T_WHITESPACE) { continue; }
                else { break; }
            }
            $alias = substr(ltrim($fqcn, '\\'), strrpos(ltrim($fqcn, '\\'), '\\') === false ? 0 : strrpos(ltrim($fqcn, '\\'), '\\') + 1);
            for (; $j < $n; $j++) {
                $u = $tokens[$j];
                if (is_array($u) && $u[0] === T_WHITESPACE) { continue; }
                if (is_array($u) && $u[0] === T_AS) {
                    $j++;
                    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
                    if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $alias = $tokens[$j][1]; }
                }
                break;
            }
            if ($fqcn !== '') { $aliases[$alias] = ltrim($fqcn, '\\'); }
            continue;
        }
        if ($t[0] !== T_STRING) { continue; }
        $class = $t[1];
        if (in_array($class, ['self', 'static', 'parent'], true)) { continue; }
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) { $prev--; }
        if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NS_SEPARATOR) { continue; }
        $next = $i + 1;
        while ($next < $n && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { $next++; }
        if (!isset($tokens[$next]) || !is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_COLON) { continue; }
        if (isset($aliases[$class]) || isset($declared[$class])) { continue; }
        if ($namespace !== '' && class_exists($namespace . '\\' . $class)) { continue; }
        if ($namespace === '' && (class_exists($class) || interface_exists($class))) { continue; }
        $errors[] = str_replace($root . '/', '', $path) . ": unresolved static class $class" . ($namespace !== '' ? " in namespace $namespace" : '');
    }
}

$errors = array_values(array_unique($errors));
echo 'Scanned ' . count($files) . " PHP files.\n";
if ($errors) {
    fwrite(STDERR, "Unresolved classes:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "All static class references resolve.\n";
