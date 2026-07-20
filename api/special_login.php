<?php
/**
 * 特設サイトの担当者ログイン。
 * data/special_auth.php のハッシュを password_verify で照合する。
 * 成功すると $_SESSION['auth']['special'][<id>] = true になり、
 * そのサイトの内容だけを保存できるようになる（他のサイトは触れない）。
 *
 * POST: site_id（1〜5）, password
 * 総当たり対策：同じIP＋サイトで5回失敗すると5分ロック（役員ログインと同じ方式）。
 */
require __DIR__ . '/_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);
if (!same_origin_ok())                             json_out(['error' => 'Bad Origin'], 403);

$id = special_site_id($_POST['site_id'] ?? '');
if ($id === null) json_out(['error' => 'site_id は 1〜5 で指定してください'], 400);

$ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
$key = 'sp' . $id . '|' . $ip;

$wait = throttle_locked_for($key);
if ($wait > 0) json_out(['error' => 'locked', 'retry' => $wait], 429);

$auth = load_special_auth();
$hash = $auth[$id] ?? '';
$pw   = (string)($_POST['password'] ?? '');

if ($hash === '' || !password_verify($pw, $hash)) {
    throttle_fail($key);
    json_out(['error' => 'invalid'], 401);
}

throttle_reset($key);

session_regenerate_id(true);
if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth']))           $_SESSION['auth'] = [];
if (!isset($_SESSION['auth']['special']) || !is_array($_SESSION['auth']['special'])) $_SESSION['auth']['special'] = [];
$_SESSION['auth']['special'][$id] = true;

json_out(['ok' => true, 'site_id' => $id, 'csrf' => issue_csrf()]);
