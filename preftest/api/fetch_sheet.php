<?php
header('Content-Type: application/json; charset=utf-8');

/* ===== 認証 ===== */
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

/* ===== パラメータ ===== */
$sheet_url  = trim($_POST['sheet_url']  ?? '');
$sheet_name = trim($_POST['sheet_name'] ?? '');

/* ===== スプレッドシートID 抽出 ===== */
if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $sheet_url, $m)) {
    http_response_code(400);
    echo json_encode(['error' => '無効なスプレッドシートURLです。Google SpreadsheetのURLを入力してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$sheet_id = $m[1];

/* ===== CSV エクスポートURL 構築 ===== */
$csv_url = 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=csv';
if ($sheet_name !== '') {
    $csv_url .= '&sheet=' . urlencode($sheet_name);
}

/* ===== フェッチ (curl 優先 → file_get_contents フォールバック) ===== */
$csv = false;

if (function_exists('curl_init')) {
    $ch = curl_init($csv_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PHP-Fetch)',
    ]);
    $csv     = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($csv === false || $http_code >= 400) {
        $csv = false;
    }
}

if ($csv === false) {
    /* curl 未使用 or 失敗時 → file_get_contents */
    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 20,
            'follow_location' => true,
            'max_redirects'   => 5,
            'user_agent'      => 'Mozilla/5.0 (compatible; PHP-Fetch)',
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $csv = @file_get_contents($csv_url, false, $context);
}

if ($csv === false) {
    http_response_code(502);
    echo json_encode([
        'error' => 'スプレッドシートの取得に失敗しました。スプレッドシートが「リンクを知っている全員が閲覧可」に設定されているか確認してください。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== BOM 除去 ===== */
if (substr($csv, 0, 3) === "\xEF\xBB\xBF") {
    $csv = substr($csv, 3);
}

echo json_encode(['ok' => true, 'csv' => $csv], JSON_UNESCAPED_UNICODE);
