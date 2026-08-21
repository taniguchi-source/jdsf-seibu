<?php
/**
 * 競技予定表を保存 → data/schedule.json
 *
 * 認証: _auth.php の require_schedule_auth()（戻り値 'admin' | 'staff'）
 *   - admin / build（役員）: 全編集（年・タイトル・行の追加削除・月日・府県/大会名/会場）
 *   - schedule（担当者）    : 既存行の 府県／大会名／会場（E/F/G）のみ。
 *                             月日・行構成はサーバーの現状を維持（ロックはサーバー側でも強制）。
 *
 * POST: rows（JSON配列）／admin時は year, title も
 */
require __DIR__ . '/_auth.php';
$role = require_schedule_auth();   // 'admin' or 'staff'

function sch_str($v, $max) { return mb_substr(trim((string)$v), 0, $max); }
function sch_int($v, $min, $max) { $n = (int)$v; if ($n < $min) $n = $min; if ($n > $max) $n = $max; return $n; }

$file = dirname(__DIR__) . '/data/schedule.json';
$raw  = @file_get_contents($file);
$cur  = $raw ? @json_decode($raw, true) : [];
if (!is_array($cur)) $cur = [];
$cur_rows = (isset($cur['rows']) && is_array($cur['rows'])) ? $cur['rows'] : [];

$payload = @json_decode($_POST['rows'] ?? '[]', true);
if (!is_array($payload)) $payload = [];

$now = date('Y-m-d H:i');

if ($role === 'admin') {
    /* 役員：年・タイトル・行を作り直す */
    $year  = sch_int($_POST['year'] ?? ($cur['year'] ?? 2027), 2000, 2100);
    $title = sch_str($_POST['title'] ?? ($cur['title'] ?? ''), 60);

    $prev = [];
    foreach ($cur_rows as $r) { if (isset($r['id'])) $prev[(string)$r['id']] = $r; }

    $rows = [];
    foreach ($payload as $r) {
        if (!is_array($r)) continue;
        if (count($rows) >= 300) break;
        $id = preg_replace('/[^a-z0-9_]/i', '', (string)($r['id'] ?? ''));
        if ($id === '') $id = 'r' . substr(md5(uniqid('', true)), 0, 10);
        $month = sch_int($r['month'] ?? 0, 0, 12);
        $day   = sch_int($r['day']   ?? 0, 0, 31);
        $pref  = sch_str($r['pref']  ?? '', 20);
        $event = sch_str($r['event'] ?? '', 120);
        $venue = sch_str($r['venue'] ?? '', 120);

        $p = $prev[$id] ?? null;
        $changed = !$p
            || ($p['pref'] ?? '')  !== $pref
            || ($p['event'] ?? '') !== $event
            || ($p['venue'] ?? '') !== $venue;
        $updated_at = $changed ? $now : (string)($p['updated_at'] ?? '');

        $rows[] = [
            'id' => $id, 'month' => $month, 'day' => $day,
            'pref' => $pref, 'event' => $event, 'venue' => $venue,
            'updated_at' => $updated_at,
        ];
    }
    $out = ['year' => $year, 'title' => $title, 'rows' => $rows, 'updated' => date('c')];
} else {
    /* 担当者：既存行の E/F/G のみ反映。月日・行構成は現状維持 */
    $edits = [];
    foreach ($payload as $r) {
        if (!is_array($r)) continue;
        $id = (string)($r['id'] ?? '');
        if ($id !== '') $edits[$id] = $r;
    }
    $rows = [];
    foreach ($cur_rows as $r) {
        $id = (string)($r['id'] ?? '');
        if (isset($edits[$id])) {
            $e = $edits[$id];
            $pref  = sch_str($e['pref']  ?? ($r['pref']  ?? ''), 20);
            $event = sch_str($e['event'] ?? ($r['event'] ?? ''), 120);
            $venue = sch_str($e['venue'] ?? ($r['venue'] ?? ''), 120);
            $changed = ($r['pref'] ?? '') !== $pref
                    || ($r['event'] ?? '') !== $event
                    || ($r['venue'] ?? '') !== $venue;
            $r['pref'] = $pref; $r['event'] = $event; $r['venue'] = $venue;
            if ($changed) $r['updated_at'] = $now;
        }
        $rows[] = $r;
    }
    $out = $cur;
    $out['rows']    = $rows;
    $out['updated'] = date('c');
}

if (file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    json_out(['error' => 'ファイルへの書き込みに失敗しました'], 500);
}
json_out(['ok' => true, 'role' => $role, 'count' => count($out['rows'])]);
