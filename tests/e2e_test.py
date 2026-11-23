#!/usr/bin/env python
"""Uji end-to-end seluruh endpoint PHP CBT melalui HTTP (api.php)."""
import json
import urllib.request
import urllib.error
import sys

BASE = "http://localhost/cbt-gas-php/api.php"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36"

passed = []
failed = []


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


def check(step, action, cond, detail=""):
    code, res = action if isinstance(action, tuple) else (0, action)
    okk = code == 200 and cond(res)
    line = f"{'PASS' if okk else 'FAIL'}  {step}"
    if not okk:
        line += f"  -> HTTP {code} {json.dumps(res, ensure_ascii=False)[:300]}"
    elif detail:
        line += f"  ({detail(res) if callable(detail) else detail})"
    print(line)
    (passed if okk else failed).append(step)
    return res


def ok(res):
    return isinstance(res, dict) and res.get("success") is True


# ---------------------------------------------------------------- 1. login
admin = check("login admin", call("login", "admin", "admin123", UA), ok,
              lambda r: "role=" + r["user"]["role"])
tok = admin.get("token", "")

check("getSessionStatus valid", call("getSessionStatus", "admin", tok),
      lambda r: r.get("valid") is True)
check("getSessionStatus invalid token", call("getSessionStatus", "admin", "TKN-salah"),
      lambda r: r.get("valid") is False)
check("getSettings", call("getSettings"), ok,
      lambda r: r["settings"]["nama_sekolah"])

# ---------------------------------------------------------------- 2. settings
_original_settings = call("getSettings", "admin", tok)[1].get("settings", {})
check("saveSettings", call("saveSettings", "admin", tok,
                           {"nama_sekolah": "SMK Negeri 1 Uji", "max_student_sessions": "2",
                            "allowed_student_browsers": "", "wajib_fullscreen": "Ya"}),
      ok, lambda r: r.get("message"))
check("getSettings setelah simpan", call("getSettings"),
      lambda r: r["settings"]["nama_sekolah"] == "SMK Negeri 1 Uji",
      lambda r: r["settings"]["nama_sekolah"])

# ---------------------------------------------------------------- 3. sesi
check("saveSession", call("saveSession", "admin", tok,
                          {"nama_sesi": "Sesi Uji", "keterangan": "Sesi pengujian"}), ok,
      lambda r: r.get("message"))
sessions = check("getSessions", call("getSessions", "admin", tok), ok,
                 lambda r: f"{len(r['sessions'])} sesi")
sesi_uji = next((s for s in sessions.get("sessions", []) if s["nama_sesi"] == "Sesi Uji"), None)

# ---------------------------------------------------------------- 4. pengguna
check("saveUser guru baru", call("saveUser", "admin", tok,
                                 {"username": "guru1", "password": "guru123", "nama_lengkap": "Guru Satu",
                                  "role": "Guru", "kelas": "-", "status_aktif": "Aktif", "sesi": "Sesi Uji"}),
      ok, lambda r: r.get("message"))
check("saveUser siswa baru", call("saveUser", "admin", tok,
                                  {"username": "siswa1", "password": "siswa123", "nama_lengkap": "Siswa Satu",
                                   "role": "Siswa", "kelas": "X IPA 1", "tingkat": "X",
                                   "status_aktif": "Aktif", "sesi": "Sesi Uji"}), ok,
      lambda r: r.get("message"))
check("saveUser duplikat ditolak", call("saveUser", "admin", tok,
                                        {"username": "siswa1", "role": "Siswa"}),
      lambda r: r.get("success") is False)
users = check("getUsers", call("getUsers", "admin", tok), ok,
              lambda r: f"{len(r['users'])} user")
siswa_rec = next((u for u in users.get("users", []) if u["username"] == "siswa1"), None)
check("getStudentExamCards", call("getStudentExamCards", "admin", tok), ok,
      lambda r: f"{len(r['students'])} siswa")
