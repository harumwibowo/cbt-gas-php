# Migrasi skema berversi

Folder ini berisi migrasi SQL yang **dijalankan sekali** oleh:

```bash
php tools/migrate.php up
php tools/migrate.php status
php tools/migrate.php rollback 0007
```

## Aturan penulisan

- Nama berkas `NNNN_deskripsi.sql` (empat digit, menaik). Nomor harus sama dengan
  nilai `CBT_SCHEMA_VERSION` di `config/config.php` setelah migrasi ditambahkan.
- Satu pernyataan per baris, dipisah `;`. Komentar `--` diabaikan runner.
- Migrasi harus **idempoten**: runner mengabaikan error MySQL 1050 (tabel sudah
  ada), 1060 (kolom sudah ada), dan 1061 (index sudah ada). Error lain menggagalkan
  migrasi dan dicatat ke `error_log`.
- Nomor versi dicatat di tabel `schema_migrations`; runner melewati berkas yang
  sudah terpasang.
- Berkas `NNNN_deskripsi.down.sql` opsional dipakai `tools/migrate.php rollback`.
  Rollback **tidak pernah** dijalankan otomatis dari request web dan menolak
  migrasi data lama v1-v6 (logika PHP-nya ada di `lib/db.php`).

Migrasi v1-v6 masih berupa fungsi PHP di `lib/db.php::db_apply_migrations()`
(karena memerlukan logika pembersihan duplikat). Mulai v7, semua migrasi baru
memakai berkas SQL di folder ini.
