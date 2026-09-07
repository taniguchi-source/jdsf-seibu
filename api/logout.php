<?php
/* ログアウト：role 指定なら該当ロールのみ、なければ全解除 */
require __DIR__ . '/_auth.php';
$role = $_POST['role'] ?? '';
if ($role === 'admin' || $role === 'build') {
    unset($_SESSION['auth'][$role]);
} elseif ($role === 'special') {
    /* 特設サイト：site_id 指定ならそのサイトだけ、無ければ特設セッションを全解除 */
    $id = special_site_id($_POST['site_id'] ?? '');
    if ($id !== null) unset($_SESSION['auth']['special'][$id]);
    else              unset($_SESSION['auth']['special']);
} else {
    $_SESSION['auth'] = [];
}
/* 受付システムで開いた大会のロック解除状態も破棄する
   （同じ端末で別の担当者がログインしたときに、他府県の大会が開けたままにならないように） */
unset($_SESSION['uk_unlocked']);

json_out(['ok' => true]);