check("importUsers", call("importUsers", "admin", tok,
                          [{"username": "siswa2", "nama_lengkap": "Siswa Dua", "role": "siswa",
                            "kelas": "X IPA 1", "tingkat": "X", "sesi": "Sesi Uji", "password": "siswa123"},
                           {"username": "", "nama": "Tanpa Username"}]), ok,
      lambda r: f"added={r['added']} updated={r['updated']} skipped={r['skipped']}")

# ---------------------------------------------------------------- 5. ujian
exam = check("saveExam", call("saveExam", "admin", tok, {
    "id_ujian": "", "nama_ujian": "UAS Matematika Uji", "durasi_menit": 90,
    "waktu_mulai": "2026-01-01T07:00", "waktu_selesai": "2026-12-31T23:59",
    "acak_soal": "Tidak", "acak_opsi": "Tidak", "token_ujian": "TOKEN1",
    "kelas": "X IPA 1", "sesi": "Sesi Uji", "tipe_peserta": "general",
    "jumlah_soal_tampil": 0, "jumlah_percobaan": 2, "max_violations": 3,
    "tindakan_pelanggaran": "auto_submit", "tampilkan_pembahasan": "Ya",
}), ok, lambda r: r.get("message"))
exam_id = exam.get("id_ujian", "")
if not exam_id:
    lst = call("getTeacherExams", "admin", tok)[1]
    cand = [e for e in lst.get("exams", []) if e["nama_ujian"] == "UAS Matematika Uji"]
    exam_id = cand[-1]["id_ujian"] if cand else ""
print(f"      exam_id = {exam_id}")

check("getTeacherExams", call("getTeacherExams", "admin", tok), ok,
      lambda r: f"{len(r['exams'])} ujian")

# ---------------------------------------------------------------- 6. soal
for idx, (teks, kunci) in enumerate([
    ("2 + 2 = ...", "A"), ("Ibu kota Indonesia adalah ...", "B"), ("Hasil 5 x 3 = ...", "C"),
], start=1):
    check(f"saveQuestion #{idx}", call("saveQuestion", "admin", tok, {
        "id_soal": "", "id_ujian": exam_id, "teks_soal": teks,
        "opsi_a": "Pilihan A", "opsi_b": "Pilihan B", "opsi_c": "Pilihan C",
        "opsi_d": "Pilihan D", "opsi_e": "", "kunci_jawaban": kunci, "bobot": 1,
        "url_gambar": "", "id_teks": "", "pembahasan": "Pembahasan " + kunci,
    }), ok, lambda r: r.get("message"))
qs = check("getExamQuestions", call("getExamQuestions", "admin", tok, exam_id), ok,
           lambda r: f"{len(r['questions'])} soal")

check("saveBulkQuestions", call("saveBulkQuestions", "admin", tok, exam_id, [{
    "teks_soal": "Soal bulk 1", "opsi_a": "A1", "opsi_b": "B1", "opsi_c": "C1",
    "opsi_d": "D1", "kunci_jawaban": "A", "bobot": 1}]), ok, lambda r: r.get("message"))
check("importQuestions", call("importQuestions", "admin", tok, exam_id, [{
    "teks_soal": "Soal impor 1", "opsi_a": "A2", "opsi_b": "B2", "opsi_c": "C2",
    "opsi_d": "D2", "kunci_jawaban": "B", "bobot": 1}]), ok, lambda r: r.get("message"))

txt = check("saveExamText", call("saveExamText", "admin", tok, {
    "id_teks": "", "id_ujian": exam_id, "nama_teks": "Teks Uji", "isi_teks": "Bacaan panjang untuk soal."}),
    ok, lambda r: r.get("message"))
check("getExamTexts", call("getExamTexts", "admin", tok, exam_id), ok,
      lambda r: f"{len(r['texts'])} teks")

# ---------------------------------------------------------------- 7. alur siswa
siswa_login = check("login siswa", call("login", "siswa1", "siswa123", UA), ok,
                    lambda r: r["user"]["role"])
