<?php
/**
 * テーマCSS変数ジェネレーター
 * data/theme.json を読み込み、選択テーマに対応した
 * CSS カスタムプロパティ（:root）とカルーセル背景色を出力する。
 */
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── 10テーマ定義（デジタル庁デザイン原則 × 和の伝統色） ──────
// ベース: 温かみある和紙白・桜白系  メイン: 深み・節制の伝統色  アクセント: 金・銅・翠など自然素材色
$THEMES = [
  1  => ['name'=>'情熱・アクティブ',    'base'=>'#FAF8F5', 'main'=>'#7A3040', 'accent'=>'#A8832A'],
  //   蘇芳(すおう)赤 × アンティークゴールド × 温白  ラテン競技の情熱を品格で包む
  2  => ['name'=>'優雅・フォーマル',    'base'=>'#F5F6F8', 'main'=>'#1A3A5C', 'accent'=>'#8A7040'],
  //   紺青(こんじょう) × 青銅金 × 清白  格式あるスタンダードの正装感
  3  => ['name'=>'爽やか・親しみやすさ','base'=>'#F5F9FC', 'main'=>'#2560A0', 'accent'=>'#C06828'],
  //   瑠璃色(るりいろ) × 丹(たん)橙 × 淡青白  開かれた連盟らしい清潔感
  4  => ['name'=>'伝統・落ち着き',      'base'=>'#FDFAF5', 'main'=>'#4A2A5E', 'accent'=>'#9C7A1E'],
  //   江戸紫 × 山吹金 × 和紙色  歴史と地域文化の深み
  5  => ['name'=>'華やか・非日常',      'base'=>'#FAFAFA', 'main'=>'#303640', 'accent'=>'#A84060'],
  //   墨色(すみいろ) × 薔薇色 × 純白  競技会の非日常・ドレスの輝き
  6  => ['name'=>'健康・生涯スポーツ',  'base'=>'#F4F9F4', 'main'=>'#2A6840', 'accent'=>'#7A9028'],
  //   常磐色(ときわいろ) × 若草色 × 淡萌葱白  自然の中の生涯スポーツ
  7  => ['name'=>'先進的・ユース世代',  'base'=>'#F5F7FA', 'main'=>'#2A4A62', 'accent'=>'#28906A'],
  //   鉄紺(てつこん) × 翠緑(すいりょく) × 淡灰白  デジタルネイティブ世代の清潔感
  8  => ['name'=>'ロマンチック・社交',  'base'=>'#FDF8F7', 'main'=>'#5C2A3A', 'accent'=>'#A88030'],
  //   葡萄色(ぶどういろ) × 金茶 × 燭白  燭台の灯りのような夜会の温もり
  9  => ['name'=>'柔雅・グレース',      'base'=>'#FEF6F8', 'main'=>'#7D5060', 'accent'=>'#9A8060'],
  //   薄紅梅(うすこうばい) × 刈安金 × 桜白  大和撫子の静かな気品
  10 => ['name'=>'アットホーム・親睦',  'base'=>'#FBF8F2', 'main'=>'#985030', 'accent'=>'#5A8028'],
  //   柿色(かきいろ) × 苔色(こけいろ) × 生成白  地域に根ざした温かさ
];

// ── 現在のテーマ読込 ─────────────────────────────────────────
$data_file = __DIR__ . '/data/theme.json';
$raw = @file_get_contents($data_file);
$saved = $raw ? @json_decode($raw, true) : [];
$id = (int)($saved['theme_id'] ?? 2);
if (!isset($THEMES[$id])) $id = 2;

$base   = $THEMES[$id]['base'];
$main   = $THEMES[$id]['main'];
$accent = $THEMES[$id]['accent'];

// ── カラー演算ユーティリティ ─────────────────────────────────
function _h2r($hex) {
  $h = ltrim($hex, '#');
  return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
}
function _r2h($r, $g, $b) {
  return sprintf('#%02x%02x%02x',
    max(0, min(255, (int)round($r))),
    max(0, min(255, (int)round($g))),
    max(0, min(255, (int)round($b)))
  );
}
function _lt($hex, $p) {            // 白方向に明るくする (p: 0〜1)
  [$r,$g,$b] = _h2r($hex);
  return _r2h($r+(255-$r)*$p, $g+(255-$g)*$p, $b+(255-$b)*$p);
}
function _dk($hex, $p) {            // 暗くする (p: 0〜1)
  [$r,$g,$b] = _h2r($hex);
  return _r2h($r*(1-$p), $g*(1-$p), $b*(1-$p));
}
function _mx($h1, $h2, $w) {        // 2色ミックス (w=0→h1, w=1→h2)
  [$r1,$g1,$b1] = _h2r($h1);
  [$r2,$g2,$b2] = _h2r($h2);
  return _r2h($r1*(1-$w)+$r2*$w, $g1*(1-$w)+$g2*$w, $b1*(1-$w)+$b2*$w);
}
function _rgba($hex, $a) {
  [$r,$g,$b] = _h2r($hex);
  return "rgba($r,$g,$b,$a)";
}

