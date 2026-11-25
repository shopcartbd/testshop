<?php
declare(strict_types=1);
namespace AllStore\Core;

final class ApiRoutes
{
    public static function map(): array
    {
        return [
            'products' => 'products.php',
            'products/{id}' => 'product.php',
            'categories' => 'categories.php',
            'brands' => 'brands.php',
            'search' => 'search.php',
            'search/suggest' => 'suggest.php',
            'cart' => 'cart.php',
            'cart/add' => 'cart-add.php',
            'cart/update' => 'cart-update.php',
            'cart/remove' => 'cart-remove.php',
            'cart/coupon' => 'cart-coupon.php',
            'wishlist' => 'wishlist.php',
            'wishlist/add' => 'wishlist-add.php',
            'wishlist/remove' => 'wishlist-remove.php',
            'orders/create' => 'orders-create.php',
            'orders' => 'orders.php',
            'orders/{id}' => 'order.php',
            'orders/{id}/cancel' => 'order-cancel.php',
            'auth/register' => 'auth-register.php',
            'auth/login' => 'auth-login.php',
            'auth/logout' => 'auth-logout.php',
            'auth/forgot-password' => 'auth-forgot.php',
            'auth/reset-password' => 'auth-reset.php',
            'account/profile' => 'account-profile.php',
            'account/password' => 'account-password.php',
            'reviews/create' => 'review-create.php',
            'newsletter' => 'newsletter.php',
            'contact' => 'contact.php',
            'tickets/create' => 'ticket-create.php',
            'notifications/read' => 'notifications-read.php',
            'admin/dashboard' => 'admin-dashboard.php',
            'admin/products' => 'admin-products.php',
            'admin/categories' => 'admin-categories.php',
            'admin/brands' => 'admin-brands.php',
            'admin/orders' => 'admin-orders.php',
            'admin/orders/update-status' => 'admin-order-status.php',
            'admin/coupons' => 'admin-coupons.php',
            'admin/banners' => 'admin-banners.php',
            'admin/sections' => 'admin-sections.php',
            'admin/settings' => 'admin-settings.php',
            'admin/inventory' => 'admin-inventory.php',
            'admin/health' => 'health.php',
            'health' => 'health.php',
            'webhooks/stripe' => 'webhook-stripe.php',
        ];
    }
}
