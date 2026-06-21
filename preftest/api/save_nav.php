<?php
header('Content-Type: application/json; charset=utf-8');

$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$slot = (int)($_POST['slot'] ?? 0);
if ($slot < 1 || $slot > 5) {
    http_response_code(400);
    echo json_encode(['error' => 'スロット番号は 1〜5 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$enabled = !empty($_POST['enabled']);
$name    = trim($_POST['name'] ?? '');
$url     = trim($_POST['url']  ?? '');
// ヒーロー（ページ上部）の表示文字。送信時のみ更新（空文字＝自動表示に戻す）
$hero_label = isset($_POST['hero_label']) ? mb_substr(trim((string)$_POST['hero_label']), 0, 40)  : null;
$hero_title = isset($_POST['hero_title']) ? mb_substr(trim((string)$_POST['hero_title']), 0, 80)  : null;
$hero_desc  = isset($_POST['hero_desc'])  ? mb_substr(trim((string)$_POST['hero_desc']),  0, 200) : null;

$nav_file = dirname(__DIR__) . '/data/nav.json';
$raw  = @file_get_contents($nav_file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data))         $data = [];
if (!isset($data['links']))   $data['links'] = [];

// デフォルト5スロット保証
$defaults = [
    1 => ['name' => 'サイト1', 'url' => 'page1.html'],
    2 => ['name' => 'サイト2', 'url' => 'page2.html'],
    3 => ['name' => 'サイト3', 'url' => 'page3.html'],
    4 => ['name' => 'サイト4', 'url' => 'page4.html'],
    5 => ['name' => 'サイト5', 'url' => 'page5.html'],
];

// links を id キーで再構築
$keyed = [];
foreach ($data['links'] as $link) {
    if (isset($link['id'])) $keyed[(int)$link['id']] = $link;
}
for ($i = 1; $i <= 5; $i++) {
    if (!isset($keyed[$i])) {
        $keyed[$i] = ['id' => $i, 'enabled' => ($i <= 3), 'name' => $defaults[$i]['name'], 'url' => $defaults[$i]['url']];
    }
}

// 対象スロットを更新
$keyed[$slot]['enabled'] = $enabled;
$keyed[$slot]['name']    = $name !== '' ? $name : $defaults[$slot]['name'];
$keyed[$slot]['url']     = $url  !== '' ? $url  : $defaults[$slot]['url'];
if ($hero_label !== null) $keyed[$slot]['hero_label'] = $hero_label;
if ($hero_title !== null) $keyed[$slot]['hero_title'] = $hero_title;
if ($hero_desc  !== null) $keyed[$slot]['hero_desc']  = $hero_desc;

// id 順に並べ直す
ksort($keyed);
$data['links']   = array_values($keyed);
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($nav_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'slot' => $slot], JSON_UNESCAPED_UNICODE);
