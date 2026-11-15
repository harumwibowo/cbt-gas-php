# CBT GAS → PHP Native + MySQL

Konversi aplikasi CBT (aslinya Google Apps Script `Code.gs` + `Index.html`) menjadi
aplikasi **PHP 8 native + MySQL** tanpa kehilangan satu fitur pun.

## Prinsip konversi

| Aspek | Asli (Apps Script) | Sekarang (PHP) |
|---|---|---|
| Backend | `Code.gs` (V8) | `api.php` + `lib/` + `modules/` (PHP 8.3) |
| Database | Google Sheets (8 sheet) | MySQL 8 (8 tabel, kolom identik) |
| Transport | `google.script.run` | shim `assets/js/rpc-shim.js` → `fetch` ke `api.php` |
| UI | `Index.html` (11.522 baris) | `Index.html` yang sama — hanya kontrol gambar yang diganti menjadi unggah berkas |

`Index.html` tetap memakai seluruh markup & logika aslinya. Satu-satunya perubahan UI:
kolom **URL gambar** pada form butir soal dan **URL logo** pada menu pengaturan diganti menjadi
kontrol **unggah berkas** (gambar disimpan di MySQL, lihat bagian *Unggah Gambar*). Semua
pemanggilan `google.script.run.fungsi(...)`, `.withSuccessHandler(...)`,
`.withFailureHandler(...)`, `.withUserObject(...)` dilayani shim (`Proxy`) yang meneruskan
ke endpoint RPC PHP.

## Struktur file

```
api.php                  Endpoint RPC tunggal: POST api.php?action=<namaFungsi>
upload.php               Endpoint unggah gambar (multipart) → disimpan ke MySQL
media.php                Penyaji gambar dari database: GET media.php?id=MED-XXXXXXXX
favicon.php              Favicon aplikasi = logo sekolah (otomatis 64x64 PNG)
index.php                Menyajikan Index.html + menyuntik shim (sebelum </body> terakhir)
config/config.php        Konstanta DB (bisa via env), timezone, bootstrap library
lib/db.php               PDO singleton + ensure_schema() + seed_defaults()
lib/helpers.php          Utilitas (ID, tanggal, token sesi, log, normalisasi ujian, katalog)
lib/auth.php             login/logout/validateSession/getSessionStatus/currentRole
lib/settings.php         Pengaturan aplikasi + batas sesi siswa
modules/admin.php        Pengguna, kartu ujian, dashboard admin, sesi login siswa, impor
modules/media.php        Simpan/sajikan gambar di MySQL (validasi MIME, dedupe, pembersihan)
modules/sessions.php     Master sesi ujian (CRUD)
modules/exams.php        Ujian (CRUD) + statistik soal/peserta
modules/questions.php    Soal, teks/konteks, impor, simpan massal
modules/results.php      Rekap hasil, hapus hasil, pelanggaran, nilai ulang
modules/student.php      Ujian tersedia, profil siswa, pembahasan, util hasil
modules/student_exam.php Mulai ujian, autosave, pelanggaran, submit, blokir/buka blokir
modules/gemini.php       Integrasi Gemini AI (model, uji koneksi, generate soal)
assets/js/rpc-shim.js    Shim google.script.run
database/schema.sql      Skema 9 tabel (8 sheet + tabel media)
tests/e2e_test.py        Uji end-to-end 59 skenario lewat HTTP
tests/image_test.py      Uji fitur unggah gambar ke database (25 skenario)
```

## Instalasi

1. **Buat database** (sekali saja):

   ```bash
   mysql -uroot -e "CREATE DATABASE cbt_gas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

2. **Konfigurasi** — ubah `config/config.php` atau set environment variable:
   `CBT_DB_HOST`, `CBT_DB_PORT`, `CBT_DB_NAME`, `CBT_DB_USER`, `CBT_DB_PASS`.

3. **Buka aplikasi**: `http://localhost/cbt-gas-php/index.php`.
   Tabel dibuat otomatis saat request pertama (`ensure_schema()`), lengkap dengan data awal:
   - akun `admin` / `admin123` (role Admin)
   - 2 sesi ujian (Sesi 1, Sesi 2)
   - 14 pengaturan default

4. **Login** sebagai `admin/admin123`, lalu ganti password lewat menu Akun.

## Parity fitur (semua endpoint dipertahankan)

Whitelist di `api.php` berisi **44 action**; 42 di antaranya memang dipanggil frontend,
ditambah `getSessions` dan alias `GetSessionStatus`. Verifikasi otomatis:

