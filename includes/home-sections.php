<?php
declare(strict_types=1);
/** Renders admin-managed homepage sections. */
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Helpers;
use AllStore\Core\Config;
require_once __DIR__ . '/components.php';

function render_home_section(array $s): string
{
    $key = $s['key'];
    $title = $s['title'];
    $sub = $s['subtitle'];
    $d = $s['data'];
    $cfg = $s['config'];
    $out = '<section class="section section-sm" data-section="' . Helpers::e($key) . '"><div class="container">';
    switch ($key) {
        case 'hero_slider':
            $banners = $d['banners'] ?? [];
            if ($banners === []) { return ''; }
            $b = $banners[0];
            $out .= '<div class="hero">'
                . '<div class="hero-copy"><span class="eyebrow">' . Helpers::e((string)Config::get('app.name')) . ' selection</span>'
                . '<h1>' . Helpers::e($b['title']) . '</h1><p>' . Helpers::e($b['subtitle']) . '</p>'
                . '<div class="hero-actions"><a class="btn btn-primary btn-lg" href="' . Helpers::e(Helpers::url($b['link_url'] ?: '/shop')) . '">Shop the collection ' . icon('arrow-right',17) . '</a>'
                . '<a class="btn btn-outline btn-lg" href="/new-arrivals">New arrivals</a></div>'
                . '<div class="row" style="margin-top:18px;gap:18px;font-size:12px;color:var(--c-muted)"><span>' . icon('truck',16) . ' Free shipping over ' . Helpers::money((int)Helpers::setting('free_shipping_threshold_cents','7500')) . '</span><span>' . icon('rotate',16) . ' 30-day returns</span></div>'
                . '</div><div class="hero-media"><img src="' . Helpers::e($b['image_url']) . '" alt="' . Helpers::e($b['title']) . '" fetchpriority="high"></div>'
                . '<div class="hero-dots">' . implode('', array_map(static fn($i) => '<button type="button" class="' . ($i === 0 ? 'active' : '') . '" aria-label="Slide ' . ($i+1) . '"></button>', array_keys($banners))) . '</div></div>';
            break;
        case 'categories':
        case 'featured_categories':
            $out .= section_head($title ?: 'Shop by category', $sub, '/shop', 'All categories');
            $out .= '<div class="category-strip">' . implode('', array_map('category_tile', $d['categories'] ?? [])) . '</div>';
            break;
        case 'flash_sale':
            $out .= '<div class="flash-shell"><div class="section-head"><div><h2>' . Helpers::e($title ?: 'Flash sale') . '</h2><p>' . Helpers::e($sub) . '</p></div>'
                . '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap"><div class="countdown" data-countdown="' . Helpers::e($d['countdown'] ?? '') . '">'
                . '<div class="cd-box"><strong data-cd>00</strong><span>Days</span></div><div class="cd-box"><strong data-cd>00</strong><span>Hrs</span></div>'
                . '<div class="cd-box"><strong data-cd>00</strong><span>Min</span></div><div class="cd-box"><strong data-cd>00</strong><span>Sec</span></div></div>'
                . '<a class="section-link" style="color:#fff" href="/flash-sale">View all ' . icon('arrow-right',15) . '</a></div></div>'
                . product_grid($d['products'] ?? [], '4') . '</div>';
            break;
        case 'promo_banners':
            $banners = $d['banners'] ?? [];
            if ($banners === []) { return ''; }
            $out .= '<div class="promo-grid">';
            foreach ($banners as $i => $b) {
                $out .= '<a class="promo-card" href="' . Helpers::e(Helpers::url($b['link_url'] ?: '/deals')) . '"><img src="' . Helpers::e($b['image_url']) . '" alt="' . Helpers::e($b['title']) . '" loading="lazy"><span class="overlay"></span>'
                    . '<div class="content"><span class="eyebrow" style="color:#cfe6dc">' . Helpers::e($b['subtitle'] ?? '') . '</span><h3>' . Helpers::e($b['title']) . '</h3><p>' . Helpers::e($b['subtitle'] ?? '') . '</p><span class="btn btn-outline" style="border-color:#fff;color:#fff">Discover more</span></div></a>';
            }
            $out .= '</div>';
            break;
        case 'brands':
            $out .= section_head($title ?: 'Featured brands', $sub, '/brands', 'All brands');
            $out .= '<div class="brand-strip">' . implode('', array_map(static fn($b) => '<a class="brand-tile" href="' . Helpers::url('/brand/'.$b['slug']) . '">' . Helpers::e($b['name']) . '</a>', $d['brands'] ?? [])) . '</div>';
            break;
        case 'newsletter':
            // Newsletter is rendered in the shared footer to avoid duplication.
            return '';
        case 'recently_viewed':
            if (($d['products'] ?? []) === []) { return ''; }
            $out .= section_head($title ?: 'Recently viewed', $sub);
            $out .= product_grid($d['products'], '4');
            break;
        default:
            $links = ['best_sellers'=>'/best-sellers','new_arrivals'=>'/new-arrivals','trending'=>'/trending','deals'=>'/deals','recommended'=>'/shop','featured'=>'/shop','products'=>'/shop'];
            $out .= section_head($title ?: 'Selected for you', $sub, $links[$key] ?? '/shop');
            $out .= product_grid($d['products'] ?? [], '4');
            break;
    }
    return $out . '</div></section>';
}
