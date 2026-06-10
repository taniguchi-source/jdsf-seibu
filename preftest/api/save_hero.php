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

$height_raw = (string)($_POST['height'] ?? 'standard');
$allowed = ['standard', 'half', 'two-thirds', 'four-fifths', 'none'];
$height  = in_array($height_raw, $allowed, true) ? $height_raw : 'standard';

$file = dirname(__DIR__) . '/data/hero.json';
$data = ['height' => $height, 'updated' => date('Y-m-d') . 'T' . date('H:i:s')];

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'height' => $height], JSON_UNESCAPED_UNICODE);
