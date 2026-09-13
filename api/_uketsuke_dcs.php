<?php
/* DCS（JDSF競技会支援システム）の作業フォルダにある2つのファイルを読む。
     SSS__I.dat   … 大会名・開催日・競技区分（名称・コード・並び順）
     SSS__MEM.dat … 名簿（背番号・氏名・所属・出場区分）
   選手名簿CSVの書き出しをしなくても、大会フォルダをそのまま取り込めるようにするため。
   区分コードと並び順がDCSの原本から取れるので、シラバス（大会要項）に頼らなくてよい。

   どちらも Shift_JIS の固定長テキスト。**桁はバイト数で決まっている**ので、
   切り出しはバイトのまま行い、切り出したあとで UTF-8 へ直す。
   （0x85 のような「改行に見えるバイト」は漢字の2バイト目なので、改行は \r\n だけで切る） */

function uk_dcs_s($bytes) {
    $s = mb_convert_encoding((string)$bytes, 'UTF-8', 'SJIS-win');
    /* 全角スペースも含めて前後を落とす */
    return trim(preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $s));
}

function uk_dcs_rows($raw) {
    return preg_split("/\r\n/", (string)$raw);
}

/* ---- SSS__I.dat ---- */
function uk_dcs_parse_info($raw) {
    $rows = uk_dcs_rows($raw);
    if (count($rows) < 20 || strpos($rows[0], '#DCSsys') === false) {
        return ['error' => 'SSS__I.dat ではないようです（1行目が #DCSsys で始まっていません）'];
    }
    $cut  = function ($s) { return uk_dcs_s(preg_replace('/\/\/-.*$/s', '', $s)); };
    /* 2行目は大会番号（データフォルダの名前。例 260914）。公認番号として使う。 */
    $comp_no = preg_replace('/[^0-9A-Za-z]/', '', uk_dcs_s($rows[1] ?? ''));
    $name = $cut($rows[2] ?? '');
    if ($name === '') return ['error' => '大会名称を読み取れませんでした'];

    /* 区分の名称（先頭40バイト）と種別（41バイト目 1=スタンダード 2=ラテン） */
    $i = null;
    foreach ($rows as $k => $r) { if (strpos($r, '//- ') === 0 && strpos(uk_dcs_s($r), '競技名称') !== false) { $i = $k; break; } }
    if ($i === null) return ['error' => '競技名称の行が見つかりません'];
    $n = 0;
    if (preg_match('/(\d+)\s*\x{533A}\x{5206}/u', uk_dcs_s($rows[$i]), $m)) $n = (int)$m[1];
    if ($n < 1 || $n > 60) return ['error' => '区分の数を読み取れませんでした'];
    $names = [];
    for ($k = 0; $k < $n; $k++) {
        $line    = $rows[$i + 1 + $k] ?? '';
        $names[] = ['name' => uk_dcs_s(substr($line, 0, 40)), 'kind' => trim(substr($line, 40, 1))];
    }

    /* 区分コード（ランキング情報の各行 2〜4文字目）。競技名称と同じ並び。 */
    $j = null;
    foreach ($rows as $k => $r) { if (strpos($r, '//- ') === 0 && strpos(uk_dcs_s($r), 'ランキング情報') !== false) { $j = $k; break; } }
    if ($j === null) return ['error' => 'ランキング情報の行が見つかりません'];
    $events = [];
    for ($k = 0; $k < $n; $k++) {
        $line = $rows[$j + 1 + $k] ?? '';
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($line, 1, 3)));
        if ($code === '') return ['error' => ($k + 1) . '番目の区分コードを読み取れませんでした'];
        $events[] = ['code' => $code, 'name' => $names[$k]['name'], 'start_time' => ''];
    }

    /* 開催日。末尾の Cael System の行に ISO 形式で入っているのでそれを使う。
       無ければ「２０２６年９月２３日（水祝）」の形を半角にして読む。 */
    $date = '';
    foreach ($rows as $k => $r) {
        if (strpos($r, '//- ') === 0 && strpos(uk_dcs_s($r), 'Cael System') !== false) {
            $cand = uk_dcs_s($rows[$k + 1] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cand)) $date = $cand;
            break;
        }
    }
    if ($date === '') {
        $jp = mb_convert_kana($cut($rows[3] ?? ''), 'a', 'UTF-8');
        if (preg_match('/(\d{4})\D+(\d{1,2})\D+(\d{1,2})/u', $jp, $m)) {
            $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
    }

    return ['name' => $name, 'date' => $date, 'comp_no' => $comp_no,
            'venue' => $cut($rows[5] ?? ''),
            'host' => $cut($rows[4] ?? ''), 'events' => $events];
}

