<?php
/* ログイン：ローカル data/auth.php のハッシュを password_verify で照合。
   成功でセッション確立＋CSRF発行。パスワードは返さない。簡易レート制限つき。 */
require __DIR__ . '/_auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Method Not Allowed'], 405);

$role = (($_POST['role'] ?? '') === 'build') ? 'build' : 'admin';
$pw   = (string)($_POST['password'] ?? '');

$af = auth_data_dir() . '/login_attempts.php';
$attempts = is_file($af) ? (include $af) : [];
if (!is_array($attempts)) $attempts = [];
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
$now = time();
$rec = $attempts[$ip] ?? ['n' => 0, 'until' => 0];
if (($rec['until'] ?? 0) > $now) json_out(['error' => 'locked', 'retry' => $rec['until'] - $now], 429);

$auth = load_auth();
$hash = $auth[$role] ?? '';
$ok   = ($hash !== '' && password_verify($pw, $hash));

/* 古い記録を掃除（24h以上前） */
foreach ($attempts as $k => $v) { if (($v['until'] ?? 0) < $now - 86400 && ($v['n'] ?? 0) === 0) unset($attempts[$k]); }

if (!$ok) {
    $rec['n'] = ($rec['n'] ?? 0) + 1;
    if ($rec['n'] >= 5) { $rec['until'] = $now + 300; $rec['n'] = 0; }  // 5回で5分ロック
    $attempts[$ip] = $rec;
    file_put_contents($af, "<?php\nreturn " . var_export($attempts, true) . ";\n", LOCK_EX);
    json_out(['error' => 'invalid'], 401);
}

unset($attempts[$ip]);
file_put_contents($af, "<?php\nreturn " . var_export($attempts, true) . ";\n", LOCK_EX);

session_regenerate_id(true);
if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) $_SESSION['auth'] = [];
$_SESSION['auth'][$role] = true;
json_out(['ok' => true, 'role' => $role, 'csrf' => issue_csrf()]);
