<?php
/**
 * 競技予定表を保存 → data/schedule.json
 *
 * 認証: _auth.php の require_schedule_auth()（戻り値 'admin' | 'staff'）
 *   - admin / build（役員）: 全編集（年・タイトル・行の追加削除・月日・府県/大会名/会場/備考）
 *   - schedule（担当者）    : 既存行の 府県／大会名／会場／備考（E/F/G/H）のみ。
 *                             ・日付（月日）は変更不可（サーバー側で現状を維持）
 *                             ・既存の開催日（行）は削除不可
 *                             ・「同じ開催日の追加行」は、既存の日付に一致する場合のみ許可
 *   いずれも保存時に日付順へ整列（同一日は入力順を維持）。
 *
 * POST: rows（JSON配列）／admin時は year, title も
 */
require __DIR__ . '/_auth.php';
$role = require_schedule_auth();   // 'admin' or 'staff'

function sch_str($v, $max) { return mb_substr(trim((string)$v), 0, $max); }
function sch_int($v, $min, $max) { $n = (int)$v; if ($n < $min) $n = $min; if ($n > $max) $n = $max; return $n; }

/* 日付順に安定ソート（同一日は元の並び順を維持） */
function sch_sort(&$rows) {
    $i = 0;
    foreach ($rows as &$r) { $r['__i'] = $i++; }
    unset($r);
    usort($rows, function ($a, $b) {
        $am = (int)$a['month'] ?: 99; $bm = (int)$b['month'] ?: 99;
        if ($am !== $bm) return $am - $bm;
        $ad = (int)$a['day'] ?: 99; $bd = (int)$b['day'] ?: 99;
        if ($ad !== $bd) return $ad - $bd;
        return $a['__i'] - $b['__i'];
    });
    foreach ($rows as &$r) { unset($r['__i']); }
    unset($r);
}

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
        if (count($rows) >= 400) break;
        $id = preg_replace('/[^a-z0-9_]/i', '', (string)($r['id'] ?? ''));
        if ($id === '') $id = 'r' . substr(md5(uniqid('', true)), 0, 10);
        $month  = sch_int($r['month']  ?? 0, 0, 12);
        $day    = sch_int($r['day']    ?? 0, 0, 31);
        $pref   = sch_str($r['pref']   ?? '', 20);
        $event  = sch_str($r['event']  ?? '', 120);
        $venue  = sch_str($r['venue']  ?? '', 120);
        $remark = sch_str($r['remark'] ?? '', 200);
        $ranking = !empty($r['ranking']);

        $p = $prev[$id] ?? null;
        $changed = !$p
            || ($p['pref'] ?? '')   !== $pref
            || ($p['event'] ?? '')  !== $event
            || ($p['venue'] ?? '')  !== $venue
            || ($p['remark'] ?? '') !== $remark
            || (!empty($p['ranking'])) !== $ranking;
        $updated_at = $changed ? $now : (string)($p['updated_at'] ?? '');

        $rows[] = compact('id', 'month', 'day', 'pref', 'event', 'venue', 'remark', 'ranking') + ['updated_at' => $updated_at];
    }
    sch_sort($rows);
    $out = ['year' => $year, 'title' => $title, 'rows' => $rows, 'updated' => date('c')];

} else {
    /* 担当者：E/F/G/H のみ更新。既存行の削除は不可（温存）。
       行の追加は「既存の開催日」または「既存の日曜の前日（土曜）」のみ許可（＋/土ボタン用）。 */
    $yr = (int)($cur['year'] ?? 2027);
    $allowed = [];
    foreach ($cur_rows as $r) {
        $mm = (int)($r['month'] ?? 0); $dd = (int)($r['day'] ?? 0);
        if ($mm < 1 || $dd < 1) continue;
        $allowed[$mm . '-' . $dd] = true;
        $ts = mktime(0, 0, 0, $mm, $dd, $yr);
        if ((int)date('w', $ts) === 0) { $pv = $ts - 86400; $allowed[((int)date('n', $pv)) . '-' . ((int)date('j', $pv))] = true; }
    }
    $existing = [];
    foreach ($cur_rows as $r) { if (isset($r['id'])) $existing[(string)$r['id']] = $r; }

    $rows = [];
    $placed = [];
    foreach ($payload as $r) {
        if (!is_array($r)) continue;
        if (count($rows) >= 400) break;
        $id = (string)($r['id'] ?? '');
        $pref   = sch_str($r['pref']   ?? '', 20);
        $event  = sch_str($r['event']  ?? '', 120);
        $venue  = sch_str($r['venue']  ?? '', 120);
        $remark = sch_str($r['remark'] ?? '', 200);
        $ranking = !empty($r['ranking']);

        if ($id !== '' && isset($existing[$id])) {
            $base = $existing[$id];
            if (!array_key_exists('remark', $base)) $base['remark'] = '';
            $changed = ($base['pref'] ?? '') !== $pref || ($base['event'] ?? '') !== $event
                    || ($base['venue'] ?? '') !== $venue || ($base['remark'] ?? '') !== $remark
                    || (!empty($base['ranking'])) !== $ranking;
            $base['pref'] = $pref; $base['event'] = $event; $base['venue'] = $venue; $base['remark'] = $remark; $base['ranking'] = $ranking;
            if ($changed) $base['updated_at'] = $now;
            $rows[] = $base;
            $placed[$id] = true;
        } else {
            $month = (int)($r['month'] ?? 0); $day = (int)($r['day'] ?? 0);
            if (empty($allowed[$month . '-' . $day])) continue;   // 任意日付の新規作成は不可
            $nid = 'd' . sprintf('%02d%02d', $month, $day) . '_' . substr(md5(uniqid('', true)), 0, 6);
            $has = ($pref !== '' || $event !== '' || $venue !== '' || $remark !== '' || $ranking);
            $rows[] = ['id' => $nid, 'month' => $month, 'day' => $day,
                       'pref' => $pref, 'event' => $event, 'venue' => $venue, 'remark' => $remark, 'ranking' => $ranking,
                       'updated_at' => $has ? $now : ''];
        }
    }
    /* 送信に含まれなかった既存行は削除させない（温存） */
    foreach ($cur_rows as $r) {
        $id = (string)($r['id'] ?? '');
        if ($id !== '' && empty($placed[$id])) { if (!array_key_exists('remark', $r)) $r['remark'] = ''; $rows[] = $r; }
    }
    sch_sort($rows);
    $out = $cur;
    $out['rows']    = $rows;
    $out['updated'] = date('c');
}

if (file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    json_out(['error' => 'ファイルへの書き込みに失敗しました'], 500);
}
json_out(['ok' => true, 'role' => $role, 'count' => count($out['rows'])]);