stok = siswa_login.get("token", "")

avail = check("getStudentAvailableExams", call("getStudentAvailableExams", "siswa1", stok), ok,
              lambda r: f"{len(r['exams'])} ujian")
start_bad = call("startStudentExam", "siswa1", stok, exam_id, "SALAH")
check("startStudentExam token salah ditolak", start_bad, lambda r: r.get("success") is False)

start = check("startStudentExam", call("startStudentExam", "siswa1", stok, exam_id, "TOKEN1"), ok,
              lambda r: f"{len(r['questions'])} soal, attempt={r['draft']['percobaan_ke']}")
questions = start.get("questions", [])

check("startStudentExam tanpa kunci jawaban",
      (200, {"success": all("kunci_jawaban" not in q for q in questions)}), ok)

jawaban = {}
kunci_map = {q["id_soal"]: None for q in questions}
# ambil kunci dari sudut pandang guru untuk menguji penilaian
for q in qs.get("questions", []):
    kunci_map[q["id_soal"]] = q["kunci_jawaban"]

# jawab 1 benar, sisanya salah, lalu submit -> harus gagal karena belum lengkap
first = questions[0]["id_soal"]
jawaban[first] = kunci_map.get(first) or "A"
check("autosaveExam", call("autosaveExam", "siswa1", stok, {
    "id_ujian": exam_id, "answers": jawaban, "violations": 0, "percobaan_ke": 1}), ok,
      lambda r: r.get("timestamp"))
check("submit belum lengkap ditolak", call("submitExam", "siswa1", stok, {
    "id_ujian": exam_id, "answers": jawaban, "violations": 0, "percobaan_ke": 1}),
      lambda r: r.get("success") is False)

check("recordViolation", call("recordViolation", "siswa1", stok, {
    "id_ujian": exam_id, "jenis": "Tab Switch", "violations": 1,
    "keterangan": "Uji pelanggaran"}), ok)

# lengkapi jawaban: semua benar
for q in questions:
    jawaban[q["id_soal"]] = kunci_map.get(q["id_soal"]) or "A"

submit = check("submitExam lengkap", call("submitExam", "siswa1", stok, {
    "id_ujian": exam_id, "answers": jawaban, "violations": 1, "percobaan_ke": 1}), ok,
    lambda r: f"score={r['score']} benar={r['correctCount']}/{r['totalQuestions']} sisa={r['sisa_percobaan']}")

check("submit ganda ditolak", call("submitExam", "siswa1", stok, {
    "id_ujian": exam_id, "answers": jawaban, "violations": 1, "percobaan_ke": 1}),
      lambda r: r.get("success") is False)

review = check("getStudentExamReview", call("getStudentExamReview", "siswa1", stok, exam_id), ok,
               lambda r: f"{len(r.get('questions', r.get('details', [])))} butir")

# percobaan ke-2 (masih ada sisa 1)
start2 = check("startStudentExam percobaan 2", call("startStudentExam", "siswa1", stok, exam_id, "TOKEN1"), ok,
               lambda r: f"attempt={r['draft']['percobaan_ke']}")
check("autosave percobaan 2", call("autosaveExam", "siswa1", stok, {
    "id_ujian": exam_id, "answers": {}, "violations": 0, "percobaan_ke": 2}), ok)
check("blockStudentExam", call("blockStudentExam", "siswa1", stok, {
    "id_ujian": exam_id, "violations": 3, "reason": "Uji blokir", "percobaan_ke": 2}), ok,
      lambda r: r.get("message"))
blocked = call("startStudentExam", "siswa1", stok, exam_id, "")
check("startStudentExam saat terblokir ditolak", blocked, lambda r: r.get("success") is False)
check("unblockStudentExam", call("unblockStudentExam", "admin", tok, exam_id, "siswa1"), ok,
      lambda r: r.get("message"))

# ---------------------------------------------------------------- 8. hasil & pelanggaran
results = check("getExamResults", call("getExamResults", "admin", tok, exam_id), ok,
                lambda r: f"{len(r.get('results', []))} baris")
