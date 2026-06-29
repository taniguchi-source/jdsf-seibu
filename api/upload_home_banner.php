<?php
// 主サイト トップの広告バナー画像をアップロード → uploads/home/ 保存（URLを返すだけ。JSONは save_home_banner.php で永続化）
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

try { $rand = bin2hex(random_bytes(3)); }
catch (Exception $e) { $rand = (string)mt_rand(100000, 999999); }
$filename = 'banner-' . date('YmdHis') . '-' . $rand . '.' . $ext;
$dest     = $upload_dir . $filename;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'  => true,
    'url' => 'uploads/home/' . $filename,
], JSON_UNESCAPED_UNICODE);
