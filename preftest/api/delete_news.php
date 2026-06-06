<?php
header('Content-Type: application/json; charset=utf-8');
$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') { http_response_code(403); echo json_encode(['error'=>'Forbidden'],JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$id = trim($_POST['id'] ?? '');
if ($id === '') { http_response_code(400); echo json_encode(['error'=>'IDが必要です'],JSON_UNESCAPED_UNICODE); exit; }
$data_file = dirname(__DIR__) . '/data/news.json';
$json = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : ['news' => []];
$existing['news'] = array_values(array_filter($existing['news'], function($n) use ($id){ return ($n['id']??'') !== $id; }));
$existing['updated'] = date('Y-m-d').'T'.date('H:i:s');
file_put_contents($data_file, json_encode($existing, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
