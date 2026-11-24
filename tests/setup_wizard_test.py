#!/usr/bin/env python
"""Uji halaman setup instalasi baru (deteksi, uji koneksi, instal, redirect)."""
import json
import os
import shutil
import subprocess
import urllib.request
import urllib.error

BASE = "http://localhost/cbt-gas-php/"
ROOT = "C:/laragon87/www/cbt-gas-php"
CONFIG = os.path.join(ROOT, "config", "database.json")
BACKUP = os.path.join(ROOT, "_tmp", "database.json.bak")
INSTALLED = os.path.join(ROOT, "config", "installed.json")
INSTALLED_BACKUP = os.path.join(ROOT, "_tmp", "installed.json.bak")
TEST_DB = "cbt_setup_test"
MYSQL = "C:/laragon87/bin/mysql/mysql-8.4.10-winx64/bin/mysql.exe"

passed, failed = [], []


def http(path, data=None, raw=False):
    url = BASE + path
    req = urllib.request.Request(url, data=None if data is None else json.dumps(data).encode(),
                                 headers={"Content-Type": "application/json"})
    opener = urllib.request.build_opener(NoRedirect)
    try:
        with opener.open(req, timeout=60) as r:
            body = r.read().decode("utf-8", "replace")
            return r.status, dict(r.headers), body
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode("utf-8", "replace")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def php(code):
    r = subprocess.run(["php", "-r", 'require "config/config.php"; ' + code],
                       cwd=ROOT, capture_output=True, text=True)
    return ((r.stdout or "") + (r.stderr or "")).strip()


def mysql(sql, db=None):
    args = [MYSQL, "-uroot"] + ([db] if db else []) + ["-e", sql]
    return subprocess.run(args, capture_output=True, text=True)


def check(step, cond, info=""):
    line = f"{'PASS' if cond else 'FAIL'}  {step}"
    if info:
        line += f"  ({info})"
    print(line)
    (passed if cond else failed).append(step)


# ------------------------------------------------------------------ persiapan
os.makedirs(os.path.dirname(BACKUP), exist_ok=True)
had_config = os.path.isfile(CONFIG)
if had_config:
    shutil.copy2(CONFIG, BACKUP)
had_installed = os.path.isfile(INSTALLED)
if had_installed:
    shutil.copy2(INSTALLED, INSTALLED_BACKUP)
mysql(f"DROP DATABASE IF EXISTS {TEST_DB}")

