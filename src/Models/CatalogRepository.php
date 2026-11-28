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
            WHERE pav.product_id=:p AND at.slug NOT LIKE 'system-%' ORDER BY at.sort_order, at.name", ['p'=>$productId]);
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
        return Database::all("SELECT a.*, (SELECT COUNT(*) FROM attribute_values v WHERE v.attribute_id=a.id) AS value_count
            FROM attributes a WHERE a.slug NOT LIKE 'system-%' ORDER BY a.sort_order, a.name");
    }
    public static function saveAttribute(array $in, ?string $id = null): string
    {
        $name = trim((string)($in['name'] ?? ''));
        $type = (string)($in['type'] ?? 'dropdown');
        if ($name === '' || !in_array($type, ['text','number','boolean','dropdown','multiselect','color','measurement'], true)) {
            throw new AppException('Attribute name and a valid type are required.', 422);
        }
        $slug = Helpers::slugify((string)($in['slug'] ?? $name));
        if (str_starts_with($slug, 'system-')) { throw new AppException('This slug is reserved for internal product data.', 422); }
        if ($id !== null) {
            $existing = Database::first('SELECT slug FROM attributes WHERE id=:i', ['i'=>$id]);
            if ($existing && str_starts_with((string)$existing['slug'], 'system-')) {
                throw new AppException('Internal product data cannot be edited here.', 422);
            }
        }
        $data = ['name'=>$name,'slug'=>$slug,'type'=>$type,
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
    private const SYSTEM_META = [
        'system-product-kind' => 'Product kind',
        'system-custom-options' => 'Custom order options',
        'system-digital-file' => 'Digital delivery file',
        'system-bundle-items' => 'Bundle contents',
        'system-service-details' => 'Service details',
    ];
    private const SYSTEM_META_FIELDS = [
        'system-product-kind' => 'product_kind',
        'system-custom-options' => 'custom_options',
        'system-digital-file' => 'digital_file_url',
        'system-bundle-items' => 'bundle_items',
        'system-service-details' => 'service_details',
    ];

    private static function ensureSystemAttribute(string $slug, string $name): string
    {
        $row = Database::first('SELECT id FROM attributes WHERE slug=:s', ['s'=>$slug]);
        if ($row) { return (string)$row['id']; }
        $id = Helpers::uuid();
        Database::exec("INSERT INTO attributes(id,name,slug,type,unit,is_filterable,sort_order,created_at)
            VALUES(:i,:n,:s,'text','',0,900,:t) ON CONFLICT(slug) DO NOTHING",
            ['i'=>$id,'n'=>$name,'s'=>$slug,'t'=>Helpers::now()]);
        $row = Database::first('SELECT id FROM attributes WHERE slug=:s', ['s'=>$slug]);
        return (string)($row['id'] ?? $id);
    }

    public static function saveSystemMeta(string $productId, string $slug, string $value): void
    {
        $name = self::SYSTEM_META[$slug] ?? 'Product meta';
        $attributeId = self::ensureSystemAttribute($slug, $name);
        $value = mb_substr($value, 0, 20000);
        Database::exec("INSERT INTO product_attribute_values(product_id,attribute_id,value_id,value_text)
            VALUES(:p,:a,'',:v) ON CONFLICT(product_id,attribute_id,value_id) DO UPDATE SET value_text=:v2",
            ['p'=>$productId,'a'=>$attributeId,'v'=>$value,'v2'=>$value]);
    }

    public static function productMetaRaw(string $productId): array
    {
        $rows = Database::all("SELECT a.slug, pav.value_text FROM product_attribute_values pav
            JOIN attributes a ON a.id=pav.attribute_id
            WHERE pav.product_id=:p AND a.slug LIKE 'system-%'", ['p'=>$productId]);
        $out = [];
        foreach ($rows as $r) { $out[(string)$r['slug']] = (string)($r['value_text'] ?? ''); }
        return $out;
    }

    public static function productMeta(string $productId): array
    {
        $raw = self::productMetaRaw($productId);
        return [
            'kind'=>mb_substr(trim((string)($raw['system-product-kind'] ?? 'physical')), 0, 40) ?: 'physical',
            'custom_options'=>self::parseCustomOptions((string)($raw['system-custom-options'] ?? '')),
            'custom_options_text'=>(string)($raw['system-custom-options'] ?? ''),
            'digital_file_url'=>Helpers::safeMediaUrl((string)($raw['system-digital-file'] ?? '')),
            'bundle_items'=>self::parseBundleItems((string)($raw['system-bundle-items'] ?? '')),
            'bundle_items_text'=>(string)($raw['system-bundle-items'] ?? ''),
            'service_details'=>(string)($raw['system-service-details'] ?? ''),
        ];
    }

    public static function customOptions(string $productId): array
    {
        return self::productMeta($productId)['custom_options'];
    }

    public static function parseCustomOptions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') { return []; }
        $rows = [];
        if (str_starts_with($raw, '[')) {
            $decoded = Helpers::decode($raw, []);
            if (is_array($decoded)) { $rows = $decoded; }
        } else {
            foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
                $parts = array_map('trim', explode('|', $line, 4));
                $rows[] = ['label'=>$parts[0] ?? '', 'type'=>$parts[1] ?? 'text', 'required'=>$parts[2] ?? '', 'choices'=>$parts[3] ?? ''];
            }
        }
        $out = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            if (!is_array($row)) { continue; }
            $label = mb_substr(trim((string)($row['label'] ?? '')), 0, 120);
            if ($label === '') { continue; }
            $type = strtolower((string)($row['type'] ?? 'text'));
            if (!in_array($type, ['text','textarea','select'], true)) { $type = 'text'; }
            $choicesRaw = $row['choices'] ?? [];
            if (is_string($choicesRaw)) { $choicesRaw = explode(',', $choicesRaw); }
            $choices = [];
            foreach ((array)$choicesRaw as $choice) {
                $choice = mb_substr(trim((string)$choice), 0, 120);
                if ($choice !== '') { $choices[] = $choice; }
                if (count($choices) >= 30) { break; }
            }
            $out[] = [
                'slug'=>Helpers::slugify($label), 'label'=>$label, 'type'=>$type,
                'required'=>in_array(strtolower((string)($row['required'] ?? '')), ['1','true','yes','required','on'], true),
                'choices'=>$choices,
            ];
        }
        return $out;
    }

    public static function parseBundleItems(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') { return []; }
        $rows = [];
        if (str_starts_with($raw, '[')) {
            $decoded = Helpers::decode($raw, []);
            if (is_array($decoded)) { $rows = $decoded; }
        } else {
            foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
                $parts = array_map('trim', explode('|', $line, 3));
                $rows[] = ['sku'=>$parts[0] ?? '', 'name'=>$parts[1] ?? ($parts[0] ?? ''), 'qty'=>$parts[2] ?? 1];
            }
        }
        $out = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) { continue; }
            $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 160);
            if ($name === '') { continue; }
            $out[] = ['sku'=>mb_substr(trim((string)($row['sku'] ?? '')),0,64),'name'=>$name,
                'qty'=>max(1,min(999,(int)($row['qty'] ?? 1)))];
        }
        return $out;
    }

    public static function validateCustomOptions(array $definitions, mixed $submitted): array
    {
        $submitted = is_array($submitted) ? $submitted : [];
        $out = [];
        foreach ($definitions as $def) {
            $slug = (string)($def['slug'] ?? '');
            if ($slug === '') { continue; }
            $raw = $submitted[$slug] ?? null;
            if (is_array($raw)) { $raw = $raw['value'] ?? ''; }
            $value = mb_substr(trim((string)(is_scalar($raw) ? $raw : '')), 0, 300);
            if (!empty($def['required']) && $value === '') {
                throw new AppException(($def['label'] ?? 'Custom option') . ' is required.', 422);
            }
            $choices = (array)($def['choices'] ?? []);
            if ($value !== '' && $choices !== [] && !in_array($value, $choices, true)) {
                throw new AppException('Choose a valid value for ' . ($def['label'] ?? 'custom option') . '.', 422);
            }
            if ($value !== '') { $out[$slug] = ['label'=>(string)($def['label'] ?? $slug),'value'=>$value]; }
        }
        return $out;
    }

    public static function saveProductSpecs(string $productId, mixed $specs): void
    {
        if (!is_array($specs)) { return; }
        foreach (array_slice($specs, 0, 40, true) as $attributeId => $value) {
            $attributeId = (string)$attributeId;
            if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $attributeId)) { continue; }
            $attribute = Database::first('SELECT slug FROM attributes WHERE id=:a', ['a'=>$attributeId]);
            if (!$attribute || str_starts_with((string)$attribute['slug'], 'system-')) { continue; }
            $text = mb_substr(trim((string)(is_scalar($value) ? $value : '')), 0, 300);
            if ($text === '') {
                Database::delete('product_attribute_values', 'product_id=:p AND attribute_id=:a AND value_id=:v',
                    ['p'=>$productId,'a'=>$attributeId,'v'=>'']);
                continue;
            }
            Database::exec("INSERT INTO product_attribute_values(product_id,attribute_id,value_id,value_text)
                VALUES(:p,:a,'',:t) ON CONFLICT(product_id,attribute_id,value_id) DO UPDATE SET value_text=:t2",
                ['p'=>$productId,'a'=>$attributeId,'t'=>$text,'t2'=>$text]);
        }
    }

    /** Normalise a free-text size list ("S, M, L, XL") into clean labels. */
    public static function parseSizeLabels(string $raw): array
    {
        $parts = preg_split('#[,;\r\n|/]+#', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $label = trim(preg_replace('/\s+/', ' ', (string)$part) ?? '');
            $label = trim($label, " -–—");
            if ($label === '' || mb_strlen($label) > 24) { continue; }
            $label = preg_replace('/[\x00-\x1F]/u', '', $label) ?? $label;
            if ($label === '') { continue; }
            $key = mb_strtolower($label);
            if (isset($out[$key])) { continue; }
            $out[$key] = $label;
            if (count($out) >= 20) { break; }
        }
        return array_values($out);
    }

    /** Size variants created by the admin "sizes" field (never manual variants). */
    public static function sizeVariants(string $productId): array
    {
        $rows = Database::all("SELECT * FROM product_variants WHERE product_id=:p AND is_active=1", ['p'=>$productId]);
        $out = [];
        foreach ($rows as $row) {
            $opts = (array)Helpers::decode((string)($row['options'] ?? '{}'), []);
            if ((string)($opts['__managed'] ?? '') !== 'size') { continue; }
            $row['__order'] = (int)($opts['__order'] ?? 999);
            $out[] = $row;
        }
        usort($out, static fn(array $a, array $b): int => [$a['__order'], (string)$a['name']] <=> [$b['__order'], (string)$b['name']]);
        return $out;
    }

    /**
     * Create/update/remove the size variants for a product from one text field.
     * Existing sizes keep their own price, stock and image; a size removed from
     * the field is deleted.
     *
     * @return array{created:int,kept:int,removed:int,labels:array<int,string>}
     */
    public static function syncSizeVariants(string $productId, string $raw): array
    {
        $labels = self::parseSizeLabels($raw);
        $product = Database::first('SELECT id, sku, name, price_cents, compare_cents, stock FROM products WHERE id=:i', ['i'=>$productId]);
        if (!$product) { return ['created'=>0,'kept'=>0,'removed'=>0,'labels'=>[]]; }
        $existing = self::sizeVariants($productId);
        $byLabel = [];
        foreach ($existing as $row) {
            $opts = (array)Helpers::decode((string)($row['options'] ?? '{}'), []);
            $byLabel[mb_strtolower((string)($opts['Size'] ?? $row['name']))] = $row;
        }
        $keep = [];
        $created = 0; $kept = 0;
        foreach ($labels as $index => $label) {
            $key = mb_strtolower($label);
            $row = $byLabel[$key] ?? null;
            if ($row) {
                $keep[] = (string)$row['id'];
                // Keep the merchant's order and label casing in step with the field.
                Database::update('product_variants', [
                    'name'=>$label,
                    'options'=>Helpers::json(['Size'=>$label, '__managed'=>'size', '__order'=>$index]),
                    'updated_at'=>Helpers::now(),
                ], 'id=:i', ['i'=>$row['id']]);
                $kept++;
                continue;
            }
            $sku = self::uniqueVariantSku((string)$product['sku'], $label);
            $id = Helpers::uuid();
            Database::insert('product_variants', [
                'id'=>$id, 'product_id'=>$productId, 'sku'=>$sku, 'barcode'=>'',
                'name'=>$label,
                'options'=>Helpers::json(['Size'=>$label, '__managed'=>'size', '__order'=>$index]),
                'price_cents'=>(int)$product['price_cents'],
                'compare_cents'=>(int)$product['compare_cents'],
                'stock'=>(int)$product['stock'],
                'image_url'=>'', 'is_active'=>1,
                'created_at'=>Helpers::now(), 'updated_at'=>Helpers::now(),
            ]);
            $wh = Database::first('SELECT id FROM warehouses WHERE is_default=1') ?: Database::first('SELECT id FROM warehouses LIMIT 1');
            if ($wh) {
                Database::insert('inventory', ['id'=>Helpers::uuid(),'product_id'=>$productId,'variant_id'=>$id,
                    'warehouse_id'=>$wh['id'],'quantity'=>(int)$product['stock'],'available'=>(int)$product['stock'],
                    'updated_at'=>Helpers::now()]);
            }
            $keep[] = $id;
            $created++;
        }
        $removed = 0;
        foreach ($existing as $row) {
            foreach ($keep as $id) { if ($id === (string)$row['id']) { continue 2; } }
            Database::delete('product_variants', 'id=:i AND product_id=:p', ['i'=>$row['id'],'p'=>$productId]);
            Database::delete('inventory', 'variant_id=:v', ['v'=>$row['id']]);
            $removed++;
        }
        // A product with sizes is a variable product; without sizes it is simple.
        $type = (string)Database::value('SELECT product_type FROM products WHERE id=:i', ['i'=>$productId]);
        if ($labels !== [] && $type === 'simple') {
            Database::update('products', ['product_type'=>'variable','updated_at'=>Helpers::now()], 'id=:i', ['i'=>$productId]);
        }
        if ($labels === [] && $type === 'variable') {
            Database::update('products', ['product_type'=>'simple','updated_at'=>Helpers::now()], 'id=:i', ['i'=>$productId]);
        }
        return ['created'=>$created,'kept'=>$kept,'removed'=>$removed,'labels'=>$labels];
    }

    /** SKU for a size variant, e.g. TS-1001-M, kept unique across the catalog. */
    private static function uniqueVariantSku(string $productSku, string $label): string
    {
        $suffix = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $label) ?? '');
        if ($suffix === '') { $suffix = strtoupper(substr(Helpers::token(4), 0, 4)); }
        $base = $productSku . '-' . mb_substr($suffix, 0, 8);
        $sku = $base;
        $n = 1;
        while (Database::first('SELECT id FROM product_variants WHERE sku=:s', ['s'=>$sku])) {
            $n++;
            $sku = $base . '-' . $n;
            if ($n > 50) { $sku = $base . '-' . strtoupper(substr(Helpers::token(4), 0, 4)); break; }
        }
        return $sku;
    }

    /**
     * Active variants in the order a shopper should see them: sizes keep the
     * merchant's order (S, M, L, XL), every other variant stays alphabetical.
     */
    public static function variantsForDisplay(string $productId): array
    {
        $variants = self::variants($productId);
        $order = [];
        foreach (self::sizeVariants($productId) as $row) { $order[(string)$row['id']] = (int)$row['__order']; }
        if ($order === []) { return $variants; }
        usort($variants, static function (array $a, array $b) use ($order): int {
            $oa = $order[(string)$a['id']] ?? 999;
            $ob = $order[(string)$b['id']] ?? 999;
            return $oa === $ob ? strcmp((string)$a['name'], (string)$b['name']) : $oa <=> $ob;
        });
        return $variants;
    }

    /** Value for the admin "sizes" field: managed sizes, else a legacy size spec. */
    public static function sizeFieldValue(string $productId): string
    {
        $labels = [];
        foreach (self::sizeVariants($productId) as $row) {
            $opts = (array)Helpers::decode((string)($row['options'] ?? '{}'), []);
            $labels[] = (string)($opts['Size'] ?? $row['name']);
        }
        if ($labels !== []) { return implode(', ', $labels); }
        $legacy = Database::value("SELECT pav.value_text FROM product_attribute_values pav
            JOIN attributes a ON a.id = pav.attribute_id
            WHERE pav.product_id=:p AND a.slug='size' LIMIT 1", ['p'=>$productId]);
        $text = trim((string)($legacy ?? ''));
        if ($text === '' || !preg_match('#[,/]#', $text)) { return ''; }
        return implode(', ', self::parseSizeLabels($text));
    }

    /** Label for the PDP option group: "Size" when the options are sizes. */
    public static function variantGroupLabel(array $variants): string
    {
        $keys = [];
        foreach ($variants as $variant) {
            $opts = (array)Helpers::decode((string)($variant['options'] ?? '{}'), []);
            foreach (array_keys($opts) as $key) {
                if (str_starts_with((string)$key, '__')) { continue; }
                $keys[mb_strtolower((string)$key)] = (string)$key;
            }
        }
        if (count($keys) === 1) {
            $key = (string)array_key_first($keys);
            if ($key === 'size') { return 'Size'; }
            if ($key === 'color' || $key === 'colour') { return 'Colour'; }
            if ($key !== 'variant') { return ucfirst($key); }
        }
        // Fall back to the labels themselves ("S", "M", "XL" → sizes).
        $sizeLike = true;
        foreach ($variants as $variant) {
            if (preg_match('/^(x{0,3}s|s|m|l|x{0,2}l|\d{2,3}|\d{1,2}\s?(y|yr|years?))$/i', trim((string)$variant['name'])) !== 1) {
                $sizeLike = false;
                break;
            }
        }
        return $sizeLike ? 'Size' : 'Choose an option';
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
        $kind = strtolower(trim((string)($in['product_kind'] ?? '')));
        if (!in_array($kind, ['physical','digital','service','subscription','custom','bundle','variable'], true)) {
            $kind = $type === 'digital' ? 'digital' : ($type === 'bundle' ? 'bundle' : ($type === 'variable' ? 'variable' : 'physical'));
        }
        // The deployed CHECK constraint supports four catalogue structures. The
        // merchant-facing "kind" covers services, subscriptions and custom work
        // without a production DDL change; digital kinds become digital products.
        if (in_array($kind, ['digital','service','subscription'], true)) { $type = 'digital'; }
        elseif ($kind === 'bundle') { $type = 'bundle'; }
        elseif ($kind === 'variable') { $type = 'variable'; }
        else { $type = 'simple'; }
        $stock = max(0, (int)($in['stock'] ?? 0));
        $weight = max(0, (int)($in['weight_grams'] ?? 0));
        if ($type === 'digital') { $stock = $stock > 0 ? $stock : 999999; $weight = 0; }
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
            'stock'=>$stock,
            'low_stock_threshold'=>max(0, (int)($in['low_stock_threshold'] ?? 5)),
            'weight_grams'=>$weight,
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
        // Generic product kinds, custom order fields, digital delivery, bundle
        // contents and per-product specifications live in the existing dynamic
        // attribute tables. Missing fields are left untouched for bulk updates.
        $metaInput = $in;
        $metaInput['product_kind'] = $kind;
        foreach (self::SYSTEM_META_FIELDS as $slug => $field) {
            if (!array_key_exists($field, $metaInput)) { continue; }
            self::saveSystemMeta($id, $slug, (string)$metaInput[$field]);
        }
        if (array_key_exists('spec', $in)) { self::saveProductSpecs($id, $in['spec']); }
        // Simple "S, M, L, XL" sizes become real variant rows, so the storefront
        // can show a size chooser and the order keeps the chosen size.
        if (array_key_exists('sizes', $in)) { self::syncSizeVariants($id, (string)$in['sizes']); }
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
        foreach (Database::all('SELECT * FROM product_attribute_values WHERE product_id=:p', ['p'=>$id]) as $row) {
            Database::insert('product_attribute_values', ['product_id'=>$newId,'attribute_id'=>$row['attribute_id'],
                'value_id'=>$row['value_id'],'value_text'=>$row['value_text']]);
        }
        $variantMap = [];
        foreach (array_slice(Database::all('SELECT * FROM product_variants WHERE product_id=:p ORDER BY created_at ASC', ['p'=>$id]), 0, 100) as $v) {
            $newVariantId = Helpers::uuid(); $variantMap[(string)$v['id']] = $newVariantId;
            Database::insert('product_variants', ['id'=>$newVariantId,'product_id'=>$newId,
                'sku'=>(string)$v['sku'] . '-C' . substr(Helpers::token(3),0,5),'barcode'=>$v['barcode'],
                'name'=>$v['name'],'options'=>$v['options'],'price_cents'=>$v['price_cents'],
                'compare_cents'=>$v['compare_cents'],'stock'=>$v['stock'],'image_url'=>$v['image_url'],
                'is_active'=>$v['is_active'],'created_at'=>Helpers::now(),'updated_at'=>Helpers::now()]);
        }
        $defaultWarehouse = Database::first('SELECT id FROM warehouses WHERE is_default=1') ?: Database::first('SELECT id FROM warehouses LIMIT 1');
        if ($defaultWarehouse) {
            foreach (Database::all('SELECT * FROM inventory WHERE product_id=:p', ['p'=>$id]) as $inv) {
                Database::insert('inventory', ['id'=>Helpers::uuid(),'product_id'=>$newId,
                    'variant_id'=>$variantMap[(string)$inv['variant_id']] ?? '', 'warehouse_id'=>$defaultWarehouse['id'],
                    'quantity'=>$inv['quantity'],'available'=>$inv['available'],'updated_at'=>Helpers::now()]);
            }
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
                'product_type'=>(string)($in['product_type'] ?? 'simple'),
                'product_kind'=>(string)($in['product_kind'] ?? ($in['kind'] ?? 'physical')),
                'short_description'=>(string)($in['short_description'] ?? ''),
                'full_description'=>(string)($in['full_description'] ?? ''),
                'weight_grams'=>(string)($in['weight_grams'] ?? '0'),
                'dimensions'=>(string)($in['dimensions'] ?? ''), 'warranty'=>(string)($in['warranty'] ?? ''),
                'tax_rate'=>(string)($in['tax_rate'] ?? '0'),
                'digital_file_url'=>Helpers::safeMediaUrl((string)($in['digital_file_url'] ?? '')),
                'custom_options'=>(string)($in['custom_options'] ?? ''),
                'bundle_items'=>(string)($in['bundle_items'] ?? ''),
                'service_details'=>(string)($in['service_details'] ?? ''),
            ]);
            $n++;
        }
        fclose($h);
        return $n;
    }
}
