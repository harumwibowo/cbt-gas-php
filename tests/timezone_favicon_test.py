#!/usr/bin/env python
"""Uji zona waktu (WIB/WITA/WIT) & favicon dari logo sekolah.

Fokus utama: jadwal ujian harus tetap benar meski zona waktu server berbeda,
karena zona waktu aplikasi selalu diambil dari pengaturan (bukan dari OS/PHP).
"""
import json
import uuid
import urllib.request
import urllib.error
from datetime import datetime, timedelta, timezone

BASE = "http://localhost/cbt-gas-php/"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"
STUDENT = "siswatime"
SPASS = "siswa123"

ZONES = {"WIB": 7, "WITA": 8, "WIT": 9}  # offset jam terhadap UTC

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


def upload(username, token, filename, content, mime="image/png"):
    boundary = "----cbt" + uuid.uuid4().hex
    parts = []
    for field, value in (("username", username), ("token", token)):
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"\r\n\r\n{value}\r\n'.encode())
    parts.append(
        f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\n'
        f'Content-Type: {mime}\r\n\r\n'.encode() + content + b"\r\n")
    parts.append(f"--{boundary}--\r\n".encode())
    req = urllib.request.Request(BASE + "upload.php", data=b"".join(parts), method="POST",
                                 headers={"Content-Type": "multipart/form-data; boundary=" + boundary})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode())


def head(path, headers=None):
    req = urllib.request.Request(BASE + path, method="GET", headers=headers or {})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.status, dict(r.headers), r.read()


def check(step, cond, info=""):
    line = f"{'PASS' if cond else 'FAIL'}  {step}"
    if info:
        line += f"  ({info})"
    print(line)
    (passed if cond else failed).append(step)


def wall_clock(zone_label, delta_minutes=0):
    """Waktu dinding (naive) di zona tertentu, `delta_minutes` dari sekarang."""
    off = ZONES[zone_label]
    t = datetime.now(timezone.utc) + timedelta(minutes=delta_minutes) + timedelta(hours=off)
    return t.strftime("%Y-%m-%dT%H:%M")


def png_1x1():
    return bytes.fromhex(
        "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
        "0000000d4944415478da63f8ffff3f0005fe02fea735c8d70000000049454e44ae426082")


def settings():
    _, r = call("getSettings", "admin", tok)
    return r


# ---------------------------------------------------------------- login
_, lg = call("login", "admin", "admin123", UA)
tok = lg.get("token", "")
check("login admin", lg.get("success") is True)

# Batas minimal pengerjaan bersifat global; uji ini langsung mengumpulkan jawaban,
# jadi set ke 0 sementara lalu pulihkan di bagian bersih-bersih.
_orig_settings = call("getSettings", "admin", tok)[1].get("settings", {})
call("saveSettings", "admin", tok, {"min_pengerjaan_ujian": "0"})

# ---------------------------------------------------------------- default WIB
g = settings()
s = g.get("settings", {})
tz = g.get("timezone", {})
check("pengaturan zona_waktu default WIB", s.get("zona_waktu") == "WIB", f"nilai={s.get('zona_waktu')}")
check("info zona waktu: WIB = Asia/Jakarta", tz.get("iana") == "Asia/Jakarta" and tz.get("offset") == "+07:00",
      f"{tz.get('iana')} / {tz.get('offset')}")

# ---------------------------------------------------------------- ganti zona
for label, iana, off in (("WITA", "Asia/Makassar", "+08:00"), ("WIT", "Asia/Jayapura", "+09:00")):
    _, sv = call("saveSettings", "admin", tok, {"zona_waktu": label})
    g = settings()
    check(f"simpan zona {label}", sv.get("success") is True, sv.get("message", ""))
    check(f"{label} tersimpan", g["settings"].get("zona_waktu") == label, g["settings"].get("zona_waktu"))
    check(f"{label} -> {iana} {off}", g["timezone"].get("iana") == iana and g["timezone"].get("offset") == off,
          f"{g['timezone'].get('iana')} / {g['timezone'].get('offset')}")

call("saveSettings", "admin", tok, {"zona_waktu": "WIB"})
check("kembali ke WIB", settings()["settings"].get("zona_waktu") == "WIB")