```
public backend fns: 43  called by frontend: 42
called by frontend but NOT whitelisted: []      ← tidak ada yang hilang
```

Modul yang diporting 1:1 (nama fungsi sama, perilaku sama):

- **Auth & sesi**: `login`, `logout`, `getSessionStatus`, `validateSession`,
  multi-token per siswa, batas perangkat, pembatasan browser.
- **Pengaturan**: `getSettings`, `saveSettings` (termasuk pemangkasan sesi terlama).
- **Admin**: `getAdminData`, `getUsers`, `saveUser`, `changePassword`, `deleteUser`,
  `importUsers`, `getStudentExamCards`, `getStudentLoginSessions`, `resetStudentLoginSession`.
- **Sesi ujian**: `getSessions`, `saveSession`, `deleteSession`.
- **Ujian**: `getTeacherExams`, `saveExam`, `deleteExam` (cascade soal + hasil).
- **Bank soal**: `getExamQuestions`, `saveQuestion`, `deleteQuestion`,
  `importQuestions`, `saveBulkQuestions`, `getExamTexts`, `saveExamText`, `deleteExamText`.
- **Hasil**: `getExamResults` (dedupe baris + kelompok percobaan + nilai tertinggi),
  `deleteStudentExamResult`, `getStudentViolations`, `regradeExamResults`.
- **Alur siswa**: `getStudentAvailableExams` (status OPEN/UPCOMING/EXPIRED/SUBMITTED/TERBLOKIR),
  `startStudentExam` (token, paket soal acak deterministik per siswa, resume draft),
  `autosaveExam`, `recordViolation`, `submitExam` (nilai dihitung server-side),
  `blockStudentExam`, `unblockStudentExam`, `getStudentExamReview`.
- **AI**: `getGeminiModels`, `testGeminiConnection`, `generateQuestionsWithGemini`.

Detail perilaku yang dijaga persis:

- Kunci jawaban **tidak pernah** dikirim ke klien saat ujian berjalan.
- Pengacakan soal/opsi memakai PRNG deterministik (`seed = username_idsoal`) sehingga
  urutan opsi tetap sama saat siswa melanjutkan ujian; jawaban disimpan sebagai `origKey`.
- Paket soal (`jumlah_soal_tampil`) disimpan di kolom `results.soal_terpilih` dan dipakai
  ulang saat resume **dan** saat penilaian.
- Submit manual ditolak selama masih ada soal belum dijawab; auto-submit tetap dinilai.
- Nilai = `round(skor_bobot / total_bobot * 100, 1)`; multi-percobaan menyimpan nilai
  tertinggi dan sisa kesempatan.
- Buka blokir menyetel pelanggaran ke `batas_global - 1` dan memperbarui `waktu_mulai`.
- Batas & tindakan pelanggaran bersifat **global** (`settings`), bukan per-ujian.
- Satu baris `results` per (ujian, siswa, percobaan) — duplikat dikonsolidasikan.

## Zona waktu (WIB / WITA / WIT)

Zona waktu diatur Admin di **Pengaturan → Profil Sekolah → Zona Waktu Sekolah** dan
disimpan sebagai `settings.zona_waktu`. Petanya:

| Nilai | Zona IANA | Offset |
|---|---|---|
| `WIB` (default) | `Asia/Jakarta` | UTC+7 |
| `WITA` | `Asia/Makassar` | UTC+8 |
| `WIT` | `Asia/Jayapura` | UTC+9 |

**Mengapa tanggal ujian tetap aman:**

- `apply_app_timezone()` dipanggil di `config/config.php` pada **setiap request**, jadi
  `date_default_timezone_set()` selalu mengikuti pengaturan — zona waktu OS/PHP server
  (WIB, WITA, WIT, bahkan UTC) **tidak berpengaruh** sama sekali.
- Semua kolom tanggal/jam (`exams.waktu_mulai`, `waktu_selesai`, `results.waktu_mulai`,
  `waktu_submit`, `logs.timestamp`, `created_at`) bertipe `DATETIME` naif dan **selalu
  ditulis oleh PHP** (`now_dt()`), tidak pernah memakai `NOW()`/`CURRENT_TIMESTAMP` MySQL,
  sehingga tidak ada konversi zona diam-diam di sisi database.
- Jam ujian disimpan sebagai **jam dinding** zona terpilih dan tidak digeser saat admin
  mengganti zona. Contoh: ujian `08:00` tetap tersimpan `08:00`; yang berubah hanya
  "sekarang" yang dipakai untuk menilai ujian sudah dimulai/selesai.
