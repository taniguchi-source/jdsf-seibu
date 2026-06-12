/**
 * JDSF 近畿中四国ブロック委員会 お問い合わせルーティングスクリプト
 *
 * 【特徴】スプレッドシートに依存しない自己完結版。
 *   - 宛先（府県→連盟名・担当者・メール）はこのコード内 PREFECTURE_CONTACTS で管理。
 *   - doPost  : 選択府県の担当者へメール送信＋事務局へBCC＋送信者へ自動返信。
 *   - doGet   : ?action=data で府県→担当者名を返す（contact.html の「担当：◯◯氏」表示用。
 *               ※メールアドレスは返さない＝公開しない）。
 *
 * 【更新・再デプロイ手順（URLを変えない）】
 * 1. https://script.google.com/ で対象プロジェクトを開く
 * 2. このコードを全文貼り替えて保存
 * 3. 右上「デプロイ」→「デプロイを管理」→ 既存デプロイの鉛筆(編集)
 * 4. バージョンを「新しいバージョン」にして「デプロイ」
 *    （「新しいデプロイ」を作るとURLが変わるので必ず既存を編集すること）
 */

// ================================================================
//  ★ 各府県連盟の宛先（連盟名 / 担当者 / メール）をここで管理 ★
//     担当者・メールを変えたい時はこの表だけ直して再デプロイ。
// ================================================================
const PREFECTURE_CONTACTS = {
  '滋賀県':        { federation: '滋賀県ダンススポーツ連盟',   person: '伊藤康雅',       email: 'y-mine.omi@zeus.eonet.ne.jp' },
  '京都府':        { federation: '京都府ダンススポーツ連盟',   person: '松本武士',       email: 'ichigo.ichie414@icloud.com' },
  '大阪府':        { federation: '大阪府ダンススポーツ連盟',   person: '天道貞一',       email: 'tendo@leto.eonet.ne.jp' },
  '兵庫県':        { federation: '兵庫県ダンススポーツ連盟',   person: '藤本悟',         email: 'fujimoto.s@theia.ocn.ne.jp' },
  '奈良県':        { federation: '奈良県ダンススポーツ連盟',   person: '阪田麗二',       email: 'raydance@iris.eonet.ne.jp' },
  '和歌山県':      { federation: '和歌山県ダンススポーツ連盟', person: '野田尚児',       email: 'tf583325@qc5.so-net.ne.jp' },
  '鳥取県':        { federation: '鳥取県ダンススポーツ連盟',   person: '前田博',         email: 'hiroshi1dannsu@sea.chukai.ne.jp' },
  '島根県':        { federation: '島根県ダンススポーツ連盟',   person: '江田哲也',       email: 'eda38@nifty.com' },
  '岡山県':        { federation: '岡山県ダンススポーツ連盟',   person: '守谷和正',       email: 'kazu34123@yahoo.co.jp' },
  '広島県':        { federation: '広島県ダンススポーツ連盟',   person: '西原和也',       email: 'shoandshow@gmail.com' },
  '香川県':        { federation: '香川県ダンススポーツ連盟',   person: '戸高誠子',       email: 'stngygw6241@md.pikara.ne.jp' },
  '徳島県':        { federation: '徳島県ダンススポーツ連盟',   person: '山本正美',       email: 'kenkouro@siren.ocn.ne.jp' },
  '愛媛県':        { federation: '愛媛県ダンススポーツ連盟',   person: '武田真知子',     email: 'matiko-takeda2@outlook.jp' },
  '高知県':        { federation: '高知県ダンススポーツ連盟',   person: '佐々木浩一',     email: 'kouichi771sasaki@gmail.com' },
  'ブロック事務局': { federation: 'JDSF近畿中四国ブロック委員会', person: '戸高誠子・鈴江潔', email: 'jdsf.seibu@gmail.com' }
};

// ブロック事務局アドレス（すべての問い合わせに BCC で通知）
const BCC_EMAIL = 'jdsf.seibu@gmail.com';

// ================================================================

function doPost(e) {
  try {
    const data = JSON.parse(e.postData ? e.postData.contents : '{}');

    const pref    = data.prefecture || 'ブロック事務局';
    const contact = PREFECTURE_CONTACTS[pref] || PREFECTURE_CONTACTS['ブロック事務局'];

    const subject = `【西部ブロックHP】${data.subject || 'お問い合わせ'}`;

    const body = [
      'JDSF西部ブロック公式サイトよりお問い合わせが届きました。',
      '',
      `■ 対象府県  : ${pref}`,
      `■ お名前    : ${data.name  || ''}`,
      `■ 所属      : ${data.org   || '未記入'}`,
      `■ メール    : ${data.email || ''}`,
      `■ 電話番号  : ${data.tel   || '未記入'}`,
      `■ 件名      : ${data.subject  || ''}`,
      '',
      '■ お問い合わせ内容:',
      data.message || '',
    ].join('\n');

    const options = {
      replyTo: data.email,
      name: `${data.name || ''}（西部ブロックHP経由）`,
    };
    if (BCC_EMAIL && BCC_EMAIL !== contact.email) {
      options.bcc = BCC_EMAIL;
    }

    // 担当者へ送信
    GmailApp.sendEmail(contact.email, subject, body, options);

    // 送信者への自動返信
    const autoBody = [
      `${data.name || ''} 様`,
      '',
      'このたびはお問い合わせいただきありがとうございます。',
      '以下の内容で受け付けました。',
      `${contact.federation}の担当者よりご連絡いたします。`,
      '',
      `■ 件名 : ${data.subject  || ''}`,
      `■ 内容 : ${data.message  || ''}`,
      '',
      '─────────────────────────────────────',
      'JDSF近畿中四国ブロック委員会（西部ブロック）',
      'https://jdsf-seibu.com/',
      '─────────────────────────────────────',
    ].join('\n');

    GmailApp.sendEmail(
      data.email,
      `【受付確認】${data.subject || 'お問い合わせ'}`,
      autoBody,
      { name: 'JDSF近畿中四国ブロック委員会' }
    );

    return ContentService
      .createTextOutput(JSON.stringify({ status: 'ok' }))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (err) {
    Logger.log('Contact error: ' + err.toString());
    return ContentService
      .createTextOutput(JSON.stringify({ status: 'error', message: err.message }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

/**
 * GET ?action=data : 府県→担当者名（氏名のみ）を返す。
 *   contact.html が「担当：◯◯氏」表示に使用。メールアドレスは返さない。
 * それ以外の GET : 動作確認用テキスト。
 */
function doGet(e) {
  const action = e && e.parameter ? e.parameter.action : '';
  if (action === 'data') {
    const out = {};
    Object.keys(PREFECTURE_CONTACTS).forEach(function (pref) {
      out[pref] = { name: PREFECTURE_CONTACTS[pref].person };
    });
    return ContentService
      .createTextOutput(JSON.stringify(out))
      .setMimeType(ContentService.MimeType.JSON);
  }
  return ContentService.createTextOutput('JDSF Seibu Contact API is running.');
}