// ── CSS変数の算出 ────────────────────────────────────────────
$pDark   = _dk($main, 0.28);     // --primary-dark
$pLight  = _lt($main, 0.24);     // --primary-light (ボタン・リンク)
$aLight  = _lt($accent, 0.40);   // --accent-light
$bgLight = _mx($base, $main, 0.04);  // --bg-light (section-alt)
$bgBlue  = _mx($base, $main, 0.11);  // --bg-blue (ラベル・ホバー背景)
$shadow  = _rgba($main, '0.11');
$shadowH = _rgba($main, '0.22');

// ── カルーセルグラデーション ─────────────────────────────────
$s1a = _dk($main, 0.35);   $s1b = _dk($main, 0.18);   $s1c = _lt($main, 0.08);
$s2a = _dk($main, 0.50);   $s2b = _dk($main, 0.34);   $s2c = _dk($main, 0.16);
$s3a = _dk($main, 0.34);   $s3b = _mx($main,$accent,0.48);  $s3c = $accent;

echo ":root {
  --primary:       $main;
  --primary-dark:  $pDark;
  --primary-light: $pLight;
  --accent:        $accent;
  --accent-light:  $aLight;
  --bg-white:      $base;
  --bg-light:      $bgLight;
  --bg-blue:       $bgBlue;
  --shadow:        0 2px 8px $shadow;
  --shadow-hover:  0 8px 28px $shadowH;
}

/* カルーセルスライド背景（テーマに連動） */
.slide-1 { background: linear-gradient(135deg, {$s1a} 0%, {$s1b} 50%, {$s1c} 100%); }
.slide-2 { background: linear-gradient(135deg, {$s2a} 0%, {$s2b} 40%, {$s2c} 100%); }
.slide-3 { background: linear-gradient(135deg, {$s3a} 0%, {$s3b} 60%, {$s3c} 100%); }
";

// ── ヒーローエリア高さ（data/hero.json 連動・描画前に適用しちらつき防止） ──
$hero_file = __DIR__ . '/data/hero.json';
$hraw   = @file_get_contents($hero_file);
$hsaved = $hraw ? @json_decode($hraw, true) : [];
$hh     = $hsaved['height'] ?? 'standard';
// 標準280pxを基準にした分数（standard は上書きせず既定280pxを使用）
$hero_map = ['five-fourths' => '350px', 'four-fifths' => '224px', 'two-thirds' => '187px', 'half' => '140px'];

if ($hh === 'none') {
    echo "\n/* ヒーローエリア非表示 */\n.hero-main { display: none !important; }\n";
} elseif (isset($hero_map[$hh])) {
    $hpx = $hero_map[$hh];
    // 標準280pxより低くするため height を !important で固定（外部CSSの height を上書き）。
    // 情報パネルははみ出し分をスクロール。
    echo "\n/* ヒーローエリア高さ（標準280px基準） */\n";
    echo "@media (min-width: 769px) {\n";
    echo "  .hero-main { height: {$hpx} !important; min-height: 0 !important; }\n";
    echo "  .hero-carousel-wrapper { height: {$hpx} !important; min-height: {$hpx} !important; }\n";
    echo "  .hero-info-wrapper { height: {$hpx} !important; overflow-y: auto; }\n";
    echo "}\n";
    echo "@media (max-width: 768px) {\n";
    echo "  .hero-main { height: auto !important; min-height: 0 !important; }\n";
    echo "  .hero-carousel-wrapper { height: {$hpx} !important; min-height: {$hpx} !important; }\n";
    echo "  .carousel-content { height: {$hpx} !important; }\n";
    echo "}\n";

    // ── 高さに合わせてカルーセル文字を縮小し、全体が収まるようにする ──
    $typo = [
        'four-fifths' => ['cpad'=>'18px 24px 22px', 'badge'=>'.72rem','bmb'=>'8px', 'title'=>'1.6rem', 'tlh'=>'1.2',  'tmb'=>'6px', 'desc'=>'.82rem','dlh'=>'1.5'],
        'two-thirds'  => ['cpad'=>'14px 22px 16px', 'badge'=>'.68rem','bmb'=>'6px', 'title'=>'1.3rem', 'tlh'=>'1.18', 'tmb'=>'5px', 'desc'=>'.78rem','dlh'=>'1.4'],
        'half'        => ['cpad'=>'9px 20px 11px',  'badge'=>'.64rem','bmb'=>'4px', 'title'=>'1.05rem','tlh'=>'1.15', 'tmb'=>'3px', 'desc'=>'.72rem','dlh'=>'1.3'],
    ];
    if (isset($typo[$hh])) {
        $t = $typo[$hh];
        echo "/* 文字を高さに合わせて縮小（タイトル・サブタイトルが収まるように） */\n";
        echo ".carousel-content { padding: {$t['cpad']} !important; }\n";
        echo ".hero-badge { font-size: {$t['badge']} !important; margin-bottom: {$t['bmb']} !important; padding: 3px 10px !important; }\n";
        echo ".hero-title { font-size: {$t['title']} !important; line-height: {$t['tlh']} !important; margin-bottom: {$t['tmb']} !important; }\n";
        echo ".hero-desc  { font-size: {$t['desc']} !important; line-height: {$t['dlh']} !important; }\n";
    }
}

