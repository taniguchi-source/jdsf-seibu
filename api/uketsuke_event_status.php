<?php
/* 種目ごとの出場・欠場（例外処理）の記録。
   1つの組が複数種目に出ているとき、用事などで一部の種目だけ欠場することがまれにある。
   チェックインと同じく追記方式なので、複数端末で同時に操作しても記録が壊れない。 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

$bib = (int)($_POST['bib'] ?? 0);
if ($bib <= 0) json_out(['error' => '背番号が不正です'], 400);

$code = uk_str($_POST['code'] ?? '', 20);
if ($code === '') json_out(['error' => '種目コードを指定してください'], 400);

/* 名簿に無い背番号や、その組が出ていない種目は受け付けない */
$person = null;
foreach (uk_load_roster($id) as $r) {
    if ((int)($r['bib'] ?? 0) === $bib) { $person = $r; break; }
}
if (!$person) json_out(['error' => "背番号 {$bib} は名簿にありません"], 404);
if (!in_array($code, (array)($person['events'] ?? []), true)) {
    json_out(['error' => "背番号 {$bib} は {$code} にエントリーしていません"], 400);
}

$absent = !empty($_POST['absent']) && $_POST['absent'] !== 'false' && $_POST['absent'] !== '0';

uk_append_checkin($id, [
    'action' => 'evstat',
    'bib'    => $bib,
    'code'   => $code,
    'absent' => $absent,
    'at'     => uk_now(),
    'by'     => uk_str($_POST['by'] ?? '', 20),
]);

json_out(['ok' => true, 'bib' => $bib, 'code' => $code, 'absent' => $absent]);
