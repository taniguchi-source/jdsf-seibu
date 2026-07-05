<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/_auth.php';
require_auth('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$id = trim($_POST['id'] ?? '');
$category = trim($_POST['category'] ?? '');
$title    = trim($_POST['title']    ?? '');
$detail   = trim($_POST['detail']   ?? '');
$url      = trim($_POST['url']      ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$always_show = !empty($_POST['always_show']);
// 日付の表示トグル（未送信は既定=表示）
$show_event = !isset($_POST['show_event_date']) ? true : ($_POST['show_event_date'] === '1');
$show_post  = !isset($_POST['show_post_date'])  ? true : ($_POST['show_post_date']  === '1');
if ($id === '' || !$category || !$title || !$detail) {
    http_response_code(400); echo json_encode(['error'=>'必須項目が不足しています'],JSON_UNESCAPED_UNICODE); exit;
}
if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) { $url = ''; }
if ($event_date) {
    $parts = explode('-', $event_date);
    $event_display = count($parts) === 3 ? sprintf('%s.%02d.%02d',$parts[0],(int)$parts[1],(int)$parts[2]) : '';
} else { $event_display = ''; }
$data_file = dirname(__DIR__) . '/data/news.json';
$json = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : ['news' => []];
$found = false;
foreach ($existing['news'] as &$item) {
    if (($item['id'] ?? '') === $id) {
        $item['category'] = $category; $item['title'] = $title;
        $item['detail'] = $detail; $item['url'] = $url;
        $item['event_date'] = $event_display; // 空欄で保存＝実施日をクリア（ホームの「開催」も非表示に）
        $item['always_show'] = $always_show;
        $item['show_event_date'] = $show_event;
        $item['show_post_date']  = $show_post;
        $item['edited_date'] = date('Y.m.d'); // 編集日を記録（ホームでは「編集 ○」と表示）
        $found = true; break;
    }
}
unset($item);
if (!$found) { http_response_code(404); echo json_encode(['error'=>'IDが見つかりません'],JSON_UNESCAPED_UNICODE); exit; }
$existing['updated'] = date('Y-m-d').'T'.date('H:i:s');
file_put_contents($data_file, json_encode($existing, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
