<?php
header('Content-Type: application/json; charset=utf-8');

// ── 認証 ──────────────────────────────────────────────────────
$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ── パラメータ ────────────────────────────────────────────────
$slide_num = (int)($_POST['slide_num'] ?? 0);
if ($slide_num < 1 || $slide_num > 3) {
    http_response_code(400);
    echo json_encode(['error' => 'スライド番号は 1〜3 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── ファイル受け取り ───────────────────────────────────────────
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['image']['error'] ?? -1;
    http_response_code(400);
    echo json_encode(['error' => 'アップロードエラー (code:' . $code . ')'], JSON_UNESCAPED_UNICODE);
    exit;
}
$file = $_FILES['image'];

// ── サイズチェック（最大 8MB） ─────────────────────────────────
if ($file['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルサイズは 8MB 以内にしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── MIMEタイプ検証 ────────────────────────────────────────────
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

// ── 保存先ディレクトリ ────────────────────────────────────────
$upload_dir = dirname(__DIR__) . '/uploads/slides/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ── 既存ファイルを削除（拡張子違いを含む） ───────────────────
foreach (array_keys($allowed) as $m) {
    $old = $upload_dir . 'slide-' . $slide_num . '.' . $allowed[$m];
    if (file_exists($old)) {
        unlink($old);
    }
}

// ── 保存 ─────────────────────────────────────────────────────
$filename = 'slide-' . $slide_num . '.' . $ext;
$dest     = $upload_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── slides.json 更新 ──────────────────────────────────────────
$slides_file = dirname(__DIR__) . '/data/slides.json';
$raw    = @file_get_contents($slides_file);
$slides = $raw ? @json_decode($raw, true) : [];
if (!isset($slides['slides'])) $slides['slides'] = [];

$slides['slides']['slide' . $slide_num] = 'uploads/slides/' . $filename;
$slides['updated'] = date('Y-m-d') . 'T' . date('H:i:s');
file_put_contents($slides_file, json_encode($slides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'ok'        => true,
    'url'       => 'uploads/slides/' . $filename,
    'slide_num' => $slide_num,
], JSON_UNESCAPED_UNICODE);