# nilai tidak valid dinormalkan
call("saveSettings", "admin", tok, {"zona_waktu": "Mars/Olympus"})
check("zona tidak valid -> WIB", settings()["settings"].get("zona_waktu") == "WIB")
call("saveSettings", "admin", tok, {"zona_waktu": "Asia/Makassar"})
check("nama IANA diterima (Asia/Makassar -> WITA)", settings()["settings"].get("zona_waktu") == "WITA")

# ---------------------------------------------------------------- user & ujian
call("saveUser", "admin", tok, {"username": STUDENT, "nama_lengkap": "Siswa Zona Waktu", "role": "siswa",
                                "password": SPASS, "kelas": "XII IPA 1", "nisn": "99990002"})

# Ujian dengan jendela waktu di zona WIT (UTC+9). Jika server masih memakai
# WIB (UTC+7), "sekarang" akan 2 jam lebih awal → ujian dianggap belum dimulai.
call("saveSettings", "admin", tok, {"zona_waktu": "WIT"})
mulai_wit = wall_clock("WIT", -5)
selesai_wit = wall_clock("WIT", 60)

_, exam = call("saveExam", "admin", tok, {
    "nama_ujian": "Ujian Zona Waktu", "durasi_menit": 30,
    "waktu_mulai": mulai_wit, "waktu_selesai": selesai_wit,
    "acak_soal": "Tidak", "acak_opsi": "Tidak", "token_ujian": "",
    "kelas": "Semua", "sesi": "Semua", "tipe_peserta": "general",
    "jumlah_soal_tampil": 0, "jumlah_percobaan": 2, "tampilkan_pembahasan": "Ya"})
exam_id = exam.get("id_ujian", "")
check("buat ujian berjadwal (jam dinding WIT)", bool(exam_id), f"{mulai_wit} .. {selesai_wit}")

call("saveQuestion", "admin", tok, {
    "id_soal": "", "id_ujian": exam_id, "teks_soal": "Soal zona waktu", "url_gambar": "",
    "opsi_a": "A", "opsi_b": "B", "opsi_c": "C", "opsi_d": "D", "opsi_e": "", "kunci_jawaban": "A", "bobot": 1})

_, qs = call("getExamQuestions", "admin", tok, exam_id)
back = ((qs.get("questions") or [{}])[0]).get("id_ujian", "")
_, exs = call("getTeacherExams", "admin", tok)
mine = [e for e in (exs.get("exams") or []) if e.get("id_ujian") == exam_id] or [{}]
check("jam ujian tersimpan apa adanya (jam dinding, tidak digeser zona)",
      str(mine[0].get("waktu_mulai", "")).replace(" ", "T")[:16] == mulai_wit,
      f"{mine[0].get('waktu_mulai')} vs {mulai_wit}")

# ---------------------------------------------------------------- siswa mulai ujian
_, sl = call("login", STUDENT, SPASS, UA)
stok = sl.get("token", "")
check("login siswa", sl.get("success") is True, sl.get("message", ""))

_, qs2 = call("getExamQuestions", "admin", tok, exam_id)
q_id = ((qs2.get("questions") or [{}])[0]).get("id_soal", "")
_, st = call("startStudentExam", STUDENT, stok, exam_id, "", "")
check("ujian berjalan tepat waktu pada zona WIT", st.get("success") is True, st.get("message", ""))
if st.get("success"):
    call("autosaveExam", STUDENT, stok, {"id_ujian": exam_id, "answers": {q_id: "A"}, "violations": 0})
    _, sub = call("submitExam", STUDENT, stok, {"id_ujian": exam_id, "answers": {q_id: "A"},
                                                "violations": 0, "percobaan_ke": 1})
    check("submit tepat waktu", sub.get("success") is True, sub.get("message", ""))

# ---------------------------------------------------------------- konsistensi jam log
g = settings()
tz_now = g["timezone"].get("sekarang", "")
utc_now = datetime.now(timezone.utc) + timedelta(hours=9)
try:
    drift = abs((datetime.strptime(tz_now, "%Y-%m-%d %H:%M:%S") - utc_now.replace(tzinfo=None)).total_seconds())
