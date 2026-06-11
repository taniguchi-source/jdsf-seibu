<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$category   = trim($_POST['category']   ?? '');
$title      = trim($_POST['title']      ?? '');
$detail     = trim($_POST['detail']     ?? '');
$url        = trim($_POST['url']        ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$always_show = (($_POST['always_show'] ?? '') === '1');

if (!$category || !$title) {
    http_response_code(400);
    echo json_encode(['error' => 'カテゴリとタイトルは必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) { $url = ''; }

$today_display = date('Y.m.d');
if ($event_date) {
    $parts = explode('-', $event_date);
    $event_display = count($parts) === 3
        ? sprintf('%s.%02d.%02d', $parts[0], (int)$parts[1], (int)$parts[2])
        : $today_display;
} else {
    $event_display = $today_display;
}

$data_file = dirname(__DIR__) . '/data/news.json';
$json      = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing  = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['news'])) { $existing = ['news' => []]; }

$entry = [
    'id'          => date('Ymd') . bin2hex(random_bytes(3)),
    'date'        => $today_display,
    'event_date'  => $event_display,
    'category'    => $category,
    'title'       => $title,
    'detail'      => $detail,
    'url'         => $url,
    'always_show' => $always_show,
];
array_unshift($existing['news'], $entry);
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
echo json_encode(['ok' => true, 'entry' => $entry], JSON_UNESCAPED_UNICODE);
