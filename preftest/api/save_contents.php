<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$site_id = (string)($_POST['site_id'] ?? '');
$valid_site = ($site_id === 'top') || (ctype_digit($site_id) && (int)$site_id >= 1 && (int)$site_id <= 15);
if (!$valid_site) {
    http_response_code(400);
    echo json_encode(['error' => 'site_id は top または 1〜15 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items_raw = $_POST['items'] ?? '[]';
$items = @json_decode($items_raw, true);
if (!is_array($items)) $items = [];

$clean = [];
foreach ($items as $item) {
    $type_raw = (string)($item['type'] ?? 'text');
    // ギャラリー画像（最大10枚・各URLは500文字まで）
    $gallery_raw = $item['gallery'] ?? [];
    $gallery = [];
    if (is_array($gallery_raw)) {
        foreach ($gallery_raw as $g) {
            $g = mb_substr(trim((string)$g), 0, 500);
            if ($g !== '') $gallery[] = $g;
            if (count($gallery) >= 10) break;
        }
    }
    // 表示範囲（A1:F20 形式のみ許可。それ以外は空＝全体）
    $sheet_range = trim((string)($item['sheet_range'] ?? ''));
    if ($sheet_range !== '' && !preg_match('/^[A-Za-z]+[0-9]+:[A-Za-z]+[0-9]+$/', $sheet_range)) {
        $sheet_range = '';
    }
    // AI整形済みHTML（保存して公開ページで表示。念のため script/onイベントを除去）
    $sheet_html = (string)($item['sheet_html'] ?? '');
    if ($sheet_html !== '') {
        $sheet_html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $sheet_html);
        $sheet_html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $sheet_html);
        $sheet_html = mb_substr($sheet_html, 0, 200000);
    }
    $sheet_format = ((string)($item['sheet_format'] ?? '') === 'ai') ? 'ai' : '';
    // PDFのPC表示幅（%）。20〜100の整数。未指定/範囲外は100（＝全幅）
    $pdf_pc_width = (int)($item['pdf_pc_width'] ?? 100);
    if ($pdf_pc_width < 20)  $pdf_pc_width = 20;
    if ($pdf_pc_width > 100) $pdf_pc_width = 100;
    // 画像のPC表示幅（%）。20〜100の整数。未指定/範囲外は100（＝全幅）
    $img_pc_width = (int)($item['img_pc_width'] ?? 100);
    if ($img_pc_width < 20)  $img_pc_width = 20;
    if ($img_pc_width > 100) $img_pc_width = 100;
    // サイト埋め込み（iframe）。http(s) のみ許可。それ以外は空＝未設定
    $embed_url = mb_substr(trim((string)($item['embed_url'] ?? '')), 0, 500);
    if ($embed_url !== '' && !preg_match('#^https?://#i', $embed_url)) {
        $embed_url = '';
    }
    // 埋め込みの高さ(px)。300〜5000。未指定/範囲外は1200
    $embed_height = (int)($item['embed_height'] ?? 1200);
    if ($embed_height < 300)  $embed_height = 300;
    if ($embed_height > 5000) $embed_height = 5000;
    $clean[] = [
        'id'         => preg_replace('/[^a-z0-9_]/i', '', (string)($item['id'] ?? '')),
        'title'      => mb_substr(trim((string)($item['title'] ?? '')), 0, 60),
        'enabled'    => !empty($item['enabled']),
        'is_sub'     => !empty($item['is_sub']),
        'show_title' => array_key_exists('show_title', $item) ? !empty($item['show_title']) : true,
        'type'       => in_array($type_raw, ['text', 'sheet', 'file', 'image', 'gallery', 'pdf', 'link', 'embed'], true) ? $type_raw : 'text',
        'body'       => mb_substr(trim((string)($item['body'] ?? '')), 0, 5000),
        'title_size' => in_array(($item['title_size'] ?? ''), ['lg', 'xl'], true) ? (string)$item['title_size'] : '',
        'sheet_url'    => mb_substr(trim((string)($item['sheet_url']  ?? '')), 0, 500),
        'sheet_name'   => mb_substr(trim((string)($item['sheet_name'] ?? '')), 0, 100),
        'sheet_range'  => $sheet_range,
        'sheet_html'   => $sheet_html,
        'sheet_format' => $sheet_format,
        'file_url'   => mb_substr(trim((string)($item['file_url']   ?? '')), 0, 500),
        'pdf_pc_width' => $pdf_pc_width,
        'img_pc_width' => $img_pc_width,
        'link_url'   => mb_substr(trim((string)($item['link_url']   ?? '')), 0, 500),
        'link_label' => mb_substr(trim((string)($item['link_label'] ?? '')), 0, 100),
        'embed_url'    => $embed_url,
        'embed_height' => $embed_height,
        'gallery'    => $gallery,
    ];
}

$file = dirname(__DIR__) . '/data/contents.json';
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
