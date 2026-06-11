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

$upload_dir = dirname(__DIR__) . '/uploads/slides/';
foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
    $f = $upload_dir . 'slide-' . $slide_num . '.' . $ext;
    if (file_exists($f)) {
        unlink($f);
    }
}

$slides_file = dirname(__DIR__) . '/data/slides.json';
$raw    = @file_get_contents($slides_file);
$slides = $raw ? @json_decode($raw, true) : [];
if (!isset($slides['slides'])) $slides['slides'] = [];

unset($slides['slides']['slide' . $slide_num]);
$slides['updated'] = date('Y-m-d') . 'T' . date('H:i:s');
file_put_contents($slides_file, json_encode($slides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode(['ok' => true, 'slide_num' => $slide_num], JSON_UNESCAPED_UNICODE);
