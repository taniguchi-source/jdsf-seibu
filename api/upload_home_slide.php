<?php
// 主サイト トップのカルーセル画像（1〜3）をアップロード → uploads/home/ 保存
//   + data/home_hero.json の slides.slideN を更新（badge/desc/sns は保持）
header('Content-Type: application/json; charset=utf-8');

$token = $_POST['token'] ?? '';
if ($token !== 'jdsfseibu2026') {
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

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['image']['error'] ?? -1;
    http_response_code(400);
    echo json_encode(['error' => 'アップロードエラー (code:' . $code . ')'], JSON_UNESCAPED_UNICODE);
    exit;
}
$file = $_FILES['image'];

if ($file['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルサイズは 8MB 以内にしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
if (!array_key_exists($mime, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => '対応形式: JPG / PNG / WebP / GIF'], JSON_UNESCAPED_UNICODE);
    exit;
}
$ext = $allowed[$mime];

$upload_dir = dirname(__DIR__) . '/uploads/home/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// 既存ファイル（拡張子違いを含む）を削除
foreach ($allowed as $m => $e) {
    $old = $upload_dir . 'home-slide-' . $slide_num . '.' . $e;
    if (file_exists($old)) unlink($old);
}

$filename = 'home-slide-' . $slide_num . '.' . $ext;
$dest     = $upload_dir . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

// home_hero.json 更新（他項目は保持）
$hero_file = dirname(__DIR__) . '/data/home_hero.json';
$raw  = @file_get_contents($hero_file);
$hero = $raw ? @json_decode($raw, true) : [];
if (!is_array($hero)) $hero = [];
if (!isset($hero['slides']) || !is_array($hero['slides'])) $hero['slides'] = [];
$hero['slides']['slide' . $slide_num] = 'uploads/home/' . $filename;
$hero['updated'] = date('Y-m-d') . 'T' . date('H:i:s');
file_put_contents($hero_file, json_encode($hero, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'ok'        => true,
    'url'       => 'uploads/home/' . $filename,
    'slide_num' => $slide_num,
], JSON_UNESCAPED_UNICODE);
