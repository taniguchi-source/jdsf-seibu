<?php
/**
 * 特設サイト（1〜5）を削除してアーカイブへ退避する。
 * - 現在のスロット設定（special_sites.json の該当id）とページ内容
 *   （special_contents.json[id]）を data/special_sites_archive.json に保存。
 * - そのうえでスロットを空に戻し、内容を空にし、担当者パスワードを解除する。
 * - 添付・画像ファイルの実体は消さない（アーカイブであとで見られるようにするため）。
 *
 * POST: site_id（1〜5）
 * 認証: _auth.php（POST + 同一オリジン + CSRF + admin または build セッション）
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/_auth.php';
require_auth_any(['admin', 'build']);

$id = special_site_id($_POST['site_id'] ?? '');
if ($id === null) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id は 1〜5 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir           = dirname(__DIR__) . '/data';
$sites_file    = $dir . '/special_sites.json';
$contents_file = $dir . '/special_contents.json';
$archive_file  = $dir . '/special_sites_archive.json';

$sdata = is_file($sites_file)    ? json_decode((string)file_get_contents($sites_file), true)    : null;
$cdata = is_file($contents_file) ? json_decode((string)file_get_contents($contents_file), true) : null;
if (!is_array($sdata)) $sdata = [];
if (!is_array($cdata)) $cdata = [];

/* いま入っているスロット設定とページ内容を取り出す */
$meta = null;
if (isset($sdata['sites']) && is_array($sdata['sites'])) {
    foreach ($sdata['sites'] as $s) {
        if ((string)($s['id'] ?? '') === $id) { $meta = $s; break; }
    }
}
$contents = (isset($cdata[$id]) && is_array($cdata[$id])) ? $cdata[$id] : [];

/* アーカイブへ退避（最新200件まで） */
$adata = is_file($archive_file) ? json_decode((string)file_get_contents($archive_file), true) : null;
if (!is_array($adata) || !isset($adata['sites']) || !is_array($adata['sites'])) $adata = ['sites' => []];
$stamp = date('Y-m-d') . 'T' . date('H:i:s');
$adata['sites'][] = [
    'archive_id'  => bin2hex(random_bytes(6)),
    'archived_at' => $stamp,
    'site_id'     => (int)$id,
    'meta'        => is_array($meta) ? $meta : [],
    'contents'    => $contents,
];
if (count($adata['sites']) > 200) $adata['sites'] = array_slice($adata['sites'], -200);
$adata['updated'] = $stamp;
if (file_put_contents($archive_file, json_encode($adata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'アーカイブの保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* スロットを空に戻す（has_password も解除の印にする） */
$empty = [
    'id' => (int)$id, 'enabled' => false, 'done' => false, 'label' => '', 'mode' => 'page',
    'url' => '', 'embed_url' => '', 'embed_height' => 1200,
    'hero_label' => '', 'hero_title' => '', 'hero_desc' => '', 'has_password' => false,
];
if (!isset($sdata['sites']) || !is_array($sdata['sites'])) $sdata['sites'] = [];
$found = false;
foreach ($sdata['sites'] as &$s) {
    if ((string)($s['id'] ?? '') === $id) { $s = $empty; $found = true; break; }
}
unset($s);
if (!$found) $sdata['sites'][] = $empty;
$sdata['updated'] = $stamp;
file_put_contents($sites_file, json_encode($sdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

/* ページ内容を空にする */
$cdata[$id] = [];
file_put_contents($contents_file, json_encode($cdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

/* 担当者パスワードを解除する */
$auth = load_special_auth();
unset($auth[$id]);
save_special_auth($auth);

echo json_encode(['ok' => true, 'site_id' => $id], JSON_UNESCAPED_UNICODE);
