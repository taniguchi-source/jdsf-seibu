#!/usr/bin/env python3
"""
マニュアル動画ガイド（preftest/manual-video.html）のナレーション音声を
Google Cloud Text-to-Speech で生成するスクリプト。

出力: preftest/audio/manual/scene-00.mp3 〜 scene-22.mp3

【APIキー】次のどちらかで渡す（チャットやGitには残さない）:
  1) 環境変数  GOOGLE_TTS_KEY
  2) ファイル  scripts/.tts_key  に1行で記入（.gitignore 済み）

【使い方】
  pip 不要（標準ライブラリのみ）。
  python scripts/gen_manual_audio.py
  # 男性の声にしたいときは:  VOICE=ja-JP-Neural2-C python scripts/gen_manual_audio.py

【注意】NARRATIONS は manual-video.html の各場面の字幕（S[].c）と
        文言を一致させること（音声と字幕がずれないように）。
"""

import os
import sys
import io
import json
import base64
import urllib.request
import urllib.error

# Windows でも UTF-8 出力
try:
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
except Exception:
    pass

VOICE = os.environ.get("VOICE", "ja-JP-Neural2-B")   # 女性。男性は ja-JP-Neural2-C / -D
RATE  = float(os.environ.get("RATE", "1.0"))         # 読み上げ速度（0.25〜4.0）。ゆっくりは 0.95 など
PITCH = float(os.environ.get("PITCH", "0.0"))        # 音の高さ（-20.0〜20.0）

HERE = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(HERE, "..", "preftest", "audio", "manual")

# manual-video.html の S[].c と同じ文言（順番も一致させる）
NARRATIONS = [
    "各府県連盟のホームページは、担当者ご自身がブラウザから内容を更新できます。このガイドでは、お知らせの投稿から見た目の設定まで、画面の図とあわせて順番にご案内します。",
    "編集する画面は2つあります。日々の更新を行う「サイト管理」と、見た目を整える「サイト構築」です。それぞれ別のパスワードでログインします。",
    "担当する府県のURLを開き、パスワード欄に事務局から伝えられたパスワードを入力して、ログインボタンを押します。一度ログインすると約3日間は保持されます。",
    "パスワードは、サイト構築ページでまとめて変更できます。サイト構築ページを開き、右上の、鍵マークのパスワード変更ボタンを押します。変更するパスワードで、サイト管理用か、サイト構築ページ用を選び、その現在のパスワードと、新しいパスワードを8文字以上で入力して、変更するを押します。変更しても他の府県には影響しません。パスワードを忘れたときは、ブロック事務局にご連絡ください。",
    "「公開お知らせ管理」で、プラス お知らせを投稿を押します。カテゴリ、タイトル、詳細内容を入力し、投稿するを押すと、トップページに表示されます。",
    "投稿したお知らせは、一覧の各行のボタンで操作します。編集で直し、表示中を押すと一時的に隠せます。削除は完全に消えるので、確認のうえ実行してください。",
    "お知らせには、便利な設定があります。編集画面で、常に表示するにチェックすると、そのお知らせをトップページの先頭に固定できます。また、公開お知らせ管理のトップ表示件数で、トップに並ぶお知らせの数を、3件か6件から選べます。",
    "お知らせには、実施日、つまり開催日のほかに、投稿・編集日を表示できます。新しく投稿したものは投稿日、あとから編集して保存したものは編集日と、自動で切り替わります。実施日と投稿・編集日は、それぞれチェックボックスで表示するかどうかを選べます。トップページでは、開催の隣に、投稿、または編集の日付が並びます。",
    "お知らせは投稿順だけでなく、好きな順番に並べ替えられます。各行の左にある、点のマークをつかんで上下にドラッグするか、上へ、下へのボタンで動かします。並べ替えは自動で保存され、トップページとお知らせ一覧にそのまま反映されます。",
    "ナビゲーション管理で、上部メニューに並ぶページの名前や表示・非表示を決めます。ページ名を分かりやすく変え、保存を押します。中身を編集で、そのページの内容を作ります。",
    "ナビゲーションのページも、お知らせと同じように並べ替えられます。各ページカードの左上にある点のマークをつかんで上下にドラッグするか、上へ、下へのボタンで動かします。並べ替えても各ページの中身はそのまま移動し、順番だけが変わります。並びは自動で保存され、上部メニューとフッターに反映されます。",
    "中身を編集の、プラス追加で部品を作ります。部品は6種類。テキスト、スプレッドシートの表、画像、ギャラリー、PDF、リンクから選びます。左の点のマークをつかむと並べ替えができます。",
    "スプレッドシートは、指定した範囲を、色やセルの結合、太字などの書式そのままで、ページに埋め込んで表示できます。表示したいタブを開いた状態で、ブラウザのアドレス欄のURLをコピーして貼り付け、表示する範囲を、開始セルから終了セルの形で入力します。プレビューを押すと、公開ページと同じ見た目を確認できます。",
    "表の下にある、別タブで開くを押すと、新しいタブでスプレッドシートが開き、そこから印刷や、PDF保存、編集ができます。編集できるのは、編集権限を持つ方だけです。表の下の余白が大きいときは、表示する範囲を、実際のデータの最終行までに縮めると、ぴったり収まります。",
    "PDFは、要項や申込書などの配布資料に使います。アップロードすると、ページの中に画像として全ページが表示され、枠の中だけをスクロールする必要はありません。パソコンで大きすぎるときは、PCで表示する幅をパーセントで指定できます。スマートフォンでは常に画面いっぱいに表示され、縮小したときは中央にそろえて表示されます。",
    "各ページの上部、ヒーローの表示文字も自由に変えられます。ページ設定のヒーローの欄で、小ラベル、大見出し、サブ見出しの3つを個別に入力し、ページ設定を保存を押します。空欄なら自動で表示されます。",
    "ここからは見た目の設定です。サイト管理ページ右上の、サイト構築ページへを押し、サイト構築用のパスワードでログインします。サイト管理とは別のパスワードです。",
    "サイト名管理で、連盟名を入力し、サイト名を保存を押します。全ページのヘッダーやフッターに反映されます。上段の公益社団法人の表記は固定で変わりません。",
    "カルーセル画像管理で、トップ最上部のメイン画像を設定します。横長の写真を選び、必要ならキャッチコピーを入れて保存します。複数枚登録すると自動で切り替わります。",
    "クイックリンクは、トップページ右側に並ぶボタンです。表示名とリンク先を設定し、必要なら文字の代わりに画像をボタンとして登録できます。画像は大きさが自動で調整され、左そろえで並びます。使わない行は今まで通り文字ボタンになります。",
    "広告バナー管理では、トップページのフッター直前に、画像のバナーを最大6枚並べられます。画像をアップロードし、リンク先を入れて保存します。パソコンは横並び、スマートフォンは縦積みで表示されます。また、公式サイトバッジを表示するのチェックで、カルーセル左上の公式サイトバッジの、表示、非表示を切り替えられます。",
    "配色テーマ変更で、サイト全体の色合いを10種類から選べます。選ぶと即座に保存され、反映されます。迷ったら標準の紺、JDSFカラーのままで問題ありません。",
    "お問い合わせ先設定で、フォームから届いたメールの届け先を決めます。送信先メールアドレスを入れるとフォームが使えるようになります。担当者名やCC、自動返信の文面も設定でき、最後に保存します。メールアドレスは公開されません。",
    "お疲れさまでした。基本の流れは、ログイン、お知らせや中身を更新、見た目を整える、の3つです。困ったときは文章マニュアルのよくある質問もご覧ください。ブロック事務局がサポートします。",
]


