<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
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
if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) { $url = ''; }

$data_file = dirname(__DIR__) . '/data/notices.json';
$json      = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing  = $json ? json_decode($json, true) : null;
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
echo json_encode(['ok' => true, 'entry' => $entry], JSON_UNESCAPED_UNICODE);
