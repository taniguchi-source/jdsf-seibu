<?php
// 主サイト トップのヒーロー（キャッチコピー badge・説明文 desc・SNSリンク）を保存
//   → data/home_hero.json（slides[] は upload_home_slide.php が更新するため保持）
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('build');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$file = dirname(__DIR__) . '/data/home_hero.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data)) $data = [];

if (isset($_POST['badge'])) $data['badge'] = mb_substr(trim((string)$_POST['badge']), 0, 120);
if (isset($_POST['desc']))  $data['desc']  = mb_substr(trim((string)$_POST['desc']), 0, 400);

// SNS URL（http(s)以外は空にする）
$sns = (isset($data['sns']) && is_array($data['sns'])) ? $data['sns'] : [];
foreach (['facebook', 'instagram', 'line', 'youtube'] as $k) {
    if (isset($_POST[$k])) {
        $u = trim((string)$_POST[$k]);
        if ($u !== '' && !preg_match('/^https?:\/\//i', $u)) $u = '';
        $sns[$k] = mb_substr($u, 0, 300);
    }
}
$data['sns'] = $sns;

if (!isset($data['slides']) || !is_array($data['slides'])) $data['slides'] = [];
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
