#!/usr/bin/env python3
"""
JDSF 西部ブロック 競技会データ取得スクリプト
Fetches western block (block S / block_id=5) competition data from adm.jdsf.jp
Each competition's detail page is also fetched to extract the venue name.
Output: data/competitions_seibu.json

Usage:
  pip install requests beautifulsoup4
  python scripts/fetch_competitions.py
"""

import requests
from bs4 import BeautifulSoup
import json
import re
import os
import sys
import io
import time
from datetime import datetime

# Force UTF-8 output to avoid cp932 encoding errors on Windows
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

BASE_URL = 'https://adm.jdsf.jp/competition/index.php'
BLOCK_ID = 5  # S = 西部ブロック（近畿・中国・四国）
OUTPUT_PATH = os.path.join(os.path.dirname(__file__), '..', 'data', 'competitions_seibu.json')

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (compatible; JDSF-SeibuBot/1.0; +https://jdsf-seibu.com/)',
    'Accept-Language': 'ja,en;q=0.5',
}


def get_fiscal_year():
    """Japanese fiscal year: starts April 1. Jan–Mar belong to previous year's 年度."""
    now = datetime.now()
    return now.year if now.month >= 4 else now.year - 1


def parse_date_iso(date_raw, comp_no):
    """
    Convert Japanese date string (e.g. "4月5日") to ISO date "2026-04-05".
    The year is derived from the 6-digit competition number (e.g. 260410 → 2026).
    """
    m = re.search(r'(\d+)月(\d+)日', date_raw)
    if not m:
        return ''
    month, day = int(m.group(1)), int(m.group(2))
    # Competition number: first 2 digits = last 2 digits of year
    try:
        year = 2000 + int(comp_no[:2])
    except (ValueError, IndexError):
        year = get_fiscal_year()
    return f"{year}-{month:02d}-{day:02d}"


def fetch_venue(detail_url):
    """
    Fetch competition detail page and extract venue name (会場).
    Returns empty string if not found or URL is not a detail page.
    """
    if not detail_url or 'detail.php' not in detail_url:
        return ''
    try:
        resp = requests.get(detail_url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.content.decode('utf-8'), 'html.parser')
        for th in soup.find_all('th'):
            if th.get_text(strip=True) == '会場':
                td = th.find_next_sibling('td')
                if td:
                    return td.get_text(strip=True)
    except Exception as exc:
        print(f"    会場取得エラー ({detail_url}): {exc}")
    return ''


def fetch_year(year):
    """Fetch all block-S competitions for the given fiscal year."""
    params = {'year': year, 'block_id': BLOCK_ID}

    resp = requests.get(BASE_URL, params=params, headers=HEADERS, timeout=30)
    resp.raise_for_status()
    resp.encoding = 'utf-8'

    soup = BeautifulSoup(resp.text, 'html.parser')
    competitions = []

    for row in soup.find_all('tr'):
        cells = row.find_all('td')
        if len(cells) < 6:
            continue

        cell_texts = [c.get_text(separator=' ', strip=True) for c in cells]

        # Competition number must be exactly 6 digits
        comp_no = cell_texts[1].strip()
        if not re.match(r'^\d{6}$', comp_no):
            continue

        date_raw = re.sub(r'[〇△◯\s]+', ' ', cell_texts[0]).strip()
        date_raw = re.sub(r'\s+', '', date_raw)  # e.g. "4月5日"
        online_entry = '〇' in cells[0].get_text() or '△' in cells[0].get_text()

        name = cell_texts[5].strip() if len(cell_texts) > 5 else ''
        # Clean up extra whitespace in name
        name = re.sub(r'\s+', ' ', name).strip()

        syllabus_text = cell_texts[3] if len(cell_texts) > 3 else ''
        result_text   = cell_texts[4] if len(cell_texts) > 4 else ''

        # Extract links from row
        detail_url, syllabus_url, result_url = '', '', ''
        for a in row.find_all('a'):
            href = a.get('href', '')
            if not href:
                continue
            if not href.startswith('http'):
                href = 'https://adm.jdsf.jp' + href
            if 'detail.php' in href:
                detail_url = href
            elif '/syllabus/' in href:
                syllabus_url = href
            elif 'kyougi.jdsf' in href:
                result_url = href

        date_iso = parse_date_iso(date_raw, comp_no)

        competitions.append({
            'date':             date_raw,
            'date_iso':         date_iso,
            'comp_no':          comp_no,
            'name':             name,
            'venue':            '',   # filled in next step
            'online_entry':     online_entry,
            'has_syllabus':     '○' in syllabus_text,
            'has_result':       '◎' in result_text,
            'has_participants': '参' in result_text,
            'detail_url':       detail_url,
            'syllabus_url':     syllabus_url,
            'result_url':       result_url,
        })

    return competitions


def main():
    os.makedirs(os.path.dirname(os.path.abspath(OUTPUT_PATH)), exist_ok=True)

    current_year = get_fiscal_year()
    all_comps = []

    for year in [current_year, current_year + 1]:
        try:
            comps = fetch_year(year)
            all_comps.extend(comps)
            print(f"  {year}年度: {len(comps)} 件")
        except Exception as exc:
            print(f"  {year}年度 取得エラー: {exc}")

    # Deduplicate by comp_no, keep first occurrence
    seen, unique = set(), []
    for c in all_comps:
        if c['comp_no'] not in seen:
            seen.add(c['comp_no'])
            unique.append(c)

    # Sort chronologically
    unique.sort(key=lambda c: c['date_iso'] or '9999-99-99')

    # Fetch venue from each competition's detail page
    print(f"\n会場名を取得中...")
    for i, c in enumerate(unique):
        if 'detail.php' in c.get('detail_url', ''):
            venue = fetch_venue(c['detail_url'])
            c['venue'] = venue
            print(f"  [{i+1}/{len(unique)}] {c['date']} {c['name'][:30]}... → {venue or '(取得できず)'}")
            time.sleep(0.5)  # polite delay
        else:
            print(f"  [{i+1}/{len(unique)}] {c['date']} {c['name'][:30]}... → (詳細URLなし)")

    output = {
        'updated':      datetime.now().strftime('%Y-%m-%dT%H:%M:%S'),
        'source':       f"{BASE_URL}?year={current_year}&block_id={BLOCK_ID}",
        'block':        'S（西部ブロック／近畿・中国・四国）',
        'competitions': unique,
    }

    abs_path = os.path.abspath(OUTPUT_PATH)
    with open(abs_path, 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    print(f"\n保存完了: {abs_path}  ({len(unique)} 件)")


if __name__ == '__main__':
    print("JDSF 西部ブロック競技会データ取得中...")
    main()
