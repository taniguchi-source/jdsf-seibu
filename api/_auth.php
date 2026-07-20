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

/* 書き込みAPIの入口：POST + 同一オリジン + CSRF + セッションロール（いずれか1つ） */
function require_auth_any(array $roles) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);
    if (!same_origin_ok())                              json_out(['error' => 'Bad Origin'], 403);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) json_out(['error' => 'CSRF'], 403);
    foreach ($roles as $r) {
        if (!empty($_SESSION['auth'][$r])) return true;
    }
    json_out(['error' => 'Forbidden'], 403);
}

function require_auth($role = 'admin') {
    return require_auth_any([$role]);
}

/* =====================================================================
   特設サイト（1〜5）ごとのパスワード
   - data/special_auth.php : <?php return ['1'=>'<bcrypt>', ...];
     .php なので data/auth.php と同じくブラウザから中身は読めない。
   - セッションは $_SESSION['auth']['special'][<id>] = true
   ===================================================================== */

function special_auth_file() { return auth_data_dir() . '/special_auth.php'; }

function load_special_auth() {
    $f = special_auth_file();
    if (is_file($f)) { $d = include $f; if (is_array($d)) return $d; }
    return [];
}
function save_special_auth($data) {
    $php = "<?php\nreturn " . var_export($data, true) . ";\n";
    return file_put_contents(special_auth_file(), $php, LOCK_EX) !== false;
}

/* 特設サイトのIDとして妥当か（1〜5の文字列を返す。不正なら null） */
function special_site_id($v) {
    $v = (string)$v;
    return (ctype_digit($v) && (int)$v >= 1 && (int)$v <= 5) ? $v : null;
}

/* 書き込みAPIの入口（特設サイト用）：
   POST + 同一オリジン + CSRF を満たしたうえで、
   admin / build / そのサイトの special セッション のいずれかがあれば通す。 */
function require_special_auth($site_id) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);
    if (!same_origin_ok())                              json_out(['error' => 'Bad Origin'], 403);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) json_out(['error' => 'CSRF'], 403);

    if (!empty($_SESSION['auth']['admin']) || !empty($_SESSION['auth']['build'])) return true;

    $id = special_site_id($site_id);
    if ($id !== null && !empty($_SESSION['auth']['special'][$id])) return true;

    json_out(['error' => 'Forbidden'], 403);
}

/* いずれかの特設サイトにログイン中か */
function has_any_special_auth() {
    $s = $_SESSION['auth']['special'] ?? null;
    if (!is_array($s)) return false;
    foreach ($s as $v) { if (!empty($v)) return true; }
    return false;
}

/* ファイルアップロードの入口：admin / build / いずれかの特設サイト担当者を通す。
   どのサイトの担当者かは問わない（uploads/ は共通のため）。 */
function require_upload_auth() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);
    if (!same_origin_ok())                              json_out(['error' => 'Bad Origin'], 403);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrf)) json_out(['error' => 'CSRF'], 403);
    if (!empty($_SESSION['auth']['admin']) || !empty($_SESSION['auth']['build'])) return true;
    if (has_any_special_auth()) return true;
    json_out(['error' => 'Forbidden'], 403);
}

/* =====================================================================
   ログイン試行のレート制限（data/login_attempts.php を共用）
   api/login.php は従来どおり自前で処理している。ここは新しいログイン
   （特設サイト）用のヘルパー。
   ===================================================================== */

function throttle_file() { return auth_data_dir() . '/login_attempts.php'; }

function throttle_load() {
    $f = throttle_file();
    $a = is_file($f) ? (include $f) : [];
    return is_array($a) ? $a : [];
}
function throttle_save($a) {
    file_put_contents(throttle_file(), "<?php\nreturn " . var_export($a, true) . ";\n", LOCK_EX);
}

/* ロック中なら残り秒数、そうでなければ 0 */
function throttle_locked_for($key) {
    $a   = throttle_load();
    $rec = $a[$key] ?? ['n' => 0, 'until' => 0];
    $now = time();
    return (($rec['until'] ?? 0) > $now) ? (($rec['until'] ?? 0) - $now) : 0;
}
/* 失敗を記録（5回で5分ロック） */
function throttle_fail($key) {
    $a   = throttle_load();
    $rec = $a[$key] ?? ['n' => 0, 'until' => 0];
    $rec['n'] = ($rec['n'] ?? 0) + 1;
    if ($rec['n'] >= 5) { $rec['until'] = time() + 300; $rec['n'] = 0; }
    $a[$key] = $rec;
    throttle_save($a);
}
function throttle_reset($key) {
    $a = throttle_load();
    unset($a[$key]);
    throttle_save($a);
}
