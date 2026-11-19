-- =====================================================================
-- 0007 - Arsitektur: 2FA, kebijakan password, audit jawaban (Bagian 3)
-- =====================================================================
-- Dijalankan oleh tools/migrate.php up (atau schema_bootstrap()).
-- Aman dijalankan ulang: runner mengabaikan error 1050/1060/1061.

-- 3.3 Autentikasi modern: rahasia TOTP & paksa ganti password.
ALTER TABLE `users` ADD COLUMN `two_factor_secret` VARCHAR(64) NULL;
ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0;

-- 3.4 Audit trail append-only untuk perubahan jawaban (sengketa nilai).
CREATE TABLE IF NOT EXISTS `answer_audit` (
  `id`         BIGINT       NOT NULL AUTO_INCREMENT,
  `id_hasil`   VARCHAR(40)  NOT NULL,
  `id_soal`    VARCHAR(40)  NOT NULL,
  `jawaban`    VARCHAR(500) NULL,
  `changed_at` DATETIME     NOT NULL,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_answer_audit_hasil` (`id_hasil`, `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
