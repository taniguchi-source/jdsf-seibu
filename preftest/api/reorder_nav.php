<?php
// ページ（ナビ）の表示順を並べ替え → data/nav.json を指定ID順に書き換え
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/_auth.php';
require_auth('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$ids = json_decode($_POST['ids'] ?? '[]', true);
if (!is_array($ids)) { http_response_code(400); echo json_encode(['error'=>'ids が不正です'],JSON_UNESCAPED_UNICODE); exit; }

$nav_file = dirname(__DIR__) . '/data/nav.json';
$json = file_exists($nav_file) ? file_get_contents($nav_file) : false;
$existing = $json ? json_decode($json, true) : ['links' => []];
if (!is_array($existing) || !isset($existing['links']) || !is_array($existing['links'])) { $existing = ['links' => []]; }

// id → link のマップ（id は変更しない。並べ替えは配列位置のみ）
$byId = [];
foreach ($existing['links'] as $link) {
    $id = (string)($link['id'] ?? '');
    if ($id !== '') $byId[$id] = $link;
}

// 受け取ったID順に並べ替え
$new = [];
foreach ($ids as $id) {
    $id = (string)$id;
    if (isset($byId[$id])) { $new[] = $byId[$id]; unset($byId[$id]); }
}
// 指定に含まれなかった既存項目は末尾に温存（データ欠落防止）
foreach ($byId as $leftover) { $new[] = $leftover; }

$existing['links']   = $new;
$existing['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($nav_file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500); echo json_encode(['error'=>'ファイルの書き込みに失敗しました'],JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode(['ok' => true, 'count' => count($new)], JSON_UNESCAPED_UNICODE);
