<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/_auth.php';
require_auth('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$category   = trim($_POST['category']   ?? '');
$title      = trim($_POST['title']      ?? '');
$detail     = trim($_POST['detail']     ?? '');
$url        = trim($_POST['url']        ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$always_show = (($_POST['always_show'] ?? '') === '1');
// 日付の表示トグル（未送信は既定=表示）
$show_event = !isset($_POST['show_event_date']) ? true : ($_POST['show_event_date'] === '1');
$show_post  = !isset($_POST['show_post_date'])  ? true : ($_POST['show_post_date']  === '1');

if (!$category || !$title) {
    http_response_code(400);
    echo json_encode(['error' => 'カテゴリとタイトルは必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) { $url = ''; }

$today_display = date('Y.m.d');
// 実施日は任意。空欄なら event_date も空（ホームの「開催」を表示しない）
if ($event_date) {
    $parts = explode('-', $event_date);
    $event_display = count($parts) === 3
        ? sprintf('%s.%02d.%02d', $parts[0], (int)$parts[1], (int)$parts[2])
        : '';
} else {
    $event_display = '';
}

$data_file = dirname(__DIR__) . '/data/news.json';
$json      = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing  = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['news'])) { $existing = ['news' => []]; }

$entry = [
    'id'              => date('Ymd') . bin2hex(random_bytes(3)),
    'date'            => $today_display,   // 投稿日
    'edited_date'     => '',               // 未編集（編集時に update_news.php で設定）
    'event_date'      => $event_display,
    'category'        => $category,
    'title'           => $title,
    'detail'          => $detail,
    'url'             => $url,
    'always_show'     => $always_show,
    'show_event_date' => $show_event,
    'show_post_date'  => $show_post,
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
