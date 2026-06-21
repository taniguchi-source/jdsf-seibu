<?php
/**
 * 【主サイト】Gemini AI整形の中央窓口（全府県共通キー方式）
 *  - キーは主サイトの data/gemini_key.php に1か所だけ保存。
 *  - action=save/status/delete : 主サイト管理トークンで操作（サイト構築ページから）。
 *  - action=format             : 府県サイトからサーバー間で呼ばれ、整形HTMLを返す（共有シークレットで認可）。
 *  Gemini の責務は意味の整理（JSON）のみ。HTMLはサーバー側で生成し全値エスケープ。
 */
header('Content-Type: application/json; charset=utf-8');

$MAIN_TOKEN     = 'jdsfseibu2026';                 // 主サイトのクライアントからのキー管理用
$CENTRAL_SECRET = 'jdsf-ai-central-9f3k2026';      // 府県⇔主サイトのサーバー間専用（クライアントには出さない）
$keyfile        = dirname(__DIR__) . '/data/gemini_key.php';
$action         = $_POST['action'] ?? $_GET['action'] ?? 'format';

/* ===== キー管理（主サイト管理トークン） ===== */
if ($action === 'status' || $action === 'save' || $action === 'delete') {
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    if ($token !== $MAIN_TOKEN) { http_response_code(403); echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE); exit; }

    if ($action === 'status') {
        $has = false;
        if (is_file($keyfile)) { $k = @include $keyfile; $has = is_string($k) && $k !== ''; }
        echo json_encode(['ok' => true, 'has' => $has], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    if ($action === 'delete') {
        if (is_file($keyfile)) @unlink($keyfile);
        echo json_encode(['ok' => true, 'has' => false], JSON_UNESCAPED_UNICODE); exit;
    }
    // save
    $key = trim($_POST['key'] ?? '');
    if (!preg_match('/^(AIza[0-9A-Za-z_\-]{20,}|AQ\.[0-9A-Za-z_\-\.]{20,})$/', $key)) {
        http_response_code(400);
        echo json_encode(['error' => 'APIキーの形式が正しくありません（AIza… または AQ.… で始まるキー）。'], JSON_UNESCAPED_UNICODE); exit;
    }
    $dir = dirname($keyfile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (file_put_contents($keyfile, "<?php\nreturn " . var_export($key, true) . ";\n") === false) {
        http_response_code(500); echo json_encode(['error' => 'キーの保存に失敗しました。'], JSON_UNESCAPED_UNICODE); exit;
    }
    @chmod($keyfile, 0644);
    echo json_encode(['ok' => true, 'has' => true], JSON_UNESCAPED_UNICODE); exit;
}

/* ===== ここから整形（action=format）：府県からの呼び出しを共有シークレットで認可 ===== */
if (($_POST['secret'] ?? '') !== $CENTRAL_SECRET) { http_response_code(403); echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$API_KEY = is_file($keyfile) ? (@include $keyfile) : '';
if (!is_string($API_KEY) || $API_KEY === '') {
    echo json_encode(['error' => '主サイトでGemini APIキーが未設定です。主サイトのサイト構築ページでキーを保存してください。', 'need_key' => true], JSON_UNESCAPED_UNICODE); exit;
}

$sheet_url   = trim($_POST['sheet_url']   ?? '');
$sheet_name  = trim($_POST['sheet_name']  ?? '');
$sheet_range = trim($_POST['sheet_range'] ?? '');
if ($sheet_range !== '' && !preg_match('/^[A-Za-z]+[0-9]+:[A-Za-z]+[0-9]+$/', $sheet_range)) $sheet_range = '';
if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $sheet_url, $m)) {
    http_response_code(400); echo json_encode(['error' => '無効なスプレッドシートURLです。'], JSON_UNESCAPED_UNICODE); exit;
}
$sheet_id = $m[1];

/* ===== CSV 取得（gviz優先） ===== */
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
if ($csv === null) { echo json_encode(['error' => 'スプレッドシートを取得できませんでした。「リンクを知っている全員が閲覧可」に設定してください。'], JSON_UNESCAPED_UNICODE); exit; }
if (substr($csv, 0, 3) === "\xEF\xBB\xBF") $csv = substr($csv, 3);
$csv = mb_substr($csv, 0, 20000, 'UTF-8');

/* ===== Gemini ===== */
$schema = ['type' => 'object', 'properties' => ['sections' => ['type' => 'array', 'items' => ['type' => 'object',
    'properties' => [
        'heading' => ['type' => 'string'],
        'side'    => ['type' => 'string', 'enum' => ['full', 'center', 'left', 'right']],
        'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
        'rows'    => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
    ], 'required' => ['heading', 'side', 'columns', 'rows']]]], 'required' => ['sections']];
$prompt = "あなたは表データ整形の専門家です。次のCSVは、ある団体の役員名簿などをGoogleスプレッドシートから取り出したものです。"
        . "ウェブサイトに見やすく掲載するための構造化データに変換してください。\n"
        . "ルール:\n"
        . "- セクション見出し（例：会長・副会長、理事、監事、参与、執行部、各部部長 など）を見つけ、sections に分けること。\n"
        . "- 各セクションは columns（列見出し。データに合うものを推測。例：役職, 氏名, 所属 など。該当が無ければ空配列でよい）と rows（各行の値の配列）を持つ。\n"
        . "- 装飾目的の空セル・空列・空行は取り除く。各行の列数は columns に揃える（不足は空文字）。\n"
        . "- 人物・役職・氏名・所属を勝手に変更・要約・省略しないこと。CSVに登場する全員を漏れなく含める。\n"
        . "- 値は元の表記のまま（読み仮名や敬称の追加・削除をしない）。\n"
        . "- セクション見出しが元データに無い場合は、適切な1つのセクションにまとめ、heading は空文字でよい。\n"
        . "【重要・横方向の配置 side】各グループが元の表で左右どこに置かれているかを、CSVの列位置から判断し side に入れる:\n"
        . "  * 左側の列にあるグループ → \"left\"\n"
        . "  * 右側の列にあるグループ → \"right\"\n"
        . "  * 左右に空の列があり中央に単独で置かれているグループ（例：上部の『会長・副会長』）→ \"center\"\n"
        . "  * 表の横幅いっぱいに広がるグループ → \"full\"\n"
        . "  例：左に『理事』『監事』、右に『参与』が並んでいる場合、理事と監事は left、参与は right にする。"
        . "上部に中央寄せで置かれた『会長・副会長』のようなグループは center。\n"
        . "  左右で対になるグループは、ウェブでも左右2列に並べて表示するので、この side の判定を正確に行うこと。\n\n"
        . "CSV:\n" . $csv;
$payload = json_encode(['contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json', 'responseSchema' => $schema]], JSON_UNESCAPED_UNICODE);

