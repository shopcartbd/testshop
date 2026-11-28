<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Config;
use AllStore\Core\Http;
use AllStore\Core\Helpers;
use AllStore\Core\Security;
use AllStore\Core\Logger;
use AllStore\Core\AppException;

/**
 * Media routing:
 *   1. Public images (products, galleries, categories, brands, banners, logo,
 *      favicon) go to Cloudinary when CLOUDINARY_* is set in the server
 *      environment. Only the returned secure_url is stored in the database
 *      (Supabase Postgres), so every page loads the image from Cloudinary.
 *   2. Private files (digital downloads, support attachments) stay on Supabase
 *      Storage behind the server-side secret key: those must never be public.
 *   3. Without Cloudinary, public images fall back to Supabase Storage, and in
 *      local development to a non-executable storage/uploads directory.
 * Random names, MIME allowlist, content inspection and size limits are enforced
 * in Security for every path. No credential is ever exposed to the browser.
 */
final class UploadService
{
    public static function configured(): bool
    {
        return (string)Config::get('supabase.url') !== '' && (string)Config::get('supabase.secret') !== '';
    }

    /** Where new public images will go right now. */
    public static function provider(): string
    {
        if (CloudinaryService::configured()) { return 'cloudinary'; }
        return self::configured() ? 'supabase' : (Config::get('app.env') === 'production' ? 'none' : 'local');
    }

    /** Credential-free status block for admin screens. */
    public static function status(): array
    {
        $provider = self::provider();
        return [
            'provider' => $provider,
            'cloudinary' => CloudinaryService::status(),
            'label' => match ($provider) {
                'cloudinary' => 'Cloudinary · ' . CloudinaryService::cloudName() . ' · /' . CloudinaryService::folder(),
                'supabase' => 'Supabase Storage · ' . (string)Config::get('supabase.public_bucket'),
                'local' => 'Local disk (development only)',
                default => 'Not configured',
            },
            'ready' => $provider !== 'none',
        ];
    }

    public static function store(array $file, bool $private = false, bool $allowPdf = false): string
    {
        // Public imagery → Cloudinary; only the secure URL is persisted by callers.
        if (!$private && !$allowPdf && CloudinaryService::configured()) {
            return CloudinaryService::uploadImage($file)['secure_url'];
        }
        [$name, $mime] = Security::validateUpload($file, $allowPdf);
        $bucket = $private ? (string)Config::get('supabase.private_bucket') : (string)Config::get('supabase.public_bucket');
        $tmp = (string)$file['tmp_name'];
        if (self::configured()) {
            $endpoint = (string)Config::get('supabase.url') . '/storage/v1/object/' . rawurlencode($bucket) . '/' . $name;
            $secret = (string)Config::get('supabase.secret');
            [$status, $body, $err] = Http::request('POST', $endpoint, [
                'Authorization: Bearer ' . $secret,
                'apikey: ' . $secret,
                'Content-Type: ' . $mime,
                'x-upsert: false',
            ], (string)file_get_contents($tmp), 20);
            if ($status < 200 || $status >= 300) {
                Logger::log('error', 'storage.upload_failed', ['status'=>$status, 'detail'=>mb_substr(Security::redact($body . ' ' . $err),0,180)]);
                throw new AppException('The image could not be stored. Please try again.', 502);
            }
            return $private
                ? 'supabase-private:' . $bucket . '/' . $name
                : (string)Config::get('supabase.url') . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . $name;
        }
        if (Config::get('app.env') === 'production') {
            throw new AppException('Image storage is not configured. Add CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET to the server environment (or SUPABASE_URL / SUPABASE_SECRET_KEY as a fallback).', 503);
        }
        // Local development fallback: never executable, no user-supplied filename.
        $dir = dirname(__DIR__, 2) . '/storage/uploads/' . dirname($name);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $dest = dirname(__DIR__, 2) . '/storage/uploads/' . $name;
        if (!move_uploaded_file($tmp, $dest)) { throw new AppException('The file could not be saved.', 500); }
        @chmod($dest, 0644);
        return '/storage/uploads/' . $name;
    }

    /** Resolve a stored value to a displayable (signed, if private) URL. */
    public static function url(string $stored, int $expiresIn = 600): string
    {
        if (!str_starts_with($stored, 'supabase-private:')) { return $stored; }
        if (!self::configured()) { return ''; }
        $path = substr($stored, strlen('supabase-private:'));
        $secret = (string)Config::get('supabase.secret');
        [$status, $body] = Http::request('POST',
            (string)Config::get('supabase.url') . '/storage/v1/object/sign/' . $path,
            ['Authorization: Bearer ' . $secret, 'apikey: ' . $secret, 'Content-Type: application/json'],
            Helpers::json(['expiresIn' => max(60, min(3600, $expiresIn))]));
        $data = json_decode($body, true);
        if ($status >= 200 && $status < 300 && is_array($data) && !empty($data['signedURL'])) {
            return (string)Config::get('supabase.url') . '/storage/v1' . ltrim((string)$data['signedURL'], '/');
        }
        Logger::log('error', 'storage.sign_failed', ['status'=>$status]);
        return '';
    }
}
