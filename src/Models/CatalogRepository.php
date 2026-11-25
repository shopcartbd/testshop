<?php
declare(strict_types=1);
namespace AllStore\Models;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\AppException;

/** All catalogue reads/writes. Dynamic categories, attributes, variants, stock. */
final class CatalogRepository
{
    private const SELECT = "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
        b.name AS brand_name, b.slug AS brand_slug,
        (SELECT pi.url FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_url
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        LEFT JOIN brands b ON b.id=p.brand_id";

    private const SORTS = [
        'recommended' => 'p.is_featured DESC, p.sold_count DESC, p.created_at DESC',
        'newest' => 'p.created_at DESC, p.id DESC',
        'price_asc' => 'p.price_cents ASC, p.id',
        'price_desc' => 'p.price_cents DESC, p.id',
        'best_selling' => 'p.sold_count DESC, p.rating_avg DESC',
        'best_rated' => 'p.rating_avg DESC, p.rating_count DESC',
        'discount' => '(p.compare_cents - p.price_cents) DESC, p.id',
    ];

    public static function listing(array $f = []): array
    {
        $where = ["p.deleted_at IS NULL", "p.status='active'"];
        $params = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '' && mb_strlen($q) <= 120) {
            $where[] = "(LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q OR LOWER(COALESCE(p.short_description,'')) LIKE :q OR LOWER(COALESCE(b.name,'')) LIKE :q OR LOWER(COALESCE(c.name,'')) LIKE :q)";
            $params['q'] = '%' . mb_strtolower($q) . '%';
        }
        $cat = (string)($f['category'] ?? '');
        if ($cat !== '') {
            $ids = self::categoryIds($cat);
            if ($ids === []) { $where[] = '1=0'; }
            else {
                $ph = [];
                foreach ($ids as $i => $id) { $ph[] = ':cat' . $i; $params['cat' . $i] = $id; }
                $where[] = 'p.category_id IN (' . implode(',', $ph) . ')';
            }
        }
        $brand = (string)($f['brand'] ?? '');
        if ($brand !== '') {
            $where[] = 'b.slug = :brand'; $params['brand'] = $brand;
        }
        $min = max(0, (int)($f['min_price'] ?? 0));
        $max = max(0, (int)($f['max_price'] ?? 0));
        if ($min > 0) { $where[] = 'p.price_cents >= :minp'; $params['minp'] = $min; }
        if ($max > 0 && $max >= $min) { $where[] = 'p.price_cents <= :maxp'; $params['maxp'] = $max; }
        if ((int)($f['rating'] ?? 0) > 0) { $where[] = 'p.rating_avg >= :rating'; $params['rating'] = min(5, (int)$f['rating']); }
        if (!empty($f['in_stock'])) { $where[] = 'p.stock > 0'; }
        if (!empty($f['on_sale'])) { $where[] = 'p.compare_cents > p.price_cents AND p.compare_cents > 0'; }
        if (!empty($f['featured'])) { $where[] = 'p.is_featured = 1'; }
        if (!empty($f['best_seller'])) { $where[] = 'p.is_best_seller = 1'; }
        if (!empty($f['new_arrival'])) { $where[] = 'p.is_new_arrival = 1'; }
        if (!empty($f['trending'])) { $where[] = 'p.is_trending = 1'; }
        if (!empty($f['flash'])) {
            $where[] = "EXISTS (SELECT 1 FROM flash_sale_items fsi JOIN flash_sales fs ON fs.id=fsi.flash_sale_id WHERE fsi.product_id=p.id AND fs.is_active=1 AND (fs.ends_at IS NULL OR fs.ends_at > :nowf))";
            $params['nowf'] = Helpers::now();
        }
        if (!empty($f['product_ids']) && is_array($f['product_ids'])) {
            $ids = array_values(array_filter($f['product_ids'], static fn($x) => is_string($x) && preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $x)));
            if ($ids === []) { $where[] = '1=0'; }
            else {
                $ph = [];
                foreach ($ids as $i => $id) { $ph[] = ':pid' . $i; $params['pid' . $i] = $id; }
                $where[] = 'p.id IN (' . implode(',', $ph) . ')';
            }
        }
        // Dynamic category attributes: {attribute_slug: [values...]}
        $attrs = $f['attributes'] ?? [];
        $ai = 0;
        if (is_array($attrs)) {
            foreach ($attrs as $slug => $values) {
                if (!is_string($slug) || !preg_match('/^[a-z0-9\-]{1,60}$/', $slug) || !is_array($values) || $values === []) { continue; }
                $vals = array_slice(array_values(array_filter($values, static fn($v) => is_scalar($v) && mb_strlen((string)$v) <= 80)), 0, 20);
                if ($vals === []) { continue; }
                $vph = [];
                foreach ($vals as $j => $v) { $vph[] = ':av' . $ai . '_' . $j; $params['av' . $ai . '_' . $j] = (string)$v; }
                $where[] = "EXISTS (SELECT 1 FROM product_attribute_values pav$ai
                    JOIN attribute_values av$ai ON av$ai.id = pav$ai.value_id
                    JOIN attributes at$ai ON at$ai.id = pav$ai.attribute_id
                    WHERE pav$ai.product_id = p.id AND at$ai.slug = :as$ai AND (av$ai.value IN (" . implode(',', $vph) . ") OR pav$ai.value_text IN (" . implode(',', $vph) . ")))";
                $params['as' . $ai] = $slug;
                $ai++;
            }
        }
        $w = implode(' AND ', $where);
        $total = (int)Database::value("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE $w", $params);
        $sort = (string)($f['sort'] ?? 'recommended');
        $order = self::SORTS[$sort] ?? self::SORTS['recommended'];
        $page = max(1, min(10000, (int)($f['page'] ?? 1)));
        $per = max(1, min(60, (int)($f['per_page'] ?? 12)));
        $offset = ($page - 1) * $per;
        // LIMIT/OFFSET are validated integers, never raw user strings.
        $items = Database::all(self::SELECT . " WHERE $w ORDER BY $order LIMIT $per OFFSET $offset", $params);
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per,
            'pages' => max(1, (int)ceil($total / $per)),
        ];
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::first(self::SELECT . ' WHERE p.slug=:s AND p.deleted_at IS NULL AND p.status=\'active\'', ['s'=>$slug]);
    }
    public static function findById(string $id, bool $anyStatus = false): ?array
    {
        $w = $anyStatus ? 'p.deleted_at IS NULL' : "p.deleted_at IS NULL AND p.status='active'";
        return Database::first(self::SELECT . " WHERE p.id=:i AND $w", ['i'=>$id]);
    }
    public static function images(string $productId): array
    {
        return Database::all('SELECT * FROM product_images WHERE product_id=:p ORDER BY is_primary DESC, sort_order ASC, created_at ASC', ['p'=>$productId]);
    }
    public static function variants(string $productId): array
    {
        return Database::all('SELECT * FROM product_variants WHERE product_id=:p AND is_active=1 ORDER BY name ASC', ['p'=>$productId]);
    }
    public static function productAttributes(string $productId): array
    {
        return Database::all("SELECT at.name AS attribute_name, at.slug AS attribute_slug, at.type, at.unit,
            COALESCE(av.label, pav.value_text, '') AS value_label, COALESCE(av.value, pav.value_text, '') AS value, av.color_hex
            FROM product_attribute_values pav
            JOIN attributes at ON at.id=pav.attribute_id
            LEFT JOIN attribute_values av ON av.id=pav.value_id
            WHERE pav.product_id=:p ORDER BY at.sort_order, at.name", ['p'=>$productId]);
    }
    public static function reviews(string $productId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all("SELECT r.*, p.full_name FROM reviews r JOIN profiles p ON p.id=r.user_id
            WHERE r.product_id=:p AND r.status='approved' ORDER BY r.created_at DESC LIMIT $limit", ['p'=>$productId]);
    }
    public static function ratingSummary(string $productId): array
    {
        $rows = Database::all("SELECT rating, COUNT(*) AS n FROM reviews WHERE product_id=:p AND status='approved' GROUP BY rating", ['p'=>$productId]);
        $dist = [1=>0,2=>0,3=>0,4=>0,5=>0];
        foreach ($rows as $r) { $dist[(int)$r['rating']] = (int)$r['n']; }
        return ['distribution'=>$dist, 'count'=>array_sum($dist)];
    }
    public static function related(string $productId, string $categoryId, int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        return Database::all(self::SELECT . " WHERE p.deleted_at IS NULL AND p.status='active' AND p.id <> :id AND p.category_id=:c ORDER BY p.sold_count DESC, p.rating_avg DESC LIMIT $limit", ['id'=>$productId,'c'=>$categoryId]);
    }
    public static function byIds(array $ids, int $limit = 20): array
    {
        $ids = array_values(array_filter($ids, static fn($x) => is_string($x) && preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $x)));
        if ($ids === []) { return []; }
        $ph = []; $p = [];
        foreach (array_slice($ids, 0, 60) as $i => $id) { $ph[] = ':i'.$i; $p['i'.$i] = $id; }
        $limit = max(1, min(60, $limit));
        return Database::all(self::SELECT . " WHERE p.deleted_at IS NULL AND p.status='active' AND p.id IN (".implode(',', $ph).") ORDER BY p.sold_count DESC LIMIT $limit", $p);
    }
    public static function recordView(string $productId, string $userKey): void
    {
        Database::exec("INSERT INTO recently_viewed(id,user_key,product_id,viewed_at) VALUES(:i,:u,:p,:t)
            ON CONFLICT(user_key,product_id) DO UPDATE SET viewed_at=:t2", [
            'i'=>Helpers::uuid(),'u'=>$userKey,'p'=>$productId,'t'=>Helpers::now(),'t2'=>Helpers::now(),
        ]);
    }
    public static function recentProducts(string $userKey, int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        return Database::all(self::SELECT . " JOIN recently_viewed rv ON rv.product_id=p.id
            WHERE rv.user_key=:u AND p.deleted_at IS NULL AND p.status='active' ORDER BY rv.viewed_at DESC LIMIT $limit", ['u'=>$userKey]);
    }

    // ------------------------------------------------------------ categories
    public static function categoryIds(string $slugOrId): array
    {
        $c = Database::first('SELECT id FROM categories WHERE (slug=:x OR id=:x) AND is_active=1 AND deleted_at IS NULL', ['x'=>$slugOrId]);
        if (!$c) { return []; }
        $ids = [$c['id']];
        for ($depth = 0; $depth < 6; $depth++) {
            $ph = []; $p = [];
            foreach ($ids as $i => $id) { $ph[] = ':c'.$i; $p['c'.$i] = $id; }
            $children = Database::all('SELECT id FROM categories WHERE parent_id IN ('.implode(',', $ph).') AND is_active=1 AND deleted_at IS NULL', $p);
            $new = [];
            foreach ($children as $ch) { if (!in_array($ch['id'], $ids, true)) { $new[] = $ch['id']; } }
            if ($new === []) { break; }
            $ids = array_merge($ids, $new);
        }
        return $ids;
    }
    public static function findCategory(string $slug): ?array
    {
        return Database::first('SELECT * FROM categories WHERE slug=:s AND is_active=1 AND deleted_at IS NULL', ['s'=>$slug]);
    }
    public static function categories(?string $parentId = null, bool $activeOnly = true): array
    {
        $w = $activeOnly ? 'is_active=1 AND deleted_at IS NULL' : 'deleted_at IS NULL';
        if ($parentId === null) { return Database::all("SELECT * FROM categories WHERE parent_id IS NULL AND $w ORDER BY sort_order, name"); }
        return Database::all("SELECT * FROM categories WHERE parent_id=:p AND $w ORDER BY sort_order, name", ['p'=>$parentId]);
    }
    public static function allCategories(): array
    {
        return Database::all('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.deleted_at IS NULL) AS product_count FROM categories c WHERE c.deleted_at IS NULL ORDER BY c.sort_order, c.name');
    }
    public static function tree(): array
    {
        $all = Database::all('SELECT * FROM categories WHERE is_active=1 AND deleted_at IS NULL ORDER BY sort_order, name');
        $byParent = [];
        foreach ($all as $c) { $byParent[$c['parent_id'] ?? 'root'][] = $c; }
        $build = function ($parent) use (&$build, $byParent): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $c) { $c['children'] = $build($c['id']); $out[] = $c; }
            return $out;
        };
        return $build('root');
    }
    public static function breadcrumbs(string $categoryId): array
    {
        $out = []; $id = $categoryId; $guard = 0;
        while ($id && $guard++ < 8) {
            $c = Database::first('SELECT id,name,slug,parent_id FROM categories WHERE id=:i', ['i'=>$id]);
            if (!$c) { break; }
            array_unshift($out, $c);
            $id = $c['parent_id'] ?? null;
        }
        return $out;
    }
    public static function categoryAttributes(string $categoryId): array
    {
        return Database::all("SELECT at.*, ca.is_filterable AS cat_filterable FROM category_attributes ca
            JOIN attributes at ON at.id=ca.attribute_id WHERE ca.category_id=:c ORDER BY ca.sort_order, at.name", ['c'=>$categoryId]);
    }
    public static function attributeOptions(string $attributeId): array
    {
        return Database::all('SELECT * FROM attribute_values WHERE attribute_id=:a ORDER BY sort_order, label', ['a'=>$attributeId]);
    }
    public static function brands(bool $activeOnly = true): array
    {
        $w = $activeOnly ? 'WHERE is_active=1' : '';
        return Database::all("SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id=b.id AND p.deleted_at IS NULL) AS product_count FROM brands b $w ORDER BY b.sort_order, b.name");
    }
    public static function findBrand(string $slug): ?array
    {
        return Database::first('SELECT * FROM brands WHERE slug=:s AND is_active=1', ['s'=>$slug]);
    }
    public static function priceRange(): array
    {
        $r = Database::first("SELECT COALESCE(MIN(price_cents),0) AS minp, COALESCE(MAX(price_cents),0) AS maxp FROM products WHERE deleted_at IS NULL AND status='active'");
        return ['min'=>(int)($r['minp'] ?? 0), 'max'=>(int)($r['maxp'] ?? 100000)];
    }

    // --------------------------------------------------------------- writes
    public static function saveCategory(array $in, ?string $id = null): string
    {
        $name = trim((string)($in['name'] ?? ''));
        if (mb_strlen($name) < 2) { throw new AppException('Category name is required.', 422); }
        $slug = Helpers::slugify((string)($in['slug'] ?? $name));
        $parent = (string)($in['parent_id'] ?? '') ?: null;
        if ($parent === $id) { throw new AppException('A category cannot be its own parent.', 422); }
        $data = [
            'name'=>$name, 'slug'=>$slug, 'parent_id'=>$parent,
            'description'=>(string)($in['description'] ?? ''), 'image_url'=>(string)($in['image_url'] ?? ''),
            'icon'=>(string)($in['icon'] ?? 'box'), 'banner_url'=>(string)($in['banner_url'] ?? ''),
            'seo_title'=>(string)($in['seo_title'] ?? ''), 'seo_description'=>(string)($in['seo_description'] ?? ''),
            'sort_order'=>(int)($in['sort_order'] ?? 0), 'is_active'=>(int)($in['is_active'] ?? 1),
            'is_featured'=>(int)($in['is_featured'] ?? 0), 'updated_at'=>Helpers::now(),
        ];
        if ($id) {
            $exists = Database::first('SELECT id FROM categories WHERE slug=:s AND id<>:i', ['s'=>$slug,'i'=>$id]);
            if ($exists) { $slug .= '-' . substr(Helpers::token(3), 0, 5); $data['slug'] = $slug; }
            Database::update('categories', $data, 'id=:id', ['id'=>$id]);
            return $id;
        }
        if (Database::first('SELECT id FROM categories WHERE slug=:s', ['s'=>$slug])) { $slug .= '-' . substr(Helpers::token(3), 0, 5); $data['slug'] = $slug; }
        $data['id'] = $id = Helpers::uuid(); $data['created_at'] = Helpers::now();
        Database::insert('categories', $data);
        return $id;
    }
    public static function deleteCategory(string $id): void
    {
        Database::update('categories', ['deleted_at'=>Helpers::now(),'is_active'=>0,'updated_at'=>Helpers::now()], 'id=:id', ['id'=>$id]);
    }
    public static function saveBrand(array $in, ?string $id = null): string
    {
        $name = trim((string)($in['name'] ?? ''));
        if (mb_strlen($name) < 2) { throw new AppException('Brand name is required.', 422); }
        $slug = Helpers::slugify((string)($in['slug'] ?? $name));
        $data = ['name'=>$name,'slug'=>$slug,'logo_url'=>(string)($in['logo_url'] ?? ''),
            'description'=>(string)($in['description'] ?? ''),'website'=>(string)($in['website'] ?? ''),
            'is_active'=>(int)($in['is_active'] ?? 1),'sort_order'=>(int)($in['sort_order'] ?? 0),'updated_at'=>Helpers::now()];
        if ($id) { Database::update('brands', $data, 'id=:id', ['id'=>$id]); return $id; }
        if (Database::first('SELECT id FROM brands WHERE slug=:s', ['s'=>$slug])) { $data['slug'] = $slug.'-'.substr(Helpers::token(3),0,5); }
        $data['id'] = $id = Helpers::uuid(); $data['created_at'] = Helpers::now();
        Database::insert('brands', $data);
        return $id;
    }
    public static function allAttributes(): array
    {
        return Database::all('SELECT a.*, (SELECT COUNT(*) FROM attribute_values v WHERE v.attribute_id=a.id) AS value_count FROM attributes a ORDER BY a.sort_order, a.name');
    }
    public static function saveAttribute(array $in, ?string $id = null): string
    {
        $name = trim((string)($in['name'] ?? ''));
        $type = (string)($in['type'] ?? 'dropdown');
        if ($name === '' || !in_array($type, ['text','number','boolean','dropdown','multiselect','color','measurement'], true)) {
            throw new AppException('Attribute name and a valid type are required.', 422);
        }
        $data = ['name'=>$name,'slug'=>Helpers::slugify((string)($in['slug'] ?? $name)),'type'=>$type,
            'unit'=>(string)($in['unit'] ?? ''),'is_filterable'=>(int)($in['is_filterable'] ?? 1),
            'sort_order'=>(int)($in['sort_order'] ?? 0)];
        if ($id) { Database::update('attributes', $data, 'id=:id', ['id'=>$id]); }
        else { $data['id'] = $id = Helpers::uuid(); $data['created_at'] = Helpers::now(); Database::insert('attributes', $data); }
        $values = (string)($in['values'] ?? '');
        if ($values !== '') {
            foreach (array_slice(preg_split('/\r\n|\r|\n/', $values) ?: [], 0, 100) as $line) {
                $line = trim($line); if ($line === '') { continue; }
                $parts = array_map('trim', explode('|', $line, 2));
                $val = Helpers::slugify($parts[0]); $label = $parts[1] ?? $parts[0];
                Database::exec("INSERT INTO attribute_values(id,attribute_id,value,label,sort_order) VALUES(:i,:a,:v,:l,0) ON CONFLICT(attribute_id,value) DO UPDATE SET label=:l2",
                    ['i'=>Helpers::uuid(),'a'=>$id,'v'=>$val,'l'=>$label,'l2'=>$label]);
            }
        }
        return $id;
    }
    public static function saveProduct(array $in, ?string $id = null): string
    {
        $name = trim((string)($in['name'] ?? ''));
        if (mb_strlen($name) < 2) { throw new AppException('Product name is required.', 422); }
        $price = max(0, (int)round(((float)($in['price'] ?? 0)) * 100));
        $status = (string)($in['status'] ?? 'draft');
        if (!in_array($status, ['draft','active','inactive','archived'], true)) { $status = 'draft'; }
        $type = (string)($in['product_type'] ?? 'simple');
        if (!in_array($type, ['simple','variable','bundle','digital'], true)) { $type = 'simple'; }
        $slug = Helpers::slugify((string)($in['slug'] ?? $name));
        $sku = trim((string)($in['sku'] ?? '')) ?: 'SKU-' . strtoupper(substr(Helpers::token(5), 0, 8));
        $data = [
            'name'=>$name, 'slug'=>$slug, 'sku'=>$sku, 'barcode'=>(string)($in['barcode'] ?? ''),
            'category_id'=>(string)($in['category_id'] ?? '') ?: null,
            'brand_id'=>(string)($in['brand_id'] ?? '') ?: null,
            'short_description'=>(string)($in['short_description'] ?? ''),
            'full_description'=>(string)($in['full_description'] ?? ''),
            'product_type'=>$type,
            'cost_cents'=>max(0, (int)round(((float)($in['cost'] ?? 0)) * 100)),
            'price_cents'=>$price,
            'compare_cents'=>max(0, (int)round(((float)($in['compare_price'] ?? 0)) * 100)),
            'tax_rate'=>(float)($in['tax_rate'] ?? 0),
            'stock'=>max(0, (int)($in['stock'] ?? 0)),
            'low_stock_threshold'=>max(0, (int)($in['low_stock_threshold'] ?? 5)),
            'weight_grams'=>max(0, (int)($in['weight_grams'] ?? 0)),
            'dimensions'=>(string)($in['dimensions'] ?? ''), 'warranty'=>(string)($in['warranty'] ?? ''),
            'return_eligible'=>(int)($in['return_eligible'] ?? 1), 'return_days'=>max(0,(int)($in['return_days'] ?? 14)),
            'status'=>$status, 'is_featured'=>(int)($in['is_featured'] ?? 0),
            'is_best_seller'=>(int)($in['is_best_seller'] ?? 0), 'is_new_arrival'=>(int)($in['is_new_arrival'] ?? 0),
            'is_trending'=>(int)($in['is_trending'] ?? 0), 'is_digital'=>($type === 'digital' ? 1 : 0),
            'seo_title'=>(string)($in['seo_title'] ?? ''), 'seo_description'=>(string)($in['seo_description'] ?? ''),
            'video_url'=>(string)($in['video_url'] ?? ''), 'supplier_name'=>(string)($in['supplier_name'] ?? ''),
            'updated_at'=>Helpers::now(),
        ];
        if ($id) {
            $conf = Database::first('SELECT id FROM products WHERE slug=:s AND id<>:i', ['s'=>$slug,'i'=>$id]);
            if ($conf) { $data['slug'] = $slug . '-' . substr(Helpers::token(3),0,5); }
            Database::update('products', $data, 'id=:id', ['id'=>$id]);
        } else {
            if (Database::first('SELECT id FROM products WHERE slug=:s', ['s'=>$slug])) { $data['slug'] = $slug.'-'.substr(Helpers::token(3),0,5); }
            if (Database::first('SELECT id FROM products WHERE sku=:s', ['s'=>$sku])) { $data['sku'] = $sku.'-'.substr(Helpers::token(3),0,5); }
            $data['id'] = $id = Helpers::uuid(); $data['created_at'] = Helpers::now();
            Database::insert('products', $data);
            $wh = Database::first('SELECT id FROM warehouses WHERE is_default=1') ?: Database::first('SELECT id FROM warehouses LIMIT 1');
            if ($wh) {
                Database::insert('inventory', ['id'=>Helpers::uuid(),'product_id'=>$id,'variant_id'=>'','warehouse_id'=>$wh['id'],
                    'quantity'=>$data['stock'],'available'=>$data['stock'],'updated_at'=>Helpers::now()]);
            }
        }
        // Images: URLs only (uploads go through UploadService). Never accept HTML.
        $images = $in['images'] ?? [];
        if (is_array($images) && $id) {
            Database::delete('product_images', 'product_id=:p', ['p'=>$id]);
            $i = 0;
            foreach (array_slice($images, 0, 12) as $url) {
                $url = trim((string)$url);
                if ($url === '') { continue; }
                if (!preg_match('#^(/assets/|/storage/uploads/[A-Za-z0-9/_\.\-]+|https://)#', $url)) { continue; }
                Database::insert('product_images', ['id'=>Helpers::uuid(),'product_id'=>$id,'url'=>$url,
                    'alt'=>$name,'sort_order'=>$i,'is_primary'=>$i === 0 ? 1 : 0,'created_at'=>Helpers::now()]);
                $i++;
            }
        }
        return $id;
    }
    public static function duplicateProduct(string $id): string
    {
        $p = Database::first('SELECT * FROM products WHERE id=:i AND deleted_at IS NULL', ['i'=>$id]);
        if (!$p) { throw new AppException('Product not found.', 404); }
        $copy = $p;
        $copy['name'] = $p['name'] . ' (Copy)';
        $copy['slug'] = Helpers::slugify($p['slug'] . '-copy-' . substr(Helpers::token(3),0,5));
        $copy['sku'] = $p['sku'] . '-C' . substr(Helpers::token(3),0,5);
        $copy['status'] = 'draft';
        $newId = Helpers::uuid();
        $insert = $copy; $insert['id'] = $newId; $insert['created_at'] = Helpers::now(); $insert['updated_at'] = Helpers::now();
        unset($insert['deleted_at']);
        Database::insert('products', $insert);
        foreach (self::images($id) as $img) {
            Database::insert('product_images', ['id'=>Helpers::uuid(),'product_id'=>$newId,'url'=>$img['url'],'alt'=>$img['alt'],
                'sort_order'=>$img['sort_order'],'is_primary'=>$img['is_primary'],'created_at'=>Helpers::now()]);
        }
        return $newId;
    }
    public static function deleteProduct(string $id): void
    {
        Database::update('products', ['deleted_at'=>Helpers::now(),'status'=>'archived','updated_at'=>Helpers::now()], 'id=:id', ['id'=>$id]);
    }
    public static function bulk(string $action, array $ids, array $extra = []): int
    {
        $n = 0;
        foreach ($ids as $id) {
            if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $id)) { continue; }
            if ($action === 'delete') { self::deleteProduct($id); $n++; }
            elseif ($action === 'duplicate') { self::duplicateProduct($id); $n++; }
            elseif ($action === 'status') {
                $s = (string)($extra['status'] ?? 'draft');
                if (in_array($s, ['draft','active','inactive','archived'], true)) {
                    Database::update('products', ['status'=>$s,'updated_at'=>Helpers::now()], 'id=:id', ['id'=>$id]); $n++;
                }
            } elseif ($action === 'category' && (string)($extra['category_id'] ?? '') !== '') {
                Database::update('products', ['category_id'=>$extra['category_id'],'updated_at'=>Helpers::now()], 'id=:id', ['id'=>$id]); $n++;
            } elseif ($action === 'price_adjust') {
                $pct = (float)($extra['percent'] ?? 0);
                if ($pct !== 0.0) {
                    $factor = max(0.01, 1 + ($pct/100));
                    Database::exec('UPDATE products SET price_cents = CASE WHEN CAST(ROUND(price_cents * :f) AS INTEGER) < 0 THEN 0 ELSE CAST(ROUND(price_cents * :f2) AS INTEGER) END, updated_at=:t WHERE id=:id',
                        ['f'=>$factor, 'f2'=>$factor, 't'=>Helpers::now(), 'id'=>$id]); $n++;
                }
            }
        }
        return $n;
    }
    public static function lowStock(): array
    {
        return Database::all("SELECT p.id,p.name,p.sku,p.stock,p.low_stock_threshold FROM products p WHERE p.deleted_at IS NULL AND p.status='active' AND p.stock <= p.low_stock_threshold ORDER BY p.stock ASC LIMIT 100");
    }
    public static function exportCsv(): string
    {
        $rows = Database::all('SELECT p.sku,p.name,p.slug,p.status,p.price_cents,p.compare_cents,p.stock,c.name AS category,b.name AS brand FROM products p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE p.deleted_at IS NULL ORDER BY p.created_at DESC');
        $h = fopen('php://temp', 'w+');
        fputcsv($h, ['sku','name','slug','status','price','compare_price','stock','category','brand']);
        foreach ($rows as $r) { fputcsv($h, [$r['sku'],$r['name'],$r['slug'],$r['status'],number_format($r['price_cents']/100,2,'.',''),number_format($r['compare_cents']/100,2,'.',''),$r['stock'],$r['category'],$r['brand']]); }
        rewind($h); $csv = (string)stream_get_contents($h); fclose($h);
        return $csv;
    }
    public static function importCsv(string $csv): int
    {
        $h = fopen('php://temp', 'w+'); fwrite($h, $csv); rewind($h);
        $header = fgetcsv($h); if (!$header) { fclose($h); return 0; }
        $header = array_map(static fn($x) => strtolower(trim((string)$x)), $header);
        $n = 0;
        while (($row = fgetcsv($h)) !== false && $n < 500) {
            if (count($row) !== count($header)) { continue; }
            $in = array_combine($header, $row);
            if (empty($in['name']) || empty($in['sku'])) { continue; }
            $cat = Database::first('SELECT id FROM categories WHERE name=:n OR slug=:n', ['n'=>trim((string)($in['category'] ?? ''))]);
            $brand = Database::first('SELECT id FROM brands WHERE name=:n OR slug=:n', ['n'=>trim((string)($in['brand'] ?? ''))]);
            self::saveProduct([
                'name'=>(string)$in['name'], 'sku'=>(string)$in['sku'],
                'slug'=>(string)($in['slug'] ?? ''), 'price'=>(string)($in['price'] ?? '0'),
                'compare_price'=>(string)($in['compare_price'] ?? '0'), 'stock'=>(string)($in['stock'] ?? '0'),
                'status'=>'draft', 'category_id'=>$cat['id'] ?? '', 'brand_id'=>$brand['id'] ?? '',
            ]);
            $n++;
        }
        fclose($h);
        return $n;
    }
}
