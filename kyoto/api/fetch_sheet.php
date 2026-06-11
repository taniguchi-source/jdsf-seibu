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

/* ===== 試行する CSV エクスポートURL リスト ===== */
$sheet_param = $sheet_name !== '' ? '&sheet=' . urlencode($sheet_name) : '';
$urls = [
    // 方法1: 標準エクスポートURL
    'export'     => 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=csv' . $sheet_param,
    // 方法2: gviz/tq エンドポイント（認証なしで動くことが多い）
    'gviz'       => 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/gviz/tq?tqx=out:csv' . $sheet_param,
    // 方法3: Pub URL（公開済みシート向け）
    'pub'        => 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/pub?output=csv' . $sheet_param,
];

$csv   = false;
$debug = [];

function try_curl(string $url): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => false, 'code' => 0, 'err' => 'curl無効', 'type' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PHP-Fetch/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: text/csv,text/plain,*/*'],
    ]);
    $body = curl_exec($ch);
    $info = [
        'ok'   => $body !== false,
        'body' => $body,
        'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'err'  => curl_error($ch),
        'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
    ];
    curl_close($ch);
    return $info;
}

function is_csv_content(string $body, string $content_type): bool {
    /* HTML（認証リダイレクト）を除外 */
    if (stripos($content_type, 'text/html') !== false) return false;
    if (stripos($body, '<!DOCTYPE') !== false)         return false;
    if (stripos($body, '<html')      !== false)         return false;
    return true;
}

/* ===== 各URLを順番に試す ===== */
foreach ($urls as $method => $url) {
    $r = try_curl($url);
    $log = "{$method}: code={$r['code']} err={$r['err']} type={$r['type']}";

    if ($r['ok'] && $r['code'] >= 200 && $r['code'] < 400 && is_csv_content($r['body'], $r['type'])) {
        $csv = $r['body'];
        $debug[] = $log . ' → OK';
        break;
    }
    $debug[] = $log . ' → NG';
}

/* ===== curl全滅 → file_get_contents フォールバック ===== */
if ($csv === false) {
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 20,
                'follow_location' => true,
                'max_redirects'   => 5,
                'user_agent'      => 'Mozilla/5.0 (compatible; PHP-Fetch/1.0)',
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        foreach ($urls as $method => $url) {
            $body = @file_get_contents($url, false, $context);
            if ($body !== false && is_csv_content($body, '')) {
                $csv = $body;
                $debug[] = "fgc:{$method} → OK";
                break;
            }
            $debug[] = "fgc:{$method} → NG";
        }
    } else {
        $debug[] = 'allow_url_fopen=off';
    }
}

/* ===== 全手段失敗 ===== */
if ($csv === false) {
    http_response_code(502);
    echo json_encode([
        'error' => 'スプレッドシートの取得に失敗しました。サーバーからGoogleへの接続が遮断されているか、URLが誤っている可能性があります。',
        'debug' => implode(' | ', $debug),
        'sheet_id' => $sheet_id,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== BOM 除去 ===== */
if (substr($csv, 0, 3) === "\xEF\xBB\xBF") {
    $csv = substr($csv, 3);
}

echo json_encode(['ok' => true, 'csv' => $csv, 'debug' => implode(' | ', $debug)], JSON_UNESCAPED_UNICODE);
