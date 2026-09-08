<?php
/* =====================================================================
   受付システム 共通処理
   - データは data/uketsuke/<大会ID>/ 配下に置く。
     選手名簿は個人情報（氏名・所属）を含むため、.htaccess で直接閲覧を禁止し、
     読み出しは必ずセッション認証済みの api/uketsuke_*.php 経由とする。
   - checkins.jsonl だけは追記専用（FILE_APPEND|LOCK_EX）。
     受付は複数端末から同時に書き込むため、JSONファイルの読み書き（後勝ち）では
     チェックインが消える。1行1件の追記なら同時書き込みでも取りこぼさない。
   ===================================================================== */

require_once __DIR__ . '/_auth.php';

function uk_root() { return dirname(__DIR__) . '/data/uketsuke'; }

/* 読み出しの入口：名簿は個人情報なので、役員ページにログイン済みでなければ返さない。
   （書き込みは既存の require_auth('admin') ＝ POST＋同一オリジン＋CSRF＋role を使う） */
function uk_require_read() {
    if (empty($_SESSION['auth']['admin']) && empty($_SESSION['auth']['build']) && empty($_SESSION['auth']['uketsuke'])) {
        json_out(['error' => 'Forbidden'], 403);
    }
    return true;
}

/* データ用ディレクトリを用意し、Web から直接読めないようにする */
function uk_ensure_root() {
    $root = uk_root();
    if (!is_dir($root)) @mkdir($root, 0755, true);
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# 個人情報を含むため直接アクセス禁止（読み出しは api/uketsuke_*.php 経由）\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return $root;
}

/* 大会IDは英数字・ハイフン・アンダースコアのみ（ディレクトリ横断を防ぐ） */
function uk_valid_id($id) {
    return is_string($id) && $id !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) === 1;
}

function uk_dir($id) {
    if (!uk_valid_id($id)) json_out(['error' => '大会IDが不正です'], 400);
    return uk_ensure_root() . '/' . $id;
}

function uk_read_json($file, $fallback) {
    if (!is_file($file)) return $fallback;
    $s = file_get_contents($file);
    if ($s === false || $s === '') return $fallback;
    $d = json_decode($s, true);
    return is_array($d) ? $d : $fallback;
}

function uk_write_json($file, $data) {
    /* 文字コードが壊れたデータが来ると json_encode が false を返す。
       そのまま書くと中身が空になり名簿が消えるので、書き込む前に必ず確かめる。 */
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        json_out(['error' => '文字コードが不正なデータが含まれているため保存を中止しました'], 400);
    }
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ok = file_put_contents($file, $json, LOCK_EX);
    if ($ok === false) json_out(['error' => 'ファイルへの書き込みに失敗しました'], 500);
    return true;
}

/* ---- 種目コードから種目名を推定する ----
   CSVには種目名が入っていないため、取込時の仮の名前がコードのままになってしまう。
   JDSFの種目コードは規則的なので、そこから読める名前を作る（違えば画面で直せる）。
     級別戦   : [J:一般 / M:シニアII / G:シニアIII / R:シニアIV] + [A-D級] + [S:スタンダード / L:ラテン]
     市民総体 : FK + [W/T/C/R] + [1:一般 / 2:シニア] */
function uk_guess_event_name($code) {
    $c = strtoupper(trim((string)$code));

    if (preg_match('/^([JMGR])([ABCDE])([SL])$/', $c, $m)) {
        $age   = ['J' => '', 'M' => 'シニアⅡ', 'G' => 'シニアⅢ', 'R' => 'シニアⅣ'];
        $dance = ['S' => 'スタンダード', 'L' => 'ラテン'];
        return $age[$m[1]] . $m[2] . '級' . $dance[$m[3]];
    }
    if (preg_match('/^FK([WTCRVFQJPS])([12])$/', $c, $m)) {
        return '市民総体' . ($m[2] === '2' ? 'シニア' : '') . $m[1];
    }
    return $c;   /* 規則に当てはまらないコードはそのまま */
}

/* ---- 前回使った種目コードの記憶 ----
   競技会ごとに種目構成はほぼ同じなので、前回の並びを覚えておいて次回の取込時に初期表示する。 */
function uk_config_file() { return uk_ensure_root() . '/config.json'; }
function uk_last_event_codes() {
    $c = uk_read_json(uk_config_file(), []);
    $v = isset($c['last_event_codes']) && is_array($c['last_event_codes']) ? $c['last_event_codes'] : [];
    return array_values($v);
}
function uk_save_last_event_codes($codes) {
    $c = uk_read_json(uk_config_file(), []);
    $c['last_event_codes'] = array_values($codes);
    $c['updated_at'] = uk_now();
    return uk_write_json(uk_config_file(), $c);
}

/* ---- 大会ごとの公認番号（パスコード） ----
   役員ページのログインは全府県で共通のため、大会のデータを開くときに
   その大会の公認番号を入力させ、担当外の大会を誤って触らないようにする。
   照合はサーバー側で行い、通った大会だけをセッションに記録する。 */
