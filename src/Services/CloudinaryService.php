<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Config;
use AllStore\Core\Helpers;
use AllStore\Core\Http;
use AllStore\Core\Logger;
use AllStore\Core\Security;
use AllStore\Core\AppException;

/**
 * Server-side Cloudinary image uploader.
 *
 * Credentials are read from the environment only (Vercel project environment
 * variables): CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET.
 * They are never rendered into HTML, never returned by the JSON API and never
 * written to the logs; the browser only ever receives the resulting secure_url.
 *
 * Uploads are signed server-side (timestamp + sha1 signature), so the API key
 * alone cannot be used from the client to upload on the account's behalf.
 */
final class CloudinaryService
{
    public const ENV_KEYS = ['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'];

    public static function cloudName(): string { return trim((string)Config::get('cloudinary.cloud', '')); }
    public static function apiKey(): string { return trim((string)Config::get('cloudinary.key', '')); }
    private static function apiSecret(): string { return trim((string)Config::get('cloudinary.secret', '')); }
    public static function folder(): string
    {
        $folder = trim((string)Config::get('cloudinary.folder', 'allstore/products'), '/');
        $folder = preg_replace('#[^A-Za-z0-9_\-/]#', '', $folder) ?? '';
        return $folder === '' ? 'allstore/products' : $folder;
    }
    private static function base(): string
    {
        $base = rtrim((string)Config::get('cloudinary.base', 'https://api.cloudinary.com/v1_1'), '/');
        return $base === '' ? 'https://api.cloudinary.com/v1_1' : $base;
    }

    /** True when all three credentials are present in the server environment. */
    public static function configured(): bool
    {
        return self::cloudName() !== '' && self::apiKey() !== '' && self::apiSecret() !== '';
    }

    /** Names of the environment variables that are still missing (never values). */
    public static function missingEnv(): array
    {
        $missing = [];
        foreach ([
            'CLOUDINARY_CLOUD_NAME' => self::cloudName(),
            'CLOUDINARY_API_KEY' => self::apiKey(),
            'CLOUDINARY_API_SECRET' => self::apiSecret(),
        ] as $name => $value) {
            if ($value === '') { $missing[] = $name; }
        }
        return $missing;
    }

    /** Non-secret status block for admin screens. */
    public static function status(): array
    {
        $configured = self::configured();
        return [
            'provider' => 'cloudinary',
            'configured' => $configured,
            'cloud_name' => $configured ? self::cloudName() : '',
            'folder' => self::folder(),
            'missing' => self::missingEnv(),
            'label' => $configured
                ? 'Cloudinary · ' . self::cloudName() . ' · /' . self::folder()
                : 'Cloudinary is not configured',
        ];
    }

    /**
     * Cloudinary request signature: sha1 over the alphabetically sorted
     * parameters (excluding file/api_key/cloud_name/resource_type) + secret.
     */
    public static function signature(array $params, string $secret): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if (in_array($key, ['file', 'api_key', 'cloud_name', 'resource_type', 'signature'], true)) { continue; }
            if ($value === null || $value === '') { continue; }
            $clean[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
        ksort($clean, SORT_STRING);
        $pairs = [];
        foreach ($clean as $key => $value) { $pairs[] = $key . '=' . $value; }
        return sha1(implode('&', $pairs) . $secret);
    }

