<?php
/* 大会の作成・名称変更・削除。
   action=create : name,date から新しい大会を作る（IDは自動採番）
   action=rename : id の名称・日付を変更
   action=delete : id の大会をデータごと削除 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

$action = $_POST['action'] ?? '';
$list   = uk_load_list();

if ($action === 'create') {
    $name = uk_str($_POST['name'] ?? '', 60);
    $date = uk_str($_POST['date'] ?? '', 20);
    if ($name === '') json_out(['error' => '大会名を入力してください'], 400);

    /* IDは日付＋連番（英数字のみ）。同名の大会が複数あっても衝突しない。 */
    $base = preg_replace('/[^0-9]/', '', $date);
    if ($base === '') $base = date('Ymd');
    $id = $base;
    $n = 1;
    $used = [];
    foreach ($list as $c) $used[$c['id'] ?? ''] = true;
    while (isset($used[$id])) { $n++; $id = $base . '-' . $n; }

    $list[] = ['id' => $id, 'name' => $name, 'date' => $date, 'created_at' => uk_now()];
    uk_save_list($list);
    /* 空のデータファイルを用意しておく */
    uk_write_json(uk_events_file($id), []);
    uk_write_json(uk_roster_file($id), []);
    json_out(['ok' => true, 'id' => $id]);
}

if ($action === 'rename') {
    $id   = $_POST['id'] ?? '';
    $name = uk_str($_POST['name'] ?? '', 60);
    $date = uk_str($_POST['date'] ?? '', 20);
    if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
    if ($name === '') json_out(['error' => '大会名を入力してください'], 400);
    $found = false;
    foreach ($list as &$c) {
        if (($c['id'] ?? '') === $id) { $c['name'] = $name; $c['date'] = $date; $found = true; break; }
    }
    unset($c);
    if (!$found) json_out(['error' => '大会が見つかりません'], 404);
    uk_save_list($list);
    json_out(['ok' => true]);
}

if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
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
