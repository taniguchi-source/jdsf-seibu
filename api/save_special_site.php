<?php
/**
 * 特設サイトの「1スロットだけ」を保存する（担当者の編集画面 special-edit.html 用）。
 *
 * 更新するのは label / mode / url / embed_url / embed_height / hero_* のみ。
 * enabled（公開するかどうか）と has_password は既存の値をそのまま残すので、
 * 担当者が勝手に公開・非公開を切り替えたりパスワードの印を消したりはできない。
 * 他のスロットには一切触れない。
 *
 * POST: site_id（1〜5）, label, mode, url, embed_url, embed_height, hero_label, hero_title, hero_desc
 * 認証: _auth.php の require_special_auth()（admin / build / そのサイトの special）
 */
header('Content-Type: application/json; charset=utf-8');

$site_id_raw = $_POST['site_id'] ?? '';

require __DIR__ . '/_auth.php';
require_special_auth($site_id_raw);

$id = special_site_id($site_id_raw);
if ($id === null) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id は 1〜5 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** http(s) のURLだけを通す。javascript: 等は空にする */
function ssone_safe_url($v, $max = 500) {
    $v = mb_substr(trim((string)$v), 0, $max);
    return preg_match('#^https?://#i', $v) ? $v : '';
}

$mode = (string)($_POST['mode'] ?? 'page');
if (!in_array($mode, ['page', 'embed', 'external'], true)) $mode = 'page';

$embed_height = (int)($_POST['embed_height'] ?? 1200);
if ($embed_height < 300 || $embed_height > 5000) $embed_height = 1200;

$file = dirname(__DIR__) . '/data/special_sites.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data) || !isset($data['sites']) || !is_array($data['sites'])) {
    http_response_code(500);
    echo json_encode(['error' => '特設サイトの設定が見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$found = false;
foreach ($data['sites'] as &$s) {
    if ((string)($s['id'] ?? '') !== $id) continue;
    $found = true;
    /* enabled と has_password は触らない */
    $s['label']        = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 40);
    $s['mode']         = $mode;
    $s['url']          = ssone_safe_url($_POST['url'] ?? '');
    $s['embed_url']    = ssone_safe_url($_POST['embed_url'] ?? '');
    $s['embed_height'] = $embed_height;
    $s['hero_label']   = mb_substr(trim((string)($_POST['hero_label'] ?? '')), 0, 40);
    $s['hero_title']   = mb_substr(trim((string)($_POST['hero_title'] ?? '')), 0, 80);
    $s['hero_desc']    = mb_substr(trim((string)($_POST['hero_desc']  ?? '')), 0, 200);
    break;
}
unset($s);

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => '対象の特設サイトが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'site_id' => $id], JSON_UNESCAPED_UNICODE);
