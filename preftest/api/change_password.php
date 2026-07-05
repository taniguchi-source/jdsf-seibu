<?php
/* セルフサービスのパスワード変更（ログイン中の府県・そのロールのみ）。
   現PW確認 → 新PWをハッシュ化して自サイトの data/auth.php を更新。 */
require __DIR__ . '/_auth.php';
$role = (($_POST['role'] ?? '') === 'build') ? 'build' : 'admin';
require_auth($role);   // POST + 同一オリジン + CSRF + 該当ロールのセッション

$cur = (string)($_POST['current'] ?? '');
$new = (string)($_POST['new'] ?? '');
if (mb_strlen($new) < 8)      json_out(['error' => '新しいパスワードは8文字以上にしてください'], 400);
if ($new === $cur)            json_out(['error' => '現在のパスワードと異なるものにしてください'], 400);

$auth = load_auth();
if (empty($auth[$role]) || !password_verify($cur, $auth[$role])) {
    json_out(['error' => '現在のパスワードが正しくありません'], 403);
}
$auth[$role] = password_hash($new, PASSWORD_DEFAULT);
if (!save_auth($auth)) json_out(['error' => '保存に失敗しました'], 500);
json_out(['ok' => true]);
