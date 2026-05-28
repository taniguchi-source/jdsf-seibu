#!/usr/bin/env python3
"""
JDSF 西部ブロック お知らせデータ取得スクリプト
Google スプレッドシートの「お知らせ」シートを読み取り news.json を生成。

列構成（1行目ヘッダー）:
  A: 日付（お知らせ対象の実施日）
  B: カテゴリ（競技会 / ランキング / お知らせ / その他）
  C: タイトル
  D: 詳細（本文・補足、任意）
  E: ＵＲＬ（任意）
  F: 公開日（記入があれば公開、空欄は非公開）

Usage:
  python scripts/fetch_news.py
"""

import csv
import io
import json
import os
import sys
import urllib.request
from datetime import datetime

SHEET_ID  = '1fpEa8jiIk9hUKyDOp4yWvBKaoPek-LsF2SPhDRTFX0g'
SHEET_GID = '1189954799'
CSV_URL   = (
    f'https://docs.google.com/spreadsheets/d/{SHEET_ID}'
    f'/export?format=csv&gid={SHEET_GID}'
)
OUTPUT_PATH = os.path.join(os.path.dirname(__file__), '..', 'data', 'news.json')


def parse_date_display(raw: str) -> str:
    """2026/06/01 や 2026-06-01 → 2026.06.01"""
    raw = raw.strip().replace('-', '/')
    parts = raw.split('/')
    if len(parts) == 3:
        y, m, d = parts
        return f'{y}.{m.zfill(2)}.{d.zfill(2)}'
    return raw


def parse_date_sort(raw: str) -> str:
    """ソート用 ISO 形式 2026-06-01"""
    raw = raw.strip().replace('/', '-')
    return raw if raw else '0000-00-00'


def make_id(date_raw: str, idx: int) -> str:
    """日付 + 連番 → ID 文字列"""
    digits = ''.join(c for c in date_raw if c.isdigit())[:8]
    return f'{digits}{idx+1:03d}'


def main():
    # --- CSV 取得 ---
    req = urllib.request.Request(
        CSV_URL,
        headers={'User-Agent': 'Mozilla/5.0 (compatible; JDSF-SeibuBot/1.0)'}
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            text = resp.read().decode('utf-8-sig')
    except Exception as exc:
        print(f'スプレッドシート取得エラー: {exc}', file=sys.stderr)
        sys.exit(1)

    reader = csv.reader(io.StringIO(text))
    rows   = list(reader)

    if not rows:
        print('データが空です', file=sys.stderr)
        sys.exit(1)

    # --- ヘッダー行をスキップ ---
    data_rows = rows[1:]

    news_items = []
    for idx, row in enumerate(data_rows):
        # 空行スキップ
        if not any(cell.strip() for cell in row):
            continue

        # 列取得（不足分は空文字で補完）
        row += [''] * (6 - len(row))
        date_raw, category, title, detail, url, pub_date = (
            row[0].strip(), row[1].strip(), row[2].strip(),
            row[3].strip(), row[4].strip(), row[5].strip()
        )

        # タイトルがなければスキップ
        if not title:
            continue

        # 公開日が空なら非公開
        if not pub_date:
            continue

        news_items.append({
            'id':           make_id(pub_date, idx),
            'date':         parse_date_display(pub_date),    # 公開日を表示日付に使用
            'event_date':   parse_date_display(date_raw),    # 実施日
            '_sort_key':    parse_date_sort(pub_date),
            'category':     category or 'お知らせ',
            'title':        title,
            'detail':       detail,
            'url':          url,
        })

    # 公開日の新しい順にソート
    news_items.sort(key=lambda n: n['_sort_key'], reverse=True)

    # ソートキーを除去
    for n in news_items:
        del n['_sort_key']

    output = {
        'updated': datetime.now().strftime('%Y-%m-%dT%H:%M:%S'),
        'source':  f'https://docs.google.com/spreadsheets/d/{SHEET_ID}/',
        'news':    news_items,
    }

    os.makedirs(os.path.dirname(os.path.abspath(OUTPUT_PATH)), exist_ok=True)
    with open(os.path.abspath(OUTPUT_PATH), 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    print(f'お知らせデータ保存完了: {len(news_items)} 件')


if __name__ == '__main__':
    main()
