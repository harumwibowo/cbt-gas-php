#!/usr/bin/env python
"""Uji fitur pembaruan aplikasi dari GitHub (RPC + mesin pembaruan)."""
import io
import json
import os
import shutil
import subprocess
import tarfile
import urllib.error
import urllib.request

BASE = "http://localhost/cbt-gas-php/"
WWW = "C:/laragon87/www"
ROOT = WWW + "/cbt-gas-php"
COPY = WWW + "/_upd_app"
PAYLOAD = WWW + "/_upd_payload.tar.gz"
PAYLOAD_BROKEN = WWW + "/_upd_payload_broken.tar.gz"
SKIP_DIRS = {".git", "storage", "tests", "_tmp", "__pycache__"}
SKIP_FILES = {"database.json", "pass.key", "installed.json", "update_token.txt"}

passed, failed = [], []


def rpc(action, *args):
    req = urllib.request.Request(BASE + "api.php?action=" + action,
                                 data=json.dumps({"args": list(args)}).encode(),
                                 headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return json.loads(e.read().decode() or "{}")


def check(step, cond, info=""):
    line = f"{'PASS' if cond else 'FAIL'}  {step}"
    if info:
        line += f"  ({info})"
    print(line)
    (passed if cond else failed).append(step)


def build_tarball(target, broken_helpers=False):
    with tarfile.open(target, "w:gz") as tar:
        for base, dirs, files in os.walk(ROOT):
            rel_base = os.path.relpath(base, ROOT).replace("\\", "/")
            if rel_base == ".":
                rel_base = ""
            dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
            for name in files:
                if name in SKIP_FILES or name.endswith((".bak", ".log", ".pyc")):
                    continue
                full = os.path.join(base, name)
                rel = (rel_base + "/" + name).lstrip("/")
                if broken_helpers and rel == "lib/helpers.php":
                    data = b"<?php\nfunction helpers_rusak( {\n    return 1;\n}\n"
                    info = tarfile.TarInfo("cbt-gas-php-main/" + rel)
                    info.size = len(data)
                    info.mode = 0o644
                    tar.addfile(info, io.BytesIO(data))
                    continue
                tar.add(full, arcname="cbt-gas-php-main/" + rel)


def run_cli(url, token, force=False):
    code = (
        "putenv('CBT_UPDATE_URL=' . getenv('URL'));"
        "require 'config/config.php';"
        "require_once APP_ROOT . '/lib/update.php';"
        "echo json_encode(update_apply('admin', getenv('TOK'), " + ("true" if force else "false") + "));"
    )
    r = subprocess.run(["php", "-r", code], cwd=COPY, capture_output=True, text=True,
                       env=dict(os.environ, URL=url, TOK=token))
    out = (r.stdout or "").strip()
    try:
        return json.loads(out)
    except Exception:
        return {"success": False, "message": "output tak terbaca: " + out[:200] + (r.stderr or "")[:200]}


def md5(path):
    import hashlib
    return hashlib.md5(open(path, "rb").read()).hexdigest()


# ------------------------------------------------------------------ login HTTP
lg = rpc("login", "admin", "admin123", "Mozilla/5.0 Chrome/120")
admin_token = lg.get("token", "")
check("login admin untuk uji pembaruan", lg.get("success") is True)

_, _ = None, None
sess = rpc("saveUser", "admin", admin_token, {"username": "siswaupd", "nama": "Siswa Update", "role": "siswa",
                                              "password": "siswa123", "kelas": "XII", "nisn": "99990004"})
sl = rpc("login", "siswaupd", "siswa123", "Mozilla/5.0 Chrome/120")
student_token = sl.get("token", "")

# ------------------------------------------------------------- 1. cek versi RPC
ver = rpc("getApplicationVersion", "admin", admin_token)
check("admin dapat membaca versi aplikasi", ver.get("success") is True, str(ver.get("message", ""))[:40])
check("repositori privat terdeteksi butuh token",
      ver.get("needs_token") is True or bool(ver.get("latest_short")),
      f"needs_token={ver.get('needs_token')} latest={ver.get('latest_short')}")
check("info versi menyertakan tautan repo", "github.com/harumwibowo/cbt-gas-php" in str(ver.get("repo")))

den = rpc("getApplicationVersion", "siswaupd", student_token)
check("siswa tidak boleh membaca versi aplikasi", den.get("success") is False, str(den.get("message"))[:40])

den = rpc("updateApplication", "siswaupd", student_token, False)
check("siswa tidak boleh menjalankan pembaruan", den.get("success") is False, str(den.get("message"))[:40])

tok_bad = rpc("setUpdateToken", "admin", admin_token, "pendek")
check("token terlalu pendek ditolak", tok_bad.get("success") is False, str(tok_bad.get("message"))[:45])

tok_den = rpc("setUpdateToken", "siswaupd", student_token, "ghp_abcdefghijklmnopqrstuvwxyz0123")
check("siswa tidak boleh mengubah token", tok_den.get("success") is False, str(tok_den.get("message"))[:40])

# ------------------------------------------------- 2. mesin pembaruan (salinan)
try:
    if os.path.isdir(COPY):
        shutil.rmtree(COPY)
    shutil.copytree(ROOT, COPY, ignore=shutil.ignore_patterns('.git', 'storage', 'tests', '_tmp', '__pycache__'))
    os.makedirs(os.path.join(COPY, "storage", "backups"), exist_ok=True)

    # tandai berkas lama agar terlihat diganti
    with open(os.path.join(COPY, "Index.html"), "a", encoding="utf-8") as fh:
        fh.write("<!-- MARKER-LAMA -->\n")
    config_before = md5(os.path.join(COPY, "config", "database.json"))
    pass_before = os.path.isfile(os.path.join(COPY, "config", "pass.key"))

    build_tarball(PAYLOAD)
    url = "file:///" + PAYLOAD.replace("\\", "/")

    res = run_cli(url, admin_token)
    check("pembaruan berhasil dijalankan", res.get("success") is True, str(res.get("message"))[:70])
    check("berkas aplikasi ikut diperbarui", (res.get("files_updated") or 0) > 10, f"updated={res.get('files_updated')}")
    check("berkas lama benar-benar diganti",
          "MARKER-LAMA" not in open(os.path.join(COPY, "Index.html"), encoding="utf-8").read())
    check("cadangan dibuat sebelum menimpa",
          res.get("backup", "").startswith("storage/backups/")
          and os.path.isdir(os.path.join(COPY, res.get("backup", "").replace("/", os.sep))),
          str(res.get("backup")))
    check("config/database.json tidak ditimpa",
          os.path.isfile(os.path.join(COPY, "config", "database.json"))
          and md5(os.path.join(COPY, "config", "database.json")) == config_before)
    check("config/pass.key tetap ada", os.path.isfile(os.path.join(COPY, "config", "pass.key")) == pass_before)
    check("versi tersimpan di pengaturan", len(str(res.get("current_version", ""))) >= 7,
          str(res.get("current_version"))[:12])

    res2 = run_cli(url, admin_token)
    check("pembaruan kedua melaporkan sudah versi terbaru",
          res2.get("success") is True and res2.get("up_to_date") is True, str(res2.get("message"))[:60])

    # --------------------------------------------- 3. berkas rusak -> rollback
    helpers_before = md5(os.path.join(COPY, "lib", "helpers.php"))
    build_tarball(PAYLOAD_BROKEN, broken_helpers=True)
    res3 = run_cli("file:///" + PAYLOAD_BROKEN.replace("\\", "/"), admin_token, force=True)
    check("pembaruan dengan berkas rusak dibatalkan", res3.get("success") is False, str(res3.get("message"))[:60])
    check("pesan menyebut pembatalan/rollback",
          "dibatalkan" in str(res3.get("message", "")).lower() or res3.get("rollback") is True)
    check("berkas yang dirusak dikembalikan seperti semula",
          md5(os.path.join(COPY, "lib", "helpers.php")) == helpers_before)
    lint = subprocess.run(["php", "-l", os.path.join(COPY, "lib", "helpers.php")],
                          capture_output=True, text=True)
    check("aplikasi tetap memiliki sintaks valid setelah rollback",
          "No syntax errors" in (lint.stdout or ""))
    temp_left = [n for n in os.listdir(os.environ.get("TEMP", "."))
                 if n.startswith("cbt-update-") and os.path.isdir(os.path.join(os.environ.get("TEMP", "."), n))]
    check("folder kerja sementara dibersihkan", temp_left == [], f"{len(temp_left)} tersisa")

    # hapus berkas hasil uji di salinan agar mudah dibersihkan
finally:
    for path in (COPY, PAYLOAD, PAYLOAD_BROKEN):
        if os.path.isdir(path):
            shutil.rmtree(path, ignore_errors=True)
        elif os.path.isfile(path):
            os.remove(path)
uid = subprocess.run(["php", "-r", 'require "config/config.php"; echo db_val(getenv("SQL"));'],
                     cwd=ROOT, capture_output=True, text=True,
                     env=dict(os.environ, SQL="SELECT id_user FROM users WHERE username='siswaupd'")).stdout.strip()
if uid:
    rpc("deleteUser", "admin", admin_token, uid)
# Hapus penanda versi yang ditulis oleh repo uji "demo/cbt".
CLEAN_SQL = "DELETE FROM settings WHERE kunci LIKE '%:demo/cbt'"
subprocess.run(["php", "-r", 'require "config/config.php"; db_exec(' + json.dumps(CLEAN_SQL) + ');'],
               cwd=ROOT, capture_output=True, text=True)

check("direktori uji dibersihkan", not os.path.isdir(COPY))

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal:", ", ".join(failed))
    raise SystemExit(1)
