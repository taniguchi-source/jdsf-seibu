<?php
/* =====================================================================
   大会ごとのシラバス（大会要項）の種目
   - 出場選手一覧に「種目（Ｗ・Ｔ・最終予選よりＶ…）」を出すために使う。
   - JDSFは大会が終わるとシラバスのページを消すため、毎朝取り込んでいる
     競技会一覧（data/competitions_seibu.json）からも種目が消える。
     そこで、大会を作った時点で data/uketsuke/<大会ID>/syllabus.json に写し取り、
     以後はその控えを使う（あとから何度印刷しても種目が出る）。
   - 控えが作れないときだけ、手動で adm.jdsf.jp のシラバスを1回取りに行く。
     この相手は連続アクセスでIP単位の遮断を受けるので、自動では絶対に走らせない。
   ===================================================================== */

function uk_syllabus_file($id) { return uk_dir($id) . '/syllabus.json'; }

function uk_syllabus_load($id) {
    $d = uk_read_json(uk_syllabus_file($id), null);
    return is_array($d) ? $d : null;
}

function uk_syllabus_save($id, $data) {
    uk_write_json(uk_syllabus_file($id), $data);
    return $data;
}

/* 大会番号（公認番号）の形。DCSの大会番号は6桁の数字。 */
function uk_valid_comp_no($s) {
    return is_string($s) && preg_match('/^[0-9]{4,8}$/', $s) === 1;
}

/* 大会名の比較用。全角英数を半角に、空白を落として大文字にする（DCSとサイトで表記が違うため） */
function uk_syl_norm($s) {
    $s = mb_convert_kana((string)$s, 'as');
    $s = preg_replace('/[（(].*?[）)]/u', '', $s);   /* 注意書きの括弧は無視 */
    $s = preg_replace('/\s+/u', '', $s);
    return mb_strtoupper(trim($s));
}

/* ---- 自サイトの競技会一覧から、その大会の行を探す ----
   公認番号が分かっていれば確実。無ければ開催日＋大会名で引き当てる。
   毎朝の取り込みは「1週間前〜これから」の大会しかシラバスを見に行かないため、
   古い大会は行はあっても種目が空。それでも公認番号とシラバスのURLは分かるので、
   行そのものを返して、呼ぶ側で使い分ける。 */
function uk_syllabus_find_row($comp_no, $date, $name) {
    $f = dirname(__DIR__) . '/data/competitions_seibu.json';
    $d = uk_read_json($f, null);
    $items = (is_array($d) && isset($d['competitions'])) ? $d['competitions'] : [];
    if (!$items) return null;

    if (uk_valid_comp_no($comp_no)) {
        foreach ($items as $c) {
            if ((string)($c['comp_no'] ?? '') === (string)$comp_no) return $c;
        }
    }
    if ($date !== '') {
        $same = [];
        foreach ($items as $c) {
            if ((string)($c['date_iso'] ?? '') === (string)$date) $same[] = $c;
        }
        $q = uk_syl_norm($name);
        foreach ($same as $c) {
            if (uk_syl_norm($c['name'] ?? '') === $q) return $c;
        }
        /* サイトの競技会一覧の大会名は「GD西部ブロックランキング対象競技会/…
           2026年西部ブロックGD・PDダンススポーツ選手権 第20回…」のように長く、
           DCSの大会名はその一部になっていることが多い。含んでいれば同じ大会とみなす。
           練習用の複製は頭に「練習用」などが付くので、それを外した名前でも見る。 */
        $q2 = preg_replace('/^(練習用|練習|テスト|コピー|複製)+/u', '', $q);
        foreach ($same as $c) {
            $l = uk_syl_norm($c['name'] ?? '');
            if ($l === '') continue;
            foreach (array_unique([$q, $q2]) as $cand) {
                if ($cand === '' || mb_strlen($cand) < 8) continue;   /* 短い名前は当てにしない */
                if (mb_strpos($l, $cand) !== false || mb_strpos($cand, $l) !== false) return $c;
            }
        }
        /* その日に1大会しか無ければ、名前が違ってもそれとみなす（練習用の複製など） */
        if (count($same) === 1) return $same[0];
    }
    return null;
}

/* ---- 第1段：自サイトの競技会一覧から種目を引く（外部への通信なし） ---- */
function uk_syllabus_from_list($comp_no, $date, $name) {
    $hit = uk_syllabus_find_row($comp_no, $date, $name);
    if (!$hit) return null;

    $events = [];
    foreach (($hit['events'] ?? []) as $e) {
        $code = strtoupper(trim((string)($e['code'] ?? '')));
        if ($code === '') continue;
        $events[] = ['code' => $code,
                     'name' => uk_str($e['name'] ?? '', 60),
                     'dances' => uk_str($e['dances'] ?? '', 60)];
    }
    if (!$events) return null;

    return ['comp_no'      => (string)($hit['comp_no'] ?? $comp_no),
            'name'         => uk_str($hit['name'] ?? '', 120),
            'syllabus_url' => (string)($hit['syllabus_url'] ?? ''),
            'source'       => 'list',
            'fetched_at'   => uk_now(),
            'events'       => $events];
}

