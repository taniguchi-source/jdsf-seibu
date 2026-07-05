<?php
/* マスター設定/リセット（ブロック事務局専用）。
   マスター秘密（data/auth_secret.php）を知る者のみが、そのサイトのPWを設定/リセットできる。
   初期投入・パスワード忘れ時のリセットに使用。現PWの確認は不要（＝リセット）。 */
require __DIR__ . '/_auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);

$ms    = master_secret();
$given = (string)($_POST['master'] ?? '');
if ($ms === null || $given === '' || !hash_equals($ms, $given)) json_out(['error' => 'Forbidden'], 403);

$role = (($_POST['role'] ?? '') === 'build') ? 'build' : 'admin';
$new  = (string)($_POST['new'] ?? '');
if (mb_strlen($new) < 8) json_out(['error' => 'パスワードは8文字以上にしてください'], 400);

$auth = load_auth();
$auth[$role] = password_hash($new, PASSWORD_DEFAULT);
if (!save_auth($auth)) json_out(['error' => '保存に失敗しました'], 500);
json_out(['ok' => true, 'role' => $role]);
