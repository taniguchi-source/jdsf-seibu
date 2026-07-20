<?php
/**
 * 指定したURLが「他サイトへの埋め込み」を許可しているかを調べる。
 *
 * 相手サーバーが X-Frame-Options や Content-Security-Policy(frame-ancestors) で
 * 埋め込みを拒否していると、iframe は灰色のまま何も表示されない。
 * 設定画面でその場で気づけるように、保存前に確認するために使う。
 *
 * POST: url
 * 認証: admin / build / いずれかの特設サイト担当者
 * 返り値: { ok, embeddable:bool, reason:string, status:int }
 *
 * 注意: サーバーが外部へリクエストを出すため、社内ネットワークを覗く踏み台
 *       （SSRF）にならないよう、http(s) のみ・私的IPアドレス宛を拒否・
 *       リダイレクト追跡なし・本文は取得しない、の制限をかけている。
 */
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_upload_auth();

$url = trim((string)($_POST['url'] ?? ''));
if (!preg_match('#^https?://#i', $url)) {
    echo json_encode(['ok' => false, 'error' => 'URLは https:// から入力してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

$host = parse_url($url, PHP_URL_HOST);
if (!$host) {
    echo json_encode(['ok' => false, 'error' => 'URLを読み取れませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 私的IP・ループバック宛は拒否（踏み台対策） */
$ips = @gethostbynamel($host);
if (!$ips) {
    echo json_encode(['ok' => false, 'error' => 'このアドレスは見つかりませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}
foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        echo json_encode(['ok' => false, 'error' => 'このアドレスは指定できません'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('curl_init')) {
    echo json_encode(['ok' => false, 'error' => 'この環境では確認できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_NOBODY         => true,       // 本文は取らない（ヘッダーだけ）
    CURLOPT_HEADER         => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,      // リダイレクトは追わない
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'JDSF-Seibu embed check',
]);
$raw    = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $status === 0) {
    echo json_encode(['ok' => false, 'error' => 'ページに接続できませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ヘッダーを小文字キーの配列にする */
$headers = [];
foreach (preg_split("/\r?\n/", $raw) as $line) {
    $p = strpos($line, ':');
    if ($p === false) continue;
    $k = strtolower(trim(substr($line, 0, $p)));
    $v = trim(substr($line, $p + 1));
    $headers[$k] = isset($headers[$k]) ? ($headers[$k] . ' ' . $v) : $v;
}

$xfo = strtolower($headers['x-frame-options'] ?? '');
$csp = strtolower($headers['content-security-policy'] ?? '');

$embeddable = true;
$reason     = '';

if ($xfo !== '') {
    if (strpos($xfo, 'deny') !== false) {
        $embeddable = false;
        $reason = 'このサイトは他サイトへの埋め込みを禁止しています（X-Frame-Options: DENY）。';
    } elseif (strpos($xfo, 'sameorigin') !== false) {
        $embeddable = false;
        $reason = 'このサイトは自分のサイト内にしか埋め込みを許していません（X-Frame-Options: SAMEORIGIN）。';
    }
}

if ($embeddable && preg_match('/frame-ancestors\s+([^;]+)/', $csp, $m)) {
    $fa = trim($m[1]);
    if (strpos($fa, "'none'") !== false || strpos($fa, "'self'") !== false) {
        $embeddable = false;
        $reason = 'このサイトは埋め込み先を制限しています（Content-Security-Policy: frame-ancestors ' . $fa . '）。';
    }
}

if ($embeddable && $status >= 400) {
    $embeddable = false;
    $reason = 'ページが見つかりませんでした（HTTP ' . $status . '）。URLをご確認ください。';
}

echo json_encode([
    'ok'         => true,
    'embeddable' => $embeddable,
    'reason'     => $reason,
    'status'     => $status,
], JSON_UNESCAPED_UNICODE);
