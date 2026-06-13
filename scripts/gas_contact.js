/**
 * JDSF 近畿中四国ブロック委員会  お問い合わせ／認証 GAS（完成版）
 * プロジェクト名: 「お問い合わせ フォーム処理」
 *
 * 【このコードでできること】
 *  1) 主サイト(jdsf-seibu.com)のお問い合わせフォーム … 府県プルダウンで選んだ府県へ送信（G/H/I列）
 *  2) 府県サブドメインのお問い合わせフォーム        … サイトURLで宛先を自動判定して送信（Q〜V列）
 *  3) サイト構築(site-config)からの宛先編集         … Q〜V列(R/S/T/U)を保存
 *  4) ログイン認証                                   … J列URLで判定し M列(管理PW)/N列(構築PW)を返す
 *
 * 【スプレッドシート「HP用資料」の列対応】
 *   G=府県名  H=メール   I=担当者            … 主サイトの府県プルダウン用（従来どおり）
 *   J=サイトURL  K=slug  L=年度  M=管理PW  N=構築PW … 認証用（従来どおり）
 *   Q=府県名  R=送信先メール(To)  S=担当者名  T=CC①  U=CC②  V=サイトURL … 府県サブドメイン用（新）
 *
 * 【再デプロイの注意】URLを変えないため、必ず
 *   「デプロイ」→「デプロイを管理」→ 既存を選び ✏️ →「新バージョン」→「デプロイ」
 *   で更新してください（新規デプロイにするとURLが変わります）。
 */

var SHEET_ID   = '1fpEa8jiIk9hUKyDOp4yWvBKaoPek-LsF2SPhDRTFX0g';
var SHEET_NAME = 'HP用資料';

// 主サイトの全問い合わせに控えを送る事務局アドレス（不要なら '' に）
var OFFICE_BCC = 'info@jdsf-seibu.com';

// site-config からの宛先保存・読込に必要な合言葉（site-config.html と一致させる）
var CONTACT_TOKEN = 'seibu-contact2026';

// ============================ ルーティング ============================

function doGet(e) {
  var p = e.parameter || {};
  if (p.action === 'data')           { return getContactData(); }                 // 主: 府県→担当者名
  if (p.action === 'auth')           { return getAuthPassword(p.site || ''); }     // 認証
  if (p.action === 'contactInfo')    { return getContactInfo(p.url || ''); }       // 府県: 担当者名（メール非公開）
  if (p.action === 'getContactConfig'){ return getContactConfig(p.url || '', p.token || ''); } // site-config 読込
  if (p.action === 'saveContact')    { return saveContact(p); }                    // site-config 保存（CORS可なGETで結果確認）
  return json({ ok: true, msg: 'JDSF Seibu API is running.' });
}

function doPost(e) {
  var data = {};
  try { data = JSON.parse(e.postData.contents); } catch (err) { data = (e && e.parameter) || {}; }

  // ハニーポット（bot対策）: 隠し項目 hp が埋まっていたら送らず正常終了を装う
  if (data.hp) { return json({ status: 'ok' }); }

  if (data.action === 'saveContact') { return saveContact(data); }  // site-config 保存
  if (data.url)                      { return handlePrefContact(data); } // 府県サブドメイン
  return handleContact(data);                                        // 主サイト（府県プルダウン）
}

// ============================ 共通ユーティリティ ============================

