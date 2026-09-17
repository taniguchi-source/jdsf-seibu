<?php
/* 出場選手一覧を Excel（.xlsx）で返す。
   紙と同じ体裁（区分の帯 → 見出し → 段組の名簿）の1シート。
   どのセルも「縮小して全体を表示する」にして、折り返さない（紙と同じ見え方にする）。

   段数・列幅・行の高さ・文字の大きさは、画面が紙の大きさで組んだ実測値を fmt で受け取る。
   同じ計算をサーバーにも書くと、片方だけ直したときに紙とExcelで体裁が食い違うため。

   xlsx は zip なので ZipArchive を使う。使えないサーバーでは CSV で返す
   （その場でどちらか分かるよう、ファイル名の拡張子も合わせる）。 */
require __DIR__ . '/_uketsuke.php';
require __DIR__ . '/_uketsuke_syllabus.php';
uk_require_read();

$id = $_GET['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 合言葉で開いた大会のみ */

$events = uk_load_events($id);
$roster = uk_load_roster($id);

$name = '';
$date = '';
foreach (uk_load_list() as $c) {
    if (($c['id'] ?? '') === $id) { $name = (string)($c['name'] ?? ''); $date = (string)($c['date'] ?? ''); break; }
}

/* 区分コード → 区分名（コードのままのときは空にして「JAS JAS」と二重に出さない） */
$evName = [];
foreach ($events as $e) {
    $code = (string)($e['code'] ?? '');
    if ($code === '') continue;
    $n = trim((string)($e['name'] ?? ''));
    $evName[$code] = ($n === '' || $n === $code) ? '' : $n;
}

/* 出す区分と並び。codes が無いときは events.json の並びをそのまま使う。 */
$codes = [];
$want = (string)($_GET['codes'] ?? '');
if ($want !== '') {
    foreach (explode(',', $want) as $c) {
        $c = trim($c);
        if ($c !== '' && isset($evName[$c]) && !in_array($c, $codes, true)) $codes[] = $c;
    }
}
if (!$codes) $codes = array_keys($evName);

/* 種目（ワルツ・タンゴなど）。控えがあれば使う。 */
$dances = [];
$syl = uk_syllabus_load($id);
foreach ((array)(($syl['syllabus']['events'] ?? []) ?: ($syl['events'] ?? [])) as $e) {
    $c = (string)($e['code'] ?? '');
    if ($c !== '') $dances[$c] = (string)($e['dances'] ?? '');
}
/* 画面と同じ書き方に直す（"W,T,(最終:V),F,Q" → "W・T・最終予選よりV・F・Q"） */
function uk_dance_text($s) {
    if ($s === '') return '';
    $out = [];
    foreach (explode(',', $s) as $t) {
        $t = trim($t);
        if ($t === '') continue;
        if (preg_match('/^[(（]\s*最終\s*[:：]\s*(.+?)\s*[)）]$/u', $t, $m)) $t = '最終予選より' . $m[1];
        $out[] = $t;
    }
    return implode('・', $out);
}

/* その区分に出る組を背番号順で */
function uk_entries_for($roster, $code) {
    $out = [];
    foreach ($roster as $r) {
        if (in_array($code, (array)($r['events'] ?? []), true)) $out[] = $r;
    }
    usort($out, function ($a, $b) { return (int)($a['bib'] ?? 0) - (int)($b['bib'] ?? 0); });
    return $out;
}

/* ── 画面から渡された紙の寸法。おかしな値が来ても紙が作れるよう、すべて範囲で丸める ── */
function uk_num($v, $min, $max, $def) {
    if (!is_numeric($v)) return $def;
    $v = (float)$v;
    return $v < $min ? $min : ($v > $max ? $max : $v);
}
$fmt = json_decode((string)($_GET['fmt'] ?? ''), true);
if (!is_array($fmt)) $fmt = [];

$cols = (int)uk_num($fmt['cols'] ?? 2, 1, 4, 2);
$memo = !empty($fmt['memo']);
$NC   = $memo ? 5 : 4;                       /* 1段ぶんの列数 */

$rw = is_array($fmt['w'] ?? null) ? $fmt['w'] : [];
$W  = [];                                    /* 1段ぶんの列幅（px） */
$defw = $memo ? [26, 40, 105, 105, 130] : [40, 105, 105, 130];
for ($i = 0; $i < $NC; $i++) $W[] = uk_num($rw[$i] ?? null, 8, 600, $defw[$i]);
$GAPW = uk_num($fmt['gap'] ?? 18, 2, 200, 18);

$R = is_array($fmt['rows'] ?? null) ? $fmt['rows'] : [];
$H_DATA  = uk_num($R['data']   ?? 17, 6, 120, 17);   /* 名簿1行の高さ（pt） */
$H_HEAD  = uk_num($R['head']   ?? 14, 6, 120, 14);
$H_TITLE = uk_num($R['title']  ?? 21, 8, 160, 21);
$H_GAP   = uk_num($R['spacer'] ?? 8,  1, 120, 8);

$F = is_array($fmt['fonts'] ?? null) ? $fmt['fonts'] : [];
$F_BIB = uk_num($F['bib'] ?? 13,   4, 40, 13);
$F_NM  = uk_num($F['nm']  ?? 11,   4, 40, 11);
$F_AFF = uk_num($F['aff'] ?? 8.5,  4, 40, 8.5);
$F_TH  = uk_num($F['th']  ?? 7.5,  4, 40, 7.5);
$F_TTL = uk_num($F['ttl'] ?? 12,   4, 40, 12);
$F_DAN = uk_num($F['dan'] ?? 10,   4, 40, 10);

/* 区分ごとの系統（色分けに使う）。画面の evKind() が決めたものをそのまま受け取る。 */
$KINDS = is_array($fmt['kinds'] ?? null) ? $fmt['kinds'] : [];
/* 帯の地色と文字色。uketsuke-program.html の .k-* と同じ値。 */
$KIND_COLOR = [
    'ten'    => ['FFE4F5E9', 'FF15803D'],
    'latin'  => ['FFFCE8EF', 'FFBE185D'],
    'std'    => ['FFE7EEFB', 'FF1E3A8A'],
    'senior' => ['FFF1E8FB', 'FF6D28D9'],
    'junior' => ['FFCDDFFB', 'FF1D4ED8'],
    'other'  => ['FFFDEDD8', 'FFC2650A'],
];
function uk_kind($KINDS, $code) {
    $k = (string)($KINDS[$code] ?? 'other');
    return isset($GLOBALS['KIND_COLOR'][$k]) ? $k : 'other';
}

$title = ($name !== '' ? $name : '出場選手一覧') . ($date !== '' ? '（' . $date . '）' : '');
$fbase = '出場選手一覧_' . ($name !== '' ? $name : $id) . ($date !== '' ? '_' . $date : '');
$fbase = preg_replace('/[\\\\\\/:*?"<>|]/u', '_', $fbase);

/* 日本語のファイル名は、そのままだと文字化けするブラウザがあるので両方の書き方で渡す。
   filename= のほうは読めない環境向けの控えなので、拡張子だけ合わせておく。 */
function uk_send_filename($fn) {
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION)) ?: 'xlsx';
    header('Content-Disposition: attachment; filename="program.' . $ext . '"; '
         . "filename*=UTF-8''" . rawurlencode($fn));
    header('Cache-Control: no-store');
}