function gemini_post($endpoint, $payload) {
    $resp = null; $code = 0; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    }
    if (($resp === false || $resp === null || $resp === '') && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 45, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $resp = @file_get_contents($endpoint, false, $ctx);
    }
    return [$resp, $code, $err];
}
$models = ['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash-lite', 'gemini-2.0-flash-001'];
$j = null; $httpcode = 0; $lastMsg = '';
foreach ($models as $model) {
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($API_KEY);
    list($resp, $httpcode, $curlerr) = gemini_post($endpoint, $payload);
    if ($resp === false || $resp === null || $resp === '') { $lastMsg = '接続に失敗しました' . ($curlerr ? '（' . $curlerr . '）' : ''); continue; }
    $jj = json_decode($resp, true);
    if ($httpcode >= 400 || isset($jj['error'])) {
        $lastMsg = isset($jj['error']['message']) ? $jj['error']['message'] : ('HTTP ' . $httpcode);
        if (stripos($lastMsg, 'no longer available') !== false || stripos($lastMsg, 'not found') !== false
            || stripos($lastMsg, 'not supported') !== false || $httpcode === 404) { continue; }
        echo json_encode(['error' => 'Gemini APIエラー: ' . $lastMsg], JSON_UNESCAPED_UNICODE); exit;
    }
    $j = $jj; break;
}
if ($j === null) { echo json_encode(['error' => 'Gemini APIエラー: ' . ($lastMsg ?: '利用可能なモデルが見つかりませんでした')], JSON_UNESCAPED_UNICODE); exit; }
$text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
if ($text === '') { echo json_encode(['error' => 'Geminiから有効な応答が得られませんでした。'], JSON_UNESCAPED_UNICODE); exit; }
$data = json_decode($text, true);
if (!is_array($data) || !isset($data['sections']) || !is_array($data['sections'])) { echo json_encode(['error' => '整形結果の解析に失敗しました。'], JSON_UNESCAPED_UNICODE); exit; }

