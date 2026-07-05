<?php
/* ログアウト：role 指定なら該当ロールのみ、なければ全解除 */
require __DIR__ . '/_auth.php';
$role = $_POST['role'] ?? '';
if ($role === 'admin' || $role === 'build') {
    unset($_SESSION['auth'][$role]);
} else {
    $_SESSION['auth'] = [];
}
json_out(['ok' => true]);
