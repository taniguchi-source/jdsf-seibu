<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/_auth.php';
require_auth('build');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$theme_id = (int)($_POST['theme_id'] ?? 0);
if ($theme_id < 1 || $theme_id > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'テーマIDが無効です（1〜10）'], JSON_UNESCAPED_UNICODE);
    exit;
}
$data = [
    'theme_id' => $theme_id,
    'updated'  => date('Y-m-d') . 'T' . date('H:i:s'),
];
$file = dirname(__DIR__) . '/data/theme.json';
if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['ok' => true, 'theme_id' => $theme_id], JSON_UNESCAPED_UNICODE);
