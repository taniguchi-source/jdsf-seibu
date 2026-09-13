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
from datetime import datetime, timedelta

# Force UTF-8 output to avoid cp932 encoding errors on Windows
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

BASE_URL = 'https://adm.jdsf.jp/competition/index.php'
BLOCK_ID = 5  # S = 西部ブロック（近畿・中国・四国）
OUTPUT_PATH = os.path.join(os.path.dirname(__file__), '..', 'data', 'competitions_seibu.json')
PUBLISHED_JSON_URL = 'https://jdsf-seibu.com/data/competitions_seibu.json'   # 前回取得分の引き継ぎ元

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


def fetch_detail_info(detail_url):
    """
    Fetch competition detail page and extract:
      - venue name       (会場)
      - entry deadline   (主催者締切日)
      - entry_url        (エントリー受付中の場合は detail_url を返す)
    Returns dict with 'venue', 'entry_deadline', and 'entry_url'.
    """
    result = {'venue': '', 'entry_deadline': '', 'entry_url': ''}
    if not detail_url or 'detail.php' not in detail_url:
        return result
    try:
        resp = requests.get(detail_url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.content.decode('utf-8'), 'html.parser')

        # 会場・主催者締切日
        for th in soup.find_all('th'):
            label = th.get_text(strip=True)
            td = th.find_next_sibling('td')
            if not td:
                continue
            if label == '会場':
                result['venue'] = td.get_text(strip=True)
            elif label == '主催者締切日':
                result['entry_deadline'] = td.get_text(strip=True)

        # エントリー受付状態を確認（sc_entry_status_text）
        status_el = soup.find(class_='sc_entry_status_text')
        if status_el and 'エントリー受付中' in status_el.get_text():
            result['entry_url'] = detail_url

    except Exception as exc:
        print(f"    詳細情報取得エラー ({detail_url}): {exc}")
    return result


def fetch_syllabus_events(syllabus_url):
    """
    シラバス（大会要項）の「競技内容」表から種目を取り出す。

    表の「区分」の並び順は DCS の「競技内容設定」の並び順そのもので、
    DCSが書き出す選手名簿CSVの競技参加区分の列順と一致する
    （2026-09-23 京都府選手権の実CSVで、参加者の背番号集合まで突き合わせて確認済み）。
    受付システムのCSV取込で列に種目コードを割り当てるのに使う。

    「種目」の列（W,T,(最終:V),F,Q のような踊る種目）も一緒に取る。
    受付システムの出場選手一覧に、区分ごとの種目として出すため。
    ※ダンススポーツでは「区分」＝A級スタンダードなど、「種目」＝ワルツ・タンゴなど。

    スマホ用の表（sp-only）は略称の列が無いので、略称を持つ表だけを見る。
    Returns: [{'no': 1, 'code': 'JAS', 'name': 'JDSF A級スタンダード',
               'dances': 'W,T,(最終:V),F,Q'}, ...]
    """
    events = []
    if not syllabus_url or '/syllabus/' not in syllabus_url:
        return events
    try:
        resp = requests.get(syllabus_url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.content.decode('utf-8', 'replace'), 'html.parser')

        for table in soup.find_all('table'):
            heads = [th.get_text(strip=True) for th in table.find_all('th')]
            if '区分' not in heads or '略称' not in heads:
                continue
            i_no   = heads.index('区分')
            i_code = heads.index('略称')
            i_name  = heads.index('競技名') if '競技名' in heads else -1
            i_dance = heads.index('種目')   if '種目'   in heads else -1

            seen = set()
            for tr in table.find_all('tr'):
                tds = tr.find_all('td')
                if len(tds) <= max(i_no, i_code, i_name, i_dance):
                    continue
                code = tds[i_code].get_text(strip=True).upper()
                if not re.match(r'^[A-Z0-9]{2,6}$', code) or code in seen:
                    continue
                seen.add(code)
                name = tds[i_name].get_text(' ', strip=True) if i_name >= 0 else ''
                name = re.sub(r'\s+', ' ', name).strip()
                dances = tds[i_dance].get_text(' ', strip=True) if i_dance >= 0 else ''
                dances = re.sub(r'\s+', ' ', dances).strip()
                try:
                    no = int(tds[i_no].get_text(strip=True))
                except ValueError:
                    no = len(events) + 1
                events.append({'no': no, 'code': code, 'name': name, 'dances': dances})
            if events:
                break

    except Exception as exc:
        print(f"    シラバス取得エラー ({syllabus_url}): {exc}")
    return events


