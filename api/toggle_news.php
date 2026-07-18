<?php
/**
 * 公開お知らせ フラグ トグルAPI
 * POST: token, id, field(always_show|important・省略時 always_show), value(0 or 1)
 *   後方互換: field 省略時は POST['always_show'] を value として扱う。
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/_auth.php';
require_auth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id    = trim($_POST['id'] ?? '');
$field = (string)($_POST['field'] ?? 'always_show');
// 切り替え対象のフィールドはホワイトリストで限定
if (!in_array($field, ['always_show', 'important'], true)) {
    $field = 'always_show';
}
// 値: value を優先、無ければ field名のPOST（後方互換：always_show を直接送る旧UI）
$value = (($_POST['value'] ?? $_POST[$field] ?? '') === '1');

if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'IDが必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data_file = dirname(__DIR__) . '/data/news.json';
$json      = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing  = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['news'])) {
    $existing = ['news' => []];
}

$found = false;
foreach ($existing['news'] as &$item) {
    if (($item['id'] ?? '') === $id) {
        $item[$field] = $value;
        $found = true;
        break;
    }
}
unset($item);

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => '該当するIDが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$existing['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

$written = file_put_contents(
    $data_file,
    json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

if ($written === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'field' => $field, 'value' => $value], JSON_UNESCAPED_UNICODE);
