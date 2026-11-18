<?php
/**
 * Favicon aplikasi diambil dari logo sekolah yang tersimpan di database.
 *
 * - Logo berupa gambar hasil unggah (media.php?id=...) → byte disajikan langsung.
 * - Logo berupa URL eksternal → dialihkan (302) ke URL tersebut.
 * - Belum ada logo → ikon bawaan (SVG) agar tab browser tetap rapi.
 *
 * Ukuran dinormalkan ke 64x64 (PNG) bila ekstensi GD tersedia.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

/** Kirim byte gambar dengan header cache yang benar. */
function favicon_send(string $bytes, string $mime, string $etagSeed): void
{
    $etag = '"' . substr(hash('sha256', $etagSeed . '|' . $mime), 0, 32) . '"';
    $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    if ($mime === 'image/svg+xml') {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=86400');

    if ($clientEtag === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/** Ikon bawaan (SVG) ketika sekolah belum mengunggah logo. */
function favicon_default_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
        . '<rect width="64" height="64" rx="14" fill="#4f46e5"/>'
        . '<path d="M32 14 54 25 32 36 10 25z" fill="#ffffff"/>'
        . '<path d="M18 30v10c0 5 6 9 14 9s14-4 14-9V30l-14 7z" fill="#c7d2fe"/>'
        . '</svg>';
}

/** Normalkan gambar apa pun menjadi PNG 64x64 (butuh GD). */
function favicon_to_png(string $bytes, string $mime): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $size = 64;
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefilledrectangle($dst, 0, 0, $size, $size, imagecolorallocatealpha($dst, 0, 0, 0, 127));

    $scale = min($size / max(1, $w), $size / max(1, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    imagecopyresampled($dst, $src, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagepng($dst);
    $png = (string) ob_get_clean();
    imagedestroy($dst);
    imagedestroy($src);

    return ($png !== '' && $mime !== 'image/svg+xml') ? $png : null;
}

$logo = '';
try {
    $settings = settings_map();
    $logo = trim(tostr($settings['logo_url'] ?? ''));
} catch (Throwable $e) {
    $logo = '';
}

// Belum ada logo → ikon bawaan.
if ($logo === '') {
    favicon_send(favicon_default_svg(), 'image/svg+xml', 'default');
}

// Logo berupa berkas di database.
if (preg_match('~media\.php\?[^"\']*\bid=([A-Za-z0-9_\-]+)~i', $logo, $m)) {
    $idMedia = $m[1];
    try {
        $row = db_one('SELECT mime, data, hash FROM media WHERE id_media = ?', [$idMedia]);
    } catch (Throwable $e) {
        $row = null;
    }

    if ($row) {
        $mime = strtolower(tostr($row['mime'])) ?: 'image/png';
        $bytes = (string) $row['data'];
        $seed = tostr($row['hash']) !== '' ? tostr($row['hash']) : $idMedia;

        // Ikon tab selalu diperkecil ke 64x64 PNG agar ringan (kecuali SVG yang
        // memang sudah ringan dan tajam di semua ukuran).
        if ($mime !== 'image/svg+xml') {
            $png = favicon_to_png($bytes, $mime);
            if ($png !== null) {
                favicon_send($png, 'image/png', $seed . '|64');
            }
        }
        favicon_send($bytes, $mime, $seed);
    }

    favicon_send(favicon_default_svg(), 'image/svg+xml', 'default');
}

// Logo berupa URL eksternal → alihkan (tidak mengunduh di server).
if (preg_match('~^https?://~i', $logo)) {
    header('Cache-Control: public, max-age=86400');
    header('Location: ' . $logo, true, 302);
    exit;
}

// Logo jalur relatif lokal.
if (preg_match('~^[A-Za-z0-9_\-./]+\.(png|jpe?g|webp|gif|svg|ico)$~i', $logo)
    && !str_contains($logo, '..')) {
    $path = __DIR__ . '/' . ltrim($logo, './');
    $realPath = realpath($path);
    $rootPath = realpath(__DIR__);
    if ($realPath !== false && $rootPath !== false
        && str_starts_with($realPath, $rootPath . DIRECTORY_SEPARATOR)
        && is_file($realPath)) {
        $path = $realPath;
        $bytes = (string) file_get_contents($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon'];
        $mime = $map[$ext] ?? 'image/png';
        if ($mime !== 'image/svg+xml') {
            $png = favicon_to_png($bytes, $mime);
            if ($png !== null) {
                favicon_send($png, 'image/png', md5($bytes) . '|64');
            }
        }
        favicon_send($bytes, $mime, md5($bytes));
    }
}

favicon_send(favicon_default_svg(), 'image/svg+xml', 'default');
