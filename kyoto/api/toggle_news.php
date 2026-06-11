<?php
header('Content-Type: application/json; charset=utf-8');
$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') { http_response_code(403); echo json_encode(['error'=>'Forbidden'],JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$id = trim($_POST['id'] ?? '');
$always_show = (($_POST['always_show'] ?? '') === '1');
if ($id === '') { http_response_code(400); echo json_encode(['error'=>'IDが必要です'],JSON_UNESCAPED_UNICODE); exit; }
$data_file = dirname(__DIR__) . '/data/news.json';
$json = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : ['news' => []];
$found = false;
foreach ($existing['news'] as &$item) {
    if (($item['id'] ?? '') === $id) { $item['always_show'] = $always_show; $found = true; break; }
}
unset($item);
if (!$found) { http_response_code(404); echo json_encode(['error'=>'IDが見つかりません'],JSON_UNESCAPED_UNICODE); exit; }
$existing['updated'] = date('Y-m-d').'T'.date('H:i:s');
file_put_contents($data_file, json_encode($existing, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true, 'always_show' => $always_show], JSON_UNESCAPED_UNICODE);
