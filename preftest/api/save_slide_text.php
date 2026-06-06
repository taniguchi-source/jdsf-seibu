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

$slide_num = (int)($_POST['slide_num'] ?? 0);
if ($slide_num < 1 || $slide_num > 3) {
    http_response_code(400);
    echo json_encode(['error' => 'スライド番号は 1〜3 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$title = trim($_POST['title'] ?? '');
$desc  = $_POST['desc']  ?? '';   // 改行を保持するため trim しない

$slides_file = dirname(__DIR__) . '/data/slides.json';
$raw  = @file_get_contents($slides_file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data))           $data = [];
if (!isset($data['slides']))    $data['slides'] = [];
if (!isset($data['texts']))     $data['texts']  = [];

$data['texts']['slide' . $slide_num] = [
    'title' => $title,
    'desc'  => $desc,
];
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($slides_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'slide_num' => $slide_num], JSON_UNESCAPED_UNICODE);
