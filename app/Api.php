<?php

declare(strict_types=1);

final class Api
{
    public static function isApi(string $path):bool{return str_starts_with($path,'/api/v1/');}

    public static function dispatch(string $path,string $method):never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        try {
            $body=self::body();
            if($path==='/api/v1/csrf'&&$method==='GET')self::respond(['csrf_token'=>csrf_token()]);
            if($path==='/api/v1/categories'&&$method==='GET')self::respond(['data'=>Catalog::categories()]);
            if($path==='/api/v1/brands'&&$method==='GET')self::respond(['data'=>Catalog::brands()]);
            if($path==='/api/v1/products'&&$method==='GET'){
                $q=mb_substr(trim((string)($_GET['q']??'')),0,100);$category=preg_replace('/[^a-z0-9-]/','',strtolower((string)($_GET['category']??'')));$brand=preg_replace('/[^a-z0-9-]/','',strtolower((string)($_GET['brand']??'')));$sort=(string)($_GET['sort']??'featured');$page=max(1,min(200,(int)($_GET['page']??1)));$limit=max(1,min(48,(int)($_GET['limit']??24)));
                self::respond(['data'=>Catalog::products($q,$category,$brand,$sort,$page,$limit),'meta'=>['page'=>$page,'per_page'=>$limit,'total'=>Catalog::productCount($q,$category,$brand)]]);
            }
            if(preg_match('#^/api/v1/products/([a-z0-9-]+)$#',$path,$m)&&$method==='GET'){
                $product=Catalog::product($m[1]);if(!$product)self::fail(404,'পণ্যটি পাওয়া যায়নি.।');$product['reviews']=Catalog::reviews((string)$product['id']);self::respond(['data'=>$product]);
            }
            if($path==='/api/v1/cart'&&$method==='GET'){
                $products=Catalog::cartProducts($_SESSION['cart']??[]);self::respond(['data'=>$products,'meta'=>['item_count'=>cart_count(),'subtotal'=>array_sum(array_column($products,'line_total')),'checkout_key'=>self::checkoutKey()]]);
            }
            if($path==='/api/v1/auth/register'&&$method==='POST'){
                self::csrf($body);$user=Auth::register((string)($body['name']??''),(string)($body['email']??''),(string)($body['password']??''));self::respond(['data'=>['id'=>$user['id'],'email'=>$user['email'],'name'=>$user['display_name'],'role'=>$user['role']]],201);
            }
            if($path==='/api/v1/auth/login'&&$method==='POST'){
                self::csrf($body);$user=Auth::login((string)($body['email']??''),(string)($body['password']??''));self::respond(['data'=>['id'=>$user['id'],'email'=>$user['email'],'name'=>$user['display_name'],'role'=>$user['role']]]);
            }
            if($path==='/api/v1/auth/logout'&&$method==='POST'){
                self::csrf($body);Auth::logout();self::respond(['data'=>['logged_out'=>true]]);
            }
            if($path==='/api/v1/me'&&$method==='GET'){
                $account=self::account();self::respond(['data'=>['id'=>$account['id'],'email'=>$account['email'],'name'=>$account['display_name'],'phone'=>$account['phone'],'role'=>$account['role']]]);
            }
            if($path==='/api/v1/me/orders'&&$method==='GET'){
                $account=self::account();self::respond(['data'=>Orders::forCustomer($account)]);
            }
            if(preg_match('#^/api/v1/me/orders/([a-f0-9-]{36})$#i',$path,$m)&&$method==='GET'){
                $order=Orders::customerOrder($m[1],self::account());if(!$order)self::fail(404,'অর্ডারটি পাওয়া যায়নি।');self::respond(['data'=>$order]);
            }
            if($path==='/api/v1/me/addresses'&&$method==='GET')self::respond(['data'=>AccountServices::addresses(self::account()['id'])]);
            if($path==='/api/v1/me/addresses'&&$method==='POST'){
                self::csrf($body);$account=self::account();AccountServices::saveAddress($account,$body);self::respond(['data'=>AccountServices::addresses($account['id'])],201);
            }
            if(preg_match('#^/api/v1/me/addresses/([a-f0-9-]{36})$#i',$path,$m)&&$method==='DELETE'){
                self::csrf($body);AccountServices::deleteAddress(self::account(),$m[1]);self::respond(['data'=>['deleted'=>true]]);
            }
            if($path==='/api/v1/me/wishlist'&&$method==='GET')self::respond(['data'=>AccountServices::wishlist(self::account())]);
            if($path==='/api/v1/me/wishlist'&&$method==='POST'){
                self::csrf($body);AccountServices::toggleWishlist(self::account(),(string)($body['product_id']??''));self::respond(['data'=>AccountServices::wishlist(self::account())]);
            }
            if($path==='/api/v1/me/reviews'&&$method==='POST'){
                self::csrf($body);$account=self::account();AccountServices::submitReview($account,(string)($body['product_id']??''),(int)($body['rating']??0),(string)($body['title']??''),(string)($body['body']??''));self::respond(['data'=>['status'=>'pending_moderation']],201);
            }
            if($path==='/api/v1/me/returns'&&$method==='POST'){
                self::csrf($body);$account=self::account();AccountServices::createReturn($account,(string)($body['order_id']??''),isset($body['order_item_id'])?(string)$body['order_item_id']:null,(string)($body['reason']??''),(string)($body['type']??'return'));self::respond(['data'=>['status'=>'requested']],201);
            }
            if($path==='/api/v1/orders'&&$method==='POST'){
                self::csrf($body);$account=Auth::current();$clientKey=(string)($body['idempotency_key']??$_SERVER['HTTP_IDEMPOTENCY_KEY']??'');if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/',$clientKey))self::fail(422,'প্রতি অর্ডারের জন্য ১৬–১২৮ অক্ষরের Idempotency key দিতে হবে।');$sessionKey=self::checkoutKey();$key=hash('sha256',$sessionKey.':'.$clientKey);$order=Orders::place($_SESSION['cart']??[],$account,$body,$key);$_SESSION['cart']=[];$_SESSION['last_order_number']=$order['order_number'];if(empty($order['idempotent_replay'])&&Mailer::configured())Mailer::send((string)$order['customer_email'],'Order '.$order['order_number'].' received','Thank you. Your order is recorded as cash on delivery. Order number: '.$order['order_number'].'. Amount: '.format_price((float)$order['grand_total'],(string)$order['currency']).'.');self::respond(['data'=>['id'=>$order['id']??null,'order_number'=>$order['order_number'],'status'=>$order['status'],'payment_method'=>'cod','total'=>$order['grand_total'],'currency'=>$order['currency']]],empty($order['idempotent_replay'])?201:200);
            }
            if($path==='/api/v1/contact'&&$method==='POST'){
                self::csrf($body);$account=Auth::current();AccountServices::createTicket($account,(string)($body['email']??''),(string)($body['subject']??''),(string)($body['message']??''),(string)($body['category']??'general'));self::respond(['data'=>['status'=>'open']],201);
            }
            if($path==='/api/v1/newsletter'&&$method==='POST'){
                self::csrf($body);$email=strtolower(trim((string)($body['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!isset($body['consent'])||$body['consent']!==true)self::fail(422,'ইমেইল দিন এবং সাবস্ক্রাইবের সম্মতি নিশ্চিত করুন।');if(!RateLimiter::allow('newsletter',$email,3,3600))self::fail(429,'পরে আবার চেষ্টা করুন।');$st=Database::connection()->prepare("INSERT INTO app.newsletter_subscribers(email,status,consented_at) VALUES(:email,'pending',now()) ON CONFLICT(email) DO UPDATE SET status=CASE WHEN app.newsletter_subscribers.status='unsubscribed' THEN 'pending' ELSE app.newsletter_subscribers.status END,consented_at=now()");$st->execute(['email'=>$email]);self::respond(['data'=>['status'=>'pending_confirmation']]);
            }

            if(str_starts_with($path,'/api/v1/admin/')){
                $admin=self::admin();
                if($path==='/api/v1/admin/summary'&&$method==='GET')self::respond(['data'=>AdminService::summary()]);
                if($path==='/api/v1/admin/orders'&&$method==='GET')self::respond(['data'=>Orders::adminRecent()]);
                if($path==='/api/v1/admin/customers'&&$method==='GET')self::respond(['data'=>AdminService::customers((string)($_GET['q']??''))]);
                if($path==='/api/v1/admin/reports'&&$method==='GET')self::respond(['data'=>AdminService::reports((int)($_GET['days']??30))]);
                if(preg_match('#^/api/v1/admin/orders/([a-f0-9-]{36})/status$#i',$path,$m)&&$method==='POST'){
                    self::csrf($body);Orders::changeStatus($m[1],(string)($body['status']??''),(string)($body['note']??''),$admin);self::respond(['data'=>['updated'=>true]]);
                }
                if($path==='/api/v1/admin/products'&&$method==='GET')self::respond(['data'=>AdminService::products((string)($_GET['q']??''),(int)($_GET['page']??1))]);
                if($path==='/api/v1/admin/products'&&$method==='POST'){
                    self::csrf($body);$id=AdminService::saveProduct($body,$_FILES['image']??null,$admin);self::respond(['data'=>['id'=>$id]],201);
                }
                if($path==='/api/v1/admin/categories'&&$method==='POST'){
                    self::csrf($body);$id=AdminService::saveCategory($body,$admin);self::respond(['data'=>['id'=>$id]],201);
                }
                if($path==='/api/v1/admin/brands'&&$method==='POST'){
                    self::csrf($body);$id=AdminService::saveBrand($body,$admin);self::respond(['data'=>['id'=>$id]],201);
                }
                self::fail(404,'API route পাওয়া যায়নি।');
            }
            self::fail(404,'API route পাওয়া যায়নি।');
        } catch (InvalidArgumentException $error) {
            self::fail(422,$error->getMessage());
        } catch (DomainException $error) {
            self::fail(http_response_code()===403?403:400,$error->getMessage());
        } catch (Throwable $error) {
            error_log('API request failed: '.get_class($error));
            self::fail(500,'সার্ভারে সমস্যা হয়েছে। পরে আবার চেষ্টা করুন।');
        }
    }

    private static function body():array
    {
        $type=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
        if(str_contains($type,'application/json')){$raw=file_get_contents('php://input');if(strlen((string)$raw)>65536)self::fail(413,'Request খুব বড়।');try{$value=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){self::fail(400,'JSON body সঠিক নয়।');}return is_array($value)?$value:[];}
        return $_POST;
    }
    private static function csrf(array $body):void
    {
        $token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$body['_csrf']??'');
        if($token===''||!hash_equals(csrf_token(),$token))self::fail(419,'Session expired. আবার refresh করে চেষ্টা করুন।');
    }
    private static function account():array
    {
        $account=Auth::current();if(!$account)self::fail(401,'লগইন প্রয়োজন।');return $account;
    }
    private static function admin():array
    {
        $account=self::account();if(!in_array($account['role'],['admin','owner'],true))self::fail(403,'এই কাজের অনুমতি নেই।');return $account;
    }
    private static function checkoutKey():string
    {
        if(empty($_SESSION['checkout_key'])||!preg_match('/^[a-f0-9]{64}$/',(string)$_SESSION['checkout_key']))$_SESSION['checkout_key']=bin2hex(random_bytes(32));return (string)$_SESSION['checkout_key'];
    }
    private static function respond(array $data,int $status=200):never
    {
        http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
    }
    private static function fail(int $status,string $message):never
    {
        http_response_code($status);echo json_encode(['error'=>['status'=>$status,'message'=>$message]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;
    }
}
