<?php
/**
 * 公開お知らせ 更新API
 * POST: token, id, category, title, detail, url, event_date
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$token = $_POST['token'] ?? '';
if ($token !== 'jdsfseibu2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id         = trim($_POST['id']         ?? '');
$category   = trim($_POST['category']   ?? '');
$title      = trim($_POST['title']      ?? '');
$detail     = trim($_POST['detail']     ?? '');
$url        = trim($_POST['url']        ?? '');
$event_date = trim($_POST['event_date'] ?? '');

if ($id === '' || !$category || !$title || !$detail) {
    http_response_code(400);
    echo json_encode(['error' => 'ID・カテゴリ・タイトル・詳細内容は必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) {
    $url = '';
}

// 実施日の処理（YYYY-MM-DD → Y.m.d 表示形式）
if ($event_date) {
    $parts = explode('-', $event_date);
    if (count($parts) === 3) {
        $event_display = sprintf('%s.%02d.%02d', $parts[0], (int)$parts[1], (int)$parts[2]);
    } else {
        $event_display = '';
    }
} else {
    $event_display = '';
}

$data_file = dirname(__DIR__) . '/data/news.json';
$json      = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing  = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['news'])) {
    http_response_code(404);
    echo json_encode(['error' => 'データファイルが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$found = false;
foreach ($existing['news'] as &$item) {
    if (($item['id'] ?? '') === $id) {
        $item['category']   = $category;
        $item['title']      = $title;
        $item['detail']     = $detail;
        $item['url']        = $url;
        if ($event_display !== '') {
            $item['event_date'] = $event_display;
        }
        $found = true;
        break;
    }
}
unset($item);

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => '該当するIDが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

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
