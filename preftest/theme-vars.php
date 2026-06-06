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
