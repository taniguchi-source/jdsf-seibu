# -*- coding: utf-8 -*-
"""
西部ブロック幹部会 議事録フォルダの Basic認証を、サイトの「構築(build)パスワード」に自動同期する。

■ 仕組み
  1. PW管理シート（HP用資料）の構築(build)PWを、サイトのGAS認証(action=auth)経由で取得
     （既存 set_passwords.fetch_current_pw を再利用。マスター秘密が必要）。
  2. そのPWで .htpasswd(bcrypt) と .htaccess を生成。
  3. SFTP(SSH鍵)で docs/meetings/minutes/西部ブロック幹部会/ へ配置。
  → これで幹部会フォルダが「構築PW」で保護される。定期実行すれば構築PW変更にも追従。

■ 認証情報の与え方
  - マスター秘密 : 環境変数 AUTH_MASTER_SECRET（GitHub Actions）優先、無ければ scripts/.master_secret
  - SSH秘密鍵   : 環境変数 SSH_PRIVATE_KEY_B64（base64・GitHub Actions）優先、無ければ scripts/.minutes_ssh_key
  ※ パスワード平文は標準出力に一切出さない（.htpasswd にハッシュで書くのみ）。

■ 使い方
  python scripts/sync_kanbu_auth.py
"""
import os
import sys
import io
import base64
import pathlib

import paramiko
import bcrypt

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import set_passwords as sp  # fetch_current_pw / master_secret を再利用

# ── 設定 ─────────────────────────────────────────────
SITE_KEY   = "jdsf-seibu"   # メインサイトのGAS認証キー（構築PWの取得元）
BASIC_USER = "kanbu"        # Basic認証のユーザーID
AUTH_REALM = "Seibu Block Kanbu-kai (Restricted)"

HOST, PORT, USER = "sv16504.xserver.jp", 10022, "jdsfseibu"
HOME = f"/home/{USER}"
# SFTPホームからの相対パス（upload_minutes_seibu.py と同じ基準）
REMOTE_DIR = "jdsf-seibu.com/public_html/docs/meetings/minutes/西部ブロック幹部会"
# .htaccess の AuthUserFile に書くサーバー絶対パス
REMOTE_HTPASSWD_ABS = f"{HOME}/{REMOTE_DIR}/.htpasswd"
# ─────────────────────────────────────────────────────


def get_master_secret() -> str:
    sec = os.environ.get("AUTH_MASTER_SECRET", "").strip()
    if sec:
        return sec
    return sp.master_secret()


def get_build_password() -> str:
    """構築(build)PWをGAS経由で取得。フォールバック値しか取れなければ中断。"""
    sec = get_master_secret()
    _admin, build = sp.fetch_current_pw(SITE_KEY, sec)
    fallback = SITE_KEY + "2026"
    if not build or build == fallback:
        sys.exit("[ERROR] 構築PWの取得に失敗しました（フォールバック値）。SITE_KEY / マスター秘密を確認してください。")
    return build


def make_htpasswd(user: str, password: str) -> str:
    """bcryptで .htpasswd 行を作る。Apache互換のため $2b$ を $2y$ に置換。"""
    h = bcrypt.hashpw(password.encode("utf-8"), bcrypt.gensalt(rounds=10)).decode("ascii")
    if h.startswith("$2b$"):
        h = "$2y$" + h[4:]
    return f"{user}:{h}\n"


def make_htaccess() -> str:
    return (
        "AuthType Basic\n"
        f'AuthName "{AUTH_REALM}"\n'
        f"AuthUserFile {REMOTE_HTPASSWD_ABS}\n"
        "Require valid-user\n"
    )


def load_ssh_key():
    """SSH秘密鍵を読み込む。env SSH_PRIVATE_KEY_B64 優先、無ければ scripts/.minutes_ssh_key。
    鍵種別（Ed25519/RSA/ECDSA）は順に試す。"""
    b64 = os.environ.get("SSH_PRIVATE_KEY_B64", "").strip()
    if b64:
        text = base64.b64decode(b64).decode("utf-8", "replace")
        return _key_from_text(text)
    keypath = os.path.join(HERE, ".minutes_ssh_key")
    if not os.path.exists(keypath):
        sys.exit("[ERROR] SSH鍵が見つかりません（env SSH_PRIVATE_KEY_B64 か scripts/.minutes_ssh_key）。")
    last = None
    for cls in (paramiko.Ed25519Key, paramiko.RSAKey, paramiko.ECDSAKey):
        try:
            return cls.from_private_key_file(keypath)
        except Exception as e:
            last = e
    sys.exit(f"[ERROR] SSH鍵の読み込みに失敗: {last}")


def _key_from_text(text: str):
    last = None
    for cls in (paramiko.Ed25519Key, paramiko.RSAKey, paramiko.ECDSAKey):
        try:
            return cls.from_private_key(io.StringIO(text))
        except Exception as e:
            last = e
    sys.exit(f"[ERROR] SSH鍵(B64)の読み込みに失敗: {last}")


def main():
    print("=" * 50)
    print(" 幹部会 Basic認証 同期（構築PW → .htpasswd）")
    print("=" * 50)

    build_pw = get_build_password()
    htpasswd = make_htpasswd(BASIC_USER, build_pw)
    htaccess = make_htaccess()
    print(f"[OK] 構築PW取得済み（user={BASIC_USER}、値は非表示）")

    key = load_ssh_key()
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, port=PORT, username=USER, pkey=key, timeout=30)
    sftp = client.open_sftp()
    print(f"[OK] SFTP接続: {USER}@{HOST}:{PORT}")

    for name, content in ((".htpasswd", htpasswd), (".htaccess", htaccess)):
        remote_path = f"{REMOTE_DIR}/{name}"
        with sftp.open(remote_path, "w") as f:
            f.write(content)
        try:
            sftp.chmod(remote_path, 0o644)
        except Exception:
            pass
        print(f"  [up] {name}")

    sftp.close()
    client.close()
    print("[DONE] 幹部会フォルダに Basic認証を配置/更新しました。")


if __name__ == "__main__":
    main()
