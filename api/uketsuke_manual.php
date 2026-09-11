<?php
/* 手動対応の一覧に、人が足す／メモを書く。
   自動で溜まるぶん（初期振分のあとに受付・取消をしようとして止まった件）は
   uketsuke_checkin.php / uketsuke_uncheckin.php が書く。
   チェックインと同じ追記方式なので、複数端末で同時に操作しても記録が壊れない。
     op=add  : 背番号と区分を指定して1件足す（電話で欠場連絡が来たときなど）
     op=note : その件のメモを書く（メモが入った件は対応済みとして扱う。空にすると未対応へ戻る） */
require __DIR__ . '/_uketsuke.php';
require_auth_any(['admin', 'build', 'uketsuke']);

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

$op   = $_POST['op'] ?? '';
$bib  = (int)($_POST['bib'] ?? 0);
$code = uk_str($_POST['code'] ?? '', 20);
$by   = uk_str($_POST['by'] ?? '', 20);

if ($bib <= 0) json_out(['error' => '背番号を入力してください'], 400);
if ($code === '') json_out(['error' => '区分を指定してください'], 400);

/* 名簿に無い背番号・この大会に無い区分は受け付けない（幽霊レコードを作らない） */
$person = null;
foreach (uk_load_roster($id) as $r) {
    if ((int)($r['bib'] ?? 0) === $bib) { $person = $r; break; }
}
if (!$person) json_out(['error' => "背番号 {$bib} は名簿にありません"], 404);
$known = false;
foreach (uk_load_events($id) as $e) {
    if (($e['code'] ?? '') === $code) { $known = true; break; }
}
if (!$known) json_out(['error' => "区分 {$code} はこの大会にありません"], 400);

if ($op === 'add') {
    /* エントリーしていない区分を足しても進行席が困るだけなので弾く */
    $entry = array_values((array)($person['events'] ?? []));
    if (!in_array($code, $entry, true)) {
        json_out(['error' => "背番号 {$bib} は {$code} にエントリーしていません"], 400);
    }
    uk_append_checkin($id, ['action' => 'manual', 'bib' => $bib, 'code' => $code,
                            'kind' => 'add', 'at' => uk_now(), 'by' => $by]);
    json_out(['ok' => true, 'bib' => $bib, 'code' => $code]);
}

if ($op === 'note') {
    $note = uk_str($_POST['note'] ?? '', 200);
    uk_append_checkin($id, ['action' => 'manual_note', 'bib' => $bib, 'code' => $code,
                            'note' => $note, 'at' => uk_now(), 'by' => $by]);
    json_out(['ok' => true, 'bib' => $bib, 'code' => $code, 'note' => $note]);
}

json_out(['error' => 'op が不正です'], 400);
