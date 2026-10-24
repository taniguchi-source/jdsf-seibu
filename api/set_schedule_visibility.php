<?php
/**
 * 競技予定調整表の公開／非公開を切り替える（役員ページ専用）。
 * POST: visible（1=公開 / 0=非公開）
 * 認証: _auth.php（POST + 同一オリジン + CSRF + admin または build セッション）
 *
 * data/schedule_visibility.json = {"visible": true|false, "updated": ...}
 * ファイルが無い＝非公開（引退）が既定。公開にするとこのファイルが作られる。
 * 競技予定調整表のデータ（schedule.json）は消さないので、いつでも復元・再利用できる。
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/_auth.php';
require_auth_any(['admin', 'build']);

$visible = ((string)($_POST['visible'] ?? '') === '1');

$file = dirname(__DIR__) . '/data/schedule_visibility.json';
$data = ['visible' => $visible, 'updated' => date('Y-m-d') . 'T' . date('H:i:s')];

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => '保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'visible' => $visible], JSON_UNESCAPED_UNICODE);
