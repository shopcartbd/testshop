<?php

declare(strict_types=1);+

final class RateLimiter
{
    public static function allow(string $scope, string $subject, int $limit, int $windowSeconds = 900): bool
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $pepper = (string) (getenv('APP_KEY') ?: 'allstore-rate-limit-v1');
        $key = hash_hmac('sha256', $scope . "\0" . strtolower(trim($subject)) . "\0" . $remote, $pepper);
        $window = max(30, min(86400, $windowSeconds));
        $maximum = max(1, min(1000, $limit));
        $sql = "INSERT INTO app.rate_limits (bucket_key,attempts,window_started_at,blocked_until,updated_at) "
            . "VALUES (:key,1,now(),NULL,now()) "
            . "ON CONFLICT (bucket_key) DO UPDATE SET "
            . "attempts=CASE WHEN app.rate_limits.window_started_at < now() - (:window_reset * interval '1 second') THEN 1 ELSE app.rate_limits.attempts + 1 END, "
            . "window_started_at=CASE WHEN app.rate_limits.window_started_at < now() - (:window_window * interval '1 second') THEN now() ELSE app.rate_limits.window_started_at END, "
            . "blocked_until=CASE WHEN (CASE WHEN app.rate_limits.window_started_at < now() - (:window_block * interval '1 second') THEN 1 ELSE app.rate_limits.attempts + 1 END) > :maximum THEN now() + (:window_cooldown * interval '1 second') ELSE NULL END, "
            . "updated_at=now() RETURNING blocked_until";
        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute(['key' => $key, 'window_reset' => $window, 'window_window' => $window, 'window_block' => $window, 'maximum' => $maximum, 'window_cooldown' => $window]);
            $blockedUntil = $stmt->fetchColumn();
            return $blockedUntil === null || $blockedUntil === false;
        } catch (Throwable $error) {
            error_log('Rate-limit store unavailable.');
            return false;
        }
    }

    public static function clear(string $scope, string $subject): void
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $pepper = (string) (getenv('APP_KEY') ?: 'allstore-rate-limit-v1');
        $key = hash_hmac('sha256', $scope . "\0" . strtolower(trim($subject)) . "\0" . $remote, $pepper);
        try {
            $stmt = Database::connection()->prepare('DELETE FROM app.rate_limits WHERE bucket_key=:key');
            $stmt->execute(['key' => $key]);
        } catch (Throwable $error) {
            error_log('Rate-limit reset failed.');
        }
    }
}
