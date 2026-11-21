<?php

declare(strict_types=1);

final class Catalog
{
    public static function productById(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) return null;
        $statement = Database::connection()->prepare("SELECT id,name,base_price AS price,currency,stock_quantity,product_type FROM app.products WHERE id=:id AND status='active' AND deleted_at IS NULL LIMIT 1");
        $statement->execute(['id'=>$id]);
        $product=$statement->fetch();
        return $product ?: null;
    }

    public static function categories(): array
    {
        return Database::connection()->query("SELECT id,name,slug,description,image_url,banner_url,icon_name,parent_id,sort_order FROM app.categories WHERE is_active ORDER BY sort_order,name LIMIT 100")->fetchAll();
    }

    public static function brands(): array
    {
        return Database::connection()->query("SELECT id,name,slug,description,logo_url FROM app.brands WHERE is_active ORDER BY name LIMIT 100")->fetchAll();
    }

    public static function products(string $search='',string $category='',string $brand='',string $sort='featured',int $page=1,int $perPage=24): array
    {
        $where=["p.status='active'","p.deleted_at IS NULL","(p.category_id IS NULL OR c.is_active=TRUE)","(p.brand_id IS NULL OR b.is_active=TRUE)"];
        $params=[];
        $search=mb_substr(trim($search),0,100);
        if($search!==''){
            $where[]='(p.name ILIKE :search_name OR COALESCE(p.sku,\'\') ILIKE :search_sku OR COALESCE(p.short_description,\'\') ILIKE :search_description)';
            $term='%'.$search.'%';$params['search_name']=$term;$params['search_sku']=$term;$params['search_description']=$term;
        }
        if($category!==''){$where[]='c.slug=:category_slug';$params['category_slug']=$category;}
        if($brand!==''){$where[]='b.slug=:brand_slug';$params['brand_slug']=$brand;}
        $order=match($sort){'price_asc'=>'p.base_price ASC,p.created_at DESC','price_desc'=>'p.base_price DESC,p.created_at DESC','popular'=>'p.is_best_seller DESC,p.is_featured DESC,p.created_at DESC','newest'=>'p.created_at DESC',default=>'p.is_featured DESC,p.created_at DESC'};
        $page=max(1,min(200,$page));$perPage=max(1,min(48,$perPage));
        $sql='SELECT p.id,p.name,p.slug,p.short_description,p.base_price AS price,p.compare_at_price,p.currency,p.stock_quantity,p.is_featured,p.is_best_seller,p.is_new_arrival,p.seo_title,p.seo_description,'
            ."COALESCE((SELECT pi.image_url FROM app.product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC,pi.sort_order LIMIT 1),'') AS image_url,"
            .'c.name AS category_name,c.slug AS category_slug,b.name AS brand_name,b.slug AS brand_slug,p.product_type '
            .'FROM app.products p LEFT JOIN app.categories c ON c.id=p.category_id LEFT JOIN app.brands b ON b.id=p.brand_id WHERE '.implode(' AND ',$where)
            .' ORDER BY '.$order.' LIMIT :limit OFFSET :offset';
        $stmt=Database::connection()->prepare($sql);
        foreach($params as $key=>$value)$stmt->bindValue(':'.$key,$value,PDO::PARAM_STR);
        $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT);$stmt->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);$stmt->execute();
        $rows=$stmt->fetchAll();
        if($search!==''){
            $log=Database::connection()->prepare('INSERT INTO app.search_events(query_hash,query_length,result_count) VALUES(:hash,:length,:count)');
            $log->execute(['hash'=>hash('sha256',mb_strtolower($search)),'length'=>mb_strlen($search),'count'=>count($rows)]);
        }
        return $rows;
    }

    public static function productCount(string $search='',string $category='',string $brand=''):int
    {
        $where=["p.status='active'","p.deleted_at IS NULL","(p.category_id IS NULL OR c.is_active=TRUE)","(p.brand_id IS NULL OR b.is_active=TRUE)"];$params=[];
        if($search!==''){$where[]='(p.name ILIKE :name OR COALESCE(p.sku,\'\') ILIKE :sku OR COALESCE(p.short_description,\'\') ILIKE :description)';$term='%'.mb_substr(trim($search),0,100).'%';$params=['name'=>$term,'sku'=>$term,'description'=>$term];}
        if($category!==''){$where[]='c.slug=:category';$params['category']=$category;}
        if($brand!==''){$where[]='b.slug=:brand';$params['brand']=$brand;}
        $sql='SELECT count(*) FROM app.products p LEFT JOIN app.categories c ON c.id=p.category_id LEFT JOIN app.brands b ON b.id=p.brand_id WHERE '.implode(' AND ',$where);
        $stmt=Database::connection()->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();
    }

    public static function product(string $slug): ?array
    {
        $statement=Database::connection()->prepare("SELECT p.id,p.category_id,p.brand_id,p.name,p.slug,p.short_description,p.description,p.currency,p.base_price AS price,p.compare_at_price,p.sku,p.stock_quantity,p.is_featured,p.product_type,p.low_stock_threshold,p.return_eligible,p.seo_title,p.seo_description,c.name AS category_name,c.slug AS category_slug,b.name AS brand_name FROM app.products p LEFT JOIN app.categories c ON c.id=p.category_id LEFT JOIN app.brands b ON b.id=p.brand_id WHERE p.slug=:slug AND p.status='active' AND p.deleted_at IS NULL AND (p.category_id IS NULL OR c.is_active=TRUE) AND (p.brand_id IS NULL OR b.is_active=TRUE) LIMIT 1");
        $statement->execute(['slug'=>$slug]);$product=$statement->fetch();if(!$product)return null;
        $images=Database::connection()->prepare('SELECT image_url,alt_text FROM app.product_images WHERE product_id=:id ORDER BY is_primary DESC,sort_order');$images->execute(['id'=>$product['id']]);$product['images']=$images->fetchAll();
        $variants=Database::connection()->prepare('SELECT id,sku,name,attributes,price,stock_quantity,image_url FROM app.product_variants WHERE product_id=:id AND is_active AND deleted_at IS NULL ORDER BY name');$variants->execute(['id'=>$product['id']]);$product['variants']=$variants->fetchAll();
        $attrs=Database::connection()->prepare('SELECT a.name,a.slug,a.unit,pav.value FROM app.product_attribute_values pav JOIN app.attributes a ON a.id=pav.attribute_id WHERE pav.product_id=:id AND pav.variant_id IS NULL AND a.is_active ORDER BY a.name');$attrs->execute(['id'=>$product['id']]);$product['attributes']=$attrs->fetchAll();
        $review=Database::connection()->prepare("SELECT count(*)::int AS count,COALESCE(avg(rating),0) AS average FROM app.marketplace_reviews WHERE product_id=:id AND status='published'");$review->execute(['id'=>$product['id']]);$product['review_summary']=$review->fetch();
        return $product;
    }

    public static function reviews(string $productId):array
    {
        $stmt=Database::connection()->prepare("SELECT r.rating,r.title,r.body,r.created_at,a.display_name FROM app.marketplace_reviews r JOIN app.accounts a ON a.id=r.account_id WHERE r.product_id=:id AND r.status='published' ORDER BY r.created_at DESC LIMIT 20");$stmt->execute(['id'=>$productId]);return $stmt->fetchAll();
    }

    public static function activeBanners(string $placement='home'):array
    {
        $stmt=Database::connection()->prepare('SELECT title,image_url,alt_text,target_url,placement FROM app.banners WHERE is_active AND placement=:placement AND (starts_at IS NULL OR starts_at<=now()) AND (ends_at IS NULL OR ends_at>now()) ORDER BY sort_order,created_at DESC LIMIT 10');$stmt->execute(['placement'=>$placement]);return $stmt->fetchAll();
    }

    public static function cartProducts(array $cart):array
    {
        $ids=array_values(array_filter(array_map('strval',array_keys($cart)),static fn(string $id):bool=>(bool)preg_match('/^[a-f0-9-]{36}$/i',$id)));
        if($ids===[])return [];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $stmt=Database::connection()->prepare("SELECT p.id,p.name,p.slug,p.base_price AS price,p.currency,p.stock_quantity,p.product_type,COALESCE((SELECT pi.image_url FROM app.product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC,pi.sort_order LIMIT 1),'') AS image_url FROM app.products p WHERE p.id IN ($marks) AND p.status='active' AND p.deleted_at IS NULL");
        $stmt->execute($ids);$rows=$stmt->fetchAll();
        foreach($rows as &$row){$row['quantity']=min(max(1,(int)($cart[(string)$row['id']]??1)),max(1,(int)$row['stock_quantity']));$row['line_total']=(float)$row['price']*$row['quantity'];}
        unset($row);return $rows;
    }
}
