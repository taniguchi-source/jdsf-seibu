<?php
header('Content-Type: application/json; charset=utf-8');

/* ===== 認証 ===== */
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

/* ===== ファイル確認 ===== */
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? 'none';
    http_response_code(400);
    echo json_encode(['error' => 'ファイルのアップロードに失敗しました (code:' . $errCode . ')'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file    = $_FILES['file'];
$tmpPath = $file['tmp_name'];
$origName = basename($file['name']);
$size    = (int)$file['size'];

/* ===== サイズ制限 (10 MB) ===== */
if ($size > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルサイズは 10MB 以下にしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== 拡張子チェック ===== */
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'jpg / png / gif / pdf のみアップロードできます'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== MIME タイプ確認 ===== */
$allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
if (function_exists('finfo_open')) {
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $tmpPath);
    finfo_close($fi);
    if (!in_array($mime, $allowedMime, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'ファイルの内容が許可されていない形式です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ===== 保存先 ===== */
$uploadDir = dirname(__DIR__) . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/* ===== ユニーク名 ===== */
$safeName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . $safeName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'      => true,
    'url'     => 'uploads/' . $safeName,
    'orig'    => $origName,
    'size'    => $size,
    'ext'     => $ext,
], JSON_UNESCAPED_UNICODE);
