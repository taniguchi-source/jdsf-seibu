<?php
$id       = $_GET['id']       ?? '';
$password = $_GET['password'] ?? '';

$json_path = __DIR__ . '/../data/references.json';
$data = json_decode(file_get_contents($json_path), true);

/* ── 該当アイテムを検索 ── */
$item = null;
foreach (($data['references'] ?? []) as $ref) {
    if (($ref['id'] ?? '') === $id) { $item = $ref; break; }
}

if (!$item) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ファイルが見つかりません');
}

/* ── パスワード検証 ── */
if (!empty($item['download_password'])) {
    if ($item['download_password'] !== $password) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'パスワードが正しくありません']));
    }
}

/* ── ファイル配信 ── */
$url = $item['url'] ?? '';
if (empty($url)) {
    http_response_code(404);
    exit('URLが設定されていません');
}

// URLリンクの場合はリダイレクト
if (preg_match('/^https?:\/\//', $url)) {
    header('Location: ' . $url);
    exit;
}

// アップロードファイルの配信
$file_path = realpath(__DIR__ . '/../' . $url);
$base_dir  = realpath(__DIR__ . '/../uploads/references/');

// パストラバーサル対策
if (!$file_path || strpos($file_path, $base_dir) !== 0 || !file_exists($file_path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ファイルが存在しません');
}

$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$mime_types = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'zip'  => 'application/zip',
];
$mime = $mime_types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode(basename($file_path)));
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: no-cache');
readfile($file_path);
