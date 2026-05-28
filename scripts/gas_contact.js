/**
 * JDSF 近畿中四国ブロック委員会 お問い合わせルーティングスクリプト
 *
 * 【デプロイ手順】
 * 1. https://script.google.com/ を開き、新しいプロジェクトを作成
 * 2. このコードを貼り付け、★の行に実際のメールアドレスを入力
 * 3. 右上「デプロイ」→「新しいデプロイ」をクリック
 * 4. 種類「ウェブアプリ」、実行ユーザー「自分」、アクセス「全員」で保存
 * 5. 表示されたURLを contact.html の APPS_SCRIPT_URL に設定
 */

// ================================================================
//  ★ 各府県連盟の担当者メールアドレスをここに入力してください ★
// ================================================================
const PREFECTURE_CONTACTS = {
  '滋賀県':        { name: '滋賀県ダンススポーツ連盟',       email: 'y-mine.omi@zeus.eonet.ne.jp' },
  '京都府':        { name: '京都府ダンススポーツ連盟',       email: 'ichigo.ichie414@icloud.com' },
  '大阪府':        { name: '大阪府ダンススポーツ連盟',       email: 'tendo@leto.eonet.ne.jp' },
  '兵庫県':        { name: '兵庫県ダンススポーツ連盟',       email: 'fujimoto.s@theia.ocn.ne.jp' },
  '奈良県':        { name: '奈良県ダンススポーツ連盟',       email: 'raydance@iris.eonet.ne.jp' },
  '和歌山県':      { name: '和歌山県ダンススポーツ連盟',     email: 'tf583325@qc5.so-net.ne.jp' },
  '鳥取県':        { name: '鳥取県ダンススポーツ連盟',       email: 'hiroshi1dannsu@sea.chukai.ne.jp' },
  '島根県':        { name: '島根県ダンススポーツ連盟',       email: 'eda38@nifty.com' },
  '岡山県':        { name: '岡山県ダンススポーツ連盟',       email: 'kazu34123@yahoo.co.jp' },
  '広島県':        { name: '広島県ダンススポーツ連盟',       email: 'shoandshow@gmail.com' },
  '香川県':        { name: '香川県ダンススポーツ連盟',       email: 'stngygw6241@md.pikara.ne.jp' },
  '徳島県':        { name: '徳島県ダンススポーツ連盟',       email: 'kenkouro@siren.ocn.ne.jp' },
  '愛媛県':        { name: '愛媛県ダンススポーツ連盟',       email: 'matiko-takeda2@outlook.jp' },
  '高知県':        { name: '高知県ダンススポーツ連盟',       email: 'kouichi771sasaki@gmail.com' },
  'ブロック事務局': { name: 'JDSF近畿中四国ブロック委員会',  email: 'info@jdsf-seibu.com' }
};

// ブロック事務局アドレス（すべての問い合わせに BCC で通知）
const BCC_EMAIL = 'info@jdsf-seibu.com'; // ★ 必要に応じて変更

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
      `■ 種別      : ${data.category || ''}`,
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
      `${contact.name}の担当者よりご連絡いたします。`,
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

/** 動作確認用 */
function doGet() {
  return ContentService.createTextOutput('JDSF Seibu Contact API is running.');
}
