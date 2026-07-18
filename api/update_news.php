<?php
/**
 * 公開お知らせ 更新API
 * POST: token, id, category, title, detail, url, event_date
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/_auth.php';
require __DIR__ . '/_news_util.php';
require_auth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id         = trim($_POST['id']         ?? '');
$category   = trim($_POST['category']   ?? '');
$title      = trim($_POST['title']      ?? '');
$detail     = trim($_POST['detail']     ?? '');
$url        = trim($_POST['url']        ?? '');
$event_date = trim($_POST['event_date'] ?? '');
$attachments = news_sanitize_attachments($_POST['attachments'] ?? '');

if ($id === '' || !$category || !$title || !$detail) {
    http_response_code(400);
    echo json_encode(['error' => 'ID・カテゴリ・タイトル・詳細内容は必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) {
    $url = '';
}

// 実施日の処理（YYYY-MM-DD → Y.m.d 表示形式。空欄なら空にする＝公開日+90日で判定）
$event_display = '';
if ($event_date) {
    $parts = explode('-', $event_date);
    if (count($parts) === 3) {
        $event_display = sprintf('%s.%02d.%02d', $parts[0], (int)$parts[1], (int)$parts[2]);
    }
}

$data_file = dirname(__DIR__) . '/data/news.json';
$json      = file_exists($data_file) ? file_get_contents($data_file) : false;
$existing  = $json ? json_decode($json, true) : null;
if (!is_array($existing) || !isset($existing['news'])) {
    http_response_code(404);
    echo json_encode(['error' => 'データファイルが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$found = false;
foreach ($existing['news'] as &$item) {
    if (($item['id'] ?? '') === $id) {
        // 編集で外された添付の実ファイルを掃除（新リストに無い旧ファイルのみ削除）
        $oldAtt = is_array($item['attachments'] ?? null) ? $item['attachments'] : [];
        $newUrls = array_map(function ($a) { return $a['url']; }, $attachments);
        $removed = array_filter($oldAtt, function ($a) use ($newUrls) {
            return is_array($a) && !in_array($a['url'] ?? '', $newUrls, true);
        });
        if ($removed) news_unlink_attachments(array_values($removed));

        $item['category']   = $category;
        $item['title']      = $title;
        $item['detail']     = $detail;
        $item['url']        = $url;
        $item['attachments'] = $attachments;
        $item['event_date'] = $event_display;   // 空欄なら空にする（実施日を消せる）
        $found = true;
        break;
    }
}
unset($item);

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => '該当するIDが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$existing['updated'] = date('Y-m-d') . 'T' . date('H:i:s');

$written = file_put_contents(
    $data_file,
    json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

if ($written === false) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの書き込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
