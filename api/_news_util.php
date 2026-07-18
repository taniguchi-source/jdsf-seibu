<?php
/* =====================================================================
   公開お知らせ（news）共通ユーティリティ
   - 添付(attachments)の検証・正規化
   - 添付ファイルの物理削除（uploads/ 配下限定・パストラバーサル防止）
   save_news / update_news / delete_news / cleanup_news で共有する。
   ===================================================================== */

/**
 * 添付（画像・PDF）の受け取りを検証して正規化する。
 * 入力: JSON文字列または配列 [{url,name,type}] / 出力: 安全な配列（最大10件）
 * url は uploads/ 配下（相対）か http(s) のみ、type は image|pdf のみ許可。
 */
function news_sanitize_attachments($raw): array {
    $arr = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $a) {
        if (!is_array($a)) continue;
        $u = trim((string)($a['url'] ?? ''));
        $t = (string)($a['type'] ?? '');
        $n = trim((string)($a['name'] ?? ''));
        if ($u === '') continue;
        $isLocal = (strpos($u, 'uploads/') === 0) && strpos($u, '..') === false;
        $isHttp  = (bool)preg_match('#^https?://#i', $u);
        if (!$isLocal && !$isHttp) continue;
        if ($t !== 'image' && $t !== 'pdf') {
            $t = preg_match('/\.pdf$/i', $u) ? 'pdf' : 'image';
        }
        $out[] = [
            'url'  => mb_substr($u, 0, 500),
            'name' => mb_substr($n, 0, 255),
            'type' => $t,
        ];
        if (count($out) >= 10) break;
    }
    return $out;
}

/**
 * attachments 配列のうち uploads/ 配下のローカルファイルを物理削除する。
 * http(s) の外部URLや、uploads/ 外・パストラバーサルは無視（安全側）。
 * 戻り値: 実際に削除したファイル数。
 */
function news_unlink_attachments(array $attachments): int {
    $uploadsDir = realpath(dirname(__DIR__) . '/uploads');
    if ($uploadsDir === false) return 0;
    $count = 0;
    foreach ($attachments as $a) {
        $u = is_array($a) ? (string)($a['url'] ?? '') : '';
        if (strpos($u, 'uploads/') !== 0) continue;      // ローカルのみ
        if (strpos($u, '..') !== false)   continue;      // トラバーサル防止
        $name = basename($u);                             // ファイル名だけに正規化
        $path = $uploadsDir . DIRECTORY_SEPARATOR . $name;
        $real = realpath($path);
        // 必ず uploads/ 直下に収まっていること
        if ($real !== false && strpos($real, $uploadsDir . DIRECTORY_SEPARATOR) === 0 && is_file($real)) {
            if (@unlink($real)) $count++;
        }
    }
    return $count;
}
