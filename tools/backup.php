<?php
/**
 * CLI backup database: php tools/backup.php [alasan]
 * (Fungsi db_backup() ada di lib/backup.php agar bisa dipakai update.php.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Perintah ini hanya boleh dijalankan dari command line.\n");
}

require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/lib/backup.php';

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'backup.php') {
    $path = db_backup((string) ($argv[1] ?? 'manual'));
    echo $path !== null ? "Backup dibuat: {$path}\n" : "Backup tidak dibuat (lihat error_log).\n";
}
