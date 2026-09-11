<?php
/* 1大会分のデータ（区分・名簿・チェックイン状況）を返す。
   受付画面・出場欠場画面はこれを数秒ごとに取得して最新状態を表示する。 */
require __DIR__ . '/_uketsuke.php';
uk_require_read();

$id = $_GET['id'] ?? ($_POST['id'] ?? '');
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

$events   = uk_load_events($id);
$roster   = uk_load_roster($id);
$checkins = uk_load_checkins($id);
$stock    = uk_load_stock($id);
$assign   = uk_load_assign($id);
$manual   = uk_load_manual($id);

/* 一覧から大会名を引く */
$name = '';
$date = '';
foreach (uk_load_list() as $c) {
    if (($c['id'] ?? '') === $id) { $name = $c['name'] ?? ''; $date = $c['date'] ?? ''; break; }
}

json_out([
    'ok'         => true,
    'id'         => $id,
    'name'       => $name,
    'date'       => $date,
    'events'     => $events,
    'roster'     => $roster,
    /* 受付は区分単位。1件＝「背番号:区分」ひとつぶんの受付記録 */
    'checkins'   => array_values($checkins),
    'stock'      => array_values($stock),
    /* 区分ごとの「ST初期振分が済んだ」記録 */
    'assign'     => array_values($assign),
    /* 手動対応が要る件（初期振分のあとに受付・取消をしようとして止まった件） */
    'manual'     => array_values($manual),
    /* 欠場者一覧ページ（読むだけのページ）から初期振分を記録できるようにトークンを渡す。
       書き込みAPI側のロール確認は従来どおり効く。 */
    'csrf'       => issue_csrf(),
    'updated_at' => uk_now(),
]);
