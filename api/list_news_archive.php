<?php
/**
 * 公開お知らせ アーカイブ 取得API（役員ページ専用・読み取り）
 *
 * 期限切れで公開から外れたお知らせ（cleanup_news.php が data/news_archive.json へ
 * 退避したもの）を返す。admin / build のセッションが無ければ 403。
 * ＝役員ページにログイン中の担当者だけが閲覧できる（公開側からは見えない）。
 *
 * 読み取り専用なので GET で可。data/news_archive.json への直接アクセスは
 * data/.htaccess で禁止しているため、閲覧はこのAPI経由に限られる。
 */
require __DIR__ . '/_auth.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['auth']['admin']) && empty($_SESSION['auth']['build'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = dirname(__DIR__) . '/data/news_archive.json';
$json = is_file($file) ? file_get_contents($file) : '';
$data = $json ? json_decode($json, true) : null;
$news = (is_array($data) && isset($data['news']) && is_array($data['news'])) ? $data['news'] : [];

echo json_encode(['ok' => true, 'news' => $news], JSON_UNESCAPED_UNICODE);
