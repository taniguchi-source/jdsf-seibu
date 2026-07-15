<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('build');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$file = dirname(__DIR__) . '/data/hero.json';
$raw  = @file_get_contents($file);
$data = $raw ? @json_decode($raw, true) : [];
if (!is_array($data)) $data = [];

// 高さ（指定された場合のみ更新）
if (isset($_POST['height'])) {
    $h = (string)$_POST['height'];
    $allowedH = ['five-fourths', 'standard', 'half', 'two-thirds', 'four-fifths', 'none'];
    $data['height'] = in_array($h, $allowedH, true) ? $h : 'standard';
}

// 横幅（指定された場合のみ更新）
if (isset($_POST['width'])) {
    $w = (string)$_POST['width'];
    $allowedW = ['full', 'standard', 'three-quarters', 'half', 'third'];
    $data['width'] = in_array($w, $allowedW, true) ? $w : 'standard';
}

// カルーセル矢印（手動切替）の表示ON/OFF（指定された場合のみ更新）
if (isset($_POST['arrows'])) {
    $data['arrows'] = ($_POST['arrows'] === '1' || $_POST['arrows'] === 'true' || $_POST['arrows'] === 1 || $_POST['arrows'] === true);
}

// 公式サイトバッジ（カルーセル左上の「○○県…公式サイト」）の表示ON/OFF（指定された場合のみ更新）
if (isset($_POST['badge_enabled'])) {
    $data['badge_enabled'] = ($_POST['badge_enabled'] === '1' || $_POST['badge_enabled'] === 'true' || $_POST['badge_enabled'] === 1 || $_POST['badge_enabled'] === true);
}

// ニュースティッカー 表示ON/OFF・本文（指定された場合のみ更新）
if (isset($_POST['ticker_enabled'])) {
    $data['ticker_enabled'] = ($_POST['ticker_enabled'] === '1' || $_POST['ticker_enabled'] === 'true' || $_POST['ticker_enabled'] === 1 || $_POST['ticker_enabled'] === true);
}
// ニュースティッカーを流す（横スクロール）ON/OFF（指定された場合のみ更新。既定＝流さない）
if (isset($_POST['ticker_scroll'])) {
    $data['ticker_scroll'] = ($_POST['ticker_scroll'] === '1' || $_POST['ticker_scroll'] === 'true' || $_POST['ticker_scroll'] === 1 || $_POST['ticker_scroll'] === true);
}
if (isset($_POST['ticker_text'])) {
    $data['ticker_text'] = mb_substr(trim((string)$_POST['ticker_text']), 0, 200);
}

// トップページに掲載するお知らせの件数（3 or 6。指定された場合のみ更新）
if (isset($_POST['news_count'])) {
    $data['news_count'] = ((int)$_POST['news_count'] === 6) ? 6 : 3;
}

// 公式SNS URL（http(s)以外は空にする。POSTされたキーのみ更新／既存は保持）
$snsKeys = ['facebook', 'instagram', 'line', 'youtube'];
$hasSnsPost = false;
foreach ($snsKeys as $k) { if (isset($_POST[$k])) { $hasSnsPost = true; break; } }
if ($hasSnsPost || (isset($data['sns']) && is_array($data['sns']))) {
    $sns = (isset($data['sns']) && is_array($data['sns'])) ? $data['sns'] : [];
    foreach ($snsKeys as $k) {
        if (isset($_POST[$k])) {
            $u = trim((string)$_POST[$k]);
            if ($u !== '' && !preg_match('/^https?:\/\//i', $u)) $u = '';
            $sns[$k] = mb_substr($u, 0, 300);
        }
    }
    $data['sns'] = $sns;
}

// 既定値の保証
if (!isset($data['height'])) $data['height'] = 'standard';
if (!isset($data['width']))  $data['width']  = 'standard';
if (!isset($data['arrows'])) $data['arrows'] = true; // 既定は表示
if (!isset($data['ticker_enabled'])) $data['ticker_enabled'] = true; // 既定は表示
if (!isset($data['badge_enabled'])) $data['badge_enabled'] = true; // 既定は表示
if (!isset($data['news_count'])) $data['news_count'] = 3; // 既定は3件
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルへの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'height' => $data['height'], 'width' => $data['width'], 'arrows' => $data['arrows']], JSON_UNESCAPED_UNICODE);
