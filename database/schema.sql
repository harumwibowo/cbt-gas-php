-- =====================================================================
-- Skema database CBT (MySQL 8)
-- Padanan langsung dari struktur sheet pada Code.gs (SHEET_HEADERS).
-- Bersifat idempotent: aman dijalankan berulang (CREATE TABLE IF NOT EXISTS).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id_user`      VARCHAR(40)  NOT NULL,
  `username`     VARCHAR(100) NOT NULL,
  `password`     VARCHAR(255) NOT NULL DEFAULT '',
  `password_enc` TEXT         NULL,
  `nama_lengkap` VARCHAR(150) NOT NULL DEFAULT '',
  `role`         VARCHAR(20)  NOT NULL DEFAULT 'Siswa',
  `kelas`        VARCHAR(50)  NOT NULL DEFAULT '-',
  `token_session` TEXT        NULL,
  `status_aktif` VARCHAR(20)  NOT NULL DEFAULT 'Aktif',
  `sesi`         VARCHAR(50)  NOT NULL DEFAULT '-',
  `tingkat`      VARCHAR(20)  NOT NULL DEFAULT '-',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sesi` (
  `id_sesi`    VARCHAR(40)  NOT NULL,
  `nama_sesi`  VARCHAR(150) NOT NULL DEFAULT '',
  `keterangan` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id_sesi`),
  UNIQUE KEY `uq_sesi_nama` (`nama_sesi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exams` (
  `id_ujian`             VARCHAR(40)  NOT NULL,
  `nama_ujian`           VARCHAR(200) NOT NULL DEFAULT '',
  `durasi_menit`         INT          NOT NULL DEFAULT 60,
  `waktu_mulai`          DATETIME     NULL,
  `waktu_selesai`        DATETIME     NULL,
  `acak_soal`            VARCHAR(5)   NOT NULL DEFAULT 'Ya',
  `acak_opsi`            VARCHAR(5)   NOT NULL DEFAULT 'Ya',
  `token_ujian`          VARCHAR(50)  NOT NULL DEFAULT '',
  `kelas`                TEXT         NULL,
  `id_guru`              VARCHAR(50)  NOT NULL DEFAULT '-',
  `sesi`                 TEXT         NULL,
  `tipe_peserta`         VARCHAR(30)  NOT NULL DEFAULT 'general',
  `peserta_khusus`       TEXT         NULL,
  `kolaborator`          TEXT         NULL,
  `tingkat`              VARCHAR(20)  NOT NULL DEFAULT '',
  `jumlah_soal_tampil`   INT          NOT NULL DEFAULT 0,
  `tampilkan_pembahasan` VARCHAR(5)   NOT NULL DEFAULT 'Tidak',
  `jumlah_percobaan`     INT          NOT NULL DEFAULT 1,
  `max_violations`       INT          NOT NULL DEFAULT 3,
  `tindakan_pelanggaran` VARCHAR(30)  NOT NULL DEFAULT 'auto_submit',
  `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_ujian`),
  KEY `idx_exams_guru` (`id_guru`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `questions` (
  `id_soal`      VARCHAR(40)  NOT NULL,
  `id_ujian`     VARCHAR(40)  NOT NULL,
  `teks_soal`    LONGTEXT     NULL,
  `url_gambar`   TEXT         NULL,
  `opsi_a`       TEXT         NULL,
  `opsi_b`       TEXT         NULL,
  `opsi_c`       TEXT         NULL,
  `opsi_d`       TEXT         NULL,
  `opsi_e`       TEXT         NULL,
  `opsi_f`       TEXT         NULL,
  `opsi_g`       TEXT         NULL,
  `opsi_h`       TEXT         NULL,
  `opsi_i`       TEXT         NULL,
  `opsi_j`       TEXT         NULL,
  `kunci_jawaban` VARCHAR(200) NOT NULL DEFAULT '',
  `bobot`        INT          NOT NULL DEFAULT 1,
  `id_teks`      VARCHAR(40)  NULL,
  `pembahasan`   LONGTEXT     NULL,
  `urutan`       INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_soal`),
  KEY `idx_questions_ujian` (`id_ujian`),
  KEY `idx_questions_teks` (`id_teks`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teks_ujian` (
  `id_teks`   VARCHAR(40)  NOT NULL,
  `id_ujian`  VARCHAR(40)  NOT NULL,
  `nama_teks` VARCHAR(200) NOT NULL DEFAULT '',
  `isi_teks`  LONGTEXT     NULL,
  PRIMARY KEY (`id_teks`),
  KEY `idx_teks_ujian` (`id_ujian`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `results` (
  `id_hasil`           VARCHAR(40)  NOT NULL,
  `id_ujian`           VARCHAR(40)  NOT NULL,
  `username_siswa`     VARCHAR(100) NOT NULL,
  `waktu_mulai`        DATETIME     NULL,
  `waktu_submit`       DATETIME     NULL,
  `jumlah_pelanggaran` INT          NOT NULL DEFAULT 0,
  `nilai_akhir`        DECIMAL(7,2) NOT NULL DEFAULT 0,
  `status`             VARCHAR(20)  NOT NULL DEFAULT 'MENGERJAKAN',
  `detail_jawaban`     LONGTEXT     NULL,
  `soal_terpilih`      LONGTEXT     NULL,
  `percobaan_ke`       INT          NOT NULL DEFAULT 1,
  `deadline_at`        DATETIME     NULL,
  PRIMARY KEY (`id_hasil`),
  UNIQUE KEY `uq_result_attempt` (`id_ujian`, `username_siswa`, `percobaan_ke`),
  KEY `idx_results_ujian` (`id_ujian`),
  KEY `idx_results_siswa` (`username_siswa`),
  KEY `idx_results_ujian_siswa` (`id_ujian`, `username_siswa`),
  KEY `idx_results_exam_status` (`id_ujian`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs` (
  `id`             BIGINT       NOT NULL AUTO_INCREMENT,
  `timestamp`      DATETIME     NOT NULL,
  `username`       VARCHAR(100) NOT NULL DEFAULT 'Anonymous',
  `jenis_kejadian` VARCHAR(80)  NOT NULL DEFAULT '',
  `keterangan`     TEXT         NULL,
  PRIMARY KEY (`id`),
  KEY `idx_logs_user` (`username`),
  KEY `idx_logs_jenis` (`jenis_kejadian`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `kunci`      VARCHAR(80)  NOT NULL,
  `nilai`      TEXT         NULL,
  `keterangan` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel penyimpanan berkas gambar (pengganti URL / ID Google Drive).
-- Data biner disimpan langsung di MySQL agar aplikasi mandiri (tanpa storage eksternal).
CREATE TABLE IF NOT EXISTS `sessions` (
  `id_session`   VARCHAR(40)  NOT NULL,
  `username`     VARCHAR(100) NOT NULL DEFAULT '',
  `token_hash`   CHAR(64)     NOT NULL DEFAULT '',
  `created_at`   DATETIME     NOT NULL,
  `last_seen_at` DATETIME     NOT NULL,
  `expires_at`   DATETIME     NOT NULL,
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`   VARCHAR(255) NOT NULL DEFAULT '',
  `revoked_at`   DATETIME     NULL,
  PRIMARY KEY (`id_session`),
  UNIQUE KEY `uq_sessions_token` (`token_hash`),
  KEY `idx_sessions_user` (`username`),
  KEY `idx_sessions_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT       NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(100) NOT NULL DEFAULT '',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `attempted_at` DATETIME     NOT NULL,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_user_time` (`username`, `attempted_at`),
  KEY `idx_attempts_ip_time` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version`    INT          NOT NULL,
  `applied_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media` (
  `id_media`   VARCHAR(40)  NOT NULL,
  `nama_file`  VARCHAR(255) NOT NULL DEFAULT '',
  `mime`       VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
  `ukuran`     INT UNSIGNED NOT NULL DEFAULT 0,
  `hash`       CHAR(64)     NOT NULL DEFAULT '',
  `data`       LONGBLOB     NOT NULL,
  `username`   VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_media`),
  KEY `idx_media_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
