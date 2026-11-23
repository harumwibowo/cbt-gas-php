#!/usr/bin/env python
"""Negative security test (Bagian 3.6): siswa memanggil action admin/guru -> 403."""
import json, urllib.request, urllib.error

BASE = "http://localhost/cbt-gas-php/api.php?action="
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"
STAFF_ACTIONS = [
    "getUsers", "getStudentExamCards", "getAdminData", "saveUser", "deleteUser",
    "importUsers", "getStudentLoginSessions", "resetStudentLoginSession",
    "saveSettings", "getSessions", "saveSession", "deleteSession",
    "updateApplication", "getApplicationVersion", "getTeacherExams", "saveExam",
    "deleteExam", "getExamQuestions", "saveQuestion", "deleteQuestion",
    "importQuestions", "saveBulkQuestions", "getExamTexts", "saveExamText",
    "deleteExamText", "getExamResults", "getExamResultDetail",
    "deleteStudentExamResult", "getStudentViolations", "regradeExamResults",
    "unblockStudentExam", "getGeminiModels", "testGeminiConnection",
    "generateQuestionsWithGemini", "generateStudentTokens", "setup2fa", "disable2fa",
]

passed, failed = [], []


def call(action, *args):
    req = urllib.request.Request(BASE + action,
        data=json.dumps({"args": list(args)}).encode("utf-8"),
        headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def body(raw):
    try: return json.loads(raw)
    except Exception: return {}


a = body(call("login", "admin", "admin123", UA)[1])
tok = a.get("token", "")
# Pastikan login siswa tidak ditolak oleh allowlist browser bawaan.
original = body(call("getSettings", "admin", tok)[1]).get("settings", {})
call("saveSettings", "admin", tok, {"allowed_student_browsers": ""})
call("saveUser", "admin", tok, {"username": "siswa_neg", "password": "NegTest123!",
    "nama_lengkap": "Siswa Negatif", "role": "siswa", "kelas": "X", "sesi": "Sesi 1",
    "status_aktif": "Aktif"})
s = body(call("login", "siswa_neg", "NegTest123!", UA)[1])
stok = s.get("token", "")
print("Login siswa uji:", s.get("success"), s.get("message", ""))

for action in STAFF_ACTIONS:
    code, raw = call(action, "siswa_neg", stok)
    ok = code == 403
    (passed if ok else failed).append(action)
    print(("PASS  " if ok else "FAIL  ") + f"{action}: HTTP {code}")

# Sanity: action siswa tetap diizinkan
code, raw = call("getStudentAvailableExams", "siswa_neg", stok)
ok = code == 200 and body(raw).get("success") is True
(passed if ok else failed).append("getStudentAvailableExams (allowed)")
print(("PASS  " if ok else "FAIL  ") + f"getStudentAvailableExams (allowed): HTTP {code}")

# Bersihkan akun uji
users = body(call("getUsers", "admin", tok)[1]).get("users", [])
for u in users:
    if u.get("username") == "siswa_neg":
        call("deleteUser", "admin", tok, u.get("id_user"))

if original.get("allowed_student_browsers") is not None:
    call("saveSettings", "admin", tok,
         {"allowed_student_browsers": original["allowed_student_browsers"]})

print(f"\n=== NEGATIVE AUTH: PASS {len(passed)}  FAIL {len(failed)} ===")
if failed:
    print("GAGAL:", failed)
