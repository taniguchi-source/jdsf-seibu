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

$file = dirname(__DIR__) . '/data/hero.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data)) $data = [];

// 高さ（指定された場合のみ更新）
if (isset($_POST['height'])) {
    $h = (string)$_POST['height'];
    $allowedH = ['five-fourths', 'standard', 'half', 'two-thirds', 'four-fifths', 'none'];
    $data['height'] = in_array($h, $allowedH, true) ? $h : 'standard';
}

// 横幅（指定された場合のみ更新）
if (isset($_POST['width'])) {
    $w = (string)$_POST['width'];
    $allowedW = ['full', 'standard', 'three-quarters', 'half', 'third'];
    $data['width'] = in_array($w, $allowedW, true) ? $w : 'standard';
}

// 既定値の保証
if (!isset($data['height'])) $data['height'] = 'standard';
if (!isset($data['width']))  $data['width']  = 'standard';
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'height' => $data['height'], 'width' => $data['width']], JSON_UNESCAPED_UNICODE);
