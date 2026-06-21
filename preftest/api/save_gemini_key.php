<?php
/**
 * Gemini APIキーの保存・状態確認。
 * キーは data/gemini_key.php に PHP 形式（<?php return '...';）で保存する。
 *   - data/ はデプロイ対象外・gitignore のためコードに混入しない。
 *   - .php 形式なので直接URLアクセスされても中身は出力されない。
 * action=status で設定有無のみ返す（キー値は返さない）。
 */
header('Content-Type: application/json; charset=utf-8');

$token = $_POST['token'] ?? $_GET['token'] ?? '';
if ($token !== 'preftest2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$keyfile = dirname(__DIR__) . '/data/gemini_key.php';
$action  = $_POST['action'] ?? $_GET['action'] ?? 'save';

if ($action === 'status') {
    $has = false;
    if (is_file($keyfile)) { $k = @include $keyfile; $has = is_string($k) && $k !== ''; }
    echo json_encode(['ok' => true, 'has' => $has], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

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