- Perbandingan jadwal (`startStudentExam`, `getStudentAvailableExams`) memakai pasangan
  `strtotime()` + `time()` pada zona aplikasi yang sama, jadi konsisten.
- Input `datetime-local` pada form ujian adalah jam dinding naif — cocok dengan cara
  penyimpanan di atas sehingga nilai tidak pernah bergeser saat dibuka kembali.
- Pergantian zona waktu dicatat ke tabel `logs` (`Ubah Zona Waktu`) sebagai jejak audit.

Catatan: bila zona diubah **saat ada ujian berjalan**, sisa waktu siswa ikut bergeser
sebesar selisih offset (karena jam mulai tersimpan sebagai jam dinding). Lakukan
perubahan di luar jam ujian.

## Favicon dari logo sekolah

`favicon.php` menyajikan ikon tab browser dari `settings.logo_url`:

- Logo hasil unggah (`media.php?id=...`) → byte diambil dari tabel `media`, lalu
  **diperkecil ke 64x64 PNG** dengan GD (logo 1000x770 tetap menghasilkan ikon ±6 KB).
- Logo berupa URL eksternal → `302` ke URL tersebut (server tidak mengunduh apa pun).
- Belum ada logo → ikon bawaan (SVG gradien + topi wisuda) agar tab tetap rapi.
- Mendukung `ETag` + `If-None-Match` (`304`) dan `Cache-Control: public, max-age=86400`.

`index.php` menyisipkan `<link rel="icon" href="favicon.php?v=<hash logo>">` di `<head>`
(hash berubah saat logo diganti, sehingga cache browser ikut diperbarui). Di sisi klien,
`applyFavicon()` memperbarui link tersebut setiap `applyBrandingSettings()` berjalan.

## Batas & tindakan pelanggaran (global)

Sebelumnya `max_violations` dan `tindakan_pelanggaran` diisi **per ujian** pada form
Tambah/Edit Ujian. Sekarang keduanya **satu pengaturan global** yang hanya diatur Admin:

**Pengaturan → Keamanan Ujian (Pelanggaran)**

| Kunci `settings` | Nilai | Arti |
|---|---|---|
| `max_violations` | 1–99 (default `3`) | Jumlah pelanggaran yang ditoleransi |
| `tindakan_pelanggaran` | `auto_submit` / `auto_blokir` / `record_only` | Tindakan saat batas tercapai |

- `auto_submit` — jawaban langsung dikumpulkan & dinilai.
- `auto_blokir` — akses siswa diblokir sampai dibuka Admin/Guru.
- `record_only` — pelanggaran hanya dicatat, batas diabaikan.

**Yang berubah:**

- Form Tambah/Edit Ujian **tidak lagi** memuat kedua kolom; diganti banner informasi
  yang menunjuk ke menu Pengaturan. Nilai per-ujian yang masih dikirim klien **diabaikan**.
- Semua jalur baca memakai satu sumber: `violation_policy()` di `lib/settings.php`
  (`get_max_violations()`, `get_violation_action()`), dipakai oleh `exam_to_client()`,
  `saveExam()`, `getStudentAvailableExams()`, `startStudentExam()`, dan `unblockStudentExam()`.
- Objek ujian yang dikirim ke klien tetap memuat `max_violations` / `tindakan_pelanggaran`
  (kini bernilai global), sehingga logika anti-curang di layar siswa tidak perlu diubah.
- Kolom `exams.max_violations` / `exams.tindakan_pelanggaran` masih ada dan selalu diisi
  nilai global agar data lama tetap terbaca, tetapi tidak pernah dibaca sebagai sumber kebenaran.
- `saveSettings` kini **hanya boleh dipanggil Admin** (sebelumnya cukup sesi valid —
  siswa yang menyusun request manual bisa mengubah pengaturan sekolah).

## Unggah gambar ke database

Dulu gambar soal & logo diisi dengan menempel **URL** atau **ID berkas Google Drive**.
Sekarang berkas diunggah dan **disimpan langsung di MySQL** (tabel `media`, kolom `data LONGBLOB`),
tanpa folder unggahan dan tanpa layanan berkas eksternal.

**Alur:**

1. Guru/Admin menekan **Upload Gambar** pada form butir soal (atau **Upload Logo** di Pengaturan).
2. Browser mengirim `multipart/form-data` ke `upload.php` (field `username`, `token`, `file`).
3. Server memvalidasi sesi (hanya Admin/Guru), MIME asli via `finfo`, ukuran ≤ 8 MB, dan
   keutuhan gambar via `getimagesize()` (SVG dikecualikan), lalu menyimpan byte ke tabel `media`.
