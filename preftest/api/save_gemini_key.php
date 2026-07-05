<?php
/**
 * Gemini APIキーの保存・状態確認。
 * キーは data/gemini_key.php に PHP 形式（<?php return '...';）で保存する。
 *   - data/ はデプロイ対象外・gitignore のためコードに混入しない。
 *   - .php 形式なので直接URLアクセスされても中身は出力されない。
 * action=status で設定有無のみ返す（キー値は返さない）。
 */
header('Content-Type: application/json; charset=utf-8');

$keyfile = dirname(__DIR__) . '/data/gemini_key.php';
$action  = $_POST['action'] ?? $_GET['action'] ?? 'save';

if ($action === 'status') {   // 有無のみ返す（秘密は返さない）。ログイン不要。
    $has = false;
    if (is_file($keyfile)) { $k = @include $keyfile; $has = is_string($k) && $k !== ''; }
    // 主サイトの共通キーの有無も確認（サーバー間・共有シークレット）
    $central = false;
    $post = http_build_query(['action' => 'ping', 'secret' => 'jdsf-ai-central-9f3k2026']);
    $url  = 'https://jdsf-seibu.com/api/gemini_central.php';
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
        $resp = curl_exec($ch); curl_close($ch);
    }
    if (($resp === false || $resp === '') && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post, 'timeout' => 8, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $resp = @file_get_contents($url, false, $ctx);
    }
    if ($resp) { $jj = json_decode($resp, true); if (is_array($jj) && !empty($jj['has'])) $central = true; }
    echo json_encode(['ok' => true, 'has' => $has, 'central' => $central, 'usable' => ($has || $central)], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/_auth.php';
require_auth('admin');   // 保存/削除はログイン必須

if ($action === 'delete') {
    if (is_file($keyfile)) @unlink($keyfile);
    echo json_encode(['ok' => true, 'has' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$key = trim($_POST['key'] ?? '');
// Gemini APIキー：旧形式(AIza…) と 新形式(AQ.…) の両方を許可
if (!preg_match('/^(AIza[0-9A-Za-z_\-]{20,}|AQ\.[0-9A-Za-z_\-\.]{20,})$/', $key)) {
    http_response_code(400);
    echo json_encode(['error' => 'APIキーの形式が正しくありません（AIza… または AQ.… で始まるキーを貼り付けてください）。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir = dirname($keyfile);
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$content = "<?php\nreturn " . var_export($key, true) . ";\n";
if (file_put_contents($keyfile, $content) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'キーの保存に失敗しました（data/ の書き込み権限をご確認ください）。'], JSON_UNESCAPED_UNICODE);
    exit;
}
@chmod($keyfile, 0644);
echo json_encode(['ok' => true, 'has' => true], JSON_UNESCAPED_UNICODE);
