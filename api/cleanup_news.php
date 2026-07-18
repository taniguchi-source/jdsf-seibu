<?php
/**
 * 公開お知らせ 自動削除（CLI専用）
 *   - always_show === true（常に表示）は対象外
 *   - 実施日（event_date）あり → その日から7日を過ぎたら削除
 *   - 実施日なし（空欄）     → 公開日（date）から90日を過ぎたら削除
 *   - どちらの日付も不正なものは対象外（日齢を計算できないため残す）
 *   - 削除時は添付ファイル（uploads/ 配下）も物理削除
 *
 * 実行: GitHub Actions の cron から SSH 経由で
 *   php /home/jdsfseibu/jdsf-seibu.com/public_html/api/cleanup_news.php
 * Web からは実行できない（403）。
 */

// Web アクセスを封じる（CLIのみ）
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: CLI only\n";
    exit;
}

date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/_news_util.php';

$GRACE_DAYS    = 7;    // 実施日ありのお知らせ：実施日から何日後に消すか
$GRACE_NO_EVENT = 90;  // 実施日なしのお知らせ：公開日から何日後に消すか
$data_file  = dirname(__DIR__) . '/data/news.json';

/** "YYYY.MM.DD" を DateTime(00:00) に。失敗時 null */
function parse_dot_date($s) {
    if (!preg_match('/^(\d{4})\.(\d{1,2})\.(\d{1,2})$/', trim((string)$s), $m)) return null;
    $d = DateTime::createFromFormat('Y-n-j', "{$m[1]}-{$m[2]}-{$m[3]}");
    if ($d === false) return null;
    $d->setTime(0, 0, 0);
    return $d;
}

$json = is_file($data_file) ? file_get_contents($data_file) : false;
$data = $json ? json_decode($json, true) : null;
if (!is_array($data) || !isset($data['news']) || !is_array($data['news'])) {
    fwrite(STDOUT, "news.json が読めない/空のため何もしません\n");
    exit(0);
}

$today = new DateTime('today');   // Asia/Tokyo の今日 00:00
$kept = [];
$removed = [];

foreach ($data['news'] as $n) {
    if (!is_array($n)) { continue; }
    // 常に表示はピン留め＝消さない
    if (!empty($n['always_show'])) { $kept[] = $n; continue; }

    $eventDate = parse_dot_date($n['event_date'] ?? '');
    if ($eventDate !== null) {
        // 実施日あり → 実施日 + GRACE_DAYS
        $deadline = (clone $eventDate)->modify("+{$GRACE_DAYS} days");
    } else {
        // 実施日なし → 公開日 + GRACE_NO_EVENT。公開日も不正なら残す。
        $pubDate = parse_dot_date($n['date'] ?? '');
        if ($pubDate === null) { $kept[] = $n; continue; }
        $deadline = (clone $pubDate)->modify("+{$GRACE_NO_EVENT} days");
    }

    // 期限を過ぎた（今日 > 期限）ら削除。
    if ($today > $deadline) {
        $removed[] = $n;
    } else {
        $kept[] = $n;
    }
}

if (count($removed) === 0) {
    fwrite(STDOUT, "削除対象なし（保持 " . count($kept) . " 件）\n");
    exit(0);
}

// 削除分は archive へ退避してから消す（誤削除に備える）。添付ファイルは実体ごと消えるが
// メタ情報（タイトル・URL・添付名）は残るので、必要なら後から状況を把握できる。
$archive_file = dirname(__DIR__) . '/data/news_archive.json';
$ajson = is_file($archive_file) ? file_get_contents($archive_file) : false;
$archive = $ajson ? json_decode($ajson, true) : null;
if (!is_array($archive) || !isset($archive['news'])) {
    $archive = ['news' => []];
}
$stamp = date('Y-m-d') . 'T' . date('H:i:s');
foreach ($removed as $n) {
    if (is_array($n)) { $n['archived_at'] = $stamp; $archive['news'][] = $n; }
}
// 肥大化を防ぐため最新500件までに制限
if (count($archive['news']) > 500) {
    $archive['news'] = array_slice($archive['news'], -500);
}
$archive['updated'] = $stamp;
file_put_contents(
    $archive_file,
    json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

// 添付ファイルを掃除
$unlinked = 0;
foreach ($removed as $n) {
    if (is_array($n['attachments'] ?? null)) {
        $unlinked += news_unlink_attachments($n['attachments']);
    }
}

$data['news']    = array_values($kept);
$data['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

$written = file_put_contents(
    $data_file,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

if ($written === false) {
    fwrite(STDERR, "書き込み失敗\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "削除 %d 件 / 保持 %d 件 / 添付ファイル削除 %d 個\n",
    count($removed), count($kept), $unlinked
));
foreach ($removed as $n) {
    fwrite(STDOUT, "  - [{$n['event_date']}] " . ($n['title'] ?? '') . "\n");
}
exit(0);
