#!/usr/bin/env python
"""Uji fitur unggah gambar ke database (upload.php + media.php + url_gambar/logo_url)."""
import json
import uuid
import urllib.request
import urllib.error
import sys

BASE = "http://localhost/cbt-gas-php/"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"

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
    body = b"".join(parts)
    req = urllib.request.Request(BASE + "upload.php", data=body, method="POST",
                                 headers={"Content-Type": "multipart/form-data; boundary=" + boundary})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "{}")


def fetch(url_path):
    with urllib.request.urlopen(urllib.request.Request(BASE + url_path, method="GET"), timeout=60) as r:
        return r.status, r.headers.get("Content-Type"), r.read()


def check(step, cond, info=""):
    line = f"{'PASS' if cond else 'FAIL'}  {step}"
    if info:
        line += f"  ({info})"
    print(line)
    (passed if cond else failed).append(step)


def png_bytes(seed=0):
    """PNG 1x1 valid dengan warna berbeda per seed (byte unik agar dedupe tidak bentrok)."""
    base = bytes.fromhex(
        "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
        "0000000d4944415478da63f8ffff3f0005fe02fea735c8d70000000049454e44ae426082")
    return base + bytes([seed]) if seed else base


# ---------------------------------------------------------------- login
_, lg = call("login", "admin", "admin123", UA)
tok = lg.get("token", "")
check("login admin", lg.get("success") is True)

# Batas minimal pengerjaan bersifat global; uji ini mengumpulkan jawaban segera,
# jadi set ke 0 sementara lalu pulihkan di akhir.
_orig_settings = call("getSettings", "admin", tok)[1].get("settings", {})
call("saveSettings", "admin", tok, {"min_pengerjaan_ujian": "0", "tampilkan_pembahasan": "Ya"})

# ---------------------------------------------------------------- upload
code, up = upload("admin", tok, "soal.png", png_bytes(1))
check("upload gambar soal", code == 200 and up.get("success") is True, up.get("url", ""))
media_url = up.get("url", "")
media_id = up.get("id", "")

code, dup = upload("admin", tok, "soal.png", png_bytes(1))
check("dedupe berkas identik", dup.get("id") == media_id, f"id sama={dup.get('id') == media_id}")

code, up2 = upload("admin", tok, "soal2.png", png_bytes(2))
check("upload gambar kedua", code == 200 and up2.get("success") is True, up2.get("url", ""))
media_url2 = up2.get("url", "")

code, bad = upload("admin", tok, "virus.png", b"<?php echo 'x'; ?>", "image/png")
check("tolak berkas bukan gambar", bad.get("success") is False, bad.get("message", ""))
code, nosession = upload("admin", "TKN-salah", "a.png", png_bytes(1))
check("tolak upload tanpa sesi", nosession.get("success") is False, nosession.get("message", ""))

# ---------------------------------------------------------------- simpan ke soal
_, exam = call("saveExam", "admin", tok, {
    "nama_ujian": "Ujian Gambar", "durasi_menit": 30,
    "waktu_mulai": "2026-01-01T07:00", "waktu_selesai": "2026-12-31T23:59",
    "acak_soal": "Tidak", "acak_opsi": "Tidak", "token_ujian": "",
    "kelas": "Semua", "sesi": "Semua", "tipe_peserta": "general",
    "jumlah_soal_tampil": 0, "jumlah_percobaan": 1, "max_violations": 3,
    "tindakan_pelanggaran": "auto_submit", "tampilkan_pembahasan": "Ya"})
exam_id = exam.get("id_ujian", "")
check("buat ujian uji gambar", bool(exam_id), exam_id)

_, q = call("saveQuestion", "admin", tok, {
    "id_soal": "", "id_ujian": exam_id, "teks_soal": "Soal bergambar",
    "url_gambar": media_url, "opsi_a": "A", "opsi_b": "B", "opsi_c": "C",
    "opsi_d": "D", "opsi_e": "", "kunci_jawaban": "A", "bobot": 1})
check("simpan soal dengan gambar database", q.get("success") is True)

_, qs = call("getExamQuestions", "admin", tok, exam_id)
q0 = (qs.get("questions") or [{}])[0]
check("url_gambar tersimpan apa adanya", q0.get("url_gambar") == media_url, q0.get("url_gambar", ""))

# ---------------------------------------------------------------- serve & akses siswa
st, ctype, body = fetch(media_url)
check("media.php menyajikan gambar", st == 200 and ctype == "image/png" and body == png_bytes(1),
      f"{st} {ctype} {len(body)}B")

