<?php
/* ログイン状態の確認（画面ロード時に使用）。CSRFトークンも返す。 */
require __DIR__ . '/_auth.php';
$auth = (isset($_SESSION['auth']) && is_array($_SESSION['auth'])) ? $_SESSION['auth'] : [];
$has_pw = (load_auth() !== []);   // 初期PW未設定の判定用
/* ログイン中の特設サイトのID一覧（例 ["1","3"]） */
$special = [];
if (isset($auth['special']) && is_array($auth['special'])) {
    foreach ($auth['special'] as $k => $v) { if (!empty($v)) $special[] = (string)$k; }
}

json_out([
    'admin'   => !empty($auth['admin']),
    'build'   => !empty($auth['build']),
    'special' => $special,
    'csrf'    => issue_csrf(),
    'has_pw'  => $has_pw,
]);