def check_result_url(result_url):
    """
    result_url が実際に HTTP 200 で応答するか確認する。
    adm.jdsf.jp の ◎ マークはJDSF事務局の手動更新待ちになることがあるため、
    kyougi.jdsf.or.jp の結果ページを直接確認して has_result を補完する。
    """
    if not result_url or 'kyougi.jdsf' not in result_url:
        return False
    try:
        resp = requests.head(result_url, headers=HEADERS, timeout=10, allow_redirects=True)
        return resp.status_code == 200
    except Exception:
        try:
            resp = requests.get(result_url, headers=HEADERS, timeout=10)
            return resp.status_code == 200
        except Exception:
            return False


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

        # 各セルのリンクを列ごとに直接取得
        detail_url, syllabus_url, result_url = '', '', ''

        # 公認競技会番号列（cell[1]）: detail.phpリンク
        if len(cells) > 1:
            for a in cells[1].find_all('a'):
                href = a.get('href', '')
                if href and 'detail.php' in href:
                    if not href.startswith('http'):
                        href = 'https://adm.jdsf.jp' + href
                    detail_url = href
                    break

        # シラバス列（cell[3]）: ○のリンクをそのまま取得（URL形式不問）
        if len(cells) > 3:
            for a in cells[3].find_all('a'):
                href = a.get('href', '')
                if href:
                    if not href.startswith('http'):
                        href = 'https://adm.jdsf.jp' + href
                    syllabus_url = href
                    break

        # 結果列（cell[4]）: ◎または参のリンク
        if len(cells) > 4:
            for a in cells[4].find_all('a'):
                href = a.get('href', '')
                if href:
                    if not href.startswith('http'):
                        href = 'https://adm.jdsf.jp' + href
                    result_url = href
                    break

        # 名前セル（cell[5]）に「詳細はこちら」等のリンクがある場合を補完
        # （中止競技会で結果列にリンクがない場合の対策）
        if not result_url and len(cells) > 5:
            for a in cells[5].find_all('a'):
                href = a.get('href', '')
                if not href:
                    continue
                if not href.startswith('http'):
                    href = 'https://adm.jdsf.jp' + href
                if 'detail.php' not in href and '/syllabus/' not in href:
                    result_url = href
                    break

        has_syllabus = '○' in syllabus_text

        date_iso = parse_date_iso(date_raw, comp_no)

        competitions.append({
            'date':             date_raw,
            'date_iso':         date_iso,
            'comp_no':          comp_no,
            'name':             name,
            'venue':            '',   # filled in next step
            'entry_deadline':   '',   # filled in next step (主催者締切日)
            'entry_url':        '',   # filled in next step (エントリー受付中の場合)
            'online_entry':     online_entry,
            'has_syllabus':     has_syllabus,
            'has_result':       '◎' in result_text,
            'has_participants': '参' in result_text,
            'events':           [],   # filled in later (シラバスの競技内容)
            'detail_url':       detail_url,
            'syllabus_url':     syllabus_url,
            'result_url':       result_url,
        })

    return competitions


def is_recent_competition(c, from_iso):
    """
    西部ブロックのトップページ「直近競技会情報」に載る競技会かどうか。

    シラバスはこの一覧に出るものだけ取りに行く（＝サイトで案内している競技会だけ）。
    判定は index.html の絞り込みとまったく同じ規則にしてあるので、
    片方だけ変えると「トップには出ているのにシラバスが取れていない」というズレが起きる。
    """
    if not c.get('date_iso') or c['date_iso'] < from_iso:
        return False                                   # 1週間前より過去は除外
    name = c.get('name') or ''
    if 'PD' in name[:10]:
        return False                                   # 大会名先頭10文字に「PD」
    if '奈良県ダンス連盟' in name:
        return False
    return True


def load_prev_events():
    """
    すでに取得済みのシラバス種目（comp_no → events）を集める。

    GitHub Actions は毎回リポジトリを clone し直し、生成した JSON は本番へ rsync するだけで
    コミットしない。そのためローカルのファイルだけを見ると毎回「未取得」になってしまうので、
    公開中の JSON も見て引き継ぐ。シラバスは一度公開されれば内容が変わらないので、
    ここで拾えたものは二度と取りに行かない。
    """
    prev = {}
    for source in ('published', 'local'):
        try:
            if source == 'local':
                with open(os.path.abspath(OUTPUT_PATH), encoding='utf-8') as f:
                    data = json.load(f)
            else:
                resp = requests.get(PUBLISHED_JSON_URL, headers=HEADERS, timeout=20)
                resp.raise_for_status()
                data = resp.json()
            for c in (data.get('competitions') or []):
                if c.get('events') and not prev.get(c.get('comp_no')):
                    prev[c['comp_no']] = c['events']
        except Exception as exc:
            print(f"  （{source} の既存データは読めませんでした: {exc}）")
    return prev


