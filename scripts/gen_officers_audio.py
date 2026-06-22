#!/usr/bin/env python3
"""
役員ページ動画ガイド（officers-manual-video.html）のナレーション音声を
Google Cloud Text-to-Speech で生成するスクリプト。

出力: audio/officers/scene-00.mp3 〜 scene-09.mp3

【APIキー】環境変数 GOOGLE_TTS_KEY か scripts/.tts_key（.gitignore済み）から読む。
【使い方】 python scripts/gen_officers_audio.py
          # 男性声: VOICE=ja-JP-Neural2-C python scripts/gen_officers_audio.py
【注意】NARRATIONS は officers-manual-video.html の各場面字幕(S[].c)と一致させること。
"""

import os
import sys
import io
import json
import base64
import urllib.request
import urllib.error

try:
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
except Exception:
    pass

VOICE = os.environ.get("VOICE", "ja-JP-Neural2-B")   # 女性。男性は ja-JP-Neural2-C / -D
RATE  = float(os.environ.get("RATE", "1.0"))
PITCH = float(os.environ.get("PITCH", "0.0"))

HERE = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(HERE, "..", "audio", "officers")

NARRATIONS = [
    "役員専用ページの操作ガイドです。図解と音声で、ログインから各機能の使い方までご案内します。",
    "役員ページを開き、事務局から配布されたパスワードを入力してログインします。ログイン状態はしばらく保持されます。共用パソコンでは、作業後にログアウトしてください。",
    "役員内部連絡は、役員どうしの連絡用です。一般には公開されません。プラス連絡事項を投稿を押し、氏名、タイトル、詳細を入力して投稿します。最新6件が表示され、全件は連絡事項一覧で見られます。",
    "公開お知らせ管理で投稿すると、トップページとお知らせページに掲載されます。カテゴリ、タイトル、詳細を入力して投稿します。一覧から編集や削除もできます。",
    "会議資料は、一覧から開いて閲覧します。活動報告は、活動報告フォームを開くボタンから、グーグルフォームでご報告ください。",
    "スプレッドシートと議事録は、それぞれのカードをクリックすると開きます。名簿や集計、過去の議事録を閲覧できます。",
    "その他参考資料では、規程集や様式などを登録できます。プラス資料を登録を押し、日付、登録者、タイトルを入れ、URLかファイルを選びます。登録には、役員ログインと同じパスワードが必要です。",
    "トップページの見た目を変えるときは、ページ右上の、サイト構築ページへを押します。サイト構築用の、別のパスワードでログインしてください。",
    "サイト構築ページでは、各府県のスプレッドシートをAIで見やすく整形するための、共通キーを一度だけ登録できます。AI整形キーの欄に、ジェミニのAPIキーを貼り付けて、キーを保存を押すと、全ての府県で共通して使えるようになります。各府県での個別設定は不要です。",
    "ページの一番下には、意見交換用のLINEグループの案内があります。右上のアクセス解析でサイトの閲覧状況を確認でき、作業が終わったらログアウトを押します。",
    "お疲れさまでした。基本は、ログイン、連絡やお知らせの投稿、資料の確認です。困ったときは、文章マニュアルのよくある質問もご覧ください。",
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
    sys.exit("APIキーがありません。環境変数 GOOGLE_TTS_KEY か scripts/.tts_key を設定してください。")


def synth(text, key):
    url = "https://texttospeech.googleapis.com/v1/text:synthesize?key=" + key
    body = json.dumps({
        "input": {"text": text},
        "voice": {"languageCode": "ja-JP", "name": VOICE},
        "audioConfig": {"audioEncoding": "MP3", "speakingRate": RATE, "pitch": PITCH},
    }).encode("utf-8")
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json; charset=utf-8"})
    with urllib.request.urlopen(req, timeout=60) as r:
        data = json.loads(r.read().decode("utf-8"))
    return base64.b64decode(data["audioContent"])


def main():
    key = get_key()
    os.makedirs(OUT_DIR, exist_ok=True)
    print(f"役員ページ動画ガイドの音声を生成します（声={VOICE} 速度={RATE}）")
    total = 0
    for i, text in enumerate(NARRATIONS):
        try:
            audio = synth(text, key)
        except urllib.error.HTTPError as e:
            sys.exit(f"[scene-{i:02d}] APIエラー {e.code}: {e.read().decode('utf-8','replace')[:400]}")
        except urllib.error.URLError as e:
            sys.exit(f"[scene-{i:02d}] 通信エラー: {e}")
        path = os.path.join(OUT_DIR, f"scene-{i:02d}.mp3")
        with open(path, "wb") as f:
            f.write(audio)
        total += len(audio)
        print(f"  scene-{i:02d}.mp3  {len(audio):>7,} bytes  | {text[:20]}…")
    print(f"\n完了: {len(NARRATIONS)} ファイル / 合計 {total:,} bytes\n保存先: {os.path.abspath(OUT_DIR)}")


if __name__ == "__main__":
    main()