function uk_norm_code($s) {
    $s = mb_convert_kana((string)$s, 'as');          /* 全角英数・全角空白を半角に */
    $s = preg_replace('/\s+/u', '', $s);             /* 空白は無視する */
    return strtoupper(trim($s));
}
function uk_comp_unlocked($id) {
    return !empty($_SESSION['uk_unlocked'][$id]);
}
function uk_unlock_comp($id) {
    if (!isset($_SESSION['uk_unlocked']) || !is_array($_SESSION['uk_unlocked'])) $_SESSION['uk_unlocked'] = [];
    $_SESSION['uk_unlocked'][$id] = true;
}
/* 公認番号が設定されている大会は、開いていなければ拒否する */
function uk_require_comp($id) {
    foreach (uk_load_list() as $c) {
        if (($c['id'] ?? '') !== $id) continue;
        if (empty($c['code_hash'])) return true;      /* 未設定の大会は誰でも開ける */
        if (uk_comp_unlocked($id)) return true;
        json_out(['error' => 'locked', 'message' => 'この大会の公認番号を入力してください'], 403);
    }
    json_out(['error' => '大会が見つかりません'], 404);
}

/* ---- 大会一覧 ---- */
function uk_list_file() { return uk_ensure_root() . '/index.json'; }
function uk_load_list() { return uk_read_json(uk_list_file(), []); }
function uk_save_list($list) { return uk_write_json(uk_list_file(), array_values($list)); }

/* ---- 大会ごとのデータ ---- */
function uk_events_file($id)   { return uk_dir($id) . '/events.json'; }
function uk_roster_file($id)   { return uk_dir($id) . '/roster.json'; }
function uk_checkins_file($id) { return uk_dir($id) . '/checkins.jsonl'; }

function uk_load_events($id) { return uk_read_json(uk_events_file($id), []); }
function uk_load_roster($id) { return uk_read_json(uk_roster_file($id), []); }

/* チェックイン記録（追記式）を読み、背番号ごとに最後の行を採用する。
   action=clear  … そこまでの記録を破棄する（全員リセット用）
   action=remove … その背番号だけ取り消す（打ち間違いの訂正用） */
function uk_load_checkins($id) {
    $file = uk_checkins_file($id);
    if (!is_file($file)) return [];
    $fh = @fopen($file, 'r');
    if (!$fh) return [];
    $map = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $rec = json_decode($line, true);
        if (!is_array($rec)) continue;
        $action = (string)($rec['action'] ?? '');
        if ($action === 'clear') { $map = []; continue; }
        if ($action === 'remove') {                     /* 1件だけ取り消し（打ち間違いの訂正） */
            unset($map[(int)($rec['bib'] ?? 0)]);
            continue;
        }
        if ($action !== '' && $action !== 'checkin') continue;   /* 在庫チェック等、他の記録は無視する */
        $bib = isset($rec['bib']) ? (int)$rec['bib'] : 0;
        if ($bib <= 0) continue;
        $map[$bib] = ['bib' => $bib, 'at' => (string)($rec['at'] ?? ''), 'by' => (string)($rec['by'] ?? '')];
    }
    fclose($fh);
    return $map;
}

/* 欠場者の背番号が受付に残っているかの確認記録。
   チェックインと同じ追記ファイルに action=stock として書く（全員解除で一緒に消えるように）。 */
function uk_load_stock($id) {
    $file = uk_checkins_file($id);
    if (!is_file($file)) return [];
    $fh = @fopen($file, 'r');
    if (!$fh) return [];
    $map = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $rec = json_decode($line, true);
        if (!is_array($rec)) continue;
        $action = (string)($rec['action'] ?? '');
        if ($action === 'clear') { $map = []; continue; }
        if ($action !== 'stock') continue;
        $bib = isset($rec['bib']) ? (int)$rec['bib'] : 0;
        if ($bib <= 0) continue;
        if (empty($rec['on'])) { unset($map[$bib]); continue; }   /* チェックを外した */
        $map[$bib] = ['bib' => $bib, 'at' => (string)($rec['at'] ?? ''), 'by' => (string)($rec['by'] ?? '')];
    }
    fclose($fh);
    return $map;
}

/* 1行追記（同時書き込みでも取りこぼさない） */
function uk_append_checkin($id, $rec) {
    $file = uk_checkins_file($id);
    $dir  = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ok = file_put_contents($file, json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    if ($ok === false) json_out(['error' => 'チェックインの記録に失敗しました'], 500);
    return true;
}

/* ---- 入力の正規化 ---- */
/* 壊れた文字コードが混ざっていても保存できるよう、不正なバイト列は落としてから切り詰める */
function uk_str($v, $max = 100) {
    $s = (string)$v;
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    return mb_substr(trim($s), 0, $max);
}
function uk_now() { return date('c'); }
