<?php
declare(strict_types=1);
namespace AllStore\Core;

/** Consistent JSON envelope + safe redirects. */
final class Response
{
    public static function json(bool $success, string $message, mixed $data = [], array $errors = [], int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('X-Content-Type-Options: nosniff');
        $envelope = [
            'success' => $success,
            'message' => $message,
            'data' => $data === [] ? new \stdClass() : $data,
            'errors' => $errors,
        ];
        // Privilege changes rotate the CSRF token; return the fresh token so
        // single-page forms remain usable without a full reload.
        $path = Request::path();
        $token = Session::csrfToken();
        if ($token !== '' && str_starts_with($path, '/api/') && !str_starts_with($path, '/api/webhooks/')) {
            $envelope['csrf_token'] = $token;
        }
        echo Helpers::json($envelope);
        exit;
    }
    public static function ok(string $message = 'Request completed', mixed $data = [], int $status = 200): never { self::json(true, $message, $data, [], $status); }
    public static function fail(string $message, array $errors = [], int $status = 400): never { self::json(false, $message, [], $errors, $status); }
    public static function redirect(string $path, int $status = 302): never
    {
        header('Location: ' . Helpers::url($path), true, $status);
        exit;
    }
    public static function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    public static function publicCache(int $seconds = 60): void
    {
        header('Cache-Control: public, max-age=' . max(0, $seconds) . ', s-maxage=' . max(0, $seconds));
    }
}
