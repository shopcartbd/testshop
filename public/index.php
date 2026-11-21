<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' && $path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'ok', 'app' => (string) (getenv('APP_NAME') ?: 'AllStore')]);
    exit;
}
if (PHP_SAPI === 'cli-server' && preg_match('/\.(?:css|js|png|jpe?g|svg|webp|ico|woff2|txt)$/i', $path)) {
    $file = realpath(__DIR__ . $path);
    $root = realpath(__DIR__);
    if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) return false;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Api.php';
if (Api::isApi($path)) Api::dispatch($path, $method);

$data = [
    'categories' => [], 'brands' => [], 'products' => [], 'product' => null, 'cart' => [],
    'subtotal' => 0.0, 'search' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
    'category' => preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['category'] ?? ''))),
    'brand' => preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['brand'] ?? ''))),
    'sort' => (string) ($_GET['sort'] ?? 'featured'), 'page' => max(1, min(200, (int) ($_GET['page'] ?? 1))),
    'db_error' => false, 'account' => null, 'admin_page' => '', 'flash' => '',
];
$view = 'not-found';
$adminPath = '/admin';

function is_local_store_path(string $candidate): bool
{
    if (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//') || str_contains($candidate, '\\') || preg_match('/[\x00-\x20\x7f]/', $candidate)) return false;
    $parts = parse_url($candidate);
    return is_array($parts) && empty($parts['scheme']) && empty($parts['host']) && empty($parts['user']);
}

function store_return_path(string $fallback): string
{
    $candidate = trim((string) ($_POST['return_to'] ?? ''));
    if ($candidate !== '' && is_local_store_path($candidate)) return $candidate;
    return $fallback;
}

function account_page(array &$data, string $title): void
{
    $data['account'] = Auth::requireLogin();
    $data['page_subtitle'] = $title;
}

try {
    $data['account'] = Auth::current();
    $data['categories'] = Catalog::categories();
    $data['brands'] = Catalog::brands();
} catch (Throwable $error) {
    error_log('Storefront bootstrap query failed: ' . get_class($error));
    $data['db_error'] = true;
}

if ($method === 'POST') {
    $fallback = str_starts_with($path, '/admin/') ? store_return_path('/admin') : store_return_path($path);
    try {
        if ($path === '/cart/add') {
            verify_csrf();
            $id = trim((string) ($_POST['product_id'] ?? ''));
            $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
            if (!preg_match('/^[a-f0-9-]{36}$/i', $id) || $quantity === false) throw new InvalidArgumentException('পণ্যের তথ্য সঠিক নয়।');
            $product = Catalog::productById($id);
            if (!$product || (int) $product['stock_quantity'] < 1) throw new DomainException('এই পণ্যটি এখন মজুতে নেই।.');
            if (($product['product_type'] ?? 'simple') !== 'simple') throw new DomainException('এই product-এর variant/bundle checkout এখনো enabled নয়।');
            $_SESSION['cart'][$id] = min((int) ($_SESSION['cart'][$id] ?? 0) + (int) $quantity, min(20, (int) $product['stock_quantity']));
            $_SESSION['flash'] = 'পণ্যটি কার্টে যোগ হয়েছে।';
            redirect('/cart');
        }
        if ($path === '/cart/update') {
            verify_csrf();
            $quantities = $_POST['quantity'] ?? [];
            if (is_array($quantities)) {
                foreach (($_SESSION['cart'] ?? []) as $id => $current) {
                    $quantity = filter_var($quantities[$id] ?? 0, FILTER_VALIDATE_INT);
                    if (!$quantity || $quantity < 1) unset($_SESSION['cart'][$id]);
                    else $_SESSION['cart'][$id] = min(20, $quantity);
                }
            }
            $_SESSION['flash'] = 'কার্ট আপডেট হয়েছে।';
            redirect('/cart');
        }
        if ($path === '/cart/remove') {
            verify_csrf();
            $id = trim((string) ($_POST['product_id'] ?? ''));
            if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) throw new InvalidArgumentException('পণ্যের তথ্য সঠিক নয়।');
            unset($_SESSION['cart'][$id]);
            $_SESSION['flash'] = 'পণ্যটি কার্ট থেকে সরানো হয়েছে।';
            redirect('/cart');
        }
        if ($path === '/login') {
            verify_csrf();
            Auth::login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            $next = trim((string) ($_POST['next'] ?? ''));
            redirect(is_local_store_path($next) ? $next : '/account');
        }
        if ($path === '/register') {
            verify_csrf();
            Auth::register((string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            redirect('/account');
        }
        if ($path === '/logout') {
            verify_csrf();
            Auth::logout();
            redirect('/');
        }
        if ($path === '/forgot-password') {
            verify_csrf();
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            if (Mailer::configured() && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $token = Auth::createResetToken($email);
                if ($token) {
                    $reset = app_url('/reset-password?token=' . rawurlencode($token));
                    Mailer::send($email, app_name() . ' পাসওয়ার্ড রিসেট', "পাসওয়ার্ড রিসেট করতে ৩০ মিনিটের মধ্যে এই লিংক খুলুন:\n" . $reset . "\n\nআপনি অনুরোধ না করলে এই বার্তা উপেক্ষা করুন।");
                }
                $_SESSION['flash'] = 'যদি এই ইমেইলে account থাকে, reset নির্দেশনা পাঠানো হয়েছে।';
            } else {
                $_SESSION['flash'] = 'পাসওয়ার্ড রিসেট চালাতে আগে Vercel-এ SMTP mail settings যোগ করতে হবে।';
            }
            redirect('/forgot-password');
        }
        if ($path === '/reset-password') {
            verify_csrf();
            Auth::resetPassword((string) ($_POST['token'] ?? ''), (string) ($_POST['password'] ?? ''));
            Auth::logout();
            $_SESSION['flash'] = 'পাসওয়ার্ড বদলানো হয়েছে। নতুন পাসওয়ার্ড দিয়ে লগইন করুন।';
            redirect('/login');
        }
        if ($path === '/account/password') {
            verify_csrf();
            account_page($data, 'পাসওয়ার্ড বদলান');
            Auth::changePassword((string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
            $_SESSION['flash'] = 'পাসওয়ার্ড আপডেট হয়েছে।';
            redirect('/account');
        }
        if ($path === '/checkout') {
            verify_csrf();
            if (empty($_SESSION['checkout_key'])) $_SESSION['checkout_key'] = bin2hex(random_bytes(32));
            $order = Orders::place($_SESSION['cart'] ?? [], Auth::current(), $_POST, (string) $_SESSION['checkout_key']);
            $_SESSION['cart'] = [];
            $_SESSION['recent_order'] = ['id' => $order['id'], 'order_number' => $order['order_number'], 'status' => $order['status'], 'grand_total' => $order['grand_total'], 'currency' => $order['currency']];
            $_SESSION['checkout_key'] = bin2hex(random_bytes(32));
            if (empty($order['idempotent_replay']) && Mailer::configured()) Mailer::send((string) $order['customer_email'], 'Order ' . $order['order_number'] . ' received', 'Your cash-on-delivery order is recorded. Order number: ' . $order['order_number'] . '. Total: ' . format_price((float) $order['grand_total'], (string) $order['currency']) . '.');
            redirect('/order-confirmation');
        }
        if ($path === '/account/addresses') {
            verify_csrf(); $account = Auth::requireLogin();
            if (isset($_POST['delete_id'])) AccountServices::deleteAddress($account, (string) $_POST['delete_id']);
            else AccountServices::saveAddress($account, $_POST, isset($_POST['id']) ? (string) $_POST['id'] : null);
            $_SESSION['flash'] = 'ঠিকানা সংরক্ষণ হয়েছে.'; redirect('/account/addresses');
        }
        if ($path === '/account/wishlist') {
            verify_csrf(); $account = Auth::requireLogin(); AccountServices::toggleWishlist($account, (string) ($_POST['product_id'] ?? '')); $_SESSION['flash'] = 'পছন্দের তালিকা আপডেট হয়েছে।'; redirect(store_return_path('/account/wishlist'));
        }
        if ($path === '/account/returns') {
            verify_csrf(); $account = Auth::requireLogin(); AccountServices::createReturn($account, (string) ($_POST['order_id'] ?? ''), isset($_POST['order_item_id']) ? (string) $_POST['order_item_id'] : null, (string) ($_POST['reason'] ?? ''), (string) ($_POST['type'] ?? 'return')); $_SESSION['flash'] = 'রিটার্ন অনুরোধ জমা হয়েছে।'; redirect('/account/orders');
        }
        if ($path === '/account/reviews') {
            verify_csrf(); $account = Auth::requireLogin(); AccountServices::submitReview($account, (string) ($_POST['product_id'] ?? ''), (int) ($_POST['rating'] ?? 0), (string) ($_POST['title'] ?? ''), (string) ($_POST['body'] ?? '')); $_SESSION['flash'] = 'রিভিউ জমা হয়েছে; প্রকাশের আগে moderation হবে।'; redirect(store_return_path('/account/orders'));
        }
        if ($path === '/contact') {
            verify_csrf(); AccountServices::createTicket(Auth::current(), (string) ($_POST['email'] ?? ''), (string) ($_POST['subject'] ?? ''), (string) ($_POST['message'] ?? ''), (string) ($_POST['category'] ?? 'general')); $_SESSION['flash'] = 'বার্তা জমা হয়েছে। আমরা যত দ্রুত সম্ভব উত্তর দেব।'; redirect('/contact');
        }
        if (str_starts_with($path, '/admin/')) {
            $admin = Auth::requireAdmin(); verify_csrf();
            if ($path === '/admin/products/save') { $id = AdminService::saveProduct($_POST, $_FILES['image'] ?? null, $admin, trim((string) ($_POST['id'] ?? '')) ?: null); $_SESSION['flash'] = 'পণ্য সংরক্ষণ হয়েছে।'; redirect('/admin/products/' . rawurlencode($id) . '/edit'); }
            if ($path === '/admin/categories/save') { AdminService::saveCategory($_POST, $admin, trim((string) ($_POST['id'] ?? '')) ?: null); $_SESSION['flash'] = 'শ্রেণি সংরক্ষণ হয়েছে।'; redirect('/admin/categories'); }
            if ($path === '/admin/brands/save') { AdminService::saveBrand($_POST, $admin, trim((string) ($_POST['id'] ?? '')) ?: null); $_SESSION['flash'] = 'ব্র্যান্ড সংরক্ষণ হয়েছে।'; redirect('/admin/brands'); }
            if ($path === '/admin/stock') { AdminService::adjustStock((string) ($_POST['product_id'] ?? ''), (int) ($_POST['delta'] ?? 0), (string) ($_POST['reason'] ?? ''), $admin); $_SESSION['flash'] = 'মজুত আপডেট হয়েছে।'; redirect('/admin/products'); }
            if (preg_match('#^/admin/orders/([a-f0-9-]{36})/status$#i', $path, $m)) { Orders::changeStatus($m[1], (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''), $admin); $_SESSION['flash'] = 'অর্ডার স্ট্যাটাস আপডেট হয়েছে।'; redirect('/admin/orders'); }
            if (preg_match('#^/admin/orders/([a-f0-9-]{36})/shipment$#i', $path, $m)) { Orders::updateShipment($m[1], (string) ($_POST['carrier'] ?? ''), (string) ($_POST['tracking_number'] ?? ''), (string) ($_POST['tracking_url'] ?? ''), $admin); $_SESSION['flash'] = 'কুরিয়ার তথ্য সংরক্ষণ হয়েছে।'; redirect('/admin/orders'); }
            if (preg_match('#^/admin/customers/([a-f0-9-]{36})/status$#i', $path, $m)) { AdminService::setCustomerStatus($m[1], (string) ($_POST['status'] ?? ''), $admin); $_SESSION['flash'] = 'গ্রাহক account আপডেট হয়েছে।'; redirect('/admin/customers'); }
            if (preg_match('#^/admin/reviews/([a-f0-9-]{36})/moderate$#i', $path, $m)) { AdminService::moderateReview($m[1], (string) ($_POST['status'] ?? ''), $admin); $_SESSION['flash'] = 'রিভিউ moderation হয়েছে।'; redirect('/admin/reviews'); }
            if ($path === '/admin/banners/save') { AdminService::saveBanner($_POST, $admin, trim((string) ($_POST['id'] ?? '')) ?: null); $_SESSION['flash'] = 'ব্যানার সংরক্ষণ হয়েছে।'; redirect('/admin/banners'); }
            throw new RuntimeException('Route not found');
        }
        http_response_code(404); $view = 'not-found';
    } catch (InvalidArgumentException|DomainException $error) {
        $_SESSION['flash'] = $error->getMessage();
        redirect($fallback === '/checkout' ? '/checkout' : $fallback);
    } catch (Throwable $error) {
        error_log('Storefront mutation failed: ' . get_class($error));
        $_SESSION['flash'] = 'অনুরোধটি সম্পন্ন করা যায়নি। তথ্য যাচাই করে আবার চেষ্টা করুন।';
        redirect($fallback === '/checkout' ? '/checkout' : $fallback);
    }
}

try {
    if ($path === '/' || $path === '/shop') {
        $view = $path === '/' ? 'home' : 'shop';
        $data['products'] = Catalog::products($data['search'], $data['category'], $data['brand'], $data['sort'], $data['page']);
        $data['product_total'] = Catalog::productCount($data['search'], $data['category'], $data['brand']);
        $data['banners'] = Catalog::activeBanners();
    } elseif (preg_match('#^/product/([a-z0-9-]+)$#', $path, $match)) {
        $view = 'product'; $data['product'] = Catalog::product($match[1]);
        if (!$data['product']) { http_response_code(404); $view = 'not-found'; }
        else $data['product']['reviews'] = Catalog::reviews((string) $data['product']['id']);
    } elseif (in_array($path, ['/cart','/checkout'], true)) {
        $view = $path === '/cart' ? 'cart' : 'checkout';
        $data['cart'] = Catalog::cartProducts($_SESSION['cart'] ?? []);
        $data['subtotal'] = array_sum(array_column($data['cart'], 'line_total'));
        if ($path === '/checkout') { $data['shipping_methods'] = Orders::shippingMethods(); if (empty($_SESSION['checkout_key'])) $_SESSION['checkout_key'] = bin2hex(random_bytes(32)); }
    } elseif ($path === '/order-confirmation') {
        $view = 'order-confirmation'; $data['recent_order'] = $_SESSION['recent_order'] ?? null;
        if (!$data['recent_order']) { http_response_code(404); $view = 'not-found'; }
    } elseif (in_array($path, ['/login','/register','/forgot-password','/reset-password'], true)) {
        if ($data['account']) redirect('/account');
        $view = match($path){'/login'=>'login','/register'=>'register','/forgot-password'=>'forgot-password',default=>'reset-password'};
        $data['next'] = trim((string) ($_GET['next'] ?? ''));
        $data['reset_token'] = trim((string) ($_GET['token'] ?? ''));
    } elseif ($path === '/account') {
        account_page($data,'আপনার account'); $data['account_orders'] = Orders::forCustomer($data['account']); $data['addresses'] = AccountServices::addresses((string) $data['account']['id']); $view='account';
    } elseif ($path === '/account/orders') {
        account_page($data,'আপনার অর্ডার'); $data['orders'] = Orders::forCustomer($data['account']); $view='account-orders';
    } elseif (preg_match('#^/account/orders/([a-f0-9-]{36})$#i',$path,$m)) {
        account_page($data,'অর্ডারের তথ্য'); $data['order'] = Orders::customerOrder($m[1],$data['account']); if(!$data['order']){http_response_code(404);$view='not-found';}else $view='account-order';
    } elseif ($path === '/account/addresses') {
        account_page($data,'ঠিকানা বই'); $data['addresses'] = AccountServices::addresses((string)$data['account']['id']); $view='account-addresses';
    } elseif ($path === '/account/wishlist') {
        account_page($data,'পছন্দের তালিকা'); $data['wishlist'] = AccountServices::wishlist($data['account']); $view='account-wishlist';
    } elseif ($path === '/account/password') {
        account_page($data,'পাসওয়ার্ড বদলান'); $view='account-password';
    } elseif ($path === '/about') $view='about';
    elseif ($path === '/contact') $view='contact';
    elseif ($path === '/faq') $view='faq';
    elseif ($path === '/privacy') $view='privacy';
    elseif ($path === '/returns') $view='returns';
    elseif ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8'); echo "User-agent: *\nAllow: /\nDisallow: /account\nDisallow: /admin\nDisallow: /api/\nSitemap: ".rtrim((string)getenv('APP_URL'),'/')."/sitemap.xml\n"; exit;
    } elseif ($path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8'); $base=rtrim((string)(getenv('APP_URL')?:''),'/');
        if($base===''){http_response_code(503);echo '<?xml version="1.0" encoding="UTF-8"?><error>APP_URL is not configured</error>';exit;}
        $urls=[$base.'/',$base.'/shop',$base.'/about',$base.'/contact'];
        foreach(Catalog::products('', '', '', 'newest',1,48) as $item)$urls[]=$base.'/product/'.rawurlencode((string)$item['slug']);
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as $url)echo '<url><loc>'.e($url).'</loc></url>';echo '</urlset>';exit;
    } elseif (str_starts_with($path,'/admin')) {
        $data['account']=Auth::requireAdmin(); $view='admin';
        if($path==='/admin'||$path==='/admin/'){$data['admin_page']='dashboard';$data['summary']=AdminService::summary();$data['recent_orders']=Orders::adminRecent(10);}
        elseif($path==='/admin/products'){$data['admin_page']='products';$data['admin_products']=AdminService::products((string)($_GET['q']??''),(int)($_GET['page']??1));$data['admin_search']=(string)($_GET['q']??'');}
        elseif($path==='/admin/products/new'){$data['admin_page']='product-form';$data['product_form']=null;$data['categories']=AdminService::categories();$data['brands']=AdminService::brands();}
        elseif(preg_match('#^/admin/products/([a-f0-9-]{36})/edit$#i',$path,$m)){$data['admin_page']='product-form';$data['product_form']=AdminService::product($m[1]);if(!$data['product_form']){http_response_code(404);$view='not-found';}$data['categories']=AdminService::categories();$data['brands']=AdminService::brands();}
        elseif($path==='/admin/categories'){$data['admin_page']='categories';$data['admin_categories']=AdminService::categories();}
        elseif($path==='/admin/brands'){$data['admin_page']='brands';$data['admin_brands']=AdminService::brands();}
        elseif($path==='/admin/orders'){$data['admin_page']='orders';$data['admin_orders']=Orders::adminRecent(100);}
        elseif($path==='/admin/customers'){$data['admin_page']='customers';$data['admin_customers']=AdminService::customers((string)($_GET['q']??''));}
        elseif($path==='/admin/reviews'){$data['admin_page']='reviews';$data['admin_reviews']=AdminService::reviews((string)($_GET['status']??'pending'));$data['review_status']=(string)($_GET['status']??'pending');}
        elseif($path==='/admin/banners'){$data['admin_page']='banners';$data['admin_banners']=AdminService::banners();}
        elseif($path==='/admin/reports'){$data['admin_page']='reports';$data['report_days']=max(1,min(365,(int)($_GET['days']??30)));$data['report']=AdminService::reports($data['report_days']);}
        else{http_response_code(404);$view='not-found';}
    } else { http_response_code(404); $view='not-found'; }
} catch (DomainException $error) {
    http_response_code(http_response_code()===403?403:404); $data['safe_error']=$error->getMessage(); $view='error';
} catch (Throwable $error) {
    error_log('Page request failed: '.get_class($error)); http_response_code(500); $data['db_error']=true; $view='error';
}

$data['flash'] = (string) ($_SESSION['flash'] ?? ''); unset($_SESSION['flash']);
require dirname(__DIR__) . '/views/layout.php';
