<?php
/* チェックインの全解除。
   ファイルを消さずに「ここまで無効」という行を追記する（記録の追記だけで完結させ、
   同時に受付操作が走っていても壊れないようにするため）。 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);

$before = count(uk_load_checkins($id));
uk_append_checkin($id, ['action' => 'clear', 'at' => uk_now(), 'by' => uk_str($_POST['by'] ?? '', 20)]);

json_out(['ok' => true, 'cleared' => $before]);
