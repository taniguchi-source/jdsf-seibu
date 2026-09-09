<?php
/* 受付のチェックイン。受付は区分単位で記録する。
   - code を指定するとその区分だけ、all=1 ならその組のエントリー区分すべてを受付する
   - 名簿に無い背番号・エントリーしていない区分は受け付けない（幽霊レコードを作らない）
   - 既に受付済みの区分は二重に書かない（already で返す）
   - 記録は1行ずつの追記なので、複数の受付端末が同時に押しても取りこぼさない */
require __DIR__ . '/_uketsuke.php';
require_auth_any(['admin', 'build', 'uketsuke']);

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
$entry = array_values((array)($person['events'] ?? []));

$code = uk_str($_POST['code'] ?? '', 20);
if ($code === '') {
    /* 区分の指定なしで全区分が入ってしまわないよう、まとめて受付するときは all=1 を必ず付ける */
    if (empty($_POST['all'])) json_out(['error' => '区分を指定してください'], 400);
    if (!$entry) json_out(['error' => "背番号 {$bib} は出場区分が登録されていません"], 400);
    $codes = $entry;
} else {
    if (!in_array($code, $entry, true)) {
        json_out(['error' => "背番号 {$bib} は {$code} にエントリーしていません"], 400);
    }
    $codes = [$code];
}

$checkins = uk_load_checkins($id);
$now = uk_now();
$by  = uk_str($_POST['by'] ?? '', 20);   /* 受付担当者名（表示用） */
$new = [];
$already = [];
foreach ($codes as $c) {
    if (isset($checkins[$bib . ':' . $c])) { $already[] = $c; continue; }
    uk_append_checkin($id, ['action' => 'checkin', 'bib' => $bib, 'code' => $c, 'at' => $now, 'by' => $by]);
    $new[] = $c;
}

/* いま受付済みの区分。画面はこれを使って、その組の状態をそのまま描き直せる */
$done = [];
foreach ($entry as $c) {
    if (in_array($c, $new, true) || isset($checkins[$bib . ':' . $c])) $done[] = $c;
}

json_out([
    'ok'          => true,
    'bib'         => $bib,
    'leader'      => $person['leader'] ?? '',
    'partner'     => $person['partner'] ?? '',
    'affiliation' => $person['affiliation'] ?? '',
    'events'      => $entry,      /* エントリー区分（受付で読み上げ確認に使う） */
    'done'        => $done,       /* うち受付済みの区分 */
    'new'         => $new,        /* この操作で受付した区分 */
    'already'     => $already,    /* 既に受付済みだった区分 */
    'at'          => $now,
]);