/* ---- 第2段：JDSFのシラバスを直接1回だけ取りに行く（手動のときだけ） ----
   相手は adm.jdsf.jp。連続アクセスで遮断されるため、
   ・自動実行しない（画面のボタンを押したときだけ）
   ・取れたら保存し、二度目は取りに行かない
   ・短い間隔で続けて叩けないよう、最後に取りに行った時刻を見て断る       */
function uk_syllabus_cooldown_file() { return auth_data_dir() . '/uketsuke_syllabus_last.php'; }

function uk_syllabus_cooldown_left($sec = 30) {
    $f = uk_syllabus_cooldown_file();
    $last = is_file($f) ? (int)(include $f) : 0;
    $left = $last + $sec - time();
    return $left > 0 ? $left : 0;
}
function uk_syllabus_cooldown_touch() {
    @file_put_contents(uk_syllabus_cooldown_file(), "<?php\nreturn " . time() . ";\n", LOCK_EX);
}

/* シラバスのページから「競技内容」の表を読む。
   表の見出しは 区分／略称／競技名／種目。scripts/fetch_competitions.py と同じ規則。 */
function uk_syllabus_parse_html($html) {
    if ($html === '' || !class_exists('DOMDocument')) return [];
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);

    foreach ($xp->query('//table') as $table) {
        $heads = [];
        foreach ($xp->query('.//th', $table) as $th) $heads[] = trim($th->textContent);
        $iCode = array_search('略称', $heads, true);
        if (!in_array('区分', $heads, true) || $iCode === false) continue;
        $iName  = array_search('競技名', $heads, true);
        $iDance = array_search('種目', $heads, true);

        $events = [];
        $seen   = [];
        foreach ($xp->query('.//tr', $table) as $tr) {
            $tds = [];
            foreach ($xp->query('.//td', $tr) as $td) {
                $tds[] = preg_replace('/\s+/u', ' ', trim($td->textContent));
            }
            $need = max($iCode, $iName === false ? 0 : $iName, $iDance === false ? 0 : $iDance);
            if (count($tds) <= $need) continue;
            $code = strtoupper(trim($tds[$iCode]));
            if (!preg_match('/^[A-Z0-9]{2,6}$/', $code) || isset($seen[$code])) continue;
            $seen[$code] = true;
            $events[] = ['code'   => $code,
                         'name'   => $iName  === false ? '' : uk_str($tds[$iName], 60),
                         'dances' => $iDance === false ? '' : uk_str($tds[$iDance], 60)];
        }
        if ($events) return $events;
    }
    return [];
}

/* 公認番号でシラバスを取りに行く。戻り値は syllabus.json と同じ形、または ['error'=>...] */
function uk_syllabus_from_jdsf($comp_no, $known_url = '') {
    if (!uk_valid_comp_no($comp_no) && $known_url === '') {
        return ['error' => '公認番号（大会番号）が分かりません'];
    }
    /* 一覧で分かっているURLがあればそれを使う（古い大会はPDFのことがある） */
    $url = $known_url !== '' ? $known_url
         : 'https://adm.jdsf.jp/competition/syllabus/' . $comp_no . '/';
    /* PDFは読み取れないので、通信する前に伝える（相手に無駄な負荷をかけない） */
    if (preg_match('/\.pdf($|\?)/i', $url)) {
        return ['error' => 'この大会のシラバスはPDFで、種目を自動で読み取れません。'
                         . 'リンクを開いて確かめてください', 'url' => $url];
    }
    if (!function_exists('curl_init')) return ['error' => 'この環境では取りに行けません'];
    $left = uk_syllabus_cooldown_left();
    if ($left > 0) return ['error' => 'JDSFへの問い合わせが続いています。' . $left . '秒ほど待ってから押してください'];
    uk_syllabus_cooldown_touch();     /* 失敗しても間隔を空けさせる */

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'JDSF-SeibuBot/1.0 (+https://jdsf-seibu.com/)',
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status === 0) return ['error' => 'JDSFのページに届きませんでした'];
    if ($status === 404) return ['error' => 'このシラバスは見つかりませんでした（大会後に公開が終わっている可能性があります）'];
    if ($status !== 200) return ['error' => 'JDSFのページが ' . $status . ' を返しました'];

    $events = uk_syllabus_parse_html($body);
    if (!$events) return ['error' => 'シラバスは取れましたが、競技内容の表を読み取れませんでした'];

    return ['comp_no'      => (string)$comp_no,
            'name'         => '',
            'syllabus_url' => $url,
            'source'       => 'jdsf',
            'fetched_at'   => uk_now(),
            'events'       => $events];
}

/* 控えが無ければ、第1段（自サイトの一覧）で作る。作れなければ null。 */
function uk_syllabus_ensure($id, $comp_no, $date, $name) {
    $have = uk_syllabus_load($id);
    if ($have && !empty($have['events'])) return $have;
    $made = uk_syllabus_from_list($comp_no, $date, $name);
    if ($made) return uk_syllabus_save($id, $made);
    return null;
}
