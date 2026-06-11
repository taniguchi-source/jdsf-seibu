<?php
header('Content-Type: application/json; charset=utf-8');

$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$site_id = (string)($_POST['site_id'] ?? '');
if (!in_array($site_id, ['top','1','2','3','4','5'])) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id は top または 1〜5 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items_raw = $_POST['items'] ?? '[]';
$items = @json_decode($items_raw, true);
if (!is_array($items)) $items = [];

$clean = [];
foreach ($items as $item) {
    $type_raw = (string)($item['type'] ?? 'text');
    // ギャラリー画像（最大10枚・各URLは500文字まで）
    $gallery_raw = $item['gallery'] ?? [];
    $gallery = [];
    if (is_array($gallery_raw)) {
        foreach ($gallery_raw as $g) {
            $g = mb_substr(trim((string)$g), 0, 500);
            if ($g !== '') $gallery[] = $g;
            if (count($gallery) >= 10) break;
        }
    }
    $clean[] = [
        'id'         => preg_replace('/[^a-z0-9_]/i', '', (string)($item['id'] ?? '')),
        'title'      => mb_substr(trim((string)($item['title'] ?? '')), 0, 60),
        'enabled'    => !empty($item['enabled']),
        'is_sub'     => !empty($item['is_sub']),
        'type'       => in_array($type_raw, ['text', 'sheet', 'file', 'image', 'gallery', 'pdf', 'link'], true) ? $type_raw : 'text',
        'body'       => mb_substr(trim((string)($item['body'] ?? '')), 0, 5000),
        'sheet_url'  => mb_substr(trim((string)($item['sheet_url']  ?? '')), 0, 500),
        'sheet_name' => mb_substr(trim((string)($item['sheet_name'] ?? '')), 0, 100),
        'file_url'   => mb_substr(trim((string)($item['file_url']   ?? '')), 0, 500),
        'link_url'   => mb_substr(trim((string)($item['link_url']   ?? '')), 0, 500),
        'link_label' => mb_substr(trim((string)($item['link_label'] ?? '')), 0, 100),
        'gallery'    => $gallery,
    ];
}

$file = dirname(__DIR__) . '/data/contents.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data)) $data = [];

$data[$site_id] = $clean;

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'site_id' => $site_id], JSON_UNESCAPED_UNICODE);
