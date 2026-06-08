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
if (!in_array($site_id, ['1','2','3','4','5'])) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id は 1〜5 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items_raw = $_POST['items'] ?? '[]';
$items = @json_decode($items_raw, true);
if (!is_array($items)) $items = [];

$clean = [];
foreach ($items as $item) {
    $clean[] = [
        'id'      => preg_replace('/[^a-z0-9_]/i', '', (string)($item['id'] ?? '')),
        'title'   => mb_substr(trim((string)($item['title'] ?? '')), 0, 60),
        'enabled' => !empty($item['enabled']),
        'body'    => mb_substr(trim((string)($item['body'] ?? '')), 0, 5000),
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
