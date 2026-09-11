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

/* 初期振分が済んだ区分は、取り消しても DCS 側は自動では直らないので止める。 */
$assign  = uk_load_assign($id);
$blocked = [];
if ($code !== '') {
    if (isset($assign[$code])) $blocked[] = $code;
} else {
    foreach ($checkins as $c) {
        if ((int)($c['bib'] ?? 0) !== $bib) continue;
        $cc = (string)($c['code'] ?? '');
        if ($cc !== '' && isset($assign[$cc]) && !in_array($cc, $blocked, true)) $blocked[] = $cc;
    }
}
if ($blocked) {
    /* 止まった件は「手動対応」の一覧に残す。その場の画面だけでは伝え漏れるため。 */
    foreach ($blocked as $c) {
        uk_append_checkin($id, ['action' => 'manual', 'bib' => $bib, 'code' => $c,
                                'kind' => 'uncheckin', 'reason' => uk_manual_reason('uncheckin'),
                            'at' => uk_now(),
                                'by' => uk_str($_POST['by'] ?? '', 20)]);
    }
    json_out(['error' => uk_assigned_message($blocked)], 409);
}

$rec = ['action' => 'remove', 'bib' => $bib];
if ($code !== '') $rec['code'] = $code;
$rec['at'] = uk_now();
$rec['by'] = uk_str($_POST['by'] ?? '', 20);
uk_append_checkin($id, $rec);

json_out(['ok' => true, 'bib' => $bib, 'code' => $code]);
