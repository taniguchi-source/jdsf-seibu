<?php
/* 大会の一覧を返す（役員ページにログイン済みのみ）。 */
require __DIR__ . '/_uketsuke.php';
uk_require_read();

$list = uk_load_list();
/* 各大会の件数も添えて、選択画面でそのまま表示できるようにする */
foreach ($list as &$c) {
    $id = $c['id'] ?? '';
    if (!uk_valid_id($id)) { $c['roster_count'] = 0; $c['checkin_count'] = 0; continue; }
    $c['roster_count']  = count(uk_load_roster($id));
    $c['checkin_count'] = count(uk_load_checkins($id));
}
unset($c);

json_out(['ok' => true, 'competitions' => $list]);
