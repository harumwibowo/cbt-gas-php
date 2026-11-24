#!/usr/bin/env python
"""Uji penyimpanan password: hash + upgrade otomatis + kartu ujian + tools CLI."""
import json
import subprocess
import urllib.request
import urllib.error

BASE = "http://localhost/cbt-gas-php/"
ROOT = "C:/laragon87/www/cbt-gas-php"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"
STUDENT = "siswahash"
SPASS = "rahasia123"

passed, failed = [], []


def call(action, *args):
    req = urllib.request.Request(
        BASE + "api.php?action=" + action,
        data=json.dumps({"args": list(args)}).encode(),
        headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "{}")


def php(code):
    r = subprocess.run(["php", "-r", 'require "config/config.php"; ' + code],
                       cwd=ROOT, capture_output=True, text=True)
    return ((r.stdout or "") + (r.stderr or "")).strip()


def db_password(username):
    return php(f'echo db_val("SELECT password FROM users WHERE username = \'{username}\'");')


def is_hash(v):
    return v.startswith("$2y$") or v.startswith("$2a$") or v.startswith("$argon2")


def check(step, cond, info=""):
    line = f"{'PASS' if cond else 'FAIL'}  {step}"
    if info:
        line += f"  ({info})"
    print(line)
    (passed if cond else failed).append(step)


# ---------------------------------------------------------------- admin default
pw = db_password("admin")
check("password admin tersimpan sebagai hash", is_hash(pw), pw[:18] + "...")
check("password admin bukan plain text", pw != "admin123")

_, lg = call("login", "admin", "admin123", UA)
tok = lg.get("token", "")
check("login admin dengan hash", lg.get("success") is True, lg.get("message", ""))

_, bad = call("login", "admin", "admin12", UA)
check("password salah ditolak", bad.get("success") is False, bad.get("message", ""))

# ---------------------------------------------------------------- user baru
call("saveUser", "admin", tok, {"username": STUDENT, "nama_lengkap": "Siswa Hash", "role": "siswa",
                                "password": SPASS, "kelas": "XII IPA 1", "nisn": "99990003"})
spw = db_password(STUDENT)
check("password user baru berupa hash", is_hash(spw), spw[:18] + "...")

_, sl = call("login", STUDENT, SPASS, UA)
stok = sl.get("token", "")
check("user baru bisa login", sl.get("success") is True, sl.get("message", ""))

# ---------------------------------------------------------------- kartu ujian
_, cards = call("getStudentExamCards", "admin", tok)
mine = [c for c in (cards.get("students") or []) if c.get("username") == STUDENT]
check("kartu ujian tidak membocorkan password",
      bool(mine) and mine[0].get("password") == "" and mine[0].get("password_available") is False,
      f"password_available={(mine[0].get('password_available') if mine else '-')}")

# ---------------------------------------------------------------- update user tanpa ganti password
call("saveUser", "admin", tok, {"id_user": php(f'echo db_val("SELECT id_user FROM users WHERE username = \'{STUDENT}\'");'),
                                "username": STUDENT, "nama_lengkap": "Siswa Hash Diubah", "role": "siswa",
                                "password": "", "kelas": "XII IPA 2", "nisn": "99990003"})
spw2 = db_password(STUDENT)
check("password tidak berubah saat form dikosongkan", spw2 == spw)

_, sl2 = call("login", STUDENT, SPASS, UA)
stok = sl2.get("token", "")
check("login tetap berhasil setelah edit", sl2.get("success") is True, sl2.get("message", ""))

# ---------------------------------------------------------------- changePassword
_, cp_bad = call("changePassword", STUDENT, stok, "salahbanget", "baru12345", "baru12345")
check("ganti password: password lama salah ditolak", cp_bad.get("success") is False, cp_bad.get("message", ""))

_, cp = call("changePassword", STUDENT, stok, SPASS, "baru12345", "baru12345")
check("ganti password berhasil", cp.get("success") is True, cp.get("message", ""))
check("password baru tersimpan sebagai hash", is_hash(db_password(STUDENT)))

_, old_login = call("login", STUDENT, SPASS, UA)
check("password lama tidak berlaku lagi", old_login.get("success") is False, old_login.get("message", ""))

_, new_login = call("login", STUDENT, "baru12345", UA)
stok = new_login.get("token", "")
check("password baru bisa dipakai login", new_login.get("success") is True, new_login.get("message", ""))

# ---------------------------------------------------------------- data lama (plain text)
php(f'echo db_exec("UPDATE users SET password = ?, password_enc = ? WHERE username = ?", ["lama123", "", "{STUDENT}"]);')
_, legacy = call("login", STUDENT, "lama123", UA)
stok = legacy.get("token", "")
check("password plain text lama masih bisa login", legacy.get("success") is True, legacy.get("message", ""))
check("plain text otomatis di-upgrade menjadi hash setelah login", is_hash(db_password(STUDENT)))

# ---------------------------------------------------------------- import
_, imp = call("importUsers", "admin", tok, [{"username": "siswaimport", "nama": "Siswa Import",
                                             "password": "impor123", "role": "siswa",
                                             "kelas": "XII IPA 1", "sesi": "Sesi 1"}])
check("impor pengguna berhasil", imp.get("success") is True, imp.get("message", ""))
check("password hasil impor berupa hash", is_hash(db_password("siswaimport")))

# ---------------------------------------------------------------- tools CLI
php(f'echo db_exec("UPDATE users SET password = ?, password_enc = ? WHERE username = ?", ["lama456", "", "siswaimport"]);')
out = subprocess.run(["php", "tools/migrate-passwords.php", "--dry-run"],
                     cwd=ROOT, capture_output=True, text=True).stdout
check("tools: dry-run melaporkan konversi", "siswaimport" in out and "dry-run" in out,
      out.strip().splitlines()[-2] if out.strip() else "")
check("tools: dry-run tidak mengubah apa pun", not is_hash(db_password("siswaimport")))

out = subprocess.run(["php", "tools/migrate-passwords.php"],
                     cwd=ROOT, capture_output=True, text=True).stdout
check("tools: konversi plain text berjalan", is_hash(db_password("siswaimport")), out.strip().splitlines()[-2])

_, imp_login = call("login", "siswaimport", "lama456", UA)
check("password hasil migrasi masih bisa dipakai", imp_login.get("success") is True, imp_login.get("message", ""))

# ---------------------------------------------------------------- reset manual (lupa password)
out = subprocess.run(["php", "tools/migrate-passwords.php", "--reset", STUDENT, "reset12345"],
                     cwd=ROOT, capture_output=True, text=True).stdout
check("tools: reset password manual", is_hash(db_password(STUDENT)), out.strip().splitlines()[-1])
_, rl = call("login", STUDENT, "reset12345", UA)
check("login dengan password hasil reset", rl.get("success") is True, rl.get("message", ""))

# ---------------------------------------------------------------- bersihkan
call("deleteUser", "admin", tok, php(f'echo db_val("SELECT id_user FROM users WHERE username = \'{STUDENT}\'");'))
call("deleteUser", "admin", tok, php('echo db_val("SELECT id_user FROM users WHERE username = \'siswaimport\'");'))
check("akun uji dibersihkan", db_password(STUDENT) == "")

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal:", ", ".join(failed))
    raise SystemExit(1)
