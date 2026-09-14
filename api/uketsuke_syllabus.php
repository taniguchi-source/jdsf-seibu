<?php
/* 大会の種目（シラバスの競技内容）を返す。
   GET  : 控えを返す。無ければ自サイトの競技会一覧から作って返す（外部通信なし）。
   POST action=refresh : JDSF（adm.jdsf.jp）へ1回だけ取りに行って控えを作り直す。
                         連続アクセスで遮断される相手なので、画面のボタンからだけ呼ぶ。 */
require __DIR__ . '/_uketsuke.php';
require __DIR__ . '/_uketsuke_syllabus.php';

$id = $_POST['id'] ?? ($_GET['id'] ?? '');
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);

$refresh = (($_POST['action'] ?? '') === 'refresh');

if ($refresh) {
    /* 取りに行くのは書き込みと同じ扱い（POST＋同一オリジン＋CSRF＋ログイン） */
    require_auth_any(['admin', 'build', 'uketsuke']);
} else {
    uk_require_read();
}
uk_require_comp($id);   /* 合言葉で開いた大会のみ */

/* 大会の情報（公認番号・開催日・大会名）を一覧から取る */
$comp = null;
foreach (uk_load_list() as $c) {
    if (($c['id'] ?? '') === $id) { $comp = $c; break; }
}
if (!$comp) json_out(['error' => '大会が見つかりません'], 404);
$comp_no = (string)($comp['comp_no'] ?? '');
$date    = (string)($comp['date'] ?? '');
$name    = (string)($comp['name'] ?? '');

if ($refresh) {
    $got = uk_syllabus_from_jdsf($comp_no);
    if (isset($got['error'])) {
        /* 取りに行けなくても、自サイトの一覧で作れるならそれを返す */
        $fall = uk_syllabus_ensure($id, $comp_no, $date, $name);
        json_out(['ok' => false, 'error' => $got['error'],
                  'syllabus' => $fall, 'comp_no' => $comp_no], 200);
    }
    uk_syllabus_save($id, $got);
    json_out(['ok' => true, 'syllabus' => $got, 'comp_no' => $comp_no]);
}

$syl = uk_syllabus_ensure($id, $comp_no, $date, $name);
json_out(['ok' => true, 'syllabus' => $syl, 'comp_no' => $comp_no,
          'can_refresh' => uk_valid_comp_no($comp_no)]);
