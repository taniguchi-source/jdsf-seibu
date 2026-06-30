<?php
header('Content-Type: application/json; charset=utf-8');
$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') { http_response_code(403); echo json_encode(['error'=>'Forbidden'],JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$id = trim($_POST['id'] ?? '');
$category = trim($_POST['category'] ?? '');
$title    = trim($_POST['title']    ?? '');
$detail   = trim($_POST['detail']   ?? '');
$url      = trim($_POST['url']      ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$always_show = !empty($_POST['always_show']);
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
        if ($event_display !== '') $item['event_date'] = $event_display;
        $item['always_show'] = $always_show;
        $found = true; break;
    }
}
unset($item);
if (!$found) { http_response_code(404); echo json_encode(['error'=>'IDが見つかりません'],JSON_UNESCAPED_UNICODE); exit; }
$existing['updated'] = date('Y-m-d').'T'.date('H:i:s');
file_put_contents($data_file, json_encode($existing, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
