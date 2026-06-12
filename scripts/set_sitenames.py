# -*- coding: utf-8 -*-
"""各府県サブドメインのサイト名を data/sitename.json に設定する。
Windowsシェル経由だと日本語がUTF-8で渡らず壊れるため、Pythonから明示的に
UTF-8でmultipart/form-data POSTする（ブラウザのFormDataと同じ方式）。
"""
import sys, urllib.request, json, ssl, uuid

TOKEN = "preftest2026"

# サブドメイン -> 連盟名（大阪・京都は「府」、他は「県」）
NAMES = {
    "hiroshima": "広島県ダンススポーツ連盟",
    "hyogo":     "兵庫県ダンススポーツ連盟",
    "kagawa":    "香川県ダンススポーツ連盟",
    "kochi":     "高知県ダンススポーツ連盟",
    "okayama":   "岡山県ダンススポーツ連盟",
    "osaka":     "大阪府ダンススポーツ連盟",
    "shiga":     "滋賀県ダンススポーツ連盟",
    "shimane":   "島根県ダンススポーツ連盟",
    "tokushima": "徳島県ダンススポーツ連盟",
    "tottori":   "鳥取県ダンススポーツ連盟",
    "wakayama":  "和歌山県ダンススポーツ連盟",
    "ehime":     "愛媛県ダンススポーツ連盟",
    "kyoto":     "京都府ダンススポーツ連盟",
}

# preftest は元の名前へ復旧（誤って空にしたため）
RESTORE = {"preftest": "テストダンススポーツ連盟"}


def build_multipart(fields):
    boundary = "----jdsf" + uuid.uuid4().hex
    parts = []
    for k, v in fields.items():
        parts.append("--" + boundary)
        parts.append('Content-Disposition: form-data; name="%s"' % k)
        parts.append("")
        parts.append(v)
    parts.append("--" + boundary + "--")
    parts.append("")
    body = "\r\n".join(parts).encode("utf-8")  # 明示的にUTF-8
    return boundary, body


def post(sub, name):
    url = "https://%s.jdsf-seibu.com/api/save_sitename.php" % sub
    boundary, body = build_multipart({"token": TOKEN, "name": name})
    req = urllib.request.Request(url, data=body, method="POST")
    req.add_header("Content-Type", "multipart/form-data; boundary=" + boundary)
    req.add_header("User-Agent", "Mozilla/5.0 (set_sitenames.py)")
    ctx = ssl.create_default_context()
    try:
        with urllib.request.urlopen(req, timeout=20, context=ctx) as r:
            raw = r.read().decode("utf-8", "replace")
            return r.status, raw
    except Exception as e:
        return "ERR", str(e)


def main():
    targets = dict(NAMES)
    if "--restore-preftest" in sys.argv:
        targets = dict(RESTORE)
    for sub, name in targets.items():
        status, resp = post(sub, name)
        print("%-10s %-28s -> [%s] %s" % (sub, name, status, resp.strip()[:120]))


if __name__ == "__main__":
    main()