def main():
    os.makedirs(os.path.dirname(os.path.abspath(OUTPUT_PATH)), exist_ok=True)

    current_year = get_fiscal_year()
    all_comps = []

    # 2020年から翌年まで取得（過去データを含む）
    fetch_years = list(range(2020, current_year + 2))
    for year in fetch_years:
        try:
            comps = fetch_year(year)
            all_comps.extend(comps)
            print(f"  {year}年: {len(comps)} 件")
        except Exception as exc:
            print(f"  {year}年 取得エラー: {exc}")

    # Deduplicate by comp_no, keep first occurrence
    seen, unique = set(), []
    for c in all_comps:
        if c['comp_no'] not in seen:
            seen.add(c['comp_no'])
            unique.append(c)

    # Assign fiscal_year from comp_no (first 2 digits = last 2 of year)
    # This is more reliable than the JDSF page year since competitions
    # can appear on multiple years' pages.
    for c in unique:
        try:
            c['fiscal_year'] = 2000 + int(c['comp_no'][:2])
        except (ValueError, IndexError):
            pass  # keep existing fiscal_year if already set

    # Sort chronologically
    unique.sort(key=lambda c: c['date_iso'] or '9999-99-99')

    # Fetch venue and entry_deadline from each competition's detail page
    # 2年以上前の競技会は会場のみ取得（エントリー情報不要）、詳細フェッチをスキップ可
    today_iso = datetime.now().strftime('%Y-%m-%d')
    cutoff_iso = '2019-01-01'  # 2020年以降は全て詳細取得（会場名を含む）
    print(f"\n詳細情報（会場・主催者締切日）を取得中... (※{cutoff_iso}以前はスキップ)")
    for i, c in enumerate(unique):
        if 'detail.php' not in c.get('detail_url', ''):
            print(f"  [{i+1}/{len(unique)}] {c['date']} {c['name'][:30]}... → (詳細URLなし)")
            continue
        if c.get('date_iso', '') < cutoff_iso:
            print(f"  [{i+1}/{len(unique)}] {c['date']} {c['name'][:30]}... → (スキップ)")
            continue
        info = fetch_detail_info(c['detail_url'])
        c['venue']          = info['venue']
        c['entry_deadline'] = info['entry_deadline']
        c['entry_url']      = info['entry_url']
        entry_status = 'エントリー受付中' if info['entry_url'] else '受付なし'
        print(f"  [{i+1}/{len(unique)}] {c['date']} {c['name'][:30]}... → 会場:{info['venue'] or '(なし)'} 締切:{info['entry_deadline'] or '(なし)'} {entry_status}")
        time.sleep(0.5)  # polite delay

    # シラバス（大会要項）の「競技内容」から区分（並び順）と種目を取得する。
    # 受付システムのCSV取込で競技参加区分の列に区分コードを割り当てるのと、
    # 出場選手一覧に区分ごとの種目を出すのに使う。
    # シラバスは一度出れば内容が変わらないので、取れているものは取り直さない。
    # ただし種目（dances）を足す前に取った古い形のものは、直近の大会に限り取り直す。
    prev_events = load_prev_events()
    syllabus_from = (datetime.now() - timedelta(days=7)).strftime('%Y-%m-%d')
    print("")
    print("競技内容（シラバス）を取得中... (※トップページの直近競技会情報に出るもののみ／取得済みは取り直さない)")
    kept = fetched = 0
    for i, c in enumerate(unique):
        prev = prev_events.get(c['comp_no'])
        # 種目が入っていないものは、この項目を足す前に取った古い形
        old_form = bool(prev) and not any('dances' in e for e in prev)
        # すでに取れている分はそのまま引き継ぐ（過去分のデータも消さない）
        if prev and not old_form:
            c['events'] = prev
            kept += 1
            continue
        # まだ取れていないもの（と古い形のもの）だけ取りに行く。
        # 直近に出ていない大会は取りに行けないので、持っているものをそのまま使う。
        if not is_recent_competition(c, syllabus_from) or not c.get('syllabus_url'):
            c['events'] = prev or []
            if prev:
                kept += 1
            continue
        c['events'] = fetch_syllabus_events(c['syllabus_url'])
        # 取り直しに失敗したときに、前に取れていたものを捨てない
        if not c['events'] and prev:
            c['events'] = prev
        fetched += 1
        codes = ','.join(e['code'] for e in c['events'])
        print(f"  [{i+1}/{len(unique)}] {c['date']} {c['name'][:24]}... → {len(c['events'])}種目 {codes[:60]}")
        time.sleep(0.5)  # polite delay
    print(f"  取得 {fetched} 件 / 引き継ぎ {kept} 件")

    # result_url が実際に存在するか確認して has_result を補完
    # adm.jdsf.jp の ◎ マーク更新はJDSF事務局の手動作業のため遅延する場合がある
    print(f"\n結果URL確認中（adm未更新分の補完）...")
    for i, c in enumerate(unique):
        # 「参」(参加者一覧)が付いている競技は、結果ページが200でも結果(◎)に昇格させない
        # （参加者一覧ページも200を返すため、参のみの競技を誤って「結果」にしないようガード）
        if not c.get('has_result') and not c.get('has_participants') and c.get('result_url'):
            live = check_result_url(c['result_url'])
            if live:
                c['has_result'] = True
                print(f"  [{i+1}] ◎補完: {c['date']} {c['name'][:30]}... → has_result=True")
            time.sleep(0.3)

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
