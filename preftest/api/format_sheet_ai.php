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
    /* ローカルにキーが無い場合は主サイトの中央窓口へ委譲（全府県共通キー方式）。
       キーは主サイト(jdsf-seibu.com)に1か所だけ保存し、府県はサーバー間で呼ぶ。 */
    $post = http_build_query([
        'secret'      => 'jdsf-ai-central-9f3k2026',
        'sheet_url'   => $_POST['sheet_url']   ?? '',
        'sheet_name'  => $_POST['sheet_name']  ?? '',
        'sheet_range' => $_POST['sheet_range'] ?? '',
        'extra'       => $_POST['extra']       ?? '',
    ]);
    $central = 'https://jdsf-seibu.com/api/gemini_central.php';
    $out = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($central);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
            CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $out = curl_exec($ch); curl_close($ch);
    }
    if (($out === false || $out === null || $out === '') && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post, 'timeout' => 60, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $out = @file_get_contents($central, false, $ctx);
    }
    if ($out === false || $out === null || $out === '') {
        echo json_encode(['error' => 'AI整形サーバー（主サイト）に接続できませんでした。主サイトでキーが設定されているかご確認ください。', 'need_key' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $out;   // 中央の応答（{ok,html} / {error}）をそのまま返す
    exit;
}

/* ===== パラメータ ===== */
$sheet_url   = trim($_POST['sheet_url']   ?? '');
$sheet_name  = trim($_POST['sheet_name']  ?? '');
$sheet_range = trim($_POST['sheet_range'] ?? '');
if ($sheet_range !== '' && !preg_match('/^[A-Za-z]+[0-9]+:[A-Za-z]+[0-9]+$/', $sheet_range)) $sheet_range = '';
$extra = mb_substr(trim((string)($_POST['extra'] ?? '')), 0, 500);   // 追加の見た目指示（任意）

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
        'density' => ['type' => 'string', 'enum' => ['compact', 'normal', 'relaxed']],
        'sections' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'heading' => ['type' => 'string'],
                    'side'    => ['type' => 'string', 'enum' => ['full', 'center', 'left', 'right']],
                    'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'rows'    => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    'column_styles' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'bold'      => ['type' => 'boolean'],
                        'underline' => ['type' => 'boolean'],
                        'color'     => ['type' => 'string'],
                        'size'      => ['type' => 'string', 'enum' => ['small', 'normal', 'large', 'xlarge']],
                        'bg'        => ['type' => 'string'],
                    ]]],
                ],
                'required' => ['heading', 'side', 'columns', 'rows'],
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
        . "- セクション見出しが元データに無い場合は、適切な1つのセクションにまとめ、heading は空文字でよい。\n"
        . "【重要・横方向の配置 side】各グループが元の表で左右どこに置かれているかを、CSVの列位置から判断し side に入れる:\n"
        . "  * 左側の列にあるグループ → \"left\"\n"
        . "  * 右側の列にあるグループ → \"right\"\n"
        . "  * 左右に空の列があり中央に単独で置かれているグループ（例：上部の『会長・副会長』）→ \"center\"\n"
        . "  * 表の横幅いっぱいに広がるグループ → \"full\"\n"
        . "  例：左に『理事』『監事』、右に『参与』が並んでいる場合、理事と監事は left、参与は right にする。"
        . "上部に中央寄せで置かれた『会長・副会長』のようなグループは center。\n"
        . "  左右で対になるグループは、ウェブでも左右2列に並べて表示するので、この side の判定を正確に行うこと。\n"
        . "【行間 density】表全体の行の高さを density で指定できる：compact(詰める)/normal(標準)/relaxed(ゆったり)。ユーザーが行間の詰め・広げを求めたら反映、指定が無ければ normal。\n"
        . "【見た目の指定 column_styles】各セクションの列ごとに見た目を指定できる（任意）。columns と同じ順番・同じ個数の配列で、各要素は "
        . "{bold(真偽), underline(真偽), color(文字色 \"#RRGGBB\"), size(文字サイズ small/normal/large/xlarge), bg(背景色 \"#RRGGBB\")}。"
        . "指定が無い列は空オブジェクト {} でよい。color・bg は必ず #RRGGBB 形式（例 赤=#FF0000）。下のユーザー要望があれば、それに沿って該当列に設定すること。\n"
        . ($extra !== '' ? ("【ユーザーの追加要望（最優先で反映）】次の要望に従うこと。"
            . "見た目（太字/下線/文字色/文字サイズ/背景色）は該当列の column_styles に反映。"
            . "配置の要望は各セクションの side に反映（左→left／右→right／中央→center／横幅いっぱい→full。"
            . "『1列にまとめる/縦に並べる』なら関係セクションを full または center にする。左右の入れ替え要望なら left と right を入れ替える）。"
            . "指示が無い項目は自動判断のままでよい。\n要望: " . $extra . "\n") : "")
        . "\nCSV:\n" . $csv;

