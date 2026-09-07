<?php
/* 大会の作成・名称変更・削除。
   action=create : name,date,code(公認番号),events(種目) から新しい大会を作る（IDは自動採番）
   action=rename : id の名称・日付・公認番号を変更
   action=delete : id の大会をデータごと削除 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

/* 「コード」「コード,種目名」「コード,種目名,開始時刻」を1行ずつ受け取って種目マスタにする。
   種目名が省略されたらコードから推定する。 */
function uk_parse_event_lines($text) {
    $out  = [];
    $seen = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
        if (trim($line) === '') continue;
        $p    = preg_split('/[,\t]/', $line);
        $code = mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', trim($p[0] ?? '')), 0, 20);
        if ($code === '' || isset($seen[$code])) continue;
        $seen[$code] = true;
        $name = uk_str($p[1] ?? '', 60);
        $out[] = [
            'code'       => $code,
            'name'       => $name !== '' ? $name : uk_guess_event_name($code),
            'start_time' => uk_str($p[2] ?? '', 10),
        ];
        if (count($out) >= 60) break;
    }
    return $out;
}

$action = $_POST['action'] ?? '';
$list   = uk_load_list();

if ($action === 'create') {
    $name = uk_str($_POST['name'] ?? '', 60);
    $date = uk_str($_POST['date'] ?? '', 20);
    $code = uk_norm_code($_POST['code'] ?? '');
    if ($name === '') json_out(['error' => '大会名を入力してください'], 400);

    /* IDは日付＋連番（英数字のみ）。同名の大会が複数あっても衝突しない。 */
    $base = preg_replace('/[^0-9]/', '', $date);
    if ($base === '') $base = date('Ymd');
    $id = $base;
    $n = 1;
    $used = [];
    foreach ($list as $c) $used[$c['id'] ?? ''] = true;
    while (isset($used[$id])) { $n++; $id = $base . '-' . $n; }

    $entry = ['id' => $id, 'name' => $name, 'date' => $date, 'created_at' => uk_now()];
    /* 公認番号はそのまま保存せず、パスワードと同じくハッシュにして持つ */
    if ($code !== '') $entry['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $list[] = $entry;
    uk_save_list($list);

    $events = uk_parse_event_lines($_POST['events'] ?? '');
    uk_write_json(uk_events_file($id), $events);
    uk_write_json(uk_roster_file($id), []);
    if ($events) uk_save_last_event_codes(array_column($events, 'code'));

    /* 作った本人はそのまま開けるようにする */
    uk_unlock_comp($id);
    json_out(['ok' => true, 'id' => $id, 'events' => count($events)]);
}

if ($action === 'rename') {
    $id   = $_POST['id'] ?? '';
    $name = uk_str($_POST['name'] ?? '', 60);
    $date = uk_str($_POST['date'] ?? '', 20);
    if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
    if ($name === '') json_out(['error' => '大会名を入力してください'], 400);
    $code  = uk_norm_code($_POST['code'] ?? '');
    $found = false;
    foreach ($list as &$c) {
        if (($c['id'] ?? '') !== $id) continue;
        $c['name'] = $name;
        $c['date'] = $date;
        /* 公認番号は入力があったときだけ差し替える（空欄なら現状維持） */
        if ($code !== '') $c['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $found = true;
        break;
    }
    unset($c);
    if (!$found) json_out(['error' => '大会が見つかりません'], 404);
    uk_save_list($list);
    json_out(['ok' => true]);
}

if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);

    /* 名簿も受付記録もまとめて消える操作なので、二重に確認する。
       ① その大会を開いていること（公認番号を通っていること）
       ② 役員ページのパスワードをもう一度入力すること
       受付スタッフに端末を渡したまま誤って消される事故を防ぐため。 */
    uk_require_comp($id);
    $pw   = (string)($_POST['password'] ?? '');
    $auth = load_auth();
    $hash = $auth['admin'] ?? '';
    if ($pw === '' || $hash === '' || !password_verify($pw, $hash)) {
        json_out(['error' => '役員ページのパスワードが違います'], 401);
    }

    $dir = uk_dir($id);
    foreach (['events.json', 'roster.json', 'checkins.jsonl'] as $f) {
        if (is_file($dir . '/' . $f)) @unlink($dir . '/' . $f);
    }
    @rmdir($dir);
    $out = [];
    foreach ($list as $c) { if (($c['id'] ?? '') !== $id) $out[] = $c; }
    uk_save_list($out);
    json_out(['ok' => true]);
}

json_out(['error' => 'action が不正です'], 400);
