# -*- coding: utf-8 -*-
"""preftest の各ページの「サイト名 動的反映」処理を拡張し、
data/sitename.json の name で、タイトル/メタ/バッジ/ヒーロー説明/ティッカー/
フッター説明/コピーライトの「テストダンススポーツ連盟」も置換する。
さらに index.html はカルーセル説明文の再描画時にも置換する。
改行(CRLF/LF)を壊さないようバイナリで処理する。
"""
import os, sys

BASE = os.path.join(os.path.dirname(__file__), "..", "preftest")
PAGES = ["index", "page1", "page2", "page3", "page4", "page5", "news", "contact"]

OLD_BLOCK = (
    "      if (!d || !d.name) return;\n"
    "      var els = document.querySelectorAll('.header-logo-text .name, .footer-logo-name');\n"
    "      for (var i=0;i<els.length;i++) els[i].textContent = d.name;\n"
    "      var cps = document.querySelectorAll('.footer-bottom span');\n"
    "      for (var j=0;j<cps.length;j++){ if (/All rights reserved|©/.test(cps[j].textContent)) cps[j].textContent = cps[j].textContent.replace(/テストダンススポーツ連盟/g, d.name); }\n"
)

NEW_BLOCK = (
    "      if (!d || !d.name) return;\n"
    "      var name = d.name, OLD = 'テストダンススポーツ連盟';\n"
    "      window._fedName = name;\n"
    "      if (document.title) document.title = document.title.split(OLD).join(name);\n"
    "      var md = document.querySelector('meta[name=\"description\"]');\n"
    "      if (md && md.content) md.content = md.content.split(OLD).join(name);\n"
    "      var logos = document.querySelectorAll('.header-logo-text .name, .footer-logo-name');\n"
    "      for (var i=0;i<logos.length;i++) logos[i].textContent = name;\n"
    "      var txts = document.querySelectorAll('#badge-text, .hero-desc, #slide-desc, .ticker-text, .footer-desc, .footer-bottom span');\n"
    "      for (var j=0;j<txts.length;j++){ if (txts[j].innerHTML.indexOf(OLD) !== -1) txts[j].innerHTML = txts[j].innerHTML.split(OLD).join(name); }\n"
)

# index.html のカルーセル説明文 再描画行（2か所・同一）
CAR_OLD = "    if (descEl)  descEl.innerHTML    = descs[current].replace(/\\n/g, '<br>');\n"
CAR_NEW = "    if (descEl)  descEl.innerHTML    = descs[current].replace(/\\n/g, '<br>').split('テストダンススポーツ連盟').join(window._fedName || 'テストダンススポーツ連盟');\n"


def patch_file(path, do_carousel):
    with open(path, "rb") as f:
        raw = f.read()
    crlf = b"\r\n" in raw
    text = raw.decode("utf-8")
    if crlf:
        text = text.replace("\r\n", "\n")  # 正規化して処理

    report = []
    n1 = text.count(OLD_BLOCK)
    if n1 == 1:
        text = text.replace(OLD_BLOCK, NEW_BLOCK)
        report.append("sitename-block:OK")
    else:
        report.append("sitename-block:MISS(%d)" % n1)

    if do_carousel:
        n2 = text.count(CAR_OLD)
        if n2 >= 1:
            text = text.replace(CAR_OLD, CAR_NEW)
            report.append("carousel:%d置換" % n2)
        else:
            report.append("carousel:MISS")

    out = text.replace("\n", "\r\n") if crlf else text
    with open(path, "wb") as f:
        f.write(out.encode("utf-8"))
    return report


def main():
    for p in PAGES:
        path = os.path.normpath(os.path.join(BASE, p + ".html"))
        rep = patch_file(path, do_carousel=(p == "index"))
        print("%-9s %s" % (p, " | ".join(rep)))


if __name__ == "__main__":
    main()
