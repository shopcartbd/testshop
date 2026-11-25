<?php
declare(strict_types=1);
namespace AllStore\Core;

/** Minimal server-side HTTP client (cURL). No secrets ever leave the server log. */
final class Http
{
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 12): array
    {
        if (!function_exists('curl_init')) { return [0, '', 'cURL extension unavailable']; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'AllStore/1.0 (+server-side)',
        ]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) { $response = ''; }
        return [$status, (string)$response, $err];
    }
}
