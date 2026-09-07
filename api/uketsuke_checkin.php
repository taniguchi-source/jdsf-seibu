<?php
/* 受付のチェックイン。
   - 名簿に無い背番号は登録しない（幽霊レコードを作らない）
   - 既にチェックイン済みならエラーにせず already=true と受付時刻を返す
   - 記録は1行の追記なので、複数の受付端末が同時に押しても取りこぼさない */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

$bib = (int)($_POST['bib'] ?? 0);
if ($bib <= 0) json_out(['error' => '背番号を入力してください'], 400);

$person = null;
foreach (uk_load_roster($id) as $r) {
    if ((int)($r['bib'] ?? 0) === $bib) { $person = $r; break; }
}
if (!$person) json_out(['error' => "背番号 {$bib} は名簿にありません"], 404);

$checkins = uk_load_checkins($id);
if (isset($checkins[$bib])) {
    json_out([
        'ok'            => true,
        'already'       => true,
        'bib'           => $bib,
        'leader'        => $person['leader'] ?? '',
        'partner'       => $person['partner'] ?? '',
        'affiliation'   => $person['affiliation'] ?? '',
        'checked_in_at' => $checkins[$bib]['at'],
    ]);
}

$now = uk_now();
uk_append_checkin($id, [
    'bib' => $bib,
    'at'  => $now,
    'by'  => uk_str($_POST['by'] ?? '', 20),   /* 受付担当者名（表示用） */
]);

json_out([
    'ok'            => true,
    'already'       => false,
    'bib'           => $bib,
    'leader'        => $person['leader'] ?? '',
    'partner'       => $person['partner'] ?? '',
    'affiliation'   => $person['affiliation'] ?? '',
    'checked_in_at' => $now,
]);
