<?php
/**
 * スプレッドシート(CSV)を Gemini で「セクション＋表」の構造化JSONに整形し、
 * 委員会サイト風の見やすいHTMLに描画して返す。
 *  - Gemini の責務は意味の整理（JSON出力）のみ。HTMLはサーバー側で生成し全値をエスケープ。
 *  - 生成は管理画面での保存時に1回だけ行い、結果(html)を contents.json に保存する想定
 *    （公開ページの閲覧では Gemini を呼ばない＝課金されない）。
 */
header('Content-Type: application/json; charset=utf-8');

$token = $_POST['token'] ?? '';
if ($token !== 'preftest2026') { http_response_code(403); echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

/* ===== APIキー ===== */
$keyfile = dirname(__DIR__) . '/data/gemini_key.php';
$API_KEY = is_file($keyfile) ? (@include $keyfile) : '';
if (!is_string($API_KEY) || $API_KEY === '') {
    echo json_encode(['error' => 'Gemini APIキーが未設定です。管理画面の「AI整形の設定」でキーを保存してください。', 'need_key' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== パラメータ ===== */
$sheet_url   = trim($_POST['sheet_url']   ?? '');
$sheet_name  = trim($_POST['sheet_name']  ?? '');
$sheet_range = trim($_POST['sheet_range'] ?? '');
if ($sheet_range !== '' && !preg_match('/^[A-Za-z]+[0-9]+:[A-Za-z]+[0-9]+$/', $sheet_range)) $sheet_range = '';

if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $sheet_url, $m)) {
    http_response_code(400);
    echo json_encode(['error' => '無効なスプレッドシートURLです。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$sheet_id = $m[1];

/* ===== CSV 取得（gviz優先：シート名・範囲を解釈できる） ===== */
function http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PHP-Fetch/1.0)',
        ]);
        $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
        if ($b !== false && $code >= 200 && $code < 400) return ['body' => $b, 'type' => $ct];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 25, 'follow_location' => true, 'max_redirects' => 5], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $b = @file_get_contents($url, false, $ctx);
        if ($b !== false) return ['body' => $b, 'type' => ''];
    }
    return null;
}
function is_csv($body, $ct) {
    if (stripos($ct, 'text/html') !== false) return false;
    if (stripos($body, '<!DOCTYPE') !== false) return false;
    if (stripos($body, '<html') !== false) return false;
    return true;
}
$sp = $sheet_name !== '' ? '&sheet=' . urlencode($sheet_name) : '';
$rp = $sheet_range !== '' ? '&range=' . urlencode($sheet_range) : '';
$base = 'https://docs.google.com/spreadsheets/d/' . $sheet_id;
$urls = [$base . '/gviz/tq?tqx=out:csv' . $sp . $rp, $base . '/export?format=csv' . $sp, $base . '/pub?output=csv' . $sp];
$csv = null;
foreach ($urls as $u) { $r = http_get($u); if ($r && is_csv($r['body'], $r['type'])) { $csv = $r['body']; break; } }
if ($csv === null) {
    echo json_encode(['error' => 'スプレッドシートを取得できませんでした。「リンクを知っている全員が閲覧可」に設定してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (substr($csv, 0, 3) === "\xEF\xBB\xBF") $csv = substr($csv, 3);
$csv = mb_substr($csv, 0, 20000, 'UTF-8');   // 入力過大を防止

/* ===== Gemini 呼び出し ===== */
$schema = [
    'type' => 'object',
    'properties' => [
        'sections' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'heading' => ['type' => 'string'],
                    'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'rows'    => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'required' => ['heading', 'columns', 'rows'],
            ],
        ],
    ],
    'required' => ['sections'],
];
$prompt = "あなたは表データ整形の専門家です。次のCSVは、ある団体の役員名簿などをGoogleスプレッドシートから取り出したものです。"
        . "ウェブサイトに見やすく掲載するための構造化データに変換してください。\n"
        . "ルール:\n"
        . "- セクション見出し（例：会長・副会長、理事、監事、参与、執行部、各部部長 など）を見つけ、sections に分けること。\n"
        . "- 各セクションは columns（列見出し。データに合うものを推測。例：役職, 氏名, 所属 など。該当が無ければ空配列でよい）と rows（各行の値の配列）を持つ。\n"
        . "- 装飾目的の空セル・空列・空行は取り除く。各行の列数は columns に揃える（不足は空文字）。\n"
        . "- 人物・役職・氏名・所属を勝手に変更・要約・省略しないこと。CSVに登場する全員を漏れなく含める。\n"
        . "- 値は元の表記のまま（読み仮名や敬称の追加・削除をしない）。\n"
        . "- セクション見出しが元データに無い場合は、適切な1つのセクションにまとめ、heading は空文字でよい。\n\n"
        . "CSV:\n" . $csv;

$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json', 'responseSchema' => $schema],
], JSON_UNESCAPED_UNICODE);

