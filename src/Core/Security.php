<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Security
{
    public static function sendHeaders(): void
    {
        if (headers_sent()) { return; }
        $prod = Config::get('app.env') === 'production';
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self), usb=()');
        if ($prod && Request::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        // No unsafe-eval, no wildcard sources, no third-party scripts.
        // style-src allows inline styles only for dynamic widths/progress bars;
        // all JavaScript is loaded from same-origin files (no inline scripts).
        $csp = implode('; ', [
            "default-src 'self'", "base-uri 'self'", "object-src 'none'",
            "frame-ancestors 'none'", "form-action 'self'",
            "img-src 'self' data: https:", "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'", "script-src 'self'",
            "connect-src 'self'", "worker-src 'none'", "manifest-src 'self'",
        ]);
        header('Content-Security-Policy: ' . $csp);
    }

    public static function e(mixed $v): string { return Helpers::e($v); }

    /** Plain text only. Rich HTML from users/admins is intentionally NOT rendered. */
    public static function plain(string $s, int $max = 20000): string
    {
        $s = str_replace(["\0", "\r"], '', $s);
        return mb_substr(trim($s), 0, $max);
    }

    public static function equals(string $a, string $b): bool { return hash_equals($a, $b); }

    /** Server-side fixed-window limiter backed by the database (works across lambdas). */
    public static function rateLimit(string $bucket, int $max, int $windowSeconds): bool
    {
        $bucket = substr(preg_replace('/[^a-zA-Z0-9:._\-]/', '', $bucket), 0, 180);
        $now = time();
        try {
            $row = Database::first('SELECT hits, window_start FROM rate_limits WHERE bucket=:b', ['b' => $bucket]);
            if (!$row || (strtotime((string)$row['window_start']) ?: 0) <= $now - $windowSeconds) {
                Database::exec("INSERT INTO rate_limits(bucket,hits,window_start,updated_at) VALUES(:b,1,:w,:t) ON CONFLICT(bucket) DO UPDATE SET hits=1, window_start=:w2, updated_at=:t2",
                    ['b'=>$bucket,'w'=>gmdate('c',$now),'t'=>Helpers::now(),'w2'=>gmdate('c',$now),'t2'=>Helpers::now()]);
                $hits = 1;
            } else {
                Database::exec('UPDATE rate_limits SET hits=hits+1, updated_at=:t WHERE bucket=:b', ['t'=>Helpers::now(),'b'=>$bucket]);
                $hits = (int)$row['hits'] + 1;
            }
            if ($hits > $max) { Logger::log('warning', 'rate.limit.exceeded', ['bucket'=>$bucket]); return false; }
            return true;
        } catch (\Throwable $e) {
            // Fail closed for sensitive endpoints: never silently disable protection.
            Logger::log('error', 'rate.limit.backend_failure', ['bucket'=>$bucket]);
            return false;
        }
    }

    /** Validate an uploaded file. Returns [safeRelativeName, mime] or throws. */
    public static function validateUpload(array $file, bool $allowPdf = false): array
    {
        if (!isset($file['error']) || is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new AppException('The file could not be uploaded.', 400);
        }
        $max = 5 * 1024 * 1024;
        if (($file['size'] ?? 0) < 1 || ($file['size'] ?? 0) > $max) {
            throw new AppException('File must be between 1 byte and 5 MB.', 400);
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) { throw new AppException('Invalid upload source.', 400); }
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        if ($allowPdf) { $allowed['application/pdf'] = 'pdf'; }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        if (!isset($allowed[$mime])) { throw new AppException('Only JPEG, PNG, WebP or GIF images are allowed.', 400); }
        if ($mime !== 'application/pdf') {
            $info = @getimagesize($tmp);
            if ($info === false) { throw new AppException('The file is not a valid image.', 400); }
        }
        $ext = $allowed[$mime];
        $name = gmdate('Y/m/') . Helpers::token(16) . '.' . $ext;
        return [$name, $mime];
    }

    public static function redact(string $msg): string
    {
        $msg = preg_replace('/(password|passwd|pwd|secret|token|api[_-]?key|authorization|bearer)\s*[:=]\s*[^\s,;"\']+/i', '$1=[REDACTED]', $msg) ?? $msg;
        $msg = preg_replace('/eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]+/', '[REDACTED_JWT]', $msg) ?? $msg;
        return mb_substr($msg, 0, 2000);
    }
}
