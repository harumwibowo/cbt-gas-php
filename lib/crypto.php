<?php
/**
 * Enkripsi simetris untuk data yang harus bisa dibaca kembali (mis. password
 * siswa yang perlu dicetak pada kartu ujian).
 *
 * Algoritma: AES-256-GCM. Kunci diambil dari environment `CBT_PASS_KEY`
 * (base64 32 byte atau passphrase bebas) atau dibuat otomatis di
 * `config/pass.key` (0600, tidak di-commit).
 */
declare(strict_types=1);

/** Kunci enkripsi aplikasi (32 byte mentah). */
function app_secret_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $env = getenv('CBT_PASS_KEY');
    if (is_string($env) && trim($env) !== '') {
        $key = app_secret_key_derive(trim($env));
        return $key;
    }

    $file = APP_ROOT . '/config/pass.key';
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(
                'Kunci enkripsi config/pass.key tidak dapat dibaca. Perbaiki izin berkas '
                . 'atau set CBT_PASS_KEY. Enkripsi dihentikan agar data tidak rusak.'
            );
        }
        $key = app_secret_key_derive(trim($raw));
        return $key;
    }

    $key = random_bytes(32);
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    $written = @file_put_contents($tmp, base64_encode($key) . "\n");
    if ($written === false || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException(
            'Kunci enkripsi tidak dapat disimpan ke config/pass.key. '
            . 'Pastikan folder config dapat ditulis atau set CBT_PASS_KEY.'
        );
    }
    return $key;
}

/** Turunkan kunci 32 byte dari base64 atau passphrase biasa. */
function app_secret_key_derive(string $raw): string
{
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && strlen($decoded) === 32) {
        return $decoded;
    }
    return hash('sha256', 'cbt-gas|' . $raw, true);
}

/**
 * Enkripsi sebuah string. Mengembalikan null bila OpenSSL/kunci tidak tersedia
 * (pemanggil harus siap menerima nilai null).
 */
function enc_secret(string $plain): ?string
{
    if ($plain === '' || !function_exists('openssl_encrypt')) {
        return null;
    }
    try {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return null;
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    } catch (Throwable $e) {
        error_log('Gagal enkripsi: ' . $e->getMessage());
        return null;
    }
}

/** Dekripsi hasil enc_secret(); '' bila gagal/kosong. */
function dec_secret($enc): string
{
    $raw = tostr($enc);
    if ($raw === '' || !str_starts_with($raw, 'v1:') || !function_exists('openssl_decrypt')) {
        return '';
    }
    $bin = base64_decode(substr($raw, 3), true);
    if ($bin === false || strlen($bin) < 29) {
        return '';
    }
    try {
        $plain = openssl_decrypt(
            substr($bin, 28),
            'aes-256-gcm',
            app_secret_key(),
            OPENSSL_RAW_DATA,
            substr($bin, 0, 12),
            substr($bin, 12, 16)
        );
        return $plain === false ? '' : (string) $plain;
    } catch (Throwable $e) {
        return '';
    }
}
