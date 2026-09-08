<?php
/**
 * 受付システム／競技用ページ ログイン。
 * 入力パスワードを次の順で照合し、一致したロールでセッションを確立：
 *   1) 受付共通PW（data/uketsuke_auth.php） → role=uketsuke
 *   2) 役員用PW（data/auth.php の admin）    → role=admin
 *   3) サイト構築PW（data/auth.php の build） → role=build
 * これにより「受付用または役員用のパスワード」で入れる。
 *
 * POST: password
 * 総当たり対策：同一IPで5回失敗すると5分ロック。
 */
require __DIR__ . '/_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);
if (!same_origin_ok())                             json_out(['error' => 'Bad Origin'], 403);

$ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
$key = 'uke|' . $ip;

$wait = throttle_locked_for($key);
if ($wait > 0) json_out(['error' => 'locked', 'retry' => $wait], 429);

$pw   = (string)($_POST['password'] ?? '');
$uke  = load_uketsuke_auth();
$auth = load_auth();

$role = null;
if ($uke !== '' && password_verify($pw, $uke))                          $role = 'uketsuke';
elseif (!empty($auth['admin']) && password_verify($pw, $auth['admin']))  $role = 'admin';
elseif (!empty($auth['build']) && password_verify($pw, $auth['build']))  $role = 'build';

if ($role === null) {
    throttle_fail($key);
    json_out(['error' => 'invalid'], 401);
}

throttle_reset($key);

session_regenerate_id(true);
if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) $_SESSION['auth'] = [];
$_SESSION['auth'][$role] = true;

json_out(['ok' => true, 'role' => $role, 'csrf' => issue_csrf()]);
