<?php
/* 種目マスタの保存（画面上の一覧をまるごと置き換える）。
   events は JSON文字列で1フィールドに入れて POST する（既存の save_schedule.php と同じ方式）。 */
require __DIR__ . '/_uketsuke.php';
require_auth_any(['admin', 'build', 'uketsuke']);

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
uk_require_comp($id);   /* 公認番号で開いた大会のみ操作できる */

$rows = json_decode($_POST['events'] ?? '[]', true);
if (!is_array($rows)) json_out(['error' => '種目データの形式が不正です'], 400);

$out  = [];
$seen = [];
foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($r['code'] ?? ''));
    $code = mb_substr($code, 0, 20);
    if ($code === '' || isset($seen[$code])) continue;   /* 空・重複コードは捨てる */
    $seen[$code] = true;
    $out[] = [
        'code'       => $code,
        'name'       => uk_str($r['name'] ?? $code, 60),
        'start_time' => uk_str($r['start_time'] ?? '', 10),
    ];
    if (count($out) >= 60) break;   /* DCSの上限40区分に対して十分な上限 */
}

uk_write_json(uk_events_file($id), $out);
json_out(['ok' => true, 'count' => count($out)]);
