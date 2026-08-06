<?php
/* 府県サイト共通の動的サイトマップ。
   アクセスされたホスト名で URL を生成するため、1ファイルで全府県サブドメインに対応する。
   /sitemap.xml から .htaccess 経由で呼ばれる（直接 /sitemap.php でも可）。 */
header('Content-Type: application/xml; charset=UTF-8');

$host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
if (!preg_match('/^[a-z0-9.\-]+$/', $host)) { $host = 'jdsf-seibu.com'; }
$base = 'https://' . $host . '/';

/* 常設の公開ページ */
$pages = array('', 'news.html', 'contact.html');

/* ナビで「表示中」の内部ページを追加（サーバー側 data/nav.json） */
$navFile = __DIR__ . '/data/nav.json';
if (is_file($navFile)) {
    $nav = json_decode((string)file_get_contents($navFile), true);
    if (isset($nav['links']) && is_array($nav['links'])) {
        foreach ($nav['links'] as $l) {
            if (empty($l['enabled'])) continue;
            $u = isset($l['url']) ? trim((string)$l['url']) : '';
            /* 内部の .html ページのみ許可（?id=6 のようなクエリ付きも可）。外部URL・javascript: 等は除外 */
            if (preg_match('#^[a-z0-9_\-]+\.html(\?[a-z0-9_\-=&]*)?$#i', $u)) {
                $pages[] = $u;
            }
        }
    }
}
$pages = array_values(array_unique($pages));

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $p) {
    $loc = htmlspecialchars($base . $p, ENT_QUOTES | ENT_XML1, 'UTF-8');
    echo '  <url><loc>' . $loc . '</loc></url>' . "\n";
}
echo '</urlset>' . "\n";
