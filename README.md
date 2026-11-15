# CBT GAS — PHP 8 + MySQL

Aplikasi **Computer Based Test (CBT)** untuk sekolah: bank soal, ujian berjadwal, token ujian,
pengacakan soal/opsi, penilaian otomatis, pembahasan, kartu ujian, monitoring pelanggaran,
dan generator soal dengan AI Gemini.

Aplikasi ini adalah konversi 1:1 dari versi **Google Apps Script + Google Sheets** ke
**PHP native + MySQL**. Seluruh fitur asli dipertahankan (67 fungsi backend, 44 action RPC),
dan tampilan tetap memakai `Index.html` yang sama — `google.script.run` diemulasi oleh
shim kecil di browser, sehingga tidak ada satu pun komponen UI yang perlu ditulis ulang.

| | Asli | Sekarang |
|---|---|---|
| Backend | `Code.gs` (Apps Script V8) | `api.php` + `lib/` + `modules/` (PHP 8) |
| Database | Google Sheets (8 sheet) | MySQL (8 tabel + 1 tabel media) |
| Berkas gambar | URL / ID Google Drive | diunggah & disimpan di MySQL |
| Frontend | `Index.html` | `Index.html` yang sama + `assets/js/rpc-shim.js` |

---

## Daftar isi

1. [Persyaratan](#1-persyaratan)
2. [Instalasi cepat (5 menit)](#2-instalasi-cepat-5-menit)
3. [Konfigurasi database](#3-konfigurasi-database)
4. [Menjalankan di web server](#4-menjalankan-di-web-server)
5. [Pengaturan awal setelah login](#5-pengaturan-awal-setelah-login)
6. [Checklist siap deploy](#6-checklist-siap-deploy)
7. [Backup & restore](#7-backup--restore)
8. [Pembaruan aplikasi dari GitHub](#7b-pembaruan-aplikasi-dari-github)
9. [Struktur proyek](#8-struktur-proyek)
10. [Cara kerja & parity fitur](#9-cara-kerja--parity-fitur)
11. [Endpoint HTTP](#10-endpoint-http)
12. [Reset data](#11-reset-data)
13. [Pemecahan masalah](#12-pemecahan-masalah)
14. [Catatan teknis & batasan](#13-catatan-teknis--batasan)

---

## 1. Persyaratan

**Server aplikasi**

- **PHP 8.0 atau lebih baru** (diuji pada PHP 8.3.33; disarankan 8.2/8.3).
- Ekstensi PHP wajib aktif:

  | Ekstensi | Dipakai untuk |
  |---|---|
  | `pdo_mysql` | seluruh akses database |
  | `json` | format request/response RPC (aktif bawaan PHP 8) |
  | `fileinfo` | validasi MIME asli saat unggah gambar |
  | `gd` | pengecilan logo menjadi favicon 64×64 |
  | `curl` | fitur AI Gemini (opsional, tanpa ini hanya AI yang mati) |

- `upload_max_filesize` dan `post_max_size` minimal **9 MB** (gambar soal dibatasi 8 MB).

**Database**

- MySQL **5.7+/8.0** atau MariaDB **10.3+**, `utf8mb4`.
- User database dengan izin `CREATE` (aplikasi membuat database & tabel sendiri saat
  request pertama) **atau** izin `SELECT/INSERT/UPDATE/DELETE` saja bila Anda mengimpor
  `database/schema.sql` secara manual.

**Web server**

- Apache 2.4 (`mod_php` atau PHP-FPM) atau Nginx + PHP-FPM. Tidak butuh Composer,
  Node.js, framework, maupun proses build apa pun.

---

## 2. Instalasi cepat (5 menit)

Contoh untuk **Laragon/XAMPP** di Windows, atau server Linux dengan document root `/var/www`.

```bash
# 1. Ambil kode
git clone https://github.com/harumwibowo/cbt-gas-php.git
#    (atau unduh ZIP lalu ekstrak)

# 2. Letakkan di document root web server, contoh:
#    Windows/Laragon : C:\laragon87\www\cbt-gas-php
#    Linux/Apache    : /var/www/cbt-gas-php
```

3. **Pastikan MySQL berjalan**, lalu buka di browser:

   ```
   http://localhost/cbt-gas-php/
   ```

4. **Halaman setup muncul otomatis** (karena aplikasi belum terpasang). Isi host, port,
   nama database, user, dan password MySQL. Ada tombol **Uji Koneksi** sebelum instalasi.
   Semua kolom kredensial tinggal diisi di halaman itu &mdash; **tidak perlu mengedit
   `config/config.php`**.
5. Tekan **Instal Sekarang**. Aplikasi membuat 9 tabel + data awal (akun `admin` /
   `admin123`, 2 sesi ujian, 14 pengaturan) dan menyimpan kredensial ke
   `config/database.json` (mode 0600, tidak masuk git).
6. Halaman setup **tidak akan muncul lagi**; Anda langsung diarahkan ke halaman login.
   Login sebagai `admin` / `admin123`, lalu **segera ganti password** lewat menu **Akun**.

> Halaman setup muncul kembali hanya bila `config/database.json` hilang, database tidak
> bisa dihubungi, atau belum ada akun Admin &mdash; jadi berguna juga untuk memperbaiki
> kredensial yang salah. Untuk membukanya secara manual tambahkan `?force=1`
> (`setup.php?force=1`).

---

## 3. Konfigurasi database

**Cara A &mdash; halaman setup (disarankan, tanpa edit berkas)**

Buka `http://host-anda/cbt-gas-php/` lalu isi formulir. Aplikasi menyimpan hasilnya ke
`config/database.json`:

```json
{
    "host": "127.0.0.1",
    "port": 3306,
    "name": "cbt_gas",
    "user": "root",
    "pass": "",
    "installed": "2026-01-01T08:00:00+07:00",
    "version": 1
}
```

| Kolom formulir | Keterangan |
|---|---|
| Host / hostname | `127.0.0.1` atau `localhost` (hosting biasanya `localhost`) |
| Port | default `3306` |
| Nama database | database kosong yang sudah dibuat (atau dicentang untuk dibuat otomatis) |
| Username / password | akun MySQL dengan izin `CREATE`, `ALTER`, `INSERT`, `SELECT` |
| Buat database otomatis | matikan bila user tidak punya izin `CREATE DATABASE` |

Kredensial juga bisa diisi dari environment variable &mdash; dipakai **lebih dulu**
daripada `config/database.json` (cocok untuk Docker/PaaS):

| Environment variable | Default |
|---|---|
| `CBT_DB_HOST` | `127.0.0.1` |
| `CBT_DB_PORT` | `3306` |
| `CBT_DB_NAME` | `cbt_gas` |
| `CBT_DB_USER` | `root` |
| `CBT_DB_PASS` | *(kosong)* |

Contoh Apache (`SetEnv`) / Nginx (`fastcgi_param`) sama seperti di dokumentasi umum:
cukup tambahkan variabel di atas, lalu aplikasi tidak memakai `config/database.json`.

**Cara B &mdash; impor skema manual (tanpa izin CREATE DATABASE)**

```bash
mysql -u root -p -e "CREATE DATABASE cbt_gas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p cbt_gas < database/schema.sql
```

`database/schema.sql` bersifat **idempotent** (`CREATE TABLE IF NOT EXISTS`), aman
dijalankan berulang. Setelah itu buka aplikasi dan isi halaman setup (database sudah ada,
jadi cukup diuji koneksinya) agar data awal terisi otomatis.

**Verifikasi koneksi**

```bash
php -r 'require "config/config.php"; echo db_val("SELECT COUNT(*) FROM users")." user\n";'
```

Harus mencetak `1 user` pada instalasi baru.

---

## 4. Menjalankan di web server

### Apache (VirtualHost)

```apache
<VirtualHost *:80>
    ServerName cbt.sekolah.sch.id
    DocumentRoot /var/www/cbt-gas-php

    <Directory /var/www/cbt-gas-php>
        # .htaccess dipakai untuk memblokir folder internal (config, lib, modules, database)
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    # Batas ukuran unggahan gambar soal
    LimitRequestBody 16777216
    ErrorLog  ${APACHE_LOG_DIR}/cbt-error.log
    CustomLog ${APACHE_LOG_DIR}/cbt-access.log combined
</VirtualHost>
```

Restart: `sudo systemctl reload apache2`.

> **Penting:** `AllowOverride All` (atau minimal `FileInfo AuthConfig Indexes`) diperlukan
> agar berkas `.htaccess` yang disertakan bekerja. Banyak paket Laragon/XAMPP memakai
> `AllowOverride None` pada document root sehingga `.htaccess` **diabaikan** — aplikasi
> tetap jalan, tetapi proteksi folder internal tidak aktif. Untuk Laragon tambahkan:

```apache
<Directory "C:/laragon87/www/cbt-gas-php">
    AllowOverride All
</Directory>
```

### Nginx + PHP-FPM

Nginx tidak membaca `.htaccess`, jadi aturan blokir ditulis di server block:

```nginx
server {
    listen 80;
    server_name cbt.sekolah.sch.id;
    root /var/www/cbt-gas-php;
    index index.php;

    client_max_body_size 9M;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 120s;
    }

    # Blokir folder internal & metadata repo
    location ^~ /config/   { deny all; }
    location ^~ /lib/      { deny all; }
    location ^~ /modules/  { deny all; }
    location ^~ /database/ { deny all; }
    location ~ /\.git      { deny all; }
    location ~* \.(md|sql|py|log)$ { deny all; }

    # Cache aset statis
    location ~* \.(png|jpe?g|webp|svg|css|js)$ {
        expires 7d;
        access_log off;
    }
}
```

### Shared hosting / cPanel

1. **Berkas → Unggah** ZIP repo, ekstrak ke `public_html/cbt-gas-php` (atau
   `public_html` langsung bila aplikasi menjadi situs utama).
2. **MultiPHP Manager**: pilih PHP **8.1+** untuk domain tersebut.
3. **MultiPHP INI Editor**: set `upload_max_filesize=16M`, `post_max_size=17M`,
   `max_execution_time=120`.
4. **MySQL Databases**: buat database + user, beri *ALL PRIVILEGES* pada database itu.
5. Isi kredensial lewat environment variable (bila panel mendukung) atau
   `config/config.php`.
6. Buka `https://domain-anda.sch.id/cbt-gas-php/index.php`.

### php.ini yang disarankan

```ini
upload_max_filesize = 16M
post_max_size       = 17M
max_execution_time  = 120
memory_limit        = 256M
expose_php          = Off
```

Zona waktu PHP **tidak perlu** diubah: aplikasi selalu menyetelnya sendiri dari
pengaturan **Zona Waktu Sekolah** (WIB/WITA/WIT).

---

## 5. Pengaturan awal setelah login

Login `admin / admin123` → urutan yang disarankan:

1. **Akun** — ganti password `admin`, isi nama tampilan.
2. **Pengaturan → Profil Sekolah** — nama sekolah, **unggah logo** (dipakai juga sebagai
   **favicon** tab browser), tahun ajaran, dan **Zona Waktu Sekolah** (WIB / WITA / WIT).
3. **Pengaturan → Keamanan Anti-Curang** — wajib fullscreen, deteksi pindah tab,
   blokir klik kanan, watermark dinamis, pembatasan browser siswa, batas sesi login siswa.
4. **Pengaturan → Keamanan Ujian (Pelanggaran)** — batas maksimal pelanggaran (default 3)
   dan tindakan saat batas tercapai (*Auto-Submit* / *Auto-Blokir* / *Hanya Catat*).
   Berlaku **global untuk semua ujian**.
5. **Pengaturan → AI Gemini** (opsional) — isi API key untuk membuat soal otomatis,
   lalu tekan **Tes Koneksi**.
6. **Sesi Ujian** — tambah/ubah sesi (mis. "Sesi 1 (07.30–09.30)").
7. **Pengguna** — tambah guru & siswa satu per satu, atau **Impor** massal
   (tempel data `username, nama, password, role, kelas, sesi, nisn`).
8. **Buat Ujian** (menu Guru/Admin) — nama, durasi, jadwal mulai/selesai, kelas & sesi
   sasaran, token, jumlah soal tampil, jumlah percobaan, acak soal/opsi, tampilkan pembahasan.
9. **Bank Soal** — tambah butir soal (teks stimulan, **gambar diunggah langsung ke database**,
   opsi A–J, kunci jawaban, bobot), atau **Impor** dari Excel, atau **Generate** dengan Gemini.
10. **Uji coba**: login sebagai siswa di jendela incognito, kerjakan satu ujian, pastikan
    timer, watermark, autosave, pengumpulan, nilai, dan pembahasan berjalan.
11. **Kartu Ujian** — cetak kartu peserta (logo sekolah ikut tercetak).

---

## 6. Checklist siap deploy

**Wajib**

- [ ] **HTTPS aktif** (Let's Encrypt/Certbot atau sertifikat hosting) dan HTTP dialihkan ke HTTPS.
- [ ] Password `admin` sudah diganti; tidak ada akun demo yang tersisa.
- [ ] User database **bukan `root`** dan hanya punya izin pada database aplikasi.
- [ ] `CBT_DB_PASS` diisi lewat environment variable, dan `config/config.php` tidak
      berisi kredensial produksi.
- [ ] `expose_php = Off`, `display_errors = Off` (aplikasi sudah mematikan `display_errors`
      agar respons JSON tidak rusak, tetapi tetap matikan di php.ini).
- [ ] Folder internal tidak bisa diakses lewat HTTP — uji langsung di browser:
      `/config/config.php`, `/lib/db.php`, `/database/schema.sql` harus **ditolak**
      (403/404). Aktifkan `AllowOverride All` (Apache) atau tulis `deny` (Nginx)
      seperti pada [bagian 4](#4-menjalankan-di-web-server).
- [ ] Backup otomatis harian + **sudah pernah diuji restore**.

**Disarankan**

- [ ] Ganti `tindakan_pelanggaran` ke **Auto-Blokir** bila ujian dipakai untuk nilai formal.
- [ ] Isi **batas browser siswa** (mis. `SEB, Chrome`) dan **batas sesi login siswa = 1**.
- [ ] Perbesar batas sesi PHP bila sekolah mengunggah banyak gambar sekaligus.
- [ ] Pasang `fail2ban`/rate limit di level web server untuk endpoint `api.php`.
- [ ] Simpan `logs` berkala (tabel `logs` menyimpan jejak login, pelanggaran, ubah pengaturan).
- [ ] Sudah membuka halaman setup dan menekan **Instal Sekarang** (bukan sekadar membuka
      `index.php` dengan kredensial default).
- [ ] Bila memakai fitur pembaruan pada repo privat: token GitHub sudah diisi.

**Catatan keamanan penting**

### Penyimpanan password

- Password disimpan sebagai **hash** (`password_hash()`, bcrypt/argon2) di kolom
  `users.password`. Tidak ada password yang bisa dibaca langsung dari database.
- Data lama hasil migrasi Google Sheets yang masih **plain text** tetap bisa login:
  saat login pertama berhasil, password otomatis dinaikkan menjadi hash
  (`upgrade_legacy_password()` di `lib/auth.php`). Migrasi serentak juga tersedia:
  `php tools/migrate-passwords.php`.
- **Kartu ujian** masih mencetak password siswa. Untuk itu password juga disimpan
  terenkripsi (AES-256-GCM) di kolom `users.password_enc`, hanya bisa dibuka dengan
  kunci aplikasi:
  - `CBT_PASS_KEY` (environment variable, base64 32 byte atau passphrase bebas), **atau**
  - berkas `config/pass.key` yang dibuat otomatis pada request pertama (sudah
    di-`.gitignore`, jangan pernah di-commit).
  - Jika kunci hilang, login tetap berjalan normal (hash tidak terpengaruh); yang hilang
    hanya tampilan password pada kartu ujian.
  - Pada server Linux, pastikan berkas kunci hanya bisa dibaca web server:
    `chmod 600 config/pass.key && chown www-data config/pass.key`.

---

## 7. Backup & restore

Semua data — termasuk **seluruh gambar soal dan logo** (tabel `media`, kolom `LONGBLOB`) —
berada di database, jadi satu `mysqldump` cukup:

```bash
# Backup harian
mysqldump -u root -p --single-transaction --routines --triggers \
  cbt_gas | gzip > /backup/cbt_gas-$(date +%F).sql.gz

# Restore
gunzip -c /backup/cbt_gas-2026-01-01.sql.gz | mysql -u root -p cbt_gas
```

Contoh cron (setiap hari 01.00):

```cron
0 1 * * * mysqldump -u cbt_user -p'PASSWORD' --single-transaction cbt_gas | gzip > /backup/cbt_gas-$(date +\%F).sql.gz
```

Yang **tidak** perlu dibackup: tidak ada folder unggahan, tidak ada berkas sesi di disk.
Yang perlu diperhatikan saat restore: pastikan **pengaturan `zona_waktu`** di database
hasil restore sesuai lokasi sekolah — semua `DATETIME` disimpan sebagai jam dinding zona itu.

---

## 7b. Pembaruan aplikasi dari GitHub

Login sebagai **Admin &rarr; Pengaturan &rarr; kartu Pembaruan Aplikasi**:

- **Cek Versi** &mdash; membandingkan versi terpasang dengan `main` di
  `github.com/harumwibowo/cbt-gas-php` (tanpa mengubah berkas).
- **Update** &mdash; mengunduh arsip repo, mencadangkan berkas lama ke
  `storage/backups/update-YYYYmmdd-HHMMSS/`, menimpa berkas aplikasi, memeriksa sintaks
  setiap berkas PHP, lalu menyimpan versi baru di tabel `settings`.

Yang **tidak** pernah ditimpa: `config/database.json`, `config/pass.key`,
`config/installed.json`, `config/update_token.txt`, folder `storage/`. Data siswa, soal,
dan hasil ujian ada di MySQL sehingga tetap utuh. Bila ada berkas PHP yang rusak setelah
diunduh, seluruh perubahan **dibatalkan otomatis** (*rollback*).

**Repositori privat.** Bila repo tidak publik, unduhan anonim akan gagal (`HTTP 404`).
Isi **Token GitHub** pada kartu yang sama (fine-grained token dengan izin *Contents: Read*)
&mdash; token disimpan di `config/update_token.txt` (0600) dan tidak pernah dikirim ke
browser, atau set environment variable `CBT_UPDATE_TOKEN`. Bila repo dijadikan publik,
token tidak diperlukan lagi.

> Tombol ini butuh ekstensi `curl` dan `phar`, serta izin tulis web server pada folder
> aplikasi. Untuk server tanpa akses internet keluar, perbarui manual (git pull / unggah
> ulang berkas) lalu buka `index.php`.

---

## 8. Struktur proyek

```
cbt-gas-php/
├── index.php                 # Halaman aplikasi: menyajikan Index.html + menyuntik shim + favicon
├── api.php                   # Satu-satunya endpoint RPC: POST api.php?action=<namaFungsi>
├── upload.php                # Unggah gambar soal/logo (multipart) → disimpan ke MySQL
├── media.php                 # Penyaji gambar dari database: GET media.php?id=MED-XXXXXXXX
├── favicon.php               # Favicon = logo sekolah (otomatis 64×64 PNG)
├── Index.html                # Seluruh UI (SPA) — sama seperti versi Apps Script
├── assets/js/rpc-shim.js     # Emulasi google.script.run → api.php
├── config/config.php         # Kredensial DB (env-overridable), batas unggah, zona waktu default
├── database/schema.sql       # Skema 9 tabel (idempotent)
├── lib/
│   ├── db.php                # PDO singleton + ensure_schema() + seed data awal
│   ├── helpers.php           # Utilitas: id, token, tanggal, log, katalog, normalisasi
│   ├── crypto.php            # AES-256-GCM untuk password_enc (kartu ujian)
│   ├── setup.php             # Deteksi instal baru, uji koneksi, simpan kredensial
│   ├── update.php            # Pembaruan aplikasi dari GitHub (+ rollback)
│   ├── auth.php              # Login/logout, hash password, validasi sesi, peran
│   └── settings.php          # Pengaturan, kebijakan pelanggaran global, zona waktu
├── tools/
│   └── migrate-passwords.php # CLI: migrasi plain text → hash, reset password akun
├── setup.php                 # Halaman setup instalasi baru (form koneksi DB)
├── storage/backups/          # Cadangan otomatis sebelum pembaruan (gitignored)
├── modules/
│   ├── admin.php             # Pengguna, kartu ujian, dashboard, sesi login siswa, impor
│   ├── sessions.php          # Master data sesi ujian
│   ├── exams.php             # CRUD ujian + konversi ke objek klien
│   ├── questions.php         # Bank soal, teks/stimulan, impor, bulk
│   ├── results.php           # Rekap nilai, pelanggaran, penilaian ulang
│   ├── student.php           # Daftar ujian siswa, profil, pembahasan
│   ├── student_exam.php      # Mulai/autosave/pelanggaran/submit/blokir/buka blokir
│   ├── media.php             # Simpan/saji/hapus gambar di MySQL (dedupe, pembersihan)
│   └── gemini.php            # Generator soal AI (opsional)
├── .htaccess                 # Proteksi folder internal (Apache; lihat catatan AllowOverride)
├── config/pass.key           # Kunci enkripsi (dibuat otomatis, tidak di-commit)
├── config/database.json      # Kredensial hasil setup (dibuat otomatis, tidak di-commit)
└── config/update_token.txt   # Token GitHub opsional (tidak di-commit)
```

---

## 9. Cara kerja & parity fitur

**Alur satu request**

```
Browser (Index.html)
   └─ google.script.run.fungsiX(args).withSuccessHandler(...)
        └─ assets/js/rpc-shim.js  →  POST api.php?action=fungsiX   body: {"args":[...]}
             └─ api.php  →  whitelist ALLOWED_ACTIONS → modul PHP
                  └─ PDO/MySQL  →  JSON { success, message, ... }
```

`Index.html` memanggil `google.script.run` di 46 tempat (28 fungsi berbeda). Shim
menyediakan `Proxy` sehingga seluruh pola Apps Script tetap jalan, termasuk
`.withSuccessHandler()`, `.withFailureHandler()`, dan `.withUserObject()`.

**Tabel database**

| Tabel | Isi |
|---|---|
| `users` | akun (`admin`/`guru`/`siswa`), kelas, sesi, token login aktif |
| `exams` | ujian: jadwal, durasi, token, target kelas/sesi/siswa, percobaan |
| `questions` | butir soal: teks, `url_gambar`, opsi A–J, `kunci_jawaban`, bobot |
| `teks_ujian` | teks stimulan/konteks (dipakai bersama butir soal) |
| `results` | satu baris per percobaan: jawaban, skor, status, pelanggaran, paket soal |
| `logs` | jejak audit: login, pelanggaran, ubah pengaturan, blokir, dll. |
| `sesi` | master data sesi ujian |
| `settings` | pengaturan global (nama sekolah, logo, zona waktu, keamanan, AI) |
| `media` | gambar (`LONGBLOB`), MIME, ukuran, hash untuk dedupe |

**Invarian yang dijaga persis seperti versi asli**

- `kunci_jawaban` **tidak pernah** dikirim ke browser selama ujian berlangsung.
- Urutan opsi diacak dengan PRNG deterministik (`seed = username_idsoal`), sehingga soal
  yang sama tetap punya urutan opsi sama saat siswa melanjutkan ujian; jawaban disimpan
  sebagai kunci asli (`origKey`).
- Paket soal (`jumlah_soal_tampil`) disimpan di `results.soal_terpilih` dan dipakai ulang
  saat resume **dan** saat penilaian → nilai konsisten.
- Submit manual ditolak selama masih ada soal belum dijawab; auto-submit tetap dinilai.
- Nilai = `round(Σ bobot benar / Σ bobot seluruh soal × 100, 1)`; multi-percobaan
  menyimpan nilai tertinggi dan sisa kesempatan.
- Buka blokir menyetel pelanggaran ke `batas_global − 1` dan memperbarui `waktu_mulai`.
- Satu baris `results` per (ujian, siswa, percobaan) — duplikat dikonsolidasikan otomatis.
- Kebijakan pelanggaran (`max_violations`, `tindakan_pelanggaran`) bersifat **global**.

---

## 10. Endpoint HTTP

| Endpoint | Metode | Keterangan |
|---|---|---|
| `index.php` | GET | Aplikasi (menyajikan `Index.html` + shim + tag favicon) |
| `api.php?action=<nama>` | POST | RPC tunggal. Body: `{"args":[...]}`. Hanya action dalam whitelist (44) yang dilayani |
| `upload.php` | POST multipart | Unggah gambar: field `username`, `token`, `file`. Hanya Admin/Guru |
| `media.php?id=MED-…` | GET | Menyajikan gambar dari database (ETag + cache), `&download=1` untuk unduh |
| `favicon.php` | GET | Ikon tab dari logo sekolah (64×64 PNG; SVG bawaan bila belum ada logo) |

Contoh memanggil RPC:

```bash
curl -X POST "http://localhost/cbt-gas-php/api.php?action=login" \
     -H "Content-Type: application/json" \
     -d '{"args":["admin","admin123","Mozilla/5.0 Chrome/120"]}'
```

---

## 11. Reset data

Kosongkan seluruh data lalu biarkan aplikasi mengisi ulang data awal saat request berikutnya:

```bash
mysql -u root -p cbt_gas -e "SET FOREIGN_KEY_CHECKS=0;
  TRUNCATE users; TRUNCATE exams; TRUNCATE questions; TRUNCATE teks_ujian;
  TRUNCATE results; TRUNCATE logs; TRUNCATE sesi; TRUNCATE settings; TRUNCATE media;"
```

Buka `index.php` sekali → kembali menjadi: `admin/admin123`, 2 sesi, 14 pengaturan.

**Lupa password admin** (password disimpan sebagai hash, jadi diubah lewat CLI):

```bash
php tools/migrate-passwords.php --reset admin admin123
```

Perintah itu menulis hash baru, memperbarui salinan terenkripsi untuk kartu ujian, dan
mengakhiri semua sesi login akun tersebut. Untuk melihat akun mana yang masih plain text:

```bash
php tools/migrate-passwords.php --dry-run
```

---

## 12. Pemecahan masalah

| Gejala | Penyebab & solusi |
|---|---|
| `Gagal terhubung ke MySQL` | MySQL mati, kredensial salah, atau user tidak punya izin `CREATE`. Periksa `CBT_DB_*`, uji dengan `mysql -u user -p`. |
| Halaman putih / 500 di `api.php` | Error PHP. `display_errors` sengaja dimatikan agar JSON tidak rusak — cek `error_log` (Apache/Nginx) atau jalankan `php -l file.php`. |
| Browser menampilkan JSON, bukan UI | Anda membuka `api.php` langsung, atau `DirectoryIndex` tidak memuat `index.php`. |
| Login siswa ditolak | `allowed_student_browsers` di Pengaturan membatasi User-Agent. Kosongkan bila ingin semua browser. |
| "Batas sesi login siswa tercapai" | `max_student_sessions` tercapai atau sesi lama menggantung. Admin → **Sesi Login Siswa** → reset sesi siswa tersebut. |
| Gambar soal tidak tampil | `media.php` mengembalikan 404 (gambar sudah dihapus) atau `url_gambar` lama berupa URL Drive yang tidak lagi publik. Unggah ulang gambarnya. |
| Unggah gambar gagal | Ukuran > 8 MB (`MAX_UPLOAD_BYTES`) atau bukan gambar valid (MIME diperiksa `finfo` + `getimagesize`). Naikkan `upload_max_filesize`/`post_max_size` di php.ini. |
| Favicon tidak berubah | Cache browser. Buka `favicon.php` langsung atau Ctrl+F5. |
| Jam ujian terasa bergeser | Zona waktu pengaturan ≠ zona sekolah. Ingat: jam ujian adalah **jam dinding** zona terpilih. Ubah zona di luar jam ujian. |
| Ujian tidak muncul untuk siswa | Cek jadwal mulai/selesai, kelas & sesi sasaran, `tipe_peserta` (khusus = daftar siswa), percobaan habis, atau status TERBLOKIR. |
| Siswa terblokir | Buka blokir di menu hasil/monitoring ujian (Admin atau Guru pemilik ujian). |
| `.htaccess` membuat 500 | Hosting melarang `Options`. Hapus blok `<IfModule mod_autoindex.c>Options -Indexes</IfModule>` dari `.htaccess`. |

---

## 13. Catatan teknis & batasan

- **Password** disimpan sebagai hash; salinan terenkripsi (`users.password_enc`) hanya
  dipakai agar kartu ujian tetap bisa mencetak password siswa — lihat
  [Penyimpanan password](#penyimpanan-password).
- **UI**: `Index.html` dipertahankan; satu-satunya perubahan adalah kontrol gambar
  (URL/ID Drive → tombol unggah) pada form butir soal dan logo sekolah.
- **Zona waktu**: seluruh tanggal disimpan sebagai `DATETIME` naif pada zona pengaturan;
  zona waktu OS/PHP server tidak berpengaruh (`apply_app_timezone()` di setiap request).
  Jangan mengubah zona saat ada ujian berjalan.
- **Ukuran database**: gambar disimpan di dalam MySQL (maks. 8 MB/berkas, dedupe SHA-256).
  Bersihkan gambar yatim dengan `media_purge_orphans()` di `modules/media.php` bila perlu.
- **Autentikasi**: memakai token per sesi (`TKN-…`) yang dikirim di body request, bukan
  cookie — jadi tidak rentan CSRF berbasis cookie. Perlakukan token seperti kredensial.
- **Autosave** bersifat *last write wins* (satu siswa tidak mungkin menulis dua kali
  bersamaan pada perangkat yang sama).
- **AI Gemini** opsional; tanpa API key fitur generator soal otomatis mati, fitur lain
  tetap normal.
- **Pengujian**: 125 pemeriksaan end-to-end tersedia di repo pengembangan (folder `tests/`)
  dan dijalankan lewat HTTP terhadap `api.php`. Folder tersebut tidak disertakan dalam
  paket instalasi ini.

---

Dibangun dari `Code.gs` + `Index.html` (Google Apps Script). Sumber asli tersimpan di
riwayat git (`git show fbcbdcc:Code.gs`).
