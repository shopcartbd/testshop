<?php

declare(strict_types=1);

final class AccountServices
{
    public static function addresses(string $accountId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM app.account_addresses WHERE account_id=:account ORDER BY is_default DESC,created_at DESC');
        $stmt->execute(['account' => $accountId]);
        return $stmt->fetchAll();
    }

    public static function saveAddress(array $account, array $input, ?string $id = null): void
    {
        $fields = [
            'recipient_name' => trim((string) ($input['recipient_name'] ?? '')),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'line1' => trim((string) ($input['line1'] ?? '')),
            'line2' => trim((string) ($input['line2'] ?? '')),
            'locality' => trim((string) ($input['locality'] ?? '')),
            'region' => trim((string) ($input['region'] ?? '')),
            'postal_code' => trim((string) ($input['postal_code'] ?? '')),
            'country_code' => strtoupper(trim((string) ($input['country_code'] ?? 'BD'))),
            'is_default' => isset($input['is_default']),
        ];
        foreach (['recipient_name' => 160,'phone' => 32,'line1' => 240,'line2' => 240,'locality' => 120,'region' => 120,'postal_code' => 32] as $key => $max) {
            if (mb_strlen($fields[$key]) > $max || (in_array($key, ['recipient_name','phone','line1','locality'], true) && mb_strlen($fields[$key]) < 2)) throw new InvalidArgumentException('ঠিকানার তথ্য যাচাই করে আবার দিন..');
        }
        if (!preg_match('/^[A-Z]{2}$/', $fields['country_code'])) throw new InvalidArgumentException('দেশের কোড সঠিক নয়।');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            if ($fields['is_default']) {
                $clear = $db->prepare('UPDATE app.account_addresses SET is_default=false WHERE account_id=:account');
                $clear->execute(['account' => $account['id']]);
            }
            if ($id) {
                $sql = 'UPDATE app.account_addresses SET recipient_name=:name,phone=:phone,line1=:line1,line2=:line2,locality=:locality,region=:region,postal_code=:postal,country_code=:country,is_default=:default,updated_at=now() WHERE id=:id AND account_id=:account';
                $stmt = $db->prepare($sql);
                $stmt->execute(['name'=>$fields['recipient_name'],'phone'=>$fields['phone'],'line1'=>$fields['line1'],'line2'=>$fields['line2']?:null,'locality'=>$fields['locality'],'region'=>$fields['region']?:null,'postal'=>$fields['postal_code']?:null,'country'=>$fields['country_code'],'default'=>$fields['is_default']?'true':'false','id'=>$id,'account'=>$account['id']]);
                if ($stmt->rowCount() < 1) throw new DomainException('ঠিকানাটি পাওয়া যায়নি।');
            } else {
                $stmt = $db->prepare('INSERT INTO app.account_addresses(account_id,recipient_name,phone,line1,line2,locality,region,postal_code,country_code,is_default) VALUES(:account,:name,:phone,:line1,:line2,:locality,:region,:postal,:country,:default)');
                $stmt->execute(['account'=>$account['id'],'name'=>$fields['recipient_name'],'phone'=>$fields['phone'],'line1'=>$fields['line1'],'line2'=>$fields['line2']?:null,'locality'=>$fields['locality'],'region'=>$fields['region']?:null,'postal'=>$fields['postal_code']?:null,'country'=>$fields['country_code'],'default'=>$fields['is_default']?'true':'false']);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public static function deleteAddress(array $account, string $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM app.account_addresses WHERE id=:id AND account_id=:account');
        $stmt->execute(['id'=>$id,'account'=>$account['id']]);
        if ($stmt->rowCount() < 1) throw new DomainException('ঠিকানাটি পাওয়া যায়নি।');
    }

    public static function wishlist(array $account): array
    {
        $db = Database::connection();
        $stmt = $db->prepare("INSERT INTO app.wishlists(account_id,name) VALUES(:account,'Favorites') ON CONFLICT(account_id,name) DO NOTHING");
        $stmt->execute(['account'=>$account['id']]);
        $stmt = $db->prepare('SELECT p.id,p.slug,p.name,p.base_price AS price, p.currency, p.stock_quantity, (SELECT pi.image_url FROM app.product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order LIMIT 1) AS image_url FROM app.wishlist_items wi JOIN app.wishlists w ON w.id=wi.wishlist_id JOIN app.products p ON p.id=wi.product_id WHERE w.account_id=:account AND p.status=\'active\' AND p.deleted_at IS NULL ORDER BY wi.created_at DESC');
        $stmt->execute(['account'=>$account['id']]);
        return $stmt->fetchAll();
    }

    public static function toggleWishlist(array $account, string $productId): void
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $productId)) throw new InvalidArgumentException('পণ্যটি সঠিক নয়।');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO app.wishlists(account_id,name) VALUES(:account,'Favorites') ON CONFLICT(account_id,name) DO NOTHING")->execute(['account'=>$account['id']]);
            $q = $db->prepare("SELECT w.id FROM app.wishlists w WHERE w.account_id=:account AND w.name='Favorites'");
            $q->execute(['account'=>$account['id']]);
            $wishlistId = $q->fetchColumn();
            $delete = $db->prepare('DELETE FROM app.wishlist_items WHERE wishlist_id=:wishlist AND product_id=:product');
            $delete->execute(['wishlist'=>$wishlistId,'product'=>$productId]);
            if ($delete->rowCount() === 0) {
                $exists = $db->prepare("SELECT id FROM app.products WHERE id=:id AND status='active' AND deleted_at IS NULL");
                $exists->execute(['id'=>$productId]);
                if (!$exists->fetchColumn()) throw new DomainException('পণ্যটি পাওয়া যায়নি।');
                $db->prepare('INSERT INTO app.wishlist_items(wishlist_id,product_id) VALUES(:wishlist,:product)')->execute(['wishlist'=>$wishlistId,'product'=>$productId]);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public static function submitReview(array $account, string $productId, int $rating, string $title, string $body): void
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $productId) || $rating < 1 || $rating > 5 || mb_strlen($title) > 160 || mb_strlen($body) > 10000) throw new InvalidArgumentException('রিভিউয়ের তথ্য যাচাই করে দিন।');
        $purchase = Database::connection()->prepare("SELECT oi.id FROM app.order_items oi JOIN app.orders o ON o.id=oi.order_id WHERE oi.product_id=:product AND o.customer_account_id=:account AND o.status='delivered' LIMIT 1");
        $purchase->execute(['product'=>$productId,'account'=>$account['id']]);
        $orderItem = $purchase->fetchColumn();
        if (!$orderItem) throw new DomainException('রিভিউ দিতে হলে পণ্যটি আপনার কাছে ডেলিভারি হওয়া অর্ডারে থাকতে হবে।');
        $stmt = Database::connection()->prepare("INSERT INTO app.marketplace_reviews(product_id,account_id,order_item_id,rating,title,body,status) VALUES(:product,:account,:item,:rating,:title,:body,'pending') ON CONFLICT(product_id,account_id) DO UPDATE SET order_item_id=EXCLUDED.order_item_id,rating=EXCLUDED.rating,title=EXCLUDED.title,body=EXCLUDED.body,status='pending',updated_at=now()");
        $stmt->execute(['product'=>$productId,'account'=>$account['id'],'item'=>$orderItem,'rating'=>$rating,'title'=>trim($title)?:null,'body'=>trim($body)?:null]);
    }

    public static function createReturn(array $account, string $orderId, ?string $itemId, string $reason, string $type = 'return'): void
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $orderId) || mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000 || !in_array($type,['return','exchange','refund'],true)) throw new InvalidArgumentException('রিটার্ন অনুরোধের তথ্য সঠিক নয়।');
        $stmt = Database::connection()->prepare("SELECT id,placed_at,status FROM app.orders WHERE id=:order_id AND customer_account_id=:account LIMIT 1");
        $stmt->execute(['order_id'=>$orderId,'account'=>$account['id']]);
        $order = $stmt->fetch();
        if (!$order || !in_array($order['status'],['delivered','confirmed','processing','shipped'],true)) throw new DomainException('এই অর্ডারের জন্য অনুরোধ করা যাচ্ছে না।');
        if ($itemId !== null) {
            $item = Database::connection()->prepare('SELECT id FROM app.order_items WHERE id=:item AND order_id=:order');
            $item->execute(['item'=>$itemId,'order'=>$orderId]);
            if (!$item->fetchColumn()) throw new DomainException('অর্ডার আইটেমটি পাওয়া যায়নি।');
        }
        $stmt = Database::connection()->prepare('INSERT INTO app.return_requests(order_id,order_item_id,customer_account_id,reason,request_type) VALUES(:order_id,:item,:account,:reason,:type)');
        $stmt->execute(['order_id'=>$orderId,'item'=>$itemId,'account'=>$account['id'],'reason'=>trim($reason),'type'=>$type]);
    }

    public static function createTicket(?array $account, string $email, string $subject, string $message, string $category = 'general'): void
    {
        $email = strtolower(trim($account['email'] ?? $email));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen(trim($subject)) < 3 || mb_strlen($subject) > 200 || mb_strlen(trim($message)) < 5 || mb_strlen($message) > 10000) throw new InvalidArgumentException('যোগাযোগের তথ্য যাচাই করে দিন।');
        if (!RateLimiter::allow('support-ticket',$email,5,3600)) throw new DomainException('অনেকবার চেষ্টা হয়েছে। পরে আবার লিখুন।');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $number = 'TK-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt=$db->prepare("INSERT INTO app.support_tickets(ticket_number,account_id,guest_email,subject,category) VALUES(:number,:account,:email,:subject,:category) RETURNING id");
            $stmt->execute(['number'=>$number,'account'=>$account['id']??null,'email'=>$account?null:$email,'subject'=>trim($subject),'category'=>preg_match('/^[a-z0-9_-]{1,40}$/',$category)?$category:'general']);
            $id=$stmt->fetchColumn();
            $stmt=$db->prepare('INSERT INTO app.ticket_messages(ticket_id,sender_account_id,message) VALUES(:ticket,:sender,:message)');
            $stmt->execute(['ticket'=>$id,'sender'=>$account['id']??null,'message'=>trim($message)]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
