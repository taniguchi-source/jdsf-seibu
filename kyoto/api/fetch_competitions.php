<?php
/* 本番サイトの競技会データ(competitions_seibu.json)をサーバー側で取得して返すプロキシ。
   preftest は別サブドメインのためブラウザから直接取得できない(CORS)のを回避する。 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$url = 'https://jdsf-seibu.com/data/competitions_seibu.json';
$json = false;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; preftest-proxy/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false && $code >= 200 && $code < 400) $json = $body;
}

if ($json === false && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0 (compatible; preftest-proxy/1.0)'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body !== false) $json = $body;
}

if ($json === false) {
    http_response_code(502);
    echo json_encode(['error' => '競技会データの取得に失敗しました', 'competitions' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

/* BOM除去してそのまま返す（既にJSON） */
if (substr($json, 0, 3) === "\xEF\xBB\xBF") $json = substr($json, 3);
echo $json;
