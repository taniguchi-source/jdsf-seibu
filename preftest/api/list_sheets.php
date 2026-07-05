<?php
header('Content-Type: application/json; charset=utf-8');

/* ===== 認証 ===== */
require __DIR__ . '/_auth.php';
require_auth('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

/* ===== スプレッドシートID 抽出 ===== */
$sheet_url = trim($_POST['sheet_url'] ?? '');
if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $sheet_url, $m)) {
    http_response_code(400);
    echo json_encode(['error' => '無効なスプレッドシートURLです。GoogleスプレッドシートのURLを入力してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$sheet_id = $m[1];

/* ===== xlsx をダウンロード（シート名はブック内 workbook.xml に含まれる） ===== */
$xlsx_url = 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=xlsx';

$data = false;
if (function_exists('curl_init')) {
    $ch = curl_init($xlsx_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PHP-Fetch/1.0)',
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
}
if (($data === false || $data === '') && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 25, 'follow_location' => true, 'max_redirects' => 5],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $data = @file_get_contents($xlsx_url, false, $ctx);
}

if ($data === false || $data === '') {
    http_response_code(502);
    echo json_encode(['error' => 'スプレッドシートの取得に失敗しました。サーバーからGoogleへの接続が遮断されている可能性があります。'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* HTML（ログイン要求）が返ってきた場合は非公開 */
if (substr($data, 0, 2) !== 'PK') {
    echo json_encode(['error' => 'シート一覧を取得できませんでした。スプレッドシートを「リンクを知っている全員が閲覧可」に設定してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!class_exists('ZipArchive')) {
    echo json_encode(['error' => 'サーバーがZIP展開に未対応のため一覧を取得できません。シート名は手入力してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== xlsx(zip) から workbook.xml を読み、シート名（タブ順）を抽出 ===== */
$tmp = tempnam(sys_get_temp_dir(), 'gsx');
file_put_contents($tmp, $data);

$names = [];
$zip = new ZipArchive();
if ($zip->open($tmp) === true) {
    $wb = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    if ($wb !== false && preg_match_all('/<sheet\b[^>]*\bname="([^"]*)"/u', $wb, $mm)) {
        foreach ($mm[1] as $n) {
            $name = html_entity_decode($n, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($name !== '') $names[] = $name;
        }
    }
}
@unlink($tmp);

if (!$names) {
    echo json_encode(['error' => 'シート名を読み取れませんでした。'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'sheets' => $names], JSON_UNESCAPED_UNICODE);
