<?php
// 府県 お問い合わせページ（contact.html）のヒーロー表示文字を保存 → data/contact_hero.json
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

$label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 40);
$title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 80);
$desc  = mb_substr(trim((string)($_POST['desc']  ?? '')), 0, 200);

$file = dirname(__DIR__) . '/data/contact_hero.json';
$data = ['label' => $label, 'title' => $title, 'desc' => $desc, 'updated' => date('Y-m-d') . 'T' . date('H:i:s')];

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