check("getStudentViolations", call("getStudentViolations", "admin", tok, exam_id, "siswa1"), ok,
      lambda r: r.get("message", "ok"))
check("regradeExamResults", call("regradeExamResults", "admin", tok, exam_id), ok,
      lambda r: r.get("message"))

# ---------------------------------------------------------------- 9. sesi login siswa
check("getStudentLoginSessions", call("getStudentLoginSessions", "admin", tok), ok,
      lambda r: f"online={r['total_online']} total={r['total_students']}")
check("resetStudentLoginSession", call("resetStudentLoginSession", "admin", tok, "siswa2"), ok,
      lambda r: r.get("message"))

# ---------------------------------------------------------------- 10. AI (tanpa API key)
# Bila server sudah menyimpan API key, fallback ke key tersimpan adalah perilaku
# yang benar; yang diuji di sini adalah endpoint tetap memberi respons terstruktur.
check("getGeminiModels", call("getGeminiModels", "admin", tok, ""),
      lambda r: r.get("success") is True or r.get("success") is False)
check("testGeminiConnection", call("testGeminiConnection", "admin", tok, "", ""),
      lambda r: r.get("success") is True or r.get("success") is False)
check("generateQuestionsWithGemini tanpa key", call("generateQuestionsWithGemini", "admin", tok, {}),
      lambda r: r.get("success") is False)

# ---------------------------------------------------------------- 11. admin dashboard
dash = check("getAdminData", call("getAdminData", "admin", tok), ok,
             lambda r: f"siswa={r['stats']['totalStudents']} ujian={r['stats']['totalExams']} submit={r['stats']['totalSubmissions']}")
check("getAdminData kelengkapan katalog",
      (200, dash), lambda r: all(k in r for k in ("available_classes", "available_grades",
                                                 "class_grade_map", "available_sessions",
                                                 "available_students", "available_teachers")))

# ---------------------------------------------------------------- 12. ubah password & bersih-bersih
s2 = call("login", "siswa2", "siswa123", UA)[1]
check("changePassword salah", call("changePassword", "siswa2", s2.get("token", ""), "salah", "abcde", "abcde"),
      lambda r: r.get("success") is False)
check("changePassword benar", call("changePassword", "siswa2", s2.get("token", ""),
                                   "siswa123", "rahasia1", "rahasia1"), ok,
      lambda r: r.get("message"))
check("login password baru", call("login", "siswa2", "rahasia1", UA), ok)

check("deleteStudentExamResult", call("deleteStudentExamResult", "admin", tok, exam_id, "siswa1"), ok,
      lambda r: r.get("message"))
check("deleteExam", call("deleteExam", "admin", tok, exam_id), ok, lambda r: r.get("message"))
# Bersihkan semua akun uji, termasuk yang dibuat lewat import.
_all_users = call("getUsers", "admin", tok)[1].get("users", [])
for _u in _all_users:
    if _u.get("username") in ("guru1", "siswa1", "siswa2"):
        check(f"deleteUser {_u['username']}",
              call("deleteUser", "admin", tok, _u.get("id_user", "")), ok,
              lambda r: r.get("message"))
if sesi_uji:
    check("deleteSession", call("deleteSession", "admin", tok, sesi_uji["id_sesi"]), ok,
          lambda r: r.get("message"))
if _original_settings:
    check("restoreSettings", call("saveSettings", "admin", tok, _original_settings), ok,
          lambda r: r.get("message"))
check("logout", call("logout", "admin", tok), ok, lambda r: r.get("message"))
check("getSessionStatus setelah logout", call("getSessionStatus", "admin", tok),
      lambda r: r.get("valid") is False)

print("\n=== RINGKASAN ===")
print(f"PASS: {len(passed)}   FAIL: {len(failed)}")
if failed:
    print("Gagal: " + ", ".join(failed))
sys.exit(1 if failed else 0)