<?php
/**
 * スプレッドシートを xlsx で取得し、書式（背景色・セル結合・太字・文字色・左右配置）を
 * できるだけ忠実に再現した HTML テーブルを返す。
 * 罫線・フォント種別・数値書式（日付等）は近似/非対応。
 * 取得に失敗した場合、呼び出し側は fetch_sheet.php（CSV）にフォールバックする想定。
 */
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
$sheet_url   = trim($_POST['sheet_url']   ?? '');
$sheet_name  = trim($_POST['sheet_name']  ?? '');
$sheet_range = trim($_POST['sheet_range'] ?? '');
if ($sheet_range !== '' && !preg_match('/^[A-Za-z]+[0-9]+:[A-Za-z]+[0-9]+$/', $sheet_range)) {
    $sheet_range = '';
}

if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $sheet_url, $m)) {
    http_response_code(400);
    echo json_encode(['error' => '無効なスプレッドシートURLです。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$sheet_id = $m[1];

/* ===== xlsx ダウンロード ===== */
$xlsx_url = 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=xlsx';
$data = false;
if (function_exists('curl_init')) {
    $ch = curl_init($xlsx_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PHP-Fetch/1.0)',
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
}
if (($data === false || $data === '') && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => true, 'max_redirects' => 5],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $data = @file_get_contents($xlsx_url, false, $ctx);
}
if ($data === false || $data === '') {
    http_response_code(502);
    echo json_encode(['error' => 'スプレッドシートの取得に失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (substr($data, 0, 2) !== 'PK') {
    echo json_encode(['error' => '書式付きで取得できませんでした。スプレッドシートを「リンクを知っている全員が閲覧可」に設定してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!class_exists('ZipArchive')) {
    echo json_encode(['error' => 'サーバーがZIP展開に未対応のため書式を読み取れません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmp = tempnam(sys_get_temp_dir(), 'gsx');
file_put_contents($tmp, $data);
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    @unlink($tmp);
    echo json_encode(['error' => 'ファイルの展開に失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== 小物 ===== */
function zget($zip, $name) { $s = $zip->getFromName($name); return $s === false ? '' : $s; }
function load_dom($xml) { if ($xml === '') return null; $d = new DOMDocument(); @$d->loadXML($xml); return $d; }
/** 子孫要素を localName で取得（名前空間に依存しない） */
function tags($node, $ln) {
    $out = [];
    if (!$node) return $out;
    foreach ($node->getElementsByTagName('*') as $e) { if ($e->localName === $ln) $out[] = $e; }
    return $out;
}
/** 直接の子要素を localName で取得 */
function kids($el, $ln) {
    $out = [];
    if (!$el) return $out;
    foreach ($el->childNodes as $c) { if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === $ln) $out[] = $c; }
    return $out;
}
function kid($el, $ln) { $k = kids($el, $ln); return $k ? $k[0] : null; }
/** "B12" → ['r'=>11,'c'=>1]（0始まり） */
function ref_rc($ref) {
    if (!preg_match('/^([A-Za-z]+)([0-9]+)$/', $ref, $mm)) return null;
    $L = strtoupper($mm[1]); $col = 0;
    for ($i = 0; $i < strlen($L); $i++) $col = $col * 26 + (ord($L[$i]) - 64);
    return ['r' => (int)$mm[2] - 1, 'c' => $col - 1];
}
/** tint 適用（RGB空間の近似） */
function apply_tint($hex, $tint) {
    if ($hex === null) return null;
    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
    if ($tint < 0) { $f = 1 + $tint; $r *= $f; $g *= $f; $b *= $f; }
    elseif ($tint > 0) { $r = $r * (1 - $tint) + 255 * $tint; $g = $g * (1 - $tint) + 255 * $tint; $b = $b * (1 - $tint) + 255 * $tint; }
    return sprintf('#%02X%02X%02X', max(0, min(255, round($r))), max(0, min(255, round($g))), max(0, min(255, round($b))));
}

/* ===== テーマ配色 ===== */
$themeColors = [];   // clrScheme の出現順: dk1, lt1, dk2, lt2, accent1..6, hlink, folHlink
$td = load_dom(zget($zip, 'xl/theme/theme1.xml'));
if ($td) {
    $cs = tags($td, 'clrScheme');
    if ($cs) {
        foreach ($cs[0]->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            $hex = null;
            foreach ($c->childNodes as $cc) {
                if ($cc->nodeType !== XML_ELEMENT_NODE) continue;
                if ($cc->localName === 'srgbClr')      $hex = $cc->getAttribute('val');
                elseif ($cc->localName === 'sysClr')   $hex = $cc->getAttribute('lastClr');
            }
            $themeColors[] = $hex ? ('#' . substr($hex, -6)) : null;
        }
    }
}
// セルの theme 属性 index → clrScheme順（先頭2ペアは入れ替え）
$themeIdxMap = [0 => 1, 1 => 0, 2 => 3, 3 => 2, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9, 10 => 10, 11 => 11];

function color_from($el, $themeColors, $themeIdxMap) {
    if (!$el) return null;
    $rgb = $el->getAttribute('rgb');
    if ($rgb !== '') return '#' . substr($rgb, -6);
    $th = $el->getAttribute('theme');
    if ($th !== '') {
        $idx  = (int)$th;
        $mi   = isset($themeIdxMap[$idx]) ? $themeIdxMap[$idx] : $idx;
        $base = isset($themeColors[$mi]) ? $themeColors[$mi] : null;
        $tint = $el->getAttribute('tint');
        return apply_tint($base, $tint !== '' ? (float)$tint : 0);
    }
    return null;
}

/* ===== スタイル（fills / fonts / cellXfs） ===== */
$fills = []; $fonts = []; $xfs = [];
$std = load_dom(zget($zip, 'xl/styles.xml'));
if ($std) {
    $fillsRoot = tags($std, 'fills');
    if ($fillsRoot) foreach (kids($fillsRoot[0], 'fill') as $fill) {
        $pf = kid($fill, 'patternFill'); $col = null;
        if ($pf && $pf->getAttribute('patternType') === 'solid') {
            $col = color_from(kid($pf, 'fgColor'), $themeColors, $themeIdxMap);
        }
        $fills[] = $col;
    }
    $fontsRoot = tags($std, 'fonts');
    if ($fontsRoot) foreach (kids($fontsRoot[0], 'font') as $font) {
        $fonts[] = [
            'bold'  => kid($font, 'b') !== null,
            'color' => color_from(kid($font, 'color'), $themeColors, $themeIdxMap),
        ];
    }
    $cxRoot = tags($std, 'cellXfs');
    if ($cxRoot) foreach (kids($cxRoot[0], 'xf') as $xf) {
        $al = kid($xf, 'alignment');
        $xfs[] = [
            'fill' => (int)$xf->getAttribute('fillId'),
            'font' => (int)$xf->getAttribute('fontId'),
            'h'    => $al ? $al->getAttribute('horizontal') : '',
        ];
    }
}

/* ===== 共有文字列 ===== */
$shared = [];
$shd = load_dom(zget($zip, 'xl/sharedStrings.xml'));
if ($shd) foreach (tags($shd, 'si') as $si) {
    $txt = '';
    foreach ($si->getElementsByTagName('*') as $t) { if ($t->localName === 't') $txt .= $t->textContent; }
    $shared[] = $txt;
}

/* ===== ワークシート選択（シート名→r:id→rels target） ===== */
$relMap = [];
$rd = load_dom(zget($zip, 'xl/_rels/workbook.xml.rels'));
if ($rd) foreach (tags($rd, 'Relationship') as $rel) { $relMap[$rel->getAttribute('Id')] = $rel->getAttribute('Target'); }

$RELNS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
$wbd = load_dom(zget($zip, 'xl/workbook.xml'));
$target = null; $firstTarget = null;
if ($wbd) foreach (tags($wbd, 'sheet') as $sh) {
    $nm  = $sh->getAttribute('name');
    $rid = $sh->getAttributeNS($RELNS, 'id');
    if ($rid === '') $rid = $sh->getAttribute('r:id');
    $tgt = isset($relMap[$rid]) ? $relMap[$rid] : null;
    if ($firstTarget === null) $firstTarget = $tgt;
    if ($sheet_name !== '' && $nm === $sheet_name) { $target = $tgt; break; }
}
if ($target === null) $target = $firstTarget;
if ($target === null) {
    @unlink($tmp);
    echo json_encode(['error' => '対象のシートが見つかりませんでした。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$wsPath = (strpos($target, '/') === 0) ? ltrim($target, '/') : ('xl/' . $target);
$wsd = load_dom(zget($zip, $wsPath));
@unlink($tmp);
if (!$wsd) {
    echo json_encode(['error' => 'シートの読み取りに失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== セル読み取り ===== */
$cells = []; $maxR = 0; $maxC = 0;
foreach (tags($wsd, 'row') as $row) {
    foreach (kids($row, 'c') as $c) {
        $rc = ref_rc($c->getAttribute('r'));
        if (!$rc) continue;
        $r = $rc['r']; $cc = $rc['c'];
        $t = $c->getAttribute('t');
        $s = $c->getAttribute('s');
        $val = '';
        if ($t === 'inlineStr') {
            $is = kid($c, 'is');
            if ($is) foreach ($is->getElementsByTagName('*') as $tt) { if ($tt->localName === 't') $val .= $tt->textContent; }
        } else {
            $vEl = kid($c, 'v');
            if ($vEl) {
                $raw = $vEl->textContent;
                if ($t === 's')        $val = isset($shared[(int)$raw]) ? $shared[(int)$raw] : '';
                elseif ($t === 'str')  $val = $raw;
                elseif ($t === 'b')    $val = ($raw === '1') ? 'TRUE' : 'FALSE';
                else                   $val = $raw;
            }
        }
        $cells[$r][$cc] = ['v' => $val, 's' => ($s === '' ? null : (int)$s)];
        if ($r > $maxR) $maxR = $r;
        if ($cc > $maxC) $maxC = $cc;
    }
}

/* ===== 表示範囲 ===== */
if ($sheet_range !== '' && preg_match('/^([A-Za-z]+)([0-9]+):([A-Za-z]+)([0-9]+)$/', $sheet_range, $rm)) {
    $a = ref_rc($rm[1] . $rm[2]); $b = ref_rc($rm[3] . $rm[4]);
    $r1 = min($a['r'], $b['r']); $r2 = max($a['r'], $b['r']);
    $c1 = min($a['c'], $b['c']); $c2 = max($a['c'], $b['c']);
} else {
    $r1 = 0; $c1 = 0; $r2 = $maxR; $c2 = $maxC;
}
/* 上限（過大表示を抑制） */
if ($r2 - $r1 > 499) $r2 = $r1 + 499;
if ($c2 - $c1 > 59)  $c2 = $c1 + 59;

/* ===== 結合セル ===== */
$skip = []; $span = [];
foreach (tags($wsd, 'mergeCell') as $mc) {
    $ref = $mc->getAttribute('ref');
    if (strpos($ref, ':') === false) continue;
    list($p1, $p2) = explode(':', $ref, 2);
    $a = ref_rc($p1); $b = ref_rc($p2);
    if (!$a || !$b) continue;
    $mr1 = min($a['r'], $b['r']); $mr2 = max($a['r'], $b['r']);
    $mc1 = min($a['c'], $b['c']); $mc2 = max($a['c'], $b['c']);
    $tr1 = max($mr1, $r1); $tc1 = max($mc1, $c1);
    $tr2 = min($mr2, $r2); $tc2 = min($mc2, $c2);
    if ($tr1 > $tr2 || $tc1 > $tc2) continue;   // 範囲外
    for ($rr = $tr1; $rr <= $tr2; $rr++)
        for ($ccx = $tc1; $ccx <= $tc2; $ccx++)
            if (!($rr === $tr1 && $ccx === $tc1)) $skip[$rr . '_' . $ccx] = true;
    $span[$tr1 . '_' . $tc1] = ['rs' => $tr2 - $tr1 + 1, 'cs' => $tc2 - $tc1 + 1, 'or' => $mr1, 'oc' => $mc1];
}

/* ===== HTML 生成 ===== */
$html = '<table style="border-collapse:collapse;font-size:.85rem;width:auto;">';
for ($r = $r1; $r <= $r2; $r++) {
    $html .= '<tr>';
    for ($c = $c1; $c <= $c2; $c++) {
        $key = $r . '_' . $c;
        if (isset($skip[$key])) continue;
        $cell = isset($cells[$r][$c]) ? $cells[$r][$c] : null;
        $val  = $cell ? $cell['v'] : '';
        $sidx = $cell ? $cell['s'] : null;
        $rs = 1; $cs = 1;
        if (isset($span[$key])) {
            $rs = $span[$key]['rs']; $cs = $span[$key]['cs'];
            if (($val === '' || $val === null)) {   // クリップされた結合元から値を補完
                $oc = isset($cells[$span[$key]['or']][$span[$key]['oc']]) ? $cells[$span[$key]['or']][$span[$key]['oc']] : null;
                if ($oc) { $val = $oc['v']; if ($sidx === null) $sidx = $oc['s']; }
            }
        }
        $st = 'border:1px solid #d4ddec;padding:5px 9px;';
        if ($sidx !== null && isset($xfs[$sidx])) {
            $xf = $xfs[$sidx];
            $bg = isset($fills[$xf['fill']]) ? $fills[$xf['fill']] : null;
            if ($bg) $st .= 'background-color:' . $bg . ';';
            $ft = isset($fonts[$xf['font']]) ? $fonts[$xf['font']] : null;
            if ($ft) {
                if ($ft['bold']) $st .= 'font-weight:700;';
                if ($ft['color'] && strtoupper($ft['color']) !== '#000000') $st .= 'color:' . $ft['color'] . ';';
            }
            if     ($xf['h'] === 'center') $st .= 'text-align:center;';
            elseif ($xf['h'] === 'right')  $st .= 'text-align:right;';
            elseif ($xf['h'] === 'left')   $st .= 'text-align:left;';
        }
        $attr = ($rs > 1 ? ' rowspan="' . $rs . '"' : '') . ($cs > 1 ? ' colspan="' . $cs . '"' : '');
        $html .= '<td' . $attr . ' style="' . $st . '">' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '</td>';
    }
    $html .= '</tr>';
}
$html .= '</table>';

echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