/* ===== HTML 描画（全値エスケープ） ===== */
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function render_section($sec) {
    $heading = isset($sec['heading']) ? trim((string)$sec['heading']) : '';
    $columns = (isset($sec['columns']) && is_array($sec['columns'])) ? $sec['columns'] : [];
    $rows    = (isset($sec['rows'])    && is_array($sec['rows']))    ? $sec['rows']    : [];
    $ncol = count($columns);
    if ($ncol === 0) { foreach ($rows as $r) { if (is_array($r)) $ncol = max($ncol, count($r)); } }
    if ($ncol === 0) return '';
    $h = '<div style="margin-bottom:4px;">';
    if ($heading !== '') {
        $h .= '<div style="font-weight:800;font-size:1rem;color:#23375f;border-left:5px solid #e8a13c;background:#eef3fb;padding:8px 14px;border-radius:4px;margin-bottom:10px;">' . esc($heading) . '</div>';
    }
    $h .= '<div style="overflow-x:auto;border-radius:8px;border:1px solid #dde4ef;"><table style="width:100%;border-collapse:collapse;font-size:.9rem;">';
    if (count($columns)) {
        $h .= '<thead><tr>';
        foreach ($columns as $col) $h .= '<th style="background:#5b7cb8;color:#fff;font-weight:700;text-align:left;padding:10px 14px;font-size:.82rem;white-space:nowrap;border-right:1px solid rgba(255,255,255,.18);">' . esc($col) . '</th>';
        $h .= '</tr></thead>';
    }
    $h .= '<tbody>';
    $rc = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $hasVal = false; foreach ($r as $v) { if (trim((string)$v) !== '') { $hasVal = true; break; } }
        if (!$hasVal) continue;
        $bg = ($rc % 2 === 0) ? '#ffffff' : '#f7f9fc';
        $h .= '<tr>';
        for ($c = 0; $c < $ncol; $c++) {
            $v = isset($r[$c]) ? $r[$c] : '';
            $first = ($c === 0);
            $cellStyle = 'padding:9px 14px;border-top:1px solid #eef1f7;background:' . $bg . ';';
            if ($first) $cellStyle .= 'color:#3b5ea6;font-weight:700;white-space:nowrap;';
            $h .= '<td style="' . $cellStyle . '">' . esc($v) . '</td>';
        }
        $h .= '</tr>'; $rc++;
    }
    $h .= '</tbody></table></div></div>';
    return $h;
}
$html = '<div style="display:flex;flex-direction:column;gap:22px;">';
$pendL = ''; $pendR = '';
$flush = function () use (&$html, &$pendL, &$pendR) {
    if ($pendL === '' && $pendR === '') return;
    $html .= '<div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start;">'
          .  '<div style="flex:1 1 300px;min-width:260px;display:flex;flex-direction:column;gap:18px;">' . $pendL . '</div>'
          .  '<div style="flex:1 1 300px;min-width:260px;display:flex;flex-direction:column;gap:18px;">' . $pendR . '</div></div>';
    $pendL = ''; $pendR = '';
};
foreach ($data['sections'] as $sec) {
    $sh = render_section($sec);
    if ($sh === '') continue;
    $side = strtolower(isset($sec['side']) ? (string)$sec['side'] : 'full');
    if ($side === 'left')        { $pendL .= $sh; }
    elseif ($side === 'right')   { $pendR .= $sh; }
    elseif ($side === 'center')  { $flush(); $html .= '<div style="max-width:520px;width:100%;margin:0 auto;">' . $sh . '</div>'; }
    else                         { $flush(); $html .= $sh; }
}
$flush();
$html .= '</div>';

echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
