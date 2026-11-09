<?php
/* =====================================================================
   お問い合わせ先設定（スプレッドシート「HP用資料」Q〜X列）の読込・保存を、
   サーバー側から GAS へ中継する。

   【なぜ中継するのか】
   以前は site-config.html がブラウザから GAS を直接呼び、合言葉
   'seibu-contact2026' をクエリに付けていた。この値は公開ページの
   JavaScript に平文で入っており（SECURITY-NOTES.md が公開されていたため
   漏洩扱い）、知っていれば誰でも GAS の saveContact を叩いて府県の
   問い合わせ送信先メールアドレスを書き換えられる状態だった。

   そこで照合値を、既にこの仕組みで使っているマスター秘密に寄せる。
     - サイト側 : data/auth_secret.php（デプロイ時配布・Gitにもブラウザにも出ない）
     - GAS側    : スクリプトプロパティ AUTH_SECRET（同値）
   秘密はサーバー間でしか流れない。ブラウザからの入口はサイト構築の
   ログイン(build)＋同一オリジン＋CSRF。

   対象サイトのURLはクライアントに決めさせず HTTP_HOST から作る
   （他府県の行を書き換えられないようにするため）。
   ===================================================================== */
require __DIR__ . '/_auth.php';
require_auth('build');

$GAS = 'https://script.google.com/macros/s/AKfycbxS07Mxs6TZHdq0aGTay547EfIrN5igaJ527EaWl-O-RgHv7VHMllszJyMkI30qRU3A/exec';

$secret = master_secret();
if ($secret === null) {
    json_out(['error' => '共通設定が未配置です（data/auth_secret.php）。デプロイを実行してください。'], 500);
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/:\d+$/', '', $host);
if ($host === '') json_out(['error' => 'ホスト名を判定できません'], 400);
$site_url = 'https://' . $host . '/';

$mode = ((string)($_POST['mode'] ?? 'load') === 'save') ? 'save' : 'load';

if ($mode === 'load') {
    $payload = ['action' => 'getContactConfig', 'secret' => $secret, 'url' => $site_url];
} else {
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => '送信先メールの形式が正しくありません。'], 400);
    }
    foreach (['cc1', 'cc2'] as $k) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            json_out(['error' => 'CCメールの形式が正しくありません。'], 400);
        }
    }
    $payload = [
        'action' => 'saveContact',
        'secret' => $secret,
        'url'    => $site_url,
        'email'  => $email,
        'person' => mb_substr(trim((string)($_POST['person'] ?? '')), 0, 100),
        'cc1'    => trim((string)($_POST['cc1'] ?? '')),
        'cc2'    => trim((string)($_POST['cc2'] ?? '')),
        'org'    => mb_substr(trim((string)($_POST['org'] ?? '')), 0, 100),
        'reply'  => mb_substr((string)($_POST['reply'] ?? ''), 0, 2000),
    ];
}

/* GAS へは必ず POST（GET だと秘密がURLに残るため）。
   GAS の doPost は JSON を優先するが、失敗時は e.parameter を読むので
   application/x-www-form-urlencoded でよい（change_password.php と同じ）。 */
$body = http_build_query($payload);
$resp = false;
if (function_exists('curl_init')) {
    $ch = curl_init($GAS);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
}
if (($resp === false || $resp === '') && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                   'content' => $body, 'timeout' => 15, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $resp = @file_get_contents($GAS, false, $ctx);
}
if ($resp === false || $resp === '') json_out(['error' => 'GASへの接続に失敗しました。'], 502);

$j = json_decode($resp, true);
if (!is_array($j)) json_out(['error' => 'GASの応答を解析できませんでした。'], 502);

/* GAS の応答をそのまま返す（秘密は含まれない）。 */
json_out($j);
