<?php

declare(strict_types=1);

final class Storage
{
    public static function productImage(array $file): string
    {
        $url = rtrim((string) getenv('SUPABASE_URL'), '/');
        $key = (string) (getenv('SUPABASE_STORAGE_SERVICE_KEY') ?: getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
        $bucket = (string) (getenv('SUPABASE_STORAGE_BUCKET') ?: 'product-media');
        if ($url === '' || $key === '') throw new RuntimeException('Image storage is not configured.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) throw new InvalidArgumentException('ছবিটি সঠিকভাবে আপলোড হয়নি`।');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) throw new InvalidArgumentException('ছবির আকার সর্বোচ্চ ৫ MB হতে পারে।');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']);
        $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($extensions[$mime])) throw new InvalidArgumentException('শুধু JPG, PNG বা WebP ছবি দেওয়া যাবে।');
        $image = @getimagesize((string) $file['tmp_name']);
        if (!is_array($image) || ($image[0] ?? 0) < 1 || ($image[1] ?? 0) < 1 || $image[0] > 6000 || $image[1] > 6000 || $image[0] * $image[1] > 30000000) throw new InvalidArgumentException('ছবির মাত্রা গ্রহণযোগ্য নয়।');
        $object = 'products/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $encoded = implode('/', array_map('rawurlencode', explode('/', $object)));
        $bucketPath = rawurlencode($bucket) . '/' . $encoded;
        $handle = fopen((string) $file['tmp_name'], 'rb');
        if (!$handle) throw new RuntimeException('ছবিটি পড়া যায়নি।');
        $contents = stream_get_contents($handle);
        fclose($handle);
        if (!is_string($contents) || strlen($contents) !== $size) throw new RuntimeException('ছবিটি পড়া যায়নি।');
        $ch = curl_init($url . '/storage/v1/object/' . $bucketPath);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $contents,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: ' . $mime,
                'Cache-Control: public, max-age=31536000, immutable',
                'x-upsert: false',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($response === false || $error !== 0 || $status < 200 || $status >= 300) {
            error_log('Supabase Storage upload failed with HTTP status ' . $status . '.');
            throw new RuntimeException('ছবি সংরক্ষণ করা যায়নি। Storage configuration যাচাই করুন।');
        }
        return $url . '/storage/v1/object/public/' . $bucketPath;
    }
}
