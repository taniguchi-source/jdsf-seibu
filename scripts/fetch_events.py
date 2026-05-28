#!/usr/bin/env python3
"""
JDSF 西部ブロック 研修イベントデータ取得スクリプト
Fetches western block (block S / b_id=5) training event data from adm.jdsf.jp
Output: data/events_seibu.json

Usage:
  pip install requests beautifulsoup4
  python scripts/fetch_events.py
"""

import requests
from bs4 import BeautifulSoup
import json
import re
import os
from datetime import datetime

BASE_URL  = 'https://adm.jdsf.jp/events/'
BLOCK_ID  = 5   # S = 西部ブロック
OUTPUT_PATH = os.path.join(os.path.dirname(__file__), '..', 'data', 'events_seibu.json')


def get_fiscal_year():
    now = datetime.now()
    return now.year if now.month >= 4 else now.year - 1


def parse_date_iso(date_raw, code):
    """4月18日 + code=260402 → 2026-04-18"""
    m = re.search(r'(\d+)月(\d+)日', date_raw)
    if not m:
        return ''
    month, day = int(m.group(1)), int(m.group(2))
    try:
        year = 2000 + int(code[:2])
    except (ValueError, IndexError):
        year = get_fiscal_year()
    return f"{year}-{month:02d}-{day:02d}"


def fetch_events():
    """Fetch all block-S training events (all years on one page)."""
    params = {'b_id': BLOCK_ID}
    headers = {
        'User-Agent': 'Mozilla/5.0 (compatible; JDSF-SeibuBot/1.0; +https://jdsf-seibu.com/)',
        'Accept-Language': 'ja,en;q=0.5',
    }

    resp = requests.get(BASE_URL, params=params, headers=headers, timeout=30)
    resp.raise_for_status()
    resp.encoding = 'utf-8'

    soup = BeautifulSoup(resp.text, 'html.parser')
    events = []

    for row in soup.find_all('tr'):
        cells = row.find_all('td')
        if len(cells) < 9:
            continue

        cell_texts = [c.get_text(separator=' ', strip=True) for c in cells]

        # 公認コードが6桁数字のみ対象
        code = cell_texts[1].strip()
        if not re.match(r'^\d{6}$', code):
            continue

        date_raw  = re.sub(r'\s+', '', cell_texts[0])
        name      = re.sub(r'\s+', ' ', cell_texts[3]).strip()
        ev_type   = cell_texts[4].strip()

        # 対象資格（〇 があれば True）
        target_instructor  = '〇' in cell_texts[6]
        target_competition = '〇' in cell_texts[7]
        target_judge       = '〇' in cell_texts[8]

        deadline = cell_texts[9].strip()  if len(cell_texts) > 9  else ''
        notes    = cell_texts[10].strip() if len(cell_texts) > 10 else ''
        notes    = re.sub(r'\s+', ' ', notes)

        # 詳細リンク
        detail_url = ''
        a = row.find('a')
        if a:
            href = a.get('href', '')
            if not href.startswith('http'):
                href = 'https://adm.jdsf.jp' + href
            detail_url = href

        date_iso = parse_date_iso(date_raw, code)

        events.append({
            'date':               date_raw,
            'date_iso':           date_iso,
            'code':               code,
            'name':               name,
            'type':               ev_type,
            'target_instructor':  target_instructor,
            'target_competition': target_competition,
            'target_judge':       target_judge,
            'deadline':           deadline,
            'notes':              notes,
            'detail_url':         detail_url,
        })

    return events


def main():
    os.makedirs(os.path.dirname(os.path.abspath(OUTPUT_PATH)), exist_ok=True)

    print("JDSF 西部ブロック研修イベントデータ取得中...")
    try:
        events = fetch_events()
        print(f"  {len(events)} 件取得")
    except Exception as exc:
        print(f"  取得エラー: {exc}")
        raise

    # 日付でソート
    events.sort(key=lambda e: e['date_iso'] or '9999-99-99')

    output = {
        'updated': datetime.now().strftime('%Y-%m-%dT%H:%M:%S'),
        'source':  f"{BASE_URL}?b_id={BLOCK_ID}",
        'block':   'S（西部ブロック／近畿・中国・四国）',
        'events':  events,
    }

    abs_path = os.path.abspath(OUTPUT_PATH)
    with open(abs_path, 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    print(f"保存完了: {abs_path}  ({len(events)} 件)")


if __name__ == '__main__':
    main()