/* ── ZipArchive が無いサーバー向け（CSVで返す） ── */
if (!class_exists('ZipArchive')) {
    header('Content-Type: text/csv; charset=UTF-8');
    uk_send_filename($fbase . '.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['区分コード', '区分名', '種目', '背番号', 'リーダー', 'パートナー', '所属']);
    foreach ($codes as $code) {
        foreach (uk_entries_for($roster, $code) as $r) {
            fputcsv($out, [$code, $evName[$code], uk_dance_text($dances[$code] ?? ''),
                           $r['bib'] ?? '', $r['leader'] ?? '', $r['partner'] ?? '', $r['affiliation'] ?? '']);
        }
    }
    fclose($out);
    exit;
}

/* ── ここから xlsx ── */

function uk_x($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* 列番号（1始まり）→ A, B, … AA */
function uk_col($n) {
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1 - $m, 26); }
    return $s;
}

/* Excelの列幅は「文字数」。px から直す（px = 文字数 * 7 + 5） */
function uk_width_px($px) { return round(max(1, ($px - 5) / 7) * 100) / 100; }

/* セル1つ。数字は数値として入れる（背番号が 1,10,11,2 の順に並ばないように）。 */
function uk_cell($col, $row, $v, $style = 0) {
    $ref = uk_col($col) . $row;
    $st  = $style ? ' s="' . $style . '"' : '';
    if ($v === null || $v === '') return '<c r="' . $ref . '"' . $st . '/>';
    if (is_int($v) || (is_string($v) && preg_match('/^\d{1,9}$/', $v))) {
        return '<c r="' . $ref . '"' . $st . '><v>' . (int)$v . '</v></c>';
    }
    return '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">'
         . uk_x($v) . '</t></is></c>';
}

