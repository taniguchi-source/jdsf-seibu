<?php
/* 選手名簿の保存。
   mode=replace : 名簿をまるごと置き換える（CSV取込・全体編集）
   mode=upsert  : 送った背番号の行だけ追加・更新する（1組だけの手直し）
   mode=delete  : 送った背番号の行を削除する
   名簿に無い種目コードが来たら、種目マスタへ仮登録（名称＝コード）する。 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);

$mode = $_POST['mode'] ?? 'replace';
$rows = json_decode($_POST['roster'] ?? '[]', true);
if (!is_array($rows)) json_out(['error' => '名簿データの形式が不正です'], 400);

/* 受け取った行を正規化する */
$clean = [];
$dupes = [];
foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $bib = (int)($r['bib'] ?? 0);
    if ($bib <= 0 || $bib > 9999) continue;              /* DCSの背番号は1〜999 */
    if (isset($clean[$bib])) $dupes[] = $bib;            /* 同一CSV内の重複は後勝ち */
    $events = [];
    if (isset($r['events']) && is_array($r['events'])) {
        foreach ($r['events'] as $c) {
            $c = mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$c), 0, 20);
            if ($c !== '') $events[$c] = true;
        }
    }
    $clean[$bib] = [
        'bib'         => $bib,
        'leader'      => uk_str($r['leader'] ?? '', 40),
        'partner'     => uk_str($r['partner'] ?? '', 40),
        'category'    => uk_str($r['category'] ?? '', 60),
        'affiliation' => uk_str($r['affiliation'] ?? '', 60),
        'events'      => array_keys($events),
    ];
    if (count($clean) >= 1200) break;
}

$current = [];
foreach (uk_load_roster($id) as $r) {
    $b = (int)($r['bib'] ?? 0);
    if ($b > 0) $current[$b] = $r;
}

if ($mode === 'replace') {
    $merged = $clean;
} elseif ($mode === 'delete') {
    $merged = $current;
    foreach ($clean as $bib => $_) unset($merged[$bib]);
} else {                                   /* upsert */
    $merged = $current;
    foreach ($clean as $bib => $r) $merged[$bib] = $r;
}

ksort($merged, SORT_NUMERIC);
uk_write_json(uk_roster_file($id), array_values($merged));

/* 名簿にあって種目マスタに無いコードを仮登録する */
$events = uk_load_events($id);
$known  = [];
foreach ($events as $e) $known[$e['code'] ?? ''] = true;
$added = [];
foreach ($merged as $r) {
    foreach (($r['events'] ?? []) as $c) {
        if ($c !== '' && !isset($known[$c])) {
            $known[$c] = true;
            $added[]   = $c;
            $events[]  = ['code' => $c, 'name' => $c, 'start_time' => ''];
        }
    }
}
if ($added) uk_write_json(uk_events_file($id), $events);

json_out([
    'ok'                 => true,
    'count'              => count($merged),
    'new_events_created' => $added,
    'duplicate_bibs'     => array_values(array_unique($dupes)),
]);
