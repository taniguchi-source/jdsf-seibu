<?php
/* 区分ごとの「ST初期振分が済んだ」「受付を終了した（ST確認待ち）」の記録。
   kind=close は受付側が押す仮の締め、kind=assign（既定）はSTが押す確定。
   DCSで初期振分をすると、あとから受付した組は自動では組み込めない。
   済みにした区分は受付・取消を止め、手動対応に回す（uketsuke_checkin.php / uketsuke_uncheckin.php）。
   チェックインと同じく追記方式なので、複数端末で同時に操作しても記録が壊れない。 */
require __DIR__ . '/_uketsuke.php';
require_auth_any(['admin', 'build', 'uketsuke']);

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 合言葉で開いた大会のみ操作できる */

$code = uk_str($_POST['code'] ?? '', 20);
if ($code === '') json_out(['error' => '区分を指定してください'], 400);

/* 区分マスタに無いコードは受け付けない（幽霊レコードを作らない） */
$known = false;
foreach (uk_load_events($id) as $e) {
    if (($e['code'] ?? '') === $code) { $known = true; break; }
}
if (!$known) json_out(['error' => "区分 {$code} はこの大会にありません"], 400);

$on = !empty($_POST['on']) && $_POST['on'] !== 'false' && $_POST['on'] !== '0';

/* close＝受付終了（ST確認待ち）、assign＝初期振分の完了。どちらも同じ形で1行残す。 */
$kind = (($_POST['kind'] ?? '') === 'close') ? 'close' : 'assign';

/* 初期振分まで済んでいる区分の「受付終了」だけを動かしても意味が無いので断る
   （STの画面で「初期振分の完了」を解除してから） */
if ($kind === 'close') {
    $asg = uk_load_assign($id);
    if (isset($asg[$code])) {
        json_out(['error' => "区分 {$code} はすでに初期振分まで済んでいます"], 409);
    }
}

uk_append_checkin($id, [
    'action' => $kind,
    'code'   => $code,
    'on'     => $on,
    'at'     => uk_now(),
    'by'     => uk_str($_POST['by'] ?? '', 20),
]);

json_out(['ok' => true, 'code' => $code, 'on' => $on, 'kind' => $kind]);