/* ── スタイル番号 ──
   0 既定 / 1 太字（大会名）/ 2 名簿の見出し / 3 背番号 / 4 氏名 / 5 所属 / 6 記入欄
   7以降 区分の帯（系統ごとに 区分名・種目 の2つ） */
$S_TH = 2; $S_BIB = 3; $S_NM = 4; $S_AFF = 5; $S_MEMO = 6;
$KIND_LIST = array_keys($KIND_COLOR);
$S_TTL = [];   /* kind => [区分名のstyle, 種目のstyle] */
foreach ($KIND_LIST as $i => $k) $S_TTL[$k] = [7 + $i * 2, 8 + $i * 2];

/* ── 1枚目：紙と同じ体裁 ── */
$rowsXml = '';
$merges  = [];
$r = 0;
$TOTAL_C = $cols * $NC + ($cols - 1);          /* 段のあいだに1列すきまを入れる */
$HALF    = (int)ceil($TOTAL_C / 2);

/* 紙の見出し（大会名と日付） */
$r++;
$rowsXml .= '<row r="' . $r . '" ht="' . round($H_TITLE, 2) . '" customHeight="1">'
          . uk_cell(1, $r, $title, 1) . '</row>';
$merges[] = 'A' . $r . ':' . uk_col($TOTAL_C) . $r;
$r++;
$rowsXml .= '<row r="' . $r . '" ht="' . round($H_GAP, 2) . '" customHeight="1"/>';

/* 段ごとの先頭の列番号（1始まり） */
$colStart = [];
for ($c = 0; $c < $cols; $c++) $colStart[] = $c * ($NC + 1) + 1;

$HEADS = $memo ? ['記入欄', '背番号', 'リーダー', 'パートナー', '所属']
               : ['背番号', 'リーダー', 'パートナー', '所属'];

foreach ($codes as $code) {
    $list = uk_entries_for($roster, $code);
    $kind = uk_kind($KINDS, $code);
    $nm   = $code . ($evName[$code] !== '' ? '　' . $evName[$code] : '');
    $d    = uk_dance_text($dances[$code] ?? '');

    /* 区分の帯。左半分に区分名、右半分に種目（紙では帯の右端に出している） */
    $r++;
    $cells = uk_cell(1, $r, $nm, $S_TTL[$kind][0]);
    for ($c = 2; $c <= $HALF; $c++)          $cells .= uk_cell($c, $r, '', $S_TTL[$kind][0]);
    $cells .= uk_cell($HALF + 1, $r, $d !== '' ? '種目　' . $d : '', $S_TTL[$kind][1]);
    for ($c = $HALF + 2; $c <= $TOTAL_C; $c++) $cells .= uk_cell($c, $r, '', $S_TTL[$kind][1]);
    $rowsXml .= '<row r="' . $r . '" ht="' . round($H_TITLE, 2) . '" customHeight="1">' . $cells . '</row>';
    $merges[] = 'A' . $r . ':' . uk_col($HALF) . $r;
    if ($TOTAL_C > $HALF + 1) $merges[] = uk_col($HALF + 1) . $r . ':' . uk_col($TOTAL_C) . $r;

    if (!$list) {
        $r++;
        $rowsXml .= '<row r="' . $r . '" ht="' . round($H_DATA, 2) . '" customHeight="1">'
                  . uk_cell(1, $r, 'エントリーはありません。') . '</row>';
        $r++;
        $rowsXml .= '<row r="' . $r . '" ht="' . round($H_GAP, 2) . '" customHeight="1"/>';
        continue;
    }

    /* 見出しの行。段の数だけ繰り返す。 */
    $r++;
    $cells = '';
    foreach ($colStart as $cs) {
        foreach ($HEADS as $i => $h) $cells .= uk_cell($cs + $i, $r, $h, $S_TH);
    }
    $rowsXml .= '<row r="' . $r . '" ht="' . round($H_HEAD, 2) . '" customHeight="1">' . $cells . '</row>';

    /* 紙と同じ配り方。左の段に前半、右の段に後半。端数は左の段から1組ずつ。 */
    $n    = count($list);
    $rows = (int)ceil($n / $cols);
    $base = intdiv($n, $cols);
    $rem  = $n % $cols;
    $part = [];
    $at   = 0;
    for ($c = 0; $c < $cols; $c++) {
        $take   = $base + ($c < $rem ? 1 : 0);
        $part[] = array_slice($list, $at, $take);
        $at    += $take;
    }

    for ($i = 0; $i < $rows; $i++) {
        $cells = '';
        foreach ($colStart as $c => $cs) {
            $e = $part[$c][$i] ?? null;
            if (!$e) continue;            /* 右の段が余ったところは、紙と同じで空けておく */
            $k = $cs;
            if ($memo) $cells .= uk_cell($k++, $r + 1, '', $S_MEMO);
            $cells .= uk_cell($k++, $r + 1, (string)($e['bib'] ?? ''), $S_BIB);
            $cells .= uk_cell($k++, $r + 1, (string)($e['leader'] ?? ''), $S_NM);
            $cells .= uk_cell($k++, $r + 1, (string)($e['partner'] ?? ''), $S_NM);
            $cells .= uk_cell($k++, $r + 1, (string)($e['affiliation'] ?? ''), $S_AFF);
        }
        $r++;
        $rowsXml .= '<row r="' . $r . '" ht="' . round($H_DATA, 2) . '" customHeight="1">' . $cells . '</row>';
    }

    /* 区分と区分のあいだ */
    $r++;
    $rowsXml .= '<row r="' . $r . '" ht="' . round($H_GAP, 2) . '" customHeight="1"/>';
}

