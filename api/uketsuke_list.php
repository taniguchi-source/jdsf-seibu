<?php
/* 大会の一覧を返す（役員ページにログイン済みのみ）。 */
require __DIR__ . '/_uketsuke.php';
uk_require_read();

$list = uk_load_list();
/* 各大会の件数も添えて、選択画面でそのまま表示できるようにする */
foreach ($list as &$c) {
    $has_code = !empty($c['code_hash']);
    unset($c['code_hash']);          /* 公認番号のハッシュは画面へ返さない */
    $id = $c['id'] ?? '';
    if (!uk_valid_id($id)) { $c['roster_count'] = 0; $c['checkin_count'] = 0; continue; }
    /* 公認番号が設定されていて、まだこのセッションで開いていない大会だけ入力を求める */
    $c['need_code']     = $has_code;   /* 公認番号がある大会は、開くたびに必ず公認番号を要求する */
    /* ただし、このセッションで既に開いた大会かどうかも返す。
       受付の途中で欠場者一覧などへ行き来するたびに番号を求めると仕事にならないので、
       別ページのタブから戻ってきたときだけ、画面側がこれを見て聞き直さずに開く。 */
    $c['unlocked']      = $has_code ? uk_comp_unlocked($id) : true;
    $c['roster_count']  = count(uk_load_roster($id));
    /* 受付は種目単位だが、一覧に出すのは「来ている組」の数なので組で数え直す */
    $c['checkin_count'] = count(uk_checked_bibs(uk_load_checkins($id)));
}
unset($c);

json_out(['ok' => true, 'competitions' => $list]);
