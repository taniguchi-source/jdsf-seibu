<?php
/**
 * アーカイブした特設サイトを、空いている枠（1〜5）へ復元する。
 * POST: archive_id, slot（1〜5・空き枠）
 * 認証: _auth.php（POST + 同一オリジン + CSRF + admin または build セッション）
 *
 * 復元すると：その枠へ設定とページ内容を書き戻し、アーカイブからは取り除く。
 * 「表示」はオフ・担当者パスワードは未設定の状態で戻す（必要なら役員が再設定）。
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/_auth.php';
require_auth_any(['admin', 'build']);

$archive_id = preg_replace('/[^a-f0-9]/i', '', (string)($_POST['archive_id'] ?? ''));
$slot = special_site_id($_POST['slot'] ?? '');
$keep = ((string)($_POST['keep'] ?? '') === '1');   // 1＝コピー（アーカイブに原本を残す）
if ($archive_id === '' || $slot === null) {
    http_response_code(400);
    echo json_encode(['error' => 'パラメータが不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dir           = dirname(__DIR__) . '/data';
$sites_file    = $dir . '/special_sites.json';
$contents_file = $dir . '/special_contents.json';
$archive_file  = $dir . '/special_sites_archive.json';

$sdata = is_file($sites_file)    ? json_decode((string)file_get_contents($sites_file), true)    : null;
$cdata = is_file($contents_file) ? json_decode((string)file_get_contents($contents_file), true) : null;
$adata = is_file($archive_file)  ? json_decode((string)file_get_contents($archive_file), true)  : null;
if (!is_array($sdata)) $sdata = [];
if (!is_array($cdata)) $cdata = [];
if (!is_array($adata) || !isset($adata['sites']) || !is_array($adata['sites'])) $adata = ['sites' => []];

/* アーカイブ該当分を探す */
$entry = null; $idx = -1;
foreach ($adata['sites'] as $i => $a) {
    if ((string)($a['archive_id'] ?? '') === $archive_id) { $entry = $a; $idx = $i; break; }
}
if ($entry === null) {
    http_response_code(404);
    echo json_encode(['error' => 'アーカイブが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 復元先の枠が空いているか確認（ボタン名・内容が無いこと） */
if (!isset($sdata['sites']) || !is_array($sdata['sites'])) $sdata['sites'] = [];
$slotMeta = null;
foreach ($sdata['sites'] as $s) {
    if ((string)($s['id'] ?? '') === $slot) { $slotMeta = $s; break; }
}
$slotLabel   = $slotMeta ? trim((string)($slotMeta['label'] ?? '')) : '';
$slotContent = (isset($cdata[$slot]) && is_array($cdata[$slot])) ? $cdata[$slot] : [];
if ($slotLabel !== '' || count($slotContent) > 0) {
    http_response_code(409);
    echo json_encode(['error' => 'その枠は使用中です。空いている枠を選んでください'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 枠のメタを書き戻す（idは復元先に合わせ、表示オフ・パスワード未設定で戻す） */
$m = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
$mode = (string)($m['mode'] ?? 'page');
if (!in_array($mode, ['page', 'embed', 'external'], true)) $mode = 'page';
$embed_height = (int)($m['embed_height'] ?? 1200);
if ($embed_height < 300 || $embed_height > 5000) $embed_height = 1200;
$restored = [
    'id'           => (int)$slot,
    'enabled'      => false,
    'done'         => false,
    'label'        => mb_substr(trim((string)($m['label'] ?? '')), 0, 40),
    'mode'         => $mode,
    'url'          => mb_substr(trim((string)($m['url'] ?? '')), 0, 500),
    'embed_url'    => mb_substr(trim((string)($m['embed_url'] ?? '')), 0, 500),
    'embed_height' => $embed_height,
    'hero_label'   => mb_substr(trim((string)($m['hero_label'] ?? '')), 0, 40),
    'hero_title'   => mb_substr(trim((string)($m['hero_title'] ?? '')), 0, 80),
    'hero_desc'    => mb_substr(trim((string)($m['hero_desc'] ?? '')), 0, 200),
    'has_password' => false,
];
$found = false;
foreach ($sdata['sites'] as &$s) {
    if ((string)($s['id'] ?? '') === $slot) { $s = $restored; $found = true; break; }
}
unset($s);
if (!$found) $sdata['sites'][] = $restored;
$stamp = date('Y-m-d') . 'T' . date('H:i:s');
$sdata['updated'] = $stamp;
file_put_contents($sites_file, json_encode($sdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

/* ページ内容を書き戻す */
$cdata[$slot] = is_array($entry['contents'] ?? null) ? $entry['contents'] : [];
file_put_contents($contents_file, json_encode($cdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

/* アーカイブから取り除く（コピー復元=keep のときは原本を残す） */
if (!$keep) {
    array_splice($adata['sites'], $idx, 1);
    $adata['updated'] = $stamp;
    file_put_contents($archive_file, json_encode($adata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

echo json_encode(['ok' => true, 'slot' => $slot, 'kept' => $keep], JSON_UNESCAPED_UNICODE);
