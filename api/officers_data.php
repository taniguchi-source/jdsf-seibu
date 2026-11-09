<?php
/* =====================================================================
   役員ページ専用データの読み出し口。

   data/officers_docs.json（委員会スプレッドシートのURL）や
   data/officers_notices.json（役員向けお知らせ）は、ページ自体は
   ログインで守られていたのに、生のJSONが
   https://jdsf-seibu.com/data/officers_docs.json で誰でも読めていた。

   data/.htaccess で生ファイルへの直接アクセスを禁止し（news_archive.json と
   同じ方式）、読み出しはここに集約する。ログイン(admin)＋同一オリジン＋CSRF が必要。
   ===================================================================== */
require __DIR__ . '/_auth.php';
require_auth('admin');

/* 読み出してよいファイルは固定。外から任意のパスを渡せないようにする。 */
$ALLOW = ['officers_docs', 'officers_notices'];

$name = (string)($_POST['name'] ?? '');
if (!in_array($name, $ALLOW, true)) json_out(['error' => 'Bad Request'], 400);

$file = dirname(__DIR__) . '/data/' . $name . '.json';
if (!is_file($file)) json_out([]);          // 未作成なら空で返す（呼び出し側は || [] で受ける）

$raw = @file_get_contents($file);
$d   = json_decode((string)$raw, true);
json_out(is_array($d) ? $d : []);
