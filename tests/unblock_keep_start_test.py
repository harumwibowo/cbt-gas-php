#!/usr/bin/env python
"""Uji: buka blokir TIDAK mereset waktu_mulai, hanya menggeser deadline_at.

Skenario mengikuti laporan lapangan: siswa dengan pengaturan "minimal pengerjaan"
terblokir di tengah ujian, lalu blokirnya dibuka. Setelah perubahan ini:
  - waktu_mulai tetap (batas minimal pengerjaan tidak dihitung ulang dari nol),
  - deadline_at digeser sebesar durasi blokir (waktu siswa tidak hangus),
  - submit tetap ditolak server selama minimal pengerjaan belum terpenuhi.
"""
import json
import subprocess
import time
import urllib.error
import urllib.request

BASE = "http://localhost/cbt-gas-php/"
API = BASE + "api.php?action="
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"
STUDENT = "siswaunb"
SPASS = "siswa123"
APP_DIR = "C:/laragon87/www/cbt-gas-php"

passed, failed = [], []


def call(action, *args):
    req = urllib.request.Request(
        API + action,
        data=json.dumps({"args": list(args)}).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "{}")


def check(step, cond, info=""):
    line = f"{'PASS' if cond else 'FAIL'}  {step}"
    if info:
        line += f"  ({info})"
    print(line)
    (passed if cond else failed).append(step)


def php(sql):
    """Jalankan SQL kecil lewat konfigurasi DB aplikasi."""
    code = (
        "require 'config/config.php';"
        "db_exec(" + json.dumps(sql) + ");"
        "echo db_val('SELECT CONCAT(waktu_mulai, \"|\", deadline_at) FROM results "
        "WHERE id_ujian = ? AND username_siswa = ?', ["
        + json.dumps(EXAM_ID) + "," + json.dumps(STUDENT) + "], 'kosong');"
    )
    return subprocess.run(["php", "-r", code], cwd=APP_DIR, capture_output=True, text=True)


def ts(value):
    return time.mktime(time.strptime(value, "%Y-%m-%d %H:%M:%S"))


EXAM_ID = ""

# ---------------------------------------------------------------- login admin
_, lg = call("login", "admin", "admin123", UA)
tok = lg.get("token", "")
check("login admin", lg.get("success") is True, lg.get("message", ""))

_, st0 = call("getSettings", "admin", tok)
settings_before = st0.get("settings", {}) or {}

# Kebijakan yang dibutuhkan skenario ini.
call("saveSettings", "admin", tok, {
    "durasi_ujian": "30",
    "min_pengerjaan_ujian": "1",
    "jumlah_percobaan": "1",
    "allowed_student_browsers": "",
})
_, st1 = call("getSettings", "admin", tok)
s1 = st1.get("settings", {}) or {}
check("minimal pengerjaan 1 menit aktif", str(s1.get("min_pengerjaan_ujian")) == "1",
      f"nilai={s1.get('min_pengerjaan_ujian')}, durasi={s1.get('durasi_ujian')}")

# ---------------------------------------------------------------- user & ujian
call("saveUser", "admin", tok, {"username": STUDENT, "nama_lengkap": "Siswa Uji Blokir",
                                "role": "siswa", "password": SPASS, "kelas": "XII IPA 1",
                                "nisn": "99990002"})

_, exam = call("saveExam", "admin", tok, {
    "nama_ujian": "Ujian Uji Buka Blokir", "durasi_menit": 30,
    "waktu_mulai": "2026-01-01T07:00", "waktu_selesai": "2026-12-31T23:59",
    "acak_soal": "Tidak", "acak_opsi": "Tidak", "token_ujian": "",
    "kelas": "Semua", "sesi": "Semua", "tipe_peserta": "general",
    "jumlah_soal_tampil": 0, "jumlah_percobaan": 1,
})
EXAM_ID = exam.get("id_ujian", "")
check("buat ujian", bool(EXAM_ID), EXAM_ID)

for i in range(2):
    call("saveQuestion", "admin", tok, {
        "id_soal": "", "id_ujian": EXAM_ID, "teks_soal": f"Soal {i+1}", "url_gambar": "",
        "opsi_a": "A", "opsi_b": "B", "opsi_c": "C", "opsi_d": "D", "opsi_e": "",
        "kunci_jawaban": "A", "bobot": 1})

# ---------------------------------------------------------------- mulai ujian
_, sl = call("login", STUDENT, SPASS, UA)
stok = sl.get("token", "")
check("login siswa", sl.get("success") is True, sl.get("message", ""))

_, start1 = call("startStudentExam", STUDENT, stok, EXAM_ID, "")
d1 = start1.get("draft") or {}
sisa1 = int(start1.get("min_pengerjaan_sisa", -1))
check("mulai ujian & sisa minimal dikirim server", start1.get("success") is True and sisa1 > 0,
      f"sisa={sisa1}s, mulai={d1.get('waktu_mulai')}, deadline={d1.get('deadline_at')}")

# ---------------------------------------------------------------- blokir -> tunggu -> buka
time.sleep(3)
_, bl = call("blockStudentExam", STUDENT, stok, {"id_ujian": EXAM_ID, "violations": 3,
                                                 "answers": {}, "percobaan_ke": 1})
check("blokir ujian", bl.get("success") is True, bl.get("message", ""))

time.sleep(4)
_, ub = call("unblockStudentExam", "admin", tok, EXAM_ID, STUDENT)
check("buka blokir", ub.get("success") is True, ub.get("message", ""))

