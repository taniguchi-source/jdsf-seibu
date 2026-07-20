<?php
/**
 * 主サイト 特設サイトの本文ブロックを保存 → data/special_contents.json の該当スロット
 * 府県連盟の preftest/api/save_contents.php を土台に、主サイトで使う5種
 * （text / image / pdf / link / embed）だけに絞ったもの。
 * スプレッドシート連携・ギャラリーは扱わない。
 *
 * POST: site_id（1〜5）, items（JSON配列）
 * 認証: _auth.php（POST + 同一オリジン + CSRF + build セッション）
 */
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('build');

/** http(s) のURLだけを通す */
function sc_safe_url($v, $max = 500) {
    $v = mb_substr(trim((string)$v), 0, $max);
    return preg_match('#^https?://#i', $v) ? $v : '';
}

/** アップロード済みファイル（uploads/…）か http(s) のURLだけを通す */
function sc_safe_file_url($v, $max = 500) {
    $v = mb_substr(trim((string)$v), 0, $max);
    if ($v === '') return '';
    if (preg_match('#^uploads/[A-Za-z0-9._/-]+$#', $v) && strpos($v, '..') === false) return $v;
    return preg_match('#^https?://#i', $v) ? $v : '';
}

$site_id = (string)($_POST['site_id'] ?? '');
if (!ctype_digit($site_id) || (int)$site_id < 1 || (int)$site_id > 5) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id は 1〜5 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items_raw = $_POST['items'] ?? '[]';
$items = @json_decode($items_raw, true);
if (!is_array($items)) $items = [];

$clean = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;
    if (count($clean) >= 30) break;                    // 1ページあたりのブロック上限

    $type = (string)($item['type'] ?? 'text');
    if (!in_array($type, ['text', 'image', 'pdf', 'link', 'embed'], true)) $type = 'text';

    // PDFのPC表示幅（%）。20〜100。範囲外は100（＝全幅）
    $pdf_pc_width = (int)($item['pdf_pc_width'] ?? 100);
    if ($pdf_pc_width < 20)  $pdf_pc_width = 20;
    if ($pdf_pc_width > 100) $pdf_pc_width = 100;

    // 埋め込みの高さ(px)。300〜5000。範囲外は1200
    $embed_height = (int)($item['embed_height'] ?? 1200);
    if ($embed_height < 300)  $embed_height = 300;
    if ($embed_height > 5000) $embed_height = 5000;

    $clean[] = [
        'id'           => preg_replace('/[^a-z0-9_]/i', '', (string)($item['id'] ?? '')),
        'title'        => mb_substr(trim((string)($item['title'] ?? '')), 0, 60),
        'enabled'      => !empty($item['enabled']),
        'show_title'   => array_key_exists('show_title', $item) ? !empty($item['show_title']) : true,
        'type'         => $type,
        'body'         => mb_substr(trim((string)($item['body'] ?? '')), 0, 5000),
        'file_url'     => sc_safe_file_url($item['file_url'] ?? ''),
        'pdf_pc_width' => $pdf_pc_width,
        'link_url'     => sc_safe_url($item['link_url'] ?? ''),
        'link_label'   => mb_substr(trim((string)($item['link_label'] ?? '')), 0, 100),
        'embed_url'    => sc_safe_url($item['embed_url'] ?? ''),
        'embed_height' => $embed_height,
    ];
}

$file = dirname(__DIR__) . '/data/special_contents.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data)) $data = [];

$data[$site_id] = $clean;

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'site_id' => $site_id], JSON_UNESCAPED_UNICODE);
