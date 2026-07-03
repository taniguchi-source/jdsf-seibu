<?php
// お知らせの表示順を並べ替え → data/news.json を指定ID順に書き換え
header('Content-Type: application/json; charset=utf-8');
$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') { http_response_code(403); echo json_encode(['error'=>'Forbidden'],JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$ids = json_decode($_POST['ids'] ?? '[]', true);
if (!is_array($ids)) { http_response_code(400); echo json_encode(['error'=>'ids が不正です'],JSON_UNESCAPED_UNICODE); exit; }

$data_file = dirname(__DIR__) . '/data/news.json';
$json = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : ['news' => []];
if (!is_array($existing) || !isset($existing['news']) || !is_array($existing['news'])) { $existing = ['news' => []]; }

// id → item のマップ
$byId = [];
foreach ($existing['news'] as $item) {
    $id = (string)($item['id'] ?? '');
    if ($id !== '') $byId[$id] = $item;
}

// 受け取ったID順に並べ替え
$new = [];
foreach ($ids as $id) {
    $id = (string)$id;
    if (isset($byId[$id])) { $new[] = $byId[$id]; unset($byId[$id]); }
}
// 指定に含まれなかった既存項目は末尾に温存（データ欠落防止）
foreach ($byId as $leftover) { $new[] = $leftover; }

$existing['news']    = $new;
$existing['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($data_file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500); echo json_encode(['error'=>'ファイルの書き込みに失敗しました'],JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode(['ok' => true, 'count' => count($new)], JSON_UNESCAPED_UNICODE);
