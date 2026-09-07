<?php
/* 欠場者の背番号在庫チェックの記録。
   受付に背番号が残っている＝本当に来ていない、という確認に使う。
   チェックインと同じく追記方式なので、複数端末で同時に操作しても記録が壊れない。 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

$bib = (int)($_POST['bib'] ?? 0);
if ($bib <= 0) json_out(['error' => '背番号が不正です'], 400);

$on = !empty($_POST['on']) && $_POST['on'] !== 'false' && $_POST['on'] !== '0';

uk_append_checkin($id, [
    'action' => 'stock',
    'bib'    => $bib,
    'on'     => $on,
    'at'     => uk_now(),
    'by'     => uk_str($_POST['by'] ?? '', 20),
]);

json_out(['ok' => true, 'bib' => $bib, 'on' => $on]);
