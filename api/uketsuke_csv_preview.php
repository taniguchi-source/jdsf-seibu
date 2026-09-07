<?php
/* アップロードされたCSVを解析して中身を返す（保存はしない）。
   列の意味は画面側で割り当ててから uketsuke_save_roster.php に送る。

   JDSF競技会支援システム（DCS）の「選手名簿ファイル処理」で書き出したCSVは
   ・1行目が項目名ではなく認識コード（例: $#*#$[DCSsys-outD] 010100001）
   ・項目名の行が存在しない
   ・文字コードは Shift_JIS(cp932)
   なので、認識コード行を読み飛ばし、ヘッダー無しとして扱えるようにする。 */
require __DIR__ . '/_uketsuke.php';
require_auth('admin');

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_out(['error' => 'CSVファイルを選択してください'], 400);
}
if (($_FILES['file']['size'] ?? 0) > 2 * 1024 * 1024) {
    json_out(['error' => 'ファイルが大きすぎます（2MBまで）'], 400);
}

$raw = file_get_contents($_FILES['file']['tmp_name']);
if ($raw === false || $raw === '') json_out(['error' => 'CSVを読み込めませんでした'], 400);

/* 文字コードを判定して UTF-8 に揃える */
$enc = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'eucJP-win'], true);
if ($enc === false) $enc = 'SJIS-win';
$text = ($enc === 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', $enc);
$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);      /* BOM を除去 */

/* DCS の認識コード行があれば読み飛ばす */
$dcs_signature = null;
$nl = strcspn($text, "\r\n");
$first = substr($text, 0, $nl);
if (strpos(ltrim($first), '$#*#$[') === 0) {
    $dcs_signature = trim($first);
    $text = ltrim(substr($text, $nl), "\r\n");
}

/* 引用符の中に改行があっても壊れないよう fgetcsv で読む */
$rows = [];
$fh = fopen('php://memory', 'r+');
fwrite($fh, $text);
rewind($fh);
while (($cols = fgetcsv($fh)) !== false) {
    if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) continue;  /* 空行 */
    $rows[] = $cols;
    if (count($rows) >= 1200) break;
}
fclose($fh);
if (!$rows) json_out(['error' => 'CSVにデータがありません'], 400);

json_out([
    'ok'                => true,
    'rows'              => $rows,
    'encoding'          => $enc,
    'dcs_signature'     => $dcs_signature,
    /* DCS形式なら項目名の行が無いので、画面側の「1行目はヘッダー」を既定でオフにする */
    'likely_has_header' => ($dcs_signature === null),
]);
