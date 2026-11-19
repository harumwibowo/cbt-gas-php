DROP INDEX `idx_results_idempotency` ON `results`;
ALTER TABLE `results` DROP COLUMN `idempotency_key`;
