<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Security;
use AllStore\Core\Request;
use AllStore\Core\AppException;
use AllStore\Core\Logger;

final class ReviewService
{
    public static function submit(string $userId, array $in): string
    {
        if (!Security::rateLimit('review:' . $userId, 8, 3600)) { throw new AppException('Too many reviews. Please try again later.', 429); }
        $productId = (string)($in['product_id'] ?? '');
        $rating = (int)($in['rating'] ?? 0);
        $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120);
        $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 2000);
        if ($rating < 1 || $rating > 5 || mb_strlen($body) < 10) { throw new AppException('Choose a rating and write at least 10 characters.', 422); }
        if (!Database::first("SELECT id FROM products WHERE id=:p AND status='active' AND deleted_at IS NULL", ['p'=>$productId])) {
            throw new AppException('Product not found.', 404);
        }
        if (Database::first('SELECT id FROM reviews WHERE product_id=:p AND user_id=:u', ['p'=>$productId,'u'=>$userId])) {
            throw new AppException('You have already reviewed this product.', 409);
        }
        // Verified purchase only: a delivered order containing this product.
        $order = Database::first("SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id=o.id
            WHERE o.user_id=:u AND o.status='delivered' AND oi.product_id=:p LIMIT 1", ['u'=>$userId,'p'=>$productId]);
        $verified = $order ? 1 : 0;
        $id = Helpers::uuid();
        Database::insert('reviews', ['id'=>$id,'product_id'=>$productId,'user_id'=>$userId,'order_id'=>$order['id'] ?? null,
            'rating'=>$rating,'title'=>$title,'body'=>$body,'image_urls'=>'[]','is_verified'=>$verified,
            'status'=>'pending','created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
        Logger::audit('review.submitted','reviews',$id,['product_id'=>$productId,'verified'=>$verified]);
        return $id;
    }
    public static function moderate(string $id, string $status): void
    {
        if (!in_array($status, ['approved','rejected','pending'], true)) { throw new AppException('Invalid review status.', 422); }
        $r = Database::first('SELECT * FROM reviews WHERE id=:i', ['i'=>$id]);
        if (!$r) { throw new AppException('Review not found.', 404); }
        Database::update('reviews', ['status'=>$status,'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$id]);
        self::recalculate((string)$r['product_id']);
        Logger::audit('review.moderated','reviews',$id,['status'=>$status]);
    }
    public static function reply(string $id, string $text): void
    {
        Database::update('reviews', ['admin_reply'=>mb_substr(trim($text),0,1000),'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$id]);
        Logger::audit('review.replied','reviews',$id);
    }
    public static function recalculate(string $productId): void
    {
        $r = Database::first("SELECT COALESCE(AVG(rating),0) AS avg, COUNT(*) AS n FROM reviews WHERE product_id=:p AND status='approved'", ['p'=>$productId]);
        Database::update('products', ['rating_avg'=>round((float)($r['avg'] ?? 0),2),'rating_count'=>(int)($r['n'] ?? 0),'updated_at'=>Helpers::now()], 'id=:i', ['i'=>$productId]);
    }
    public static function allForAdmin(?string $status = null): array
    {
        $w = $status ? 'WHERE r.status=:s' : '';
        $p = $status ? ['s'=>$status] : [];
        return Database::all("SELECT r.*, p.name AS product_name, pr.full_name AS customer
            FROM reviews r JOIN products p ON p.id=r.product_id JOIN profiles pr ON pr.id=r.user_id
            $w ORDER BY r.created_at DESC LIMIT 200", $p);
    }
}
