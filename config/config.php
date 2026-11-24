<?php
/**
 * Konfigurasi aplikasi CBT (PHP Native + MySQL)
 * Nilai dapat dioverride lewat environment variable.
 */
declare(strict_types=1);

/**
 * Kredensial database.
 * Urutan prioritas: environment variable -> config/database.json (dibuat oleh
 * halaman setup) -> default pengembangan. Tidak perlu mengedit berkas ini.
 */
define('CBT_DB_CONFIG_FILE', __DIR__ . '/database.json');

$cbtInstallConfig = [];
if (is_file(CBT_DB_CONFIG_FILE)) {
    $cbtRaw = @file_get_contents(CBT_DB_CONFIG_FILE);
    $cbtDecoded = is_string($cbtRaw) ? json_decode($cbtRaw, true) : null;
    if (is_array($cbtDecoded)) {
        $cbtInstallConfig = $cbtDecoded;
    }
}

define('DB_HOST', getenv('CBT_DB_HOST') ?: (string) ($cbtInstallConfig['host'] ?? '127.0.0.1'));
define('DB_PORT', (int) (getenv('CBT_DB_PORT') ?: ($cbtInstallConfig['port'] ?? 3306)));
define('DB_NAME', getenv('CBT_DB_NAME') ?: (string) ($cbtInstallConfig['name'] ?? 'cbt_gas'));
define('DB_USER', getenv('CBT_DB_USER') ?: (string) ($cbtInstallConfig['user'] ?? 'root'));
define('DB_PASS', getenv('CBT_DB_PASS') !== false ? (string) getenv('CBT_DB_PASS') : (string) ($cbtInstallConfig['pass'] ?? ''));

/** True bila kredensial berasal dari environment (mode produksi lanjutan). */
define('CBT_DB_FROM_ENV', getenv('CBT_DB_HOST') !== false || getenv('CBT_DB_NAME') !== false);

define('APP_NAME', 'CBT GAS - Platform Ujian Online');
define('APP_TIMEZONE', 'Asia/Jakarta');

/**
 * Versi skema terbaru yang dikenal aplikasi.
 *
 * ensure_schema() hanya menjalankan satu query ringan untuk membandingkan nilai
 * ini dengan schema_migrations. DDL/seed penuh dipindah ke schema_bootstrap()
 * yang hanya dipanggil oleh setup.php dan tools/migrate.php.
 * Naikkan nilai ini setiap kali menambah migrasi di lib/db.php.
 */
define('CBT_SCHEMA_VERSION', 11);

/** Batas ukuran berkas gambar yang diunggah (byte). */
define('MAX_UPLOAD_BYTES', 8 * 1024 * 1024); // 8 MB

/** MIME gambar yang diterima untuk fitur unggah. */
define('ALLOWED_IMAGE_MIMES', [
    'image/png'     => 'png',
    'image/jpeg'    => 'jpg',
    'image/webp'    => 'webp',
    'image/gif'     => 'gif',
    // SVG sengaja tidak diizinkan: dapat memuat script/iframe (stored XSS).
]);

date_default_timezone_set(APP_TIMEZONE);

// Endpoint API mengembalikan JSON: jangan pernah mencampur peringatan PHP ke output.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_reporting', (string) (E_ALL & ~E_DEPRECATED));
error_reporting(E_ALL & ~E_DEPRECATED);

/** Path root aplikasi */
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/lib/cache.php';
require_once APP_ROOT . '/lib/exceptions.php';
require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/backup.php';
require_once APP_ROOT . '/lib/maintenance.php';
require_once APP_ROOT . '/lib/helpers.php';
require_once APP_ROOT . '/lib/security.php';
require_once APP_ROOT . '/lib/observability.php';
require_once APP_ROOT . '/lib/version.php';
require_once APP_ROOT . '/lib/crypto.php';
require_once APP_ROOT . '/lib/auth.php';
require_once APP_ROOT . '/lib/totp.php';
require_once APP_ROOT . '/lib/session_cookie.php';
require_once APP_ROOT . '/lib/settings.php';
require_once APP_ROOT . '/lib/setup.php';

// Zona waktu aplikasi mengikuti pengaturan (WIB / WITA / WIT), bukan zona waktu
// server. Bila database belum siap, tetap memakai default Asia/Jakarta.
try {
    apply_app_timezone();
} catch (Throwable $e) {
    date_default_timezone_set(APP_TIMEZONE);
}
