<?php
/**
 * 削除した特設サイトのアーカイブ取得API（役員ページ専用・読み取り）
 * admin / build のセッションが無ければ 403。公開側からは見えない。
 * data/special_sites_archive.json への直接アクセスは data/.htaccess で禁止。
 */
require __DIR__ . '/_auth.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['auth']['admin']) && empty($_SESSION['auth']['build'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file  = dirname(__DIR__) . '/data/special_sites_archive.json';
$json  = is_file($file) ? file_get_contents($file) : '';
$data  = $json ? json_decode($json, true) : null;
$sites = (is_array($data) && isset($data['sites']) && is_array($data['sites'])) ? $data['sites'] : [];

echo json_encode(['ok' => true, 'sites' => $sites], JSON_UNESCAPED_UNICODE);
