<?php
/* セルフサービスのパスワード変更（ログイン中の府県・そのロールのみ）。
   現PW確認 → 新PWをハッシュ化して自サイトの data/auth.php を更新。 */
require __DIR__ . '/_auth.php';
$role = (($_POST['role'] ?? '') === 'build') ? 'build' : 'admin';

/* PW変更はサイト構築(build)ページに集約。build セッションがあれば admin/build どちらのPWも
   変更できる（変更には対象PWの「現在の値」が必須＝実質のゲート）。従来どおり対象ロール自身の
   セッションでも可。POST + 同一オリジン + CSRF は必須。 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')   json_out(['error' => 'Method Not Allowed'], 405);
if (!same_origin_ok())                               json_out(['error' => 'Bad Origin'], 403);
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) json_out(['error' => 'CSRF'], 403);
if (empty($_SESSION['auth']['build']) && empty($_SESSION['auth'][$role]))        json_out(['error' => 'Forbidden'], 403);

$cur = (string)($_POST['current'] ?? '');
$new = (string)($_POST['new'] ?? '');
if (mb_strlen($new) < 8)      json_out(['error' => '新しいパスワードは8文字以上にしてください'], 400);
if ($new === $cur)            json_out(['error' => '現在のパスワードと異なるものにしてください'], 400);

$auth = load_auth();
if (empty($auth[$role]) || !password_verify($cur, $auth[$role])) {
    json_out(['error' => '現在のパスワードが正しくありません'], 403);
}
$auth[$role] = password_hash($new, PASSWORD_DEFAULT);
if (!save_auth($auth)) json_out(['error' => '保存に失敗しました'], 500);

// 私用シートへ書き戻し（ベストエフォート。失敗してもローカルのPW変更は成立）
$secret = master_secret();
if ($secret) {
    $sub = explode('.', strtolower($_SERVER['HTTP_HOST'] ?? ''))[0];
    $gas = 'https://script.google.com/macros/s/AKfycbxS07Mxs6TZHdq0aGTay547EfIrN5igaJ527EaWl-O-RgHv7VHMllszJyMkI30qRU3A/exec';
    $payload = http_build_query(['action' => 'setSheetPassword', 'secret' => $secret, 'site' => $sub, 'role' => $role, 'password' => $new]);
    if (function_exists('curl_init')) {
        $ch = curl_init($gas);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
        @curl_exec($ch); curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $payload, 'timeout' => 8, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        @file_get_contents($gas, false, $ctx);
    }
}
json_out(['ok' => true]);
