<?php
/* パスワードの変更（サイト構築ページの「🔑 パスワード変更」から使用）。
   - admin（役員用）/ build（サイト構築ページ用）: data/auth.php を更新。現PW確認が必須。
   - schedule（担当者用・競技予定）: data/schedule_auth.php を更新。初回のみ現PW不要。
   いずれも POST + 同一オリジン + CSRF が必須。役員/サイト構築セッションで操作。 */
require __DIR__ . '/_auth.php';

$roleIn = (string)($_POST['role'] ?? '');
$role   = in_array($roleIn, ['admin', 'build', 'schedule', 'uketsuke'], true) ? $roleIn : 'admin';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')   json_out(['error' => 'Method Not Allowed'], 405);
if (!same_origin_ok())                               json_out(['error' => 'Bad Origin'], 403);
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) json_out(['error' => 'CSRF'], 403);

$cur = (string)($_POST['current'] ?? '');
$new = (string)($_POST['new'] ?? '');
if (mb_strlen($new) < 8) json_out(['error' => '新しいパスワードは8文字以上にしてください'], 400);

/* ===== 担当者用（競技予定）: data/schedule_auth.php ===== */
if ($role === 'schedule') {
    /* サイト構築/役員でログイン済みなら現在PW不要（信頼済み操作）。担当者自身のセッションでも可。 */
    if (empty($_SESSION['auth']['build']) && empty($_SESSION['auth']['admin']) && empty($_SESSION['auth']['schedule'])) {
        json_out(['error' => 'Forbidden'], 403);
    }
    if (!save_schedule_auth(password_hash($new, PASSWORD_DEFAULT))) json_out(['error' => '保存に失敗しました'], 500);
    json_out(['ok' => true]);
}

/* ===== 受付システム用: data/uketsuke_auth.php ===== */
if ($role === 'uketsuke') {
    if (empty($_SESSION['auth']['build']) && empty($_SESSION['auth']['admin']) && empty($_SESSION['auth']['uketsuke'])) {
        json_out(['error' => 'Forbidden'], 403);
    }
    if (!save_uketsuke_auth(password_hash($new, PASSWORD_DEFAULT))) json_out(['error' => '保存に失敗しました'], 500);
    json_out(['ok' => true]);
}

/* ===== 役員用(admin) / サイト構築用(build): data/auth.php =====
   役員用は、サイト構築/役員でログイン済みなら現在PW不要（信頼済み操作）。
   サイト構築用(build)は入口のパスワードのため、従来どおり現在PWが必要。 */
$auth = load_auth();
if ($role === 'admin') {
    if (empty($_SESSION['auth']['build']) && empty($_SESSION['auth']['admin'])) json_out(['error' => 'Forbidden'], 403);
    $auth['admin'] = password_hash($new, PASSWORD_DEFAULT);
} else { /* build */
    if (empty($_SESSION['auth']['build'])) json_out(['error' => 'Forbidden'], 403);
    if ($new === $cur) json_out(['error' => '現在のパスワードと異なるものにしてください'], 400);
    if (empty($auth['build']) || !password_verify($cur, $auth['build'])) {
        json_out(['error' => '現在のパスワードが正しくありません'], 403);
    }
    $auth['build'] = password_hash($new, PASSWORD_DEFAULT);
}
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
