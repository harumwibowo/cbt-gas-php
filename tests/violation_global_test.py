#!/usr/bin/env python
"""Uji kebijakan pelanggaran GLOBAL (settings.max_violations / tindakan_pelanggaran).

Membuktikan bahwa batas maksimal pelanggaran & tindakan setelah batas tercapai
tidak lagi per-ujian, melainkan satu pengaturan global di halaman Pengaturan Admin.
"""
import json
import urllib.request
import urllib.error

BASE = "http://localhost/cbt-gas-php/"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"
STUDENT = "siswaviol"
SPASS = "siswa123"

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


def check(step, cond, info=""):
    line = f"{'PASS' if cond else 'FAIL'}  {step}"
    if info:
        line += f"  ({info})"
    print(line)
    (passed if cond else failed).append(step)


def settings():
    _, r = call("getSettings", "admin", tok)
    return r.get("settings", r) or {}


# ---------------------------------------------------------------- login admin
_, lg = call("login", "admin", "admin123", UA)
tok = lg.get("token", "")
check("login admin", lg.get("success") is True)

# ---------------------------------------------------------------- default global
s = settings()
check("pengaturan global max_violations ada", str(s.get("max_violations")) == "3", f"nilai={s.get('max_violations')}")
check("pengaturan global tindakan_pelanggaran ada", s.get("tindakan_pelanggaran") == "auto_submit",
      f"nilai={s.get('tindakan_pelanggaran')}")

# ---------------------------------------------------------------- ubah global
_, sv = call("saveSettings", "admin", tok, {"max_violations": 5, "tindakan_pelanggaran": "auto_blokir"})
check("simpan pengaturan global", sv.get("success") is True, sv.get("message", ""))
s = settings()
check("nilai global tersimpan", str(s.get("max_violations")) == "5" and s.get("tindakan_pelanggaran") == "auto_blokir",
      f"{s.get('max_violations')}/{s.get('tindakan_pelanggaran')}")

# ---------------------------------------------------------------- validasi nilai
call("saveSettings", "admin", tok, {"max_violations": "abc", "tindakan_pelanggaran": "xyz"})
s = settings()
check("nilai tidak valid dinormalkan (abc -> 3)", str(s.get("max_violations")) == "3", f"nilai={s.get('max_violations')}")
check("tindakan tidak valid dinormalkan (xyz -> auto_submit)", s.get("tindakan_pelanggaran") == "auto_submit",
      f"nilai={s.get('tindakan_pelanggaran')}")
call("saveSettings", "admin", tok, {"max_violations": 5, "tindakan_pelanggaran": "auto_blokir"})

# ---------------------------------------------------------------- user & ujian
call("saveUser", "admin", tok, {"username": STUDENT, "nama_lengkap": "Siswa Violasi", "role": "siswa",
                                "password": SPASS, "kelas": "XII IPA 1", "nisn": "99990001"})

_, exam = call("saveExam", "admin", tok, {
    "nama_ujian": "Ujian Global Pelanggaran", "durasi_menit": 30,
    "waktu_mulai": "2026-01-01T07:00", "waktu_selesai": "2026-12-31T23:59",
    "acak_soal": "Tidak", "acak_opsi": "Tidak", "token_ujian": "",
    "kelas": "Semua", "sesi": "Semua", "tipe_peserta": "general",
    "jumlah_soal_tampil": 0, "jumlah_percobaan": 3,
    # Nilai per-ujian sengaja dikirim BEDA untuk membuktikan sudah diabaikan.
    "max_violations": 7, "tindakan_pelanggaran": "record_only",
    "tampilkan_pembahasan": "Ya"})
exam_id = exam.get("id_ujian", "")
check("buat ujian", bool(exam_id), exam_id)

for i in range(3):
    call("saveQuestion", "admin", tok, {
        "id_soal": "", "id_ujian": exam_id, "teks_soal": f"Soal {i+1}", "url_gambar": "",
        "opsi_a": "A", "opsi_b": "B", "opsi_c": "C", "opsi_d": "D", "opsi_e": "",
        "kunci_jawaban": "A", "bobot": 1})

# ---------------------------------------------------------------- nilai per-ujian diabaikan
_, exs = call("getTeacherExams", "admin", tok)
mine = [e for e in (exs.get("exams") or []) if e.get("id_ujian") == exam_id]
e0 = mine[0] if mine else {}
check("exam list memakai nilai global (bukan 7)", int(e0.get("max_violations") or 0) == 5, f"nilai={e0.get('max_violations')}")
check("exam list memakai tindakan global (bukan record_only)",
      e0.get("tindakan_pelanggaran") == "auto_blokir", f"nilai={e0.get('tindakan_pelanggaran')}")

