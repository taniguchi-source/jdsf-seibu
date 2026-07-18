<?php
/**
 * 公開お知らせ 削除API
 * POST: token, id
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/_auth.php';
require __DIR__ . '/_news_util.php';
require_auth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = trim($_POST['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'IDが必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data_file = dirname(__DIR__) . '/data/news.json';
$json     = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['news'])) {
    $existing = ['news' => []];
}

// 削除対象の添付ファイルを先に物理削除（孤立ファイルを残さない）
foreach ($existing['news'] as $n) {
    if (($n['id'] ?? '') === $id && is_array($n['attachments'] ?? null)) {
        news_unlink_attachments($n['attachments']);
    }
}

$existing['news'] = array_values(
    array_filter($existing['news'], function ($n) use ($id) {
        return ($n['id'] ?? '') !== $id;
    })
);
$existing['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

$written = file_put_contents(
    $data_file,
    json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

if ($written === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
