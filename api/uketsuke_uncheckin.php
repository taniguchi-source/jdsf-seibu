<?php
/* 受付の取り消し。code を指定するとその区分だけ、指定しなければその組の全区分を取り消す。
   背番号や区分を押し間違えた場合の訂正に使う。
   記録は消さず「取り消し」の行を追記する（追記だけで完結させ、
   複数の受付端末が同時に操作していても壊れないようにするため）。 */
require __DIR__ . '/_uketsuke.php';
require_auth_any(['admin', 'build', 'uketsuke']);

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

$bib = (int)($_POST['bib'] ?? 0);
if ($bib <= 0) json_out(['error' => '背番号が不正です'], 400);

$code     = uk_str($_POST['code'] ?? '', 20);
$checkins = uk_load_checkins($id);

if ($code !== '') {
    if (!isset($checkins[$bib . ':' . $code])) {
        json_out(['error' => "背番号 {$bib} の {$code} は受付されていません"], 404);
    }
} else {
    $has = false;
    foreach ($checkins as $c) { if ((int)($c['bib'] ?? 0) === $bib) { $has = true; break; } }
    if (!$has) json_out(['error' => "背番号 {$bib} は受付されていません"], 404);
}

$rec = ['action' => 'remove', 'bib' => $bib];
if ($code !== '') $rec['code'] = $code;
$rec['at'] = uk_now();
$rec['by'] = uk_str($_POST['by'] ?? '', 20);
uk_append_checkin($id, $rec);

json_out(['ok' => true, 'bib' => $bib, 'code' => $code]);