$colsXml = '';
for ($c = 0; $c < $cols; $c++) {
    foreach ($W as $i => $px) {
        $ci = $colStart[$c] + $i;
        $colsXml .= '<col min="' . $ci . '" max="' . $ci . '" width="' . uk_width_px($px) . '" customWidth="1"/>';
    }
    if ($c < $cols - 1) {
        $ci = $colStart[$c] + $NC;
        $colsXml .= '<col min="' . $ci . '" max="' . $ci . '" width="' . uk_width_px($GAPW) . '" customWidth="1"/>';
    }
}

$sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
  /* 「横1ページに収める」を効かせるには、この宣言も要る（pageSetup だけでは無視される） */
  . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
  . '<dimension ref="A1:' . uk_col($TOTAL_C) . max(1, $r) . '"/>'
  . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"/></sheetViews>'
  . '<sheetFormatPr defaultRowHeight="' . round($H_DATA, 2) . '"/>'
  . '<cols>' . $colsXml . '</cols>'
  . '<sheetData>' . $rowsXml . '</sheetData>'
  . ($merges ? '<mergeCells count="' . count($merges) . '">'
      . implode('', array_map(function ($m) { return '<mergeCell ref="' . $m . '"/>'; }, $merges))
      . '</mergeCells>' : '')
  /* Excelから刷ったときも紙と同じ向き・余白になるようにしておく */
  . '<pageMargins left="0.3937" right="0.3937" top="0.3937" bottom="0.3937" header="0" footer="0"/>'
  . '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>'
  . '</worksheet>';

$sheets = [['出場選手一覧', $sheet1]];

/* ── styles.xml ── */
$fonts = [
    '<font><sz val="11"/><color theme="1"/><name val="Yu Gothic"/><family val="3"/></font>',                 /* 0 */
    '<font><b/><sz val="11"/><color theme="1"/><name val="Yu Gothic"/><family val="3"/></font>',             /* 1 */
    '<font><b/><sz val="' . $F_TH  . '"/><color theme="1"/><name val="Yu Gothic"/><family val="3"/></font>', /* 2 見出し */
    '<font><b/><sz val="' . $F_BIB . '"/><color theme="1"/><name val="Yu Gothic"/><family val="3"/></font>', /* 3 背番号 */
    '<font><b/><sz val="' . $F_NM  . '"/><color theme="1"/><name val="Yu Gothic"/><family val="3"/></font>', /* 4 氏名 */
    '<font><sz val="'    . $F_AFF . '"/><color theme="1"/><name val="Yu Gothic"/><family val="3"/></font>',  /* 5 所属 */
];
$fontOf = [];   /* kind => [区分名のfontId, 種目のfontId] */
foreach ($KIND_LIST as $k) {
    $bar = $KIND_COLOR[$k][1];
    $fonts[] = '<font><b/><sz val="' . $F_TTL . '"/><color rgb="' . $bar . '"/><name val="Yu Gothic"/><family val="3"/></font>';
    $fonts[] = '<font><b/><sz val="' . $F_DAN . '"/><color rgb="' . $bar . '"/><name val="Yu Gothic"/><family val="3"/></font>';
    $fontOf[$k] = [count($fonts) - 2, count($fonts) - 1];
}

