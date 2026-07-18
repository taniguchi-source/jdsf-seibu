<?php
/**
 * 公開お知らせ 投稿API
 * POST: token, category, title, detail, url, event_date, always_show
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

$category    = trim($_POST['category']    ?? '');
$title       = trim($_POST['title']       ?? '');
$detail      = trim($_POST['detail']      ?? '');
$url         = trim($_POST['url']         ?? '');
$event_date  = trim($_POST['event_date']  ?? '');
$always_show = (($_POST['always_show'] ?? '') === '1');
$important   = (($_POST['important']   ?? '') === '1');
$attachments = news_sanitize_attachments($_POST['attachments'] ?? '');

if (!$category || !$title) {
    http_response_code(400);
    echo json_encode(['error' => 'カテゴリとタイトルは必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) {
    $url = '';
}

// 今日の日付（表示用）
$today_display = date('Y.m.d');

// 実施日の処理（空欄は空のまま保存＝自動削除は公開日+90日で判定）
$event_display = '';
if ($event_date) {
    $parts = explode('-', $event_date);
    if (count($parts) === 3) {
        $event_display = sprintf('%s.%02d.%02d', $parts[0], (int)$parts[1], (int)$parts[2]);
    }
}

$data_file = dirname(__DIR__) . '/data/news.json';
$json     = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['news'])) {
    $existing = ['news' => []];
}

$id = date('Ymd') . bin2hex(random_bytes(3));

$entry = [
    'id'          => $id,
    'date'        => $today_display,
    'event_date'  => $event_display,
    'category'    => $category,
    'title'       => $title,
    'detail'      => $detail,
    'url'         => $url,
    'always_show' => $always_show,
    'important'   => $important,
    'attachments' => $attachments,
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

// GASでメーリングリストに通知（サーバーサイド）
$gas_url = 'https://script.google.com/macros/s/AKfycbxMV9fLxcCrFn6cxjnOwGXDzEk2cP1pONhEsybIjo_sbe2xh0R2oNqZKsbNaPr1QwrO/exec';
$gas_params = http_build_query([
    'name'   => '公開お知らせ管理',
    'title'  => '[' . $category . '] ' . $title,
    'detail' => $detail . ($event_date ? "\n\n実施日：" . $event_display : ''),
    'url'    => $url,
]);
if (function_exists('curl_init')) {
    $ch = curl_init($gas_url . '?' . $gas_params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    @curl_exec($ch);
    curl_close($ch);
}

echo json_encode(['ok' => true, 'entry' => $entry], JSON_UNESCAPED_UNICODE);
