<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/_auth.php';
require_auth('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$id = trim($_POST['id'] ?? '');
if ($id === '') { http_response_code(400); echo json_encode(['error'=>'IDが必要です'],JSON_UNESCAPED_UNICODE); exit; }
$data_file = dirname(__DIR__) . '/data/notices.json';
$json = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing = $json ? json_decode($json, true) : ['notices' => []];
$existing['notices'] = array_values(array_filter($existing['notices'], function($n) use ($id){ return ($n['id']??'') !== $id; }));
file_put_contents($data_file, json_encode($existing, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
