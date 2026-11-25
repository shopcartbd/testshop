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
 * Uploads go to Supabase Storage via the server-side secret key. Random names,
 * MIME allowlist, content inspection and size limits are enforced in Security.
 * In local mode a non-executable storage/uploads directory is used instead.
 */
final class UploadService
{
    public static function configured(): bool
    {
        return (string)Config::get('supabase.url') !== '' && (string)Config::get('supabase.secret') !== '';
    }

    public static function store(array $file, bool $private = false, bool $allowPdf = false): string
    {
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
            throw new AppException('Storage is not configured (SUPABASE_URL / SUPABASE_SECRET_KEY).', 503);
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
