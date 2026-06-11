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

$file = dirname(__DIR__) . '/data/sitename.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data)) $data = [];

if (isset($_POST['name'])) {
    $name = mb_substr(trim((string)$_POST['name']), 0, 80);
    $data['name'] = $name !== '' ? $name : 'テストダンススポーツ連盟';
}
if (!isset($data['name'])) $data['name'] = 'テストダンススポーツ連盟';
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'name' => $data['name']], JSON_UNESCAPED_UNICODE);
