<?php
/* チェックインの取り消し（1件だけ）。
   背番号を打ち間違えた場合の訂正に使う。
   記録は消さず「取り消し」の行を追記する（追記だけで完結させ、
   複数の受付端末が同時に操作していても壊れないようにするため）。 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);

$bib = (int)($_POST['bib'] ?? 0);
if ($bib <= 0) json_out(['error' => '背番号が不正です'], 400);

$checkins = uk_load_checkins($id);
if (!isset($checkins[$bib])) {
    json_out(['error' => "背番号 {$bib} はチェックインされていません"], 404);
}

uk_append_checkin($id, [
    'action' => 'remove',
    'bib'    => $bib,
    'at'     => uk_now(),
    'by'     => uk_str($_POST['by'] ?? '', 20),
]);

json_out(['ok' => true, 'bib' => $bib]);
