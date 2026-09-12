<?php
/* 大会を複製する（練習用の大会を作るため）。
   名簿と区分マスタはそのまま引き継ぎ、受付記録は引き継がない。
   元の大会は公認番号で開いていること（uk_require_comp）を条件にするので、
   番号を知らない人が名簿を別の大会へ複製することはできない。
   新しい大会は一覧の先頭に入れる（練習用がいつも上に来るように）。 */
require __DIR__ . '/_uketsuke.php';
require_auth_any(['admin', 'build', 'uketsuke']);

$src = $_POST['id'] ?? '';
if (!uk_valid_id($src)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($src);

$name = uk_str($_POST['name'] ?? '', 60);
if ($name === '') json_out(['error' => '大会名を入力してください'], 400);
$code = uk_norm_code($_POST['code'] ?? '');
if ($code === '') json_out(['error' => '公認番号を入力してください'], 400);

$list = uk_load_list();
$from = null;
foreach ($list as $c) {
    if (($c['id'] ?? '') === $src) { $from = $c; break; }
}
if (!$from) json_out(['error' => '元の大会が見つかりません'], 404);
$date = uk_str($_POST['date'] ?? '', 20);
if ($date === '') $date = uk_str($from['date'] ?? '', 20);

/* IDは作成と同じ作法（日付＋連番）。同じ日の大会が複数あっても衝突しない。 */
$base = preg_replace('/[^0-9]/', '', $date);
if ($base === '') $base = date('Ymd');
$id = $base;
$n  = 1;
$used = [];
foreach ($list as $c) $used[$c['id'] ?? ''] = true;
while (isset($used[$id])) { $n++; $id = $base . '-' . $n; }

$entry = ['id' => $id, 'name' => $name, 'date' => $date, 'created_at' => uk_now(),
          'copied_from' => $src,
          'code_hash' => password_hash($code, PASSWORD_DEFAULT)];

/* 名簿と区分だけを引き継ぐ。受付記録（checkins.jsonl）は作らないので、練習は白紙から始まる。 */
$events = uk_load_events($src);
$roster = uk_load_roster($src);
uk_write_json(uk_events_file($id), $events);
uk_write_json(uk_roster_file($id), $roster);

array_unshift($list, $entry);   /* 一覧の先頭に置く */
uk_save_list($list);

/* 作った本人はそのまま開けるようにする */
uk_unlock_comp($id);

json_out(['ok' => true, 'id' => $id, 'name' => $name, 'date' => $date,
          'events' => count($events), 'roster' => count($roster)]);