uname = "siswaimg"
# Pastikan akun siswa uji selalu ada dengan password yang diketahui.
call("saveUser", "admin", tok, {"username": uname, "password": "siswa123",
                                "nama_lengkap": "Siswa Gambar", "role": "Siswa",
                                "kelas": "Semua", "tingkat": "X",
                                "status_aktif": "Aktif", "sesi": "Semua"})
_, sl = call("login", uname, "siswa123", UA)
if not sl.get("success"):
    _, users = call("getUsers", "admin", tok)
    rec = next((u for u in users.get("users", []) if u.get("username") == uname), None)
    if rec:
        call("saveUser", "admin", tok, {"id_user": rec["id_user"], "username": uname,
                                        "password": "siswa123", "nama_lengkap": "Siswa Gambar",
                                        "role": "Siswa", "kelas": "Semua", "tingkat": "X",
                                        "status_aktif": "Aktif", "sesi": "Semua"})
    _, sl = call("login", uname, "siswa123", UA)
stok = sl.get("token", "")
check("login siswa uji", sl.get("success") is True)

_, start = call("startStudentExam", uname, stok, exam_id, "")
sq = (start.get("questions") or [{}])[0]
check("gambar tampil pada lembar ujian siswa", sq.get("url_gambar") == media_url, sq.get("url_gambar", ""))

_, ans = call("submitExam", uname, stok, {
    "id_ujian": exam_id, "answers": {q0["id_soal"]: "A"}, "violations": 0, "percobaan_ke": 1})
check("submit ujian bergambar", ans.get("success") is True, f"score={ans.get('score')}")

_, review = call("getStudentExamReview", uname, stok, exam_id)
rq = (review.get("questions") or [{}])[0]
check("gambar muncul di pembahasan siswa", rq.get("url_gambar") == media_url, rq.get("url_gambar", ""))

# ---------------------------------------------------------------- ganti gambar soal
_, edit = call("saveQuestion", "admin", tok, {
    "id_soal": q0["id_soal"], "id_ujian": exam_id, "teks_soal": "Soal bergambar (ganti)",
    "url_gambar": media_url2, "opsi_a": "A", "opsi_b": "B", "opsi_c": "C",
    "opsi_d": "D", "opsi_e": "", "kunci_jawaban": "A", "bobot": 1})
check("ganti gambar soal", edit.get("success") is True)

try:
    fetch(media_url)
    check("media lama dihapus setelah ganti gambar", False, "masih bisa diakses")
except urllib.error.HTTPError as e:
    check("media lama dihapus setelah ganti gambar", e.code == 404, f"HTTP {e.code}")

st, _, _ = fetch(media_url2)
check("media baru tetap tersedia", st == 200)

# ---------------------------------------------------------------- logo sekolah
_, s0 = call("getSettings")
logo_lama = s0.get("settings", {}).get("logo_url", "")
code, logo_up = upload("admin", tok, "logo.png", png_bytes(3))
check("upload logo sekolah", logo_up.get("success") is True, logo_up.get("url", ""))
_, sv = call("saveSettings", "admin", tok, {"logo_url": logo_up.get("url", "")})
check("simpan logo_url", sv.get("success") is True)
_, s1 = call("getSettings")
check("logo_url tersimpan", s1["settings"]["logo_url"] == logo_up.get("url"), s1["settings"]["logo_url"]

      )
st, ctype, _ = fetch(logo_up.get("url", ""))
check("logo dapat disajikan", st == 200 and ctype == "image/png", f"{st} {ctype}")

# media dipakai soal -> tetap ada saat logo diganti
_, sv2 = call("saveSettings", "admin", tok, {"logo_url": ""})
check("kosongkan logo_url", sv2.get("success") is True)
call("saveSettings", "admin", tok, {"logo_url": logo_lama} if logo_lama else {"logo_url": ""})

# ---------------------------------------------------------------- hapus soal membersihkan media
_, dq = call("deleteQuestion", "admin", tok, q0["id_soal"])
check("hapus soal", dq.get("success") is True)
try:
    fetch(media_url2)
    check("media terhapus bersama soal", False, "masih bisa diakses")
except urllib.error.HTTPError as e:
    check("media terhapus bersama soal", e.code == 404, f"HTTP {e.code}")

# ---------------------------------------------------------------- upload ditolak untuk siswa
code, su = upload(uname, stok, "x.png", png_bytes(4))
check("siswa tidak boleh upload", code == 403 and su.get("success") is False, su.get("message", ""))

# ---------------------------------------------------------------- bersihkan
call("deleteExam", "admin", tok, exam_id)
call("saveSettings", "admin", tok,
     {"min_pengerjaan_ujian": _orig_settings.get("min_pengerjaan_ujian", "0"),
      "tampilkan_pembahasan": _orig_settings.get("tampilkan_pembahasan", "Tidak")})

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal: " + ", ".join(failed))
sys.exit(1 if failed else 0)
