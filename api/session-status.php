<?php
/* ログイン状態の確認（画面ロード時に使用）。CSRFトークンも返す。 */
require __DIR__ . '/_auth.php';
$auth = (isset($_SESSION['auth']) && is_array($_SESSION['auth'])) ? $_SESSION['auth'] : [];
$has_pw = (load_auth() !== []);   // 初期PW未設定の判定用
json_out([
    'admin'   => !empty($auth['admin']),
    'build'   => !empty($auth['build']),
    'csrf'    => issue_csrf(),
    'has_pw'  => $has_pw,
]);
