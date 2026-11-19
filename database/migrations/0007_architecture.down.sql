-- Rollback 0007. Hanya menghapus struktur; data audit sengaja tidak dihapus
-- otomatis (backup dulu sebelum rollback).
DROP TABLE IF EXISTS `answer_audit`;
ALTER TABLE `users` DROP COLUMN `two_factor_secret`;
ALTER TABLE `users` DROP COLUMN `must_change_password`;
