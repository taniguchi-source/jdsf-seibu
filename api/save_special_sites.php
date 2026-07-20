<?php
/**
 * 主サイト 特設サイト（最大5つ）のスロット設定を保存 → data/special_sites.json
 * 府県連盟の「サイト1〜5」（preftest/data/nav.json）と同じ考え方だが、
 * 主サイト専用・府県とは完全に独立したデータを持つ。
 *
 * POST: sites（JSON配列・最大5件）
 * 認証: _auth.php（POST + 同一オリジン + CSRF + build セッション）
 */
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('build');

/** http(s) のURLだけを通す。javascript: 等は空にする */
function ss_safe_url($v, $max = 500) {
    $v = mb_substr(trim((string)$v), 0, $max);
    return preg_match('#^https?://#i', $v) ? $v : '';
}

$items_raw = $_POST['sites'] ?? '[]';
$items = @json_decode($items_raw, true);
if (!is_array($items)) $items = [];

$clean = [];
$i = 0;
foreach ($items as $item) {
    $i++;
    if ($i > 5) break;                     // スロットは5つまで
    if (!is_array($item)) $item = [];

    $mode = (string)($item['mode'] ?? 'page');
    if (!in_array($mode, ['page', 'embed', 'external'], true)) $mode = 'page';

    $embed_height = (int)($item['embed_height'] ?? 1200);
    if ($embed_height < 300 || $embed_height > 5000) $embed_height = 1200;

    $clean[] = [
        'id'           => $i,
        'enabled'      => !empty($item['enabled']),
        'label'        => mb_substr(trim((string)($item['label'] ?? '')), 0, 40),
        'mode'         => $mode,
        'url'          => ss_safe_url($item['url'] ?? ''),
        'embed_url'    => ss_safe_url($item['embed_url'] ?? ''),
        'embed_height' => $embed_height,
        'hero_label'   => mb_substr(trim((string)($item['hero_label'] ?? '')), 0, 40),
        'hero_title'   => mb_substr(trim((string)($item['hero_title'] ?? '')), 0, 80),
        'hero_desc'    => mb_substr(trim((string)($item['hero_desc']  ?? '')), 0, 200),
    ];
}

/* 5スロットに満たない分は空スロットで埋める（管理画面は常に5枠を描画する） */
for ($n = count($clean) + 1; $n <= 5; $n++) {
    $clean[] = [
        'id' => $n, 'enabled' => false, 'label' => '', 'mode' => 'page',
        'url' => '', 'embed_url' => '', 'embed_height' => 1200,
        'hero_label' => '', 'hero_title' => '', 'hero_desc' => '',
    ];
}

$file = dirname(__DIR__) . '/data/special_sites.json';
$data = ['sites' => $clean, 'updated' => date('Y-m-d') . 'T' . date('H:i:s')];

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
