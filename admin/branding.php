<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Session;
use AllStore\Core\Response;
use AllStore\Core\Logger;
use AllStore\Core\AppException;
use AllStore\Services\UploadService;
require_once APP_ROOT . '/includes/admin-crud.php';
Auth::requireAdmin('settings.manage');

$current = [
    'site_logo_url' => (string)Helpers::setting('site_logo_url', ''),
    'favicon_url' => (string)Helpers::setting('favicon_url', ''),
];

if (Request::isPost()) {
    Session::requireCsrf();
    try {
        $action = Request::str('action', 'save', 20);
        if ($action === 'reset') {
            Helpers::setSetting('site_logo_url', '', 'general');
            Helpers::setSetting('favicon_url', '', 'general');
            Logger::audit('admin.branding.reset', 'settings');
            Session::flash('success', 'Default logo and favicon restored.');
            Response::redirect('/admin/branding');
        }
        $saved = [];
        foreach (['logo_file' => 'site_logo_url', 'favicon_file' => 'favicon_url'] as $fileKey => $settingKey) {
            $file = $_FILES[$fileKey] ?? null;
            $hasUpload = is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            $urlInput = trim(Request::str('url_' . $settingKey, '', 1000));
            $clear = !empty(Request::input('clear_' . $settingKey));
            if ($clear) {
                Helpers::setSetting($settingKey, '', 'general');
                $saved[] = $settingKey;
                continue;
            }
            if ($hasUpload) {
                $stored = UploadService::store($file);
                Helpers::setSetting($settingKey, $stored, 'general');
                $saved[] = $settingKey;
                continue;
            }
            if ($urlInput !== '') {
                $safe = Helpers::safeMediaUrl($urlInput);
                if ($safe === '') { throw new AppException('Use an /assets/ or /storage/uploads/ path, or a full HTTPS image URL.', 422); }
                Helpers::setSetting($settingKey, $safe, 'general');
                $saved[] = $settingKey;
            }
        }
        Logger::audit('admin.branding.updated', 'settings', '', ['fields' => $saved]);
        Session::flash('success', $saved === [] ? 'Nothing changed.' : 'Branding updated.');
    } catch (AppException $e) {
        Session::flash('error', $e->getMessage());
    } catch (\Throwable $e) {
        Logger::exception($e);
        Session::flash('error', 'Branding could not be saved.');
    }
    Response::redirect('/admin/branding');
}

$current = [
    'site_logo_url' => (string)Helpers::setting('site_logo_url', ''),
    'favicon_url' => (string)Helpers::setting('favicon_url', ''),
];
$logoPreview = Helpers::safeMediaUrl($current['site_logo_url']);
$faviconPreview = Helpers::safeMediaUrl($current['favicon_url']);
$csrf = Session::csrfField();

admin_header(['title' => 'Branding & favicon', 'subtitle' => 'Store logo and the browser tab icon', 'active' => 'branding']);

echo '<div class="alert alert-info" style="margin-bottom:16px">' . icon('info', 16)
    . '<span>Without an upload the bundled AllStore mark and favicon are used, so the storefront looks exactly as it does now. Upload a PNG, JPG, WebP, SVG or ICO file — square images work best for the favicon (32×32 up to 512×512).</span></div>';

echo '<form method="post" enctype="multipart/form-data">' . $csrf . '<input type="hidden" name="action" value="save">';
echo '<div class="admin-grid-2">';

