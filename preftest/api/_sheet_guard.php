<?php
/* =====================================================================
   スプレッドシート読み取りproxy（fetch_sheet.php / fetch_sheet_styled.php）の
   取得先を、このサイト自身が掲載しているシートだけに限定する。

   【なぜトークンではないのか】
   以前は固定トークン 'preftest2026' 1個で通していたが、この値は公開ページの
   JavaScript に平文で入っていた。公開ページが表を描くために同じAPIを呼ぶので、
   ログイン必須にすることも、値を隠すこともできない＝秘密として機能しない。
   （SECURITY-NOTES.md が公開されていたため、値そのものは漏洩扱い。）

   実際に防ぎたいのは「第三者が当サイトのサーバーを踏み台にして、任意の
   Googleスプレッドシートを取得すること」。なので照合する相手をトークンから
   “取得先”に変える。

   - 取得先URLは docs.google.com に固定（呼び出し元が与えるのはシートIDのみ）。
   - そのシートIDが data/*.json（このサイトの掲載内容）に出てくる場合のみ許可。
     公開ページが描くのは保存済みの内容なので、これで通常の閲覧は通る。
   - 管理画面で未保存のURLをプレビューする場合は allowlist に無いので、
     ログイン(admin)＋CSRF を要求する。_auth.php を読むのはこの分岐だけ＝
     公開閲覧でセッションを作らない（共有ホスティングのセッション増を避ける）。
   ===================================================================== */

/* このサイトの掲載内容に出てくるスプレッドシートIDの集合。
   data/*.json は json_encode(JSON_PRETTY_PRINT) で書かれており「\/」と
   エスケープされるため、照合前にスラッシュを戻す。 */
function sheet_guard_allowed_ids() {
    static $ids = null;
    if ($ids !== null) return $ids;
    $ids = [];
    $files = glob(dirname(__DIR__) . '/data/*.json');
    if (!is_array($files)) return $ids;
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if (!is_string($raw) || $raw === '') continue;
        $raw = str_replace('\/', '/', $raw);
        if (preg_match_all('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $raw, $mm)) {
            foreach ($mm[1] as $id) $ids[$id] = true;
        }
    }
    return $ids;
}

/* 同一オリジン確認（Origin/Referer があれば自ホストと一致必須。無い場合は許容）。
   _auth.php の same_origin_ok() と同じ判定だが、セッションを開始しないために
   ここに独立して持つ。 */
function sheet_guard_same_origin_ok() {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $k) {
        if (!empty($_SERVER[$k])) {
            $h = parse_url($_SERVER[$k], PHP_URL_HOST);
            if ($h !== null && $h !== '') return (strtolower($h) === $host);
        }
    }
    return true;
}

/* $sheet_id の取得を許可してよいか判定する。だめならこの中で 403 を返して終了。 */
function sheet_guard_require($sheet_id) {
    if (!sheet_guard_same_origin_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Bad Origin'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sheet_id = (string)$sheet_id;
    $allowed  = sheet_guard_allowed_ids();
    if ($sheet_id !== '' && isset($allowed[$sheet_id])) return;

    /* 掲載内容に無いシート＝管理画面でのプレビュー等。ログイン必須にする。 */
    require __DIR__ . '/_auth.php';
    require_auth('admin');   // POST + 同一オリジン + CSRF + ログイン（失敗時はここで終了）
}
