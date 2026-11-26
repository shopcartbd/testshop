<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
use AllStore\Core\Response;

/**
 * Admin-only download of the exact product image attached to an order line.
 * Local media is read from a fixed allowlist; remote HTTPS media is fetched
 * with a pinned, public DNS result, no redirects, a size cap and an image MIME
 * check. A labelled SVG is generated when no safe image is available.
 */
final class OrderMediaService
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public static function downloadOrderItem(string $orderId, string $itemId, string $orderNumber): never
    {
        Response::noCache();
        $item = Database::first('SELECT * FROM order_items WHERE id=:i AND order_id=:o', ['i'=>$itemId,'o'=>$orderId]);
        if (!$item) { http_response_code(404); exit('Order item not found.'); }

        $url = Helpers::safeMediaUrl((string)($item['image_url'] ?? ''));
        if ($url === '') {
            $url = Helpers::safeMediaUrl((string)Database::value('SELECT url FROM product_images WHERE product_id=:p ORDER BY is_primary DESC, sort_order ASC LIMIT 1', ['p'=>$item['product_id']]));
        }
        $filename = self::filename($orderNumber, (string)($item['sku'] ?? ''), (string)($item['name'] ?? 'item'));
        Logger::audit('admin.order.image_download', 'order_items', $itemId, ['order_number'=>$orderNumber]);

        if ($url === '') { self::placeholder($item, $orderNumber, $filename); }
        if (str_starts_with($url, 'supabase-private:')) {
            $signed = UploadService::url($url);
            $url = Helpers::safeMediaUrl($signed);
            if ($url === '') { self::placeholder($item, $orderNumber, $filename); }
        }
        if (str_starts_with($url, '/')) { self::local($url, $item, $orderNumber, $filename); }
        self::remote($url, $item, $orderNumber, $filename);
    }

    private static function local(string $url, array $item, string $orderNumber, string $baseName): never
    {
        $root = dirname(__DIR__, 2);
        $path = (string)parse_url($url, PHP_URL_PATH);
        $real = realpath($root . $path);
        $assets = realpath($root . '/assets');
        $uploads = realpath($root . '/storage/uploads');
        $inside = $real !== false && (
            ($assets !== false && str_starts_with($real, $assets . DIRECTORY_SEPARATOR))
            || ($uploads !== false && str_starts_with($real, $uploads . DIRECTORY_SEPARATOR))
        );
        if (!$inside || !is_file($real) || filesize($real) > self::MAX_BYTES) { self::placeholder($item, $orderNumber, $baseName); }
        $mime = (string)(mime_content_type($real) ?: '');
        if (!str_starts_with(strtolower($mime), 'image/')) { self::placeholder($item, $orderNumber, $baseName); }
        $bytes = (string)file_get_contents($real);
        self::emit($bytes, $mime, $baseName . '.' . self::extension($mime));
    }

    private static function remote(string $url, array $item, string $orderNumber, string $baseName): never
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $ips = $host !== '' ? gethostbynamel($host) : false;
        if (!$ips) { self::placeholder($item, $orderNumber, $baseName); }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                self::placeholder($item, $orderNumber, $baseName);
            }
        }
        if (!function_exists('curl_init')) { self::placeholder($item, $orderNumber, $baseName); }
        $body = ''; $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ips[0]],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/svg+xml'],
            CURLOPT_USERAGENT => 'AllStore/1.0 order-image-download',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        curl_close($ch);
        $mime = explode(';', $mime)[0];
        if ($status !== 200 || $tooLarge || $body === '' || !str_starts_with($mime, 'image/')) {
            self::placeholder($item, $orderNumber, $baseName);
        }
        self::emit($body, $mime, $baseName . '.' . self::extension($mime));
    }

    private static function placeholder(array $item, string $orderNumber, string $baseName): never
    {
        $xml = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = $xml(Helpers::truncate((string)($item['name'] ?? 'Ordered item'), 52));
        $sku = $xml(mb_substr((string)($item['sku'] ?? ''), 0, 40));
        $qty = (int)($item['quantity'] ?? 1);
        $number = $xml($orderNumber);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="600" viewBox="0 0 900 600">'
            . '<rect width="900" height="600" fill="#f5f3ef"/>'
            . '<rect x="32" y="32" width="836" height="536" rx="28" fill="#ffffff" stroke="#d8d2c8" stroke-width="3"/>'
            . '<text x="72" y="130" font-family="Arial,sans-serif" font-size="30" fill="#6b6256">AllStore ordered item</text>'
            . '<text x="72" y="220" font-family="Arial,sans-serif" font-size="44" font-weight="700" fill="#171717">' . $name . '</text>'
            . '<text x="72" y="290" font-family="Arial,sans-serif" font-size="27" fill="#5f574d">SKU: ' . $sku . ' · Qty: ' . $qty . '</text>'
            . '<text x="72" y="360" font-family="Arial,sans-serif" font-size="27" fill="#5f574d">Order: ' . $number . '</text>'
            . '<text x="72" y="470" font-family="Arial,sans-serif" font-size="22" fill="#8a8175">Original product image is unavailable; this record preserves the ordered item identity.</text>'
            . '</svg>';
        self::emit($svg, 'image/svg+xml', $baseName . '.svg');
    }

    private static function emit(string $bytes, string $mime, string $filename): never
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    private static function filename(string $number, string $sku, string $name): string
    {
        $base = $number . '-' . ($sku !== '' ? $sku . '-' : '') . Helpers::slugify($name);
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $base) ?? 'order-item';
        return trim(substr($base, 0, 110), '-') ?: 'order-item';
    }

    private static function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg','image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'image/avif' => 'avif',
            default => 'img',
        };
    }
}