try:
    if os.path.isfile(CONFIG):
        os.remove(CONFIG)

    # --------------------------------------------------- 1. deteksi instal baru
    st, hd, _ = http("index.php")
    check("index.php mengarah ke setup saat belum terpasang",
          st == 302 and "setup.php" in hd.get("Location", ""), f"{st} {hd.get('Location', '')}")

    st, _, body = http("setup.php")
    check("halaman setup tampil", st == 200 and "Setup Instalasi" in body)
    check("form berisi field koneksi",
          all(x in body for x in ['id="f-host"', 'id="f-port"', 'id="f-name"', 'id="f-user"', 'id="f-pass"']))
    check("halaman setup menampilkan pemeriksaan server", "Pemeriksaan server" in body)

    st, _, body = http("api.php?action=getSettings", {"args": []})
    parsed = json.loads(body) if body.strip().startswith("{") else {}
    check("api.php menolak dipakai sebelum instalasi",
          st == 503 and parsed.get("needs_setup") is True, f"{st} {parsed.get('error', '')[:40]}")

    # ------------------------------------------------------- 2. uji koneksi DB
    st, _, body = http("setup.php?action=test",
                       {"host": "127.0.0.1", "port": 3306, "name": "cbt_gas", "user": "root", "pass": "salah"})
    res = json.loads(body)
    check("password database salah ditolak", res.get("ok") is False, res.get("message", "")[:60])

    st, _, body = http("setup.php?action=test",
                       {"host": "127.0.0.1", "port": 3306, "name": "cbt_gas", "user": "root",
                        "pass": "", "create_db": False})
    res = json.loads(body)
    check("koneksi database yang benar berhasil", res.get("ok") is True, res.get("version", ""))

    st, _, body = http("setup.php?action=test",
                       {"host": "127.0.0.1", "port": 3306, "name": TEST_DB, "user": "root",
                        "pass": "", "create_db": False})
    res = json.loads(body)
    check("database yang belum ada dilaporkan tanpa membuatnya",
          res.get("ok") is False and "belum ada" in res.get("message", ""), res.get("message", "")[:50])

    st, _, body = http("setup.php?action=test",
                       {"host": "127.0.0.1", "port": 3306, "name": "nama db; DROP", "user": "root", "pass": ""})
    res = json.loads(body)
    check("nama database berbahaya ditolak", res.get("ok") is False, res.get("message", "")[:40])

    # --------------------------------------------------------------- 3. instal
    st, _, body = http("setup.php?action=install",
                       {"host": "127.0.0.1", "port": 3306, "name": TEST_DB, "user": "root",
                        "pass": "", "create_db": True})
    res = json.loads(body)
    check("instalasi database baru berhasil", res.get("success") is True, res.get("message", ""))
    check("berkas config/database.json dibuat", os.path.isfile(CONFIG))
    saved = json.load(open(CONFIG)) if os.path.isfile(CONFIG) else {}
    check("kredensial tersimpan sesuai input", saved.get("name") == TEST_DB and saved.get("host") == "127.0.0.1",
          json.dumps(saved.get("name")))
    check("laporan instalasi menyebut jumlah akun & pengaturan",
          res.get("users") == 1 and res.get("settings", 0) >= 10,
          f"users={res.get('users')} settings={res.get('settings')}")

    out = mysql("SHOW TABLES;", TEST_DB).stdout
    check("9 tabel terbentuk di database baru", out.count("\n") >= 9, f"{out.count(chr(10))} tabel")
    pwhash = php(f'echo db_val("SELECT password FROM users WHERE LOWER(role)=\'admin\'");')
    check("akun admin dibuat dengan password ter-hash", pwhash.startswith("$2y$"), pwhash[:10])

    # ------------------------------------------------- 4. setup tidak muncul lagi
    st, hd, _ = http("setup.php")
    check("halaman setup mengarah ke aplikasi bila sudah terpasang",
          st == 302 and "index.php" in hd.get("Location", ""), f"{st} {hd.get('Location', '')}")

    st, _, body = http("index.php")
    check("index.php menyajikan aplikasi", st == 200 and "CBT GAS" in body)
    check("halaman login tersedia", "login" in body.lower())

    st, _, body = http("api.php?action=login", {"args": ["admin", "admin123", "Mozilla/5.0 Chrome/120"]})
    res = json.loads(body)
    check("login admin berhasil setelah instalasi", res.get("success") is True, res.get("message", ""))

    st, _, body = http("setup.php?force=1")
    check("setup.php?force=1 tetap dapat dibuka admin untuk memperbaiki kredensial",
          st == 200 and "Mode setup ulang" in body)

    # password database yang tersimpan tidak boleh tampil di HTML
    saved = json.load(open(CONFIG))
    saved["pass"] = "rahasia123"
    json.dump(saved, open(CONFIG, "w"))
    st, _, body = http("setup.php?force=1")
    check("password database tersimpan tidak bocor ke halaman setup",
          "rahasia123" not in body and "•" in body, f"panjang html={len(body)}")
    saved["pass"] = ""
    json.dump(saved, open(CONFIG, "w"))

    # ------------------------------------------------------- 5. galat koneksi
    st, _, body = http("setup.php?action=test",
                       {"host": "127.0.0.1", "port": 3307, "name": TEST_DB, "user": "root", "pass": ""})
    res = json.loads(body)
    check("host/port yang salah dilaporkan gagal", res.get("ok") is False, res.get("message", "")[:45])

finally:
    # ------------------------------------------------------------- pemulihan
    if had_config:
        shutil.copy2(BACKUP, CONFIG)
        os.remove(BACKUP)
    elif os.path.isfile(CONFIG):
        os.remove(CONFIG)
    # metadata instalasi ikut dipulihkan (uji menulis db uji cbt_setup_test)
    if had_installed:
        shutil.copy2(INSTALLED_BACKUP, INSTALLED)
        os.remove(INSTALLED_BACKUP)
    elif os.path.isfile(INSTALLED):
        os.remove(INSTALLED)
    mysql(f"DROP DATABASE IF EXISTS {TEST_DB}")

st, _, body = http("index.php")
check("aplikasi kembali normal setelah pemulihan", st == 200 and "CBT GAS" in body)

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal:", ", ".join(failed))
    raise SystemExit(1)
