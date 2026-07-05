#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
サイトの管理/構築パスワードを設定・リセットするツール（ブロック事務局専用）。

各サイトの api/set_password.php に「マスター秘密」を添えてPOSTし、
そのサイトの data/auth.php にハッシュ化して保存させる。
初期投入（現行PWの移行）と、府県がPWを忘れた時のリセットに使う。

【マスター秘密】scripts/.master_secret（1行・.gitignore済）を読む。
【使い方】
  # 1サイト
  python scripts/set_passwords.py https://preftest.jdsf-seibu.com --admin "＜新PW＞" --build "＜新PW＞"
  # 府県サブドメイン（省略形。--sub で https://<sub>.jdsf-seibu.com を組み立て）
  python scripts/set_passwords.py --sub kochi --admin "＜新PW＞"
  # 管理PWだけ / 構築PWだけ でもOK（指定した role だけ設定）

※ 現在のPWの「閲覧」はできません（ハッシュのため）。できるのは新しいPWへの設定/リセットのみ。
"""
import argparse
import json
import os
import sys
import io
import urllib.request
import urllib.parse
import urllib.error

try:
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
GAS_AUTH_URL = "https://script.google.com/macros/s/AKfycbxS07Mxs6TZHdq0aGTay547EfIrN5igaJ527EaWl-O-RgHv7VHMllszJyMkI30qRU3A/exec"


def fetch_current_pw(sub, secret=""):
    """現行GAS(?action=auth)から現在の 管理PW/構築PW を取得。空なら旧フォールバック <sub>2026。
    GAS更新後は secret 必須。"""
    url = GAS_AUTH_URL + "?action=auth&site=" + urllib.parse.quote(sub)
    if secret:
        url += "&secret=" + urllib.parse.quote(secret)
    fallback = sub + "2026"
    try:
        with urllib.request.urlopen(url, timeout=30) as r:
            d = json.loads(r.read().decode("utf-8", "replace"))
    except Exception as e:
        print(f"  ⚠ GAS取得失敗（{e}）→ フォールバック {fallback} を使用")
        return fallback, fallback
    admin = (d.get("password") or "").strip() or fallback
    build = (d.get("build") or "").strip() or fallback
    return admin, build


def master_secret():
    p = os.path.join(HERE, ".master_secret")
    if not os.path.exists(p):
        sys.exit("マスター秘密が見つかりません: scripts/.master_secret を用意してください。")
    with open(p, encoding="utf-8") as f:
        s = f.read().strip()
    if not s:
        sys.exit("scripts/.master_secret が空です。")
    return s


def set_one(base, role, new_pw, secret):
    url = base.rstrip("/") + "/api/set_password.php"
    data = urllib.parse.urlencode({"master": secret, "role": role, "new": new_pw}).encode("utf-8")
    req = urllib.request.Request(url, data=data)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            body = r.read().decode("utf-8", "replace")
            res = json.loads(body)
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")[:200]
        return False, f"HTTP {e.code}: {detail}"
    except Exception as e:
        return False, str(e)
    if res.get("ok"):
        return True, f"{role} 設定OK"
    return False, res.get("error", str(res))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("base", nargs="?", help="サイトURL 例: https://preftest.jdsf-seibu.com")
    ap.add_argument("--sub", help="府県サブドメイン名（base の代わりに https://<sub>.jdsf-seibu.com）")
    ap.add_argument("--admin", help="管理(admin)パスワード")
    ap.add_argument("--build", help="構築(build)パスワード")
    ap.add_argument("--inherit", action="store_true", help="現行GASから現在のPWを取得して継承（--admin/--build不要）")
    ap.add_argument("--allow-short", action="store_true", help="8文字未満のPWも許可（現行の短いPWをそのまま保持したい時）")
    args = ap.parse_args()

    base = args.base or (f"https://{args.sub}.jdsf-seibu.com" if args.sub else None)
    if not base:
        sys.exit("サイトURL（または --sub）を指定してください。")

    if args.inherit:
        sub = args.sub or urllib.parse.urlparse(base).hostname.split(".")[0]
        a, b = fetch_current_pw(sub, master_secret())
        def ok8(pw, role):
            if not args.allow_short and len(pw) < 8:
                print(f"  ⚠ {role} の現行PWが8文字未満 → {sub}2026 に設定（担当者へ連絡要）")
                return sub + "2026"
            return pw
        args.admin = args.admin or ok8(a, "admin")
        args.build = args.build or ok8(b, "build")
        print(f"継承: {sub} の現行PWを取得しました。")

    if not args.admin and not args.build:
        sys.exit("--admin / --build か --inherit を指定してください。")
    if not args.allow_short:
        for pw in (args.admin, args.build):
            if pw is not None and len(pw) < 8:
                sys.exit("パスワードは8文字以上にしてください（現行の短いPWを保持するなら --allow-short）。")

    secret = master_secret()
    print(f"対象: {base}")
    ok_all = True
    if args.admin:
        ok, msg = set_one(base, "admin", args.admin, secret)
        print(("  ✅ " if ok else "  ❌ ") + msg); ok_all &= ok
    if args.build:
        ok, msg = set_one(base, "build", args.build, secret)
        print(("  ✅ " if ok else "  ❌ ") + msg); ok_all &= ok
    sys.exit(0 if ok_all else 1)


if __name__ == "__main__":
    main()
