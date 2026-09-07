<?php
/* 1大会分のデータ（種目・名簿・チェックイン状況）を返す。
   受付画面・出場欠場画面はこれを数秒ごとに取得して最新状態を表示する。 */
require __DIR__ . '/_uketsuke.php';
uk_require_read();

$id = $_GET['id'] ?? ($_POST['id'] ?? '');
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);

$events   = uk_load_events($id);
$roster   = uk_load_roster($id);
$checkins = uk_load_checkins($id);
$stock    = uk_load_stock($id);

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
    'checkins'   => array_values($checkins),
    'stock'      => array_values($stock),
    'updated_at' => uk_now(),
]);
