<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Config;
use AllStore\Core\Http;
use AllStore\Core\Helpers;
use AllStore\Core\Logger;
use AllStore\Core\Security;

/**
 * Integration-ready email. With no provider configured the method returns a
 * clear "not configured" result - it NEVER reports a fake successful send.
 */
final class MailService
{
    public static function configured(): bool
    {
        return (string)Config::get('mail.url') !== '' && (string)Config::get('mail.key') !== '' && (string)Config::get('mail.from') !== '';
    }
    public static function send(string $to, string $subject, string $text): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return ['ok'=>false,'reason'=>'Invalid recipient']; }
        if (!self::configured()) {
            Logger::log('info', 'mail.skipped_not_configured', ['subject'=>mb_substr($subject,0,60)]);
            return ['ok'=>false,'reason'=>'Email provider is not configured (MAIL_PROVIDER_URL / MAIL_API_KEY / MAIL_FROM_ADDRESS).'];
        }
        $payload = Helpers::json([
            'from' => ['address'=>(string)Config::get('mail.from'), 'name'=>(string)Config::get('mail.name')],
            'to' => [['address'=>$to]], 'subject' => $subject, 'text' => $text,
        ]);
        [$status, $body, $err] = Http::request('POST', (string)Config::get('mail.url'), [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string)Config::get('mail.key'),
            'Accept: application/json',
        ], $payload);
        if ($status >= 200 && $status < 300) { return ['ok'=>true,'reason'=>'']; }
        Logger::log('error', 'mail.provider_failed', ['status'=>$status, 'detail'=>mb_substr(Security::redact($body . ' ' . $err),0,200)]);
        return ['ok'=>false,'reason'=>'Email provider rejected the request.'];
    }
}
