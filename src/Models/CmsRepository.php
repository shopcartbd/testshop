<?php
declare(strict_types=1);
namespace AllStore\Models;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\AppException;

final class CmsRepository
{
    public static function page(string $slug): ?array
    {
        return Database::first('SELECT * FROM pages WHERE slug=:s AND is_published=1', ['s'=>$slug]);
    }
    public static function allPages(): array { return Database::all('SELECT * FROM pages ORDER BY title'); }
    public static function savePage(string $slug, array $in): void
    {
        $data = ['title'=>trim((string)($in['title'] ?? $slug)), 'body'=>(string)($in['body'] ?? ''),
            'seo_title'=>(string)($in['seo_title'] ?? ''), 'seo_description'=>(string)($in['seo_description'] ?? ''),
            'is_published'=>(int)($in['is_published'] ?? 1), 'updated_at'=>Helpers::now()];
        if (Database::first('SELECT id FROM pages WHERE slug=:s', ['s'=>$slug])) {
            Database::update('pages', $data, 'slug=:s', ['s'=>$slug]);
        } else {
            $data['id'] = Helpers::uuid(); $data['slug'] = $slug; $data['created_at'] = Helpers::now();
            Database::insert('pages', $data);
        }
    }
    public static function blogs(bool $published = true): array
    {
        $w = $published ? 'WHERE is_published=1' : '';
        return Database::all("SELECT * FROM blogs $w ORDER BY COALESCE(published_at, created_at) DESC");
    }
    public static function blog(string $slug): ?array
    {
        return Database::first('SELECT * FROM blogs WHERE slug=:s AND is_published=1', ['s'=>$slug]);
    }
    public static function faqs(): array
    {
        return Database::all('SELECT * FROM faqs WHERE is_published=1 ORDER BY sort_order, id');
    }
    public static function banners(?string $placement = null, bool $activeOnly = true): array
    {
        $w = [];
        $p = [];
        if ($activeOnly) {
            $w[] = 'is_active=1';
            $w[] = "(starts_at IS NULL OR starts_at <= :now)";
            $w[] = "(expires_at IS NULL OR expires_at > :now2)";
            $p['now'] = Helpers::now(); $p['now2'] = Helpers::now();
        }
        if ($placement) { $w[] = 'placement=:pl'; $p['pl'] = $placement; }
        return Database::all('SELECT * FROM banners ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY sort_order, created_at', $p);
    }
    public static function sections(bool $enabledOnly = true): array
    {
        $w = $enabledOnly ? "WHERE is_enabled=1 AND (starts_at IS NULL OR starts_at <= :now) AND (expires_at IS NULL OR expires_at > :now2)" : '';
        $p = $enabledOnly ? ['now'=>Helpers::now(),'now2'=>Helpers::now()] : [];
        return Database::all("SELECT * FROM homepage_sections $w ORDER BY sort_order, created_at", $p);
    }
    public static function saveSection(array $in, ?string $id = null): string
    {
        $key = (string)($in['section_key'] ?? 'products');
        if (!preg_match('/^[a-z_]{2,40}$/', $key)) { throw new AppException('Invalid section type.', 422); }
        $data = ['section_key'=>$key, 'title'=>(string)($in['title'] ?? ''), 'subtitle'=>(string)($in['subtitle'] ?? ''),
            'config'=>(string)($in['config'] ?? '{}'), 'sort_order'=>(int)($in['sort_order'] ?? 0),
            'is_enabled'=>(int)($in['is_enabled'] ?? 1), 'starts_at'=>(string)($in['starts_at'] ?? '') ?: null,
            'expires_at'=>(string)($in['expires_at'] ?? '') ?: null, 'updated_at'=>Helpers::now()];
        $cfg = Helpers::decode($data['config'], []);
        $data['config'] = Helpers::json(is_array($cfg) ? $cfg : []);
        if ($id) { Database::update('homepage_sections', $data, 'id=:id', ['id'=>$id]); return $id; }
        $data['id'] = $id = Helpers::uuid(); $data['created_at'] = Helpers::now();
        Database::insert('homepage_sections', $data);
        return $id;
    }
    public static function flashSale(): ?array
    {
        return Database::first('SELECT * FROM flash_sales WHERE is_active=1 AND (ends_at IS NULL OR ends_at > :n) ORDER BY created_at DESC LIMIT 1', ['n'=>Helpers::now()]);
    }
    public static function flashProducts(int $limit = 12): array
    {
        $limit = max(1, min(48, $limit));
        return Database::all("SELECT p.*, fsi.sale_price_cents, fsi.discount_percent,
            (SELECT pi.url FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_url
            FROM flash_sale_items fsi JOIN flash_sales fs ON fs.id=fsi.flash_sale_id JOIN products p ON p.id=fsi.product_id
            WHERE fs.is_active=1 AND p.status='active' AND p.deleted_at IS NULL
            ORDER BY fsi.sort_order LIMIT $limit");
    }
}