except Exception as e:
    drift = 99999
check("jam PHP mengikuti zona WIT (UTC+9)", drift < 300, f"drift={int(drift)}s jam={tz_now}")

# ---------------------------------------------------------------- ganti zona: jam ujian tetap
call("saveSettings", "admin", tok, {"zona_waktu": "WIB"})
_, exs2 = call("getTeacherExams", "admin", tok)
mine2 = [e for e in (exs2.get("exams") or []) if e.get("id_ujian") == exam_id] or [{}]
check("setelah ganti ke WIB jam ujian tidak bergeser",
      str(mine2[0].get("waktu_mulai", "")).replace(" ", "T")[:16] == mulai_wit,
      f"{mine2[0].get('waktu_mulai')}")
g = settings()
utc_now7 = datetime.now(timezone.utc) + timedelta(hours=7)
try:
    drift7 = abs((datetime.strptime(g["timezone"].get("sekarang", ""), "%Y-%m-%d %H:%M:%S")
                  - utc_now7.replace(tzinfo=None)).total_seconds())
except Exception:
    drift7 = 99999
check("jam PHP kembali mengikuti WIB (UTC+7)", drift7 < 300, f"drift={int(drift7)}s")

# ---------------------------------------------------------------- favicon
# Logo bisa saja sudah diisi oleh uji lain; kosongkan dulu agar kondisi "tanpa
# logo" benar-benar teruji, lalu pulihkan nilai aslinya di akhir.
_orig_logo = settings()["settings"].get("logo_url", "")
call("saveSettings", "admin", tok, {"logo_url": ""})
code, hdr, body = head("favicon.php")
check("favicon tanpa logo -> ikon bawaan SVG",
      code == 200 and "image/svg" in hdr.get("Content-Type", ""), hdr.get("Content-Type", ""))
etag_default = hdr.get("ETag", "")
check("favicon punya ETag", etag_default != "")

try:
    req = urllib.request.Request(BASE + "favicon.php", method="GET", headers={"If-None-Match": etag_default})
    with urllib.request.urlopen(req, timeout=30) as r:
        code304 = r.status
except urllib.error.HTTPError as e:
    code304 = e.code
check("favicon mendukung 304 (cache)", code304 == 304, f"status={code304}")

up = upload("admin", tok, "logo.png", png_1x1())
call("saveSettings", "admin", tok, {"logo_url": up.get("url", "")})
code, hdr, body = head("favicon.php")
check("favicon memakai logo sekolah", code == 200 and "image/png" in hdr.get("Content-Type", ""),
      hdr.get("Content-Type", ""))
check("favicon ringan (< 64 KB)", len(body) < 64 * 1024, f"{len(body)} byte")
check("favicon berbeda setelah logo diganti", hdr.get("ETag", "") != etag_default)

html = head("index.php")[2].decode("utf-8", "replace")
check("index.php memuat <link rel=\"icon\">", html.count('data-app-favicon') == 2,
      f"jumlah={html.count('data-app-favicon')}")
check("link ikon menunjuk favicon.php", 'rel="icon" type="image/png" href="favicon.php?v=' in html)

call("saveSettings", "admin", tok, {"logo_url": ""})
code, hdr, body = head("favicon.php")
check("logo dihapus -> ikon bawaan lagi",
      code == 200 and "image/svg" in hdr.get("Content-Type", ""), hdr.get("Content-Type", ""))

# ---------------------------------------------------------------- bersihkan
call("deleteExam", "admin", tok, exam_id)
_, _users = call("getUsers", "admin", tok)
_rec = next((u for u in (_users.get("users") or []) if u.get("username") == STUDENT), None)
if _rec:
    call("deleteUser", "admin", tok, _rec.get("id_user"))
call("saveSettings", "admin", tok,
     {"zona_waktu": "WIB",
      "logo_url": _orig_logo if _orig_logo else "",
      "min_pengerjaan_ujian": str(_orig_settings.get("min_pengerjaan_ujian", "0"))})

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal:", ", ".join(failed))
    raise SystemExit(1)
