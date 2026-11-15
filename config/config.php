<?php
/**
 * Konfigurasi aplikasi CBT (PHP Native + MySQL)
 * Nilai dapat dioverride lewat environment variable.
 */
declare(strict_types=1);

define('DB_HOST', getenv('CBT_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('CBT_DB_PORT') ?: 3306));
define('DB_NAME', getenv('CBT_DB_NAME') ?: 'cbt_gas');
define('DB_USER', getenv('CBT_DB_USER') ?: 'root');
define('DB_PASS', getenv('CBT_DB_PASS') !== false ? getenv('CBT_DB_PASS') : '');

define('APP_NAME', 'CBT GAS - Platform Ujian Online');
define('APP_TIMEZONE', 'Asia/Jakarta');

/** Batas ukuran berkas gambar yang diunggah (byte). */
define('MAX_UPLOAD_BYTES', 8 * 1024 * 1024); // 8 MB

/** MIME gambar yang diterima untuk fitur unggah. */
define('ALLOWED_IMAGE_MIMES', [
    'image/png'     => 'png',
    'image/jpeg'    => 'jpg',
    'image/webp'    => 'webp',
    'image/gif'     => 'gif',
    'image/svg+xml' => 'svg',
]);

date_default_timezone_set(APP_TIMEZONE);

// Endpoint API mengembalikan JSON: jangan pernah mencampur peringatan PHP ke output.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_reporting', (string) (E_ALL & ~E_DEPRECATED));
error_reporting(E_ALL & ~E_DEPRECATED);

/** Path root aplikasi */
define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/helpers.php';
require_once APP_ROOT . '/lib/auth.php';
require_once APP_ROOT . '/lib/settings.php';

// Zona waktu aplikasi mengikuti pengaturan (WIB / WITA / WIT), bukan zona waktu
// server. Bila database belum siap, tetap memakai default Asia/Jakarta.
try {
    apply_app_timezone();
} catch (Throwable $e) {
    date_default_timezone_set(APP_TIMEZONE);
}