function json(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
                       .setMimeType(ContentService.MimeType.JSON);
}
function sheet() {
  return SpreadsheetApp.openById(SHEET_ID).getSheetByName(SHEET_NAME);
}
// URLからホスト名だけを取り出して小文字化（"https://osaka.jdsf-seibu.com/" → "osaka.jdsf-seibu.com"）
function hostOf(u) {
  return String(u || '').toLowerCase().replace(/^https?:\/\//, '').split('/')[0].trim();
}

// ============================ 1) 主サイト: 府県→担当者名 ============================

function getContactData() {
  var data = sheet().getRange('G3:I17').getValues();
  var result = {};
  for (var i = 0; i < data.length; i++) {
    var pref  = String(data[i][0]).trim();
    var name  = String(data[i][2]).trim();
    if (pref) result[pref] = { name: name };
  }
  return json(result);
}

// 主サイト: 府県プルダウンで選んだ府県へ送信（G/H/I列）
function handleContact(data) {
  try {
    var rows = sheet().getRange('G3:I17').getValues();
    var pref = String(data.prefecture || '').trim();
    var to = '', person = '';
    for (var i = 0; i < rows.length; i++) {
      if (String(rows[i][0]).trim() === pref) {
        to     = String(rows[i][1]).trim();   // H: メール
        person = String(rows[i][2]).trim();   // I: 担当者
        break;
      }
    }
    if (!to) { return json({ status: 'error', message: '宛先が見つかりません: ' + pref }); }

    var subject = '【西部ブロックHP】' + (data.subject || 'お問い合わせ');
    var body = [
      'JDSF西部ブロック公式サイトよりお問い合わせが届きました。', '',
      '■ 対象府県 : ' + pref,
      '■ お名前   : ' + (data.name  || ''),
      '■ 所属     : ' + (data.org   || '未記入'),
      '■ メール   : ' + (data.email || ''),
      '■ 電話     : ' + (data.tel   || ''),
      '■ 件名     : ' + (data.subject || ''), '',
      '■ お問い合わせ内容:', (data.message || '')
    ].join('\n');

    var opt = { replyTo: data.email, name: (data.name || '') + '（西部ブロックHP経由）' };
    if (OFFICE_BCC && OFFICE_BCC !== to) opt.bcc = OFFICE_BCC;
    GmailApp.sendEmail(to, subject, body, opt);

    sendAutoReply(data, { prefName: pref, person: person, email: to });
    return json({ status: 'ok' });
  } catch (err) {
    Logger.log('handleContact error: ' + err);
    return json({ status: 'error', message: String(err) });
  }
}

// ============================ 2) 府県サブドメイン: Q〜V列 ============================

// 府県サブドメイン: その府県の担当者名だけ返す（メールは非公開）
function getContactInfo(url) {
  var row = findContactRowByUrl(url);
  if (!row) return json({ ok: false });
  return json({ ok: true, prefName: row.prefName, person: row.person, hasEmail: !!row.email });
}

// 府県サブドメイン: フォーム送信（V列URLで行特定 → R列へ送信、T/U列をCC）
function handlePrefContact(data) {
  try {
    var row = findContactRowByUrl(data.url);
    if (!row || !row.email) {
      return json({ status: 'error', message: 'この府県の送信先が未設定です（サイト構築で設定してください）。' });
    }
    var subject = '【' + (row.prefName || '府県連盟HP') + '】' + (data.subject || 'お問い合わせ');
    var body = [
      (row.prefName || '') + 'のホームページよりお問い合わせが届きました。', '',
      '■ お名前 : ' + (data.name  || ''),
      '■ 所属   : ' + (data.org   || '未記入'),
      '■ メール : ' + (data.email || ''),
      '■ 電話   : ' + (data.tel   || ''),
      '■ 件名   : ' + (data.subject || ''), '',
      '■ お問い合わせ内容:', (data.message || '')
    ].join('\n');

    var opt = { replyTo: data.email, name: (data.name || '') + '（' + (row.prefName || 'HP') + '経由）' };
    var cc = [row.cc1, row.cc2].filter(function (x) { return x; }).join(',');
    if (cc) opt.cc = cc;
    GmailApp.sendEmail(row.email, subject, body, opt);

    sendAutoReply(data, { prefName: row.prefName, person: row.person, email: row.email });
    return json({ status: 'ok' });
  } catch (err) {
    Logger.log('handlePrefContact error: ' + err);
    return json({ status: 'error', message: String(err) });
  }
}

// Q〜V列を読み、V列URLのホストが一致する行を返す
function findContactRowByUrl(url) {
  var host = hostOf(url);
  if (!host) return null;
  var sh = sheet();
  var last = sh.getLastRow();
  if (last < 3) return null;
  var rows = sh.getRange(3, 17, last - 2, 6).getValues(); // Q(17)〜V(22)
  for (var i = 0; i < rows.length; i++) {
    var v = hostOf(rows[i][5]); // V: サイトURL
    if (v && v === host) {
      return {
        rowIndex: i + 3,
        prefName: String(rows[i][0]).trim(), // Q
        email:    String(rows[i][1]).trim(), // R
        person:   String(rows[i][2]).trim(), // S
        cc1:      String(rows[i][3]).trim(), // T
        cc2:      String(rows[i][4]).trim(), // U
        url:      String(rows[i][5]).trim()  // V
      };
    }
  }
  return null;
}

// ============================ 3) site-config からの宛先編集 ============================

// 読込（現在値をフォームに表示するため）。token が必要。
function getContactConfig(url, token) {
  if (token !== CONTACT_TOKEN) return json({ ok: false, message: 'forbidden' });
  var row = findContactRowByUrl(url);
  if (!row) return json({ ok: false, message: 'この URL の行が見つかりません（V列にサイトURLが必要です）。' });
  return json({ ok: true, prefName: row.prefName, email: row.email, person: row.person, cc1: row.cc1, cc2: row.cc2 });
}

// 保存（R=メール, S=担当者, T=CC①, U=CC② を更新）。token が必要。
function saveContact(data) {
  try {
    if (data.token !== CONTACT_TOKEN) return json({ status: 'error', message: 'forbidden' });
    var row = findContactRowByUrl(data.url);
    if (!row) return json({ status: 'error', message: 'この URL の行が見つかりません（V列にサイトURLを入力してください）。' });
    var sh = sheet();
    sh.getRange(row.rowIndex, 18).setValue(String(data.email  || '').trim()); // R
    sh.getRange(row.rowIndex, 19).setValue(String(data.person || '').trim()); // S
    sh.getRange(row.rowIndex, 20).setValue(String(data.cc1    || '').trim()); // T
    sh.getRange(row.rowIndex, 21).setValue(String(data.cc2    || '').trim()); // U
    return json({ status: 'ok' });
  } catch (err) {
    Logger.log('saveContact error: ' + err);
    return json({ status: 'error', message: String(err) });
  }
}

// ============================ 4) 認証（M列/N列） ============================

function getAuthPassword(site) {
  site = String(site || '').toLowerCase().trim();
  var pw = null, build = null;
  if (site) {
    var sh = sheet();
    var last = sh.getLastRow();
    if (last >= 3) {
      var rows = sh.getRange(3, 10, last - 2, 5).getValues(); // J(10)〜N(14)
      for (var i = 0; i < rows.length; i++) {
        var sub = hostOf(rows[i][0]).split('.')[0]; // J列URLのサブドメイン
        if (sub && sub === site) {
          pw    = String(rows[i][3]).trim(); // M
          build = String(rows[i][4]).trim(); // N
          break;
        }
      }
    }
  }
  return json({ password: pw, build: build });
}

// ============================ 自動返信（送信者控え） ============================

// 府県名 → 署名用の連盟名（例: 大阪府 → 大阪府ダンススポーツ連盟）。
// 既に「連盟/事務局/委員会」を含む場合・空の場合はそのまま扱う。
function orgNameOf(prefName) {
  var p = String(prefName || '').trim();
  if (!p) return 'JDSF近畿中四国ブロック委員会';
  if (/連盟|事務局|委員会/.test(p)) return p;
  return p + 'ダンススポーツ連盟';
}

function sendAutoReply(data, sig) {
  if (!data.email) return;
  sig = sig || {};
  var org = orgNameOf(sig.prefName);
  var lines = [
    (data.name || '') + ' 様', '',
    'このたびはお問い合わせいただきありがとうございます。',
    '以下の内容で受け付けました。担当者より追ってご連絡いたします。', '',
    '■ 件名 : ' + (data.subject || ''),
    '■ 内容 : ' + (data.message || ''), '',
    '──────────────────────────',
    org
  ];
  if (sig.person) lines.push('担当：' + sig.person + '氏');
  if (sig.email)  lines.push('メール：' + sig.email);
  lines.push('──────────────────────────');
  lines.push('※本メールは自動送信です。ご返信の際は上記のメールアドレス宛にお願いいたします。');
  GmailApp.sendEmail(data.email, '【受付確認】' + (data.subject || 'お問い合わせ'), lines.join('\n'),
                     { name: org });
}