4. Respons: `{ success, id, url: "media.php?id=MED-XXXXXXXX", mime, ukuran, nama_file }`.
5. Nilai `url` disimpan apa adanya ke `questions.url_gambar` / `settings.logo_url`.
   Karena berupa URL gambar biasa, semua markup `<img src="...">` yang sudah ada tetap bekerja
   (lembar ujian siswa, pratinjau guru, pembahasan, kop cetak, kartu ujian).

**Detail teknis:**

- `media.php?id=...` menyajikan gambar dengan `Content-Type` asli, `ETag`, dan
  `Cache-Control: immutable` (mendukung `304`). SVG dikirim dengan CSP `default-src 'none'`.
- **Deduplikasi**: berkas identik (SHA-256 sama) tidak disimpan dua kali; ID yang ada dipakai ulang.
- **Pembersihan otomatis**: gambar dihapus dari `media` ketika soal dihapus, gambar soal diganti,
  logo diganti, atau ujian dihapus — dan hanya jika tidak lagi dirujuk soal/logo lain.
  Pembersihan massal manual: `SELECT`→`media_purge_orphans()` pada `modules/media.php`.
- **Kompatibilitas**: nilai lama berbentuk `https://...` tetap ditampilkan; jalur Drive ID mentah
  tidak diubah (perilaku sama seperti versi Apps Script).
- Batas ukuran dapat diubah lewat konstanta `MAX_UPLOAD_BYTES` di `config/config.php`.

## Pengujian

```bash
python tests/e2e_test.py               # 59 pemeriksaan alur RPC
python tests/image_test.py             # 25 pemeriksaan unggah gambar
python tests/violation_global_test.py  # 25 pemeriksaan kebijakan pelanggaran global
python tests/timezone_favicon_test.py  # 29 pemeriksaan zona waktu & favicon
```

`e2e_test.py` menjalankan alur HTTP terhadap `api.php` (login → CRUD ujian/soal →
mulai/pause/submit ujian siswa → blokir/buka blokir → rekap → impor → pengaturan →
hapus → logout). Hasil terakhir: **59 PASS, 0 FAIL**.

`image_test.py` menguji unggah gambar (valid/bukan gambar/tanpa sesi), dedupe, penyajian
biner lewat `media.php`, tampil di lembar ujian & pembahasan siswa, penggantian gambar,
pembersihan media saat soal/ujian dihapus, serta logo sekolah. Hasil: **25 PASS, 0 FAIL**.

`timezone_favicon_test.py` membuktikan zona waktu benar-benar dipakai: ujian berjadwal
jam dinding WIT bisa dimulai & dikumpulkan tepat waktu (gagal bila server masih WIB),
`timezone.sekarang` bergeser sesuai offset, jam ujian tidak bergeser saat zona diganti,
nilai zona tidak valid dinormalkan, serta favicon ikut logo (SVG bawaan → PNG 64x64,
`304` cache, kembali ke bawaan saat logo dihapus). Hasil: **29 PASS, 0 FAIL**.

`violation_global_test.py` membuktikan kebijakan pelanggaran sudah global: nilai per-ujian
yang dikirim klien diabaikan, `getTeacherExams`/`getStudentAvailableExams`/`startStudentExam`
memakai nilai global, buka blokir memakai `batas_global - 1`, normalisasi nilai tidak valid,
penolakan siswa mengubah pengaturan. Hasil: **25 PASS, 0 FAIL**.

Untuk memulai ulang dari data bersih:

```bash
mysql -uroot cbt_gas -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE users; TRUNCATE exams; TRUNCATE questions; TRUNCATE teks_ujian; TRUNCATE results; TRUNCATE logs; TRUNCATE sesi; TRUNCATE settings; TRUNCATE media;"
```

## Catatan teknis

- Password disimpan apa adanya seperti pada versi Apps Script (parity perilaku).
  Untuk produksi sebaiknya ganti ke `password_hash()`.
- `export_*`/cetak tetap berjalan di sisi klien (frontend tidak diubah).
- Folder `_analysis/` (hasil ekstraksi sementara untuk analisis) sudah dihapus.
- `Code.gs` (sumber asli Apps Script) **sudah dihapus** dari folder kerja karena tidak
  dipakai aplikasi PHP. Masih tersimpan di riwayat git untuk rujukan parity:
  `git show fbcbdcc:Code.gs > Code.gs`.