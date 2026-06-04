<?php
/**
 * 役員からのお知らせ 投稿API
 * POST: token, name, title, detail, url
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 簡易トークン認証（ブルートフォース防止）
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

$name   = trim($_POST['name']   ?? '');
$title  = trim($_POST['title']  ?? '');
$detail = trim($_POST['detail'] ?? '');
$url    = trim($_POST['url']    ?? '');

if ($name === '' || $title === '') {
    http_response_code(400);
    echo json_encode(['error' => '氏名とタイトルは必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

// URLの簡易バリデーション
if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) {
    $url = ''; // 不正なURLは無視
}

$data_file = dirname(__DIR__) . '/data/officers_notices.json';

$json     = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['notices'])) {
    $existing = ['notices' => []];
}

$entry = [
    'id'     => bin2hex(random_bytes(8)),
    'date'   => date('Y-m-d'),
    'name'   => $name,
    'title'  => $title,
    'detail' => $detail,
    'url'    => $url,
];

array_unshift($existing['notices'], $entry);

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
    'name'   => $name,
    'title'  => $title,
    'detail' => $detail,
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
