<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;

final class NotificationService
{
    public static function create(?string $userId, string $type, string $title, string $body, bool $adminOnly = false, array $data = []): void
    {
        try {
            Database::insert('notifications', ['id'=>Helpers::uuid(),'user_id'=>$userId,'admin_only'=>$adminOnly ? 1 : 0,
                'type'=>$type,'title'=>$title,'body'=>$body,'data'=>Helpers::json($data),'created_at'=>Helpers::now()]);
        } catch (\Throwable) {}
    }
    public static function welcome(string $userId, string $name): void
    {
        self::create($userId, 'welcome', 'Welcome to ' . (string)\AllStore\Core\Config::get('app.name'),
            'Hi ' . $name . ', your account is ready. Explore the latest arrivals.');
    }
    public static function orderPlaced(array $order): void
    {
        self::create($order['user_id'] ?? null, 'order_placed', 'Order ' . $order['order_number'] . ' placed',
            'We received your order. You can track its progress from your account.');
        self::create(null, 'new_order', 'New order ' . $order['order_number'], 'A new order needs review.', true);
    }
    public static function orderStatus(array $order, string $status): void
    {
        $labels = ['confirmed'=>'confirmed','processing'=>'is being processed','packed'=>'has been packed',
            'shipped'=>'has shipped','delivered'=>'was delivered','cancelled'=>'was cancelled',
            'return_requested'=>'has a return request','returned'=>'was returned','refunded'=>'was refunded'];
        self::create($order['user_id'] ?? null, 'order_' . $status, 'Order ' . $order['order_number'],
            'Your order ' . ($labels[$status] ?? 'was updated') . '.', false, ['status'=>$status]);
    }
    public static function lowStock(string $productName, string $productId): void
    {
        self::create(null, 'low_stock', 'Low stock: ' . $productName, 'Replenish inventory soon.', true, ['product_id'=>$productId]);
    }
    public static function unreadCount(?string $userId): int
    {
        if (!$userId) { return 0; }
        return (int)Database::value('SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0', ['u'=>$userId]);
    }
    public static function adminUnread(): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM notifications WHERE admin_only=1 AND is_read=0');
    }
}