# ---------------------------------------------------------------- lanjutkan ujian
_, start2 = call("startStudentExam", STUDENT, stok, EXAM_ID, "")
d2 = start2.get("draft") or {}
sisa2 = int(start2.get("min_pengerjaan_sisa", -1))
check("siswa bisa melanjutkan setelah buka blokir", start2.get("success") is True,
      start2.get("message", ""))

def norm_waktu(value):
    """Samakan format: respons percobaan baru memakai 'Y-m-d H:i:s', sedangkan
    respons resume memakai ISO tanpa detik ('Y-m-dTH:i')."""
    return str(value or "").replace("T", " ")[:16]


check("waktu_mulai TIDAK direset saat buka blokir",
      bool(d1.get("waktu_mulai")) and norm_waktu(d1.get("waktu_mulai")) == norm_waktu(d2.get("waktu_mulai")),
      f"sebelum={d1.get('waktu_mulai')} sesudah={d2.get('waktu_mulai')}")

try:
    geser = ts(d2.get("deadline_at", "")) - ts(d1.get("deadline_at", ""))
except Exception:
    geser = -999
check("deadline digeser sebesar durasi blokir", 3 <= geser <= 15, f"geser={geser} detik")

check("sisa minimal pengerjaan TIDAK kembali ke awal", 0 <= sisa2 < sisa1,
      f"sebelum={sisa1}s sesudah={sisa2}s")

# ---------------------------------------------------------------- cek waktu otoritatif
_, tm1 = call("getExamTiming", STUDENT, stok, EXAM_ID, 1)
check("getExamTiming mengembalikan acuan server",
      tm1.get("success") is True and int(tm1.get("min_pengerjaan_sisa", -1)) == sisa2
      and int(tm1.get("deadline_epoch") or 0) > 0 and int(tm1.get("server_epoch") or 0) > 0,
      f"sisa={tm1.get('min_pengerjaan_sisa')}s, menit={tm1.get('min_pengerjaan_menit')}")

_, tm_admin = call("getExamTiming", "admin", tok, EXAM_ID, 1)
check("getExamTiming ditolak untuk admin (khusus siswa)",
      tm_admin.get("success") is not True,
      (tm_admin.get("error") or tm_admin.get("message") or "")[:60])

_, tm_bad = call("getExamTiming", STUDENT, "TKN-salah", EXAM_ID, 1)
check("getExamTiming menolak token salah", tm_bad.get("success") is not True,
      (tm_bad.get("message") or "")[:60])

questions = start2.get("questions") or []
answers = {q["id_soal"]: "A" for q in questions}
_, sub1 = call("submitExam", STUDENT, stok, {
    "id_ujian": EXAM_ID, "answers": answers, "violations": 0, "percobaan_ke": 1})
check("submit tetap ditolak selama minimal pengerjaan belum terpenuhi",
      sub1.get("success") is not True and "minimal pengerjaan" in str(sub1.get("message", "")).lower(),
      (sub1.get("message") or "")[:90])
check("pesan penolakan membawa sisa waktu otoritatif untuk sinkronisasi klien",
      sub1.get("min_pengerjaan_sisa") is not None and int(sub1.get("min_pengerjaan_sisa")) > 0
      and int(sub1.get("server_epoch") or 0) > 0,
      f"sisa={sub1.get('min_pengerjaan_sisa')}s")

# ---------------------------------------------------------------- minimal sudah lewat
out = php("UPDATE results SET waktu_mulai = DATE_SUB(NOW(), INTERVAL 5 MINUTE) "
          f"WHERE id_ujian = {json.dumps(EXAM_ID)} AND username_siswa = {json.dumps(STUDENT)}")
check("(setup) waktu_mulai ujian dimundurkan 5 menit", out.returncode == 0, (out.stderr or out.stdout)[:120])

_, start3 = call("startStudentExam", STUDENT, stok, EXAM_ID, "")
sisa3 = int(start3.get("min_pengerjaan_sisa", -1))
check("setelah minimal terlampaui, tombol kirim terbuka (sisa 0)", sisa3 == 0, f"sisa={sisa3}s")

_, tm3 = call("getExamTiming", STUDENT, stok, EXAM_ID, 1)
check("getExamTiming konfirmasi minimal sudah terpenuhi", int(tm3.get("min_pengerjaan_sisa", -1)) == 0,
      f"sisa={tm3.get('min_pengerjaan_sisa')}s")

questions = start3.get("questions") or []
answers = {q["id_soal"]: "A" for q in questions}
_, sub2 = call("submitExam", STUDENT, stok, {
    "id_ujian": EXAM_ID, "answers": answers, "violations": 0, "percobaan_ke": 1})
check("submit berhasil setelah minimal pengerjaan terpenuhi", sub2.get("success") is True,
      f"nilai={sub2.get('score')}"

      )

# ---------------------------------------------------------------- bersihkan
call("deleteExam", "admin", tok, EXAM_ID)
_, _usr = call("getUsers", "admin", tok)
_rec = next((u for u in (_usr.get("users") or []) if u.get("username") == STUDENT), None)
if _rec:
    call("deleteUser", "admin", tok, _rec.get("id_user"))
restore = {k: settings_before.get(k) for k in (
    "durasi_ujian", "min_pengerjaan_ujian", "jumlah_percobaan", "allowed_student_browsers")}
call("saveSettings", "admin", tok, {k: v for k, v in restore.items() if v is not None})
_, st2 = call("getSettings", "admin", tok)
s2 = st2.get("settings", {}) or {}
check("pengaturan dipulihkan", str(s2.get("min_pengerjaan_ujian")) == str(settings_before.get("min_pengerjaan_ujian")),
      f"min={s2.get('min_pengerjaan_ujian')}, durasi={s2.get('durasi_ujian')}")

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal:", ", ".join(failed))
    raise SystemExit(1)
