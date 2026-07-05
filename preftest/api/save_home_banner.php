<?php
// 府県サイト トップの広告バナー（最大6枚）を保存 → data/home_banner.json
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('build');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$items_raw = $_POST['banners'] ?? '[]';
$items = @json_decode($items_raw, true);
if (!is_array($items)) $items = [];

$clean = [];
$i = 0;
foreach ($items as $item) {
    $i++;
    if ($i > 6) break; // 最大6枚
    // 画像URL：uploads/... または http(s):// のみ許可（それ以外は空）
    $img = mb_substr(trim((string)($item['image'] ?? '')), 0, 300);
    if ($img !== '' && !preg_match('#^(https?://|uploads/)#i', $img)) $img = '';
    // リンクURL：javascript:/data:/vbscript: は破棄
    $url = mb_substr(trim((string)($item['url'] ?? '')), 0, 300);
    if ($url !== '' && preg_match('#^\s*(javascript|data|vbscript):#i', $url)) $url = '';
    $clean[] = [
        'id'      => $i,
        'enabled' => !empty($item['enabled']),
        'image'   => $img,
        'url'     => $url,
        'newtab'  => !empty($item['newtab']),
    ];
}

$file = dirname(__DIR__) . '/data/home_banner.json';
$data = ['banners' => $clean, 'updated' => date('Y-m-d') . 'T' . date('H:i:s')];

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
