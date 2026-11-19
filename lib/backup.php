<?php
/**
 * Backup database (Bagian 3.7). Bisa dipanggil CLI maupun request update.
 * mysqldump lebih cepat; fallback PHP dipakai bila plugin auth tidak tersedia.
 */
declare(strict_types=1);

/** Buat backup SQL ke storage/backups/. Mengembalikan path atau null. */
function db_backup(string $reason = 'manual'): ?string
{
    $dir = APP_ROOT . '/storage/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('[CBT backup] tidak bisa membuat folder ' . $dir);
        return null;
    }
    $file = $dir . '/pre-' . preg_replace('/[^a-z0-9_-]/i', '_', $reason)
        . '-' . date('Ymd-His') . '.sql';
    $safePass = DB_PASS !== '' ? ' --password=' . escapeshellarg(DB_PASS) : '';
    $cmd = 'mysqldump --host=' . escapeshellarg(DB_HOST)
        . ' --port=' . (int) DB_PORT
        . ' --user=' . escapeshellarg(DB_USER)
        . $safePass
        . ' --no-tablespaces --single-transaction --quick'
        . ' ' . escapeshellarg(DB_NAME)
        . ' > ' . escapeshellarg($file) . ' 2> ' . escapeshellarg($file . '.err');
    $code = 0;
    $unused = [];
    if (function_exists('exec')) {
        @exec($cmd, $unused, $code);
    } else {
        $code = 1; // exec dinonaktifkan hosting -> langsung ke fallback PHP.
    }
    $err = is_file($file . '.err') ? (string) file_get_contents($file . '.err') : '';
    @unlink($file . '.err');
    if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
        @unlink($file);
        error_log('[CBT backup] mysqldump gagal, mencoba fallback PHP: ' . trim($err));
        return db_backup_php($file, $reason);
    }
    @chmod($file, 0600);
    return $file;
}

/** Fallback backup tanpa mysqldump: CREATE TABLE + INSERT dari PDO. */
function db_backup_php(string $file, string $reason = 'manual'): ?string
{
    try {
        $fh = @fopen($file, 'wb');
        if ($fh === false) {
            return null;
        }
        fwrite($fh, "-- CBT GAS backup (PHP) {$reason} " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach (db_all('SHOW TABLES') as $row) {
            $table = (string) array_values($row)[0];
            $create = db_one('SHOW CREATE TABLE `' . $table . '`');
            $ddl = $create['Create Table'] ?? ($create['Create View'] ?? null);
            if (!is_string($ddl)) {
                continue;
            }
            fwrite($fh, "\nDROP TABLE IF EXISTS `{$table}`;\n" . $ddl . ";\n");
            foreach (db_all('SELECT * FROM `' . $table . '`') as $data) {
                $cols = [];
                $vals = [];
                foreach ($data as $key => $value) {
                    $cols[] = '`' . $key . '`';
                    if ($value === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $vals[] = (string) $value;
                    } else {
                        $vals[] = db()->quote((string) $value);
                    }
                }
                fwrite($fh, 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES ('
                    . implode(',', $vals) . ");\n");
            }
        }
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        @chmod($file, 0600);
        return $file;
    } catch (Throwable $e) {
        @unlink($file);
        error_log('[CBT backup] fallback gagal: ' . $e->getMessage());
        return null;
    }
}
