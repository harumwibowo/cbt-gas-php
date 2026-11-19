<?php
/**
 * Penyaji berkas gambar dari tabel `media`.
 *
 * Pemakaian:  GET media.php?id=MED-XXXXXXXX
 *             GET media.php?id=MED-XXXXXXXX&download=1   (paksa unduh)
 *
 * Gambar disimpan sebagai LONGBLOB di MySQL dan dialirkan langsung ke browser,
 * sehingga tidak memerlukan folder unggahan maupun layanan berkas eksternal.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/modules/media.php';

$idMedia = trim((string) ($_GET['id'] ?? ''));

if ($idMedia === '' || !preg_match('/^[A-Za-z0-9\-_]{1,40}$/', $idMedia)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Parameter id tidak valid.';
    exit;
}

try {
    // sengaja TIDAK memanggil ensure_schema(): penyajian gambar hanya butuh
    // SELECT. DDL/seed per request (terutama per gambar) dihentikan di sini.
    $row = db_one('SELECT id_media, nama_file, mime, ukuran, hash, data FROM media WHERE id_media = ?', [$idMedia]);
} catch (Throwable $e) {
    error_log('[CBT media] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gagal membaca media.';
    exit;
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Gambar tidak ditemukan.';
    exit;
}

$mime = tostr($row['mime']) !== '' ? tostr($row['mime']) : 'application/octet-stream';
if (!isset(ALLOWED_IMAGE_MIMES[$mime])) {
    $mime = 'application/octet-stream';
}

$bytes = $row['data'];
if (!is_string($bytes) || $bytes === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Isi gambar kosong.';
    exit;
}

$etag = '"' . tostr($row['hash'] ?: md5($bytes)) . '"';
$namaFile = tostr($row['nama_file']) !== '' ? tostr($row['nama_file']) : ($idMedia . '.' . (ALLOWED_IMAGE_MIMES[$mime] ?? 'bin'));

// Cache panjang + ETag: URL bersifat permanen (immutable), isi tidak pernah berubah.
$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

$forceDownload = isset($_GET['download']) && $_GET['download'] !== '' && $_GET['download'] !== '0';

// Dukungan HTTP Range (single range) agar browser/pdf viewer dapat mengambil
// sebagian berkas tanpa mengunduh seluruh BLOB.
$length = strlen($bytes);
$start = 0;
$end = $length - 1;
$status = 200;
$rangeHeader = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $suffix = min($length, max(0, (int) $m[2]));
        $start = max(0, $length - $suffix);
    } else {
        $start = max(0, (int) $m[1]);
        if ($m[2] !== '') {
            $end = min($length - 1, (int) $m[2]);
        }
    }
    if ($start > $end || $start >= $length) {
        header('Content-Range: bytes */' . $length);
        http_response_code(416);
        exit;
    }
    $status = 206;
}

http_response_code($status);
header('Content-Type: ' . $mime);
header('Content-Length: ' . ($end - $start + 1));
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $length);
}
header(
    'Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline')
    . '; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $namaFile) . '"'
);
// SVG dapat memuat skrip bila dibuka langsung: batasi total.
if ($mime === 'image/svg+xml') {
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
}

echo $status === 206 ? substr($bytes, $start, $end - $start + 1) : $bytes;
