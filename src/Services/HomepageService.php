<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Models\CatalogRepository;
use AllStore\Models\CmsRepository;
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Helpers;

/** Turns admin-managed homepage_sections rows into render-ready data. */
final class HomepageService
{
    public static function resolve(): array
    {
        $out = [];
        foreach (CmsRepository::sections(true) as $s) {
            $cfg = (array)Helpers::decode((string)$s['config'], []);
            $key = (string)$s['section_key'];
            $limit = max(2, min(24, (int)($cfg['limit'] ?? 8)));
            $data = ['products'=>[], 'categories'=>[], 'brands'=>[], 'banners'=>[], 'countdown'=>null];
            switch ($key) {
                case 'hero_slider':
                    $data['banners'] = CmsRepository::banners('hero');
                    break;
                case 'categories':
                case 'featured_categories':
                    $data['categories'] = array_slice(CatalogRepository::categories(), 0, max(3, min(12, (int)($cfg['limit'] ?? 8))));
                    break;
                case 'flash_sale':
                    $data['products'] = CmsRepository::flashProducts($limit);
                    $sale = CmsRepository::flashSale();
                    $data['countdown'] = $sale['ends_at'] ?? null;
                    break;
                case 'promo_banners':
                    $data['banners'] = CmsRepository::banners('promo');
                    break;
                case 'brands':
                    $data['brands'] = array_slice(CatalogRepository::brands(), 0, 12);
                    break;
                case 'recently_viewed':
                    $userKey = Auth::id() ?: Session::id();
                    $data['products'] = CatalogRepository::recentProducts($userKey, $limit);
                    break;
                default:
                    $f = ['per_page'=>$limit, 'sort'=>(string)($cfg['sort'] ?? 'recommended')];
                    if (!empty($cfg['product_ids']) && is_array($cfg['product_ids'])) { $f['product_ids'] = $cfg['product_ids']; }
                    if (!empty($cfg['category'])) { $f['category'] = (string)$cfg['category']; }
                    if ($key === 'best_sellers') { $f['best_seller'] = 1; }
                    if ($key === 'new_arrivals') { $f['new_arrival'] = 1; $f['sort'] = 'newest'; }
                    if ($key === 'trending') { $f['trending'] = 1; }
                    if ($key === 'deals') { $f['on_sale'] = 1; $f['sort'] = 'discount'; }
                    if ($key === 'featured') { $f['featured'] = 1; }
                    $data['products'] = CatalogRepository::listing($f)['items'];
                    break;
            }
            $out[] = ['row'=>$s, 'key'=>$key, 'title'=>(string)$s['title'], 'subtitle'=>(string)$s['subtitle'],
                'config'=>$cfg, 'data'=>$data];
        }
        return $out;
    }
}
