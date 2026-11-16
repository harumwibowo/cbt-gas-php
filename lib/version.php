<?php
/**
 * Nama versi rilis aplikasi.
 *
 * Sumber tunggal: berkas `VERSION` di root aplikasi (satu baris, mis. "1.0.3").
 * Berkas ini ikut tersalin saat pembaruan dari GitHub, sehingga nama versi di
 * halaman Pengaturan dan halaman login otomatis berganti setelah pembaruan.
 */
declare(strict_types=1);

/** Nama versi cadangan bila berkas VERSION tidak ditemukan. */
const APP_VERSION_FALLBACK = '1.0.0';

/**
 * Normalisasi teks versi: buang spasi, awalan "v"/"V", dan baris tambahan.
 * Mengembalikan '' bila tidak ada angka versi.
 */
function version_name_normalize($value): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    if ($text === '') {
        return '';
    }
    $text = trim((string) strtok($text, "\n"));
    $text = preg_replace('/^versi\s+/i', '', $text);
    $text = preg_replace('/^v(?=\d)/i', '', trim((string) $text));

    return trim((string) $text);
}

/** Baca nama versi dari satu berkas ('' bila berkas tidak ada/tidak valid). */
function version_name_from_file(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }
    $raw = @file_get_contents($path, false, null, 0, 200);
    if (!is_string($raw)) {
        return '';
    }

    return version_name_normalize($raw);
}

/**
 * Nama versi terpasang (dari berkas VERSION di root aplikasi).
 * Contoh keluaran: "1.0.3".
 */
function app_version_name(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $name = version_name_from_file(APP_ROOT . '/VERSION');
    $cached = $name !== '' ? $name : APP_VERSION_FALLBACK;

    return $cached;
}

/** Nama versi untuk ditampilkan (mis. "v1.0.3"). */
function app_version_label(): string
{
    return 'v' . app_version_name();
}
