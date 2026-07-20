<?php
/**
 * 特設サイト（1〜5）ごとのパスワードを設定／解除する。
 * 役員用ページ・サイト構築ページからのみ操作できる（担当者本人は変更できない）。
 *
 * POST: site_id（1〜5）, password（8文字以上）／ action=clear で解除
 * 認証: _auth.php（POST + 同一オリジン + CSRF + admin または build セッション）
 */
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth_any(['admin', 'build']);

$id = special_site_id($_POST['site_id'] ?? '');
if ($id === null) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id は 1〜5 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clear = ((string)($_POST['action'] ?? '') === 'clear');
$auth  = load_special_auth();

if ($clear) {
    unset($auth[$id]);
} else {
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'パスワードは8文字以上にしてください'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $auth[$id] = password_hash($pw, PASSWORD_DEFAULT);
}

if (!save_special_auth($auth)) {
    http_response_code(500);
    echo json_encode(['error' => 'パスワードの保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 公開ページが「編集ボタンを出すか」を判断できるよう、
   ハッシュではなく has_password の印だけを special_sites.json に反映する。 */
$file = dirname(__DIR__) . '/data/special_sites.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (is_array($data) && isset($data['sites']) && is_array($data['sites'])) {
    foreach ($data['sites'] as &$s) {
        if ((string)($s['id'] ?? '') === $id) $s['has_password'] = !$clear;
    }
    unset($s);
    $data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo json_encode(['ok' => true, 'site_id' => $id, 'has_password' => !$clear], JSON_UNESCAPED_UNICODE);