// ---- logo ----
echo '<div class="card card-pad"><h3 style="margin-bottom:12px">Store logo (header)</h3>';
echo '<div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;min-height:56px;padding:10px;border:1px dashed var(--c-border);border-radius:10px">';
if ($logoPreview !== '') {
    echo '<img src="' . Helpers::e($logoPreview) . '" alt="Current logo" style="max-height:44px;max-width:200px;object-fit:contain">';
} else {
    echo '<span class="brand-mark">' . icon('layers', 20) . '</span><span class="text-muted" style="font-size:13px">Default mark in use</span>';
}
echo '</div>';
echo '<div class="field"><label for="logo_file">Upload logo</label><input class="input" type="file" id="logo_file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml"></div>';
echo '<div class="field" style="margin-top:10px"><label for="url_site_logo_url">…or image URL</label><input class="input" type="text" id="url_site_logo_url" name="url_site_logo_url" placeholder="/storage/uploads/... or https://…" maxlength="1000"></div>';
if ($logoPreview !== '') {
    echo '<label class="checkbox" style="margin-top:10px"><input type="checkbox" name="clear_site_logo_url" value="1"><span>Remove custom logo (use the default mark)</span></label>';
}
echo '<p class="field-hint" style="margin-top:8px">Recommended: transparent PNG or SVG, about 200×48 px.</p></div>';

// ---- favicon ----
echo '<div class="card card-pad"><h3 style="margin-bottom:12px">Favicon (browser tab &amp; phone icon)</h3>';
echo '<div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;min-height:56px;padding:10px;border:1px dashed var(--c-border);border-radius:10px">';
echo '<img src="' . Helpers::e($faviconPreview !== '' ? $faviconPreview : '/assets/img/favicon-48.png') . '" alt="Current favicon" style="width:40px;height:40px;border-radius:8px;object-fit:cover">';
echo '<div style="font-size:12px;color:var(--c-muted)">' . ($faviconPreview !== '' ? 'Custom favicon in use' : 'Default AllStore favicon (green stacked layers)') . '</div>';
echo '</div>';
echo '<div class="field"><label for="favicon_file">Upload favicon</label><input class="input" type="file" id="favicon_file" name="favicon_file" accept="image/png,image/x-icon,image/svg+xml,image/webp"></div>';
echo '<div class="field" style="margin-top:10px"><label for="url_favicon_url">…or icon URL</label><input class="input" type="text" id="url_favicon_url" name="url_favicon_url" placeholder="/assets/img/favicon-192.png or https://…" maxlength="1000"></div>';
if ($faviconPreview !== '') {
    echo '<label class="checkbox" style="margin-top:10px"><input type="checkbox" name="clear_favicon_url" value="1"><span>Remove custom favicon (use the default)</span></label>';
}
echo '<p class="field-hint" style="margin-top:8px">Square PNG of 192×192 or 512×512 is ideal. The same file is used for iOS home screens.</p></div>';
echo '</div>';

echo '<div class="row wrap" style="margin-top:16px;gap:8px"><button class="btn btn-primary btn-lg" type="submit">' . icon('check', 16) . 'Save branding</button>'
    . '<a class="btn btn-outline btn-lg" href="/" target="_blank" rel="noopener">Preview '.icon('external', 15) . '</a></div></form>';

echo '<form method="post" style="margin-top:12px">' . $csrf . '<input type="hidden" name="action" value="reset">'
    . '<button class="btn btn-ghost" type="submit" data-confirm="Restore the default logo and favicon?">' . icon('rotate', 15) . 'Reset to defaults</button></form>';

echo '<div class="card card-pad" style="margin-top:16px"><h3 style="margin-bottom:10px">Bundled icon files</h3><div class="row wrap" style="gap:14px;align-items:flex-end">';
foreach ([16, 32, 48, 96, 180, 192, 512] as $size) {
    echo '<div style="text-align:center;font-size:11px;color:var(--c-muted)"><img src="/assets/img/favicon-' . $size . '.png" alt="' . $size . 'px favicon" style="width:' . min(96, max(16, $size / 2)) . 'px;height:auto;border-radius:6px"><div>' . $size . 'px</div></div>';
}
echo '</div><p class="field-hint" style="margin-top:10px">Also shipped: <code>/assets/img/favicon.svg</code>, <code>/assets/img/favicon.ico</code> and <code>/assets/img/site.webmanifest</code>.</p></div>';

admin_footer();