def get_key():
    k = os.environ.get("GOOGLE_TTS_KEY", "").strip()
    if k:
        return k
    p = os.path.join(HERE, ".tts_key")
    if os.path.exists(p):
        with open(p, encoding="utf-8") as f:
            k = f.read().strip()
        if k:
            return k
    sys.exit(
        "APIキーが見つかりません。\n"
        "  環境変数 GOOGLE_TTS_KEY を設定するか、scripts/.tts_key にキーを1行で記入してください。"
    )


def synth(text, key):
    url = "https://texttospeech.googleapis.com/v1/text:synthesize?key=" + key
    body = json.dumps({
        "input": {"text": text},
        "voice": {"languageCode": "ja-JP", "name": VOICE},
        "audioConfig": {
            "audioEncoding": "MP3",
            "speakingRate": RATE,
            "pitch": PITCH,
        },
    }).encode("utf-8")
    req = urllib.request.Request(
        url, data=body,
        headers={"Content-Type": "application/json; charset=utf-8"},
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        data = json.loads(r.read().decode("utf-8"))
    return base64.b64decode(data["audioContent"])


def main():
    key = get_key()
    os.makedirs(OUT_DIR, exist_ok=True)
    print(f"音声を生成します（声={VOICE} 速度={RATE} 高さ={PITCH}）")
    total = 0
    for i, text in enumerate(NARRATIONS):
        try:
            audio = synth(text, key)
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", "replace")[:400]
            sys.exit(f"[scene-{i:02d}] APIエラー {e.code}: {detail}")
        except urllib.error.URLError as e:
            sys.exit(f"[scene-{i:02d}] 通信エラー: {e}")
        path = os.path.join(OUT_DIR, f"scene-{i:02d}.mp3")
        with open(path, "wb") as f:
            f.write(audio)
        total += len(audio)
        print(f"  scene-{i:02d}.mp3  {len(audio):>7,} bytes  | {text[:20]}…")
    print(f"\n完了: {len(NARRATIONS)} ファイル / 合計 {total:,} bytes")
    print(f"保存先: {os.path.abspath(OUT_DIR)}")


if __name__ == "__main__":
    main()
