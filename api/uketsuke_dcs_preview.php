<?php
/* DCSの2ファイル（SSS__I.dat / SSS__MEM.dat）を読んで中身を返す。保存はしない。
   画面はこれを使って、大会名・開催日・区分を入力欄に入れ、名簿を持っておく。
   大会を作ったあとに uketsuke_save_roster.php へ名簿を送る（CSV取込と同じ流れ）。 */
require __DIR__ . '/_uketsuke.php';
require __DIR__ . '/_uketsuke_dcs.php';
/* 受付システムの他のAPIと同じ顔ぶれ。受付（競技用）のパスワードでも
   大会の作成・名簿の保存はできるので、その元になるファイルの読み取りだけを
   役員に限る理由がない。ここが admin だけだと、受付のパスワードで入った人は
   「読み込めませんでした：Forbidden」になって大会を作れない。 */
require_auth_any(['admin', 'build', 'uketsuke']);

$a = uk_dcs_upload('info', 'SSS__I.dat');
$b = uk_dcs_upload('mem',  'SSS__MEM.dat');

/* 2つを逆に選んでも通るようにする（どちらが大会情報かは中身で分かる） */
if (strpos(substr($a, 0, 64), '#DCSsys') === false && strpos(substr($b, 0, 64), '#DCSsys') !== false) {
    $t = $a; $a = $b; $b = $t;
}

$r = uk_dcs_read_pair($a, $b);
if (isset($r['error'])) json_out(['error' => $r['error']], 400);

json_out([
    'ok'     => true,
    'name'   => $r['name'],
    'date'   => $r['date'],
    'comp_no' => $r['comp_no'],   /* DCSの大会番号。公認番号として使う */
    'venue'  => $r['venue'],
    'host'   => $r['host'],       /* 主催。確認表で「別の大会では？」に気づくために出す */
    'long_names' => $r['long_names'],   /* 16バイト超の氏名を別欄から復元した件数 */
    'events' => $r['events'],     /* コード・名称・並び順（受付締切は空） */
    'roster' => $r['roster'],     /* 背番号・氏名・所属・出場区分 */
]);
