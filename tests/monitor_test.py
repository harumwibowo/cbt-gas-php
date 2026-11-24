#!/usr/bin/env python
"""Uji fungsional halaman Monitor Ujian: getExamMonitor + forceFinishExam."""
import json
import urllib.request
import urllib.error
import sys

BASE = "http://localhost/cbt-gas-php/api.php"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36"

passed, failed = [], []


def call(action, *args):
    req = urllib.request.Request(
        BASE + "?action=" + action,
        data=json.dumps({"args": list(args)}).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            body = r.read().decode("utf-8")
            code = r.status
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8")
        code = e.code
    try:
        return code, json.loads(body)
    except json.JSONDecodeError:
        return code, {"_raw": body[:400]}


def check(step, res_code_res, cond, detail=""):
    code, res = res_code_res
    # 403 juga sah untuk uji otorisasi
    good = (code == 200 or code == 403) and cond(res)
    line = f"{'PASS' if good else 'FAIL'}  {step}"
    if not good:
        line += f"  -> HTTP {code} {json.dumps(res, ensure_ascii=False)[:300]}"
    elif detail:
        line += f"  ({detail(res) if callable(detail) else detail})"
    print(line)
    (passed if good else failed).append(step)
    return res


admin = check("login admin", call("login", "admin", "admin123", UA),
              lambda r: r.get("success") is True)
tok = admin.get("token", "")

# Kebijakan percobaan bersifat global; dipulihkan setelah uji multi-percobaan.
_orig_settings = call("getSettings", "admin", tok)[1].get("settings", {})
_orig_attempts = str(_orig_settings.get("jumlah_percobaan", "1"))

# Ambil satu ujian yang sudah punya soal
data = check("getAdminData", call("getAdminData", "admin", tok),
             lambda r: r.get("success") is True)
exams = [e for e in (data.get("exams") or []) if int(e.get("jumlah_soal") or 0) > 0]
if not exams:
    print("Tidak ada ujian bersoal untuk diuji.")
    sys.exit(1)
# Utamakan ujian yang bank soalnya lebih banyak daripada "Jumlah Soal Tampil"
# agar batas maksimal progres benar-benar teruji.
preferred = [e for e in exams
             if 0 < int(e.get("jumlah_soal_tampil") or 0) < int(e.get("jumlah_soal") or 0)]
exam = (preferred or exams)[0]
exam_id = exam["id_ujian"]
print(f"    ujian dipakai: {exam_id} ({exam.get('nama_ujian')}) soal={exam.get('jumlah_soal')}")

# Siapkan siswa uji dengan username unik
import time as _t
UNAME = "monitor" + str(int(_t.time()) % 100000)


def hapus_user(uname):
    users = call("getUsers", "admin", tok)
    for u in ((users[1] or {}).get("users") or []):
        if str(u.get("username", "")).lower() == uname.lower():
            call("deleteUser", "admin", tok, u.get("id_user"))


check("saveUser siswa uji", call("saveUser", "admin", tok, {
    "username": UNAME, "nama_lengkap": "Siswa Monitor",
    "role": "siswa", "password": "monitor123", "kelas": "XII-A", "sesi": "1",
}), lambda r: r.get("success") is True, UNAME)

slogin = check("login siswa monitor1", call("login", UNAME, "monitor123", UA),
               lambda r: r.get("success") is True)
stok = slogin.get("token", "")

started = call("startStudentExam", UNAME, stok, exam_id)
started = check("startStudentExam", started, lambda r: r.get("success") is True)
# Paket soal yang benar-benar diterima siswa (bisa berupa subset acak)
student_questions = [q for q in (started.get("questions") or []) if q.get("id_soal")]

# Kosong: siswa belum dijawab -> progres 0
mon = check("getExamMonitor (semua ujian)", call("getExamMonitor", "admin", tok, ""),
            lambda r: r.get("success") is True and isinstance(r.get("rows"), list))
row = next((r for r in mon["rows"] if r["username_siswa"] == UNAME and r["id_ujian"] == exam_id), None)
check("baris monitor siswa muncul", (200, row or {}),
      lambda r: bool(r.get("username_siswa")),
      lambda r: f"status={r.get('status')} progres={r.get('answered')}/{r.get('total_soal')}")
check("status awal = MENGERJAKAN", (200, row or {}),
      lambda r: r.get("status") == "MENGERJAKAN")
check("progres awal 0", (200, row or {}),
      lambda r: int(r.get("answered") or 0) == 0)
total_soal = int((row or {}).get("total_soal") or 0)
bank_total = int(exam.get("jumlah_soal") or 0)
tampil_total = int(exam.get("jumlah_soal_tampil") or 0)
expected_total = min(tampil_total, bank_total) if tampil_total > 0 else bank_total
check("total soal = maks(Jumlah Soal Tampil, bank)", (200, row or {}),
      lambda r: int(r.get("total_soal") or -1) == expected_total,
      lambda r: f"{r.get('total_soal')} (bank {bank_total}, tampil {tampil_total})")

# Jawab 2 soal dari paket siswa lalu autosave
answers = {}
for q in student_questions[:2]:
    answers[str(q.get("id_soal"))] = "A"
check("autosaveExam 2 jawaban",
      call("autosaveExam", UNAME, stok, {"id_ujian": exam_id, "answers": answers, "violations": 0}),
      lambda r: r.get("success") is True)

mon2 = check("getExamMonitor (per ujian)", call("getExamMonitor", "admin", tok, exam_id),
             lambda r: r.get("success") is True)
row2 = next((r for r in mon2["rows"] if r["username_siswa"] == UNAME), None)
expected_answered = min(2, len(student_questions))
check("progres naik sesuai jawaban tersimpan", (200, row2 or {}),
      lambda r: int(r.get("answered") or 0) == expected_answered,
      lambda r: f"{r.get('answered')}/{r.get('total_soal')} (harap {expected_answered})")

# Rekap harus TIDAK memuat siswa yang masih mengerjakan
rek = check("getExamResults", call("getExamResults", "admin", tok, exam_id, False),
            lambda r: r.get("success") is True)
check("rekap tidak memuat yang mengerjakan", (200, rek),
      lambda r: all(x["username_siswa"] != UNAME for x in (r.get("results") or [])))

# Paksa selesai
forced = call("forceFinishExam", "admin", tok, exam_id, UNAME)
check("forceFinishExam", forced, lambda r: r.get("success") is True,
      lambda r: "nilai=" + str(r.get("nilai_akhir")))

mon3 = call("getExamMonitor", "admin", tok, exam_id)
mon3 = check("getExamMonitor setelah paksa selesai", mon3, lambda r: r.get("success") is True)
row3 = next((r for r in mon3["rows"] if r["username_siswa"] == UNAME), None)
check("status jadi SELESAI", (200, row3 or {}), lambda r: r.get("status") == "SELESAI")

rek2 = call("getExamResults", "admin", tok, exam_id, False)
rek2 = check("getExamResults setelah paksa selesai", rek2, lambda r: r.get("success") is True)
check("rekap kini memuat siswa", (200, rek2),
      lambda r: any(x["username_siswa"] == UNAME for x in (r.get("results") or [])))

check("forceFinishExam ulang ditolak", call("forceFinishExam", "admin", tok, exam_id, UNAME),
      lambda r: r.get("success") is not True)
check("forceFinishExam siswa tak dikenal ditolak",
      call("forceFinishExam", "admin", tok, exam_id, "tidak_ada_siswa"),
      lambda r: r.get("success") is not True)

# RBAC: siswa tidak boleh memantau
check("getExamMonitor ditolak untuk siswa (403)",
      call("getExamMonitor", UNAME, stok, ""),
      lambda r: r.get("success") is not True, "HTTP 403")
check("forceFinishExam ditolak untuk siswa (403)",
      call("forceFinishExam", UNAME, stok, exam_id, UNAME),
      lambda r: r.get("success") is not True, "HTTP 403")

# ---------------------------------------------------------------- percobaan ke-2
# Percobaan TERBARU harus menang: siswa yang sudah mengumpulkan percobaan 1 lalu
# mulai percobaan 2 harus tampil MENGERJAKAN (bukan SELESAI dari percobaan 1).
check("atur global 2 percobaan", call("saveSettings", "admin", tok, {"jumlah_percobaan": "2"}),
      lambda r: r.get("success") is True)
st2 = call("startStudentExam", UNAME, stok, exam_id)
st2 = check("mulai percobaan ke-2", st2, lambda r: r.get("success") is True)
answers2 = {str(q.get("id_soal")): "B" for q in (st2.get("questions") or [])[:1] if q.get("id_soal")}
check("autosave percobaan ke-2",
      call("autosaveExam", UNAME, stok, {"id_ujian": exam_id, "answers": answers2, "violations": 0}),
      lambda r: r.get("success") is True)
mon4 = check("getExamMonitor setelah mulai percobaan 2",
             call("getExamMonitor", "admin", tok, exam_id),
             lambda r: r.get("success") is True)
row4 = next((r for r in mon4["rows"] if r["username_siswa"] == UNAME), None)
check("status kembali MENGERJAKAN (percobaan terbaru menang)", (200, row4 or {}),
      lambda r: r.get("status") == "MENGERJAKAN",
      lambda r: f"status={r.get('status')}")
check("monitor menampilkan percobaan ke-2", (200, row4 or {}),
      lambda r: int(r.get("percobaan_ke") or 0) == 2,
      lambda r: f"percobaan={r.get('percobaan_ke')}")
rek3 = call("getExamResults", "admin", tok, exam_id, False)
check("rekap tetap hanya memuat yang selesai", rek3,
      lambda r: all(x["username_siswa"] != UNAME or str(x.get("status", "")).upper() == "SUBMITTED"
                    for x in (r.get("results") or [])))

# Bersihkan
call("deleteStudentExamResult", "admin", tok, exam_id, UNAME)
hapus_user(UNAME)
call("saveSettings", "admin", tok, {"jumlah_percobaan": _orig_attempts})
call("logout", "admin", tok)

print(f"\n=== MONITOR: PASS {len(passed)}  FAIL {len(failed)} ===")
if failed:
    print("GAGAL:", failed)
    sys.exit(1)
