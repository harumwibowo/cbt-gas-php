#!/usr/bin/env python
"""Load test ringan (Bagian 3.6): N siswa serentak mengakses RPC.

Pemakaian:
    python tests/load_test.py [jumlah_siswa] [jumlah_request_per_siswa]

Default 20 x 5 = 100 request. Ini bukan pengganti k6/Locust untuk 500 user,
tetapi cukup untuk mendeteksi regresi latensi setelah optimasi.
"""
import json, sys, time, urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor

BASE = "http://localhost/cbt-gas-php/api.php?action="
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36"
USERS = int(sys.argv[1]) if len(sys.argv) > 1 else 20
LOOPS = int(sys.argv[2]) if len(sys.argv) > 2 else 5


def call(action, *args):
    req = urllib.request.Request(BASE + action,
        data=json.dumps({"args": list(args)}).encode(),
        headers={"Content-Type": "application/json"}, method="POST")
    t0 = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            code, body = r.status, r.read()
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read()
    except Exception:
        code, body = 0, b""
    return code, (time.perf_counter() - t0) * 1000


def worker(i):
    username = f"load{i}"
    code, _ = call("login", username, "LoadTest123!", UA)
    if code != 200:
        return []
    # Token tidak dipakai di sini: hanya mengukur endpoint ringan.
    return [call("getSettings") for _ in range(LOOPS)]


# Siapkan akun uji lewat admin.
def admin_token():
    req = urllib.request.Request(BASE + "login",
        data=json.dumps({"args": ["admin", "admin123", UA]}).encode(),
        headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode()).get("token", "")


tok = admin_token()
req = urllib.request.Request(BASE + "saveSettings",
    data=json.dumps({"args": ["admin", tok, {"allowed_student_browsers": ""}]}).encode(),
    headers={"Content-Type": "application/json"}, method="POST")
urllib.request.urlopen(req, timeout=60).read()

for i in range(USERS):
    req = urllib.request.Request(BASE + "saveUser",
        data=json.dumps({"args": ["admin", tok, {
            "username": f"load{i}", "password": "LoadTest123!", "nama_lengkap": f"Load {i}",
            "role": "siswa", "kelas": "X", "sesi": "-", "status_aktif": "Aktif"}]}).encode(),
        headers={"Content-Type": "application/json"}, method="POST")
    urllib.request.urlopen(req, timeout=60).read()

print(f"Menjalankan {USERS} user x {LOOPS} request ...")
t0 = time.perf_counter()
with ThreadPoolExecutor(max_workers=min(USERS, 50)) as pool:
    results = list(pool.map(worker, range(USERS)))
elapsed = time.perf_counter() - t0

codes = [x for batch in results for x in batch]
durations = sorted(x[1] for x in codes if len(x) >= 2)
fail = sum(1 for x in codes if x[0] != 200)
p50 = durations[len(durations) // 2] if durations else 0
p95 = durations[int(len(durations) * 0.95)] if durations else 0

print(f"Total request : {len(codes)} | gagal: {fail}")
print(f"Durasi total  : {elapsed:.2f} dtk")
print(f"p50           : {p50:.1f} ms")
print(f"p95           : {p95:.1f} ms")
print("Body         :", "OK" if fail == 0 and p95 < 2000 else "PERLU INVESTIGASI")

# Bersihkan
users = json.loads(urllib.request.urlopen(urllib.request.Request(BASE + "getUsers",
    data=json.dumps({"args": ["admin", tok]}).encode(),
    headers={"Content-Type": "application/json"}, method="POST"), timeout=60).read().decode())
for u in users.get("users", []):
    if u.get("username", "").startswith("load"):
        req = urllib.request.Request(BASE + "deleteUser",
            data=json.dumps({"args": ["admin", tok, u["id_user"]]}).encode(),
            headers={"Content-Type": "application/json"}, method="POST")
        urllib.request.urlopen(req, timeout=60).read()
