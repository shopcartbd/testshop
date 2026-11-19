<?php

declare(strict_types=1);

final class Catalog
{
    public static function productById(string $id): ?array
    {
        $statement = Database::connection()->prepare("SELECT id, name, base_price AS price, stock_quantity FROM app.products WHERE id = :id AND status = 'active' LIMIT 1");
        $statement->execute(['id' => $id]);
        $product = $statement->fetch();
        return $product ?: null;
    }

    public static function categories(): array
    {
        $sql = "SELECT id, name, slug, description, image_url FROM app.categories WHERE is_active = TRUE ORDER BY sort_order ASC, name ASC LIMIT 12";
        return Database::connection()->query($sql)->fetchAll();
    }

    public static function products(string $search = '', string $category = ''): array
    {
        $where = ["p.status = 'active'"];
        $params = [];
        if ($search !== '') {
            $where[] = '(p.name ILIKE :search_name OR p.sku ILIKE :search_sku OR p.short_description ILIKE :search_description)';
            $term = '%' . mb_substr($search, 0, 100) . '%';
            $params['search_name'] = $term;
            $params['search_sku'] = $term;
            $params['search_description'] = $term;
        }
        if ($category !== '') {
            $where[] = 'c.slug = :category';
            $params['category'] = $category;
        }
        $sql = 'SELECT p.id, p.name, p.slug, p.short_description, p.base_price AS price, p.compare_at_price, p.stock_quantity, p.is_featured, '
            . "COALESCE((SELECT pi.image_url FROM app.product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order LIMIT 1), '') AS image_url, "
            . 'c.name AS category_name, c.slug AS category_slug '
            . 'FROM app.products p LEFT JOIN app.categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.is_featured DESC, p.created_at DESC LIMIT 24';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function product(string $slug): ?array
    {
        $statement = Database::connection()->prepare(
            "SELECT p.*, p.base_price AS price, c.name AS category_name, c.slug AS category_slug, b.name AS brand_name "
            . 'FROM app.products p LEFT JOIN app.categories c ON c.id = p.category_id LEFT JOIN app.brands b ON b.id = p.brand_id '
            . "WHERE p.slug = :slug AND p.status = 'active' LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $product = $statement->fetch();
        if (!$product) {
            return null;
        }
        $images = Database::connection()->prepare('SELECT image_url, alt_text FROM app.product_images WHERE product_id = :id ORDER BY sort_order');
        $images->execute(['id' => $product['id']]);
        $product['images'] = $images->fetchAll();
        return $product;
    }

    public static function cartProducts(array $cart): array
    {
        $ids = array_values(array_filter(array_map('strval', array_keys($cart)), static fn (string $id): bool => (bool) preg_match('/^[a-f0-9-]{36}$/i', $id)));
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = Database::connection()->prepare(
            "SELECT p.id, p.name, p.slug, p.base_price AS price, p.stock_quantity, "
            . "COALESCE((SELECT pi.image_url FROM app.product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order LIMIT 1), '') AS image_url "
            . "FROM app.products p WHERE p.id IN ($marks) AND p.status = 'active'"
        );
        $statement->execute($ids);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['quantity'] = min(max(1, (int) ($cart[(string) $row['id']] ?? 1)), max(1, (int) $row['stock_quantity']));
            $row['line_total'] = (float) $row['price'] * $row['quantity'];
        }
        unset($row);
        return $rows;
    }
}
