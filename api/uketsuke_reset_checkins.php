<?php
/* チェックインの全解除。
   ファイルを消さずに「ここまで無効」という行を追記する（記録の追記だけで完結させ、
   同時に受付操作が走っていても壊れないようにするため）。 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

/* 受付をやり直せなくする操作なので、大会の削除と同じく役員ページのパスワードを求める。
   受付スタッフに端末を渡したまま誤って全解除される事故を防ぐため。 */
$pw   = (string)($_POST['password'] ?? '');
$auth = load_auth();
$hash = $auth['admin'] ?? '';
if ($pw === '' || $hash === '' || !password_verify($pw, $hash)) {
    json_out(['error' => '役員ページのパスワードが違います'], 401);
}

$before = count(uk_load_checkins($id));
uk_append_checkin($id, ['action' => 'clear', 'at' => uk_now(), 'by' => uk_str($_POST['by'] ?? '', 20)]);

json_out(['ok' => true, 'cleared' => $before]);
