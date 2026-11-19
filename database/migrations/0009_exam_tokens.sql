-- =====================================================================
-- 0009 - Token ujian per siswa, sekali pakai & berlaku jendela waktu (3.4)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `exam_tokens` (
  `id_ujian`       VARCHAR(40)  NOT NULL,
  `username_siswa` VARCHAR(100) NOT NULL,
  `token_hash`     CHAR(64)     NOT NULL DEFAULT '',
  `expires_at`     DATETIME     NOT NULL,
  `used_at`        DATETIME     NULL,
  `created_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id_ujian`, `username_siswa`),
  KEY `idx_exam_tokens_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (kunci, nilai, keterangan)
VALUES ('token_per_siswa', 'Tidak', 'Gunakan token sekali pakai per siswa (Ya/Tidak). Kosong = token ujian global')
ON DUPLICATE KEY UPDATE kunci = kunci;
