-- =====================================================================
-- 0008 - Integritas ujian: idempotency key submit (Bagian 3.4)
-- =====================================================================
ALTER TABLE `results` ADD COLUMN `idempotency_key` VARCHAR(64) NULL;
CREATE INDEX `idx_results_idempotency` ON `results` (`idempotency_key`);