    /**
     * Upload one validated image and return the Cloudinary metadata.
     *
     * @return array{secure_url:string,public_id:string,width:int,height:int,format:string,bytes:int,provider:string}
     */
    public static function uploadImage(array $file, array $extra = []): array
    {
        if (!self::configured()) {
            $missing = implode(', ', self::missingEnv());
            throw new AppException('Cloudinary is not configured. Add ' . $missing . ' to the server environment (Vercel → Settings → Environment Variables).', 503);
        }
        // Same allowlist/size checks as every other upload path (JPEG/PNG/WebP/GIF, 5 MB).
        [, $mime] = Security::validateUpload($file);
        $tmp = (string)($file['tmp_name'] ?? '');
        $binary = $tmp !== '' ? (string)@file_get_contents($tmp) : '';
        if ($binary === '') { throw new AppException('The image could not be read. Please try again.', 400); }

        $timestamp = time();
        $params = array_merge([
            'folder' => self::folder(),
            'public_id' => 'img-' . gmdate('Ymd') . '-' . Helpers::token(10),
            'timestamp' => $timestamp,
        ], $extra);
        $signature = self::signature($params, self::apiSecret());
        $fields = $params + [
            'api_key' => self::apiKey(),
            'signature' => $signature,
            'file' => 'data:' . $mime . ';base64,' . base64_encode($binary),
        ];
        $endpoint = self::base() . '/' . rawurlencode(self::cloudName()) . '/image/upload';
        [$status, $body, $err] = Http::request('POST', $endpoint, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query($fields), 30);

        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $apiMessage = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
            Logger::log('error', 'cloudinary.upload_failed', [
                'status' => $status,
                'detail' => mb_substr(Security::redact($body . ' ' . $err), 0, 180),
            ]);
            throw new AppException(self::failureMessage($status, $apiMessage), 502);
        }
        $url = (string)($data['secure_url'] ?? '');
        if (preg_match('#^https://#i', $url) !== 1) {
            Logger::log('error', 'cloudinary.bad_response', ['keys' => implode(',', array_slice(array_keys($data), 0, 8))]);
            throw new AppException('Cloudinary did not return a secure URL. Please try again.', 502);
        }
        return [
            'secure_url' => $url,
            'public_id' => (string)($data['public_id'] ?? ''),
            'width' => (int)($data['width'] ?? 0),
            'height' => (int)($data['height'] ?? 0),
            'format' => (string)($data['format'] ?? ''),
            'bytes' => (int)($data['bytes'] ?? 0),
            'provider' => 'cloudinary',
        ];
    }

    /** Human, credential-free error text for the admin flash message. */
    private static function failureMessage(int $status, string $apiMessage = ''): string
    {
        // Cloudinary answers "Invalid cloud_name x" / "cloud_name mismatch" when
        // the cloud name does not belong to the API key pair; say so plainly
        // instead of blaming the key.
        if ($apiMessage !== '' && preg_match('/cloud[ _]?name/i', $apiMessage) === 1) {
            return 'CLOUDINARY_CLOUD_NAME does not match this API key. Copy the exact Cloud name from the Cloudinary dashboard.';
        }
        if ($apiMessage !== '' && preg_match('/(invalid|unknown)\s+(api[ _]?key|signature)|signature.*(invalid|mismatch)/i', $apiMessage) === 1) {
            return 'Cloudinary rejected the credentials. Check CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET in Vercel.';
        }
        if ($status === 401 || $status === 403) {
            return 'Cloudinary rejected the credentials. Check CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET in Vercel.';
        }
        if ($status === 400) {
            return 'Cloudinary rejected the file. Use a JPEG, PNG, WebP or GIF image under 5 MB.';
        }
        if ($status === 420 || $status === 429) {
            return 'Cloudinary rate limit reached. Please wait a moment and try again.';
        }
        if ($status === 0) {
            return 'Cloudinary could not be reached from the server. Please try again.';
        }
        return 'Cloudinary upload failed (HTTP ' . $status . '). Please try again.';
    }

    /**
     * Delete an uploaded asset (used when an admin removes an image that this
     * store uploaded). Never throws: a missing asset is not an error.
     */
    public static function destroy(string $publicId): bool
    {
        if (!self::configured() || $publicId === '') { return false; }
        $timestamp = time();
        $params = ['public_id' => $publicId, 'timestamp' => $timestamp];
        $fields = $params + [
            'api_key' => self::apiKey(),
            'signature' => self::signature($params, self::apiSecret()),
        ];
        [$status] = Http::request('POST',
            self::base() . '/' . rawurlencode(self::cloudName()) . '/image/destroy',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query($fields), 15);
        return $status >= 200 && $status < 300;
    }

    /** Extract the public_id from a Cloudinary secure_url, or '' when unknown. */
    public static function publicIdFromUrl(string $url): string
    {
        if (preg_match('#^https://res\.cloudinary\.com/[^/]+/image/upload/(?:v\d+/)?(.+?)(?:\.[a-z0-9]+)?$#i', $url, $m) !== 1) {
            return '';
        }
        return (string)$m[1];
    }
}
