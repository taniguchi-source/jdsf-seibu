<?php
/**
 * テーマCSS変数ジェネレーター
 * data/theme.json を読み込み、選択テーマに対応した
 * CSS カスタムプロパティ（:root）とカルーセル背景色を出力する。
 */
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── 10テーマ定義 ─────────────────────────────────────────────
$THEMES = [
  1  => ['name'=>'情熱・アクティブ',    'base'=>'#F7F3EF', 'main'=>'#8C2333', 'accent'=>'#C8961E'],
  2  => ['name'=>'優雅・フォーマル',    'base'=>'#F1F3F5', 'main'=>'#1864AB', 'accent'=>'#D4AF37'],
  3  => ['name'=>'爽やか・親しみやすさ','base'=>'#FFFFFF',  'main'=>'#228BE6', 'accent'=>'#FD7E14'],
  4  => ['name'=>'伝統・落ち着き',      'base'=>'#FDFBF7', 'main'=>'#5F316E', 'accent'=>'#E6B422'],
  5  => ['name'=>'華やか・非日常',      'base'=>'#FDFDFD', 'main'=>'#343A40', 'accent'=>'#C23B6E'],
  6  => ['name'=>'健康・生涯スポーツ',  'base'=>'#F2F8F2', 'main'=>'#2B8A3E', 'accent'=>'#FAB005'],
  7  => ['name'=>'先進的・ユース世代',  'base'=>'#F8F9FA', 'main'=>'#495057', 'accent'=>'#12B886'],
  8  => ['name'=>'ロマンチック・社交',  'base'=>'#FEF9F6', 'main'=>'#6B2C40', 'accent'=>'#C09246'],
  9  => ['name'=>'柔雅・グレース',       'base'=>'#FFF5F8', 'main'=>'#B06880', 'accent'=>'#B89060'],
  10 => ['name'=>'アットホーム・親睦',  'base'=>'#FFFDF5', 'main'=>'#D9480F', 'accent'=>'#66A80F'],
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
