<?php
/**
 * 競技予定表の担当者共通パスワードを設定／変更する（役員のみ）。
 * data/schedule_auth.php に bcrypt で保存。ブラウザからは中身を読めない。
 *
 * POST: password（8〜200文字）
 * 認証: admin / build（POST・同一オリジン・CSRF）
 */
require __DIR__ . '/_auth.php';
require_auth_any(['admin', 'build']);

$pw = (string)($_POST['password'] ?? '');
if (mb_strlen($pw) < 8)   json_out(['error' => 'パスワードは8文字以上にしてください'], 400);
if (mb_strlen($pw) > 200) json_out(['error' => 'パスワードが長すぎます'], 400);

$hash = password_hash($pw, PASSWORD_DEFAULT);
if (!save_schedule_auth($hash)) json_out(['error' => '保存に失敗しました'], 500);

json_out(['ok' => true]);