$fills = [
    '<fill><patternFill patternType="none"/></fill>',
    '<fill><patternFill patternType="gray125"/></fill>',
    '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>',  /* 2 見出し */
];
$fillOf = [];
foreach ($KIND_LIST as $k) {
    $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="' . $KIND_COLOR[$k][0]
             . '"/><bgColor indexed="64"/></patternFill></fill>';
    $fillOf[$k] = count($fills) - 1;
}

$thin = '<color rgb="FFB9C2CF"/>';
$borders = [
    '<border><left/><right/><top/><bottom/><diagonal/></border>',
    '<border><left style="thin">' . $thin . '</left><right style="thin">' . $thin . '</right>'
    . '<top style="thin">' . $thin . '</top><bottom style="thin">' . $thin . '</bottom><diagonal/></border>',
];
$borderOf = [];   /* 帯の左の太い罫（紙と同じ） */
foreach ($KIND_LIST as $k) {
    $borders[] = '<border><left style="medium"><color rgb="' . $KIND_COLOR[$k][1] . '"/></left>'
               . '<right/><top/><bottom/><diagonal/></border>';
    $borderOf[$k] = count($borders) - 1;
}

$xfs = [
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',                                                                                                                      /* 0 既定 */
    '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center" shrinkToFit="1"/></xf>',                                                                                                        /* 1 大会名 */
    '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" shrinkToFit="1"/></xf>',  /* 2 名簿の見出し */
    '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" shrinkToFit="1"/></xf>',                /* 3 背番号 */
    '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" shrinkToFit="1"/></xf>',                                    /* 4 氏名 */
    '<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" shrinkToFit="1"/></xf>',                                    /* 5 所属 */
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" shrinkToFit="1"/></xf>',                                                                                                      /* 6 記入欄 */
];
foreach ($KIND_LIST as $k) {
    $xfs[] = '<xf numFmtId="0" fontId="' . $fontOf[$k][0] . '" fillId="' . $fillOf[$k] . '" borderId="' . $borderOf[$k]
           . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
           . '<alignment horizontal="left" vertical="center" indent="1" shrinkToFit="1"/></xf>';
    $xfs[] = '<xf numFmtId="0" fontId="' . $fontOf[$k][1] . '" fillId="' . $fillOf[$k] . '" borderId="0"'
           . ' xfId="0" applyFont="1" applyFill="1" applyAlignment="1">'
           . '<alignment horizontal="right" vertical="center" indent="1" shrinkToFit="1"/></xf>';
}

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
  . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
  . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
  . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
  . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
  . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
  . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
  . '</styleSheet>';

/* ── zip に詰める ── */
$tmp = tempnam(sys_get_temp_dir(), 'ukxlsx');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    json_out(['error' => 'Excelファイルを作れませんでした'], 500);
}

$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
  . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
  . '<Default Extension="xml" ContentType="application/xml"/>'
  . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
  . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
  . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
foreach ($sheets as $i => $s) {
    $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" '
         . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}
$zip->addFromString('[Content_Types].xml', $ct . '</Types>');

$zip->addFromString('_rels/.rels',
  '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
  . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
  . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
  . '</Relationships>');

$zip->addFromString('docProps/core.xml',
  '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
  . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
  . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
  . '<dc:title>' . uk_x($title) . '</dc:title>'
  . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
  . '</cp:coreProperties>');

$wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
  . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
$rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
  . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
foreach ($sheets as $i => $s) {
    $n = $i + 1;
    $wb  .= '<sheet name="' . uk_x($s[0]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    $rel .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
          . 'Target="worksheets/sheet' . $n . '.xml"/>';
    $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $s[1]);
}
$sn = count($sheets) + 1;
$rel .= '<Relationship Id="rId' . $sn . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
$zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
$zip->addFromString('xl/_rels/workbook.xml.rels', $rel . '</Relationships>');
$zip->addFromString('xl/styles.xml', $stylesXml);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
uk_send_filename($fbase . '.xlsx');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
