<?php
/**
 * Cache ringan lintas-request.
 *
 * Memakai APCu bila ekstensi tersedia (umumnya di shared hosting & PHP-FPM),
 * dengan fallback file di storage/cache/ (sudah diblokir oleh .htaccess). Cache
 * ini sengaja sederhana: hanya untuk data yang bisa dihitung ulang dari
 * database, seperti versi skema, peta pengaturan, dan hasil pengecekan update.
 */
declare(strict_types=1);

/**
 * Namespace cache per database.
 *
 * Beberapa proses dalam satu instalasi dapat berganti database (halaman setup
 * memakai DB uji, lalu kembali ke DB utama). Tanpa pemisahan ini, nilai cache
 * milik database lain — mis. peta pengaturan berisi nilai bawaan — ikut
 * terbaca dan bahkan bisa tertulis kembali ke database yang benar.
 */
function cbt_cache_namespace(): string
{
    static $ns = null;
    if ($ns !== null) {
        return $ns;
    }
    $host = defined('DB_HOST') ? (string) DB_HOST : '';
    $port = defined('DB_PORT') ? (string) DB_PORT : '';
    $name = defined('DB_NAME') ? (string) DB_NAME : '';
    $ns = ($host === '' && $name === '')
        ? ''
        : substr(sha1($host . '|' . $port . '|' . $name), 0, 10);
    return $ns;
}

/** Kunci final untuk penyimpanan lintas-request (APCu & berkas). */
function cbt_cache_key(string $key): string
{
    $ns = cbt_cache_namespace();
    return $ns === '' ? $key : $ns . ':' . $key;
}

/** Simpan nilai cache di proses (ditambah APCu/file bila tersedia). */
function cbt_cache_get(string $key)
{
    if (!isset($GLOBALS['CBT_CACHE_LOCAL']) || !is_array($GLOBALS['CBT_CACHE_LOCAL'])) {
        $GLOBALS['CBT_CACHE_LOCAL'] = [];
    }
    if (array_key_exists($key, $GLOBALS['CBT_CACHE_LOCAL'])) {
        return $GLOBALS['CBT_CACHE_LOCAL'][$key];
    }

    if (cbt_cache_apcu_ready()) {
        $ok = false;
        $value = apcu_fetch(cbt_cache_key($key), $ok);
        if ($ok) {
            $GLOBALS['CBT_CACHE_LOCAL'][$key] = $value;
            return $value;
        }
    }

    $file = cbt_cache_file($key);
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && array_key_exists('val', $data)) {
            $exp = (int) ($data['exp'] ?? 0);
            if ($exp === 0 || $exp > time()) {
                $GLOBALS['CBT_CACHE_LOCAL'][$key] = $data['val'];
                return $data['val'];
            }
        }
        // Berkas kedaluwarsa/rusak: buang supaya tidak dipakai ulang.
        @unlink($file);
    }
    return null;
}

/** Tulis nilai cache dengan TTL detik (0 = tanpa kedaluwarsa). */
function cbt_cache_set(string $key, $value, int $ttl = 60): void
{
    if (!isset($GLOBALS['CBT_CACHE_LOCAL']) || !is_array($GLOBALS['CBT_CACHE_LOCAL'])) {
        $GLOBALS['CBT_CACHE_LOCAL'] = [];
    }
    $GLOBALS['CBT_CACHE_LOCAL'][$key] = $value;

    if (cbt_cache_apcu_ready()) {
        @apcu_store(cbt_cache_key($key), $value, max(0, $ttl));
    }

    $file = cbt_cache_file($key);
    $payload = json_encode(
        ['exp' => $ttl > 0 ? time() + $ttl : 0, 'val' => $value],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($payload)) {
        return;
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

/** Hapus satu kunci cache (dipanggil setelah data sumber berubah). */
function cbt_cache_delete(string $key): void
{
    if (isset($GLOBALS['CBT_CACHE_LOCAL']) && is_array($GLOBALS['CBT_CACHE_LOCAL'])) {
        unset($GLOBALS['CBT_CACHE_LOCAL'][$key]);
    }
    if (cbt_cache_apcu_ready()) {
        @apcu_delete(cbt_cache_key($key));
    }
    $file = cbt_cache_file($key);
    if (is_file($file)) {
        @unlink($file);
    }
}

/** Hapus seluruh berkas cache aplikasi (dipakai oleh CLI migrate/clear-cache). */
function cbt_cache_flush(): int
{
    $GLOBALS['CBT_CACHE_LOCAL'] = [];
    $dir = APP_ROOT . '/storage/cache';
    $deleted = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }
    if (cbt_cache_apcu_ready() && function_exists('apcu_clear_cache')) {
        @apcu_clear_cache();
    }
    return $deleted;
}

/** APCu hanya dipakai bila benar-benar aktif (CLI perlu apc.enable_cli=1). */
function cbt_cache_apcu_ready(): bool
{
    return function_exists('apcu_fetch') && function_exists('apcu_store')
        && (PHP_SAPI !== 'cli' || (bool) ini_get('apc.enable_cli'));
}

/** Path berkas cache untuk sebuah kunci (dibedakan per database). */
function cbt_cache_file(string $key): string
{
    return APP_ROOT . '/storage/cache/' . substr(sha1('cbt:' . cbt_cache_key($key)), 0, 32) . '.json';
}
