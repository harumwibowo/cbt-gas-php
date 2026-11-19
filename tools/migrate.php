<?php
/**
 * CLI pemeliharaan skema & cache (tidak dijalankan lewat web).
 *
 * Pemakaian:
 *   php tools/migrate.php status        lihat versi skema saat ini vs target
 *   php tools/migrate.php up            jalankan DDL + migrasi + seed (idempoten)
 *   php tools/migrate.php cache:clear   hapus cache APCu/file aplikasi
 *   php tools/migrate.php cleanup        bersihkan media orphan & log lama (cron)
 *   php tools/migrate.php rollback 9     rollback struktur v9 (dengan backup)
 *   php tools/migrate.php backup         backup database ke storage/backups/
 *
 * Semua perintah menolak dipanggil dari web server (PHP_SAPI harus cli).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Perintah ini hanya boleh dijalankan dari command line.\n");
}

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/backup.php';

$command = strtolower(trim((string) ($argv[1] ?? 'status')));

try {
    switch ($command) {
        case 'status':
            $version = null;
            try {
                $version = db_val('SELECT MAX(version) FROM schema_migrations', [], null);
            } catch (Throwable $e) {
                $version = null;
            }
            echo 'Database      : ' . DB_NAME . ' @ ' . DB_HOST . ':' . DB_PORT . "\n";
            echo 'Versi skema   : ' . ($version === null ? 'belum ada' : (string) (int) $version) . "\n";
            echo 'Versi target  : ' . schema_target_version() . "\n";
            echo 'Status        : ' . (($version !== null && (int) $version >= schema_target_version())
                ? 'sudah mutakhir' : 'perlu php tools/migrate.php up') . "\n";
            foreach (db_migrations_status() as $m) {
                echo sprintf("  %-32s %s\n", $m['file'], $m['applied'] ? 'terpasang' : 'belum');
            }
            break;

        case 'up':
        case 'migrate':
            $backup = db_backup('migrate');
            if ($backup !== null) {
                echo "Backup: {$backup}\n";
            }
            schema_bootstrap();
            $version = (int) db_val('SELECT MAX(version) FROM schema_migrations', [], 0);
            cbt_cache_delete('cbt.schema.version');
            echo 'Migrasi selesai. Versi skema sekarang: ' . $version . "\n";
            if ($version < schema_target_version()) {
                echo "Peringatan: sebagian migrasi gagal. Periksa error_log PHP.\n";
                exit(1);
            }
            break;

        case 'cache:clear':
        case 'clear-cache':
            echo 'Berkas cache dihapus: ' . cbt_cache_flush() . "\n";
            break;

        case 'backup':
            $path = db_backup((string) ($argv[2] ?? 'manual'));
            echo $path !== null ? "Backup dibuat: {$path}\n" : "Backup tidak dibuat (lihat STDERR).\n";
            break;

        case 'rollback':
            $target = (int) ($argv[2] ?? 0);
            if ($target < 7 || $target > 9) {
                fwrite(STDERR, "Rollback tersedia untuk versi 7, 8, dan 9 saja. "
                    . "Migrasi v1-v6 mengandung logika data PHP di lib/db.php.\n");
                exit(1);
            }
            $backup = db_backup('rollback-' . $target);
            if ($backup !== null) {
                echo "Backup: {$backup}\n";
            }
            db_rollback_migration($target);
            cbt_cache_delete('cbt.schema.version');
            echo "Rollback v{$target} selesai. Jalankan 'php tools/migrate.php status' untuk verifikasi.\n";
            break;

        case 'cleanup':
            // Pemeliharaan berkala: jalankan dari cron, bukan dari request web.
            require_once dirname(__DIR__) . '/modules/media.php';
            $purged = media_purge_orphans();
            session_cleanup();
            log_retention_cleanup();
            echo "Media orphan dihapus: {$purged}. Sesi/log lama dibersihkan.\n";
            break;

        default:
            fwrite(STDERR, "Perintah tidak dikenal: {$command}\n");
            fwrite(STDERR, "Pilihan: status | up | cache:clear | backup | cleanup | rollback <7|8|9>\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
