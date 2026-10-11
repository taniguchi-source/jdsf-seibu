# Google Search Console 所有権確認ファイル（府県別）

各府県（サブドメイン）の Google Search Console 所有権確認用 HTML ファイルを置く場所。

## 使い方
1. `seo/google-verification/<サブドメイン>/google◯◯◯◯.html` にファイルを置く
   （例: `seo/google-verification/okayama/google7f2fe7a07ae15d56.html`）。
   中身は Google が指定したそのまま（1行 `google-site-verification: <ファイル名>`）。
2. master に push。
3. GitHub → Actions →「Deploy Google verification files」を実行
   （`only` にサブドメインを入れると対象を絞れる。例: `okayama`）。
4. `https://<サブドメイン>.jdsf-seibu.com/google◯◯◯◯.html` が表示されれば完了。

## 仕組み・注意
- ワークフローは各フォルダの `google*.html` を、同名で
  `/home/jdsfseibu/jdsf-seibu.com/public_html/<サブドメイン>.jdsf-seibu.com/` へ scp する。
- 府県デプロイ（`deploy-prefectures.yml`）は `--exclude='google*.html'` なので、
  rsync --delete でこの確認ファイルは消えない（＝一度置けば残る）。
- 本番デプロイ（`deploy-prod.yml`）は `--exclude='seo/'` なので、この管理用フォルダは
  主サイト（jdsf-seibu.com）には配信されない。
- Search Console 登録後も削除しないこと。
