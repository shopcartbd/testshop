<?php

declare(strict_types=1);

final class AdminService
{
    public static function summary(): array
    {
        $sql = "SELECT (SELECT count(*) FROM app.products WHERE deleted_at IS NULL) AS products, (SELECT count(*) FROM app.products WHERE status='active' AND deleted_at IS NULL) AS active_products, (SELECT count(*) FROM app.orders) AS orders, (SELECT count(*) FROM app.orders WHERE status IN ('pending','confirmed')) AS open_orders, (SELECT count(*) FROM app.accounts WHERE role='customer') AS customers, (SELECT COALESCE(sum(grand_total),0) FROM app.orders WHERE status NOT IN ('cancelled','refunded') AND placed_at >= now()-interval '30 days') AS revenue_30d, (SELECT count(*) FROM app.return_requests WHERE status='requested') AS returns_pending, (SELECT count(*) FROM app.marketplace_reviews WHERE status='pending') AS reviews_pending";
        return Database::connection()->query($sql)->fetch() ?: [];
    }

    public static function products(string $search = '', int $page = 1): array
    {
        $page = max(1,min(200,$page));
        $search=mb_substr(trim($search),0,100);
        $stmt=Database::connection()->prepare("SELECT p.id,p.name,p.slug,p.sku,p.status,p.base_price,p.currency,p.stock_quantity,p.is_featured,c.name AS category_name,b.name AS brand_name,(SELECT pi.image_url FROM app.product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order LIMIT 1) AS image_url FROM app.products p LEFT JOIN app.categories c ON c.id=p.category_id LEFT JOIN app.brands b ON b.id=p.brand_id WHERE p.deleted_at IS NULL AND (p.name ILIKE :name OR COALESCE(p.sku,'') ILIKE :sku) ORDER BY p.created_at DESC LIMIT 50 OFFSET :offset");
        $term='%'.$search.'%';
        $stmt->bindValue(':name',$term,PDO::PARAM_STR);
        $stmt->bindValue(':sku',$term,PDO::PARAM_STR);
        $stmt->bindValue(':offset',($page-1)*50,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function product(string $id): ?array
    {
        $stmt=Database::connection()->prepare("SELECT p.*,(SELECT pi.image_url FROM app.product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order LIMIT 1) AS image_url FROM app.products p WHERE p.id=:id AND p.deleted_at IS NULL");
        $stmt->execute(['id'=>$id]);
        return $stmt->fetch() ?: null;
    }

    public static function saveProduct(array $input, ?array $imageFile, array $actor, ?string $id = null): string
    {
        $name=trim(preg_replace('/\s+/u',' ',(string)($input['name']??''))??'');
        $slug=strtolower(trim((string)($input['slug']??'')));
        $sku=trim((string)($input['sku']??''));
        $description=trim((string)($input['description']??''));
        $short=trim((string)($input['short_description']??''));
        $status=(string)($input['status']??'draft');
        $price=filter_var($input['base_price']??null,FILTER_VALIDATE_FLOAT);
        $compare=($input['compare_at_price']??'')===''?null:filter_var($input['compare_at_price'],FILTER_VALIDATE_FLOAT);
        $stock=filter_var($input['stock_quantity']??null,FILTER_VALIDATE_INT);
        $tax=filter_var($input['tax_rate']??0,FILTER_VALIDATE_FLOAT);
        $threshold=filter_var($input['low_stock_threshold']??5,FILTER_VALIDATE_INT);
        $weight=($input['weight_grams']??'')===''?null:filter_var($input['weight_grams'],FILTER_VALIDATE_INT);
        $category=trim((string)($input['category_id']??''))?:null;
        $brand=trim((string)($input['brand_id']??''))?:null;
        $imageUrl=trim((string)($input['image_url']??''));
        if (mb_strlen($name)<2||mb_strlen($name)>240||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||strlen($slug)>240) throw new InvalidArgumentException('পণ্যের নাম ও URL slug সঠিকভাবে দিন।');
        if ($sku!==''&&(mb_strlen($sku)>80||preg_match('/[\r\n]/',$sku))) throw new InvalidArgumentException('SKU সঠিক নয়।');
        if ($price===false||$price<0||$price>9999999999.99||($compare!==null&&($compare===false||$compare<$price))||$stock===false||$stock<0||$stock>100000000||$tax===false||$tax<0||$tax>100||$threshold===false||$threshold<0||($weight!==null&&($weight===false||$weight<0))) throw new InvalidArgumentException('দাম, মজুত, কর বা ওজন যাচাই করুন।');
        if (!in_array($status,['draft','active','inactive','archived'],true)||mb_strlen($description)>20000||mb_strlen($short)>1000) throw new InvalidArgumentException('পণ্যের অবস্থা বা বিবরণ গ্রহণযোগ্য নয়।');
        foreach (['category'=>$category,'brand'=>$brand] as $field=>$value) if ($value!==null&&!preg_match('/^[a-f0-9-]{36}$/i',$value)) throw new InvalidArgumentException('পণ্যের শ্রেণি/ব্র্যান্ড সঠিক নয়।');
        if ($imageFile && (($imageFile['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)) $imageUrl=Storage::productImage($imageFile);
        if ($imageUrl!==''&&(!filter_var($imageUrl,FILTER_VALIDATE_URL)||strtolower((string)parse_url($imageUrl,PHP_URL_SCHEME))!=='https'||strlen($imageUrl)>2048)) throw new InvalidArgumentException('ছবির ঠিকানা অবশ্যই HTTPS হতে হবে।');
        $db=Database::connection();
        $db->beginTransaction();
        try {
            if ($id) {
                $old=self::product($id);
                if (!$old) throw new DomainException('পণ্যটি পাওয়া যায়নি।');
                $stmt=$db->prepare('UPDATE app.products SET category_id=:category,brand_id=:brand,name=:name,slug=:slug,short_description=:short,description=:description,status=:status,currency=:currency,base_price=:price,compare_at_price=:compare,sku=:sku,stock_quantity=:stock,is_featured=:featured,tax_rate=:tax,low_stock_threshold=:threshold,weight_grams=:weight,seo_title=:seo_title,seo_description=:seo_description,published_at=CASE WHEN :published_status=\'active\' THEN COALESCE(published_at,now()) ELSE published_at END,updated_at=now() WHERE id=:id');
                $stmt->execute(['category'=>$category,'brand'=>$brand,'name'=>$name,'slug'=>$slug,'short'=>$short?:null,'description'=>$description?:null,'status'=>$status,'currency'=>'BDT','price'=>number_format((float)$price,2,'.',''),'compare'=>$compare===null?null:number_format((float)$compare,2,'.',''),'sku'=>$sku?:null,'stock'=>$stock,'featured'=>isset($input['is_featured'])?'true':'false','tax'=>number_format((float)$tax,2,'.',''),'threshold'=>$threshold,'weight'=>$weight,'seo_title'=>trim((string)($input['seo_title']??''))?:null,'seo_description'=>trim((string)($input['seo_description']??''))?:null,'published_status'=>$status,'id'=>$id]);
                if ((int)$old['stock_quantity'] !== $stock) self::movement($db,$id,$stock-(int)$old['stock_quantity'],$stock,'Admin stock adjustment',$actor['id']);
            } else {
                $stmt=$db->prepare('INSERT INTO app.products(category_id,brand_id,name,slug,short_description,description,status,currency,base_price,compare_at_price,sku,stock_quantity,is_featured,tax_rate,low_stock_threshold,weight_grams,seo_title,seo_description,published_at) VALUES(:category,:brand,:name,:slug,:short,:description,:status,\'BDT\',:price,:compare,:sku,:stock,:featured,:tax,:threshold,:weight,:seo_title,:seo_description,CASE WHEN :published_status=\'active\' THEN now() ELSE NULL END) RETURNING id');
                $stmt->execute(['category'=>$category,'brand'=>$brand,'name'=>$name,'slug'=>$slug,'short'=>$short?:null,'description'=>$description?:null,'status'=>$status,'price'=>number_format((float)$price,2,'.',''),'compare'=>$compare===null?null:number_format((float)$compare,2,'.',''),'sku'=>$sku?:null,'stock'=>$stock,'featured'=>isset($input['is_featured'])?'true':'false','tax'=>number_format((float)$tax,2,'.',''),'threshold'=>$threshold,'weight'=>$weight,'seo_title'=>trim((string)($input['seo_title']??''))?:null,'seo_description'=>trim((string)($input['seo_description']??''))?:null,'published_status'=>$status]);
                $id=(string)$stmt->fetchColumn();
                if ($stock>0) self::movement($db,$id,$stock,$stock,'Initial stock',$actor['id']);
            }
            if ($imageUrl!=='') {
                $db->prepare('DELETE FROM app.product_images WHERE product_id=:id')->execute(['id'=>$id]);
                $img=$db->prepare('INSERT INTO app.product_images(product_id,image_url,alt_text,sort_order,is_primary) VALUES(:id,:url,:alt,0,true)');
                $img->execute(['id'=>$id,'url'=>$imageUrl,'alt'=>$name]);
            }
            self::audit($db,$actor,'product.save','product',$id,['status'=>$status]);
            $db->commit();
            return $id;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error instanceof PDOException && $error->getCode()==='23505') throw new DomainException('Slug বা SKU আগে থেকে আছে। অন্য মান দিন.');
            throw $error;
        }
    }

    public static function categories(): array { return Database::connection()->query('SELECT * FROM app.categories ORDER BY sort_order,name')->fetchAll(); }
    public static function brands(): array { return Database::connection()->query('SELECT * FROM app.brands ORDER BY name')->fetchAll(); }

    public static function saveCategory(array $in, array $actor, ?string $id=null): string
    {
        $name=trim((string)($in['name']??'')); $slug=strtolower(trim((string)($in['slug']??''))); $desc=trim((string)($in['description']??'')); $image=trim((string)($in['image_url']??''));
        if(mb_strlen($name)<2||mb_strlen($name)>160||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||mb_strlen($desc)>4000) throw new InvalidArgumentException('শ্রেণির তথ্য যাচাই করুন।');
        if($image!==''&&(!filter_var($image,FILTER_VALIDATE_URL)||strtolower((string)parse_url($image,PHP_URL_SCHEME))!=='https')) throw new InvalidArgumentException('ছবির HTTPS URL সঠিক নয়।');
        $db=Database::connection();
        if($id){$st=$db->prepare('UPDATE app.categories SET name=:name,slug=:slug,description=:description,image_url=:image,is_active=:active,sort_order=:sort WHERE id=:id');$st->execute(['name'=>$name,'slug'=>$slug,'description'=>$desc?:null,'image'=>$image?:null,'active'=>isset($in['is_active'])?'true':'false','sort'=>(int)($in['sort_order']??0),'id'=>$id]);}
        else{$st=$db->prepare('INSERT INTO app.categories(name,slug,description,image_url,is_active,sort_order) VALUES(:name,:slug,:description,:image,:active,:sort) RETURNING id');$st->execute(['name'=>$name,'slug'=>$slug,'description'=>$desc?:null,'image'=>$image?:null,'active'=>isset($in['is_active'])?'true':'false','sort'=>(int)($in['sort_order']??0)]);$id=(string)$st->fetchColumn();}
        self::audit($db,$actor,'category.save','category',$id,[]); return $id;
    }

    public static function saveBrand(array $in, array $actor, ?string $id=null): string
    {
        $name=trim((string)($in['name']??'')); $slug=strtolower(trim((string)($in['slug']??''))); $desc=trim((string)($in['description']??'')); $logo=trim((string)($in['logo_url']??''));
        if(mb_strlen($name)<2||mb_strlen($name)>160||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||mb_strlen($desc)>4000) throw new InvalidArgumentException('ব্র্যান্ডের তথ্য যাচাই করুন।');
        if($logo!==''&&(!filter_var($logo,FILTER_VALIDATE_URL)||strtolower((string)parse_url($logo,PHP_URL_SCHEME))!=='https')) throw new InvalidArgumentException('লোগোর HTTPS URL সঠিক নয়।');
        $db=Database::connection();
        if($id){$st=$db->prepare('UPDATE app.brands SET name=:name,slug=:slug,description=:description,logo_url=:logo,is_active=:active WHERE id=:id');$st->execute(['name'=>$name,'slug'=>$slug,'description'=>$desc?:null,'logo'=>$logo?:null,'active'=>isset($in['is_active'])?'true':'false','id'=>$id]);}
        else{$st=$db->prepare('INSERT INTO app.brands(name,slug,description,logo_url,is_active) VALUES(:name,:slug,:description,:logo,:active) RETURNING id');$st->execute(['name'=>$name,'slug'=>$slug,'description'=>$desc?:null,'logo'=>$logo?:null,'active'=>isset($in['is_active'])?'true':'false']);$id=(string)$st->fetchColumn();}
        self::audit($db,$actor,'brand.save','brand',$id,[]); return $id;
    }

    public static function adjustStock(string $productId, int $delta, string $reason, array $actor): void
    {
        if(!preg_match('/^[a-f0-9-]{36}$/i',$productId)||$delta===0||abs($delta)>100000000||mb_strlen(trim($reason))<3||mb_strlen($reason)>500) throw new InvalidArgumentException('মজুত পরিবর্তনের তথ্য সঠিক নয়।');
        $db=Database::connection();$db->beginTransaction();
        try{$st=$db->prepare('UPDATE app.products SET stock_quantity=stock_quantity+:delta WHERE id=:id AND deleted_at IS NULL AND stock_quantity+:delta_check>=0 RETURNING stock_quantity');$st->execute(['delta'=>$delta,'id'=>$productId,'delta_check'=>$delta]);$after=$st->fetchColumn();if($after===false)throw new DomainException('পণ্য পাওয়া যায়নি বা মজুত শূন্যের নিচে হবে।');self::movement($db,$productId,$delta,(int)$after,$reason,$actor['id']);self::audit($db,$actor,'stock.adjust','product',$productId,['delta'=>$delta]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    private static function movement(PDO $db,string $productId,int $delta,int $after,string $reason,?string $actor):void
    {
        if($delta===0)return;$type=$delta>0?'stock_in':'stock_out';$st=$db->prepare('INSERT INTO app.stock_movements(product_id,movement_type,quantity_delta,quantity_after,reason,actor_account_id) VALUES(:id,:type,:delta,:after,:reason,:actor)');$st->execute(['id'=>$productId,'type'=>$type,'delta'=>$delta,'after'=>$after,'reason'=>$reason,'actor'=>$actor]);
    }

    public static function customers(string $search=''): array
    {
        $term='%'.mb_substr(trim($search),0,100).'%';$st=Database::connection()->prepare("SELECT id,email,display_name,phone,role,status,last_login_at,created_at FROM app.accounts WHERE role='customer' AND (email ILIKE :email OR display_name ILIKE :name) ORDER BY created_at DESC LIMIT 100");$st->execute(['email'=>$term,'name'=>$term]);return $st->fetchAll();
    }

    public static function setCustomerStatus(string $id,string $status,array $actor):void
    {
        if(!preg_match('/^[a-f0-9-]{36}$/i',$id)||!in_array($status,['active','disabled','locked'],true))throw new InvalidArgumentException('Account state সঠিক নয়।');
        $st=Database::connection()->prepare("UPDATE app.accounts SET status=:status WHERE id=:id AND role='customer'");$st->execute(['status'=>$status,'id'=>$id]);if($st->rowCount()<1)throw new DomainException('গ্রাহক account পাওয়া যায়নি।');
        if($status!=='active')Database::connection()->prepare('DELETE FROM app.sessions WHERE account_id=:id')->execute(['id'=>$id]);
        self::audit(Database::connection(),$actor,'customer.status','account',$id,['status'=>$status]);
    }

    public static function reviews(string $status='pending'):array
    {
        if(!in_array($status,['pending','published','rejected'],true))$status='pending';$st=Database::connection()->prepare('SELECT r.id,r.product_id,r.rating,r.title,r.body,r.status,r.created_at,p.name AS product_name,a.display_name FROM app.marketplace_reviews r JOIN app.products p ON p.id=r.product_id JOIN app.accounts a ON a.id=r.account_id WHERE r.status=:status ORDER BY r.created_at DESC LIMIT 100');$st->execute(['status'=>$status]);return $st->fetchAll();
    }

    public static function moderateReview(string $id,string $status,array $actor):void
    {
        if(!in_array($status,['published','rejected'],true))throw new InvalidArgumentException('রিভিউ অবস্থা সঠিক নয়।');$st=Database::connection()->prepare('UPDATE app.marketplace_reviews SET status=:status,updated_at=now() WHERE id=:id');$st->execute(['status'=>$status,'id'=>$id]);if($st->rowCount()<1)throw new DomainException('রিভিউটি পাওয়া যায়নি।');self::audit(Database::connection(),$actor,'review.moderate','review',$id,['status'=>$status]);
    }

    public static function saveBanner(array $in,array $actor,?string $id=null):string
    {
        $title=trim((string)($in['title']??''));$image=trim((string)($in['image_url']??''));$target=trim((string)($in['target_url']??''));$alt=trim((string)($in['alt_text']??''));
        if(mb_strlen($title)<2||mb_strlen($title)>200||!filter_var($image,FILTER_VALIDATE_URL)||strtolower((string)parse_url($image,PHP_URL_SCHEME))!=='https')throw new InvalidArgumentException('ব্যানারের নাম ও HTTPS ছবি দিন।');
        if($target!==''&&(!str_starts_with($target,'/')&&(!filter_var($target,FILTER_VALIDATE_URL)||strtolower((string)parse_url($target,PHP_URL_SCHEME))!=='https')))throw new InvalidArgumentException('ব্যানার লিংক relative অথবা HTTPS হতে হবে।');
        $params=['title'=>$title,'image'=>$image,'alt'=>$alt?:null,'target'=>$target?:null,'active'=>isset($in['is_active']),'sort'=>(int)($in['sort_order']??0)];
        $params['active']=isset($in['is_active'])?'true':'false';$db=Database::connection();if($id){$st=$db->prepare('UPDATE app.banners SET title=:title,image_url=:image,alt_text=:alt,target_url=:target,is_active=:active,sort_order=:sort WHERE id=:id');$st->execute($params+['id'=>$id]);}else{$st=$db->prepare('INSERT INTO app.banners(title,image_url,alt_text,target_url,is_active,sort_order) VALUES(:title,:image,:alt,:target,:active,:sort) RETURNING id');$st->execute($params);$id=(string)$st->fetchColumn();}self::audit($db,$actor,'banner.save','banner',$id,[]);return $id;
    }

    public static function banners():array{return Database::connection()->query('SELECT * FROM app.banners ORDER BY sort_order,created_at DESC LIMIT 100')->fetchAll();}

    public static function reports(int $days=30):array
    {
        $days=max(1,min(365,$days));$db=Database::connection();$summary=$db->prepare("SELECT count(*) FILTER(WHERE status NOT IN('cancelled','refunded')) AS order_count,COALESCE(sum(grand_total) FILTER(WHERE status NOT IN('cancelled','refunded')),0) AS gross_sales,COALESCE(avg(grand_total) FILTER(WHERE status NOT IN('cancelled','refunded')),0) AS average_order FROM app.orders WHERE placed_at>=now()-(:days*interval '1 day')");$summary->execute(['days'=>$days]);$row=$summary->fetch()?:[];$best=$db->prepare('SELECT oi.product_name,sum(oi.quantity)::bigint AS units,sum(oi.line_total) AS revenue FROM app.order_items oi JOIN app.orders o ON o.id=oi.order_id WHERE o.status NOT IN (\'cancelled\',\'refunded\') AND o.placed_at>=now()-(:days*interval \'1 day\') GROUP BY oi.product_name ORDER BY units DESC LIMIT 10');$best->execute(['days'=>$days]);$row['top_products']=$best->fetchAll();return $row;
    }

    private static function audit(PDO $db,array $actor,string $action,string $entity,string $id,array $details):void
    {
        $st=$db->prepare('INSERT INTO app.audit_logs(actor_profile_id,actor_account_id,action,entity_type,entity_id,details) VALUES(NULL,:actor,:action,:entity,:id,:details)');$st->execute(['actor'=>$actor['id']??null,'action'=>$action,'entity'=>$entity,'id'=>$id,'details'=>json_encode($details,JSON_THROW_ON_ERROR)]);
    }
}
