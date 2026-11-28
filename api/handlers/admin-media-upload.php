<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
require_once __DIR__ . '/_common.php';

use AllStore\Core\Auth;
use AllStore\Core\Logger;
use AllStore\Core\Request;
use AllStore\Core\Response;
use AllStore\Core\Session;
use AllStore\Core\AppException;
use AllStore\Services\UploadService;
use AllStore\Services\CloudinaryService;

/**
 * POST /api/admin/media/upload
 *
 * The only image upload entry point used by the admin panel. The file reaches
 * this server-side route first (never Cloudinary directly from the browser),
 * the route uploads it with the CLOUDINARY_* credentials taken from the server
 * environment, and the browser receives nothing but the secure_url.
 *
 * Accepts: multipart/form-data with `file` (single) and/or `files[]` (multiple).
 * Returns: { provider, images: [{ url, public_id, width, height, format, bytes }] }
 * The caller stores `url` (the Cloudinary secure_url) in the database.
 */
api_method('POST');
api_require_admin('products.manage');
api_csrf();
api_rate('admin.media.upload', 60, 300);

$provider = UploadService::provider();
if ($provider === 'none') {
    Response::fail(
        'Image storage is not configured on the server. Add CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET in Vercel → Settings → Environment Variables, then redeploy.',
        ['env' => CloudinaryService::missingEnv()],
        503
    );
}

/** Normalise single + array uploads into a list of file arrays. */
$files = [];
$single = $_FILES['file'] ?? null;
if (is_array($single) && !is_array($single['name'] ?? null) && (int)($single['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $files[] = $single;
}
$multi = $_FILES['files'] ?? null;
if (is_array($multi) && isset($multi['name']) && is_array($multi['name'])) {
    foreach (array_keys($multi['name']) as $i) {
        if ((int)($multi['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        $files[] = [
            'name' => (string)($multi['name'][$i] ?? ''),
            'type' => (string)($multi['type'][$i] ?? ''),
            'tmp_name' => (string)($multi['tmp_name'][$i] ?? ''),
            'error' => (int)($multi['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($multi['size'][$i] ?? 0),
        ];
    }
}
if ($files === []) {
    Response::fail('Choose an image to upload.', ['file' => 'No file was received.'], 422);
}
if (count($files) > 12) {
    Response::fail('Upload at most 12 images at a time.', ['files' => 'Too many files.'], 422);
}

$images = [];
$errors = [];
foreach ($files as $index => $file) {
    try {
        $result = CloudinaryService::configured()
            ? CloudinaryService::uploadImage($file)
            : ['secure_url' => UploadService::store($file), 'public_id' => '', 'width' => 0, 'height' => 0, 'format' => '', 'bytes' => 0, 'provider' => $provider];
        $images[] = [
            'url' => (string)$result['secure_url'],
            'public_id' => (string)($result['public_id'] ?? ''),
            'width' => (int)($result['width'] ?? 0),
            'height' => (int)($result['height'] ?? 0),
            'format' => (string)($result['format'] ?? ''),
            'bytes' => (int)($result['bytes'] ?? 0),
        ];
        // Audit is best-effort and must never turn a successful upload into an
        // error response.
        try {
            Logger::audit('admin.media.uploaded', 'media', (string)($result['public_id'] ?? ''), [
                'provider' => (string)($result['provider'] ?? $provider),
                'bytes' => (int)($result['bytes'] ?? 0),
            ]);
        } catch (\Throwable $auditError) {
            Logger::exception($auditError);
        }
    } catch (AppException $e) {
        $errors['file_' . $index] = $e->getMessage();
    } catch (\Throwable $e) {
        Logger::exception($e);
        $errors['file_' . $index] = 'The image could not be uploaded. Please try again.';
    }
}

if ($images === []) {
    $first = (string)reset($errors);
    Response::fail($first !== '' ? $first : 'The image could not be uploaded.', $errors, 422);
}

$message = count($images) === 1
    ? 'Image uploaded to ' . ($provider === 'cloudinary' ? 'Cloudinary' : ucfirst($provider)) . '.'
    : count($images) . ' images uploaded to ' . ($provider === 'cloudinary' ? 'Cloudinary' : ucfirst($provider)) . '.';
if ($errors !== []) { $message .= ' ' . count($errors) . ' file(s) failed.'; }

Response::ok($message, [
    'provider' => $provider,
    'images' => $images,
    'failed' => (object)$errors,
    'folder' => $provider === 'cloudinary' ? CloudinaryService::folder() : '',
]);