// ── カルーセル矢印（手動切替）の表示ON/OFF（data/hero.json arrows 連動） ──
// 既定（キー無し）は表示。arrows===false のときだけ矢印を隠す。
if (array_key_exists('arrows', $hsaved) && $hsaved['arrows'] === false) {
    echo "\n/* カルーセル矢印を非表示（手動切替オフ） */\n.carousel-arrow { display: none !important; }\n";
}

// ── カルーセル横幅（data/hero.json width 連動・デスクトップのみ） ──
$hw = $hsaved['width'] ?? 'standard';
$width_map = ['three-quarters' => 75, 'half' => 50, 'third' => 33.333];

if ($hw === 'full') {
    // 全幅: カルーセルが全体を占有、クイックリンクは非表示、タイトルは画像に重ねる
    echo "\n/* カルーセル横幅: 全幅（クイックリンク非表示） */\n";
    echo "@media (min-width: 769px) {\n";
    echo "  .hero-carousel-wrapper { flex: 1 1 100% !important; max-width: 100% !important; }\n";
    echo "  .hero-info-wrapper { display: none !important; }\n";
    echo "  .carousel-content { right: 0 !important; }\n";
    echo "}\n";
} elseif (isset($width_map[$hw])) {
    // 狭幅: 画像を画面中央に、タイトルは左パネル（クイックリンクと同じ濃紺）、QLは右パネル
    // 左右の列幅を (100% - 画像幅) / 2 で明示固定 → カルーセル画像が必ず中央
    $wnum = $width_map[$hw];
    $side = (100 - $wnum) / 2;
    $wpct = rtrim(rtrim(number_format($wnum, 3, '.', ''), '0'), '.') . '%';
    $spct = rtrim(rtrim(number_format($side, 3, '.', ''), '0'), '.') . '%';
    echo "\n/* カルーセル横幅: {$wpct}（画像中央・左右に濃紺パネル {$spct}） */\n";
    echo "@media (min-width: 769px) {\n";
    echo "  .hero-main { align-items: stretch !important; }\n";
    // 左：タイトル列（背景はクイックリンクと同じ濃紺、文字は白、幅は右と同じ）
    echo "  .carousel-content { position: static !important; right: auto !important; bottom: auto !important; order: 1 !important; flex: 0 0 {$spct} !important; max-width: {$spct} !important; min-width: 0 !important; align-self: stretch !important; box-sizing: border-box !important; display: flex !important; flex-direction: column !important; justify-content: center !important; pointer-events: auto !important; padding: 18px 24px !important; background: var(--primary, #0D2347) !important; color: #fff !important; }\n";
    echo "  .carousel-content .hero-title { color: #fff !important; text-shadow: none !important; }\n";
    echo "  .carousel-content .hero-desc { color: rgba(255,255,255,.85) !important; text-shadow: none !important; }\n";
    // バッジはカルーセル画像の上（左上）に重ねる
    echo "  .carousel-content .hero-badge { position: absolute !important; top: 16px !important; left: calc({$spct} + 16px) !important; z-index: 4 !important; margin: 0 !important; background: rgba(0,0,0,.45) !important; color: #fff !important; border-color: rgba(255,255,255,.3) !important; backdrop-filter: blur(4px); }\n";
    echo "  .carousel-content .hero-badge-dot { background: #4ade80 !important; }\n";
    // 中央：カルーセル画像（固定割合）
    echo "  .hero-carousel-wrapper { order: 2 !important; flex: 0 0 {$wpct} !important; max-width: {$wpct} !important; }\n";
    // 右：クイックリンク（左と同じ幅 → 画像が中央に来る）。高さ制限を解除し全件表示
    echo "  .hero-info-wrapper { order: 3 !important; flex: 0 0 {$spct} !important; max-width: {$spct} !important; min-width: 0 !important; width: auto !important; box-sizing: border-box !important; height: auto !important; overflow: visible !important; }\n";
    // クイックリンクの列数: 1/2・1/3 のときだけ2列、それ以外(3/4)は1列
    if ($hw === 'half' || $hw === 'third') {
        echo "  .hero-info-links { display: grid !important; grid-template-columns: 1fr 1fr !important; align-content: center !important; }\n";
        echo "  .hero-info-links a { border-bottom: 1px solid rgba(255,255,255,.07) !important; }\n";
    }
    echo "}\n";
}