$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json', 'responseSchema' => $schema],
], JSON_UNESCAPED_UNICODE);

function gemini_post($endpoint, $payload) {
    $resp = null; $code = 0; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    }
    if (($resp === false || $resp === null || $resp === '') && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 45, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $resp = @file_get_contents($endpoint, false, $ctx);
    }
    return [$resp, $code, $err];
}

/* 新しめのモデルを順に試す（提供終了に強くするため複数候補） */
$models = ['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash-lite', 'gemini-2.0-flash-001'];
$j = null; $httpcode = 0; $curlerr = ''; $lastMsg = '';
foreach ($models as $model) {
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($API_KEY);
    list($resp, $httpcode, $curlerr) = gemini_post($endpoint, $payload);
    if ($resp === false || $resp === null || $resp === '') { $lastMsg = '接続に失敗しました' . ($curlerr ? '（' . $curlerr . '）' : ''); continue; }
    $jj = json_decode($resp, true);
    if ($httpcode >= 400 || isset($jj['error'])) {
        $lastMsg = isset($jj['error']['message']) ? $jj['error']['message'] : ('HTTP ' . $httpcode);
        // モデル提供終了/未提供なら次の候補へ。その他のエラーは即終了。
        if (stripos($lastMsg, 'no longer available') !== false || stripos($lastMsg, 'not found') !== false
            || stripos($lastMsg, 'not supported') !== false || $httpcode === 404) {
            continue;
        }
        echo json_encode(['error' => 'Gemini APIエラー: ' . $lastMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $j = $jj; break;   // 成功
}
if ($j === null) {
    echo json_encode(['error' => 'Gemini APIエラー: ' . ($lastMsg ?: '利用可能なモデルが見つかりませんでした')], JSON_UNESCAPED_UNICODE);
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

/** 1グループ（見出し＋表）のHTMLを返す。空なら ''。$pad はセルの余白（行間） */
function render_section($sec, $pad) {
    $heading = isset($sec['heading']) ? trim((string)$sec['heading']) : '';
    $columns = (isset($sec['columns']) && is_array($sec['columns'])) ? $sec['columns'] : [];
    $rows    = (isset($sec['rows'])    && is_array($sec['rows']))    ? $sec['rows']    : [];
    $colStyles = (isset($sec['column_styles']) && is_array($sec['column_styles'])) ? $sec['column_styles'] : [];
    $ncol = count($columns);
    if ($ncol === 0) { foreach ($rows as $r) { if (is_array($r)) $ncol = max($ncol, count($r)); } }
    if ($ncol === 0) return '';

    $h = '<div style="margin-bottom:4px;">';
    if ($heading !== '') {
        $h .= '<div style="font-weight:800;font-size:1rem;color:#23375f;border-left:5px solid #e8a13c;'
            . 'background:#eef3fb;padding:8px 14px;border-radius:4px;margin-bottom:10px;">' . esc($heading) . '</div>';
    }
    $h .= '<div style="overflow-x:auto;border-radius:8px;border:1px solid #dde4ef;">'
        . '<table style="width:100%;border-collapse:collapse;font-size:.9rem;">';
    if (count($columns)) {
        $h .= '<thead><tr>';
        foreach ($columns as $col) {
            $h .= '<th style="background:#5b7cb8;color:#fff;font-weight:700;text-align:left;padding:10px 14px;'
                . 'font-size:.82rem;white-space:nowrap;border-right:1px solid rgba(255,255,255,.18);">' . esc($col) . '</th>';
        }
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
            $cellStyle = 'padding:' . $pad . ';border-top:1px solid #eef1f7;background:' . $bg . ';';
            // 追加指示による列スタイル（安全な項目のみ）
            $cs = (isset($colStyles[$c]) && is_array($colStyles[$c])) ? $colStyles[$c] : null;
            $custom = '';
            if ($cs) {
                if (!empty($cs['bold']))      $custom .= 'font-weight:700;';
                if (!empty($cs['underline'])) $custom .= 'text-decoration:underline;';
                if (!empty($cs['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$cs['color'])) $custom .= 'color:' . $cs['color'] . ';';
                if (!empty($cs['size'])) { $sm = ['small' => '.8rem', 'normal' => '.9rem', 'large' => '1.05rem', 'xlarge' => '1.2rem']; if (isset($sm[$cs['size']])) $custom .= 'font-size:' . $sm[$cs['size']] . ';'; }
                if (!empty($cs['bg']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$cs['bg'])) $custom .= 'background:' . $cs['bg'] . ';';
            }
            if ($c === 0) $cellStyle .= 'color:#3b5ea6;font-weight:700;white-space:nowrap;';  // 既定：先頭列(氏名)を強調
            if ($custom !== '') $cellStyle .= 'white-space:nowrap;' . $custom;                // 追加指定を上書き・追加
            $h .= '<td style="' . $cellStyle . '">' . esc($v) . '</td>';
        }
        $h .= '</tr>';
        $rc++;
    }
    $h .= '</tbody></table></div></div>';
    return $h;
}

/* 行間（density）→ セル余白 */
$density = isset($data['density']) ? (string)$data['density'] : 'normal';
$padMap = ['compact' => '4px 12px', 'normal' => '9px 14px', 'relaxed' => '15px 16px'];
$pad = isset($padMap[$density]) ? $padMap[$density] : '9px 14px';

/* full は全幅、left/right は左右2列にまとめて配置（スプレッドシートの左右並びを再現） */
$html  = '<div style="display:flex;flex-direction:column;gap:22px;">';
$pendL = ''; $pendR = '';
$flush = function () use (&$html, &$pendL, &$pendR) {
    if ($pendL === '' && $pendR === '') return;
    $html .= '<div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start;">'
          .  '<div style="flex:1 1 300px;min-width:260px;display:flex;flex-direction:column;gap:18px;">' . $pendL . '</div>'
          .  '<div style="flex:1 1 300px;min-width:260px;display:flex;flex-direction:column;gap:18px;">' . $pendR . '</div>'
          .  '</div>';
    $pendL = ''; $pendR = '';
};
foreach ($data['sections'] as $sec) {
    $sh = render_section($sec, $pad);
    if ($sh === '') continue;
    $side = strtolower(isset($sec['side']) ? (string)$sec['side'] : 'full');
    if ($side === 'left')      { $pendL .= $sh; }
    elseif ($side === 'right') { $pendR .= $sh; }
    elseif ($side === 'center') { $flush(); $html .= '<div style="max-width:520px;width:100%;margin:0 auto;">' . $sh . '</div>'; }  // 中央配置
    else { $flush(); $html .= $sh; }   // full：左右ブロックを確定してから全幅で出力
}
$flush();
$html .= '</div>';

echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
