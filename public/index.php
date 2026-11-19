<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && preg_match('/\.(?:css|js|png|jpe?g|svg|webp|ico|woff2)$/i', $path)) {
    $file = realpath(__DIR__ . $path);
    $root = realpath(__DIR__);
    if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'app' => app_name()]);
    exit;
}

if ($method === 'POST') {
    verify_csrf();
    if ($path === '/cart/add') {
        $id = trim((string) ($_POST['product_id'] ?? ''));
        $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]) ?: 1;
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            http_response_code(422);
            exit('Invalid product.');
        }
        try {
            $product = Catalog::productById($id);
            if (!$product || (int) $product['stock_quantity'] < 1) {
                $_SESSION['flash'] = 'এই পণ্যটি এখন মজুতে নেই।';
            } else {
                $_SESSION['cart'][$id] = min((int) ($_SESSION['cart'][$id] ?? 0) + $quantity, min(20, (int) $product['stock_quantity']));
                $_SESSION['flash'] = 'পণ্যটি কার্টে যোগ হয়েছে।';
            }
        } catch (Throwable $error) {
            error_log('Catalog lookup failed while adding cart item.');
            $_SESSION['flash'] = 'কার্ট আপডেট করা যায়নি। পরে আবার চেষ্টা করুন।';
        }
        redirect('/cart');
    }
    if ($path === '/cart/update') {
        $quantities = $_POST['quantity'] ?? [];
        if (is_array($quantities)) {
            foreach (($_SESSION['cart'] ?? []) as $id => $current) {
                $quantity = filter_var($quantities[$id] ?? 0, FILTER_VALIDATE_INT);
                if (!$quantity || $quantity < 1) {
                    unset($_SESSION['cart'][$id]);
                } else {
                    $_SESSION['cart'][$id] = min(20, $quantity);
                }
            }
        }
        $_SESSION['flash'] = 'কার্ট আপডেট হয়েছে।';
        redirect('/cart');
    }
    http_response_code(404);
    exit('Not found');
}

$search = trim((string) ($_GET['q'] ?? ''));
$category = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['category'] ?? '')));
$data = ['categories' => [], 'products' => [], 'product' => null, 'cart' => [], 'subtotal' => 0.0, 'search' => $search, 'category' => $category, 'db_error' => false];
try {
    if ($path === '/' || $path === '/shop') {
        $data['categories'] = Catalog::categories();
        $data['products'] = Catalog::products($search, $category);
    } elseif (preg_match('#^/product/([a-z0-9-]+)$#', $path, $match)) {
        $data['product'] = Catalog::product($match[1]);
        if (!$data['product']) {
            http_response_code(404);
        }
    } elseif ($path === '/cart' || $path === '/checkout') {
        $data['cart'] = Catalog::cartProducts($_SESSION['cart'] ?? []);
        $data['subtotal'] = array_sum(array_column($data['cart'], 'line_total'));
    } elseif (!in_array($path, ['/about', '/contact', '/faq', '/privacy', '/returns'], true)) {
        http_response_code(404);
    }
} catch (Throwable $error) {
    error_log('Storefront data request failed.');
    $data['db_error'] = true;
}

$view = match (true) {
    $path === '/' => 'home',
    $path === '/shop' => 'shop',
    preg_match('#^/product/[a-z0-9-]+$#', $path) === 1 => 'product',
    $path === '/cart' => 'cart',
    $path === '/checkout' => 'checkout',
    $path === '/about' => 'about',
    $path === '/contact' => 'contact',
    $path === '/faq' => 'faq',
    $path === '/privacy' => 'privacy',
    $path === '/returns' => 'returns',
    default => 'not-found',
};

require dirname(__DIR__) . '/views/layout.php';