# ---------------------------------------------------------------- siswa melihat nilai global
_, sl = call("login", STUDENT, SPASS, UA)
stok = sl.get("token", "")
check("login siswa", sl.get("success") is True, sl.get("message", ""))

_, av = call("getStudentAvailableExams", STUDENT, stok)
avail = [e for e in (av.get("exams") or []) if e.get("id_ujian") == exam_id]
a0 = avail[0] if avail else {}
check("daftar ujian siswa memakai nilai global", int(a0.get("max_violations") or 0) == 5,
      f"nilai={a0.get('max_violations')}")
check("daftar ujian siswa memakai tindakan global", a0.get("tindakan_pelanggaran") == "auto_blokir",
      f"nilai={a0.get('tindakan_pelanggaran')}")

_, st = call("startStudentExam", STUDENT, stok, exam_id, "", "")
ex = st.get("exam") or {}
check("mulai ujian", st.get("success") is True, st.get("message", ""))
check("objek ujian runtime memakai batas global", int(ex.get("max_violations") or 0) == 5,
      f"nilai={ex.get('max_violations')}")
check("objek ujian runtime memakai tindakan global", ex.get("tindakan_pelanggaran") == "auto_blokir",
      f"nilai={ex.get('tindakan_pelanggaran')}")

# ---------------------------------------------------------------- blokir & buka blokir
_, rv = call("recordViolation", STUDENT, stok, {"id_ujian": exam_id, "jenis": "Tab Switch",
                                                  "keterangan": "Uji pelanggaran global"})
check("catat pelanggaran", rv.get("success") is True, rv.get("message", ""))

_, bl = call("blockStudentExam", STUDENT, stok, {"id_ujian": exam_id, "violations": 5,
                                                 "answers": {}, "percobaan_ke": 1})
check("blokir ujian siswa", bl.get("success") is True, bl.get("message", ""))

_, ub = call("unblockStudentExam", "admin", tok, exam_id, STUDENT)
check("buka blokir", ub.get("success") is True, ub.get("message", ""))

_, vr = call("getStudentViolations", "admin", tok, exam_id, STUDENT)
check("buka blokir menyisakan (batas global - 1) = 4 pelanggaran", int(vr.get("totalRecorded") or -1) == 4,
      f"nilai={vr.get('totalRecorded')}")

# ---------------------------------------------------------------- ubah batas -> unblock ikut berubah
call("saveSettings", "admin", tok, {"max_violations": 2})
call("blockStudentExam", STUDENT, stok, {"id_ujian": exam_id, "violations": 2,
                                         "answers": {}, "percobaan_ke": 1})
_, ub2 = call("unblockStudentExam", "admin", tok, exam_id, STUDENT)
_, vr2 = call("getStudentViolations", "admin", tok, exam_id, STUDENT)
check("batas global baru (2) dipakai saat buka blokir -> 1", int(vr2.get("totalRecorded") or -1) == 1,
      f"nilai={vr2.get('totalRecorded')}")

# ---------------------------------------------------------------- mode hanya catat
_, sv3 = call("saveSettings", "admin", tok, {"max_violations": 1, "tindakan_pelanggaran": "record_only"})
s = settings()
check("tindakan tersimpan record_only", s.get("tindakan_pelanggaran") == "record_only", s.get("tindakan_pelanggaran"))
_, exs2 = call("getTeacherExams", "admin", tok)
e1 = ([e for e in (exs2.get("exams") or []) if e.get("id_ujian") == exam_id] or [{}])[0]
check("ujian mengikuti mode record_only global", e1.get("tindakan_pelanggaran") == "record_only",
      f"nilai={e1.get('tindakan_pelanggaran')}")

# ---------------------------------------------------------------- siswa tidak boleh ubah
_, deny = call("saveSettings", STUDENT, stok, {"max_violations": 99})
check("siswa tidak boleh mengubah pengaturan", deny.get("success") is not True,
      deny.get("error") or deny.get("message", ""))

# ---------------------------------------------------------------- bersihkan
call("saveSettings", "admin", tok, {"max_violations": 3, "tindakan_pelanggaran": "auto_submit"})
call("deleteExam", "admin", tok, exam_id)
_, _usr = call("getUsers", "admin", tok)
_rec = next((u for u in (_usr.get("users") or []) if u.get("username") == STUDENT), None)
if _rec:
    call("deleteUser", "admin", tok, _rec.get("id_user"))
check("kembalikan nilai default", str(settings().get("max_violations")) == "3")

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal:", ", ".join(failed))
    raise SystemExit(1)
