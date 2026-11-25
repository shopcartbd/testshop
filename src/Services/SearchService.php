<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Auth;
use AllStore\Core\Request;
use AllStore\Core\Security;
use AllStore\Models\CatalogRepository;

final class SearchService
{
    public static function search(array $filters): array
    {
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            if (!Security::rateLimit('search:' . Request::ip(), 60, 60)) {
                throw new \AllStore\Core\AppException('Too many searches. Please slow down.', 429);
            }
        }
        $result = CatalogRepository::listing($filters);
        if ($q !== '' && $result['total'] === 0 && mb_strlen($q) >= 3) {
            $ids = self::typoMatches($q);
            if ($ids !== []) {
                $alt = $filters; unset($alt['q']);
                $alt['product_ids'] = $ids;
                $alt['sort'] = 'recommended';
                $fallback = CatalogRepository::listing($alt);
                if ($fallback['total'] > 0) {
                    $result = $fallback;
                    $result['did_you_mean'] = true;
                }
            }
        }
        if ($q !== '') {
            try {
                Database::insert('search_logs', ['id'=>Helpers::uuid(),'query'=>mb_substr($q,0,120),
                    'results_count'=>$result['total'],'user_id'=>Auth::id(),'ip_address'=>Request::ip(),'created_at'=>Helpers::now()]);
                Database::exec("INSERT INTO popular_searches(query,hits,updated_at) VALUES(:q,1,:t)
                    ON CONFLICT(query) DO UPDATE SET hits = hits + 1, updated_at=:t2",
                    ['q'=>mb_strtolower($q),'t'=>Helpers::now(),'t2'=>Helpers::now()]);
            } catch (\Throwable) {}
        }
        return $result;
    }

    /** Lightweight typo tolerance without a DB extension (portable). */
    private static function typoMatches(string $q): array
    {
        $needle = mb_strtolower($q);
        $rows = Database::all("SELECT id,name,sku FROM products WHERE status='active' AND deleted_at IS NULL ORDER BY sold_count DESC LIMIT 300");
        $scored = [];
        foreach ($rows as $r) {
            $name = mb_strtolower((string)$r['name']);
            $score = 0;
            similar_text($needle, $name, $score);
            $words = preg_split('/\s+/', $name) ?: [];
            foreach ($words as $w) {
                if (mb_strlen($w) > 3 && levenshtein($needle, mb_substr($w, 0, 20)) <= 2) { $score = max($score, 70.0); }
            }
            if (str_contains($name, $needle) || str_contains(mb_strtolower((string)$r['sku']), $needle)) { $score = 100.0; }
            if ($score >= 55) { $scored[] = ['id'=>$r['id'],'score'=>$score]; }
        }
        usort($scored, static fn($a,$b) => $b['score'] <=> $a['score']);
        return array_column(array_slice($scored, 0, 24), 'id');
    }

    public static function suggest(string $q): array
    {
        $q = trim(mb_substr($q, 0, 60));
        if ($q === '') { return ['products'=>[],'categories'=>[],'brands'=>[],'popular'=>self::popular(6),'recent'=>self::recent()]; }
        if (!Security::rateLimit('suggest:' . Request::ip(), 90, 60)) { return ['products'=>[],'categories'=>[],'brands'=>[],'popular'=>[],'recent'=>[]]; }
        $like = '%' . mb_strtolower($q) . '%';
        $products = Database::all("SELECT p.id,p.name,p.slug,p.price_cents,p.compare_cents,
            (SELECT pi.url FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_url
            FROM products p WHERE p.status='active' AND p.deleted_at IS NULL
            AND (LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q) ORDER BY p.sold_count DESC LIMIT 6", ['q'=>$like]);
        $categories = Database::all("SELECT name,slug FROM categories WHERE is_active=1 AND deleted_at IS NULL AND LOWER(name) LIKE :q ORDER BY sort_order LIMIT 4", ['q'=>$like]);
        $brands = Database::all("SELECT name,slug FROM brands WHERE is_active=1 AND LOWER(name) LIKE :q ORDER BY name LIMIT 3", ['q'=>$like]);
        self::remember($q);
        return ['products'=>$products,'categories'=>$categories,'brands'=>$brands,'popular'=>self::popular(4),'recent'=>self::recent()];
    }

    public static function popular(int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        return array_column(Database::all("SELECT query FROM popular_searches ORDER BY hits DESC LIMIT $limit"), 'query');
    }
    private static function remember(string $q): void
    {
        $r = (array)(SessionRecent::get());
        array_unshift($r, mb_substr($q, 0, 60));
        SessionRecent::set(array_values(array_unique(array_slice($r, 0, 6))));
    }
    public static function recent(): array { return (array)SessionRecent::get(); }
}

/** Tiny session-backed recent-search helper (no cookies written by JS). */
final class SessionRecent
{
    public static function get(): array { return (array)\AllStore\Core\Session::get('recent_searches', []); }
    public static function set(array $v): void { \AllStore\Core\Session::set('recent_searches', $v); }
}
