<?php
/**
 * 競技予定表 担当者ログイン（府県連盟の担当者共通パスワード・1つ）。
 * data/schedule_auth.php のハッシュを password_verify で照合する。
 * 成功すると $_SESSION['auth']['schedule'] = true になり、
 * 既存行の 府県／大会名／会場（E/F/G）だけを保存できるようになる。
 *
 * POST: password
 * 総当たり対策：同一IPで5回失敗すると5分ロック（役員・特設ログインと同じ方式）。
 */
require __DIR__ . '/_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);
if (!same_origin_ok())                             json_out(['error' => 'Bad Origin'], 403);

$ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
$key = 'sched|' . $ip;

$wait = throttle_locked_for($key);
if ($wait > 0) json_out(['error' => 'locked', 'retry' => $wait], 429);

$hash = load_schedule_auth();
$pw   = (string)($_POST['password'] ?? '');

if ($hash === '' || !password_verify($pw, $hash)) {
    throttle_fail($key);
    json_out(['error' => 'invalid'], 401);
}

throttle_reset($key);

session_regenerate_id(true);
if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) $_SESSION['auth'] = [];
$_SESSION['auth']['schedule'] = true;

json_out(['ok' => true, 'csrf' => issue_csrf()]);
