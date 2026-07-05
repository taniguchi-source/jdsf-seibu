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
if ($slot < 1 || $slot > 15) {
    http_response_code(400);
    echo json_encode(['error' => 'スロット番号は 1〜15 で指定してください'], JSON_UNESCAPED_UNICODE);
    exit;
}
$action = (string)($_POST['action'] ?? '');

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

// 既存 links は「配列順＝表示順」なので順序を保持したまま扱う（手動並べ替えを壊さない）
$links = array_values($data['links']);

// 既存 id 一覧
$existingIds = [];
foreach ($links as $lnk) { if (isset($lnk['id'])) $existingIds[(int)$lnk['id']] = true; }

// デフォルト5スロット保証（未存在のものだけ末尾に追加＝既存順は崩さない）
for ($i = 1; $i <= 5; $i++) {
    if (!isset($existingIds[$i])) {
        $links[] = ['id' => $i, 'enabled' => ($i <= 3), 'name' => $defaults[$i]['name'], 'url' => $defaults[$i]['url']];
        $existingIds[$i] = true;
    }
}

// 追加ページ（6以上）の削除。1〜5 は削除不可。順序は保持
if ($action === 'delete') {
    if ($slot > 5) {
        $links = array_values(array_filter($links, function ($lnk) use ($slot) {
            return (int)($lnk['id'] ?? 0) !== $slot;
        }));
    }
    $data['links']   = $links;
    $data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');
    file_put_contents($nav_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'deleted' => $slot], JSON_UNESCAPED_UNICODE);
    exit;
}

// このスロットの既定名・URL（6以上は page.html?id=◯）
$def_name = isset($defaults[$slot]) ? $defaults[$slot]['name'] : ('サイト' . $slot);
$def_url  = isset($defaults[$slot]) ? $defaults[$slot]['url']  : ('page.html?id=' . $slot);

// 対象スロットを「既存の並び順のまま」その場更新（無ければ末尾に追加）
$found = false;
foreach ($links as &$lnk) {
    if ((int)($lnk['id'] ?? 0) === $slot) {
        $lnk['id']      = $slot;
        $lnk['enabled'] = $enabled;
        $lnk['name']    = $name !== '' ? $name : $def_name;
        $lnk['url']     = $url  !== '' ? $url  : $def_url;
        if ($hero_label !== null) $lnk['hero_label'] = $hero_label;
        if ($hero_title !== null) $lnk['hero_title'] = $hero_title;
        if ($hero_desc  !== null) $lnk['hero_desc']  = $hero_desc;
        $found = true;
        break;
    }
}
unset($lnk);
if (!$found) {
    $newLink = ['id' => $slot, 'enabled' => $enabled,
                'name' => ($name !== '' ? $name : $def_name),
                'url'  => ($url  !== '' ? $url  : $def_url)];
    if ($hero_label !== null) $newLink['hero_label'] = $hero_label;
    if ($hero_title !== null) $newLink['hero_title'] = $hero_title;
    if ($hero_desc  !== null) $newLink['hero_desc']  = $hero_desc;
    $links[] = $newLink;
}

$data['links']   = array_values($links);
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($nav_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'slot' => $slot], JSON_UNESCAPED_UNICODE);
