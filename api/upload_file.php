<?php
/**
 * 汎用ファイルアップロードAPI（主サイト）
 * お知らせの添付（画像・PDF）等で使用。
 * POST: file（multipart）, 任意 max_px（画像を長辺 max_px まで縮小）
 * 認証: _auth.php（POST + 同一オリジン + CSRF + admin セッション）
 * 返り値: { ok, url:'uploads/xxx', orig, size, ext, type:'image'|'pdf' }
 */
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_auth.php';
require_auth('admin');

/* ===== ファイル確認 ===== */
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? 'none';
    http_response_code(400);
    echo json_encode(['error' => 'ファイルのアップロードに失敗しました (code:' . $errCode . ')'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file     = $_FILES['file'];
$tmpPath  = $file['tmp_name'];
$origName = basename($file['name']);
$size     = (int)$file['size'];

/* ===== サイズ制限 (10 MB) ===== */
if ($size > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'ファイルサイズは 10MB 以下にしてください'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== 拡張子チェック ===== */
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'jpg / png / gif / pdf のみアップロードできます'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== MIME タイプ確認 ===== */
$allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
if (function_exists('finfo_open')) {
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $tmpPath);
    finfo_close($fi);
    if (!in_array($mime, $allowedMime, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'ファイルの内容が許可されていない形式です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ===== 保存先 ===== */
$uploadDir = dirname(__DIR__) . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/* ===== ユニーク名 ===== */
$safeName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . $safeName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'ファイルの保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== 任意: 長辺を max_px まで縮小（POSTで max_px が来たときだけ） =====
   呼び出し側が明示したときだけ縮小し、既定は従来どおり無変換。
   GDが無い・アニメGIF等で失敗した場合は原本のまま残す（アップロードは失敗させない）。 */
$maxPx = isset($_POST['max_px']) ? (int)$_POST['max_px'] : 0;
if ($maxPx >= 50 && $maxPx <= 4000
    && in_array($ext, ['jpg', 'jpeg', 'png'], true)   // gif はアニメを壊さないよう対象外
    && function_exists('imagecreatetruecolor')) {
    $shrunk = shrink_image($destPath, $ext, $maxPx);
    if ($shrunk) {
        clearstatcache(true, $destPath);
        $size = (int)filesize($destPath);
    }
}

/**
 * 長辺が $maxPx を超える画像を縮小して上書き保存する。
 * 成功時 true。縮小不要・失敗時は false（元ファイルは触らない）。
 */
function shrink_image(string $path, string $ext, int $maxPx): bool
{
    $info = @getimagesize($path);
    if (!$info) return false;
    [$w, $h] = $info;
    if ($w <= 0 || $h <= 0) return false;
    if (max($w, $h) <= $maxPx) return false;          // 既に十分小さい

    $scale = $maxPx / max($w, $h);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $src = ($ext === 'png') ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
    if (!$src) return false;

    $dst = imagecreatetruecolor($nw, $nh);
    if (!$dst) { imagedestroy($src); return false; }
    if ($ext === 'png') {                              // 透過を保つ
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    // 一時ファイルへ書いてから差し替え（途中で失敗しても原本を壊さない）
    $tmp = $path . '.tmp';
    $ok  = ($ext === 'png') ? @imagepng($dst, $tmp, 6) : @imagejpeg($dst, $tmp, 85);
    imagedestroy($src);
    imagedestroy($dst);

    if (!$ok || !file_exists($tmp)) { @unlink($tmp); return false; }
    // 縮小しても軽くならない画像は原本のまま残す。
    if (filesize($tmp) >= filesize($path)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $path))      { @unlink($tmp); return false; }
    return true;
}

echo json_encode([
    'ok'   => true,
    'url'  => 'uploads/' . $safeName,
    'orig' => $origName,
    'size' => $size,
    'ext'  => $ext,
    'type' => ($ext === 'pdf') ? 'pdf' : 'image',
], JSON_UNESCAPED_UNICODE);
