<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ── システム認証 ── */
require __DIR__ . '/_auth.php';
require_auth('admin');

/* ── 資料操作パスワード（役員ログインと同じスプレッドシートM列・GAS経由） ── */
function jdsf_officer_password() {
    $url = 'https://script.google.com/macros/s/AKfycbxS07Mxs6TZHdq0aGTay547EfIrN5igaJ527EaWl-O-RgHv7VHMllszJyMkI30qRU3A/exec?action=auth&site=jdsf-seibu';
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
    }
    if ($resp === false && ini_get('allow_url_fopen')) {
        $resp = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10]]));
    }
    if ($resp) {
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['password'])) {
            $p = trim((string)$j['password']);
            if ($p !== '') return $p;
        }
    }
    return 'seibu2026'; // GAS不通/空欄時のフォールバック（officers.html と一致）
}

$ref_password = $_POST['ref_password'] ?? '';
if ($ref_password !== jdsf_officer_password()) {
    echo json_encode(['ok' => false, 'error' => 'パスワードが正しくありません']);
    exit;
}

$json_path = __DIR__ . '/../data/references.json';
$content   = @file_get_contents($json_path);
$data      = ($content !== false) ? json_decode($content, true) : [];
if (!isset($data['references'])) $data['references'] = [];

$action = $_POST['action'] ?? 'add';

/* ── 削除 ── */
if ($action === 'delete') {
    $del_id = $_POST['id'] ?? '';
    $data['references'] = array_values(array_filter(
        $data['references'],
        function($r) use ($del_id) { return ($r['id'] ?? '') !== $del_id; }
    ));
    file_put_contents($json_path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);
    exit;
}

/* ── 追加 ── */
$date  = trim($_POST['date']  ?? '');
$name  = trim($_POST['name']  ?? '');
$title = trim($_POST['title'] ?? '');
$type  = trim($_POST['type']  ?? 'url');
$url   = trim($_POST['url']   ?? '');

if (!$date || !$name || !$title) {
    echo json_encode(['ok' => false, 'error' => '日付・登録者・資料タイトルは必須です']);
    exit;
}

/* ── ファイルアップロード処理 ── */
if ($type === 'upload') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'ファイルが選択されていないか、アップロードに失敗しました']);
        exit;
    }

    $max_size = 20 * 1024 * 1024; // 20MB
    if ($_FILES['file']['size'] > $max_size) {
        echo json_encode(['ok' => false, 'error' => 'ファイルサイズは20MB以内にしてください']);
        exit;
    }

    $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        echo json_encode(['ok' => false, 'error' => '対応ファイル形式: PDF・Word・Excel・PowerPoint・CSV・ZIP']);
        exit;
    }

    $upload_dir = __DIR__ . '/../uploads/references/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $safe_name = date('Ymd_His') . '_' . preg_replace('/[^\w.\-]/u', '_', basename($_FILES['file']['name']));
    $dest = $upload_dir . $safe_name;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => 'ファイルの保存に失敗しました']);
        exit;
    }

    $url = 'uploads/references/' . $safe_name;
}

$download_password = trim($_POST['download_password'] ?? '');

$new_item = [
    'id'    => 'ref_' . time() . '_' . rand(100, 999),
    'date'  => $date,
    'name'  => $name,
    'title' => $title,
    'url'   => $url,
];
if ($download_password !== '') {
    $new_item['download_password'] = $download_password;
}

array_unshift($data['references'], $new_item); // 新しい順（先頭に追加）
$data['updated'] = date('Y-m-d');

if (file_put_contents($json_path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    echo json_encode(['ok' => false, 'error' => 'データの保存に失敗しました']);
    exit;
}

echo json_encode(['ok' => true]);
