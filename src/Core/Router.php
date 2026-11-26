<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Router
{
    /** Clean URL => real PHP page file. Every major page has its own file. */
    private const ROUTES = [
        '/' => 'pages/home.php',
        '/shop' => 'pages/shop.php',
        '/category/{slug}' => 'pages/category.php',
        '/subcategory/{slug}' => 'pages/subcategory.php',
        '/product/{slug}' => 'pages/product.php',
        '/search' => 'pages/search.php',
        '/brands' => 'pages/brands.php',
        '/brand/{slug}' => 'pages/brand.php',
        '/flash-sale' => 'pages/flash-sale.php',
        '/deals' => 'pages/deals.php',
        '/best-sellers' => 'pages/best-sellers.php',
        '/new-arrivals' => 'pages/new-arrivals.php',
        '/trending' => 'pages/trending.php',
        '/cart' => 'pages/cart.php',
        '/checkout' => 'pages/checkout.php',
        '/order-confirmation/{number}' => 'pages/order-confirmation.php',
        '/contact' => 'pages/contact.php',
        '/about' => 'pages/about.php',
        '/faq' => 'pages/faq.php',
        '/blog' => 'pages/blog.php',
        '/blog/{slug}' => 'pages/blog-post.php',
        '/privacy-policy' => 'pages/privacy.php',
        '/terms' => 'pages/terms.php',
        '/return-refund-policy' => 'pages/returns.php',
        '/shipping-policy' => 'pages/shipping.php',
        '/sitemap.xml' => 'pages/sitemap.php',
        '/robots.txt' => 'pages/robots.php',
        '/403' => 'pages/403.php',
        '/404' => 'pages/404.php',
        '/500' => 'pages/500.php',
        '/login' => 'pages/account/login.php',
        '/register' => 'pages/account/register.php',
        '/forgot-password' => 'pages/account/forgot-password.php',
        '/reset-password' => 'pages/account/reset-password.php',
        '/logout' => 'pages/account/logout.php',
        '/account' => 'pages/account/overview.php',
        '/account/profile' => 'pages/account/profile.php',
        '/account/addresses' => 'pages/account/addresses.php',
        '/account/orders' => 'pages/account/orders.php',
        '/account/orders/{number}' => 'pages/account/order-details.php',
        '/account/tracking/{number}' => 'pages/account/order-tracking.php',
        '/account/wishlist' => 'pages/account/wishlist.php',
        '/account/reviews' => 'pages/account/reviews.php',
        '/account/notifications' => 'pages/account/notifications.php',
        '/account/coupons' => 'pages/account/coupons.php',
        '/account/returns' => 'pages/account/returns.php',
        '/account/refunds' => 'pages/account/refunds.php',
        '/account/tickets' => 'pages/account/tickets.php',
        '/account/tickets/{id}' => 'pages/account/ticket-details.php',
        '/account/change-password' => 'pages/account/change-password.php',
        '/admin' => 'admin/index.php',
        '/admin/login' => 'admin/login.php',
        '/admin/logout' => 'admin/logout.php',
        '/admin/products' => 'admin/products.php',
        '/admin/products/new' => 'admin/product-form.php',
        '/admin/products/{id}' => 'admin/product-form.php',
        '/admin/products/{id}/variants' => 'admin/variants.php',
        '/admin/categories' => 'admin/categories.php',
        '/admin/brands' => 'admin/brands.php',
        '/admin/attributes' => 'admin/attributes.php',
        '/admin/inventory' => 'admin/inventory.php',
        '/admin/warehouses' => 'admin/warehouses.php',
        '/admin/orders' => 'admin/orders.php',
        '/admin/orders/{number}' => 'admin/order-details.php',
        '/admin/customers' => 'admin/customers.php',
        '/admin/customers/{id}' => 'admin/customer-details.php',
        '/admin/coupons' => 'admin/coupons.php',
        '/admin/promotions' => 'admin/promotions.php',
        '/admin/flash-sales' => 'admin/flash-sales.php',
        '/admin/banners' => 'admin/banners.php',
        '/admin/homepage' => 'admin/homepage.php',
        '/admin/reviews' => 'admin/reviews.php',
        '/admin/payments' => 'admin/payments.php',
        '/admin/transactions' => 'admin/transactions.php',
        '/admin/shipping' => 'admin/shipping.php',
        '/admin/locations' => 'admin/locations.php',
        '/admin/delivery-charges' => 'admin/delivery-charges.php',
        '/admin/setup-guide' => 'admin/setup-guide.php',
        '/admin/couriers' => 'admin/couriers.php',
        '/admin/returns' => 'admin/returns.php',
        '/admin/refunds' => 'admin/refunds.php',
        '/admin/tickets' => 'admin/tickets.php',
        '/admin/notifications' => 'admin/notifications.php',
        '/admin/reports' => 'admin/reports.php',
        '/admin/admin-users' => 'admin/admin-users.php',
        '/admin/roles' => 'admin/roles.php',
        '/admin/permissions' => 'admin/permissions.php',
        '/admin/activity-logs' => 'admin/activity-logs.php',
        '/admin/pages' => 'admin/pages.php',
        '/admin/blog' => 'admin/blog.php',
        '/admin/settings' => 'admin/settings.php',
        '/admin/security' => 'admin/security.php',
        '/admin/health' => 'admin/health.php',
    ];

    public static function dispatch(): void
    {
        $path = Request::path();
        if ($path !== '/') { $path = '/' . trim($path, '/'); }
        if (str_starts_with($path, '/api/') || $path === '/api') {
            require dirname(__DIR__, 2) . '/api/index.php';
            return;
        }
        foreach (self::ROUTES as $pattern => $file) {
            $raw = '';
            foreach (explode('/', trim($pattern, '/')) as $seg) {
                if (preg_match('/^\{([a-z_]+)\}$/', $seg, $m)) { $raw .= '/(?P<' . $m[1] . '>[a-zA-Z0-9_\-]+)'; }
                else { $raw .= '/' . preg_quote($seg, '#'); }
            }
            $regex = $pattern === '/' ? '#^/$#' : '#^' . $raw . '/?$#';
            if (preg_match($regex, $path, $m)) {
                foreach ($m as $k => $v) { if (is_string($k)) { $_GET[$k] = $v; } }
                self::serve($file);
                return;
            }
        }
        http_response_code(404);
        self::serve('pages/404.php');
    }

    private static function serve(string $relative): void
    {
        $file = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($file)) {
            Logger::log('error', 'route.missing_file', ['file'=>$relative]);
            http_response_code(500);
            $file = dirname(__DIR__, 2) . '/pages/500.php';
        }
        if (str_starts_with($relative, 'admin/') || str_starts_with($relative, 'pages/account/') || in_array($relative, ['pages/checkout.php','pages/cart.php'], true)) {
            Response::noCache();
        }
        require $file;
    }

    public static function routes(): array { return self::ROUTES; }
}
