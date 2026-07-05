<?php
// 主サイト トップの都道府県リンク（クイックリンク）を保存 → data/home_quicklinks.json
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('build');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$items_raw = $_POST['links'] ?? '[]';
$items = @json_decode($items_raw, true);
if (!is_array($items)) $items = [];

$clean = [];
$i = 0;
foreach ($items as $item) {
    $i++;
    if ($i > 20) break; // スロット上限
    $clean[] = [
        'id'      => $i,
        'enabled' => !empty($item['enabled']),
        'label'   => mb_substr(trim((string)($item['label'] ?? '')), 0, 40),
        'url'     => mb_substr(trim((string)($item['url'] ?? '')), 0, 300), // 空＝非リンク表示
        'newtab'  => !empty($item['newtab']),
    ];
}

$file = dirname(__DIR__) . '/data/home_quicklinks.json';
$data = ['links' => $clean, 'updated' => date('Y-m-d') . 'T' . date('H:i:s')];

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
