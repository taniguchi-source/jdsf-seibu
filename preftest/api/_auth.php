<?php
/* =====================================================================
   共有 認証基盤（府県別ハッシュ照合・セッション・CSRF・マスター秘密）
   - data/auth.php         : <?php return ['admin'=>'<bcrypt>','build'=>'<bcrypt>'];
   - data/auth_secret.php   : <?php return '<マスター秘密>';   （デプロイ時に配布）
   - data/login_attempts.php: <?php return [ip=>['n'=>int,'until'=>ts]];
   いずれも .php ＝実行され中身は露出しない。data/ はサイト別・デプロイ除外で永続。
   ===================================================================== */

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true,
            'secure' => $secure, 'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_name('jdsfsess');
    session_start();
}

function auth_data_dir() { return dirname(__DIR__) . '/data'; }

function json_out($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function load_auth() {
    $f = auth_data_dir() . '/auth.php';
    if (is_file($f)) { $d = include $f; if (is_array($d)) return $d; }
    return [];
}
function save_auth($data) {
    $f = auth_data_dir() . '/auth.php';
    $php = "<?php\nreturn " . var_export($data, true) . ";\n";
    return file_put_contents($f, $php, LOCK_EX) !== false;
}
function master_secret() {
    $f = auth_data_dir() . '/auth_secret.php';
    if (is_file($f)) { $s = include $f; if (is_string($s) && $s !== '') return $s; }
    return null;
}
function issue_csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/* 同一オリジン確認（Origin/Referer があれば自ホストと一致必須。無い場合は許容） */
function same_origin_ok() {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $k) {
        if (!empty($_SERVER[$k])) {
            $h = parse_url($_SERVER[$k], PHP_URL_HOST);
            if ($h !== null && $h !== '') return (strtolower($h) === $host);
        }
    }
    return true;
}

/* 書き込みAPIの入口：POST + 同一オリジン + CSRF + セッションロール */
function require_auth($role = 'admin') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);
    if (!same_origin_ok())                              json_out(['error' => 'Bad Origin'], 403);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) json_out(['error' => 'CSRF'], 403);
    if (empty($_SESSION['auth'][$role]))               json_out(['error' => 'Forbidden'], 403);
    return true;
}