$model = 'gemini-2.0-flash';
$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($API_KEY);

$resp = null; $httpcode = 0; $curlerr = '';
if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch); $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlerr = curl_error($ch); curl_close($ch);
}
if (($resp === false || $resp === null || $resp === '') && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 45, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $resp = @file_get_contents($endpoint, false, $ctx);
}
if ($resp === false || $resp === null || $resp === '') {
    echo json_encode(['error' => 'Gemini APIへの接続に失敗しました。', 'debug' => $curlerr], JSON_UNESCAPED_UNICODE);
    exit;
}

$j = json_decode($resp, true);
if ($httpcode >= 400 || isset($j['error'])) {
    $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $httpcode);
    echo json_encode(['error' => 'Gemini APIエラー: ' . $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
$text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
if ($text === '') {
    echo json_encode(['error' => 'Geminiから有効な応答が得られませんでした。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$data = json_decode($text, true);
if (!is_array($data) || !isset($data['sections']) || !is_array($data['sections'])) {
    echo json_encode(['error' => '整形結果の解析に失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== HTML 描画（委員会サイト風・全値エスケープ） ===== */
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$html = '<div style="display:flex;flex-direction:column;gap:22px;">';
foreach ($data['sections'] as $sec) {
    $heading = isset($sec['heading']) ? trim((string)$sec['heading']) : '';
    $columns = (isset($sec['columns']) && is_array($sec['columns'])) ? $sec['columns'] : [];
    $rows    = (isset($sec['rows'])    && is_array($sec['rows']))    ? $sec['rows']    : [];
    // 列数を決定（columns 優先、無ければ最大行長）
    $ncol = count($columns);
    if ($ncol === 0) { foreach ($rows as $r) { if (is_array($r)) $ncol = max($ncol, count($r)); } }
    if ($ncol === 0) continue;

    $html .= '<div>';
    if ($heading !== '') {
        $html .= '<div style="font-weight:800;font-size:1rem;color:#23375f;border-left:5px solid #e8a13c;'
              .  'background:#eef3fb;padding:8px 14px;border-radius:4px;margin-bottom:10px;">' . esc($heading) . '</div>';
    }
    $html .= '<div style="overflow-x:auto;border-radius:8px;border:1px solid #dde4ef;">'
          .  '<table style="width:100%;border-collapse:collapse;font-size:.9rem;">';
    if (count($columns)) {
        $html .= '<thead><tr>';
        foreach ($columns as $i => $col) {
            $html .= '<th style="background:#5b7cb8;color:#fff;font-weight:700;text-align:left;padding:10px 14px;'
                  .  'font-size:.82rem;white-space:nowrap;border-right:1px solid rgba(255,255,255,.18);">' . esc($col) . '</th>';
        }
        $html .= '</tr></thead>';
    }
    $html .= '<tbody>';
    $rc = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        // 空行スキップ
        $hasVal = false; foreach ($r as $v) { if (trim((string)$v) !== '') { $hasVal = true; break; } }
        if (!$hasVal) continue;
        $bg = ($rc % 2 === 0) ? '#ffffff' : '#f7f9fc';
        $html .= '<tr>';
        for ($c = 0; $c < $ncol; $c++) {
            $v = isset($r[$c]) ? $r[$c] : '';
            $first = ($c === 0 && count($columns) > 1);
            $cellStyle = 'padding:9px 14px;border-top:1px solid #eef1f7;background:' . $bg . ';';
            if ($first) $cellStyle .= 'color:#3b5ea6;font-weight:700;white-space:nowrap;';
            $html .= '<td style="' . $cellStyle . '">' . esc($v) . '</td>';
        }
        $html .= '</tr>';
        $rc++;
    }
    $html .= '</tbody></table></div></div>';
}
$html .= '</div>';

echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