/* ---- SSS__MEM.dat ----
   前半1000件が名前レコード（背番号＝並び順）。後半はカナ・会員番号などで、受付では使わない。
     0-15  リーダー氏名 / 16-31 パートナー氏名 / 32-55 所属 / 56- 出場区分のフラグ
   16バイトに収まらない名前はフィールドが「*」になり、96バイト目から32バイトずつ実体が入る。 */
function uk_dcs_parse_members($raw, $codes) {
    $rows = uk_dcs_rows($raw);
    if (count($rows) < 100) return ['error' => 'SSS__MEM.dat ではないようです（行数が少なすぎます）'];
    $n    = count($codes);
    $out  = [];
    /* 16バイトに収まらない名前が何件あったかを数える（確認表に出す） */
    $long = 0;
    for ($i = 0; $i < 1000 && $i < count($rows); $i++) {
        $r = $rows[$i];
        if (trim($r) === '') continue;
        $leader  = uk_dcs_s(substr($r, 0, 16));
        $partner = uk_dcs_s(substr($r, 16, 16));
        if ($leader === '*' || $partner === '*') {
            if ($leader  === '*') $long++;
            if ($partner === '*') $long++;
            $ext = [];
            for ($o = 96; $o < strlen($r); $o += 32) {
                $v = uk_dcs_s(substr($r, $o, 32));
                if ($v !== '') $ext[] = $v;
            }
            if ($leader  === '*' && $ext) $leader  = array_shift($ext);
            if ($partner === '*' && $ext) $partner = array_shift($ext);
        }
        $flags  = substr($r, 56, $n);
        $events = [];
        for ($k = 0; $k < $n; $k++) {
            if (substr($flags, $k, 1) === '1') $events[] = $codes[$k];
        }
        $out[] = ['bib' => $i + 1, 'leader' => $leader, 'partner' => $partner,
                  'affiliation' => uk_dcs_s(substr($r, 32, 24)), 'events' => $events];
    }
    if (!$out) return ['error' => '名簿が1組も読み取れませんでした'];
    return ['roster' => $out, 'long_names' => $long];
}

/* アップロードされた1ファイルを読む（共通の入口） */
function uk_dcs_upload($key, $label) {
    if (!isset($_FILES[$key]) || ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_out(['error' => $label . ' を選んでください'], 400);
    }
    if (($_FILES[$key]['size'] ?? 0) > 4 * 1024 * 1024) {
        json_out(['error' => $label . ' が大きすぎます（4MBまで）'], 400);
    }
    $raw = file_get_contents($_FILES[$key]['tmp_name']);
    if ($raw === false || $raw === '') json_out(['error' => $label . ' を読み込めませんでした'], 400);
    return $raw;
}

/* 2つのファイルから「大会情報＋区分＋名簿」を作る。どこかで失敗したら error を返す。 */
function uk_dcs_read_pair($raw_info, $raw_mem) {
    $info = uk_dcs_parse_info($raw_info);
    if (isset($info['error'])) return $info;
    $mem = uk_dcs_parse_members($raw_mem, array_column($info['events'], 'code'));
    if (isset($mem['error'])) return $mem;
    $info['roster']     = $mem['roster'];
    $info['long_names'] = $mem['long_names'];
    return $info;
}
