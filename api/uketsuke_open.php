<?php
/* 大会を開く（公認番号の照合）。
   合っていればセッションに記録し、以後その大会のデータを扱えるようにする。 */
require __DIR__ . '/_uketsuke.php';
require_auth_any(['admin', 'build', 'uketsuke']);

$id = $_POST['id'] ?? '';
if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);

$comp = null;
foreach (uk_load_list() as $c) {
    if (($c['id'] ?? '') === $id) { $comp = $c; break; }
}
if (!$comp) json_out(['error' => '大会が見つかりません'], 404);

/* 公認番号が設定されていない大会（以前に作ったもの）はそのまま開ける */
if (empty($comp['code_hash'])) {
    uk_unlock_comp($id);
    json_out(['ok' => true, 'id' => $id, 'need_code' => false]);
}

$raw  = (string)($_POST['code'] ?? '');          /* 役員パスワードの照合に使う（大小文字はそのまま） */
$code = uk_norm_code($raw);                      /* 合言葉の照合に使う（全角・空白・大小文字を吸収） */
if ($code === '') json_out(['error' => '合言葉を入力してください'], 400);

/* 総当たりを避けるため、ログインと同じ仕組みで失敗回数を制限する */
$af = auth_data_dir() . '/login_attempts.php';
$attempts = is_file($af) ? (include $af) : [];
if (!is_array($attempts)) $attempts = [];
$key = 'uk:' . ($_SERVER['REMOTE_ADDR'] ?? '0');
$now = time();
$rec = $attempts[$key] ?? ['n' => 0, 'until' => 0];
if (($rec['until'] ?? 0) > $now) {
    json_out(['error' => '試行回数が多すぎます。しばらく待ってから試してください', 'retry' => $rec['until'] - $now], 429);
}

/* 合言葉のほかに、役員ページのパスワードでも開ける。
   合言葉は大会ごとに決めるものなので、忘れると誰もその大会を開けなくなってしまう
   （編集も削除も「開いていること」が条件のため、戻す手段が無くなる）。
   役員パスワードを知っている人はもともと全大会を扱えるので、これで弱くはならない。
   合言葉は大文字小文字を区別しない（uk_norm_code）が、パスワードは区別するので、
   役員パスワードの照合には入力されたままの文字列を使う。 */
$auth  = load_auth();
$admin = (string)($auth['admin'] ?? '');
$ok = password_verify($code, $comp['code_hash'])
   || ($admin !== '' && password_verify($raw, $admin));

if (!$ok) {
    $rec['n'] = ($rec['n'] ?? 0) + 1;
    if ($rec['n'] >= 8) { $rec['until'] = $now + 300; $rec['n'] = 0; }
    $attempts[$key] = $rec;
    @file_put_contents($af, "<?php\nreturn " . var_export($attempts, true) . ";\n", LOCK_EX);
    json_out(['error' => '合言葉が違います'], 401);
}

unset($attempts[$key]);
@file_put_contents($af, "<?php\nreturn " . var_export($attempts, true) . ";\n", LOCK_EX);

uk_unlock_comp($id);
json_out(['ok' => true, 'id' => $id]);
