<?php
declare(strict_types=1);
/**
 * ONE-TIME administrator setup form. Deployed temporarily, protected by a
 * random token supplied through the ALLSTORE_ADMIN_FORM_TOKEN env var.
 * The plaintext password exists only in this request's memory: it is hashed
 * with password_hash(), then unset. It is never logged, stored or returned.
 * The route self-disables as soon as bootstrap_completed = '1'.
 */
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Request;
use AllStore\Core\Response;

Response::noCache();
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; img-src 'self' data:");

$expected = (string)(getenv('ALLSTORE_ADMIN_FORM_TOKEN') ?: '');
$provided = (string)($_GET['t'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(404);
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not found</title></head><body style="font:16px system-ui;padding:28px"><h1>404</h1><p>Not found.</p></body></html>';
    exit;
}

function admform_esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function admform_page(string $title, string $body): never
{
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow,noarchive"><title>' . admform_esc($title) . '</title>'
       . '<style>*{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#16202e;font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px}'
       . '.card{max-width:520px;margin:6vh auto;background:#fff;border:1px solid #e3e8f0;border-radius:16px;padding:26px;box-shadow:0 8px 30px rgba(16,32,54,.08)}'
       . 'h1{font-size:22px;margin:0 0 6px}p{margin:8px 0;color:#4a586c}label{display:block;font-weight:600;margin:16px 0 6px;font-size:14px}'
       . 'input{width:100%;padding:12px 14px;border:1px solid #cfd8e6;border-radius:10px;font-size:16px;background:#fff}'
       . 'button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:10px;background:#1667d6;color:#fff;font-size:16px;font-weight:700;cursor:pointer}'
       . '.note{background:#eef4ff;border:1px solid #d5e3ff;border-radius:10px;padding:12px;font-size:13px;color:#20436f;margin-top:18px}'
       . '.err{background:#fff0f0;border:1px solid #ffd0d0;border-radius:10px;padding:12px;color:#9b1c1c;margin-top:14px;font-size:14px}'
       . '.ok{background:#edfaf1;border:1px solid #c7ecd4;border-radius:10px;padding:14px;color:#14663a;margin-top:16px}'
       . 'a{color:#1667d6;font-weight:700}.small{font-size:12px;color:#6b7a90;margin-top:16px}</style></head>'
       . '<body><main class="card"><h1>' . admform_esc($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

try {
    $completed = (string)Database::value("SELECT value FROM settings WHERE key='bootstrap_completed'");
    $adminCount = (int)Database::value("SELECT COUNT(*) FROM profiles p JOIN roles r ON r.id=p.role_id WHERE r.slug IN ('super_admin','admin')");
    $roleId = Database::value("SELECT id FROM roles WHERE slug='super_admin'");
} catch (Throwable $e) {
    http_response_code(503);
    admform_page('সাময়িকভাবে অপ্রাপ্য', '<p>ডাটাবেজে সংযোগ করা যাচ্ছে না। কিছুই বদলানো হয়নি। একটু পরে আবার চেষ্টা করুন।</p>');
}

if ($completed === '1' || $adminCount > 0) {
    http_response_code(410);
    admform_page('ইতিমধ্যে সম্পন্ন', '<div class="ok">✅ প্রথম অ্যাডমিন আগেই তৈরি হয়েছে। এই ফর্মটি আর সক্রিয় নেই।</div>'
        . '<p>এখন <a href="/admin/login">/admin/login</a>-এ লগইন করুন।</p>');
}

$error = '';
if (Request::isPost()) {
    $tPost = (string)($_POST['t'] ?? '');
    if ($tPost === '' || !hash_equals($expected, $tPost)) {
        http_response_code(403);
        admform_page('অনুমতি নেই', '<p>টোকেন মিলেনি। কিছুই বদলানো হয়নি।</p>');
    }
    $email = strtolower(trim((string)Request::str('email', '', 254)));
    $rawPw = Request::input('password', '');
    $rawPw2 = Request::input('password_confirm', '');
    $pw = is_string($rawPw) ? $rawPw : '';
    $pw2 = is_string($rawPw2) ? $rawPw2 : '';
    $name = trim((string)Request::str('name', 'Store Owner', 120));
    if ($name === '') { $name = 'Store Owner'; }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'সঠিক ইমেল দিন।';
    } elseif (strlen($pw) < 12 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        $error = 'পাসওয়ার্ড কমপক্ষে ১২ অক্ষরের হতে হবে এবং অক্ষর + ডিজিট থাকতে হবে।';
    } elseif (!hash_equals($pw, $pw2)) {
        $error = 'দুইবারের পাসওয়ার্ড মিলেনি।';
    } elseif (!$roleId) {
        $error = 'super_admin role পাওয়া যায়নি। কিছুই বদলানো হয়নি।';
    } else {
        try {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            if (!is_string($hash) || !password_verify($pw, $hash)) {
                throw new RuntimeException('hash failed');
            }
            $id = Helpers::uuid();
            $now = Helpers::now();
            $safeEmail = $email;
            Database::tx(static function () use ($id, $safeEmail, $hash, $name, $roleId, $now): void {
                Database::insert('profiles', [
                    'id' => $id, 'email' => $safeEmail, 'password_hash' => $hash,
                    'full_name' => $name, 'role_id' => $roleId, 'status' => 'active',
                    'email_verified' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                Database::exec("INSERT INTO settings(key,value,group_name) VALUES('bootstrap_completed','1','security') ON CONFLICT(key) DO UPDATE SET value='1'");
                Database::exec(
                    "INSERT INTO audit_logs(id,actor_email,action,entity,meta,severity,created_at) VALUES(:i,:e,'admin.bootstrap','profiles',:m,'critical',:t)",
                    ['i' => Helpers::uuid(), 'e' => $safeEmail, 'm' => '{}', 't' => $now]
                );
            });
            unset($pw, $pw2, $rawPw, $rawPw2, $hash);
            admform_page('✅ অ্যাডমিন তৈরি হয়েছে',
                '<div class="ok"><strong>সফল।</strong> আপনার ইমেল: <b>' . admform_esc($email) . '</b><br>'
                . 'পাসওয়ার্ড শুধু আপনি জানেন — সার্ভারে hash হয়ে ডাটাবেজে গেছে, কোথাও plaintext রাখা হয়নি।</div>'
                . '<p>এখন <a href="/admin/login">https://allstorbd.vercel.app/admin/login</a> খুলে ইমেল + পাসওয়ার্ড দিয়ে লগইন করুন।</p>'
                . '<div class="note">পাসওয়ার্ডটি এখনি আপনার password manager-এ রেখে দিন। এই ফর্মের লিংকটি কাউকে দেবেন না। '
                . 'লগইন সফল হলে আমাকে বলুন — আমি এই ফর্ম ও temporary token মুছে clean redeploy করে দেব।</div>');
        } catch (Throwable $e) {
            unset($pw, $pw2, $rawPw, $rawPw2);
            $error = 'সংরক্ষণ করা যায়নি; কিছুই বদলানো হয়নি। একটু পরে আবার চেষ্টা করুন।';
        }
    }
    unset($pw, $pw2, $rawPw, $rawPw2);
}

$t = admform_esc($provided);
$errHtml = $error !== '' ? '<div class="err">' . admform_esc($error) . '</div>' : '';
admform_page('প্রথম অ্যাডমিন সেট করুন',
    '<p>একবারের ফর্ম। পাসওয়ার্ড চ্যাটে, লগে বা ফাইলে যায় না — সরাসরি সার্ভারে <code>password_hash()</code> করে ডাটাবেজে রাখা হয়।</p>'
    . $errHtml
    . '<form method="post" action="" autocomplete="off">'
    . '<input type="hidden" name="t" value="' . $t . '">'
    . '<label for="email">অ্যাডমিন ইমেল</label>'
    . '<input id="email" name="email" type="email" required autocomplete="username" placeholder="owner@example.com">'
    . '<label for="name">নাম (ঐচ্ছিক)</label>'
    . '<input id="name" name="name" type="text" maxlength="120" value="Store Owner" autocomplete="off">'
    . '<label for="password">নতুন পাসওয়ার্ড</label>'
    . '<input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" placeholder="কমপক্ষে ১২ অক্ষর, অক্ষর + ডিজিট">'
    . '<label for="password_confirm">পাসওয়ার্ড আবার দিন</label>'
    . '<input id="password_confirm" name="password_confirm" type="password" required minlength="12" autocomplete="new-password">'
    . '<button type="submit">অ্যাডমিন তৈরি করুন</button>'
    . '</form>'
    . '<div class="note">💡 ২০+ অক্ষরের random পাসওয়ার্ড ব্যবহার করুন (password manager দিয়ে বানানো)। '
    . 'লগইনে ৫ বারের বেশি ভুল করলে ১৫ মিনিট অপেক্ষা করতে হবে।</div>'
    . '<p class="small">এই পাতাটি noindex / no-store; সফল হওয়ার সাথে সাথেই নিজে বন্ধ হয়ে যায়।</p>');
